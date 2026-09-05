<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Support\QuestionConstants;
use Illuminate\Validation\Rule;

class StoreQuestionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_type' => ['required', Rule::in(QuestionConstants::CHALLENGE_TYPES)],
            'question_text' => ['required', 'string', 'min:1'],
            'points_value' => ['required', Rule::in(QuestionConstants::POINT_VALUES)],
            'question_type' => ['nullable', Rule::in(QuestionConstants::TYPES)],
            'subject_id' => [
                Rule::requiredIf(fn () => in_array($this->input('challenge_type'), ['school', 'family'], true)),
                'nullable',
                'uuid',
            ],
            'category_id' => [
                Rule::requiredIf(fn () => $this->input('challenge_type') === 'family'),
                'nullable',
                'uuid',
            ],
            'chapter_id' => ['nullable', 'uuid'],
            'educational_stage' => [Rule::requiredIf(fn () => $this->input('challenge_type') === 'school')],
            'grade' => [Rule::requiredIf(fn () => $this->input('challenge_type') === 'school')],
            'chapter_resolution' => [Rule::requiredIf(fn () => $this->input('challenge_type') === 'school' && ! $this->filled('chapter_id'))],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('challenge_type') === 'family') {
            $this->merge(['chapter_id' => null]);
        }

        if ($this->input('challenge_type') === 'school') {
            $this->merge(['category_id' => null]);
        }
    }
}
