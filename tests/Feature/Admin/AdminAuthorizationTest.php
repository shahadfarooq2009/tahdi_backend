<?php

namespace Tests\Feature\Admin;

use Tests\Support\SanctumTestHelper;
use Tests\TestCase;

class AdminAuthorizationTest extends TestCase
{
    public function test_admin_questions_returns_401_without_token(): void
    {
        $this->getJson('/api/admin/questions')->assertUnauthorized();
    }

    public function test_admin_questions_returns_403_for_user_role(): void
    {
        SanctumTestHelper::actingAsRole('user');

        $this->getJson('/api/admin/questions')->assertForbidden();
    }

    public function test_admin_users_returns_403_for_editor_role(): void
    {
        SanctumTestHelper::actingAsRole('editor');

        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_admin_categories_returns_401_without_token(): void
    {
        $this->getJson('/api/admin/categories')->assertUnauthorized();
    }

    public function test_admin_subjects_returns_401_without_token(): void
    {
        $this->getJson('/api/admin/subjects')->assertUnauthorized();
    }
}
