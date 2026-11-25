<?php

declare(strict_types=1);

namespace Mattmy\FillMap\Tests;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mattmy\FillMap\AttributeMapper;
use Mattmy\FillMap\InputKeyStyle;
use Mattmy\FillMap\MappingSource;
use Mattmy\FillMap\Tests\Fixtures\MappingModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use stdClass;

final class AttributeMappingTest extends TestCase
{
    /**
     * Supplies literal and derived source names, including competing input keys.
     *
     * @return iterable<string, array{InputKeyStyle, string}>
     */
    public static function styles(): iterable
    {
        yield 'exact' => [InputKeyStyle::Exact, 'end_time'];
        yield 'camel' => [InputKeyStyle::Camel, 'endTime'];
        yield 'snake' => [InputKeyStyle::Snake, 'end_time'];
        yield 'pascal' => [InputKeyStyle::Pascal, 'EndTime'];
        yield 'kebab' => [InputKeyStyle::Kebab, 'end-time'];
    }

    /** Uses only the configured key, regardless of source ordering. */
    #[DataProvider('styles')]
    public function test_maps_literal_names(InputKeyStyle $style, string $source): void
    {
        $input = ['end_time' => 1, 'endTime' => 2, $source => 3];
        foreach ([$input, \array_reverse($input, true)] as $ordered) {
            $chain = AttributeMapper::from($ordered)->forModel(MappingModel::class)->inputKeys($style);
            self::assertSame(['end_time' => 3], $chain->toArray());
        }
    }

    /** Derives non-ASCII keys through the installed Laravel version. */
    public function test_uses_laravel_naming_for_non_ascii(): void
    {
        $model = (new MappingModel())->fillable(['État_actif']);
        $chain = AttributeMapper::from([Str::camel('État_actif') => true])
            ->forModel($model)
            ->inputKeys(InputKeyStyle::Camel);

        self::assertSame(['État_actif' => true], $chain->toArray());
    }

    /** Clones settings, reads the current target once, and preserves value presence and order. */
    public function test_branches_use_current_fillable_and_preserve_raw_values(): void
    {
        $first = (new MappingModel())->fillable(['end_time', 'active', 'end_time']);
        $second = (new MappingModel())->fillable(['preferences']);
        $base = AttributeMapper::from(['active' => false, 'endTime' => null, 'end_time' => 'original', 'preferences' => [1 => 0]]);
        $camel = $base->forModel($first)->inputKeys(InputKeyStyle::Camel);
        $exact = $camel->inputKeys(InputKeyStyle::Exact);
        $other = $base->forModel($second);

        $reads = $first->fillableReads;
        self::assertSame(['end_time' => null, 'active' => false], $camel->toArray());
        self::assertSame($reads + 1, $first->fillableReads);
        self::assertSame(['end_time' => 'original', 'active' => false], $exact->toArray());
        self::assertSame(['preferences' => [1 => 0]], $other->toArray());
        $first->fillable(['end_time']);
        self::assertSame(['end_time' => 'original'], $exact->toArray());
    }

    /** Uses only explicitly listed attributes even when Eloquent is unguarded. */
    public function test_empty_fillable_and_unguarded(): void
    {
        $model = (new MappingModel())->fillable([]);
        $chain = AttributeMapper::from(['end_time' => 'provided'])->forModel($model);
        self::assertSame([], Model::unguarded($chain->toArray(...)));

        $this->expectException(InvalidArgumentException::class);
        $chain->aliases(['source' => 'end_time'])->toArray();
    }

    /** Treats aliases, dot names and explicit JSON paths as literal names. */
    public function test_alias_replaces_derived_key_and_preserves_literal_paths(): void
    {
        $model = (new MappingModel())->fillable(['end_time', 'options->enabled', 'profile.name']);
        $base = AttributeMapper::from([
            'end_time' => 'ignored',
            'profile' => ['name' => 'nested'],
            'profile.name' => 'literal',
            'options->enabled' => null,
            'jsonOption' => true,
        ])->forModel($model);

        self::assertSame(['options->enabled' => null, 'profile.name' => 'literal'], $base->aliases(['missing' => 'end_time'])->toArray());
        self::assertSame(['end_time' => 'literal', 'options->enabled' => null], $base
            ->aliases(['profile.name' => 'end_time', 'other' => 'profile.name'])
            ->toArray());
        self::assertSame(['end_time' => 'ignored', 'options->enabled' => true, 'profile.name' => 'literal'], $base
            ->aliases(['jsonOption' => 'options->enabled'])
            ->toArray());
    }

    /** A derived integer key is absent but a literal alias can still match it. */
    public function test_derived_integer_key_is_absent(): void
    {
        $chain = AttributeMapper::from(['valid' => 42])
            ->forModel((new MappingModel())->fillable(['1_']))
            ->inputKeys(InputKeyStyle::Camel);

        self::assertSame([], $chain->toArray());
        self::assertSame(['1_' => 42], $chain->aliases(['valid' => '1_'])->toArray());
    }

    /** Resolves source collisions from the branch's final settings, even without input. */
    public function test_detects_collision_at_output(): void
    {
        $base = AttributeMapper::from([])->forModel((new MappingModel())->fillable(['end_time', 'endTime']));
        self::assertSame([], $base->toArray());

        $this->expectException(InvalidArgumentException::class);
        $base->inputKeys(InputKeyStyle::Camel)->toArray();
    }

    /** Detects derived collisions even when the shared key cannot appear in the source. */
    public function test_detects_collision_of_derived_integer_keys(): void
    {
        $model = (new MappingModel())->fillable(['1_', '1-']);
        $chain = AttributeMapper::from([])->forModel($model)->inputKeys(InputKeyStyle::Camel);

        $this->expectException(InvalidArgumentException::class);
        $chain->toArray();
    }

    /** Checks aliases against the derived keys of the final naming style. */
    public function test_detects_alias_collision_only_with_the_conflicting_style(): void
    {
        $chain = AttributeMapper::from(['endTime' => 'date'])->forModel(MappingModel::class)
            ->aliases(['endTime' => 'active']);
        self::assertSame(['active' => 'date'], $chain->inputKeys(InputKeyStyle::Camel)->inputKeys(InputKeyStyle::Exact)->toArray());

        $this->expectException(InvalidArgumentException::class);
        $chain->inputKeys(InputKeyStyle::Exact)->inputKeys(InputKeyStyle::Camel)->toArray();
    }

    /** Exact naming still detects aliases that reuse another fillable attribute's source key. */
    public function test_detects_alias_collision_with_exact_keys(): void
    {
        $model = (new MappingModel())->fillable(['end_time', 'active']);
        $chain = AttributeMapper::from([])->forModel($model)->inputKeys(InputKeyStyle::Exact);

        $this->expectException(InvalidArgumentException::class);
        $chain->aliases(['active' => 'end_time'])->toArray();
    }

    /** Accepts an empty array but rejects an invalid top-level key immediately. */
    public function test_rejects_invalid_top_level_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AttributeMapper::from(['1' => 42]);
    }

    /**
     * Supplies ineligible source, alias, or target values.
     *
     * @return iterable<string, array{Closure(): mixed}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'empty input key' => [static fn () => AttributeMapper::from(['' => null])];
        $bound = static fn () => AttributeMapper::from([])->forModel(MappingModel::class);
        yield 'numeric alias source' => [static fn () => $bound()->aliases([1 => 'end_time'])];
        yield 'empty alias source' => [static fn () => $bound()->aliases(['' => 'end_time'])];
        yield 'non-string alias target' => [static fn () => $bound()->aliases(['first' => 0])];
        yield 'numeric alias target' => [static fn () => $bound()->aliases(['first' => '1'])];
        yield 'duplicate targets' => [static fn () => $bound()->aliases(['first' => 'end_time', 'second' => 'end_time'])];
        yield 'second aliases' => [static fn () => $bound()->aliases([])->inputKeys(InputKeyStyle::Exact)->aliases([])];
        yield 'non-model target' => [static fn () => (new ReflectionMethod(MappingSource::class, 'forModel'))->invoke(AttributeMapper::from([]), stdClass::class)];
        yield 'required constructor' => [static fn () => AttributeMapper::from([])->forModel(RequiredModel::class)];
    }

    /**
     * Reports invalid arguments before any terminal method.
     *
     * @param  Closure(): mixed  $operation
     */
    #[DataProvider('invalidSettings')]
    public function test_rejects_invalid_settings(Closure $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $operation();
    }

    /** Defer instantiation and propagate the actual constructor failure. */
    public function test_defers_construction_to_output(): void
    {
        $chain = AttributeMapper::from([])->forModel(ThrowingModel::class);
        $this->expectExceptionMessage('model constructor failure');
        $chain->toArray();
    }

    /** Rejects invalid fillable entries even for a filtered, empty source. */
    public function test_validates_entire_fillable_at_each_terminal(): void
    {
        foreach (['', '1', 1] as $invalid) {
            $model = new MappingModel(['active' => false]);
            (new ReflectionProperty(Model::class, 'fillable'))->setValue($model, ['end_time', $invalid]);
            $chain = AttributeMapper::from([])->forModel($model)->only('end_time');

            foreach ([$chain->toArray(...), $chain->collect(...), $chain->toModel(...)] as $output) {
                try {
                    $output();
                    self::fail('An invalid fillable attribute must fail.');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('getFillable()', $exception->getMessage());
                }
            }

            self::assertSame(['active' => false], $model->received);
        }
    }

    /** Exposes only model binding on each source, and only one binding per chain. */
    public function test_requires_model_before_configuring_or_producing_results(): void
    {
        $sources = [
            AttributeMapper::from(['end_time' => 'provided']),
            AttributeMapper::fromRequestInput(new Request(request: ['end_time' => 'provided']), ['end_time']),
        ];

        foreach ($sources as $source) {
            $methods = \array_diff(\get_class_methods($source), ['__construct']);
            self::assertSame(['forModel'], \array_values($methods));

            $chain = $source->forModel(MappingModel::class);
            self::assertNotContains('forModel', \get_class_methods($chain));
            self::assertSame(['end_time' => 'provided'], $chain->toArray());
        }
    }

    /** Preserves PHP references in prepared values. */
    public function test_prepared_nested_references_remain_live(): void
    {
        $value = true;
        $chain = AttributeMapper::from(['preferences' => ['email' => &$value]])->forModel(MappingModel::class);
        $value = false;
        self::assertSame(['preferences' => ['email' => false]], $chain->toArray());
    }
}

final class RequiredModel extends Model
{
    /** Makes this fixture ineligible for parameterless construction. */
    public function __construct(public readonly string $required)
    {
        parent::__construct();
    }
}

final class ThrowingModel extends Model
{
    /** Makes the constructor failure observable at output time. */
    public function __construct()
    {
        throw new RuntimeException('model constructor failure');
    }
}
