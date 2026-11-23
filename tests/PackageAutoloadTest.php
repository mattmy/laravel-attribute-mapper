<?php

declare(strict_types=1);

namespace Mattmy\FillMap\Tests;

use Mattmy\FillMap\AttributeMapper;
use Mattmy\FillMap\InputKeyStyle;
use Mattmy\FillMap\MappingSource;
use PHPUnit\Framework\TestCase;

final class PackageAutoloadTest extends TestCase
{
    /** Confirms the public types load without starting Laravel. */
    public function test_public_types_load_from_composer(): void
    {
        self::assertTrue(\class_exists(AttributeMapper::class));
        self::assertTrue(\enum_exists(InputKeyStyle::class));
        self::assertTrue(\class_exists(MappingSource::class));
    }
}
