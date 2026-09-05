<?php

namespace App\Services\Curriculum;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class PdfPageOcrService
{
    public function __construct(
        private readonly PdfExternalTools $tools,
        private readonly ArabicPdfTextNormalizer $normalizer,
        private readonly ArabicExtractionQualityService $quality,
    ) {}

    public function isAvailable(): bool
    {
        return (bool) config('textbook_extraction.ocr_enabled', true) && $this->tools->ocrAvailable();
    }

    public function resolvedLanguage(): string
    {
        return $this->tools->resolveOcrLanguage();
    }

    /**
     * @return array{text: string, quality: array<string, mixed>, exit_code: int, stderr: string}|null
     */
    public function ocrPage(string $absolutePdfPath, int $pageNumber, ?string $sharedTempDir = null): ?array
    {
        $results = $this->ocrPagesBatch($absolutePdfPath, [$pageNumber], $sharedTempDir);

        return $results[$pageNumber] ?? null;
    }

    /**
     * OCR multiple pages, optionally in small parallel batches.
     *
     * @param  list<int>  $pageNumbers
     * @return array<int, array{text: string, quality: array<string, mixed>, exit_code: int, stderr: string}|null>
     */
    public function ocrPagesBatch(string $absolutePdfPath, array $pageNumbers, ?string $sharedTempDir = null): array
    {
        if (! $this->isAvailable() || ! is_file($absolutePdfPath) || $pageNumbers === []) {
            return [];
        }

        $results = [];

        foreach ($pageNumbers as $pageNumber) {
            $results[$pageNumber] = $this->ocrSinglePage($absolutePdfPath, $pageNumber, $sharedTempDir);
        }

        return $results;
    }

    public function cleanupDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($directory);
    }

    /**
     * @return array{text: string, quality: array<string, mixed>, exit_code: int, stderr: string}|null
     */
    private function ocrSinglePage(string $absolutePdfPath, int $pageNumber, ?string $sharedTempDir = null): ?array
    {
        if (! $this->isAvailable() || ! is_file($absolutePdfPath)) {
            return null;
        }

        $resolved = $this->tools->resolve();
        $dpi = (int) config('textbook_extraction.ocr_dpi', 200);
        $language = $this->resolvedLanguage();
        $tempDir = $sharedTempDir ?: storage_path('app/tmp/ocr/'.Str::uuid());
        $prefix = $tempDir.'/page-'.$pageNumber;
        $ownedTempDir = $sharedTempDir === null;

        if (! is_dir($tempDir) && ! mkdir($tempDir, 0775, true) && ! is_dir($tempDir)) {
            return null;
        }

        try {
            $render = Process::timeout(120)->run([
                $resolved['pdftoppm'],
                '-f', (string) $pageNumber,
                '-l', (string) $pageNumber,
                '-r', (string) $dpi,
                '-png',
                '-singlefile',
                $absolutePdfPath,
                $prefix,
            ]);

            if (! $render->successful()) {
                return null;
            }

            $imagePath = $prefix.'.png';

            if (! is_file($imagePath)) {
                return null;
            }

            $ocr = Process::timeout(120)->run([
                $resolved['tesseract'],
                $imagePath,
                'stdout',
                '-l', $language,
                '--psm', '6',
            ]);

            if (! $ocr->successful()) {
                return null;
            }

            $text = $this->normalizer->normalizePageText(trim($ocr->output()));

            if ($text === '') {
                return null;
            }

            return [
                'text' => $text,
                'quality' => $this->quality->assessPage($text),
                'exit_code' => $ocr->exitCode() ?? 0,
                'stderr' => trim($ocr->errorOutput()),
            ];
        } catch (\Throwable) {
            return null;
        } finally {
            if ($ownedTempDir) {
                $this->cleanupDirectory($tempDir);
            } else {
                @unlink($prefix.'.png');
            }
        }
    }
}
