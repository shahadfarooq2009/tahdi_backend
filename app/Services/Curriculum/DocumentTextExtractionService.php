<?php

namespace App\Services\Curriculum;

use App\Exceptions\ValidationException;
use App\Support\Utf8Text;
use Illuminate\Http\UploadedFile;
use ZipArchive;

class DocumentTextExtractionService
{
    public function __construct(
        private readonly TextExtractionService $pdf,
    ) {}

    /**
     * @return array{page_count: int, unit_label: string, supports_page_range: bool, range_note?: string}
     */
    public function inspectUploadedFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            throw new ValidationException('تعذر قراءة الملف المرفوع');
        }

        return match ($extension) {
            'pdf' => [
                'page_count' => $this->inspectPdfPageCount($path),
                'unit_label' => 'صفحة',
                'supports_page_range' => true,
            ],
            'pptx' => [
                'page_count' => $this->countPptxSlides($path),
                'unit_label' => 'شريحة',
                'supports_page_range' => true,
            ],
            'docx' => [
                'page_count' => 1,
                'unit_label' => 'صفحة',
                'supports_page_range' => false,
                'range_note' => 'ملفات Word تُستخدم بالكامل عند التوليد',
            ],
            'doc' => throw new ValidationException('صيغة .doc القديمة غير مدعومة. احفظ الملف بصيغة Word (.docx)'),
            'ppt' => throw new ValidationException('صيغة .ppt القديمة غير مدعومة. احفظ الملف بصيغة PowerPoint (.pptx)'),
            default => throw new ValidationException('نوع الملف غير مدعوم. استخدم PDF أو Word أو PowerPoint'),
        };
    }

    public function extractFromUploadedFile(UploadedFile $file, ?int $pageFrom = null, ?int $pageTo = null): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            throw new ValidationException('تعذر قراءة الملف المرفوع');
        }

        if (($pageFrom !== null || $pageTo !== null) && ! in_array($extension, ['pdf', 'pptx'], true)) {
            throw new ValidationException('تحديد نطاق الصفحات متاح فقط لملفات PDF و PowerPoint');
        }

        $content = match ($extension) {
            'pdf' => $this->extractFromPdf($path, $pageFrom, $pageTo),
            'docx' => $this->extractFromDocx($path),
            'pptx' => $this->extractFromPptx($path, $pageFrom, $pageTo),
            'doc' => throw new ValidationException('صيغة .doc القديمة غير مدعومة. احفظ الملف بصيغة Word (.docx)'),
            'ppt' => throw new ValidationException('صيغة .ppt القديمة غير مدعومة. احفظ الملف بصيغة PowerPoint (.pptx)'),
            default => throw new ValidationException('نوع الملف غير مدعوم. استخدم PDF أو Word أو PowerPoint'),
        };

        if (trim($content) === '') {
            throw new ValidationException('النطاق المحدد لا يحتوي على نص قابل للاستخراج');
        }

        return Utf8Text::sanitize($content);
    }

    /**
     * @return array<int, array{page_number: int, content_text: string}>
     */
    private function getPdfPages(string $path): array
    {
        $buffer = file_get_contents($path);

        if ($buffer === false || $buffer === '') {
            throw new ValidationException('تعذر قراءة ملف PDF');
        }

        $this->pdf->assertPdfBuffer($buffer);

        return array_map(
            fn (array $page) => [
                'page_number' => (int) $page['page_number'],
                'content_text' => trim((string) ($page['content_text'] ?? '')),
            ],
            $this->pdf->extractPdfPages($buffer)
        );
    }

    /**
     * @return array<int, array{page_number: int, content_text: string}>
     */
    private function getPptxSlides(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new ValidationException('تعذر فتح ملف PowerPoint');
        }

        $slideFiles = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (! is_string($name) || ! preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $matches)) {
                continue;
            }

            $slideFiles[(int) $matches[1]] = $name;
        }

        ksort($slideFiles, SORT_NUMERIC);

        $slides = [];
        $slideNumber = 1;

        foreach ($slideFiles as $name) {
            $xml = $zip->getFromName($name);

            if ($xml === false) {
                continue;
            }

            $slideText = $this->extractPptxSlideText($xml);
            $slides[] = [
                'page_number' => $slideNumber,
                'content_text' => $slideText,
            ];
            $slideNumber++;
        }

        $zip->close();

        if ($slides === []) {
            throw new ValidationException('ملف PowerPoint لا يحتوي على شرائح نصية');
        }

        return $slides;
    }

    private function countPptxSlides(string $path): int
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new ValidationException('تعذر فتح ملف PowerPoint');
        }

        $slideNumbers = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (is_string($name) && preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $matches)) {
                $slideNumbers[(int) $matches[1]] = true;
            }
        }

        $zip->close();

        $count = count($slideNumbers);

        if ($count === 0) {
            throw new ValidationException('ملف PowerPoint لا يحتوي على شرائح');
        }

        return $count;
    }

    /**
     * @param  array<int, array{page_number: int, content_text: string}>  $pages
     */
    private function slicePages(array $pages, ?int $pageFrom, ?int $pageTo, string $unitLabel): string
    {
        $total = count($pages);

        if ($total === 0) {
            throw new ValidationException('الملف لا يحتوي على صفحات قابلة للقراءة');
        }

        $from = max(1, min($pageFrom ?? 1, $total));
        $to = max($from, min($pageTo ?? $total, $total));

        if ($from < 1 || $to < 1 || $from > $to) {
            throw new ValidationException("نطاق {$unitLabel} غير صالح. الملف يحتوي على {$total} {$unitLabel}");
        }

        $selected = array_values(array_filter(
            $pages,
            fn (array $page) => $page['page_number'] >= $from && $page['page_number'] <= $to
        ));

        $content = trim(implode("\n\n", array_map(
            fn (array $page) => trim($page['content_text']),
            $selected
        )));

        if ($content === '') {
            throw new ValidationException("{$unitLabel} المحددة لا تحتوي على نص قابل للاستخراج");
        }

        return $content;
    }

    private function extractFromPdf(string $path, ?int $pageFrom = null, ?int $pageTo = null): string
    {
        return $this->slicePages($this->getPdfPages($path), $pageFrom, $pageTo, 'صفحة');
    }

    private function extractFromDocx(string $path): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new ValidationException('تعذر فتح ملف Word');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new ValidationException('ملف Word غير صالح أو فارغ');
        }

        return $this->normalizeOfficeXmlText($xml, ['</w:p>', '</w:tr>']);
    }

    private function extractFromPptx(string $path, ?int $pageFrom = null, ?int $pageTo = null): string
    {
        return $this->slicePages($this->getPptxSlides($path), $pageFrom, $pageTo, 'شريحة');
    }

    /**
     * @param  array<int, string>  $lineBreakMarkers
     */
    private function normalizeOfficeXmlText(string $xml, array $lineBreakMarkers): string
    {
        $withBreaks = str_replace($lineBreakMarkers, "\n", $xml);
        $text = strip_tags($withBreaks);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function extractPptxSlideText(string $xml): string
    {
        preg_match_all('/<a:t[^>]*>(.*?)<\/a:t>/s', $xml, $matches);

        $parts = array_map(
            fn (string $part) => trim(html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_XML1, 'UTF-8')),
            $matches[1] ?? []
        );

        return trim(implode(' ', array_filter($parts, fn (string $part) => $part !== '')));
    }

    private function inspectPdfPageCount(string $path): int
    {
        try {
            $pages = $this->getPdfPages($path);
            $count = count($pages);

            if ($count > 0) {
                return $count;
            }
        } catch (\Throwable) {
            // Fall back to parser page count when extraction is unavailable.
        }

        return $this->pdf->countPdfPages($path);
    }
}
