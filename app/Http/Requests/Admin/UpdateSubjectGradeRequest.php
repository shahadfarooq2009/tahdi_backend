<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use App\Support\Grades;

class UpdateSubjectGradeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'grade' => ['required', function ($attribute, $value, $fail) {
                if (! Grades::isValid((string) $value)) {
                    $fail('Invalid grade');
                }
            }],
            'remove' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('grade')) {
            $this->merge(['grade' => Grades::normalize((string) $this->grade)]);
        }
    }
}
