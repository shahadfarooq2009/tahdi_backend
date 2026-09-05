<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Game\GameSessionService;
use Illuminate\Support\Facades\DB;

$subject = DB::table('subjects as s')
    ->join('chapters as c', 'c.subject_id', '=', 's.id')
    ->join('questions as q', 'q.chapter_id', '=', 'c.id')
    ->whereNull('s.category_id')
    ->where('s.is_deleted', false)
    ->whereNull('q.category_id')
    ->where('q.is_deleted', false)
    ->where('q.approval_status', 'approved')
    ->where('q.grade', 'grade10')
    ->where('q.educational_stage', 'high')
    ->select('s.id as subject_id', 's.name as subject_name', 'c.id as chapter_id', 'c.name as chapter_name', DB::raw('count(q.id) as qcount'))
    ->groupBy('s.id', 's.name', 'c.id', 'c.name')
    ->havingRaw('count(q.id) >= 20')
    ->orderByDesc('qcount')
    ->first();

if (! $subject) {
    echo "No subject/chapter with 20+ grade10 questions\n";
    exit(1);
}

echo "Testing with: {$subject->subject_name} / {$subject->chapter_name} ({$subject->qcount} questions)\n";

$hostUserId = DB::table('game_sessions')->orderByDesc('created_at')->value('host_id')
    ?? 'bca0a6d1-0a30-4140-bee0-f3eae6d9af91';

$payload = [
    'mode' => 'school',
    'class_name' => 'فصل اختبار',
    'subject_ids' => [$subject->subject_id],
    'teams' => [
        ['name' => 'فريق 1', 'avatar_url' => '/assets/avatars/1.png', 'color' => '#6B46C1'],
        ['name' => 'فريق 2', 'avatar_url' => '/assets/avatars/2.png', 'color' => '#FACC15'],
    ],
    'metadata' => [
        'educational_stage' => 'المرحلة الثانوية',
        'grade' => 'أول ثانوي',
        'unit' => $subject->chapter_name,
        'chapter_id' => $subject->chapter_id,
        'selected_subject' => $subject->subject_name,
        'selected_powers' => ['teacher'],
    ],
];

try {
    $service = app(GameSessionService::class);
    $started = microtime(true);
    $result = $service->createGameSession($payload, $hostUserId);
    $elapsed = round((microtime(true) - $started) * 1000);

    $sessionId = $result['session']['id'] ?? 'unknown';
    $questionCount = DB::table('game_session_questions')->where('game_session_id', $sessionId)->count();
    $source = $result['session']['metadata']['question_source'] ?? 'review_set';

    echo "SUCCESS in {$elapsed}ms\n";
    echo "Session: {$sessionId}\n";
    echo "Questions assigned: {$questionCount}\n";
    echo "Source: {$source}\n";

    DB::table('game_session_questions')->where('game_session_id', $sessionId)->delete();
    DB::table('game_session_teams')->where('game_session_id', $sessionId)->delete();
    DB::table('game_session_subjects')->where('game_session_id', $sessionId)->delete();
    DB::table('game_sessions')->where('id', $sessionId)->delete();
    echo "Cleaned up test session\n";
} catch (Throwable $e) {
    echo 'FAILED: '.$e->getMessage()."\n";
    exit(1);
}
