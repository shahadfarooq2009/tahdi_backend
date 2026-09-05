<?php

namespace App\Services\Curriculum;

use Illuminate\Support\Facades\Process;

class TextbookOcrDiagnosticService
{
    public function __construct(
        private readonly PdfExternalTools $tools,
        private readonly PdfPageOcrService $ocr,
    ) {}

    /**
     * Safe diagnostic snapshot (no secrets).
     *
     * @return array<string, mixed>
     */
    public function diagnose(): array
    {
        $resolved = $this->tools->resolve();
        $tesseractPath = $resolved['tesseract'];
        $arabicFound = $tesseractPath !== null && $this->tools->tesseractListsArabic($tesseractPath);

        $ocrEnabled = (bool) config('textbook_extraction.ocr_enabled', true);
        $ocrRuntimeReady = $ocrEnabled
            && $tesseractPath !== null
            && $resolved['pdftoppm'] !== null
            && $arabicFound;

        return [
            'ocr_enabled' => $ocrEnabled,
            'tesseract_found' => $tesseractPath !== null,
            'tesseract_path' => $tesseractPath,
            'arabic_trained_data_found' => $arabicFound,
            'pdftoppm_found' => $resolved['pdftoppm'] !== null,
            'pdftoppm_path' => $resolved['pdftoppm'],
            'pdftotext_found' => $resolved['pdftotext'] !== null,
            'pdftotext_path' => $resolved['pdftotext'],
            'ocr_runtime_ready' => $ocrRuntimeReady,
            'ocr_service_available' => $this->ocr->isAvailable(),
            'poppler_text_available' => $this->tools->popplerTextAvailable(),
            'ocr_policy' => [
                'scope' => 'front_matter_only',
                'max_pages' => (int) config('textbook_extraction.max_ocr_pages', 25),
                'front_matter_pages' => (int) config('textbook_extraction.front_matter_pages', 30),
                'trigger' => 'average_front_matter_quality_below_threshold',
                'front_matter_quality_threshold' => (float) config(
                    'textbook_extraction.front_matter_quality_threshold',
                    0.45
                ),
                'never_ocr_full_book_by_default' => true,
            ],
        ];
    }
}
