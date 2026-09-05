<?php

namespace App\Services\Curriculum;

use Illuminate\Support\Facades\Process;
use Smalot\PdfParser\Parser;

class PopplerPdfTextExtractor
{
    public function __construct(
        private readonly PdfExternalTools $tools,
    ) {}

    public function isAvailable(): bool
    {
        return $this->tools->popplerTextAvailable();
    }

    /**
     * @return array<int, array{page_number: int, content_text: string}>|null
     */
    public function extractPages(string $absolutePdfPath): ?array
    {
        $pdftotext = $this->tools->resolve()['pdftotext'];

        if ($pdftotext === null || ! is_file($absolutePdfPath)) {
            return null;
        }

        try {
            $result = Process::timeout(180)->run([
                $pdftotext,
                '-layout',
                '-enc', 'UTF-8',
                $absolutePdfPath,
                '-',
            ]);

            if (! $result->successful()) {
                return null;
            }

            return $this->splitIntoPages($result->output());
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{content_text: string, score: float}|null
     */
    public function extractPage(string $absolutePdfPath, int $pageNumber): ?array
    {
        $pdftotext = $this->tools->resolve()['pdftotext'];

        if ($pdftotext === null || ! is_file($absolutePdfPath)) {
            return null;
        }

        $result = $this->runPdftotext($pdftotext, $absolutePdfPath, $pageNumber, $pageNumber);
        $text = trim($result['stdout']);

        if ($result['exit_code'] !== 0 || $text === '') {
            return null;
        }

        return [
            'content_text' => $text,
            'score' => app(ArabicPdfTextNormalizer::class)->scoreLine($text),
        ];
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    private function runPdftotext(string $pdftotext, string $absolutePdfPath, int $fromPage, int $toPage): array
    {
        try {
            $result = Process::timeout(120)->run([
                $pdftotext,
                '-layout',
                '-enc', 'UTF-8',
                '-f', (string) $fromPage,
                '-l', (string) $toPage,
                $absolutePdfPath,
                '-',
            ]);

            return [
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
                'exit_code' => $result->exitCode() ?? 1,
            ];
        } catch (\Throwable $exception) {
            return [
                'stdout' => '',
                'stderr' => $exception->getMessage(),
                'exit_code' => 1,
            ];
        }
    }

    /**
     * @return array{pages: array<int, array{page_number: int, content_text: string}>, errors: array<string, mixed>}
     */
    public function extractPageRangeWithDiagnostics(string $absolutePdfPath, int $fromPage, int $toPage): array
    {
        $pdftotext = $this->tools->resolve()['pdftotext'];

        if ($pdftotext === null || ! is_file($absolutePdfPath)) {
            return [
                'pages' => [],
                'errors' => ['reason' => 'pdftotext binary or PDF missing'],
            ];
        }

        $batch = $this->runPdftotext($pdftotext, $absolutePdfPath, $fromPage, $toPage);

        if ($batch['exit_code'] === 0 && trim($batch['stdout']) !== '') {
            $pages = $this->splitIntoPages($batch['stdout']);

            foreach ($pages as $index => $page) {
                $pages[$index]['page_number'] = $fromPage + $index;
            }

            if ($pages !== []) {
                return ['pages' => $pages, 'errors' => []];
            }
        }

        $errors = [
            'batch_exit_code' => $batch['exit_code'],
            'batch_stderr' => trim($batch['stderr']),
            'batch_stdout_length' => strlen($batch['stdout']),
            'per_page_failures' => [],
        ];

        $pages = [];

        for ($pageNumber = $fromPage; $pageNumber <= $toPage; $pageNumber++) {
            $single = $this->runPdftotext($pdftotext, $absolutePdfPath, $pageNumber, $pageNumber);
            $text = trim($single['stdout']);

            if ($single['exit_code'] !== 0 || $text === '') {
                $errors['per_page_failures'][$pageNumber] = [
                    'exit_code' => $single['exit_code'],
                    'stderr' => trim($single['stderr']),
                ];

                continue;
            }

            $pages[] = [
                'page_number' => $pageNumber,
                'content_text' => $text,
            ];
        }

        return ['pages' => $pages, 'errors' => $errors];
    }

    /**
     * @return array<int, array{page_number: int, content_text: string}>|null
     */
    public function extractPageRange(string $absolutePdfPath, int $fromPage, int $toPage): ?array
    {
        $result = $this->extractPageRangeWithDiagnostics($absolutePdfPath, $fromPage, $toPage);

        return $result['pages'] !== [] ? $result['pages'] : null;
    }

    /**
     * @return array<int, array{page_number: int, content_text: string}>
     */
    private function splitIntoPages(string $text): array
    {
        $chunks = preg_split('/\f+/u', $text) ?: [];
        $pages = [];

        foreach ($chunks as $index => $chunk) {
            $chunk = trim((string) $chunk);

            if ($chunk === '') {
                continue;
            }

            $pages[] = [
                'page_number' => $index + 1,
                'content_text' => $chunk,
            ];
        }

        return $pages;
    }

    /**
     * Fallback page count via smalot when poppler returns one blob.
     */
    public function countPages(string $absolutePdfPath): int
    {
        $parser = new Parser;
        $pdf = $parser->parseFile($absolutePdfPath);

        return max(1, count($pdf->getPages()));
    }
}
