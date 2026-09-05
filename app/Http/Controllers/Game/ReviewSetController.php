<?php

namespace App\Http\Controllers\Game;

use App\Exceptions\ValidationException;
use App\Http\Controllers\Admin\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\ReviewSetUsageRequest;
use App\Services\Curriculum\ReviewSetUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewSetController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly ReviewSetUsageService $reviewSetUsage,
    ) {}

    public function next(Request $request, string $textbookId, string $unitKey): JsonResponse
    {
        $className = trim((string) $request->query('class_name', ''));

        if ($className === '') {
            throw new ValidationException('class_name is required');
        }

        $hostUserId = $this->actor($request)['actorUserId'];

        return $this->success($this->reviewSetUsage->selectNextReviewSet(
            $textbookId,
            $unitKey,
            $hostUserId,
            $className
        ));
    }

    public function remaining(Request $request, string $textbookId, string $unitKey): JsonResponse
    {
        return $this->success($this->reviewSetUsage->getRemainingReviewSetCount(
            $textbookId,
            $unitKey,
            $this->actor($request)['actorUserId'],
            trim((string) $request->query('class_name', ''))
        ));
    }

    public function markUsed(ReviewSetUsageRequest $request, string $reviewSetId): JsonResponse
    {
        $validated = $request->validated();

        return $this->success($this->reviewSetUsage->recordReviewSetUsage(
            $reviewSetId,
            $validated['textbook_id'],
            $validated['unit_key'],
            $this->actor($request)['actorUserId'],
            $validated['class_name'],
            $validated['game_session_id'] ?? null,
        ));
    }
}
