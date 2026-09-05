<?php

/**
 * End-to-end auth smoke test against the running Laravel API.
 * Usage: php scripts/e2e-auth-smoke.php
 */

$base = 'http://127.0.0.1:8000';
$email = 'auth-debug-'.date('YmdHis').'@example.test';
$password = 'TestPass123!';

function request(string $method, string $url, ?array $body = null, ?string $token = null): array
{
    $ch = curl_init($url);
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

    return ['status' => $status, 'body' => json_decode($raw ?: 'null', true), 'raw' => $raw];
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== REGISTER ===\n";
$register = request('POST', "$base/api/auth/register", [
    'full_name' => 'Auth Debug User',
    'email' => $email,
    'password' => $password,
    'user_type' => 'student_man',
]);
echo "HTTP {$register['status']}\n";
echo json_encode($register['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";

if ($register['status'] !== 201) {
    exit(1);
}

$userId = $register['body']['data']['user']['id'] ?? null;
$token = $register['body']['data']['token'] ?? null;

$row = DB::table('user_profiles')->where('email', $email)->first();
$storedPassword = $row->password ?? null;
$looksHashed = is_string($storedPassword)
    && (str_starts_with($storedPassword, '$2y$')
        || str_starts_with($storedPassword, '$2a$')
        || str_starts_with($storedPassword, '$argon2'));

echo "DB user exists: ".($row ? 'yes' : 'no')."\n";
echo "DB password present: ".(filled($storedPassword) ? 'yes' : 'no')."\n";
echo "DB password looks hashed: ".($looksHashed ? 'yes' : 'no')."\n";
echo "Hash::check works: ".(filled($storedPassword) && Illuminate\Support\Facades\Hash::check($password, $storedPassword) ? 'yes' : 'no')."\n";

$tokenRow = DB::table('personal_access_tokens')
    ->where('tokenable_id', $userId)
    ->orderByDesc('created_at')
    ->first();
echo "Sanctum token row created: ".($tokenRow ? 'yes' : 'no')."\n";

echo "\n=== LOGIN ===\n";
$login = request('POST', "$base/api/auth/login", [
    'email' => $email,
    'password' => $password,
]);
echo "HTTP {$login['status']}\n";
echo json_encode($login['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";

if ($login['status'] !== 200) {
    exit(1);
}

$loginToken = $login['body']['data']['token'] ?? $token;

echo "\n=== ME ===\n";
$me = request('GET', "$base/api/auth/me", null, $loginToken);
echo "HTTP {$me['status']}\n";
echo json_encode($me['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";

if ($me['status'] !== 200) {
    exit(1);
}

echo "\n=== LOGOUT ===\n";
$logout = request('POST', "$base/api/auth/logout", null, $loginToken);
echo "HTTP {$logout['status']}\n";
echo json_encode($logout['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";

$tokenStillExists = DB::table('personal_access_tokens')
    ->where('tokenable_id', $userId)
    ->exists();
echo "Token still in DB after logout: ".($tokenStillExists ? 'yes' : 'no')."\n";

echo "\n=== ME AFTER LOGOUT (expect 401) ===\n";
$meAfter = request('GET', "$base/api/auth/me", null, $loginToken);
echo "HTTP {$meAfter['status']}\n";
echo json_encode($meAfter['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";

echo "\nTEST_EMAIL={$email}\n";
