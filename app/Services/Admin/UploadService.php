<?php

namespace App\Services\Admin;

use App\Exceptions\ValidationException;
use App\Services\Storage\LocalMediaStorageService;
use App\Support\UploadFileName;
use Illuminate\Http\UploadedFile;

class UploadService
{
    public function __construct(
        private readonly LocalMediaStorageService $storage,
    ) {}

    /**
     * @return array{bucket: string, path: string, signed_url: string, token: string, public_url: string, content_type: string, max_bytes: int, mode?: string, url?: string}
     */
    public function createSignedUpload(string $purpose, string $fileName, string $contentType, int $fileSize): array
    {
        $config = $this->purposeConfig($purpose);

        if (! in_array($contentType, $config['mime_types'], true)) {
            throw new ValidationException('Unsupported file type');
        }

        if ($fileSize <= 0 || $fileSize > $config['max_bytes']) {
            throw new ValidationException('File exceeds maximum allowed size');
        }

        $safeName = UploadFileName::sanitize($fileName);

        return $this->storage->createUploadIntent(
            $config['folder'],
            $safeName,
            $contentType,
            $config['max_bytes'],
        );
    }

    public function storeUploadedFile(string $purpose, UploadedFile $file): string
    {
        $config = $this->purposeConfig($purpose);
        $contentType = (string) ($file->getMimeType() ?: $file->getClientMimeType());

        if (! in_array($contentType, $config['mime_types'], true)) {
            throw new ValidationException('Unsupported file type');
        }

        if ($file->getSize() <= 0 || $file->getSize() > $config['max_bytes']) {
            throw new ValidationException('File exceeds maximum allowed size');
        }

        $safeName = UploadFileName::sanitize($file->getClientOriginalName() ?: 'upload.bin');

        return $this->storage->storeUploadedFile($config['folder'], $safeName, $file);
    }

    /**
     * @return array{bucket: string, folder: string, max_bytes: int, mime_types: string[]}
     */
    public function purposeConfig(string $purpose): array
    {
        $purposes = config('uploads.purposes', []);

        if (! isset($purposes[$purpose]) || ! is_array($purposes[$purpose])) {
            throw new ValidationException('Invalid upload purpose');
        }

        return $purposes[$purpose];
    }
}
