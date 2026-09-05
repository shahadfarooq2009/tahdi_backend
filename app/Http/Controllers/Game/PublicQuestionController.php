<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\ValidationException;
use App\Http\Controllers\Controller;
use App\Services\Game\GameSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicQuestionController extends Controller
{
    public function __construct(
        private readonly GameSessionService $sessions,
    ) {}

    public function show(Request $request, string $questionId): JsonResponse
    {
        $sessionId = $this->sessionIdFromQuery($request);

        return $this->success($this->sessions->getPublicSessionQuestion($questionId, $sessionId));
    }

    public function revealAnswer(Request $request, string $questionId): JsonResponse
    {
        $sessionId = $this->sessionIdFromQuery($request);

        return $this->success($this->sessions->revealPublicSessionQuestionAnswer($questionId, $sessionId));
    }

    private function sessionIdFromQuery(Request $request): string
    {
        $sessionId = $request->query('sessionId');

        if (! is_string($sessionId) || trim($sessionId) === '') {
            throw new ValidationException('sessionId query parameter is required');
        }

        return trim($sessionId);
    }
}
