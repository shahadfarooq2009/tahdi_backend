<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class ChapterResolveRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject_id' => ['required', 'uuid'],
            'selected_chapter_id' => ['nullable', 'string'],
            'new_chapter_name' => ['nullable', 'string'],
        ];
    }
}
