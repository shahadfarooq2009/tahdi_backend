<?php

namespace App\Http\Requests\Me;

use App\Http\Requests\ApiFormRequest;

class InspectPlaySetFileRequest extends ApiFormRequest
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
            'file' => [
                'required',
                'file',
                'max:'.(50 * 1024),
                'mimes:pdf,doc,docx,ppt,pptx',
            ],
        ];
    }
}
