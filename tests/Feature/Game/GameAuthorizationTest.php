<?php

namespace Tests\Feature\Game;

use Tests\TestCase;

class GameAuthorizationTest extends TestCase
{
    public function test_game_session_create_requires_auth(): void
    {
        $this->postJson('/api/game/sessions', [
            'mode' => 'family',
            'subject_ids' => ['00000000-0000-4000-8000-000000000001'],
            'teams' => [['name' => 'Team A']],
        ])->assertUnauthorized();
    }

    public function test_public_question_requires_session_id(): void
    {
        $this->getJson('/api/game/questions/00000000-0000-4000-8000-000000000099')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }
}
