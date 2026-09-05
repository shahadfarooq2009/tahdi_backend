<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class GenerateUnitQuestionsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unit_key' => ['nullable', 'string', 'max:255'],
            'auto_promote' => ['nullable', 'boolean'],
        ];
    }
}
