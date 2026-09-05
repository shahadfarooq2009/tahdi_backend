<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$rows = DB::table('questions')->where('is_deleted', false)->select('question_source', DB::raw('count(*) as c'))->groupBy('question_source')->get();
foreach ($rows as $r) { echo $r->question_source.'='.$r->c.PHP_EOL; }
$manualSchool = DB::table('questions')->where('is_deleted', false)->where('question_source', 'manual')->whereNull('category_id')->count();
$manualFamily = DB::table('questions')->where('is_deleted', false)->where('question_source', 'manual')->whereNotNull('category_id')->count();
echo "manual_school={$manualSchool}\n";
echo "manual_family={$manualFamily}\n";
