<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class ProcessTextbookFileRequest extends ApiFormRequest
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
        $maxKilobytes = (int) config('uploads.purposes.textbook-pdf.max_bytes', 100 * 1024 * 1024) / 1024;

        return [
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.$maxKilobytes],
            'title' => ['required', 'string', 'max:500'],
            'academic_stage' => ['nullable', 'string', 'max:100'],
            'grade' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'uuid'],
            'academic_year' => ['nullable', 'string', 'max:50'],
            'semester' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:10'],
        ];
    }
}
