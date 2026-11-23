<?php

declare(strict_types=1);

namespace Mattmy\FillMap\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Mattmy\FillMap\AttributeMapper;
use Mattmy\FillMap\AttributeMapperServiceProvider;
use Mattmy\FillMap\InputKeyStyle;
use Mattmy\FillMap\Tests\Fixtures\MappingModel;
use Mattmy\FillMap\Tests\Fixtures\UpdateRequest;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class InputKeyConfigurationTest extends TestCase
{
    /** Keeps exact matching when the application uses the shipped default. */
    public function test_defaults_to_exact(): void
    {
        self::assertSame(InputKeyStyle::Exact, config('attribute-mapper.input_key_style'));
        self::assertSame(['end_time' => 'exact'], AttributeMapper::from(['end_time' => 'exact', 'endTime' => 'camel'])
            ->forModel(MappingModel::class)
            ->toArray());
    }

    /**
     * Supplies both the enum case and differently cased names.
     *
     * @return iterable<string, array{InputKeyStyle|string}>
     */
    public static function camelStyles(): iterable
    {
        yield 'enum' => [InputKeyStyle::Camel];
        yield 'lowercase' => ['camel'];
        yield 'title case' => ['Camel'];
        yield 'mixed case' => ['cAmEl'];
    }

    /** Applies the configured style to validated input and selected body fields. */
    #[DataProvider('camelStyles')]
    public function test_uses_the_configured_style_for_both_request_entry_points(InputKeyStyle|string $style): void
    {
        config(['attribute-mapper.input_key_style' => $style]);

        Route::patch('/configured-mapping', static function (UpdateRequest $request): array {
            $validated = AttributeMapper::from($request)->forModel(MappingModel::class)->toArray();
            $selected = AttributeMapper::fromRequestInput($request, ['endTime'])->forModel(MappingModel::class)->toArray();

            return [$validated, $selected];
        });

        $expected = ['end_time' => '2026-12-01'];
        $this->patchJson('/configured-mapping', ['endTime' => '2026-12-01'])->assertOk()->assertExactJson([$expected, $expected]);

        $request = Request::create('/', 'POST', ['endTime' => '2026-12-01']);
        self::assertSame([], AttributeMapper::fromRequestInput($request, ['end_time'])->forModel(MappingModel::class)->toArray());
    }

    /** Keeps explicit styles and aliases ahead of the application default. */
    public function test_overrides_the_default_without_changing_the_base_chain(): void
    {
        config(['attribute-mapper.input_key_style' => InputKeyStyle::Camel]);
        $base = AttributeMapper::from(['endTime' => 'camel', 'end_time' => 'exact', 'expiresAt' => 'alias'])
            ->forModel(MappingModel::class);

        self::assertSame(['end_time' => 'exact'], $base->inputKeys(InputKeyStyle::Exact)->collect()->all());
        self::assertSame(['end_time' => 'alias'], $base->aliases(['expiresAt' => 'end_time'])->toArray());
        self::assertSame(['end_time' => 'camel'], $base->toArray());
    }

    /**
     * Supplies invalid configuration values.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function invalidStyles(): iterable
    {
        yield 'unknown name' => ['camel-case'];
        yield 'empty name' => [''];
        yield 'array' => [[InputKeyStyle::Camel]];
        yield 'null' => [null];
    }

    /** Reports invalid configuration when the chain uses the application default. */
    #[DataProvider('invalidStyles')]
    public function test_rejects_invalid_configuration(mixed $style): void
    {
        config(['attribute-mapper.input_key_style' => $style]);
        $model = new MappingModel(['active' => false]);
        $chain = AttributeMapper::from(['end_time' => null])->forModel($model);

        self::assertSame(['end_time' => null], $chain->inputKeys(InputKeyStyle::Exact)->toArray());
        $chain = $chain->merge(['external_id' => 42]);
        foreach ([$chain->toArray(...), $chain->collect(...), $chain->toModel(...)] as $output) {
            try {
                $output();
                self::fail('An invalid input key style must fail.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('attribute-mapper.input_key_style', $exception->getMessage());
            }
        }

        self::assertSame(['active' => false], $model->received);
        self::assertArrayNotHasKey('external_id', $model->getAttributes());
    }

    /**
     * Loads the package configuration in the test application.
     *
     * @return list<class-string<AttributeMapperServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [AttributeMapperServiceProvider::class];
    }
}
