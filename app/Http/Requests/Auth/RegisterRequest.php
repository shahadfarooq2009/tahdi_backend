<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
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
      'full_name' => ['required', 'string', 'max:255'],
      'email' => ['required', 'email', 'max:255'],
      'password' => ['required', 'string', 'min:6'],
      'phone' => ['nullable', 'string', 'max:50'],
      'school' => ['nullable', 'string', 'max:255'],
      'user_type' => [
        'required',
        Rule::in(['teacher_man', 'teacher_women', 'student_man', 'student_lady']),
      ],
    ];
  }

  /**
   * @return array<string, string>
   */
  public function messages(): array
  {
    return [
      'full_name.required' => 'الاسم الكامل مطلوب',
      'email.required' => 'البريد الإلكتروني مطلوب',
      'email.email' => 'صيغة البريد الإلكتروني غير صحيحة',
      'password.required' => 'كلمة المرور مطلوبة',
      'password.min' => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل',
      'user_type.required' => 'نوع المستخدم مطلوب',
    ];
  }
}
