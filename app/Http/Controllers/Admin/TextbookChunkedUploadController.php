<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InitTextbookChunkedUploadRequest;
use App\Http\Requests\Admin\UploadTextbookChunkRequest;
use App\Services\Audit\AuditService;
use App\Services\Curriculum\TextbookChunkedUploadService;
use Illuminate\Http\JsonResponse;

class TextbookChunkedUploadController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly TextbookChunkedUploadService $chunkedUploads,
        private readonly AuditService $audit,
    ) {}

    public function init(InitTextbookChunkedUploadRequest $request): JsonResponse
    {
        $data = $this->chunkedUploads->initSession(
            $request->validated(),
            $this->actor($request)['actorUserId'],
        );

        $this->audit->write(
            $request->attributes->get('auth_user')->id,
            'TEXTBOOK_CHUNKED_UPLOAD_INIT',
            $data['upload_id'] ?? null,
            true,
            [
                'file_name' => $data['file_name'] ?? null,
                'file_size' => $data['file_size'] ?? null,
                'total_chunks' => $data['total_chunks'] ?? null,
            ],
        );

        return $this->success($data, 201);
    }

    public function show(string $uploadId): JsonResponse
    {
        return $this->success($this->chunkedUploads->getSession(
            $uploadId,
            $this->actor(request())['actorUserId'],
        ));
    }

    public function storeChunk(UploadTextbookChunkRequest $request, string $uploadId, int $chunkIndex): JsonResponse
    {
        $chunk = $request->file('chunk');

        $data = $this->chunkedUploads->storeChunk(
            $uploadId,
            $chunkIndex,
            $chunk,
            (int) $request->input('chunk_size', $chunk?->getSize() ?? 0),
            $this->actor($request)['actorUserId'],
        );

        return $this->success($data);
    }

    public function complete(string $uploadId): JsonResponse
    {
        $actor = $this->actor(request());

        try {
            $data = $this->chunkedUploads->completeSession($uploadId, $actor['actorUserId']);

            $this->audit->write(
                request()->attributes->get('auth_user')->id,
                'TEXTBOOK_CHUNKED_UPLOAD_COMPLETED',
                $data['textbook']['id'] ?? null,
                true,
                [
                    'upload_id' => $uploadId,
                    'file_size_bytes' => $data['textbook']['file_size_bytes'] ?? null,
                ],
            );

            return $this->success($data, 201);
        } catch (\Throwable $exception) {
            logger()->error('Textbook chunked upload complete failed', [
                'upload_id' => $uploadId,
                'http_status' => 500,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $this->audit->write(
                request()->attributes->get('auth_user')?->id,
                'TEXTBOOK_CHUNKED_UPLOAD_COMPLETED',
                $uploadId,
                false,
            );

            throw $exception;
        }
    }

    public function destroy(string $uploadId): JsonResponse
    {
        $this->chunkedUploads->cancelSession($uploadId, $this->actor(request())['actorUserId']);

        return $this->success(['cancelled' => true]);
    }
}
