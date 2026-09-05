<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class UploadTextbookFileRequest extends ApiFormRequest
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'يرجى اختيار ملف PDF.',
            'file.file' => 'الملف المرفوع غير صالح.',
            'file.mimetypes' => 'يجب أن يكون الملف بصيغة PDF.',
            'file.max' => 'حجم الملف أكبر من الحد المسموح.',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new \App\Exceptions\ValidationException(
            'تعذر رفع الكتاب.',
            $validator->errors()->toArray()
        );
    }
}
