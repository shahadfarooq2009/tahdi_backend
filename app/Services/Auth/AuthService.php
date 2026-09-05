<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthService
{
  /**
   * @param  array{email: string, password: string}  $credentials
   * @return array{token: string, expires_at: int, user: array<string, mixed>}
   */
  public function login(array $credentials): array
  {
    $user = User::query()
      ->where('email', $credentials['email'])
      ->where('is_deleted', false)
      ->first();

    if (! $user || ! filled($user->password) || ! Hash::check($credentials['password'], $user->password)) {
      throw new ApiException('البريد الإلكتروني أو كلمة المرور غير صحيحة', 401, 'INVALID_CREDENTIALS');
    }

    if ($user->is_active === false) {
      throw new ApiException('الحساب غير نشط. تواصل مع الدعم.', 403, 'ACCOUNT_INACTIVE');
    }

    return $this->issueTokenResponse($user);
  }

  /**
   * @param  array<string, mixed>  $data
   * @return array{token: string, expires_at: int, user: array<string, mixed>}
   */
  public function register(array $data): array
  {
    $existing = User::query()->where('email', $data['email'])->first();

    if ($existing && $existing->is_deleted === false) {
      throw new ApiException('هذا البريد الإلكتروني مسجل مسبقاً', 422, 'EMAIL_TAKEN');
    }

    $role = str_starts_with((string) ($data['user_type'] ?? ''), 'teacher_') ? 'editor' : 'user';

    $user = User::query()->updateOrCreate(
      ['email' => $data['email']],
      [
        'full_name' => $data['full_name'],
        'phone' => $data['phone'] ?? null,
        'school' => $data['school'] ?? null,
        'user_type' => $data['user_type'],
        'role' => $role,
        'password' => $data['password'],
        'auth_provider' => 'email',
        'is_active' => true,
        'is_deleted' => false,
        'email_verified_at' => now(),
      ],
    );

    return $this->issueTokenResponse($user);
  }

  public function logout(User $user): void
  {
    $token = $user->currentAccessToken();

    if ($token !== null) {
      $token->delete();
    }
  }

  public function sendPasswordResetLink(string $email): string
  {
    $normalizedEmail = strtolower(trim($email));

    $user = User::query()
      ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
      ->where('is_deleted', false)
      ->first();

    // Do not reveal whether the email exists.
    $successMessage = 'إذا كان البريد مسجلاً لدينا، فستصلك رسالة لإعادة تعيين كلمة المرور';

    if (! $user) {
      return $successMessage;
    }

    $status = Password::sendResetLink(['email' => $user->email]);

    if ($status !== Password::RESET_LINK_SENT) {
      throw new ApiException('تعذر إرسال رابط إعادة التعيين. حاول مرة أخرى لاحقاً.', 422, 'RESET_LINK_FAILED');
    }

    return $successMessage;
  }

  /**
   * Set a Laravel password for an existing legacy account without changing id/role/profile.
   */
  public function setPasswordForExistingUser(User $user, string $plainPassword): User
  {
    $originalId = $user->id;
    $originalRole = $user->role;

    $user->forceFill([
      'password' => $plainPassword,
      'remember_token' => Str::random(60),
      'email_verified_at' => $user->email_verified_at ?? now(),
    ])->save();

    $user->refresh();

    if ($user->id !== $originalId) {
      throw new ApiException('تعذر تحديث كلمة المرور للحساب الحالي', 500, 'LEGACY_PASSWORD_UPDATE_FAILED');
    }

    if ($user->role !== $originalRole) {
      throw new ApiException('تعذر تحديث كلمة المرور دون تغيير الصلاحيات', 500, 'LEGACY_PASSWORD_UPDATE_FAILED');
    }

    $user->tokens()->delete();

    return $user;
  }

  /**
   * @param  array{email: string, token: string, password: string}  $data
   */
  public function resetPassword(array $data): string
  {
    $status = Password::reset(
      [
        'email' => $data['email'],
        'password' => $data['password'],
        'password_confirmation' => $data['password'],
        'token' => $data['token'],
      ],
      function (User $user, string $password): void {
        $user->forceFill([
          'password' => $password,
          'remember_token' => Str::random(60),
          'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $user->tokens()->delete();

        event(new PasswordReset($user));
      }
    );

    if ($status !== Password::PASSWORD_RESET) {
      throw new ApiException('رابط إعادة تعيين كلمة المرور غير صالح أو منتهي الصلاحية', 422, 'RESET_FAILED');
    }

    return 'تم تحديث كلمة المرور بنجاح';
  }

  /**
   * @return array{url: string}
   */
  public function googleRedirectUrl(): array
  {
    $url = Socialite::driver('google')
      ->stateless()
      ->redirect()
      ->getTargetUrl();

    return ['url' => $url];
  }

  /**
   * @return array{token: string, expires_at: int, user: array<string, mixed>}
   */
  public function handleGoogleCallback(): array
  {
    $googleUser = Socialite::driver('google')->stateless()->user();

    $user = User::query()
      ->where('google_id', $googleUser->getId())
      ->orWhere('email', $googleUser->getEmail())
      ->first();

    if ($user) {
      $user->fill([
        'google_id' => $googleUser->getId(),
        'auth_provider' => 'google',
        'full_name' => $user->full_name ?: $googleUser->getName(),
        'email_verified_at' => $user->email_verified_at ?? now(),
        'is_active' => true,
        'is_deleted' => false,
      ])->save();
    } else {
      $user = User::query()->create([
        'full_name' => $googleUser->getName() ?: 'مستخدم',
        'email' => $googleUser->getEmail(),
        'google_id' => $googleUser->getId(),
        'auth_provider' => 'google',
        'user_type' => 'student_man',
        'role' => 'user',
        'email_verified_at' => now(),
        'is_active' => true,
        'is_deleted' => false,
      ]);
    }

    if ($user->is_active === false) {
      throw new ApiException('الحساب غير نشط. تواصل مع الدعم.', 403, 'ACCOUNT_INACTIVE');
    }

    return $this->issueTokenResponse($user);
  }

  /**
   * @param  array<string, mixed>  $updates
   * @return array<string, mixed>
   */
  public function updateProfile(User $user, array $updates): array
  {
    $allowed = ['full_name', 'phone', 'school', 'user_type', 'avatar_url'];
    $payload = array_intersect_key($updates, array_flip($allowed));

    if (isset($payload['user_type']) && str_starts_with((string) $payload['user_type'], 'teacher_')) {
      if ($user->role === 'user') {
        $payload['role'] = 'editor';
      }
    }

    $user->fill($payload)->save();

    return $user->fresh()->toProfileArray();
  }

  /**
   * @return array{token: string, expires_at: int, user: array<string, mixed>}
   */
  public function issueTokenResponse(User $user): array
  {
    $user->tokens()->where('name', 'spa')->delete();

    $expiresAt = now()->addMinutes((int) config('sanctum.expiration', 10080));
    $token = $user->createToken('spa', ['*'], $expiresAt);

    $profile = $user->toProfileArray();
    $profile['role'] = Roles::isValidRole($profile['role'] ?? null) ? $profile['role'] : 'user';

    return [
      'token' => $token->plainTextToken,
      'expires_at' => $expiresAt->timestamp,
      'user' => [
        'id' => $user->id,
        'email' => $user->email,
        'role' => $profile['role'],
        'auth_provider' => $user->auth_provider,
        'profile' => $profile,
      ],
    ];
  }
}
