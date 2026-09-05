<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class InitTextbookChunkedUploadRequest extends ApiFormRequest
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
            'title' => ['required', 'string', 'max:500'],
            'file_name' => ['required', 'string', 'max:255', 'regex:/\.pdf$/i'],
            'content_type' => ['required', 'string', Rule::in(['application/pdf'])],
            'file_size' => [
                'required',
                'integer',
                'min:1',
                'max:'.(int) config('uploads.purposes.textbook-pdf.max_bytes', 1024 * 1024 * 1024),
            ],
            'file_hash' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/i'],
            'academic_stage' => ['nullable', 'string', 'max:100'],
            'grade' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'uuid'],
            'academic_year' => ['nullable', 'string', 'max:50'],
            'semester' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:10'],
        ];
    }
}
