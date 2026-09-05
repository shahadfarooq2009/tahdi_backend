<?php

namespace App\Http\Controllers\Me;

use App\Exceptions\ValidationException;
use App\Http\Controllers\Controller;
use App\Services\Me\ViewedQuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViewedQuestionController extends Controller
{
    public function __construct(
        private readonly ViewedQuestionService $viewedQuestions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('auth_user')->id;

        return $this->success($this->viewedQuestions->listQuestionIds($userId));
    }

    public function store(Request $request): JsonResponse
    {
        $questionId = (string) $request->input('question_id', '');

        if ($questionId === '') {
            throw new ValidationException('question_id is required');
        }

        $userId = $request->attributes->get('auth_user')->id;
        $this->viewedQuestions->mark($userId, $questionId);

        return $this->success(['question_id' => $questionId], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $userId = $request->attributes->get('auth_user')->id;
        $this->viewedQuestions->reset($userId);

        return $this->success(['cleared' => true]);
    }
}
