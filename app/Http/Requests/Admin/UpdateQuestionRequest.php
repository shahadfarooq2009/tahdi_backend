<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Support\QuestionConstants;
use Illuminate\Validation\Rule;

class UpdateQuestionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_type' => ['nullable', Rule::in(QuestionConstants::CHALLENGE_TYPES)],
            'question_text' => ['nullable', 'string', 'min:1'],
            'points_value' => ['nullable', Rule::in(QuestionConstants::POINT_VALUES)],
            'question_type' => ['nullable', Rule::in(QuestionConstants::TYPES)],
            'subject_id' => ['nullable', 'uuid'],
            'category_id' => ['nullable', 'uuid'],
            'chapter_id' => ['nullable', 'uuid'],
        ];
    }
}
