<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->toProfileArray();

        return $this->success([
            'id' => $user->id,
            'email' => $user->email,
            'role' => $profile['role'],
            'auth_provider' => $user->auth_provider,
            'profile' => $profile,
        ]);
    }
}
