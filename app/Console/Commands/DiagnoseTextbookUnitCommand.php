<?php

namespace App\Console\Commands;

use App\Services\Curriculum\TextbookUnitDiagnosticService;
use Illuminate\Console\Command;

class DiagnoseTextbookUnitCommand extends Command
{
    protected $signature = 'textbook:diagnose
        {unit : Unit key, curriculum_unit_generation_status id, or textbook_id:unit_key}
        {--textbook= : Textbook UUID when unit is a unit_key only}';

    protected $description = 'Print safe diagnostics for textbook unit AI question generation';

    public function handle(TextbookUnitDiagnosticService $diagnostics): int
    {
        try {
            $resolved = $diagnostics->resolveUnitReference(
                (string) $this->argument('unit'),
                $this->option('textbook') ? (string) $this->option('textbook') : null,
            );
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $report = $diagnostics->diagnose($resolved['textbook_id'], $resolved['unit_key']);

        $this->line('Textbook unit diagnostics');
        $this->line('========================');
        $this->line('textbook_id: '.$report['textbook_id']);
        $this->line('unit_key: '.$report['unit_key']);
        $this->line('unit_title: '.($report['unit_title'] ?? 'n/a'));
        $this->line('stored_pdf_exists: '.($report['stored_pdf_exists'] ? 'yes' : 'no'));
        $this->line('file_path: '.($report['absolute_pdf_path'] ?? $report['storage_path'] ?? 'n/a'));
        $this->line('unit_start_page: '.($report['unit_start_page'] ?? 'n/a'));
        $this->line('unit_end_page: '.($report['unit_end_page'] ?? 'n/a'));
        $this->line('extracted_content_exists: '.($report['extracted_content_exists'] ? 'yes' : 'no'));
        $this->line('extracted_content_char_count: '.$report['extracted_content_char_count']);
        $this->line('queue_connection: '.$report['queue_connection']);
        $this->line('jobs_table_count: '.$report['jobs_table_count']);
        $this->line('failed_jobs_count: '.$report['failed_jobs_count']);
        $this->line('ai_provider: '.$report['ai_provider']);
        $this->line('ai_configured: '.($report['ai_configured'] ? 'yes' : 'no'));
        $this->line('ai_generation_model: '.$report['ai_generation_model']);
        $this->line('ai_reachable: '.($report['ai_reachable'] ? 'yes' : 'no'));
        $this->line('ai_probe: '.$report['ai_probe_message']);
        $this->line('processing_status: '.$report['processing_status']);
        $this->line('structure_status: '.($report['structure_status'] ?? 'n/a'));
        $this->line('structure_approved: '.($report['structure_approved'] ? 'yes' : 'no'));
        $this->line('textbook_page_count: '.$report['textbook_page_count']);
        $this->line('build_chunks_status: '.($report['build_chunks_status'] ?? 'not run'));
        $this->line('build_chunks_error: '.($report['build_chunks_error'] ?? 'none'));
        $this->line('unit_generation_status: '.($report['unit_generation_status'] ?? 'n/a'));
        $this->line('unit_generation_error: '.($report['unit_generation_error'] ?? 'none'));
        $this->line('ai_generated_question_count: '.$report['ai_generated_question_count']);
        $this->line('promoted_question_count: '.$report['promoted_question_count']);

        $this->newLine();
        $this->line('Pipeline stages');
        $this->line('---------------');

        foreach ($report['stages'] as $stage) {
            $mark = $stage['ok'] ? '[OK]' : '[FAIL]';
            $this->line("{$mark} {$stage['stage']} — {$stage['detail']}");
        }

        if ($report['likely_failure_stage']) {
            $this->newLine();
            $this->warn('Likely failure: '.$report['likely_failure_stage']);
        } else {
            $this->newLine();
            $this->info('All checked stages passed.');
        }

        return $report['likely_failure_stage'] ? self::FAILURE : self::SUCCESS;
    }
}
