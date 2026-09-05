<?php

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class LocalMediaStorageService
{
    private const DISK = 'public';

    /**
     * @return array{bucket: string, path: string, signed_url: string, token: string, public_url: string, content_type: string, max_bytes: int, mode: string, url: string}
     */
    public function createUploadIntent(
        string $folder,
        string $safeFileName,
        string $contentType,
        int $maxBytes,
    ): array {
        $path = trim($folder, '/').'/'.$safeFileName;

        return [
            'mode' => 'api',
            'url' => '/api/admin/uploads',
            'bucket' => self::DISK,
            'path' => $path,
            'signed_url' => '/api/admin/uploads',
            'token' => '',
            'public_url' => $this->publicUrl($path),
            'content_type' => $contentType,
            'max_bytes' => $maxBytes,
        ];
    }

    public function storeUploadedFile(string $folder, string $safeFileName, UploadedFile $file): string
    {
        $path = trim($folder, '/').'/'.$safeFileName;
        Storage::disk(self::DISK)->putFileAs(trim($folder, '/'), $file, $safeFileName);

        return $this->publicUrl($path);
    }

    public function publicUrl(string $path): string
    {
        return Storage::disk(self::DISK)->url($path);
    }
}
