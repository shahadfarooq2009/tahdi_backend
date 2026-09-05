# Migration API Inventory (Node → Laravel)

Reference implementation: `backend-node-legacy/`

Laravel migration status legend:
- `[A]` Phase A — done
- `[ ]` Not migrated yet

## Health

| Status | Method | Path | Auth | Permissions | Notes |
|--------|--------|------|------|-------------|-------|
| [A] | GET | `/api/health` | Public | — | Returns `status`, `supabaseAuthConfigured`, `supabaseAdminConfigured`, `databaseConnected` |

## Auth helpers (Laravel-only during migration)

| Status | Method | Path | Auth | Permissions | Notes |
|--------|--------|------|------|-------------|-------|
| [A] | GET | `/api/auth/me` | Bearer JWT | — | Returns authenticated user profile |
| [A] | GET | `/api/auth/permission-check` | Bearer JWT | `canManageUsers` | Test route for permission middleware |

## AI (`/api/ai`)

| Status | Method | Path | Auth | Permissions |
|--------|--------|------|------|-------------|
| [E] | GET | `/api/ai/status` | Bearer JWT | `canUseAI` |
| [E] | POST | `/api/ai/generate-question` | Bearer JWT | `canUseAI` |

Rate limit: AI bucket (legacy: 30 req / 15 min)

## Game (`/api/game`)

| Status | Method | Path | Auth | Permissions |
|--------|--------|------|------|-------------|
| [ ] | GET | `/api/game/questions/:questionId` | Public | — |
| [ ] | GET | `/api/game/questions/:questionId/answer` | Public | — |
| [ ] | POST | `/api/game/sessions` | Bearer JWT | — |
| [ ] | GET | `/api/game/sessions/:id` | Bearer JWT | — |
| [ ] | PATCH | `/api/game/sessions/:id` | Bearer JWT | — |
| [ ] | POST | `/api/game/sessions/:id/finish` | Bearer JWT | — |
| [ ] | GET | `/api/game/sessions/:sessionId/questions` | Bearer JWT | — |
| [ ] | GET | `/api/game/sessions/:sessionId/questions/:questionId/answer` | Bearer JWT | — |
| [ ] | POST | `/api/game/sessions/:sessionId/questions/:questionId/answer` | Bearer JWT | — |
| [ ] | POST | `/api/game/sessions/:sessionId/board/claim` | Bearer JWT | — |
| [ ] | POST | `/api/game/sessions/:sessionId/board/assign` | Bearer JWT | — |
| [ ] | POST | `/api/game/sessions/:sessionId/powerups` | Bearer JWT | — |
| [ ] | GET | `/api/game/sessions/:sessionId/scores` | Bearer JWT | — |
| [ ] | POST | `/api/game/sessions/:sessionId/scores/adjust` | Bearer JWT | — |
| [ ] | GET | `/api/game/textbooks/:textbookId/units/:unitKey/review-sets/next` | Bearer JWT | — |
| [ ] | GET | `/api/game/textbooks/:textbookId/units/:unitKey/review-sets/remaining` | Bearer JWT | — |
| [ ] | POST | `/api/game/review-sets/:reviewSetId/usage` | Bearer JWT | — |

Rate limit: Game bucket (legacy: 300 req / 15 min)

## Admin — Users (`/api/admin/users`)

| Status | Method | Path | Auth | Permissions |
|--------|--------|------|------|-------------|
| [B] | GET | `/api/admin/users` | Bearer JWT | `canManageUsers` |
| [B] | GET | `/api/admin/users/:id` | Bearer JWT | `canManageUsers` or `canEditQuestions` |
| [B] | PATCH | `/api/admin/users/:userId/role` | Bearer JWT | `canManageUsers` |
| [B] | PATCH | `/api/admin/users/:id/status` | Bearer JWT | `canManageUsers` |
| [B] | POST | `/api/admin/users/:id/restore` | Bearer JWT | `canManageUsers` |
| [B] | DELETE | `/api/admin/users/:id/permanent` | Bearer JWT | `canManageUsers` |
| [B] | DELETE | `/api/admin/users/:id` | Bearer JWT | `canManageUsers` |

## Admin — Questions (`/api/admin/questions`)

| Status | Method | Path | Auth | Permissions |
|--------|--------|------|------|-------------|
| [B] | GET | `/api/admin/questions` | Bearer JWT | any question permission |
| [B] | POST | `/api/admin/questions/import` | Bearer JWT | `canAddQuestions` |
| [B] | POST | `/api/admin/questions/bulk-delete` | Bearer JWT | `canDeleteQuestions` |
| [B] | PATCH | `/api/admin/questions/bulk-approve` | Bearer JWT | `canEditQuestions` |
| [B] | PATCH | `/api/admin/questions/bulk-reject` | Bearer JWT | `canEditQuestions` |
| [B] | GET | `/api/admin/questions/:id` | Bearer JWT | any question permission |
| [B] | POST | `/api/admin/questions` | Bearer JWT | `canAddQuestions` |
| [B] | PATCH | `/api/admin/questions/:id/approve` | Bearer JWT | `canEditQuestions` |
| [B] | PATCH | `/api/admin/questions/:id/reject` | Bearer JWT | `canEditQuestions` |
| [B] | POST | `/api/admin/questions/:id/restore` | Bearer JWT | `canEditQuestions` |
| [B] | PATCH | `/api/admin/questions/:id` | Bearer JWT | `canEditQuestions` |
| [B] | DELETE | `/api/admin/questions/:id` | Bearer JWT | `canDeleteQuestions` |

## Admin — Chapters (`/api/admin/chapters`)

| Status | Method | Path | Auth | Permissions |
|--------|--------|------|------|-------------|
| [B] | POST | `/api/admin/chapters/resolve` | Bearer JWT | `canAddQuestions` |

## Admin — Subjects (`/api/admin/subjects`)

| Status | Method | Path | Auth | Permissions |
|--------|--------|------|------|-------------|
| [B] | GET | `/api/admin/subjects/question-stats` | Bearer JWT | any subject permission |
| [B] | GET | `/api/admin/subjects` | Bearer JWT | any subject permission |
| [B] | GET | `/api/admin/subjects/:id` | Bearer JWT | any subject permission |
| [B] | POST | `/api/admin/subjects` | Bearer JWT | `canAddSubjects` |
| [B] | PATCH | `/api/admin/subjects/:id/grades` | Bearer JWT | `canEditSubjects` |
| [B] | POST | `/api/admin/subjects/:id/grades/:grade/toggle-completion` | Bearer JWT | `canEditSubjects` |
| [B] | PATCH | `/api/admin/subjects/:id` | Bearer JWT | `canEditSubjects` |
| [B] | POST | `/api/admin/subjects/:id/restore` | Bearer JWT | `canEditSubjects` |
| [B] | DELETE | `/api/admin/subjects/:id` | Bearer JWT | `canDeleteSubjects` |

## Admin — Categories (`/api/admin/categories`)

| Status | Method | Path | Auth | Permissions |
|--------|--------|------|------|-------------|
| [B] | GET | `/api/admin/categories` | Bearer JWT | any category permission |
| [B] | POST | `/api/admin/categories` | Bearer JWT | `canAddCategories` |
| [B] | PATCH | `/api/admin/categories/:id` | Bearer JWT | `canEditCategories` |
| [B] | POST | `/api/admin/categories/:id/restore` | Bearer JWT | `canEditCategories` |
| [B] | DELETE | `/api/admin/categories/:id` | Bearer JWT | `canDeleteCategories` |

## Admin — Uploads (`/api/admin/uploads`)

| Status | Method | Path | Auth | Permissions |
|--------|--------|------|------|-------------|
| [D] | POST | `/api/admin/uploads/sign` | Bearer JWT | `canAddQuestions` |

## Admin — Textbooks (`/api/admin/textbooks`)

All routes require `canManageCurriculum`.

| Status | Method | Path |
|--------|--------|------|
| [D] | GET | `/api/admin/textbooks` |
| [D] | POST | `/api/admin/textbooks` |
| [D] | POST | `/api/admin/textbooks/:id/confirm-upload` |
| [D] | GET | `/api/admin/textbooks/:id/status` |
| [D] | GET | `/api/admin/textbooks/:id/analysis` |
| [D] | PATCH | `/api/admin/textbooks/:id/structure` |
| [D] | POST | `/api/admin/textbooks/:id/approve-structure` |
| [D] | POST | `/api/admin/textbooks/:id/retry` |
| [E] | POST | `/api/admin/textbooks/:id/generate-questions` |
| [E] | POST | `/api/admin/textbooks/:id/generate-unit-questions` |
| [E] | GET | `/api/admin/textbooks/:id/units/generation-status` |
| [D] | GET | `/api/admin/textbooks/:id/units/:unitKey/review-sets` |
| [D] | GET | `/api/admin/textbooks/:id/units/:unitKey/review-sets/remaining` |
| [D] | GET | `/api/admin/textbooks/:id/review-sets/:reviewSetId` |
| [E] | GET | `/api/admin/textbooks/:id/generated-questions` |
| [E] | GET | `/api/admin/textbooks/:id/generated-questions/:generatedQuestionId/provenance` |
| [D] | GET | `/api/admin/textbooks/:id/chapter-mapping` |
| [D] | GET | `/api/admin/textbooks/:id/unit-mappings` |
| [D] | PUT | `/api/admin/textbooks/:id/unit-mappings` |
| [E] | POST | `/api/admin/textbooks/:id/generated-questions/bulk-review` |
| [E] | POST | `/api/admin/textbooks/:id/generated-questions/:generatedQuestionId/review` |

Rate limit: Admin bucket (legacy: 100 req / 15 min); AI generation routes also use AI bucket.

## Error contract (all routes)

```json
{
  "error": {
    "code": "UNAUTHORIZED",
    "message": "Authentication required",
    "details": {}
  }
}
```

## Auth flow (preserved from Node)

1. Frontend sends `Authorization: Bearer <supabase_access_token>`
2. Laravel validates token via `GET {SUPABASE_URL}/auth/v1/user`
3. Laravel loads `user_profiles` row (PostgreSQL preferred, REST fallback)
4. Role permissions resolved from `app/Support/Roles.php` (mirrors `backend-node-legacy/src/constants/roles.js`)
