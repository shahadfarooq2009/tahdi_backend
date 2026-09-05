<?php

namespace App\Http\Requests\Me;

use App\Http\Requests\ApiFormRequest;

class GeneratePlaySetRequest extends ApiFormRequest
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
            'title' => ['nullable', 'string', 'max:500'],
            'page_from' => ['nullable', 'integer', 'min:1'],
            'page_to' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $from = $this->input('page_from');
            $to = $this->input('page_to');

            if (($from === null) xor ($to === null)) {
                $validator->errors()->add('page_from', 'يجب تحديد نطاق الصفحات من وإلى معاً');
            }

            if (is_numeric($from) && is_numeric($to) && (int) $from > (int) $to) {
                $validator->errors()->add('page_to', 'رقم النهاية يجب أن يكون أكبر من أو يساوي رقم البداية');
            }
        });
    }
}
