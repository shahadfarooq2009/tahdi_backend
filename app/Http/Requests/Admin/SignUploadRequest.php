<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class SignUploadRequest extends ApiFormRequest
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
            'purpose' => ['required', 'string'],
            'file_name' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'max:100'],
            'file_size' => ['required', 'integer', 'min:1'],
        ];
    }
}
