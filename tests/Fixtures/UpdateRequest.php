<?php

declare(strict_types=1);

namespace Mattmy\FillMap\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateRequest extends FormRequest
{
    /** Allows the test route to exercise Laravel's authorization failure. */
    public function authorize(): bool
    {
        return ! $this->boolean('denied');
    }

    /**
     * Allows omission and explicit clearing of the mapped date.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['endTime' => ['sometimes', 'nullable', 'date']];
    }
}
