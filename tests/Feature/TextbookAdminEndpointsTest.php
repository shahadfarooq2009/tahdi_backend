<?php

namespace Tests\Feature;

use Tests\Support\SanctumTestHelper;
use Tests\TestCase;

class TextbookAdminEndpointsTest extends TestCase
{
    public function test_textbook_routes_require_authentication(): void
    {
        $response = $this->getJson('/api/admin/textbooks');

        $response
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_upload_sign_requires_can_add_questions_permission(): void
    {
        SanctumTestHelper::actingAsRole('user');

        $response = $this->postJson('/api/admin/uploads/sign', [
            'purpose' => 'question-image',
            'file_name' => 'photo.png',
            'content_type' => 'image/png',
            'file_size' => 1024,
        ]);

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_textbook_routes_require_can_manage_curriculum_permission(): void
    {
        SanctumTestHelper::actingAsRole('user');

        $response = $this->getJson('/api/admin/textbooks');

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_upload_sign_validates_purpose(): void
    {
        SanctumTestHelper::actingAsRole('editor');

        $response = $this->postJson('/api/admin/uploads/sign', [
            'purpose' => 'invalid-purpose',
            'file_name' => 'photo.png',
            'content_type' => 'image/png',
            'file_size' => 1024,
        ]);

        $response
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }
}
