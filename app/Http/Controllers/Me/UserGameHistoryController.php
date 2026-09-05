<?php

namespace App\Http\Controllers\Me;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Me\UserGameHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserGameHistoryController extends Controller
{
    public function __construct(
        private readonly UserGameHistoryService $history,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->success($this->history->listForUser($user->id));
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $deletedCount = $this->history->clearForUser($user->id);

        return $this->success([
            'deleted_count' => $deletedCount,
        ]);
    }
}
