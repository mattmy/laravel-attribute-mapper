<?php

declare(strict_types=1);

namespace Mattmy\FillMap;

use Illuminate\Support\ServiceProvider;

final class AttributeMapperServiceProvider extends ServiceProvider
{
    /** Loads defaults for options the application has not configured. */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/attribute-mapper.php', 'attribute-mapper');
    }

    /** Makes the package configuration available for publishing. */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/attribute-mapper.php' => config_path('attribute-mapper.php'),
        ], 'attribute-mapper-config');
    }
}
