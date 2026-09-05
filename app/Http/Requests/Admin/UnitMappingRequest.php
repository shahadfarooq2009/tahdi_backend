<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class UnitMappingRequest extends ApiFormRequest
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
            'chapter_id' => ['required', 'uuid'],
            'unit_key' => ['required', 'string', 'max:255'],
        ];
    }
}
