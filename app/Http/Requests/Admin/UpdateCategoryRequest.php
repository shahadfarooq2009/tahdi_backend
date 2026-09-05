<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class UpdateCategoryRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'min:1'],
            'description' => ['nullable', 'string'],
            'icon_url' => ['nullable', 'string'],
            'color_hex' => ['nullable', 'string'],
        ];
    }
}
