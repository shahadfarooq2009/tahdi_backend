<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Admin\QuestionService;

$started = microtime(true);
$service = app(QuestionService::class);
$rows = $service->list(['is_deleted' => false]);
$elapsed = round((microtime(true) - $started) * 1000);

$schoolManual = 0;
$textbookAi = 0;
$family = 0;

foreach ($rows as $row) {
    if (! empty($row['category_id'])) {
        $family++;
        continue;
    }
    if (($row['question_source'] ?? 'manual') === 'textbook_ai') {
        $textbookAi++;
        continue;
    }
    $schoolManual++;
}

echo 'api_rows=' . count($rows) . PHP_EOL;
echo "elapsed_ms={$elapsed}" . PHP_EOL;
echo "school_manual={$schoolManual}" . PHP_EOL;
echo "textbook_ai={$textbookAi}" . PHP_EOL;
echo "family={$family}" . PHP_EOL;

$json = json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
echo 'json_bytes=' . strlen($json) . PHP_EOL;
