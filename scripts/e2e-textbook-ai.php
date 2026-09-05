<?php

/**
 * End-to-end textbook AI pipeline test (5 questions, one unit, Gemini).
 *
 * Usage:
 *   php scripts/e2e-textbook-ai.php [path/to/textbook.pdf]
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Textbook;
use App\Models\TextbookProcessingJob;
use App\Services\Curriculum\TextbookService;
use App\Services\Curriculum\UnitGenerationOrchestratorService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$defaultPdf = realpath(__DIR__.'/../../backend-node-legacy/scripts/fixtures/staging-arabic-textbook.pdf');
$pdfPath = realpath($argv[1] ?? $defaultPdf ?: '');

if ($pdfPath === false || ! is_readable($pdfPath)) {
    fwrite(STDERR, "PDF not found: ".($argv[1] ?? $defaultPdf)."\n");
    exit(1);
}

// Limit generation to 5 questions for this E2E run only.
config([
    'curriculum.target_questions_per_unit' => 5,
    'curriculum.questions_per_point_tier' => 1,
]);

$report = [
    'started_at' => now()->toIso8601String(),
    'pdf_path' => $pdfPath,
    'pdf_size_bytes' => filesize($pdfPath),
    'ai_provider' => config('ai.provider'),
    'ai_configured' => app(\App\Services\Ai\AiClient::class)->isConfigured(),
    'target_questions_per_unit' => config('curriculum.target_questions_per_unit'),
    'steps' => [],
    'errors' => [],
];

function step(array &$report, string $name, callable $callback): mixed
{
    $startedAt = microtime(true);

    try {
        $result = $callback();
        $report['steps'][$name] = [
            'status' => 'ok',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'result' => $result,
        ];

        echo "[OK] {$name} ({$report['steps'][$name]['duration_ms']}ms)\n";

        return $result;
    } catch (Throwable $exception) {
        $report['steps'][$name] = [
            'status' => 'failed',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error' => $exception->getMessage(),
        ];
        $report['errors'][] = "{$name}: {$exception->getMessage()}";
        echo "[FAIL] {$name}: {$exception->getMessage()}\n";
        throw $exception;
    }
}

function drainQueue(int $maxRuns = 30): int
{
    $processed = 0;

    for ($i = 0; $i < $maxRuns; $i++) {
        $pending = DB::table('jobs')->count();

        if ($pending === 0) {
            break;
        }

        Artisan::call('queue:work', [
            '--once' => true,
            '--timeout' => 3600,
            '--tries' => 1,
        ]);

        $processed++;
    }

    return $processed;
}

function waitForJobs(string $textbookId, array $expectedTypes, int $timeoutSeconds = 600): array
{
    $deadline = time() + $timeoutSeconds;
    $seen = [];

    while (time() < $deadline) {
        $jobs = TextbookProcessingJob::query()
            ->where('textbook_id', $textbookId)
            ->orderByDesc('created_at')
            ->get();

        foreach ($expectedTypes as $type) {
            $job = $jobs->firstWhere('job_type', $type);

            if ($job && ! isset($seen[$type])) {
                if ($job->status === 'completed') {
                    $seen[$type] = $job->toArray();
                } elseif ($job->status === 'failed') {
                    throw new RuntimeException("Job {$type} failed: ".($job->error_message ?? 'unknown'));
                }
            }
        }

        if (count($seen) === count($expectedTypes)) {
            return array_values($seen);
        }

        drainQueue();
        sleep(2);
    }

    throw new RuntimeException('Timed out waiting for jobs: '.implode(', ', $expectedTypes));
}

function uploadPdfToSignedUrl(string $signedUrl, string $pdfPath): void
{
    $bytes = file_get_contents($pdfPath);

    $ch = curl_init($signedUrl);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['x-upsert: true'],
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('PDF upload failed: HTTP '.$status.' '.$body);
    }
}

$admin = DB::table('user_profiles')
    ->where('role', 'admin')
    ->where('is_active', true)
    ->orderBy('email')
    ->first(['id', 'email']);

$subject = DB::table('subjects')
    ->where('is_deleted', false)
    ->orderBy('name')
    ->first(['id', 'name']);

if ($admin === null || $subject === null) {
    fwrite(STDERR, "Missing admin user or subject.\n");
    exit(1);
}

$actorUserId = (string) $admin->id;
$subjectId = (string) $subject->id;
$fileName = 'e2e-'.date('Ymd-His').'-'.basename($pdfPath);
$fileSize = filesize($pdfPath);

/** @var TextbookService $textbooks */
$textbooks = app(TextbookService::class);

echo "E2E textbook AI test\n";
echo "PDF: {$pdfPath} ({$fileSize} bytes)\n";
echo "AI provider: ".config('ai.provider').' configured='.(app(\App\Services\Ai\AiClient::class)->isConfigured() ? 'yes' : 'no')."\n\n";

try {
    $create = step($report, 'create_textbook_upload', function () use ($textbooks, $actorUserId, $subjectId, $fileName, $fileSize, $pdfPath) {
        return $textbooks->createUpload([
            'title' => 'E2E Arabic Textbook '.date('Y-m-d H:i'),
            'file_name' => $fileName,
            'content_type' => 'application/pdf',
            'file_size' => $fileSize,
            'academic_stage' => 'متوسط',
            'grade' => '7',
            'subject_id' => $subjectId,
            'academic_year' => '2025-2026',
            'semester' => '1',
            'language' => 'ar',
        ], $actorUserId);
    });

    $textbookId = (string) $create['textbook']['id'];

    step($report, 'upload_pdf_signed_url', function () use ($create, $pdfPath) {
        uploadPdfToSignedUrl(
            (string) $create['upload']['signed_url'],
            $pdfPath,
        );

        return ['bucket' => $create['upload']['bucket'], 'path' => $create['upload']['path']];
    });

    step($report, 'confirm_upload', function () use ($textbooks, $textbookId, $actorUserId) {
        return $textbooks->confirmUpload($textbookId, $actorUserId);
    });

    step($report, 'extract_text_and_detect_structure', function () use ($textbookId) {
        drainQueue(10);
        waitForJobs($textbookId, ['extract_text', 'detect_structure'], 300);

        $pages = DB::table('textbook_pages')->where('textbook_id', $textbookId)->count();

        return ['page_count' => $pages];
    });

    $analysis = step($report, 'verify_structure', function () use ($textbooks, $textbookId) {
        $data = $textbooks->analysis($textbookId);
        $structure = $data['proposed_structure'] ?? null;

        if (! is_array($structure) || ($structure['children'] ?? []) === []) {
            throw new RuntimeException('No proposed structure detected');
        }

        $units = [];
        foreach ($structure['children'] as $child) {
            if (($child['type'] ?? '') !== 'unit') {
                continue;
            }

            $lessons = [];
            foreach ($child['children'] ?? [] as $lesson) {
                if (($lesson['type'] ?? '') === 'lesson') {
                    $lessons[] = [
                        'key' => $lesson['key'] ?? null,
                        'title' => $lesson['title'] ?? null,
                    ];
                }
            }

            $units[] = [
                'key' => $child['key'] ?? null,
                'title' => $child['title'] ?? null,
                'lessons' => $lessons,
            ];
        }

        if ($units === []) {
            throw new RuntimeException('No Arabic units detected in structure');
        }

        return [
            'structure_status' => $data['structure_status'] ?? null,
            'processing_status' => $data['processing_status'] ?? null,
            'units' => $units,
        ];
    });

    step($report, 'approve_structure', function () use ($textbooks, $textbookId, $actorUserId) {
        return $textbooks->approveStructure($textbookId, $actorUserId);
    });

    step($report, 'build_chunks', function () use ($textbookId) {
        drainQueue(10);
        waitForJobs($textbookId, ['build_chunks'], 300);

        $chunks = DB::table('textbook_content_chunks')
            ->where('textbook_id', $textbookId)
            ->orderBy('source_page_start')
            ->get();

        if ($chunks->isEmpty()) {
            throw new RuntimeException('No content chunks created');
        }

        $sample = $chunks->first();
        $missing = [];

        foreach (['textbook_id', 'unit_key', 'content', 'source_page_start'] as $field) {
            if (empty($sample->{$field})) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException('Chunk missing fields: '.implode(', ', $missing));
        }

        return [
            'chunk_count' => $chunks->count(),
            'units' => $chunks->pluck('unit_key')->unique()->values()->all(),
            'sample' => [
                'unit_key' => $sample->unit_key,
                'lesson_key' => $sample->lesson_key,
                'source_page_start' => $sample->source_page_start,
                'source_page_end' => $sample->source_page_end,
                'content_preview' => mb_substr((string) $sample->content, 0, 120),
            ],
        ];
    });

    $selectedUnit = $analysis['units'][0];
    $unitKey = (string) $selectedUnit['key'];

    /** @var UnitGenerationOrchestratorService $unitGeneration */
    $unitGeneration = app(UnitGenerationOrchestratorService::class);

    step($report, 'generate_unit_questions', function () use ($unitGeneration, $textbookId, $unitKey, $actorUserId) {
        $result = $unitGeneration->requestUnitQuestionGeneration($textbookId, [
            'unit_key' => $unitKey,
            'auto_promote' => true,
        ], ['actorUserId' => $actorUserId, 'actorRole' => 'admin']);

        drainQueue(30);
        waitForJobs($textbookId, ['generate_unit_questions'], 900);

        return $result;
    });

    $generated = DB::table('ai_generated_questions')
        ->where('textbook_id', $textbookId)
        ->where('unit_key', $unitKey)
        ->orderBy('created_at')
        ->get();

    $promoted = DB::table('questions')
        ->where('textbook_id', $textbookId)
        ->where('question_source', 'textbook_ai')
        ->orderBy('created_at')
        ->get();

    $questionRows = [];

    foreach ($generated as $row) {
        $bank = $promoted->firstWhere('id', $row->question_id);
        $lessonTitle = null;

        foreach ($selectedUnit['lessons'] as $lesson) {
            if (($lesson['key'] ?? null) === $row->lesson_key) {
                $lessonTitle = $lesson['title'];
                break;
            }
        }

        $questionRows[] = [
            'question_text' => $row->question_text,
            'answer_text' => $row->answer_text,
            'points_value' => (int) $row->points_value,
            'source_unit' => $unitKey,
            'source_lesson' => $lessonTitle ?? $row->lesson_key,
            'source_pages' => [$row->source_page_start, $row->source_page_end],
            'validation_status' => $row->validation_status,
            'confidence_score' => $row->confidence_score,
            'validation_notes' => $row->validation_notes,
            'promoted' => $bank !== null,
            'question_id' => $row->question_id,
            'bank_fields' => $bank ? [
                'question_source' => $bank->question_source,
                'ai_generated' => (bool) $bank->ai_generated,
                'textbook_id' => $bank->textbook_id,
                'approval_status' => $bank->approval_status,
            ] : null,
        ];
    }

    $rejectedEstimate = max(0, (int) DB::table('curriculum_unit_generation_status')
        ->where('textbook_id', $textbookId)
        ->where('unit_key', $unitKey)
        ->value('generated_count') - $generated->count());

    $report['pdf_processing'] = [
        'textbook_id' => $textbookId,
        'page_count' => $report['steps']['extract_text_and_detect_structure']['result']['page_count'] ?? null,
        'processing_status' => Textbook::query()->find($textbookId)?->processing_status,
        'structure_status' => Textbook::query()->find($textbookId)?->structure_status,
    ];

    $report['detected_structure'] = $analysis;
    $report['chunk_summary'] = $report['steps']['build_chunks']['result'] ?? null;
    $report['generated_questions'] = $questionRows;
    $report['promoted_count'] = count(array_filter($questionRows, fn ($q) => $q['promoted']));
    $report['validated_count'] = $generated->whereIn('validation_status', ['validated', 'needs_review', 'approved'])->count();
    $report['rejected_or_skipped'] = [
        'note' => 'Rejected generations are skipped during orchestration and not persisted to ai_generated_questions.',
        'generation_attempts' => json_decode((string) DB::table('curriculum_unit_generation_status')
            ->where('textbook_id', $textbookId)
            ->where('unit_key', $unitKey)
            ->value('metadata'), true)['generation_attempts'] ?? null,
        'persisted_non_rejected' => $generated->count(),
    ];

    $report['finished_at'] = now()->toIso8601String();
    $report['overall'] = [
        'success' => $generated->count() > 0,
        'target_met' => $generated->count() <= 5,
        'categories_timeout_risk' => 'n/a',
        'quality_notes' => [],
    ];

    if ($generated->count() === 0) {
        $report['overall']['quality_notes'][] = 'No questions persisted — check Gemini API key, validation thresholds, or chunk content.';
    }

    $outPath = storage_path('logs/e2e-textbook-ai-'.date('Ymd-His').'.json');
    file_put_contents($outPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo "\n=== E2E REPORT ===\n";
    echo "Textbook ID: {$textbookId}\n";
    echo "Selected unit: {$unitKey} ({$selectedUnit['title']})\n";
    echo "Chunks: ".($report['chunk_summary']['chunk_count'] ?? 0)."\n";
    echo "Generated questions: ".$generated->count()."\n";
    echo "Promoted to bank: ".$report['promoted_count']."\n";
    echo "Full report: {$outPath}\n";

    echo "\nQuestions:\n";
    foreach ($questionRows as $index => $q) {
        echo ($index + 1).". [{$q['points_value']}pts] {$q['validation_status']} conf={$q['confidence_score']} promoted=".($q['promoted'] ? 'yes' : 'no')."\n";
        echo "   Q: {$q['question_text']}\n";
        echo "   A: {$q['answer_text']}\n";
        echo "   Unit: {$q['source_unit']} | Lesson: {$q['source_lesson']} | Pages: ".implode('-', $q['source_pages'])."\n";
    }

    exit($generated->count() > 0 ? 0 : 1);
} catch (Throwable $exception) {
    $report['fatal_error'] = $exception->getMessage();
    $outPath = storage_path('logs/e2e-textbook-ai-FAILED-'.date('Ymd-His').'.json');
    file_put_contents($outPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fwrite(STDERR, "\nFatal: {$exception->getMessage()}\nReport: {$outPath}\n");
    exit(1);
}
