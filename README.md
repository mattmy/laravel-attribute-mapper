# Laravel Attribute Mapper

[繁體中文](README.zh-TW.md)

Laravel Attribute Mapper turns a Form Request's validated data into the attribute array you need to create or update an Eloquent model.

- Reuse your model's `$fillable` list to limit which attributes request data can populate.
- Match `camelCase` input to `snake_case` attributes with `inputKeys(InputKeyStyle::Camel)`. Use an alias to choose the input key for a specific attribute.
- Keep or exclude specific attributes and add values from your app, such as the editor's ID or a default status.

## Requirements

| Laravel | Composer compatibility | PHP versions in the CI matrix |
| --- | --- | --- |
| 12.x | PHP 8.2 or later in the 8.x series | 8.2, 8.3, 8.4, 8.5 |
| 13.x | PHP 8.3 or later in the 8.x series | 8.3, 8.4, 8.5 |

## Installation

Run this from your Laravel project root.

```sh
composer require mattmy/laravel-attribute-mapper
```

## Quick start

Create this Form Request in `app/Http/Requests/MappingExampleRequest.php`.

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MappingExampleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'startTime' => ['sometimes', 'date'],
            'endTime' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
```

Add this preview route to `routes/web.php`.

```php
use App\Http\Requests\MappingExampleRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Mattmy\FillMap\AttributeMapper;
use Mattmy\FillMap\InputKeyStyle;

Route::get('/mapping-example', static function (MappingExampleRequest $request): array {
    $model = new class() extends Model
    {
        protected $fillable = ['start_time', 'end_time'];
    };

    return AttributeMapper::from($request)
        ->forModel($model)
        ->inputKeys(InputKeyStyle::Camel)
        ->toArray();
});
```

Visit `/mapping-example?startTime=2026-12-01&endTime=2026-12-02` to get `{"start_time":"2026-12-01","end_time":"2026-12-02"}`.

When your app already has the data in an array, you can pass that array to `AttributeMapper::from()`.

## Documentation

[Getting started](https://mattmy.github.io/laravel-attribute-mapper-doc/guide/getting-started) · [API reference](https://mattmy.github.io/laravel-attribute-mapper-doc/guide/api-reference)

[Changelog](CHANGELOG.md) · [MIT License](LICENSE)
