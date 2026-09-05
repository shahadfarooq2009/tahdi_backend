<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class SanctumTestHelper
{
    public static function actingAsRole(string $role, ?string $id = null): User
    {
        $user = new User([
            'id' => $id ?? (string) Str::uuid(),
            'full_name' => ucfirst($role).' User',
            'email' => "{$role}@example.com",
            'user_type' => 'teacher_man',
            'role' => $role,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        Sanctum::actingAs($user);

        return $user;
    }
}
