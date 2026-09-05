<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiProviderContract;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiProvider;

class AiClient
{
    private readonly AiProviderContract $provider;

    public function __construct(?AiProviderContract $provider = null)
    {
        $this->provider = $provider ?? $this->resolveProvider();
    }

    public function provider(): string
    {
        return $this->provider->name();
    }

    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }

    public function generationModel(): string
    {
        return $this->provider->generationModel();
    }

    public function validationModel(): string
    {
        return $this->provider->validationModel();
    }

    public function legacyModel(): string
    {
        return $this->provider->legacyModel();
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{model?: string, temperature?: float, json?: bool, max_tokens?: int}  $options
     */
    public function chat(array $messages, array $options = []): string
    {
        return $this->provider->chat($messages, $options);
    }

    private function resolveProvider(): AiProviderContract
    {
        return match (strtolower((string) config('ai.provider', 'openai'))) {
            'gemini' => new GeminiProvider,
            default => new OpenAiProvider,
        };
    }
}
