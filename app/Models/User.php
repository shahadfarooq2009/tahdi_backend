<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'user_profiles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'full_name',
        'email',
        'phone',
        'school',
        'user_type',
        'role',
        'avatar_url',
        'password',
        'google_id',
        'auth_provider',
        'is_active',
        'is_deleted',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! filled($user->id)) {
                $user->id = (string) Str::uuid();
            }

            if (! filled($user->role)) {
                $user->role = str_starts_with((string) $user->user_type, 'teacher_')
                    ? 'editor'
                    : 'user';
            }

            if ($user->is_active === null) {
                $user->is_active = true;
            }

            if ($user->is_deleted === null) {
                $user->is_deleted = false;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_deleted' => 'boolean',
            'deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toProfileArray(): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'school' => $this->school,
            'user_type' => $this->user_type,
            'role' => Roles::isValidRole($this->role) ? $this->role : 'user',
            'avatar_url' => $this->avatar_url,
            'auth_provider' => $this->auth_provider,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    public function isOAuthUser(): bool
    {
        return $this->auth_provider === 'google' || filled($this->google_id);
    }

    public function sendPasswordResetNotification($token): void
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $resetUrl = $frontendUrl.'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $this->email,
        ]);

        $this->notify(new \App\Notifications\ResetPasswordNotification($resetUrl));
    }
}
