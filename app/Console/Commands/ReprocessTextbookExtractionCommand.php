<?php

namespace App\Console\Commands;

use App\Models\Textbook;
use App\Models\User;
use App\Services\Curriculum\TextbookService;
use Illuminate\Console\Command;

class ReprocessTextbookExtractionCommand extends Command
{
    protected $signature = 'textbook:reprocess-extraction
        {textbook : Textbook UUID}
        {--user= : Actor user UUID for the processing job (defaults to first admin)}';

    protected $description = 'Re-extract text from the stored backend PDF and re-run structure detection';

    public function handle(TextbookService $textbooks): int
    {
        $textbookId = (string) $this->argument('textbook');

        if (Textbook::query()->find($textbookId) === null) {
            $this->error("Textbook not found: {$textbookId}");

            return self::FAILURE;
        }

        $actorUserId = $this->resolveActorUserId();

        if ($actorUserId === null) {
            $this->error('No actor user available. Pass --user=<uuid> for an admin account.');

            return self::FAILURE;
        }

        try {
            $status = $textbooks->reprocessExtractionFromStoredPdf($textbookId, $actorUserId);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Reprocess queued from stored PDF.');
        $this->line('textbook_id: '.$textbookId);
        $this->line('processing_status: '.($status['textbook']['processing_status'] ?? 'unknown'));

        $jobs = $status['jobs'] ?? [];

        if (is_array($jobs) && $jobs !== []) {
            $latest = $jobs[0];
            $this->line('latest_job_type: '.($latest['job_type'] ?? 'n/a'));
            $this->line('latest_job_status: '.($latest['status'] ?? 'n/a'));
        }

        $this->newLine();
        $this->line('Ensure a queue worker is running, then monitor with:');
        $this->line("  php scripts/list-textbooks.php");
        $this->line("  php scripts/benchmark-textbook-extraction.php {$textbookId}");

        return self::SUCCESS;
    }

    private function resolveActorUserId(): ?string
    {
        $explicit = $this->option('user');

        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        $admin = User::query()
            ->where('role', 'admin')
            ->where('is_deleted', false)
            ->orderBy('created_at')
            ->value('id');

        return is_string($admin) && $admin !== '' ? $admin : null;
    }
}
