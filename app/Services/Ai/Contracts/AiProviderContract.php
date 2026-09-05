<?php

namespace App\Services\Ai\Contracts;

interface AiProviderContract
{
    public function name(): string;

    public function isConfigured(): bool;

    public function generationModel(): string;

    public function validationModel(): string;

    public function legacyModel(): string;

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array{model?: string, temperature?: float, json?: bool, max_tokens?: int}  $options
     */
    public function chat(array $messages, array $options = []): string;
}
