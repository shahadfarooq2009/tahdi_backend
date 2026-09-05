<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class PatchTextbookStructureRequest extends ApiFormRequest
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
            'operations' => ['required_without:proposed_structure', 'array'],
            'operations.*.action' => ['required_with:operations', 'string'],
            'proposed_structure' => ['required_without:operations', 'array'],
        ];
    }
}
