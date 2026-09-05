<?php

namespace App\Http\Controllers\Game;

use App\Http\Controllers\Admin\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\AdjustScoreRequest;
use App\Http\Requests\Game\ApplyPowerupRequest;
use App\Http\Requests\Game\AssignSchoolTileRequest;
use App\Http\Requests\Game\ClaimTileRequest;
use App\Http\Requests\Game\CreateGameSessionRequest;
use App\Http\Requests\Game\SubmitQuestionOutcomeRequest;
use App\Http\Requests\Game\UpdateGameSessionRequest;
use App\Services\Audit\AuditService;
use App\Services\Game\GameSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameSessionController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly GameSessionService $sessions,
        private readonly AuditService $audit,
    ) {}

    public function store(CreateGameSessionRequest $request): JsonResponse
    {
        try {
            $data = $this->sessions->createGameSession($request->sessionPayload(), $this->actor($request)['actorUserId']);
            $this->audit->write($request->attributes->get('auth_user')->id, 'GAME_SESSION_CREATED', $data['session']['id'] ?? null, true, [
                'mode' => $request->validated('mode'),
                'team_count' => count($request->validated('teams')),
            ]);

            return $this->success($data, 201);
        } catch (\Throwable $e) {
            $this->audit->write($request->attributes->get('auth_user')->id ?? null, 'GAME_SESSION_CREATED', null, false);
            throw $e;
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->success($this->sessions->getGameSession($id, $this->actor($request)['actorUserId']));
    }

    public function update(UpdateGameSessionRequest $request, string $id): JsonResponse
    {
        return $this->success($this->sessions->updateGameSession($id, $request->validated(), $this->actor($request)['actorUserId']));
    }

    public function finish(Request $request, string $id): JsonResponse
    {
        $data = $this->sessions->finishGameSession($id, $this->actor($request)['actorUserId'], [
            'winner_team_id' => $request->input('winner_team_id'),
            'completion_reason' => $request->input('completion_reason', 'manual'),
        ]);

        $this->audit->write($request->attributes->get('auth_user')->id, 'GAME_SESSION_FINISHED', $id, true);

        return $this->success($data);
    }

    public function questions(Request $request, string $sessionId): JsonResponse
    {
        return $this->success($this->sessions->listSafeSessionQuestions($sessionId, $this->actor($request)['actorUserId']));
    }

    public function revealAnswer(Request $request, string $sessionId, string $questionId): JsonResponse
    {
        return $this->success($this->sessions->revealSessionQuestionAnswer(
            $sessionId,
            $questionId,
            $this->actor($request)['actorUserId']
        ));
    }

    public function submitAnswer(SubmitQuestionOutcomeRequest $request, string $sessionId, string $questionId): JsonResponse
    {
        return $this->success($this->sessions->submitQuestionOutcome(
            $sessionId,
            $questionId,
            $request->validated(),
            $this->actor($request)['actorUserId']
        ));
    }

    public function claimTile(ClaimTileRequest $request, string $sessionId): JsonResponse
    {
        $validated = $request->validated();

        return $this->success($this->sessions->claimBoardTile(
            $sessionId,
            (int) $validated['row'],
            (int) $validated['col'],
            $this->actor($request)['actorUserId']
        ));
    }

    public function assignTile(AssignSchoolTileRequest $request, string $sessionId): JsonResponse
    {
        $validated = $request->validated();

        return $this->success($this->sessions->assignSchoolTileTeam(
            $sessionId,
            (int) $validated['row'],
            (int) $validated['col'],
            (int) $validated['team_index'],
            $this->actor($request)['actorUserId']
        ));
    }

    public function applyPowerup(ApplyPowerupRequest $request, string $sessionId): JsonResponse
    {
        return $this->success($this->sessions->applySchoolPowerup(
            $sessionId,
            $request->validated(),
            $this->actor($request)['actorUserId']
        ));
    }

    public function scores(Request $request, string $sessionId): JsonResponse
    {
        $data = $this->sessions->getGameSession($sessionId, $this->actor($request)['actorUserId']);

        return $this->success([
            'scores' => $data['scores'],
            'teams' => $data['teams'],
        ]);
    }

    public function adjustScore(AdjustScoreRequest $request, string $sessionId): JsonResponse
    {
        $validated = $request->validated();

        return $this->success($this->sessions->adjustTeamScore(
            $sessionId,
            (int) $validated['team_index'],
            (int) $validated['delta'],
            $this->actor($request)['actorUserId']
        ));
    }
}
