<?php

namespace App\Support;

final class UploadFileName
{
    public static function sanitize(string $fileName): string
    {
        $baseName = basename(str_replace('\\', '/', $fileName)) ?: 'file';
        $parts = explode('.', $baseName);
        $extension = count($parts) > 1 ? strtolower((string) array_pop($parts)) : 'bin';
        $stem = preg_replace('/[^a-zA-Z0-9_-]+/', '-', implode('.', $parts)) ?? 'file';
        $stem = substr($stem !== '' ? $stem : 'file', 0, 80);

        return time().'-'.$stem.'.'.$extension;
    }
}
