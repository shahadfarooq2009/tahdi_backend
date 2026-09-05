<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Support\QuestionConstants;
use Illuminate\Validation\Rule;

class ImportQuestionsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'uuid'],
            'subject_id' => ['required', 'uuid'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.question_text' => ['required', 'string'],
            'questions.*.answer_text' => ['required', 'string'],
            'questions.*.points_value' => ['required', Rule::in(QuestionConstants::POINT_VALUES)],
            'questions.*.choice_options' => ['nullable', 'array', 'max:4'],
            'questions.*.choice_options.*' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function importRows(): array
    {
        return collect($this->validated('questions'))->map(function (array $q) {
            $choiceOptions = collect($q['choice_options'] ?? [])
                ->map(fn ($option) => trim((string) $option))
                ->filter(fn (string $option) => $option !== '')
                ->values()
                ->all();

            return [
                'category_id' => $this->validated('category_id'),
                'subject_id' => $this->validated('subject_id'),
                'question_text' => trim($q['question_text']),
                'answer_text' => trim($q['answer_text']),
                'points_value' => (int) $q['points_value'],
                'chapter_id' => null,
                'question_source' => 'excel',
                'ai_generated' => false,
                'choice_options' => $choiceOptions !== [] ? $choiceOptions : null,
            ];
        })->all();
    }
}
