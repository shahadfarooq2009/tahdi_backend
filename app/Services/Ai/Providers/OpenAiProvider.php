<?php

namespace App\Services\Ai\Providers;

use App\Exceptions\ServiceUnavailableException;
use App\Services\Ai\Contracts\AiProviderContract;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements AiProviderContract
{
    public function name(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    private function apiKey(): ?string
    {
        $key = config('services.openai.key') ?: config('ai.openai.api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function generationModel(): string
    {
        return (string) config('ai.openai.generation_model', 'gpt-4o-mini');
    }

    public function validationModel(): string
    {
        return (string) config('ai.openai.validation_model', 'gpt-4o-mini');
    }

    public function legacyModel(): string
    {
        return (string) config('ai.openai.legacy_model', 'gpt-3.5-turbo');
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

        $payload = [
            'model' => $options['model'] ?? $this->generationModel(),
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.4,
        ];

        if (($options['json'] ?? false) === true) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = $options['max_tokens'];
        }

        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            throw new ServiceUnavailableException('AI service is not configured');
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post((string) config('ai.openai.chat_completions_url'), $payload);

        if (! $response->successful()) {
            $safeMessage = $response->json('error.message')
                ?? $response->json('error.type')
                ?? 'unknown provider error';

            logger()->error('OpenAI provider request failed', [
                'status' => $response->status(),
                'provider_message' => is_string($safeMessage) ? mb_substr($safeMessage, 0, 500) : 'unknown',
            ]);

            $error = new ServiceUnavailableException('AI provider request failed');
            $error->providerStatus = $response->status();

            throw $error;
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || $content === '') {
            throw new ServiceUnavailableException('AI provider returned empty content');
        }

        return $content;
    }
}
