<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
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
      'email' => ['required', 'email'],
      'password' => ['required', 'string'],
    ];
  }

  /**
   * @return array<string, string>
   */
  public function messages(): array
  {
    return [
      'email.required' => 'البريد الإلكتروني مطلوب',
      'email.email' => 'صيغة البريد الإلكتروني غير صحيحة',
      'password.required' => 'كلمة المرور مطلوبة',
    ];
  }
}
