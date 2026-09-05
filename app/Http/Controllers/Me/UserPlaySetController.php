<?php

namespace App\Http\Controllers\Me;

use App\Http\Controllers\Admin\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Me\GeneratePlaySetRequest;
use App\Http\Requests\Me\InspectPlaySetFileRequest;
use App\Http\Requests\Me\SavePlaySetRequest;
use App\Http\Requests\Me\StartPlaySetGameRequest;
use App\Services\Curriculum\DocumentTextExtractionService;
use App\Services\Play\UserPlaySetGameService;
use App\Services\Play\UserPlaySetService;
use Illuminate\Http\JsonResponse;

class UserPlaySetController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly UserPlaySetService $playSets,
        private readonly UserPlaySetGameService $playSetGames,
        private readonly DocumentTextExtractionService $documents,
    ) {}

    public function index(): JsonResponse
    {
        $userId = $this->actor(request())['actorUserId'];

        return $this->success($this->playSets->listForUser($userId));
    }

    public function show(string $id): JsonResponse
    {
        $userId = $this->actor(request())['actorUserId'];

        return $this->success($this->playSets->getForUser($userId, $id));
    }

    public function inspect(InspectPlaySetFileRequest $request): JsonResponse
    {
        return $this->success($this->documents->inspectUploadedFile($request->file('file')));
    }

    public function generate(GeneratePlaySetRequest $request): JsonResponse
    {
        $userId = $this->actor($request)['actorUserId'];
        $validated = $request->validated();

        $result = $this->playSets->generateFromUpload(
            $userId,
            $request->file('file'),
            $validated['title'] ?? null,
            isset($validated['page_from']) ? (int) $validated['page_from'] : null,
            isset($validated['page_to']) ? (int) $validated['page_to'] : null,
        );

        return $this->success($result, 201);
    }

    public function updateDraft(SavePlaySetRequest $request, string $id): JsonResponse
    {
        $userId = $this->actor($request)['actorUserId'];
        $validated = $request->validated();

        return $this->success($this->playSets->updateDraft(
            $userId,
            $id,
            $validated['questions'],
            $validated['title'] ?? null,
        ));
    }

    public function regenerateQuestion(string $id, string $questionId): JsonResponse
    {
        $userId = $this->actor(request())['actorUserId'];

        return $this->success($this->playSets->regenerateQuestion($userId, $id, $questionId));
    }

    public function save(SavePlaySetRequest $request, string $id): JsonResponse
    {
        $userId = $this->actor($request)['actorUserId'];
        $validated = $request->validated();

        return $this->success($this->playSets->save(
            $userId,
            $id,
            $validated['questions'],
            $validated['title'] ?? null,
        ));
    }

    public function startGame(StartPlaySetGameRequest $request, string $id): JsonResponse
    {
        $userId = $this->actor($request)['actorUserId'];

        return $this->success(
            $this->playSetGames->startGame($userId, $id, $request->gamePayload()),
            201,
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $userId = $this->actor(request())['actorUserId'];
        $this->playSets->delete($userId, $id);

        return $this->success(['deleted' => true]);
    }
}
