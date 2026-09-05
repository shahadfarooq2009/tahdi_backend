<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Curriculum\TextbookAiService;
use Illuminate\Support\Facades\DB;

$textbookId = $argv[1] ?? '019fec7a-3c0f-7194-9a66-247cd48de54d';

$admin = DB::table('user_profiles')
    ->where('role', 'admin')
    ->where('is_active', true)
    ->orderBy('email')
    ->first(['id']);

if ($admin === null) {
    fwrite(STDERR, "No active admin found.\n");
    exit(1);
}

$pending = DB::table('ai_generated_questions')
    ->where('textbook_id', $textbookId)
    ->whereNull('question_id')
    ->whereIn('validation_status', ['validated', 'needs_review'])
    ->orderBy('created_at')
    ->get();

echo 'pending_count='.$pending->count().PHP_EOL;

/** @var TextbookAiService $service */
$service = app(TextbookAiService::class);
$actor = ['actorUserId' => $admin->id, 'actorRole' => 'admin'];

$attempted = 0;
$promoted = 0;
$failures = [];

foreach ($pending as $row) {
    $attempted++;
    try {
        $textbook = DB::table('textbooks')->where('id', $textbookId)->first();
        $bankGrade = \App\Support\QuestionGradeMapper::toBankGrade($textbook->grade ?? null);
        if ($textbook && $bankGrade) {
            DB::table('subject_grades')->updateOrInsert(
                ['subject_id' => $textbook->subject_id, 'grade' => $bankGrade],
                ['updated_at' => now(), 'created_at' => now(), 'is_completed' => false]
            );
        }

        $result = $service->reviewGeneratedQuestion(
            (string) $row->id,
            'approved',
            $actor,
            null,
            true,
        );

        if (filled($result->question_id ?? null)) {
            $promoted++;
            $bank = DB::table('questions')->where('id', $result->question_id)->first();
            echo 'PROMOTED '.$result->question_id.PHP_EOL;
            echo '  ai_type='.$row->question_type.' bank_type='.($bank->question_type ?? 'n/a').PHP_EOL;
            echo '  question_source='.($bank->question_source ?? 'n/a').PHP_EOL;
            echo '  ai_generated='.(isset($bank->ai_generated) ? (int) $bank->ai_generated : 'n/a').PHP_EOL;
            echo '  textbook_id='.($bank->textbook_id ?? 'n/a').PHP_EOL;
            echo '  unit_key='.$row->unit_key.' lesson_key='.$row->lesson_key.PHP_EOL;
            echo '  pages='.$row->source_page_start.'-'.$row->source_page_end.PHP_EOL;
            echo '  Q: '.mb_substr((string) $row->question_text, 0, 80).PHP_EOL;
        } else {
            $failures[] = ['id' => $row->id, 'error' => 'No question_id returned'];
            echo 'FAILED '.$row->id.' (no question_id)'.PHP_EOL;
        }
    } catch (Throwable $exception) {
        $failures[] = ['id' => $row->id, 'error' => $exception->getMessage()];
        echo 'FAILED '.$row->id.' '.$exception->getMessage().PHP_EOL;
    }
}

$textbook = DB::table('textbooks')->where('id', $textbookId)->first(['processing_status']);
echo PHP_EOL.'attempted='.$attempted.PHP_EOL;
echo 'promoted='.$promoted.PHP_EOL;
echo 'failures='.count($failures).PHP_EOL;
echo 'textbook_processing_status='.($textbook->processing_status ?? 'n/a').PHP_EOL;

exit($promoted === $attempted && $attempted > 0 ? 0 : 1);
