<?php

declare(strict_types=1);

/**
 * Measure textbook status endpoint read performance (no HTTP).
 *
 * Usage:
 *   php scripts/benchmark-textbook-status.php [textbook_id]
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Textbook;
use App\Services\Curriculum\TextbookService;

$textbookId = $argv[1] ?? Textbook::query()->orderByDesc('created_at')->value('id');

if (! $textbookId) {
    fwrite(STDERR, "No textbook found\n");
    exit(1);
}

$service = app(TextbookService::class);

$statusSamples = [];
$timelineSamples = [];

for ($i = 0; $i < 5; $i++) {
    $started = microtime(true);
    $service->status((string) $textbookId);
    $statusSamples[] = (microtime(true) - $started) * 1000;

    $started = microtime(true);
    $service->processingTimeline((string) $textbookId);
    $timelineSamples[] = (microtime(true) - $started) * 1000;
}

$avg = static fn (array $samples): float => array_sum($samples) / max(1, count($samples));

fwrite(STDOUT, "Textbook: {$textbookId}\n");
fwrite(STDOUT, sprintf("/status avg: %.1f ms (samples: %s)\n", $avg($statusSamples), implode(', ', array_map(fn ($v) => round($v, 1), $statusSamples))));
fwrite(STDOUT, sprintf("/processing-status avg: %.1f ms (samples: %s)\n", $avg($timelineSamples), implode(', ', array_map(fn ($v) => round($v, 1), $timelineSamples))));

exit($avg($statusSamples) < 500 && $avg($timelineSamples) < 500 ? 0 : 1);
