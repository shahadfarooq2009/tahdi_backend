<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class GeneratedQuestionReviewRequest extends ApiFormRequest
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
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'chapter_id' => ['nullable', 'uuid'],
            'create_chapter' => ['nullable', 'boolean'],
        ];
    }
}
