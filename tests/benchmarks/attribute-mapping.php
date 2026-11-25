<?php

declare(strict_types=1);

namespace Mattmy\FillMap\Tests;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;
use Mattmy\FillMap\AttributeMapper;
use Mattmy\FillMap\InputKeyStyle;
use RuntimeException;

require \dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Warms the operation, then returns its median elapsed time in milliseconds.
 *
 * @param  Closure(): mixed  $operation
 */
function medianMilliseconds(Closure $operation, int $rows): float
{
    for ($index = 0; $index < 250; $index++) {
        $operation();
    }

    $samples = [];
    for ($round = 0; $round < 5; $round++) {
        $start = \hrtime(true);
        for ($index = 0; $index < $rows; $index++) {
            $operation();
        }

        $samples[] = (\hrtime(true) - $start) / 1_000_000;
    }

    \sort($samples);

    return $samples[\intdiv(\count($samples), 2)];
}

\printf("PHP %s, Laravel %s, %s\n", \PHP_VERSION, InstalledVersions::getPrettyVersion('laravel/framework') ?? 'unknown', \PHP_OS_FAMILY);
foreach ([10, 100] as $width) {
    $model = new class() extends Model
    {
        public static int $width = 100;

        /** @var list<string> */
        protected $fillable = [];

        /** Assigns the benchmark fixture's fillable list without framework or database setup. */
        public function __construct()
        {
            for ($index = 0; $index < self::$width; $index++) {
                $this->fillable[] = "sample_field_{$index}";
            }
        }
    };

    $model::$width = $width;
    $model = new ($model::class);
    $row = [];
    $fillable = $model->getFillable();
    foreach ($fillable as $index => $name) {
        if ($index % 5 !== 0) {
            $key = Str::camel($name);
            if ($key === '') {
                throw new RuntimeException('The benchmark expects nonempty input keys.');
            }

            $row[$key] = $index % 3 === 0 ? null : $index;
        }
    }

    $direct = static function () use ($model, $row): array {
        $mapped = [];
        foreach ($model->getFillable() as $field) {
            $key = Str::camel($field);
            if (\array_key_exists($key, $row)) {
                $mapped[$field] = $row[$key];
            }
        }

        return $mapped;
    };
    $classChain = static fn () => AttributeMapper::from($row)->forModel($model::class)->inputKeys(InputKeyStyle::Camel);
    $instanceChain = static fn () => AttributeMapper::from($row)->forModel($model)->inputKeys(InputKeyStyle::Camel);
    if ($instanceChain()->toArray() !== $direct() || $classChain()->toArray() !== $direct()) {
        throw new RuntimeException('The benchmark and the direct mapping produce different attributes.');
    }

    $request = new Request(request: $row);
    $keys = \array_keys($row);
    $formRequest = FormRequest::create('/', 'POST', $row);
    $validator = new Validator(new Translator(new ArrayLoader(), 'en'), $row, \array_fill_keys($keys, ['nullable', 'integer']));
    if ($validator->validate() !== $row) {
        throw new RuntimeException('The benchmark expects the validated request to preserve the input row.');
    }

    $formRequest->setValidator($validator);
    $operations = [
        'direct' => $direct,
        'array / class' => static fn () => $classChain()->toArray(),
        'array / instance' => static fn () => $instanceChain()->toArray(),
        'fill / instance' => static fn () => $instanceChain()->toModel(),
        'validated input' => static fn () => AttributeMapper::from($formRequest),
        'body selection' => static fn () => AttributeMapper::fromRequestInput($request, $keys),
    ];

    foreach ([1_000, 10_000] as $rows) {
        foreach ($operations as $name => $operation) {
            $milliseconds = medianMilliseconds($operation, $rows);
            \printf("%3d fields %5d rows %-16s %9.3f ms %7.3f us/row\n", $width, $rows, $name, $milliseconds, 1000 * $milliseconds / $rows);
        }
    }
}
