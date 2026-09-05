<?php

namespace App\Services\Curriculum;

use App\Exceptions\ServiceUnavailableException;
use App\Exceptions\ValidationException;
use App\Models\Textbook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class TextbookFileStorageService
{
    private const DISK = 'local';

    private const STORED_FILENAME = 'original.pdf';

    /**
     * Relative path inside the private local disk (storage/app/private).
     */
    public function storagePathFor(Textbook $textbook): string
    {
        return 'textbooks/'.$textbook->id.'/'.self::STORED_FILENAME;
    }

    public function absolutePath(Textbook $textbook): string
    {
        return Storage::disk(self::DISK)->path($this->storagePathFor($textbook));
    }

    public function exists(Textbook $textbook): bool
    {
        $path = (string) ($textbook->storage_path ?: $this->storagePathFor($textbook));

        return Storage::disk(self::DISK)->exists($path);
    }

    public function store(Textbook $textbook, UploadedFile $file): void
    {
        $relativePath = $this->storagePathFor($textbook);
        $directory = dirname($relativePath);

        Storage::disk(self::DISK)->makeDirectory($directory);

        $stored = Storage::disk(self::DISK)->putFileAs(
            $directory,
            $file,
            self::STORED_FILENAME
        );

        if ($stored === false || ! Storage::disk(self::DISK)->exists($relativePath)) {
            throw new ServiceUnavailableException('تعذر حفظ ملف الكتاب على الخادم.');
        }

        $textbook->update([
            'storage_bucket' => 'local',
            'storage_path' => $relativePath,
            'file_size_bytes' => Storage::disk(self::DISK)->size($relativePath) ?: $textbook->file_size_bytes,
            'updated_at' => now(),
        ]);
    }

    public function read(Textbook $textbook): string
    {
        $path = (string) ($textbook->storage_path ?: $this->storagePathFor($textbook));

        if (! Storage::disk(self::DISK)->exists($path)) {
            throw new ServiceUnavailableException('لم يُعثر على ملف الكتاب المحفوظ على الخادم.');
        }

        $contents = Storage::disk(self::DISK)->get($path);

        if (! is_string($contents) || $contents === '') {
            throw new ServiceUnavailableException('تعذر قراءة ملف الكتاب المحفوظ.');
        }

        return $contents;
    }

    public function assertStored(Textbook $textbook): void
    {
        if (! $this->exists($textbook)) {
            throw new ValidationException('PDF file has not been uploaded yet');
        }
    }
}
