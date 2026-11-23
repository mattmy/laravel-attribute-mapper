<?php

declare(strict_types=1);

namespace Mattmy\FillMap\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mattmy\FillMap\AttributeMapper;
use Mattmy\FillMap\InputKeyStyle;
use Mattmy\FillMap\MappingSource;
use Mattmy\FillMap\Tests\Fixtures\UpdateRequest;
use Ramsey\Uuid\Uuid;
use RuntimeException;

require \getcwd() . '/vendor/autoload.php';
require_once __DIR__ . '/Fixtures/UpdateRequest.php';

/** Verifies an installed-package property even when PHP assertions are disabled. */
function verify(bool $condition, string $description): void
{
    if (! $condition) {
        throw new RuntimeException($description);
    }
}

/**
 * Extracts the Form Request and route from a README.
 *
 * @return list<string>
 */
function readmeQuickStart(string $readmeName): array
{
    $readme = \file_get_contents(\dirname(__DIR__) . '/' . $readmeName);
    if ($readme === false || \preg_match_all('/^```php\R(.*?)^```$/ms', $readme, $snippets) !== 2) {
        throw new RuntimeException("The quick-start example in {$readmeName} is missing.");
    }

    return $snippets[1];
}

$app = require \getcwd() . '/bootstrap/app.php';
if (! $app instanceof Application) {
    throw new RuntimeException('Expected a Laravel application in the working directory.');
}

$app->make(Kernel::class)->bootstrap();

$commodity = new class() extends Model
{
    public $timestamps = false;

    protected $table = 'commodities';

    /** @var list<string> */
    protected $fillable = ['end_time', 'uuid'];
};

$expectedStyle = match ($argv[1] ?? null) {
    null => InputKeyStyle::Exact,
    'camel-enum' => InputKeyStyle::Camel,
    'camel-string' => 'Camel',
    default => throw new RuntimeException('Unknown expected configuration for the smoke test.'),
};
verify(config('attribute-mapper.input_key_style') === $expectedStyle, 'The expected input key style was not loaded.');

if ($expectedStyle !== InputKeyStyle::Exact) {
    $attributes = AttributeMapper::from(['endTime' => '2026-12-01'])->forModel($commodity)->toArray();
    verify($attributes === ['end_time' => '2026-12-01'], 'The cached Camel setting did not map the input.');
    \fwrite(\STDOUT, "Cached Camel configuration check passed.\n");

    exit(0);
}

config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
Schema::create('commodities', static function (Blueprint $table): void {
    $table->id();
    $table->string('end_time')->nullable()->default('database-default');
    $table->string('uuid')->nullable();
});

verify(\class_exists(AttributeMapper::class), 'AttributeMapper autoload failed.');
verify(\enum_exists(InputKeyStyle::class), 'InputKeyStyle autoload failed.');
verify(\class_exists(MappingSource::class), 'MappingSource autoload failed.');
verify(\is_file(\getcwd() . '/vendor/mattmy/laravel-attribute-mapper/LICENSE'), 'The installed MIT license is missing.');

$quickStart = readmeQuickStart('README.md');
verify($quickStart === readmeQuickStart('README.zh-TW.md'), 'The README quick-start examples differ.');

foreach ($quickStart as $snippet) {
    $example = \tempnam(storage_path('framework'), 'fillmap-');
    if ($example === false) {
        throw new RuntimeException('Cannot create a temporary README example.');
    }

    try {
        $code = \str_starts_with($snippet, '<?php') ? $snippet : "<?php\n\n" . $snippet;
        verify(\file_put_contents($example, $code) !== false, 'Cannot write the README example.');
        require $example;
    } finally {
        \unlink($example);
    }
}

$response = $app->handle(Request::create('/mapping-example?startTime=2026-12-01&endTime=2026-12-02&uuid=ignored'));
verify($response->getStatusCode() === Response::HTTP_OK, 'The README route failed.');
$json = \json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
verify($json === ['start_time' => '2026-12-01', 'end_time' => '2026-12-02'], 'The README route returned unexpected attributes.');

Route::patch('/fillmap', static function (UpdateRequest $request) use ($commodity): array {
    $commodity = clone $commodity;
    $commodity->setAttribute('end_time', 'existing');

    $validated = AttributeMapper::from($request)->forModel($commodity)->inputKeys(InputKeyStyle::Camel);
    $selected = AttributeMapper::fromRequestInput($request, ['uuid'])->forModel($commodity)->toArray();
    $updated = $validated->merge($selected)->toModel();

    return ['attributes' => $validated->toArray(), 'selected' => $selected, 'updated' => $updated];
});

$uuid = Uuid::fromString('dc904a70-eb42-4460-96d2-86d75d08c3aa');
$body = '{"endTime":null,"uuid":"body-only"}';
$response = $app->handle(Request::create('/fillmap?endTime=query', 'PATCH', [], [], [], [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
], $body));
verify($response->getStatusCode() === Response::HTTP_OK, 'The request mapping route failed.');
$json = \json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
verify($json === [
    'attributes' => ['end_time' => null],
    'selected' => ['uuid' => 'body-only'],
    'updated' => ['end_time' => null, 'uuid' => 'body-only'],
], 'The request mapping route returned unexpected attributes.');

DB::connection()->enableQueryLog();
$chain = AttributeMapper::from([])->forModel($commodity::class);
$omitted = $chain->toModel();
$cleared = AttributeMapper::from(['end_time' => null])->forModel($commodity::class)->toModel();
verify(DB::connection()->getQueryLog() === [], 'Mapping executed a database query.');
DB::connection()->disableQueryLog();
verify($commodity->newQuery()->count() === 0, 'toModel() saved data automatically.');
$omitted->saveOrFail();
$cleared->saveOrFail();
verify($omitted->refresh()->getAttribute('end_time') === 'database-default', 'An omitted field lost its database default.');
verify($cleared->refresh()->getAttribute('end_time') === null, 'An explicit null did not reach the database.');

Str::createUuidsUsing(static fn () => $uuid);

try {
    foreach ([$chain->setUuid(), $chain->setUuid($uuid), $chain->setUuid($uuid->toString())] as $mapped) {
        verify($mapped->toArray() === ['uuid' => $uuid->toString()], 'A UUID did not normalize to a string.');
    }
} finally {
    Str::createUuidsNormally();
}

\fwrite(\STDOUT, "Clean Laravel install, both READMEs, SQLite persistence, and UUID checks passed.\n");
