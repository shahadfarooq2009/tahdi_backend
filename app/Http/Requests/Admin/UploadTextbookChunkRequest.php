<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class UploadTextbookChunkRequest extends ApiFormRequest
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
        $maxKb = (int) ceil(((int) config('textbook_upload.chunk_request_max_bytes', 17 * 1024 * 1024)) / 1024);

        return [
            'chunk' => ['required', 'file', 'max:'.$maxKb],
            'chunk_size' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
