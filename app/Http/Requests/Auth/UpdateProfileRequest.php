<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
      'full_name' => ['sometimes', 'string', 'max:255'],
      'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
      'school' => ['sometimes', 'nullable', 'string', 'max:255'],
      'user_type' => [
        'sometimes',
        Rule::in(['teacher_man', 'teacher_women', 'student_man', 'student_lady']),
      ],
      'avatar_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
    ];
  }
}
