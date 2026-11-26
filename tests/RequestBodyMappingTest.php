<?php

declare(strict_types=1);

namespace Mattmy\FillMap\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use InvalidArgumentException;
use LogicException;
use Mattmy\FillMap\AttributeMapper;
use Mattmy\FillMap\InputKeyStyle;
use Mattmy\FillMap\Tests\Fixtures\MappingModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;

final class RequestBodyMappingTest extends TestCase
{
    /** Uses the current JSON container, including Laravel merges, and captures it only once. */
    public function test_selects_from_current_json_container(): void
    {
        $request = Request::create('/?uuid=from-query', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"endTime":null,"EndTime":"other","preferences":{"1":[false]}}');
        $request->merge(['uuid' => $request->query('uuid')]);
        $source = AttributeMapper::fromRequestInput($request, ['endTime', 'endTime', 'preferences', 'uuid']);
        $request->merge(['endTime' => 'changed before binding']);
        $chain = $source->forModel(MappingModel::class)->inputKeys(InputKeyStyle::Camel);
        $expected = ['end_time' => null, 'uuid' => 'from-query', 'preferences' => [1 => [false]]];
        $before = $chain->toArray();
        $request->merge(['endTime' => 'changed after binding']);
        self::assertSame([$expected, $expected], [$before, $chain->toArray()]);
    }

    /** Reads named form fields without looking in unrelated request sources. */
    public function test_does_not_fall_back_to_other_request_sources(): void
    {
        $request = Request::create('/from-route?uuid=query', 'POST', ['endTime' => null, 'EndTime' => 'ignored', 'extra' => 'not fillable'], [
            'id' => 'cookie',
        ], ['preferences' => UploadedFile::fake()->create('sample.txt')], ['HTTP_ACTIVE' => 'header']);
        $route = new Route(['POST'], '/{userId}', static fn (): null => null);
        $route->bind($request);
        $request->setRouteResolver(static fn (): Route => $route);
        self::assertSame('from-route', $request->route('userId'));

        $chain = AttributeMapper::fromRequestInput($request, ['endTime', 'uuid', 'id', 'preferences', 'active', 'extra', 'userId'])
            ->forModel(MappingModel::class)
            ->inputKeys(InputKeyStyle::Camel);

        self::assertSame(['end_time' => null], $chain->toArray());
    }

    /** A casing mismatch leaves the selected key absent. */
    public function test_selection_is_case_sensitive(): void
    {
        $request = new Request(request: ['EndTime' => 'provided']);
        $chain = AttributeMapper::fromRequestInput($request, ['endTime'])->forModel(MappingModel::class)
            ->inputKeys(InputKeyStyle::Camel);

        self::assertSame([], $chain->toArray());
    }

    /** An empty selection needs no access to the request body. */
    public function test_empty_selection_does_not_read_the_body(): void
    {
        $request = new class() extends Request
        {
            /** Fails if the mapper tries to select a body container. */
            public function isJson(): bool
            {
                throw new LogicException('An empty selection must not access the request body.');
            }
        };

        self::assertSame([], AttributeMapper::fromRequestInput($request, [])->forModel(MappingModel::class)->toArray());
    }

    /** Leaves Laravel's malformed JSON handling intact and allows explicit supplementing. */
    public function test_malformed_json_gives_empty_source(): void
    {
        $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{broken');
        $chain = AttributeMapper::fromRequestInput($request, ['uuid'])->forModel(MappingModel::class);

        self::assertSame([], $chain->toArray());
        self::assertSame(['uuid' => 'provided'], $chain->merge(['uuid' => 'provided'])->toArray());
    }

    /**
     * Supplies invalid list shapes and names.
     *
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty name' => [['']];
        yield 'numeric name' => [['1']];
        yield 'number' => [[1]];
        yield 'sparse list' => [[1 => 'uuid']];
        yield 'associative list' => [['key' => 'uuid']];
    }

    /**
     * Rejects malformed key selections before constructing a chain.
     *
     * @param  array<array-key, mixed>  $keys
     */
    #[DataProvider('invalidKeys')]
    public function test_rejects_invalid_keys(array $keys): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ReflectionMethod(AttributeMapper::class, 'fromRequestInput'))->invoke(null, new Request(), $keys);
    }

    /** Ignores an invalid unselected value and immediately rejects it once selected. */
    public function test_rejects_object_only_when_selected(): void
    {
        $request = new Request(request: ['active' => true, 'extra' => [new stdClass()]]);
        self::assertSame(['active' => true], AttributeMapper::fromRequestInput($request, ['active'])->forModel(MappingModel::class)->toArray());
        $this->expectException(InvalidArgumentException::class);

        AttributeMapper::fromRequestInput($request, ['extra']);
    }

    /** Rejects resources nested in an included field. */
    public function test_rejects_resource(): void
    {
        $stream = \fopen('php://memory', 'r+');
        self::assertIsResource($stream);

        try {
            $this->expectException(InvalidArgumentException::class);
            AttributeMapper::fromRequestInput(new Request(request: ['preferences' => [$stream]]), ['preferences']);
        } finally {
            \fclose($stream);
        }
    }

    /** Stops on back-edges in selected request values. */
    public function test_rejects_circular_arrays(): void
    {
        $cyclic = [];
        $cyclic['loop'] = &$cyclic;
        $this->expectException(InvalidArgumentException::class);
        AttributeMapper::fromRequestInput(new Request(request: ['preferences' => $cyclic]), ['preferences']);
    }

    /** Copies top-level and shared nested references while retaining their key order. */
    public function test_captures_references_by_value(): void
    {
        $value = true;
        $shared = [3 => &$value, 'tail' => 0];
        $body = ['active' => &$value, 'preferences' => ['first' => &$shared, 'second' => &$shared]];
        $request = new Request(request: $body);
        $chain = AttributeMapper::fromRequestInput($request, ['active', 'preferences'])->forModel(MappingModel::class);

        $expected = ['preferences' => ['first' => [3 => true, 'tail' => 0], 'second' => [3 => true, 'tail' => 0]], 'active' => true];
        $value = false;
        $afterFirstChange = $chain->toArray();
        $value = new stdClass();
        self::assertSame([$expected, $expected], [$afterFirstChange, $chain->toArray()]);
    }

    /** Preserves string and integer keys that PHP can store separately. */
    public function test_preserves_nested_integer_like_string_keys(): void
    {
        $key = '1';
        $$key = ['label' => 'string key'];
        $flag = true;
        $preferences = \compact($key) + [1 => &$flag];
        $request = new Request(request: ['preferences' => $preferences]);
        $chain = AttributeMapper::fromRequestInput($request, ['preferences'])->forModel(MappingModel::class);
        $flag = false;

        $actual = $chain->toArray()['preferences'];
        self::assertIsArray($actual);
        self::assertSame(['1', 1], \array_keys($actual));
        self::assertSame([['label' => 'string key'], true], \array_values($actual));
    }

    /** Accepts larger legal selections and preserves all nested array keys. */
    public function test_preserves_broad_and_deep_values(): void
    {
        $nested = [1 => true];
        for ($depth = 0; $depth < 70; $depth++) {
            $nested = ['next' => $nested];
        }

        $body = [];
        for ($index = 0; $index < 100; $index++) {
            $body["field_{$index}"] = $nested;
        }

        $keys = \array_keys($body);
        $model = (new MappingModel())->fillable($keys);
        self::assertSame($body, AttributeMapper::fromRequestInput(new Request(request: $body), $keys)->forModel($model)->toArray());
    }
}
