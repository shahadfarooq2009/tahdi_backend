<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$client = app(App\Services\Ai\AiClient::class);
$baseUrl = rtrim((string) config('ai.gemini.base_url'), '/');
$model = config('ai.gemini.generation_model');
$key = config('ai.gemini.api_key');

echo 'provider='.config('ai.provider').' configured='.($client->isConfigured() ? 'yes' : 'no')."\n";
echo "model={$model}\n";

$probe = Illuminate\Support\Facades\Http::timeout(60)->post(
    "{$baseUrl}/models/{$model}:generateContent?key={$key}",
    [
        'contents' => [['role' => 'user', 'parts' => [['text' => 'Say hello in Arabic as JSON {"greeting":"..."}']]]],
        'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.2],
    ]
);

echo 'probe_status='.$probe->status()."\n";
echo $probe->body()."\n\n";

try {
    $content = $client->chat([
        ['role' => 'system', 'content' => 'Return JSON only'],
        ['role' => 'user', 'content' => 'Generate one Arabic quiz question about energy with answer in JSON keys question_text, answer_text'],
    ], ['json' => true, 'temperature' => 0.3]);
    echo "chat_ok:\n{$content}\n";
} catch (Throwable $e) {
    echo 'chat_error: '.$e->getMessage()."\n";
}
