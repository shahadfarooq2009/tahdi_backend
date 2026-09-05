<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Services\Auth\AuthenticatedUser;
use Illuminate\Http\Request;

trait ResolvesActor
{
    /**
     * @return array{actorUserId: string, actorRole: string}
     */
    protected function actor(Request $request): array
    {
        /** @var AuthenticatedUser $user */
        $user = $request->attributes->get('auth_user');

        return [
            'actorUserId' => $user->id,
            'actorRole' => $user->role,
        ];
    }
}
