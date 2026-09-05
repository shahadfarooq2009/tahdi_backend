<?php

namespace App\Services\Curriculum;

use App\Exceptions\ValidationException;
use App\Support\Utf8Text;
use Smalot\PdfParser\Parser;

class TextExtractionService
{
    private const PDF_MAGIC = '%PDF';

    /** Minimum total extracted characters before treating a PDF as usable text. */
    private const MIN_TOTAL_TEXT_CHARS = 500;

    public function __construct(
        private readonly LayeredArabicPdfExtractionService $layeredExtractor,
    ) {}

    public function assertPdfBuffer(string $buffer): void
    {
        if (strlen($buffer) < 4 || substr($buffer, 0, 4) !== self::PDF_MAGIC) {
            throw new ValidationException('File is not a valid PDF');
        }
    }

    /**
     * @return array<int, array{page_number: int, content_text: string, normalized_text: string}>
     */
    public function extractPdfPages(string $buffer): array
    {
        $this->assertPdfBuffer($buffer);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'tahdi-pdf-');

        if ($temporaryPath === false) {
            throw new ValidationException('تعذر تجهيز ملف PDF مؤقت');
        }

        file_put_contents($temporaryPath, $buffer);

        try {
            return $this->extractPdfPagesFromPath($temporaryPath);
        } finally {
            @unlink($temporaryPath);
        }
    }

    /**
     * Extract pages from a PDF on disk (avoids keeping a duplicate 60MB+ buffer in PHP).
     *
     * @param  callable(int $pageNumber, int $totalPages): void|null  $onPageExtracted
     * @param  callable(int $ocrDone, int $ocrTotal): void|null  $onOcrProgress
     * @return array{
     *   pages: array<int, array<string, mixed>>,
     *   diagnostics: array<string, mixed>
     * }
     */
    public function extractPdfPagesFromPathWithDiagnostics(
        string $absolutePath,
        ?callable $onPageExtracted = null,
        ?callable $onOcrProgress = null,
    ): array {
        if (! is_file($absolutePath)) {
            throw new ValidationException('تعذر قراءة ملف PDF من التخزين');
        }

        $header = file_get_contents($absolutePath, false, null, 0, 2048);

        if ($header === false || ! str_starts_with($header, self::PDF_MAGIC)) {
            throw new ValidationException('File is not a valid PDF');
        }

        $result = $this->layeredExtractor->extract($absolutePath, $onPageExtracted, $onOcrProgress);
        $this->assertExtractedTextIsUsable($result['pages']);

        return $result;
    }

    /**
     * @param  callable(int $pageNumber, int $totalPages): void|null  $onPageExtracted
     * @return array<int, array{
     *   page_number: int,
     *   content_text: string,
     *   normalized_text: string,
     *   printed_page_number?: int|null,
     *   extraction_source?: string|null,
     *   extraction_quality?: array<string, mixed>|null
     * }>
     */
    public function extractPdfPagesFromPath(string $absolutePath, ?callable $onPageExtracted = null): array
    {
        return $this->extractPdfPagesFromPathWithDiagnostics($absolutePath, $onPageExtracted)['pages'];
    }

    /**
     * @param  array<int, array{page_number: int, content_text: string, normalized_text: string}>  $pages
     */
    public function assertExtractedTextIsUsable(array $pages): void
    {
        $totalChars = 0;
        $pagesWithText = 0;

        foreach ($pages as $page) {
            $length = mb_strlen(trim((string) ($page['content_text'] ?? '')));

            if ($length > 0) {
                $pagesWithText++;
                $totalChars += $length;
            }
        }

        if ($pagesWithText === 0 || $totalChars < self::MIN_TOTAL_TEXT_CHARS) {
            throw new ValidationException('تعذر استخراج نص كافٍ من الكتاب. قد يكون الملف ممسوحاً ضوئياً (صور فقط).');
        }
    }

    /**
     * @param  iterable<mixed>  $pdfPages
     * @return array<int, array{page_number: int, content_text: string, normalized_text: string}>
     */
    private function collectPages(iterable $pdfPages): array
    {
        /** @var array<int, array{page_number: int, content_text: string, normalized_text: string}> $pages */
        $pages = [];

        foreach ($pdfPages as $index => $page) {
            $text = trim($page->getText());
            $pageNumber = $index + 1;

            $pages[] = [
                'page_number' => $pageNumber,
                'content_text' => Utf8Text::sanitize($text),
                'normalized_text' => ArabicTextService::normalizeArabicText($text),
            ];
        }

        if ($pages === []) {
            return $pages;
        }

        return $pages;
    }

    public function countPdfPages(string $path): int
    {
        if (! is_file($path)) {
            throw new ValidationException('تعذر قراءة ملف PDF');
        }

        $parser = new Parser;
        $pdf = $parser->parseFile($path);
        $count = count($pdf->getPages());

        return max(1, $count);
    }

    /**
     * @param  array<int, array{page_number: int, content_text: string, normalized_text?: string}>  $pages
     * @return array<int, array{page_number: int, title: string, score: int}>
     */
    public function detectHeadingCandidates(array $pages): array
    {
        /** @var array<int, array{page_number: int, title: string, score: int}> $headings */
        $headings = [];

        foreach ($pages as $page) {
            $lines = array_values(array_filter(
                array_map('trim', preg_split('/\R+/', $page['content_text'] ?? '') ?: []),
                fn ($line) => $line !== ''
            ));

            foreach ($lines as $line) {
                $normalized = ArabicTextService::normalizeArabicText($line);

                if (mb_strlen($normalized) < 4 || mb_strlen($normalized) > 120) {
                    continue;
                }

                $score = 0;

                if (preg_match('/^(الوحدة|الوحده|الفصل|الدرس|باب|مقدمة)\s*(?:الأولى|الثانية|الثالثة|الرابعة|الخامسة|السادسة|السابعة|الثامنة|التاسعة|العاشرة|الأول|الثاني|الثالث|الرابع|الخامس|السادس|السابع|الثامن|التاسع|العاشر|\d+)/u', $normalized)) {
                    $score += 4;
                } elseif (preg_match('/^(الوحدة|الفصل|الدرس|الوحده|باب|مقدمة)/u', $normalized)) {
                    $score += 3;
                }

                if (preg_match('/^\d+[\.\-:]/', $normalized)) {
                    $score += 1;
                }

                if ($line === mb_strtoupper($line) && preg_match('/[A-Z]/', $line)) {
                    $score += 1;
                }

                if ($score > 0) {
                    $headings[] = [
                        'page_number' => (int) $page['page_number'],
                        'title' => $line,
                        'score' => $score,
                    ];
                }
            }
        }

        usort($headings, function (array $a, array $b): int {
            if ($a['page_number'] !== $b['page_number']) {
                return $a['page_number'] <=> $b['page_number'];
            }

            return $b['score'] <=> $a['score'];
        });

        return $headings;
    }
}
