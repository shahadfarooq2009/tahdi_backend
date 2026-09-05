<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Textbook;
use App\Services\Curriculum\TextbookFileStorageService;
use Illuminate\Support\Facades\DB;

$files = app(TextbookFileStorageService::class);

$textbooks = DB::table('textbooks')
    ->orderByDesc('created_at')
    ->limit(5)
    ->get([
        'id',
        'title',
        'processing_status',
        'last_error',
        'storage_path',
        'file_size_bytes',
        'created_at',
        'updated_at',
    ]);

$report = [
    'queue_connection' => config('queue.default'),
    'app_env' => config('app.env'),
    'jobs_total' => DB::table('jobs')->count(),
    'jobs_pending' => DB::table('jobs')->whereNull('reserved_at')->count(),
    'jobs_reserved' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
    'failed_jobs' => DB::table('failed_jobs')->count(),
    'latest_failed_jobs' => DB::table('failed_jobs')->orderByDesc('id')->limit(3)->get(['id', 'uuid', 'queue', 'failed_at']),
    'latest_laravel_jobs' => DB::table('jobs')->orderByDesc('id')->limit(5)->get(['id', 'queue', 'attempts', 'reserved_at', 'available_at', 'created_at']),
    'textbook_processing_jobs' => DB::table('textbook_processing_jobs')
        ->orderByDesc('created_at')
        ->limit(10)
        ->get(['id', 'textbook_id', 'job_type', 'status', 'started_at', 'completed_at', 'error_message', 'created_at']),
    'textbooks' => [],
];

foreach ($textbooks as $row) {
    $model = Textbook::query()->find($row->id);
    $absolutePath = $model ? $files->absolutePath($model) : null;
    $exists = $absolutePath && is_file($absolutePath);

    $report['textbooks'][] = [
        'id' => $row->id,
        'title' => $row->title,
        'processing_status' => $row->processing_status,
        'last_error' => $row->last_error,
        'storage_path' => $row->storage_path,
        'file_exists' => $exists,
        'absolute_path' => $absolutePath,
        'file_size_bytes' => $row->file_size_bytes,
        'pipeline_jobs' => DB::table('textbook_processing_jobs')
            ->where('textbook_id', $row->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'job_type', 'status', 'started_at', 'error_message']),
    ];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
