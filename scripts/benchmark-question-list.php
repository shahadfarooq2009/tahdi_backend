<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\Admin\QuestionService;
$s = app(QuestionService::class);
$start = microtime(true);
$school = $s->list(['is_deleted' => false, 'question_source' => 'manual', 'has_category' => false]);
echo 'school_count='.count($school).' time='.round(microtime(true)-$start, 2).'s'.PHP_EOL;
$start = microtime(true);
$excel = $s->list(['is_deleted' => false, 'question_source' => 'excel']);
echo 'excel_count='.count($excel).' time='.round(microtime(true)-$start, 2).'s'.PHP_EOL;
