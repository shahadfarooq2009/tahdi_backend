<?php

namespace Tests\Feature;

use Tests\Support\SanctumTestHelper;
use Tests\TestCase;

class AiEndpointsTest extends TestCase
{
    public function test_ai_routes_require_authentication(): void
    {
        $this->getJson('/api/ai/status')->assertUnauthorized();
        $this->postJson('/api/ai/generate-question', [])->assertUnauthorized();
    }

    public function test_ai_routes_require_can_use_ai_permission(): void
    {
        SanctumTestHelper::actingAsRole('user');

        $this->getJson('/api/ai/status')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_ai_status_returns_configuration_shape(): void
    {
        SanctumTestHelper::actingAsRole('editor');

        $response = $this->getJson('/api/ai/status');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['provider', 'configured', 'ready']]);
    }

    public function test_ai_status_reports_active_provider(): void
    {
        config([
            'ai.provider' => 'gemini',
            'ai.gemini.api_key' => 'test-gemini-key',
            'ai.openai.api_key' => null,
        ]);

        SanctumTestHelper::actingAsRole('editor');

        $this->getJson('/api/ai/status')
            ->assertOk()
            ->assertJsonPath('data.provider', 'gemini')
            ->assertJsonPath('data.configured', true);
    }

    public function test_textbook_generate_questions_requires_curriculum_permission(): void
    {
        SanctumTestHelper::actingAsRole('user');

        $this->postJson('/api/admin/textbooks/00000000-0000-4000-8000-000000000001/generate-questions', [
            'points_value' => 100,
        ])->assertForbidden();
    }
}
