<?php

declare(strict_types=1);

namespace Mattmy\FillMap\Tests;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Process\Factory;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mattmy\FillMap\AttributeMapper;
use Mattmy\FillMap\InputKeyStyle;
use Mattmy\FillMap\Tests\Fixtures\MappingModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class AttributeOutputTest extends TestCase
{
    /** Compares raw attributes across outputs while leaving casts and defaults to Eloquent. */
    public function test_outputs_agree_before_eloquent_mutation(): void
    {
        $model = new MappingModel();
        $chain = AttributeMapper::from(['count' => '12', 'active' => false])
            ->forModel($model)
            ->merge(['id' => 17]);

        $expected = ['id' => 17, 'active' => false, 'count' => '12'];
        self::assertSame($expected, $chain->toArray());
        self::assertSame($expected, $chain->collect()->all());
        self::assertSame($model, $chain->toModel());
        self::assertSame($expected, $model->received);
        self::assertSame('default', $model->getAttribute('end_time'));
        self::assertSame(12, $model->getAttribute('count'));
        self::assertFalse($model->exists);

        $new = AttributeMapper::from([])->forModel(MappingModel::class);
        self::assertNotSame($new->toModel(), $new->toModel());
    }

    /** Leaves Arrayable values intact in the output collection and input to fill(). */
    public function test_collection_keeps_arrayable_values(): void
    {
        $value = new class() implements Arrayable
        {
            /**
             * Exposes a representation the mapper must leave alone.
             *
             * @return array{value: int}
             */
            public function toArray(): array
            {
                return ['value' => 1];
            }
        };

        $chain = AttributeMapper::from(['preferences' => $value])->forModel(MappingModel::class);
        $expected = ['preferences' => $value];
        self::assertSame($expected, $chain->toArray());
        self::assertSame($expected, $chain->collect()->all());
        self::assertSame($expected, $chain->toModel()->received);
    }

    /** Applies supplements before filters, with independent branches and scoped blank removal. */
    public function test_merge_and_filter_order(): void
    {
        $model = new MappingModel();
        $base = AttributeMapper::from(['end_time' => null, 'active' => false, 'id' => 0, 'uuid' => '', 'preferences' => [], 'count' => '0'])
            ->forModel($model);
        self::assertSame(['end_time' => null, 'id' => 0, 'uuid' => '', 'preferences' => [], 'active' => false, 'count' => '0'], $base->toArray());
        self::assertSame(['id' => 0, 'active' => false, 'count' => '0'], $base->withoutBlank()->toArray());
        self::assertSame(['end_time' => 'merged', 'id' => 0, 'uuid' => '', 'preferences' => [], 'active' => false, 'count' => '0'], $base
            ->withoutBlank()
            ->merge(['end_time' => 'merged'])
            ->withoutBlank([])
            ->toArray());

        $configured = $base->merge(['end_time' => 'merged'])->only(['end_time', 'active', 'count']);
        $branch = $configured->merge(['end_time' => ' '])->only(['end_time', 'active', 'uuid'])->withoutBlank('end_time');
        $other = $configured->except('count')->except(['active']);

        self::assertSame(['end_time' => 'merged', 'active' => false, 'count' => '0'], $configured->toArray());
        self::assertSame(['active' => false], $branch->toArray());
        self::assertSame(['end_time' => 'merged'], $other->toArray());
        self::assertSame($model, $branch->toModel());
        self::assertSame($model, $other->toModel());
    }

    /** Applies set semantics to valid names and permits an empty selection. */
    public function test_valid_unmatched_and_empty_filters(): void
    {
        $base = AttributeMapper::from(['end_time' => null, '1.5' => 5])
            ->forModel((new MappingModel())->fillable(['end_time', '1.5']));

        self::assertSame([], $base->only([])->toArray());
        self::assertSame([], $base->only('missing')->toArray());
        self::assertSame($base->toArray(), $base->except([])->except('missing')->withoutBlank('missing')->toArray());
        self::assertSame(['1.5' => 5], $base->withoutBlank(null)->toArray());
        self::assertSame($base->only('1.5')->toArray(), $base->only(['1.5'])->toArray());
        self::assertSame(['end_time' => null], $base->except('1.5')->toArray());
        self::assertSame(['end_time' => null], $base->except(['1.5'])->toArray());

        $blank = $base->merge(['1.5' => null]);
        self::assertSame(['end_time' => null], $blank->withoutBlank('1.5')->toArray());
        self::assertSame(['end_time' => null], $blank->withoutBlank(['1.5'])->toArray());
        self::assertSame(['1.5' => 4], $base->setId(4, '1.5')->only('1.5')->toArray());
        $uuid = 'dc904a70-eb42-4460-96d2-86d75d08c3aa';
        self::assertSame(['end_time' => null, '1.5' => $uuid], $base->setUuid($uuid, '1.5')->toArray());
    }

    /** Keeps the original PHP semantics of nested merge references. */
    public function test_merge_retains_nested_references(): void
    {
        $value = true;
        $chain = AttributeMapper::from([])->forModel(MappingModel::class)->merge(['preferences' => [4 => &$value]]);
        $value = false;
        self::assertSame(['preferences' => [4 => false]], $chain->toArray());
    }

    /** Normalizes every UUID source to a stable string and applies last-write precedence. */
    public function test_identifiers_and_last_write_wins(): void
    {
        $uuid = Uuid::fromString('dc904a70-eb42-4460-96d2-86d75d08c3aa');
        $generated = 0;
        Str::createUuidsUsing(static function () use ($uuid, &$generated) {
            $generated++;

            return $uuid;
        });

        try {
            $base = AttributeMapper::from([])->forModel(MappingModel::class);
            $chain = $base->setUuid()->setId(123, 'user_id')->merge(['user_id' => 456])->setId(789, 'user_id')->setId();
            $expected = ['id' => 0, 'uuid' => $uuid->toString(), 'user_id' => 789];
            self::assertSame(1, $generated);
            self::assertSame($expected, $chain->toArray());
            self::assertSame($expected, $chain->collect()->all());
            self::assertSame($expected, $chain->toModel()->received);
            self::assertSame(1, $generated);

            $string = \strtoupper($uuid->toString());
            foreach ([$base->setUuid(null), $base->setUuid($uuid), $base->setUuid($string)] as $index => $explicit) {
                $expected = ['uuid' => $index === 2 ? $string : $uuid->toString()];
                self::assertSame($expected, $explicit->toArray());
                self::assertSame($expected, $explicit->collect()->all());
                self::assertSame($expected, $explicit->toModel()->received);
            }

            self::assertSame(2, $generated);
            self::assertSame(['id' => 0], $base->setId(null)->toArray());
            self::assertSame(['user_id' => $string], $base->setUuid($string, 'user_id')->toArray());
            self::assertSame(['uuid' => 'manual'], $base->setUuid($uuid)->merge(['uuid' => 'manual'])->toArray());
        } finally {
            Str::createUuidsNormally();
        }
    }

    /** Applies only explicitly supplied identifiers outside the fillable list. */
    public function test_identifiers_can_be_added_outside_fillable(): void
    {
        $uuid = 'dc904a70-eb42-4460-96d2-86d75d08c3aa';
        $model = (new MappingModel())->fillable(['active']);
        $base = AttributeMapper::from(['id' => 99, 'uuid' => $uuid, 'active' => true])->forModel($model);
        self::assertSame(['active' => true], $base->toArray());

        $chain = $base->setUuid($uuid)->setId();
        $expected = ['active' => true, 'uuid' => $uuid, 'id' => 0];
        self::assertSame($expected, $chain->toArray());
        self::assertSame($expected, $chain->collect()->all());
        self::assertSame(['active' => true, 'uuid' => $uuid], $chain->except('id')->toArray());
        self::assertArrayNotHasKey('id', $chain->except('id')->toModel()->getAttributes());
        self::assertSame($model, $chain->toModel());
        self::assertSame(['active' => true], $model->received);
        self::assertSame($expected, \array_diff_key($model->getAttributes(), ['end_time' => true]));

        $custom = (new MappingModel())->fillable([]);
        $named = AttributeMapper::from([])->forModel($custom)
            ->merge(['external_id' => 99])
            ->setId(42, 'external_id')
            ->setUuid($uuid, 'external_uuid');
        $customExpected = ['external_id' => 42, 'external_uuid' => $uuid];
        self::assertSame($customExpected, $named->toArray());
        self::assertSame($customExpected, \array_diff_key($named->toModel()->getAttributes(), ['end_time' => true]));
    }

    /** Applies authorized merge values outside fillable without admitting source values. */
    public function test_merge_can_add_attributes_outside_fillable(): void
    {
        $model = (new MappingModel())->fillable(['active']);
        $base = AttributeMapper::from(['id' => 99, 'is_admin' => true, 'active' => true])->forModel($model);
        self::assertSame(['active' => true], $base->toArray());

        $chain = $base->setId(42)->merge(['is_admin' => false, 'id' => 17, 'verified_at' => null]);
        $expected = ['active' => true, 'id' => 17, 'is_admin' => false, 'verified_at' => null];
        self::assertSame($expected, $chain->toArray());
        self::assertSame($expected, $chain->collect()->all());
        self::assertSame(['id' => 17, 'is_admin' => false], $chain->only(['id', 'is_admin', 'verified_at'])->withoutBlank()->toArray());
        self::assertArrayNotHasKey('is_admin', $chain->except(['is_admin', 'verified_at'])->toModel()->getAttributes());
        self::assertSame($model, $chain->toModel());
        self::assertSame(['active' => true], $model->received);
        self::assertSame($expected, \array_diff_key($model->getAttributes(), ['end_time' => true]));
    }

    /**
     * Generates invalid filters, supplement keys, and ID/UUID values.
     *
     * @return iterable<string, array{string, list<mixed>}>
     */
    public static function invalidSettings(): iterable
    {
        foreach (['only', 'except', 'withoutBlank'] as $method) {
            foreach ([1.5, [1.5], '', ['1'], [0 => 'uuid', 2 => 'id'], ['key' => 'uuid'], [['uuid']]] as $index => $value) {
                yield "{$method} {$index}" => [$method, [$value]];
            }
        }

        yield 'only null' => ['only', [null]];
        yield 'except null' => ['except', [null]];
        yield 'merge empty key' => ['merge', [['' => 123]]];
        yield 'merge integer key' => ['merge', [[1 => 123]]];
        foreach ([0, -1, '1', 1.5, false] as $index => $value) {
            yield "ID {$index}" => ['setId', [$value]];
        }

        foreach (['setId', 'setUuid'] as $method) {
            foreach (['', '1', 1.5] as $index => $name) {
                yield "{$method} name {$index}" => [$method, [null, $name]];
            }
        }

        yield 'invalid UUID string' => ['setUuid', ['invalid']];
        yield 'invalid UUID number' => ['setUuid', [12]];
    }

    /**
     * Checks that malformed settings fail before any output.
     *
     * @param  list<mixed>  $arguments
     */
    #[DataProvider('invalidSettings')]
    public function test_rejects_invalid_settings(string $method, array $arguments): void
    {
        $invoke = [AttributeMapper::from([])->forModel(MappingModel::class), $method];
        if (! \is_callable($invoke)) {
            self::fail('The test operation is not callable.');
        }

        $this->expectException(InvalidArgumentException::class);
        $invoke(...$arguments);
    }

    /** Calls the public API from PHP code with default scalar coercion. */
    public function test_weak_caller_does_not_coerce_inputs(): void
    {
        $code = <<<'PHP'
            require 'vendor/autoload.php';

            $chain = Mattmy\FillMap\AttributeMapper::from([])->forModel(Mattmy\FillMap\Tests\Fixtures\MappingModel::class);
            $invalidCalls = [
                'only' => [[1.5], [[1.5]], [null]],
                'except' => [[1.5], [[1.5]], [null]],
                'withoutBlank' => [[1.5], [[1.5]]],
                'setId' => [[0], [-1], ['1'], [1.5], [true], [null, ''], [null, '1'], [1, 1.5]],
                'setUuid' => [[null, ''], [null, '1'], [null, 1.5]],
            ];

            foreach ($invalidCalls as $method => $calls) {
                foreach ($calls as $args) {
                    try {
                        $chain->{$method}(...$args);
                        throw new LogicException($method . ' accepted an invalid argument.');
                    } catch (InvalidArgumentException) {
                    }
                }
            }

            $model = (new Mattmy\FillMap\Tests\Fixtures\MappingModel())->fillable(['end_time', '1.5']);
            $chain = Mattmy\FillMap\AttributeMapper::from(['end_time' => null, '1.5' => null])->forModel($model);
            foreach (['only' => ['1.5' => null], 'except' => ['end_time' => null], 'withoutBlank' => ['end_time' => null]] as $method => $expected) {
                if ($chain->{$method}('1.5')->toArray() !== $expected || $chain->{$method}(['1.5'])->toArray() !== $expected) {
                    throw new LogicException($method . ' did not match the literal string in both forms.');
                }
            }
            PHP;

        $result = (new Factory())->newPendingProcess()
            ->path(\dirname(__DIR__))
            ->timeout(15)
            ->run([\PHP_BINARY, '-r', $code]);
        $result->throw();
        self::assertSame(0, $result->exitCode());
    }

    /** Validates all final settings before any fill, including filtered-out bad settings. */
    public function test_terminal_errors_occur_before_fill(): void
    {
        $model = (new MappingModel(['active' => false]))->fillable(['end_time', 'endTime']);
        $base = AttributeMapper::from(['end_time' => 'provided'])->forModel($model);

        $invalidChains = [
            $base->merge(['id' => 42])->aliases(['key' => 'id']),
            $base->inputKeys(InputKeyStyle::Camel),
        ];
        foreach ($invalidChains as $invalid) {
            $filtered = $invalid->only([]);
            foreach ([$filtered->toArray(...), $filtered->collect(...), $filtered->toModel(...)] as $output) {
                try {
                    $output();
                    self::fail('Invalid configuration must fail.');
                } catch (InvalidArgumentException) {
                    self::assertSame(['active' => false], $model->received);
                }
            }
        }
    }

    /** Leaves a model's overridden mass-assignment policy in control of fill(). */
    public function test_model_can_apply_a_stricter_fill_policy(): void
    {
        $model = new class() extends MappingModel
        {
            /** Rejects the attribute even though it appears in getFillable(). */
            public function isFillable($key): bool
            {
                return false;
            }
        };

        $chain = AttributeMapper::from(['active' => true])->forModel($model);
        self::assertSame(['active' => true], $chain->toArray());
        self::assertSame($model, $chain->toModel());
        self::assertSame(['active' => true], $model->received);
        self::assertSame(['end_time' => 'default'], $model->getAttributes());
    }

    /** Keeps fillable order and propagates a mutator's original failure without rolling back. */
    public function test_eloquent_mutator_order_and_failure(): void
    {
        $model = new class() extends Model
        {
            /** @var list<string> */
            protected $fillable = ['first', 'second', 'first'];

            /** Exposes whether the first field was already written. */
            public function setSecondAttribute(int $value): void
            {
                $first = $this->getAttribute('first');

                throw new RuntimeException($first === 1 ? "first:{$value}" : 'first missing');
            }
        };

        $chain = AttributeMapper::from(['second' => 2, 'first' => 1])->forModel($model);
        self::assertSame(['first' => 1, 'second' => 2], $chain->collect()->all());

        try {
            $chain->toModel();
            self::fail('The mutator should throw.');
        } catch (RuntimeException $exception) {
            self::assertSame('first:2', $exception->getMessage());
            self::assertSame(['first' => 1], $model->getAttributes());
        }
    }

    /** Propagates a direct-assignment error and leaves earlier writes in place. */
    public function test_outside_fillable_mutator_failure_preserves_earlier_writes(): void
    {
        $model = new class() extends Model
        {
            public readonly RuntimeException $failure;

            /** @var list<string> */
            protected $fillable = ['active'];

            /** Prepares the exception for an identity assertion. */
            public function __construct()
            {
                $this->failure = new RuntimeException('outside fillable mutator');
                parent::__construct();
            }

            /** Fails when the mapper writes this explicitly added attribute. */
            public function setExternalIdAttribute(int $value): never
            {
                throw $this->failure;
            }
        };

        $chain = AttributeMapper::from(['active' => true])->forModel($model)
            ->merge(['create_by' => 7, 'external_id' => 42]);

        try {
            $chain->toModel();
            self::fail('The mutator should throw.');
        } catch (RuntimeException $exception) {
            self::assertSame($model->failure, $exception);
            self::assertSame(['active' => true, 'create_by' => 7], $model->getAttributes());
        }
    }
}
