<?php

namespace App\Support;

final class Roles
{
    public const USER_ROLES = ['admin', 'editor', 'user'];

    public const PERMISSIONS = [
        'admin' => [
            'canAccessAdmin' => true,
            'canManageUsers' => true,
            'canUseAI' => true,
            'canManageCurriculum' => true,
            'canAddQuestions' => true,
            'canEditQuestions' => true,
            'canDeleteQuestions' => true,
            'canAddCategories' => true,
            'canEditCategories' => true,
            'canDeleteCategories' => true,
            'canAddSubjects' => true,
            'canEditSubjects' => true,
            'canDeleteSubjects' => true,
        ],
        'editor' => [
            'canAccessAdmin' => true,
            'canManageUsers' => false,
            'canUseAI' => true,
            'canManageCurriculum' => true,
            'canAddQuestions' => true,
            'canEditQuestions' => true,
            'canDeleteQuestions' => false,
            'canAddCategories' => true,
            'canEditCategories' => true,
            'canDeleteCategories' => false,
            'canAddSubjects' => true,
            'canEditSubjects' => true,
            'canDeleteSubjects' => false,
        ],
        'user' => [
            'canAccessAdmin' => false,
            'canManageUsers' => false,
            'canUseAI' => false,
            'canManageCurriculum' => false,
            'canAddQuestions' => false,
            'canEditQuestions' => false,
            'canDeleteQuestions' => false,
            'canAddCategories' => false,
            'canEditCategories' => false,
            'canDeleteCategories' => false,
            'canAddSubjects' => false,
            'canEditSubjects' => false,
            'canDeleteSubjects' => false,
        ],
    ];

    public static function isValidRole(?string $role): bool
    {
        return is_string($role) && in_array($role, self::USER_ROLES, true);
    }

    public static function roleHasPermission(?string $role, string $permission): bool
    {
        if (! self::isValidRole($role)) {
            return false;
        }

        return (bool) (self::PERMISSIONS[$role][$permission] ?? false);
    }
}
