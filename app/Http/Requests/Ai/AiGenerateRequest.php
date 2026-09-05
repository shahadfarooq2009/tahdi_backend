<?php

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiFormRequest;

class AiGenerateRequest extends ApiFormRequest
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
            'category' => ['required', 'string', 'max:120'],
            'subject' => ['required', 'string', 'max:120'],
            'points' => ['required', 'integer', 'min:1', 'max:1000'],
            'count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'prompt' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
