<?php

namespace App\Console\Commands;

use App\Services\Curriculum\TextbookOcrDiagnosticService;
use Illuminate\Console\Command;

class DiagnoseTextbookOcrCommand extends Command
{
    protected $signature = 'textbook:ocr-diagnose';

    protected $description = 'Print safe diagnostics for textbook OCR / Poppler tooling (no secrets)';

    public function handle(TextbookOcrDiagnosticService $diagnostics): int
    {
        $report = $diagnostics->diagnose();

        $this->line('Textbook OCR diagnostics');
        $this->line('=========================');
        $this->line('OCR enabled: '.($report['ocr_enabled'] ? 'yes' : 'no'));
        $this->line('Tesseract found: '.($report['tesseract_found'] ? 'yes' : 'no'));
        $this->line('Arabic trained data found: '.($report['arabic_trained_data_found'] ? 'yes' : 'no'));
        $this->line('pdftoppm found: '.($report['pdftoppm_found'] ? 'yes' : 'no'));
        $this->line('pdftotext found: '.($report['pdftotext_found'] ? 'yes' : 'no'));
        $this->line('OCR runtime ready: '.($report['ocr_runtime_ready'] ? 'yes' : 'no'));
        $this->line('OCR service available: '.($report['ocr_service_available'] ? 'yes' : 'no'));
        $this->line('Poppler text available: '.($report['poppler_text_available'] ? 'yes' : 'no'));

        if (is_string($report['tesseract_path'] ?? null) && $report['tesseract_path'] !== '') {
            $this->line('Tesseract path: '.$report['tesseract_path']);
        }

        if (is_string($report['pdftoppm_path'] ?? null) && $report['pdftoppm_path'] !== '') {
            $this->line('pdftoppm path: '.$report['pdftoppm_path']);
        }

        if (is_string($report['pdftotext_path'] ?? null) && $report['pdftotext_path'] !== '') {
            $this->line('pdftotext path: '.$report['pdftotext_path']);
        }

        $policy = $report['ocr_policy'] ?? [];

        if (is_array($policy) && $policy !== []) {
            $this->newLine();
            $this->line('OCR policy');
            $this->line('----------');
            $this->line('Scope: '.($policy['scope'] ?? 'n/a'));
            $this->line('Front matter pages: '.($policy['front_matter_pages'] ?? 'n/a'));
            $this->line('Max OCR pages per book: '.($policy['max_pages'] ?? 'n/a'));
            $this->line('Trigger: '.($policy['trigger'] ?? 'n/a'));
            $this->line('Quality threshold: '.($policy['front_matter_quality_threshold'] ?? 'n/a'));
            $this->line('Full-book OCR by default: '
                .(($policy['never_ocr_full_book_by_default'] ?? false) ? 'no' : 'yes'));
        }

        if (! $report['ocr_runtime_ready']) {
            $this->newLine();
            $this->warn('OCR is not fully ready. Run backend/scripts/setup-windows-textbook-ocr.ps1 for setup help.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
