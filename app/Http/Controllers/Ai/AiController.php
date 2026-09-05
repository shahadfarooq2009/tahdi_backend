<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Admin\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\AiGenerateRequest;
use App\Services\Ai\AiService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;

class AiController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly AiService $ai,
        private readonly AuditService $audit,
    ) {}

    public function status(): JsonResponse
    {
        try {
            $data = $this->ai->getStatus();

            $this->audit->write(
                request()->attributes->get('auth_user')->id,
                'AI_STATUS_CHECK',
                null,
                true,
                ['configured' => $data['configured'], 'ready' => $data['ready']],
            );

            return $this->success($data);
        } catch (\Throwable $exception) {
            $this->audit->write(
                request()->attributes->get('auth_user')?->id,
                'AI_STATUS_CHECK',
                null,
                false,
            );

            throw $exception;
        }
    }

    public function generateQuestion(AiGenerateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->ai->generateQuestions(
                $validated['category'],
                $validated['subject'],
                (int) $validated['points'],
                (int) ($validated['count'] ?? 1),
            );

            $this->audit->write(
                $request->attributes->get('auth_user')->id,
                'AI_GENERATE_QUESTION',
                null,
                true,
                [
                    'category' => $validated['category'],
                    'subject' => $validated['subject'],
                    'points' => $validated['points'],
                    'count' => $validated['count'] ?? 1,
                    'usedFallback' => $result['usedFallback'],
                    'questionCount' => count($result['questions']),
                ],
            );

            return $this->success($result);
        } catch (\Throwable $exception) {
            $this->audit->write(
                $request->attributes->get('auth_user')?->id,
                'AI_GENERATE_QUESTION',
                null,
                false,
                [
                    'category' => $validated['category'] ?? null,
                    'subject' => $validated['subject'] ?? null,
                    'points' => $validated['points'] ?? null,
                ],
            );

            throw $exception;
        }
    }
}
