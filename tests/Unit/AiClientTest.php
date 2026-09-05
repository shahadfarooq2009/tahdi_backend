<?php

namespace Tests\Unit;

use App\Services\Ai\AiClient;
use App\Services\Ai\Providers\GeminiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiClientTest extends TestCase
{
    public function test_resolves_gemini_provider_from_config(): void
    {
        config([
            'ai.provider' => 'gemini',
            'ai.gemini.api_key' => 'test-key',
        ]);

        $client = new AiClient;

        $this->assertSame('gemini', $client->provider());
        $this->assertTrue($client->isConfigured());
    }

    public function test_gemini_provider_returns_chat_content(): void
    {
        config([
            'ai.gemini.api_key' => 'test-key',
            'ai.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"question_text":"test"}'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $provider = new GeminiProvider;

        $content = $provider->chat([
            ['role' => 'system', 'content' => 'system'],
            ['role' => 'user', 'content' => 'user'],
        ], [
            'model' => 'gemini-2.0-flash',
            'json' => true,
        ]);

        $this->assertSame('{"question_text":"test"}', $content);
    }
}
