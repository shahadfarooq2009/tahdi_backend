<?php

namespace App\Http\Requests\Me;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class SavePlaySetRequest extends ApiFormRequest
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
            'title' => ['nullable', 'string', 'max:500'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.question_text' => ['required', 'string', 'max:5000'],
            'questions.*.answer_text' => ['required', 'string', 'max:5000'],
            'questions.*.points_value' => ['nullable', 'integer', Rule::in([100, 200, 300, 400, 500])],
            'questions.*.is_approved' => ['nullable', 'boolean'],
            'questions.*.ai_generated' => ['nullable', 'boolean'],
        ];
    }
}
