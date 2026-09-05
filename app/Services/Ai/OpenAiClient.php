<?php

namespace App\Services\Ai;

/**
 * @deprecated Use AiClient instead.
 */
class OpenAiClient
{
    public function __construct(
        private readonly AiClient $ai,
    ) {}

    public function isConfigured(): bool
    {
        return $this->ai->isConfigured();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createChatCompletion(array $payload): array
    {
        $messages = $payload['messages'] ?? [];
        $options = [
            'model' => $payload['model'] ?? null,
            'temperature' => $payload['temperature'] ?? 0.4,
            'max_tokens' => $payload['max_tokens'] ?? null,
            'json' => isset($payload['response_format']['type']) && $payload['response_format']['type'] === 'json_object',
        ];

        $options = array_filter($options, fn ($value) => $value !== null);

        $content = $this->ai->chat($messages, $options);

        return [
            'choices' => [
                [
                    'message' => [
                        'content' => $content,
                    ],
                ],
            ],
        ];
    }
}
