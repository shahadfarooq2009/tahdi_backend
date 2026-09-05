<?php

namespace App\Services\Ai\Providers;

use App\Exceptions\ServiceUnavailableException;
use App\Services\Ai\Contracts\AiProviderContract;
use Illuminate\Support\Facades\Http;

class GeminiProvider implements AiProviderContract
{
    public function name(): string
    {
        return 'gemini';
    }

    public function isConfigured(): bool
    {
        return filled(config('ai.gemini.api_key'));
    }

    public function generationModel(): string
    {
        return (string) config('ai.gemini.generation_model', 'gemini-2.0-flash');
    }

    public function validationModel(): string
    {
        return (string) config('ai.gemini.validation_model', 'gemini-2.0-flash');
    }

    public function legacyModel(): string
    {
        return (string) config('ai.gemini.legacy_model', 'gemini-2.0-flash');
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{model?: string, temperature?: float, json?: bool, max_tokens?: int}  $options
     */
    public function chat(array $messages, array $options = []): string
    {
        if (! $this->isConfigured()) {
            throw new ServiceUnavailableException('AI service is not configured');
        }

        $model = $options['model'] ?? $this->generationModel();
        $baseUrl = rtrim((string) config('ai.gemini.base_url'), '/');
        $apiKey = (string) config('ai.gemini.api_key');

        $systemInstruction = null;
        $contents = [];

        foreach ($messages as $message) {
            $role = $message['role'] === 'assistant' ? 'model' : $message['role'];

            if ($role === 'system') {
                $systemInstruction = ['parts' => [['text' => $message['content']]]];

                continue;
            }

            $contents[] = [
                'role' => $role === 'user' ? 'user' : 'model',
                'parts' => [['text' => $message['content']]],
            ];
        }

        if ($contents === [] && $systemInstruction !== null) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => 'Follow the system instructions.']],
            ];
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.4,
            ],
        ];

        if ($systemInstruction !== null) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        if (($options['json'] ?? false) === true) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
        }

        if (isset($options['max_tokens'])) {
            $payload['generationConfig']['maxOutputTokens'] = $options['max_tokens'];
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->timeout(120)->post("{$baseUrl}/models/{$model}:generateContent?key={$apiKey}", $payload);

        if (! $response->successful()) {
            $detail = $response->json('error.message') ?? $response->body();
            $error = new ServiceUnavailableException('AI provider request failed: HTTP '.$response->status().' — '.mb_substr((string) $detail, 0, 500));
            $error->providerStatus = $response->status();

            throw $error;
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

        if ($text === '') {
            throw new ServiceUnavailableException('AI provider returned empty content');
        }

        return $text;
    }
}
