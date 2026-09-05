<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Game\GameCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class GameCatalogController extends Controller
{
    public function __construct(
        private readonly GameCatalogService $catalog,
    ) {}

    public function categories(): JsonResponse
    {
        return $this->success($this->catalog->listCategories());
    }

    public function subjects(Request $request): JsonResponse
    {
        $challengeType = (string) $request->query('challenge_type', 'school');

        if ($challengeType === 'family') {
            return $this->success($this->catalog->listFamilySubjects());
        }

        return $this->success($this->catalog->listSchoolSubjects(
            $request->query('educational_stage') ? (string) $request->query('educational_stage') : null,
            $request->query('grade') ? (string) $request->query('grade') : null,
        ));
    }

    public function familySubjectCounts(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        return $this->success($this->catalog->familySubjectQuestionCounts(
            is_object($user) ? $user->id : null
        ));
    }

    public function subjectChapters(Request $request): JsonResponse
    {
        $subjectName = trim((string) $request->query('subject_name', ''));
        $subjectId = $request->query('subject_id') ? (string) $request->query('subject_id') : null;

        if ($subjectName === '' && ($subjectId === null || trim($subjectId) === '')) {
            return $this->success([]);
        }

        return $this->success($this->catalog->listChaptersForSubject(
            $subjectName,
            $request->query('educational_stage') ? (string) $request->query('educational_stage') : null,
            $request->query('grade') ? (string) $request->query('grade') : null,
            $this->optionalUserId($request),
            $request->query('subject_id') ? (string) $request->query('subject_id') : null,
            $request->query('course_id') ? (string) $request->query('course_id') : null,
        ));
    }

    public function subjectCourses(Request $request, string $subjectId): JsonResponse
    {
        return $this->success($this->catalog->listCoursesForSubject(
            $subjectId,
            $request->query('grade') ? (string) $request->query('grade') : null,
        ));
    }

    public function unitGames(Request $request, string $unitId): JsonResponse
    {
        return $this->success($this->catalog->listGamesForUnit(
            $unitId,
            $this->optionalUserId($request),
        ));
    }

    private function optionalUserId(Request $request): ?string
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($bearerToken);

        if (! $accessToken) {
            return null;
        }

        $user = $accessToken->tokenable;

        return $user instanceof User ? $user->id : null;
    }
}
