<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ValidationException;
use App\Http\Controllers\Admin\Concerns\ResolvesActor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SignUploadRequest;
use App\Http\Requests\Admin\StoreUploadRequest;
use App\Services\Admin\UploadService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;

class UploadController extends Controller
{
    use ResolvesActor;

    public function __construct(
        private readonly UploadService $uploads,
        private readonly AuditService $audit,
    ) {}

    public function sign(SignUploadRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $config = $this->uploads->purposeConfig($validated['purpose']);

            if ($validated['file_size'] > $config['max_bytes']) {
                throw new ValidationException('File exceeds maximum allowed size');
            }

            $data = $this->uploads->createSignedUpload(
                $validated['purpose'],
                $validated['file_name'],
                $validated['content_type'],
                (int) $validated['file_size'],
            );

            $this->audit->write(
                $request->attributes->get('auth_user')->id,
                'UPLOAD_SIGN_CREATED',
                null,
                true,
                [
                    'purpose' => $validated['purpose'],
                    'path' => $data['path'],
                    'file_size' => $validated['file_size'],
                ],
            );

            return $this->success($data);
        } catch (\Throwable $exception) {
            $this->audit->write(
                $request->attributes->get('auth_user')?->id,
                'UPLOAD_SIGN_CREATED',
                null,
                false,
                ['purpose' => $validated['purpose'] ?? null],
            );

            throw $exception;
        }
    }

    public function store(StoreUploadRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $publicUrl = $this->uploads->storeUploadedFile(
                $validated['purpose'],
                $request->file('file'),
            );

            $this->audit->write(
                $request->attributes->get('auth_user')->id,
                'UPLOAD_STORED',
                null,
                true,
                [
                    'purpose' => $validated['purpose'],
                    'public_url' => $publicUrl,
                ],
            );

            return $this->success([
                'public_url' => $publicUrl,
            ], 201);
        } catch (\Throwable $exception) {
            $this->audit->write(
                $request->attributes->get('auth_user')?->id,
                'UPLOAD_STORED',
                null,
                false,
                ['purpose' => $validated['purpose'] ?? null],
            );

            throw $exception;
        }
    }
}
