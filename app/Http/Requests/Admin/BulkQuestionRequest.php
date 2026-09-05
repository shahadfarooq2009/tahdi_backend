<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class BulkQuestionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['uuid'],
            'rejection_reason' => ['nullable', 'string'],
        ];
    }
}
