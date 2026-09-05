<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Support\Grades;
use App\Support\QuestionConstants;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'min:1'],
            'challenge_type' => ['nullable', Rule::in(QuestionConstants::CHALLENGE_TYPES)],
            'category_id' => ['nullable', 'uuid'],
            'grades' => ['nullable', 'array'],
            'grades.*' => [function ($attribute, $value, $fail) {
                if (! Grades::isValid((string) $value)) {
                    $fail('Invalid grade');
                }
            }],
            'question_type' => ['nullable', 'string'],
            'icon_url' => ['nullable', 'string'],
            'stage_icons' => ['nullable', 'array'],
            'stage_icons.primary' => ['nullable', 'string'],
            'stage_icons.middle' => ['nullable', 'string'],
            'stage_icons.high' => ['nullable', 'string'],
            'color_hex' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'is_high_school_parent' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->name)) {
            $this->merge(['name' => trim($this->name)]);
        }

        if ($this->has('grades') && is_array($this->grades)) {
            $this->merge([
                'grades' => collect($this->grades)->map(fn ($g) => Grades::normalize((string) $g))->unique()->values()->all(),
            ]);
        }
    }
}
