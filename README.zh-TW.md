# Laravel Attribute Mapper

[English](README.md)

Laravel Attribute Mapper 可以把 Form Request 的驗證結果，直接轉成建立或更新 Eloquent Model 要用的屬性陣列。

- **以 Form Request 和 `$fillable` 為準。** 套件依驗證結果和 Model 的可填清單產生屬性陣列，省去另外維護一份欄位清單。
- **可指定輸入欄位的命名規則。** `inputKeys()` 可設定命名規則。例如，`inputKeys(InputKeyStyle::Camel)` 可將 `camelCase` 的請求欄位對應到 Model 的 `snake_case` 屬性。
- **可調整映射結果。** 支援保留或排除指定屬性，也能補入更新者 ID、預設狀態等由應用程式決定的值。

## 系統需求

| Laravel | Composer 宣告相容版本 | CI 矩陣的 PHP 版本 |
| --- | --- | --- |
| 12.x | PHP 8.x 系列的 8.2 以上版本 | 8.2、8.3、8.4、8.5 |
| 13.x | PHP 8.x 系列的 8.3 以上版本 | 8.3、8.4、8.5 |

## 安裝

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

將下方預覽路由加入 `routes/web.php`，重複的 `use` 只留一份。

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

開啟 `/mapping-example?startTime=2026-12-01&endTime=2026-12-02`，就會收到 `{"start_time":"2026-12-01","end_time":"2026-12-02"}`。建立和更新資料的控制器也能用同樣的寫法產生屬性陣列。

應用程式已經準備好資料時，也可以把陣列傳給 `AttributeMapper::from()`。

## 完整文件

想知道怎麼讀取其他請求資料、調整欄位對照或處理錯誤，可以看[完整文件](https://mattmy.github.io/laravel-attribute-mapper-doc/zh-TW/guide/getting-started)。

[版本變更紀錄](CHANGELOG.md) · [MIT 授權條款](LICENSE)
