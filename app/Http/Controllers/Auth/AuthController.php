<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Storage\LocalMediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly LocalMediaStorageService $mediaStorage,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->success($this->auth->login($request->validated()));
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        return $this->success($this->auth->register($request->validated()), 201);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->auth->logout($user);

        return $this->success(['message' => 'تم تسجيل الخروج بنجاح']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $message = $this->auth->sendPasswordResetLink($validated['email']);

        return $this->success(['message' => $message]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(6)],
        ]);

        $message = $this->auth->resetPassword($validated);

        return $this->success(['message' => $message]);
    }

    public function googleRedirect(): JsonResponse
    {
        return $this->success($this->auth->googleRedirectUrl());
    }

    public function googleCallback()
    {
        $payload = $this->auth->handleGoogleCallback();
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
        $query = http_build_query([
            'access_token' => $payload['token'],
            'expires_at' => $payload['expires_at'],
        ]);

        return redirect($frontendUrl.'/login?'.$query);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->auth->updateProfile($user, $request->validated());

        return $this->success([
            'id' => $user->id,
            'email' => $user->email,
            'role' => $profile['role'] ?? $user->role,
            'profile' => $profile,
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,gif', 'max:2048'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $file = $validated['file'];
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $safeName = $user->id.'-'.time().'.'.$extension;
        $publicUrl = $this->mediaStorage->storeUploadedFile('avatars', $safeName, $file);
        $profile = $this->auth->updateProfile($user, ['avatar_url' => $publicUrl]);

        return $this->success([
            'avatar_url' => $publicUrl,
            'profile' => $profile,
        ]);
    }
}
