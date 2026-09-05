<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Support\QuestionConstants;
use Illuminate\Validation\Rule;

class GenerateQuestionsRequest extends ApiFormRequest
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
            'lesson_key' => ['nullable', 'string', 'max:255'],
            'difficulty_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'points_value' => ['required', 'integer', Rule::in(QuestionConstants::POINT_VALUES)],
            'question_type' => ['nullable', 'string', Rule::in(QuestionConstants::TYPES)],
            'count' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
