<?php

namespace App\Services\Auth;

class AuthenticatedUser
{
    /**
     * @param  array<string, mixed>  $profile
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $email,
        public readonly string $role,
        public readonly array $profile,
        public readonly string $accessToken,
    ) {}

    public function hasPermission(string $permission): bool
    {
        return \App\Support\Roles::roleHasPermission($this->role, $permission);
    }
}
