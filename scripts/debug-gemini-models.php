<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = config('ai.gemini.api_key');
$baseUrl = rtrim((string) config('ai.gemini.base_url'), '/');
$models = ['gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-1.5-flash', 'gemini-2.5-flash-preview-04-17'];

foreach ($models as $model) {
    $response = Illuminate\Support\Facades\Http::timeout(60)->post(
        "{$baseUrl}/models/{$model}:generateContent?key={$key}",
        ['contents' => [['role' => 'user', 'parts' => [['text' => 'Say hi']]]]]
    );
    echo $model.' => '.$response->status();
    if (! $response->successful()) {
        $msg = $response->json('error.message') ?? $response->body();
        echo ' | '.mb_substr((string) $msg, 0, 120);
    }
    echo PHP_EOL;
}
