<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Textbook;
use App\Models\TextbookProcessingJob;
use App\Services\Ai\AiClient;
use App\Services\Curriculum\TextbookAiService;
use App\Services\Curriculum\TextbookJobService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$requestedCount = 5;
$textbookId = $argv[1] ?? null;

$textbook = $textbookId
    ? Textbook::query()->find($textbookId)
    : Textbook::query()
        ->where('structure_status', 'approved')
        ->orderByDesc('updated_at')
        ->first();

if ($textbook === null) {
    fwrite(STDERR, "No approved textbook found.\n");
    exit(1);
}

$admin = DB::table('user_profiles')
    ->where('role', 'admin')
    ->where('is_active', true)
    ->orderBy('email')
    ->first(['id']);

if ($admin === null) {
    fwrite(STDERR, "No active admin user found.\n");
    exit(1);
}

$structure = is_array($textbook->approved_structure) ? $textbook->approved_structure : [];
$firstUnit = collect($structure['children'] ?? [])->first(fn ($node) => ($node['type'] ?? null) === 'unit');

if ($firstUnit === null) {
    fwrite(STDERR, "No unit found in approved structure.\n");
    exit(1);
}

$unitKey = (string) ($firstUnit['key'] ?? '');
$unitTitle = (string) ($firstUnit['title'] ?? $unitKey);

echo '=== GEMINI PROVIDER CHECK ==='.PHP_EOL;
$ai = app(AiClient::class);
echo 'provider='.$ai->provider().PHP_EOL;
echo 'configured='.($ai->isConfigured() ? 'yes' : 'no').PHP_EOL;
echo 'generation_model='.$ai->generationModel().PHP_EOL;
echo 'validation_model='.$ai->validationModel().PHP_EOL;
echo 'legacy_model='.$ai->legacyModel().PHP_EOL;

if ($ai->generationModel() !== 'gemini-3.5-flash-lite') {
    fwrite(STDERR, "GeminiProvider did not resolve gemini-3.5-flash-lite.\n");
    exit(1);
}

echo PHP_EOL.'=== ARABIC JSON SMOKE TEST (GeminiProvider) ==='.PHP_EOL;
$smokeResponse = $ai->chat([
    ['role' => 'user', 'content' => 'أعد JSON فقط بالشكل {"answer":"مرحبا"}'],
], [
    'json' => true,
    'temperature' => 0.1,
    'max_tokens' => 32,
]);
$decoded = json_decode($smokeResponse, true);
echo 'smoke_success='.(is_array($decoded) ? 'yes' : 'no').PHP_EOL;
echo 'smoke_response='.$smokeResponse.PHP_EOL;

if (! is_array($decoded)) {
    fwrite(STDERR, "GeminiProvider JSON smoke test failed.\n");
    exit(1);
}

echo PHP_EOL.'=== 5-QUESTION GENERATION TEST ==='.PHP_EOL;
echo 'textbook_id='.$textbook->id.PHP_EOL;
echo 'textbook_title='.$textbook->title.PHP_EOL;
echo 'processing_status='.$textbook->processing_status.PHP_EOL;
echo 'structure_status='.$textbook->structure_status.PHP_EOL;
echo 'unit_key='.$unitKey.PHP_EOL;
echo 'unit_title='.$unitTitle.PHP_EOL;

$beforeGeneratedCount = DB::table('ai_generated_questions')
    ->where('textbook_id', $textbook->id)
    ->count();

$questionsHasTextbookFields = Illuminate\Support\Facades\Schema::hasColumn('questions', 'textbook_id')
    && Illuminate\Support\Facades\Schema::hasColumn('questions', 'question_source')
    && Illuminate\Support\Facades\Schema::hasColumn('questions', 'ai_generated');

$beforeBankQuestionIds = DB::table('ai_generated_questions')
    ->where('textbook_id', $textbook->id)
    ->whereNotNull('question_id')
    ->pluck('question_id')
    ->all();

/** @var TextbookAiService $textbookAi */
$textbookAi = app(TextbookAiService::class);
/** @var TextbookJobService $jobs */
$jobs = app(TextbookJobService::class);

$batchId = (string) Str::uuid();
DB::table('ai_question_generation_batches')->insert([
    'id' => $batchId,
    'textbook_id' => $textbook->id,
    'unit_key' => $unitKey,
    'lesson_key' => null,
    'difficulty_level' => 3,
    'points_value' => 100,
    'question_type' => 'single_answer',
    'requested_count' => $requestedCount,
    'status' => 'queued',
    'created_by' => $admin->id,
    'created_at' => now(),
    'updated_at' => now(),
]);

$job = $jobs->enqueue($textbook->id, 'generate_questions', [
    'batch_id' => $batchId,
    'unit_key' => $unitKey,
    'lesson_key' => null,
    'difficulty_level' => 3,
    'points_value' => 100,
    'question_type' => 'single_answer',
    'count' => $requestedCount,
    'subject_id' => $textbook->subject_id,
    'grade' => $textbook->grade,
    'educational_stage' => $textbook->academic_stage,
], $admin->id);

$startedAt = microtime(true);
$textbookAi->runGenerateQuestionsJob($job);
$jobs->markCompleted($job->id);
$durationMs = (int) round((microtime(true) - $startedAt) * 1000);

$generated = DB::table('ai_generated_questions')
    ->where('textbook_id', $textbook->id)
    ->where('batch_id', $batchId)
    ->orderBy('created_at')
    ->get();

$validatedCount = $generated->whereIn('validation_status', ['validated', 'needs_review', 'approved'])->count();
$rejectedCount = $generated->where('validation_status', 'rejected')->count();
$promotedCount = 0;

$lessonTitles = [];
foreach ($firstUnit['children'] ?? [] as $lesson) {
    if (($lesson['type'] ?? null) === 'lesson') {
        $lessonTitles[(string) $lesson['key']] = (string) ($lesson['title'] ?? $lesson['key']);
    }
}

$questionRows = [];
foreach ($generated as $row) {
    $promoted = false;
    $bank = null;
    $promotionError = null;

    if ($questionsHasTextbookFields && in_array($row->validation_status, ['validated', 'needs_review'], true)) {
        try {
            $reviewed = $textbookAi->reviewGeneratedQuestion(
                (string) $row->id,
                'approved',
                ['actorUserId' => $admin->id, 'actorRole' => 'admin'],
                null,
                true,
            );
            $promoted = filled($reviewed->question_id ?? null);
            if ($promoted) {
                $promotedCount++;
                $bank = DB::table('questions')->where('id', $reviewed->question_id)->first();
            }
        } catch (Throwable $exception) {
            $promotionError = $exception->getMessage();
        }
    } elseif ($row->validation_status === 'approved' && filled($row->question_id)) {
        $promoted = true;
        $promotedCount++;
        $bank = DB::table('questions')->where('id', $row->question_id)->first();
    }

    $questionRows[] = [
        'question_text' => $row->question_text,
        'correct_answer' => $row->answer_text,
        'point_tier' => (int) $row->points_value,
        'lesson' => $lessonTitles[(string) $row->lesson_key] ?? $row->lesson_key,
        'source_pages' => [(int) $row->source_page_start, (int) $row->source_page_end],
        'confidence' => $row->confidence_score,
        'validation_result' => $row->validation_status,
        'validation_notes' => $row->validation_notes,
        'promoted' => $promoted,
        'promotion_error' => $promotionError,
        'question_source' => $bank->question_source ?? ($row->validation_status !== 'rejected' ? 'textbook_ai (ai_generated_questions only)' : null),
        'ai_generated' => isset($bank->ai_generated) ? (bool) $bank->ai_generated : true,
        'textbook_id' => $bank->textbook_id ?? $row->textbook_id,
    ];
}

$textbook->refresh();

echo PHP_EOL.'=== SUMMARY ==='.PHP_EOL;
echo 'requested='.$requestedCount.PHP_EOL;
echo 'generated='.$generated->count().PHP_EOL;
echo 'validated='.$validatedCount.PHP_EOL;
echo 'rejected='.$rejectedCount.PHP_EOL;
echo 'promoted='.$promotedCount.PHP_EOL;
echo 'generation_duration_ms='.$durationMs.PHP_EOL;
echo 'textbook_processing_status='.$textbook->processing_status.PHP_EOL;
echo 'questions_bank_schema_ready='.($questionsHasTextbookFields ? 'yes' : 'no').PHP_EOL;
echo 'new_ai_generated_questions='.$generated->count().PHP_EOL;
echo 'total_ai_generated_questions='.DB::table('ai_generated_questions')->where('textbook_id', $textbook->id)->count().PHP_EOL;
echo 'new_promoted_bank_questions='.count(array_diff(
    DB::table('ai_generated_questions')->where('textbook_id', $textbook->id)->whereNotNull('question_id')->pluck('question_id')->all(),
    $beforeBankQuestionIds
)).PHP_EOL;
echo 'manual_fallback_used=no'.PHP_EOL;

echo PHP_EOL.'=== QUESTIONS ==='.PHP_EOL;
foreach ($questionRows as $index => $row) {
    echo ($index + 1).'.'.PHP_EOL;
    foreach ($row as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        echo '   '.$key.'='.$value.PHP_EOL;
    }
}

$reportPath = storage_path('logs/gemini-5q-test-'.date('Ymd-His').'.json');
file_put_contents($reportPath, json_encode([
    'provider' => $ai->provider(),
    'generation_model' => $ai->generationModel(),
    'textbook_id' => $textbook->id,
    'unit_key' => $unitKey,
    'requested' => $requestedCount,
    'generated' => $generated->count(),
    'validated' => $validatedCount,
    'rejected' => $rejectedCount,
    'promoted' => $promotedCount,
    'textbook_processing_status' => $textbook->processing_status,
    'questions' => $questionRows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo PHP_EOL.'report='.$reportPath.PHP_EOL;

exit($generated->count() > 0 ? 0 : 1);
