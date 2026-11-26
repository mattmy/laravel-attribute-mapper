# Laravel Attribute Mapper

[English](README.md)

Laravel Attribute Mapper 把 Form Request 的驗證結果轉成屬性陣列，可用來新增或更新 Eloquent Model。

- 沿用 Model 的 `$fillable` 清單，限制請求資料可填入的屬性。
- 用 `inputKeys(InputKeyStyle::Camel)` 把 `camelCase` 的輸入欄位對應到 `snake_case` 的 Model 屬性。也能用別名指定個別屬性的來源鍵。
- 保留或排除指定屬性，也能補入更新者 ID、預設狀態等由應用程式提供的值。

## 系統需求

| Laravel | Composer 宣告相容版本 | CI 矩陣的 PHP 版本 |
| --- | --- | --- |
| 12.x | PHP 8.x 系列的 8.2 以上版本 | 8.2、8.3、8.4、8.5 |
| 13.x | PHP 8.x 系列的 8.3 以上版本 | 8.3、8.4、8.5 |

## 安裝

在 Laravel 專案根目錄執行以下指令。

```sh
composer require mattmy/laravel-attribute-mapper
```

## 快速開始

在 `app/Http/Requests/MappingExampleRequest.php` 建立這個 Form Request。

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

將下方預覽路由加入 `routes/web.php`。

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

開啟 `/mapping-example?startTime=2026-12-01&endTime=2026-12-02`，就會收到 `{"start_time":"2026-12-01","end_time":"2026-12-02"}`。

應用程式已經準備好資料時，也可以把陣列傳給 `AttributeMapper::from()`。

## 完整文件

[開始使用](https://mattmy.github.io/laravel-attribute-mapper-doc/zh-TW/guide/getting-started) · [API 參考](https://mattmy.github.io/laravel-attribute-mapper-doc/zh-TW/guide/api-reference)

[版本變更紀錄](CHANGELOG.md) · [MIT 授權條款](LICENSE)
