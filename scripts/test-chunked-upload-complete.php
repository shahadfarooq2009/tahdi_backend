<?php

declare(strict_types=1);

/**
 * Test chunked upload init → chunks → complete without extraction during /complete.
 *
 * Usage:
 *   php scripts/test-chunked-upload-complete.php [path/to/file.pdf]
 *
 * If no path is given, creates a temporary ~146 MB file for assembly testing.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\TextbookProcessingJob;
use App\Models\User;
use App\Services\Curriculum\TextbookChunkedUploadService;
use Illuminate\Http\UploadedFile;

$pdfPath = $argv[1] ?? null;
$tempFile = null;

if ($pdfPath === null) {
    $targetBytes = 152882380;
    $tempFile = tempnam(sys_get_temp_dir(), 'chunk-test-');
    $handle = fopen($tempFile, 'wb');
    fwrite($handle, "%PDF-1.4\n% minimal chunked upload test\n");
    $written = ftell($handle);
    $chunk = str_repeat('0', 1024 * 1024);

    while ($written < $targetBytes) {
        $remaining = $targetBytes - $written;
        $size = min(strlen($chunk), $remaining);
        fwrite($handle, substr($chunk, 0, $size));
        $written += $size;
    }

    fclose($handle);
    $pdfPath = $tempFile;
    fwrite(STDOUT, "Created temp test file: {$pdfPath} ({$written} bytes)\n");
}

if (! is_file($pdfPath)) {
    fwrite(STDERR, "File not found: {$pdfPath}\n");
    exit(1);
}

$fileSize = (int) filesize($pdfPath);
$actor = User::query()->orderBy('created_at')->first();

if (! $actor) {
    fwrite(STDERR, "No users found in database\n");
    exit(1);
}

$service = app(TextbookChunkedUploadService::class);
$chunkSize = (int) config('textbook_upload.chunk_size', 10 * 1024 * 1024);
$totalChunks = (int) ceil($fileSize / $chunkSize);

fwrite(STDOUT, "File: {$pdfPath}\n");
fwrite(STDOUT, "Size: {$fileSize} bytes (".round($fileSize / (1024 * 1024), 1)." MB)\n");
fwrite(STDOUT, "Chunk size: {$chunkSize} bytes\n");
fwrite(STDOUT, "Total chunks: {$totalChunks}\n");
fwrite(STDOUT, "Actor: {$actor->id}\n\n");

$peakBefore = memory_get_peak_usage(true);

$session = $service->initSession([
    'title' => 'Chunk upload test '.now()->format('Y-m-d H:i:s'),
    'file_name' => basename($pdfPath),
    'content_type' => 'application/pdf',
    'file_size' => $fileSize,
    'language' => 'ar',
], $actor->id);

$uploadId = $session['upload_id'];
fwrite(STDOUT, "Init OK — upload_id={$uploadId}\n");

$handle = fopen($pdfPath, 'rb');

for ($index = 0; $index < $totalChunks; $index++) {
    $start = $index * $chunkSize;
    $length = min($chunkSize, $fileSize - $start);
    $data = fread($handle, $length);

    if ($data === false || strlen($data) !== $length) {
        fwrite(STDERR, "Failed to read chunk {$index}\n");
        exit(1);
    }

    $chunkPath = sys_get_temp_dir().DIRECTORY_SEPARATOR."chunk-{$uploadId}-{$index}.part";
    file_put_contents($chunkPath, $data);

    $uploaded = new UploadedFile($chunkPath, "chunk-{$index}.part", 'application/octet-stream', null, true);

    $service->storeChunk($uploadId, $index, $uploaded, $length, $actor->id);
    @unlink($chunkPath);

    fwrite(STDOUT, "Chunk {$index}/{$totalChunks} uploaded\n");
}

fclose($handle);

$peakAfterChunks = memory_get_peak_usage(true);
fwrite(STDOUT, "\nAll chunks uploaded. Calling /complete...\n");

try {
    $result = $service->completeSession($uploadId, $actor->id);
} catch (Throwable $exception) {
    fwrite(STDERR, "COMPLETE FAILED: {$exception->getMessage()}\n");
    fwrite(STDERR, $exception::class."\n");
    exit(1);
}

$peakAfterComplete = memory_get_peak_usage(true);
$textbookId = $result['textbook']['id'] ?? null;
$assembledSize = (int) ($result['upload']['assembled_file_size'] ?? 0);
$expectedSize = (int) ($result['upload']['expected_file_size'] ?? $fileSize);

$jobDispatched = TextbookProcessingJob::query()
    ->where('textbook_id', $textbookId)
    ->where('job_type', 'extract_text')
    ->exists();

fwrite(STDOUT, "\n=== RESULT ===\n");
fwrite(STDOUT, "Failing endpoint (before fix): POST /api/admin/textbooks/uploads/{id}/complete (HTTP 500 OOM in pdfparser)\n");
fwrite(STDOUT, "Complete: OK\n");
fwrite(STDOUT, "Textbook ID: {$textbookId}\n");
fwrite(STDOUT, "Total chunks: {$totalChunks}\n");
fwrite(STDOUT, "Expected size: {$expectedSize}\n");
fwrite(STDOUT, "Assembled size: {$assembledSize}\n");
fwrite(STDOUT, "Size match: ".($assembledSize === $expectedSize ? 'yes' : 'no')."\n");
fwrite(STDOUT, "extract_text job dispatched: ".($jobDispatched ? 'yes' : 'no')."\n");
fwrite(STDOUT, "Peak memory after chunks: ".round($peakAfterChunks / (1024 * 1024), 1)." MB\n");
fwrite(STDOUT, "Peak memory after complete: ".round($peakAfterComplete / (1024 * 1024), 1)." MB\n");

if ($tempFile !== null) {
    @unlink($tempFile);
}

exit($assembledSize === $expectedSize && $jobDispatched ? 0 : 1);
