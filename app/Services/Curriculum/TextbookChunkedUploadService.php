<?php

namespace App\Services\Curriculum;

use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\Textbook;
use App\Models\TextbookUploadSession;
use App\Services\Admin\UploadService;
use App\Support\TextbookProcessingStatus;
use App\Support\TextbookProcessingStage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TextbookChunkedUploadService
{
    private const DISK = 'local';

    public function __construct(
        private readonly UploadService $uploads,
        private readonly TextbookFileStorageService $files,
        private readonly TextbookJobService $jobs,
        private readonly TextbookService $textbooks,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function initSession(array $payload, string $actorUserId): array
    {
        $config = $this->uploads->purposeConfig('textbook-pdf');
        $fileSize = (int) $payload['file_size'];
        $chunkSize = $this->configuredChunkSize();

        if ($fileSize <= 0 || $fileSize > $config['max_bytes']) {
            throw new ValidationException('File exceeds maximum allowed size');
        }

        if (! in_array($payload['content_type'], $config['mime_types'], true)) {
            throw new ValidationException('Unsupported file type');
        }

        $totalChunks = (int) ceil($fileSize / $chunkSize);

        if ($totalChunks < 1) {
            throw new ValidationException('Invalid file size');
        }

        $session = TextbookUploadSession::query()->create([
            'user_id' => $actorUserId,
            'file_name' => $payload['file_name'],
            'file_size' => $fileSize,
            'content_type' => $payload['content_type'],
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
            'received_chunks' => [],
            'file_hash' => isset($payload['file_hash']) ? strtolower((string) $payload['file_hash']) : null,
            'status' => 'uploading',
            'title' => $payload['title'],
            'academic_stage' => $payload['academic_stage'] ?? null,
            'grade' => $payload['grade'] ?? null,
            'subject_id' => $payload['subject_id'] ?? null,
            'academic_year' => $payload['academic_year'] ?? null,
            'semester' => $payload['semester'] ?? null,
            'language' => $payload['language'] ?? 'ar',
            'expires_at' => now()->addHours((int) config('textbook_upload.session_ttl_hours', 24)),
        ]);

        $this->ensureSessionDirectory($session);

        return $this->serializeSession($session);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSession(string $uploadId, string $actorUserId): array
    {
        $session = $this->getOwnedSession($uploadId, $actorUserId);
        $this->assertSessionActive($session);

        return $this->serializeSession($session);
    }

    /**
     * @return array<string, mixed>
     */
    public function storeChunk(
        string $uploadId,
        int $chunkIndex,
        UploadedFile $chunkFile,
        int $declaredChunkSize,
        string $actorUserId,
    ): array {
        $session = $this->getOwnedSession($uploadId, $actorUserId);
        $this->assertSessionActive($session);

        if ($chunkIndex < 0 || $chunkIndex >= $session->total_chunks) {
            throw new ValidationException('Invalid chunk index');
        }

        $expectedSize = $this->expectedChunkSize($session, $chunkIndex);

        if ($declaredChunkSize > 0 && $declaredChunkSize !== $expectedSize) {
            logger()->warning('Textbook chunk size mismatch', [
                'upload_id' => $uploadId,
                'chunk_index' => $chunkIndex,
                'declared_size' => $declaredChunkSize,
                'expected_size' => $expectedSize,
            ]);
            throw new ValidationException('Chunk size mismatch');
        }

        $chunkPath = $this->chunkPath($session, $chunkIndex);
        $incomingSize = (int) $chunkFile->getSize();

        if ($incomingSize !== $expectedSize) {
            logger()->warning('Textbook chunk upload size mismatch', [
                'upload_id' => $uploadId,
                'chunk_index' => $chunkIndex,
                'incoming_size' => $incomingSize,
                'expected_size' => $expectedSize,
            ]);
            throw new ValidationException('Uploaded chunk size does not match expected size');
        }

        $alreadyReceived = in_array($chunkIndex, $session->receivedChunkIndices(), true);

        if ($alreadyReceived && is_file($chunkPath) && filesize($chunkPath) === $expectedSize) {
            logger()->info('Textbook chunk upload skipped (already stored)', [
                'upload_id' => $uploadId,
                'chunk_index' => $chunkIndex,
                'size_bytes' => $expectedSize,
            ]);

            return $this->serializeSession($session->fresh());
        }

        $this->ensureSessionDirectory($session);

        $stream = fopen($chunkFile->getRealPath(), 'rb');

        if ($stream === false) {
            throw new ValidationException('Failed to read uploaded chunk');
        }

        $destination = fopen($chunkPath, 'wb');

        if ($destination === false) {
            fclose($stream);
            throw new ValidationException('Failed to store uploaded chunk');
        }

        try {
            stream_copy_to_stream($stream, $destination);
        } finally {
            fclose($stream);
            fclose($destination);
        }

        clearstatcache(true, $chunkPath);

        if (! is_file($chunkPath) || filesize($chunkPath) !== $expectedSize) {
            @unlink($chunkPath);
            throw new ValidationException('Chunk storage verification failed');
        }

        $received = $session->receivedChunkIndices();

        if (! in_array($chunkIndex, $received, true)) {
            $received[] = $chunkIndex;
            sort($received);
            $session->update(['received_chunks' => $received]);
        }

        logger()->info('Textbook chunk stored', [
            'upload_id' => $uploadId,
            'chunk_index' => $chunkIndex,
            'size_bytes' => $expectedSize,
            'received_count' => count($received),
            'total_chunks' => $session->total_chunks,
        ]);

        return $this->serializeSession($session->fresh());
    }

    /**
     * Assemble chunks, verify integrity, create textbook record, dispatch processing.
     *
     * @return array{textbook: array<string, mixed>, upload: array<string, mixed>}
     */
    public function completeSession(string $uploadId, string $actorUserId): array
    {
        $session = $this->getOwnedSession($uploadId, $actorUserId);
        $this->assertSessionActive($session);

        if ($session->status === 'assembling') {
            throw new ValidationException('Upload assembly is already in progress');
        }

        $received = $session->receivedChunkIndices();

        if (count($received) !== $session->total_chunks) {
            throw new ValidationException('Upload is incomplete');
        }

        for ($index = 0; $index < $session->total_chunks; $index++) {
            if (! in_array($index, $received, true)) {
                throw new ValidationException('Missing chunk '.$index);
            }
        }

        $session->update(['status' => 'assembling', 'last_error' => null]);

        $assembledRelativePath = $this->assembledRelativePath($session);
        $assembledAbsolutePath = Storage::disk(self::DISK)->path($assembledRelativePath);
        $this->ensureParentDirectory($assembledAbsolutePath);

        try {
            $this->assembleChunksToFile($session, $assembledAbsolutePath);
            $this->verifyAssembledFile($session, $assembledAbsolutePath);
        } catch (\Throwable $exception) {
            $session->update([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
            ]);

            if (is_file($assembledAbsolutePath)) {
                @unlink($assembledAbsolutePath);
            }

            throw $exception;
        }

        return DB::transaction(function () use ($session, $assembledRelativePath, $assembledAbsolutePath, $actorUserId) {
            $textbook = Textbook::query()->create([
                'title' => $session->title,
                'academic_stage' => $session->academic_stage,
                'grade' => $session->grade,
                'subject_id' => $session->subject_id,
                'academic_year' => $session->academic_year,
                'semester' => $session->semester,
                'language' => $session->language,
                'storage_bucket' => 'local',
                'storage_path' => '',
                'file_size_bytes' => $session->file_size,
                'processing_status' => TextbookProcessingStatus::UPLOADED,
                'structure_status' => 'pending',
                'created_by' => $actorUserId,
            ]);

            $finalRelativePath = $this->files->storagePathFor($textbook);
            $finalAbsolutePath = Storage::disk(self::DISK)->path($finalRelativePath);
            $this->ensureParentDirectory($finalAbsolutePath);

            if (! rename($assembledAbsolutePath, $finalAbsolutePath)) {
                if (! copy($assembledAbsolutePath, $finalAbsolutePath)) {
                    throw new ValidationException('Failed to move assembled PDF to textbook storage');
                }

                @unlink($assembledAbsolutePath);
            }

            $textbook->update([
                'storage_path' => $finalRelativePath,
                'file_size_bytes' => filesize($finalAbsolutePath) ?: $session->file_size,
                'processing_status' => TextbookProcessingStatus::UPLOADED,
                'last_error' => null,
            ]);

            $session->update([
                'status' => 'completed',
                'textbook_id' => $textbook->id,
            ]);

            $this->deleteSessionChunks($session);

            $this->jobs->enqueue($textbook->id, 'extract_text', [], $actorUserId);

            app(TextbookProcessingTimelineService::class)->advanceToStage($textbook->id, TextbookProcessingStage::UPLOAD, [
                'upload_completed_at' => now()->toIso8601String(),
            ]);
            app(TextbookProcessingTimelineService::class)->advanceToStage($textbook->id, TextbookProcessingStage::SAVE, [
                'save_completed_at' => now()->toIso8601String(),
            ]);
            app(TextbookProcessingTimelineService::class)->initializeAfterSave($textbook->id, null);

            $assembledSize = (int) filesize($finalAbsolutePath);

            logger()->info('Textbook chunked upload completed', [
                'upload_id' => $session->id,
                'textbook_id' => $textbook->id,
                'expected_file_size' => $session->file_size,
                'assembled_file_size' => $assembledSize,
                'extract_job_dispatched' => true,
            ]);

            return [
                'textbook' => $this->textbooks->sanitizeForClient($textbook->fresh()),
                'upload' => [
                    'mode' => 'chunked',
                    'upload_id' => $session->id,
                    'completed' => true,
                    'expected_file_size' => $session->file_size,
                    'assembled_file_size' => $assembledSize,
                    'total_chunks' => $session->total_chunks,
                ],
            ];
        });
    }

    public function cancelSession(string $uploadId, string $actorUserId): void
    {
        $session = $this->getOwnedSession($uploadId, $actorUserId);

        if ($session->status === 'completed') {
            return;
        }

        $this->deleteSessionChunks($session);
        $session->update(['status' => 'cancelled']);
    }

    public function cleanupExpiredSessions(): int
    {
        $removed = 0;

        TextbookUploadSession::query()
            ->where('expires_at', '<', now())
            ->whereNotIn('status', ['completed'])
            ->orderBy('expires_at')
            ->chunkById(50, function ($sessions) use (&$removed) {
                foreach ($sessions as $session) {
                    $this->deleteSessionChunks($session);
                    $session->update(['status' => 'expired']);
                    $removed++;
                }
            });

        return $removed;
    }

    private function assembleChunksToFile(TextbookUploadSession $session, string $assembledAbsolutePath): void
    {
        $output = fopen($assembledAbsolutePath, 'wb');

        if ($output === false) {
            throw new ValidationException('Failed to open assembled file for writing');
        }

        $hashContext = $session->file_hash ? hash_init('sha256') : null;

        try {
            for ($index = 0; $index < $session->total_chunks; $index++) {
                $chunkPath = $this->chunkPath($session, $index);

                if (! is_file($chunkPath)) {
                    throw new ValidationException('Missing chunk file '.$index);
                }

                $input = fopen($chunkPath, 'rb');

                if ($input === false) {
                    throw new ValidationException('Failed to read chunk file '.$index);
                }

                stream_copy_to_stream($input, $output);

                if ($hashContext !== null) {
                    rewind($input);
                    hash_update_stream($hashContext, $input);
                }

                fclose($input);
            }
        } finally {
            fclose($output);
        }

        if ($hashContext !== null) {
            $actualHash = hash_final($hashContext);

            if (! hash_equals(strtolower((string) $session->file_hash), $actualHash)) {
                throw new ValidationException('File hash mismatch after assembly');
            }
        }
    }

    private function verifyAssembledFile(TextbookUploadSession $session, string $assembledAbsolutePath): void
    {
        if (! is_file($assembledAbsolutePath)) {
            throw new ValidationException('Assembled file is missing');
        }

        $size = filesize($assembledAbsolutePath);

        if ($size !== $session->file_size) {
            throw new ValidationException('Assembled file size mismatch');
        }

        $header = file_get_contents($assembledAbsolutePath, false, null, 0, 5);

        if ($header !== '%PDF-') {
            throw new ValidationException('Assembled file is not a valid PDF');
        }
    }

    private function getOwnedSession(string $uploadId, string $actorUserId): TextbookUploadSession
    {
        $session = TextbookUploadSession::query()->find($uploadId);

        if ($session === null) {
            throw new NotFoundException('Upload session not found');
        }

        if ($session->user_id !== $actorUserId) {
            throw new NotFoundException('Upload session not found');
        }

        return $session;
    }

    private function assertSessionActive(TextbookUploadSession $session): void
    {
        if ($session->isExpired()) {
            throw new ValidationException('Upload session has expired');
        }

        if (in_array($session->status, ['completed', 'cancelled', 'expired'], true)) {
            throw new ValidationException('Upload session is no longer active');
        }
    }

    private function configuredChunkSize(): int
    {
        $chunkSize = (int) config('textbook_upload.chunk_size', 10 * 1024 * 1024);
        $min = 8 * 1024 * 1024;
        $max = 16 * 1024 * 1024;

        return max($min, min($max, $chunkSize));
    }

    private function expectedChunkSize(TextbookUploadSession $session, int $chunkIndex): int
    {
        if ($chunkIndex === $session->total_chunks - 1) {
            $remainder = $session->file_size % $session->chunk_size;

            return $remainder > 0 ? $remainder : $session->chunk_size;
        }

        return $session->chunk_size;
    }

    private function sessionDirectory(TextbookUploadSession $session): string
    {
        return 'textbook-uploads/'.$session->id;
    }

    private function chunkPath(TextbookUploadSession $session, int $chunkIndex): string
    {
        return Storage::disk(self::DISK)->path($this->sessionDirectory($session).'/chunk-'.$chunkIndex.'.part');
    }

    private function assembledRelativePath(TextbookUploadSession $session): string
    {
        return $this->sessionDirectory($session).'/assembled.pdf';
    }

    private function ensureSessionDirectory(TextbookUploadSession $session): void
    {
        Storage::disk(self::DISK)->makeDirectory($this->sessionDirectory($session));
    }

    private function ensureParentDirectory(string $absolutePath): void
    {
        $directory = dirname($absolutePath);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new ValidationException('Failed to prepare storage directory');
        }
    }

    private function deleteSessionChunks(TextbookUploadSession $session): void
    {
        Storage::disk(self::DISK)->deleteDirectory($this->sessionDirectory($session));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSession(TextbookUploadSession $session): array
    {
        $received = $session->receivedChunkIndices();
        $missing = [];

        for ($index = 0; $index < $session->total_chunks; $index++) {
            if (! in_array($index, $received, true)) {
                $missing[] = $index;
            }
        }

        return [
            'upload_id' => $session->id,
            'status' => $session->status,
            'file_name' => $session->file_name,
            'file_size' => $session->file_size,
            'chunk_size' => $session->chunk_size,
            'total_chunks' => $session->total_chunks,
            'received_chunks' => $received,
            'missing_chunks' => $missing,
            'bytes_received' => $this->bytesReceived($session, $received),
            'progress_percent' => $session->total_chunks > 0
                ? (int) floor((count($received) / $session->total_chunks) * 100)
                : 0,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'textbook_id' => $session->textbook_id,
            'last_error' => $session->last_error,
            'upload' => [
                'mode' => 'chunked',
                'chunk_url' => '/api/admin/textbooks/uploads/'.$session->id.'/chunks/{chunk_index}',
                'complete_url' => '/api/admin/textbooks/uploads/'.$session->id.'/complete',
            ],
        ];
    }

    /**
     * @param  list<int>  $received
     */
    private function bytesReceived(TextbookUploadSession $session, array $received): int
    {
        $bytes = 0;

        foreach ($received as $chunkIndex) {
            $bytes += $this->expectedChunkSize($session, (int) $chunkIndex);
        }

        return min($bytes, $session->file_size);
    }
}
