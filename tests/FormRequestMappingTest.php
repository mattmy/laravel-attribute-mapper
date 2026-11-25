<?php

declare(strict_types=1);

namespace Mattmy\FillMap\Tests;

use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use Mattmy\FillMap\AttributeMapper;
use Mattmy\FillMap\InputKeyStyle;
use Mattmy\FillMap\Tests\Fixtures\MappingModel;
use Mattmy\FillMap\Tests\Fixtures\UpdateRequest;
use Orchestra\Testbench\TestCase;

final class FormRequestMappingTest extends TestCase
{
    /** Exercises the actual Form Request lifecycle before either mapper entry point. */
    protected function setUp(): void
    {
        parent::setUp();

        Route::patch('/validated', static function (UpdateRequest $request): array {
            $model = new MappingModel(['end_time' => 'existing']);

            $mapped = AttributeMapper::from($request)->forModel($model)->inputKeys(InputKeyStyle::Camel);
            $array = AttributeMapper::from($request->validated())->forModel($model)->inputKeys(InputKeyStyle::Camel);
            self::assertSame($array->toArray(), $mapped->toArray());

            $mapped->toModel();

            return ['received' => $model->received, 'end_time' => $model->getAttribute('end_time')];
        });

        Route::patch('/selected', static fn (UpdateRequest $request): array => AttributeMapper::fromRequestInput($request, ['uuid'])
            ->forModel(MappingModel::class)
            ->toArray());
    }

    /** Only the explicitly selected body path sees an unvalidated UUID. */
    public function test_compares_both_entry_points(): void
    {
        $data = ['endTime' => '2026-12-01', 'uuid' => 'body-value'];

        $this->patchJson('/validated', $data)->assertOk()->assertExactJson([
            'received' => ['end_time' => '2026-12-01'],
            'end_time' => '2026-12-01',
        ]);
        $this->patchJson('/selected', $data)->assertOk()->assertExactJson(['uuid' => 'body-value']);
    }

    /** Explicit body data can replace a validated value for the same attribute. */
    public function test_selected_body_value_overrides_the_validated_value_when_merged(): void
    {
        Route::patch('/combined', static function (UpdateRequest $request): array {
            $validated = AttributeMapper::from($request)->forModel(MappingModel::class)->inputKeys(InputKeyStyle::Camel);
            $request->merge(['endTime' => '2026-12-02']);
            $body = AttributeMapper::fromRequestInput($request, ['endTime'])->forModel(MappingModel::class)->inputKeys(InputKeyStyle::Camel);

            return [$validated->toArray(), $validated->merge($body->toArray())->toArray()];
        });

        $this->patchJson('/combined', ['endTime' => '2026-12-01'])->assertOk()->assertExactJson([
            ['end_time' => '2026-12-01'],
            ['end_time' => '2026-12-02'],
        ]);
    }

    /** Omission keeps the existing value and an explicit null clears it. */
    public function test_partial_updates_distinguish_omission_and_null(): void
    {
        $this->patchJson('/validated', [])->assertOk()->assertExactJson([
            'received' => [],
            'end_time' => 'existing',
        ]);
        $this->patchJson('/validated', ['endTime' => null])->assertOk()->assertExactJson([
            'received' => ['end_time' => null],
            'end_time' => null,
        ]);
    }

    /** Laravel denies invalid or unauthorized requests before invoking the controller. */
    public function test_request_lifecycle_rejects_both_routes(): void
    {
        foreach (['/validated', '/selected'] as $route) {
            $this->patchJson($route, ['endTime' => 'invalid-date'])->assertUnprocessable();
            $this->patchJson($route, ['denied' => true])->assertForbidden();
        }
    }

    /** Captures the current result and preserves its top-level and nested references. */
    public function test_captures_validated_result_at_construction(): void
    {
        $first = new UpdateRequest();
        $value = true;
        $factory = app(Factory::class);
        $validator = $this->createStub(Validator::class);
        $validator->method('validated')->willReturn(['preferences' => ['email' => &$value], 'active' => &$value]);
        $first->setValidator($validator);
        $source = AttributeMapper::from($first);
        $first->setValidator($factory->make([], []));
        $chain = $source->forModel(MappingModel::class);
        self::assertSame(['preferences' => ['email' => true], 'active' => true], $chain->toArray());

        $value = false;
        self::assertSame(['preferences' => ['email' => false], 'active' => false], $chain->toArray());
    }

    /** Applies input-key validation to a Form Request's validated data. */
    public function test_rejects_numeric_validated_key(): void
    {
        $request = new UpdateRequest();
        $request->setValidator(app(Factory::class)->make([1 => 'value'], [1 => 'string']));
        $this->expectException(InvalidArgumentException::class);

        AttributeMapper::from($request);
    }

    /** Rejects an empty validated key before binding a model. */
    public function test_rejects_empty_validated_key(): void
    {
        $request = new UpdateRequest();
        $validator = $this->createStub(Validator::class);
        $validator->method('validated')->willReturn(['' => 'value']);
        $request->setValidator($validator);

        $this->expectException(InvalidArgumentException::class);
        AttributeMapper::from($request);
    }
}
