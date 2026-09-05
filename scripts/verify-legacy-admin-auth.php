<?php

/**
 * Verify legacy admin activation: set-password + login + admin access checks.
 *
 * Usage:
 *   php scripts/verify-legacy-admin-auth.php [admin-email]
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

$adminEmail = $argv[1] ?? 'alfarqi@hotmail.com';
$testPassword = 'LegacyAdminTest!'.random_int(1000, 9999);

function api(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    $ch = curl_init('http://127.0.0.1:8000'.$path);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer '.$token;
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => json_decode($raw ?: 'null', true)];
}

/** @var AuthService $auth */
$auth = app(AuthService::class);

echo "=== LEGACY ADMIN CHECK: {$adminEmail} ===\n";

$admin = User::query()
    ->whereRaw('LOWER(email) = ?', [strtolower($adminEmail)])
    ->where('is_deleted', false)
    ->first();

if (! $admin) {
    echo "Admin user not found\n";
    exit(1);
}

$beforeId = $admin->id;
$beforeRole = $admin->role;

echo 'exists: yes'."\n";
echo 'role: '.$admin->role."\n";
echo 'password present before: '.(filled($admin->password) ? 'yes' : 'no')."\n";

$auth->setPasswordForExistingUser($admin, $testPassword);
$admin->refresh();

echo 'password present after set: '.(filled($admin->password) ? 'yes' : 'no')."\n";
echo 'password looks hashed: '.(
    is_string($admin->password)
    && (str_starts_with($admin->password, '$2y$')
        || str_starts_with($admin->password, '$2a$')
        || str_starts_with($admin->password, '$argon2'))
        ? 'yes' : 'no'
)."\n";
echo 'id preserved: '.($admin->id === $beforeId ? 'yes' : 'no')."\n";
echo 'role preserved: '.($admin->role === $beforeRole ? 'yes' : 'no')."\n";

echo "\n=== PASSWORD RESET FLOW (token-based) ===\n";
$resetUser = User::query()->where('role', 'admin')->whereNull('password')->where('is_deleted', false)->where('email', '!=', $adminEmail)->first();
if ($resetUser) {
    $resetPass = 'ResetFlowTest!'.random_int(1000, 9999);
    $token = Password::createToken($resetUser);
    $reset = api('POST', '/api/auth/reset-password', [
        'email' => $resetUser->email,
        'token' => $token,
        'password' => $resetPass,
        'password_confirmation' => $resetPass,
    ]);
    echo "reset-password HTTP {$reset['status']} for {$resetUser->email}\n";
    $resetUser->refresh();
    echo 'reset role preserved: '.($resetUser->role === 'admin' ? 'yes' : $resetUser->role)."\n";
    echo 'reset password present: '.(filled($resetUser->password) ? 'yes' : 'no')."\n";
} else {
    echo "skipped (no second null-password admin available)\n";
}

echo "\n=== ADMIN LOGIN ===\n";
$login = api('POST', '/api/auth/login', [
    'email' => $adminEmail,
    'password' => $testPassword,
]);
echo 'login HTTP '.$login['status']."\n";
if ($login['status'] !== 200) {
    echo json_encode($login['body'], JSON_PRETTY_PRINT)."\n";
    exit(1);
}

$token = $login['body']['data']['token'] ?? null;

$me = api('GET', '/api/auth/me', null, $token);
echo 'me HTTP '.$me['status'].' role='.($me['body']['data']['role'] ?? 'n/a')."\n";

$adminEndpoint = api('GET', '/api/admin/questions', null, $token);
echo 'admin/questions HTTP '.$adminEndpoint['status']."\n";

echo "\n=== NORMAL USER ADMIN ACCESS (expect 403) ===\n";
$normal = User::query()->where('role', 'user')->where('is_deleted', false)->first();
if ($normal && filled($normal->password)) {
    $normalLogin = api('POST', '/api/auth/login', [
        'email' => $normal->email,
        'password' => 'should-not-work',
    ]);
    echo "normal user with password exists: {$normal->email} (login skipped if unknown password)\n";
}

// Use freshly registered user for 403 test
$regEmail = 'legacy-normal-'.time().'@example.test';
$reg = api('POST', '/api/auth/register', [
    'full_name' => 'Normal User',
    'email' => $regEmail,
    'password' => 'NormalUserPass123!',
    'user_type' => 'student_man',
]);
$userToken = $reg['body']['data']['token'] ?? null;
$forbidden = api('GET', '/api/admin/questions', null, $userToken);
echo 'normal user admin/questions HTTP '.$forbidden['status']."\n";

echo "\n=== NO TOKEN (expect 401) ===\n";
$unauth = api('GET', '/api/admin/questions');
echo 'no token admin/questions HTTP '.$unauth['status']."\n";

echo "\nDONE\n";
