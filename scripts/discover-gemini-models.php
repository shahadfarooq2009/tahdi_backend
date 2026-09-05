<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$apiKey = (string) config('ai.gemini.api_key');
$baseUrl = rtrim((string) config('ai.gemini.base_url'), '/');

if ($apiKey === '') {
    fwrite(STDERR, "GEMINI_API_KEY is not configured in backend/.env\n");
    exit(1);
}

$testPrompt = 'أجب بكلمة واحدة فقط: مرحبا';

function maskKey(string $key): string
{
    if (strlen($key) <= 8) {
        return '***';
    }

    return substr($key, 0, 4).'...'.substr($key, -4);
}

function classifyModel(array $model): array
{
    $name = (string) ($model['name'] ?? '');
    $display = (string) ($model['displayName'] ?? '');
    $description = (string) ($model['description'] ?? '');
    $haystack = strtolower($name.' '.$display.' '.$description);

    $flags = [];
    if (str_contains($haystack, 'deprecated')) {
        $flags[] = 'deprecated';
    }
    if (str_contains($haystack, 'preview') || str_contains($haystack, 'experimental')) {
        $flags[] = 'preview';
    }
    if (str_contains($haystack, 'lite')) {
        $flags[] = 'lite';
    }
    if (str_contains($haystack, 'flash')) {
        $flags[] = 'flash';
    }

    return $flags;
}

function isLikelyTextGenerationModel(array $model): bool
{
    $name = strtolower((string) ($model['name'] ?? ''));
    $methods = $model['supportedGenerationMethods'] ?? [];

    if (! is_array($methods) || ! in_array('generateContent', $methods, true)) {
        return false;
    }

    if (str_contains($name, 'embedding') || str_contains($name, 'aqa') || str_contains($name, 'imagen') || str_contains($name, 'veo')) {
        return false;
    }

    return str_contains($name, 'gemini');
}

function shortModelId(string $name): string
{
    return str_starts_with($name, 'models/') ? substr($name, 7) : $name;
}

echo 'gemini_key_configured=yes'.PHP_EOL;
echo 'gemini_key_masked='.maskKey($apiKey).PHP_EOL;
echo 'gemini_base_url='.$baseUrl.PHP_EOL.PHP_EOL;

$models = [];
$pageToken = null;

do {
    $query = ['pageSize' => 100, 'key' => $apiKey];
    if ($pageToken !== null) {
        $query['pageToken'] = $pageToken;
    }

    $listResponse = Http::timeout(30)->get("{$baseUrl}/models", $query);

    if (! $listResponse->successful()) {
        $message = $listResponse->json('error.message') ?? $listResponse->body();
        fwrite(STDERR, 'Models list failed: HTTP '.$listResponse->status().' — '.$message.PHP_EOL);
        exit(1);
    }

    $batch = $listResponse->json('models', []);
    if (is_array($batch)) {
        $models = array_merge($models, $batch);
    }

    $pageToken = $listResponse->json('nextPageToken');
} while (filled($pageToken));

$generateContentModels = array_values(array_filter($models, function ($model) {
    $methods = $model['supportedGenerationMethods'] ?? [];

    return is_array($methods) && in_array('generateContent', $methods, true);
}));

echo '=== MODELS WITH generateContent ('.count($generateContentModels).') ==='.PHP_EOL;

$textModels = [];
foreach ($generateContentModels as $model) {
    if (! isLikelyTextGenerationModel($model)) {
        continue;
    }

    $textModels[] = $model;
    $flags = classifyModel($model);
    $methods = implode(', ', $model['supportedGenerationMethods'] ?? []);
    $flagText = $flags === [] ? 'stable' : implode(', ', $flags);

    echo sprintf(
        "- %s | %s | methods=[%s] | flags=%s\n",
        shortModelId((string) ($model['name'] ?? '')),
        $model['displayName'] ?? '(no display name)',
        $methods,
        $flagText
    );
}

echo PHP_EOL.'=== FLASH / FLASH-LITE CANDIDATES FOR TEST ==='.PHP_EOL;

$candidates = [];
foreach ($textModels as $model) {
    $id = strtolower(shortModelId((string) ($model['name'] ?? '')));
    if (! str_contains($id, 'flash')) {
        continue;
    }

    $candidates[$id] = $model;
}

$preferredOrder = [
    'gemini-2.0-flash-lite',
    'gemini-2.0-flash',
    'gemini-2.5-flash',
    'gemini-2.5-flash-lite',
    'gemini-1.5-flash-8b',
    'gemini-1.5-flash',
    'gemini-flash-latest',
    'gemini-2.5-flash-preview-05-20',
    'gemini-2.0-flash-lite-preview',
];

$orderedCandidates = [];
foreach ($preferredOrder as $preferred) {
    if (isset($candidates[$preferred])) {
        $orderedCandidates[$preferred] = $candidates[$preferred];
        unset($candidates[$preferred]);
    }
}

foreach ($candidates as $id => $model) {
    $orderedCandidates[$id] = $model;
}

if ($orderedCandidates === []) {
    fwrite(STDERR, "No Flash/Flash-Lite models found for testing.\n");
    exit(1);
}

$testResults = [];

foreach ($orderedCandidates as $modelId => $model) {
    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => $testPrompt]],
        ]],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 16,
        ],
    ];

    $startedAt = microtime(true);
    $response = Http::timeout(30)
        ->withHeaders(['Content-Type' => 'application/json'])
        ->post("{$baseUrl}/models/{$modelId}:generateContent?key={$apiKey}", $payload);
    $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

    $status = $response->status();
    $error = $response->json('error', []);
    $errorCode = is_array($error) ? ($error['code'] ?? null) : null;
    $errorStatus = is_array($error) ? ($error['status'] ?? null) : null;
    $errorMessage = is_array($error) ? ($error['message'] ?? null) : null;

    $limitZero = false;
    if (is_string($errorMessage)) {
        $limitZero = str_contains(strtolower($errorMessage), 'limit: 0')
            || str_contains(strtolower($errorMessage), 'quota exceeded')
            || str_contains(strtolower($errorMessage), 'resource_exhausted');
    }

    $parts = $response->json('candidates.0.content.parts', []);
    $text = '';
    if (is_array($parts)) {
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }
    }
    $text = trim($text);

    $success = $response->successful() && $text !== '';

    $testResults[] = [
        'model' => $modelId,
        'displayName' => $model['displayName'] ?? '',
        'http_status' => $status,
        'success' => $success,
        'error_code' => $errorCode,
        'error_status' => $errorStatus,
        'error_message' => $errorMessage,
        'limit_zero' => $limitZero,
        'latency_ms' => $elapsedMs,
        'response_text' => $success ? mb_substr($text, 0, 80) : null,
    ];

    echo sprintf(
        "- %s | HTTP %d | success=%s | error_code=%s | error_status=%s | limit_zero=%s | latency=%dms\n",
        $modelId,
        $status,
        $success ? 'yes' : 'no',
        $errorCode === null ? 'n/a' : (string) $errorCode,
        $errorStatus === null ? 'n/a' : (string) $errorStatus,
        $limitZero ? 'yes' : 'no',
        $elapsedMs
    );

    if ($errorMessage !== null) {
        echo '  error_message: '.mb_substr((string) $errorMessage, 0, 220).PHP_EOL;
    }
    if ($success) {
        echo '  response_text: '.$text.PHP_EOL;
    }
}

echo PHP_EOL.'=== JSON STRUCTURED OUTPUT SMOKE TEST (successful models only) ==='.PHP_EOL;

$jsonResults = [];
foreach ($testResults as $result) {
    if (! $result['success']) {
        continue;
    }

    $modelId = $result['model'];
    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => 'أعد JSON فقط بالشكل {"answer":"مرحبا"}']],
        ]],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 32,
            'responseMimeType' => 'application/json',
        ],
    ];

    $response = Http::timeout(30)
        ->withHeaders(['Content-Type' => 'application/json'])
        ->post("{$baseUrl}/models/{$modelId}:generateContent?key={$apiKey}", $payload);

    $parts = $response->json('candidates.0.content.parts', []);
    $text = '';
    if (is_array($parts)) {
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }
    }
    $text = trim($text);
    $decoded = json_decode($text, true);
    $jsonOk = $response->successful() && is_array($decoded);

    $jsonResults[] = [
        'model' => $modelId,
        'http_status' => $response->status(),
        'json_ok' => $jsonOk,
        'response_text' => mb_substr($text, 0, 120),
    ];

    echo sprintf(
        "- %s | HTTP %d | json_ok=%s | sample=%s\n",
        $modelId,
        $response->status(),
        $jsonOk ? 'yes' : 'no',
        mb_substr($text, 0, 120)
    );
}

echo PHP_EOL.'=== RECOMMENDATION ==='.PHP_EOL;

$successful = array_values(array_filter($testResults, fn ($r) => $r['success']));
$jsonSuccessful = array_values(array_filter($jsonResults, fn ($r) => $r['json_ok']));

if ($successful === []) {
    echo "No Flash/Flash-Lite model succeeded with the test prompt.\n";
    echo "Check quota/billing limits on the Google AI project before changing GEMINI_MODEL.\n";
    exit(2);
}

$recommended = null;
foreach ($jsonSuccessful as $candidate) {
    $recommended = $candidate['model'];
    if (str_contains($candidate['model'], 'flash-lite')) {
        break;
    }
}

if ($recommended === null) {
    usort($successful, fn ($a, $b) => $a['latency_ms'] <=> $b['latency_ms']);
    $recommended = $successful[0]['model'];
}

echo 'recommended_gemini_model='.$recommended.PHP_EOL;
echo 'recommended_for=Arabic textbook question generation with JSON structured output (low latency/cost)'.PHP_EOL;
echo 'configure_with=GEMINI_GENERATION_MODEL='.$recommended.PHP_EOL;
