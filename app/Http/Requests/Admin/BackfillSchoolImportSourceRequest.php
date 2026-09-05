<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class BackfillSchoolImportSourceRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_id' => ['required', 'uuid'],
            'educational_stage' => ['nullable', 'string', 'max:120'],
            'grade' => ['nullable', 'string', 'max:120'],
            'course_id' => ['nullable', 'uuid'],
            'import_source' => ['required', 'string', 'max:255'],
        ];
    }
}
