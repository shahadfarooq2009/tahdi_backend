<?php

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tests = [
    ['CategoryService', App\Services\Admin\CategoryService::class, 'list', [['is_deleted' => false]]],
    ['SubjectService', App\Services\Admin\SubjectService::class, 'list', [['is_deleted' => false]]],
    ['QuestionService', App\Services\Admin\QuestionService::class, 'list', [['is_deleted' => false]]],
    ['TextbookService', App\Services\Curriculum\TextbookService::class, 'list', [[]]],
];

foreach ($tests as [$label, $class, $method, $args]) {
    $start = microtime(true);
    try {
        $result = app($class)->{$method}(...$args);
        $ms = round((microtime(true) - $start) * 1000);
        $count = is_array($result) ? count($result) : 0;
        echo "[OK] {$label}: {$count} rows in {$ms}ms\n";
    } catch (Throwable $e) {
        $ms = round((microtime(true) - $start) * 1000);
        echo "[FAIL] {$label} in {$ms}ms: ".get_class($e).': '.$e->getMessage()."\n";
        if ($e instanceof Illuminate\Database\QueryException) {
            echo "  SQLSTATE: ".($e->errorInfo[0] ?? 'n/a')."\n";
        }
    }
}
