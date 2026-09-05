<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo json_encode([
    'queue_connection' => config('queue.default'),
    'jobs_total' => DB::table('jobs')->count(),
    'jobs_available' => DB::table('jobs')->whereNull('reserved_at')->count(),
    'jobs_reserved' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
    'failed_jobs' => DB::table('failed_jobs')->count(),
    'latest_jobs' => DB::table('jobs')->orderByDesc('id')->limit(3)->get(['id', 'queue', 'attempts', 'reserved_at', 'available_at']),
    'textbook_jobs_queued' => DB::table('textbook_processing_jobs')->where('status', 'queued')->count(),
    'textbook_jobs_processing' => DB::table('textbook_processing_jobs')->where('status', 'processing')->count(),
], JSON_PRETTY_PRINT).PHP_EOL;
