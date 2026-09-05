<?php

namespace App\Console\Commands;

use App\Services\Curriculum\TextbookChunkedUploadService;
use Illuminate\Console\Command;

class CleanupTextbookUploadSessions extends Command
{
    protected $signature = 'textbook-uploads:cleanup';

    protected $description = 'Remove expired incomplete textbook chunked upload sessions';

    public function handle(TextbookChunkedUploadService $chunkedUploads): int
    {
        $removed = $chunkedUploads->cleanupExpiredSessions();
        $this->info("Cleaned up {$removed} expired upload session(s).");

        return self::SUCCESS;
    }
}
