<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$total = DB::table('questions')->where('is_deleted', false)->count();
$withCategory = DB::table('questions')->where('is_deleted', false)->whereNotNull('category_id')->count();
$textbookAi = DB::table('questions')->where('is_deleted', false)->where('question_source', 'textbook_ai')->count();
$noCategory = DB::table('questions')->where('is_deleted', false)->whereNull('category_id')->count();
$aiGenerated = DB::table('ai_generated_questions')->count();
$aiPending = DB::table('ai_generated_questions')->where('validation_status', '!=', 'approved')->count();

echo "total={$total}\n";
echo "with_category={$withCategory}\n";
echo "no_category={$noCategory}\n";
echo "textbook_ai={$textbookAi}\n";
echo "ai_generated_questions={$aiGenerated}\n";
echo "ai_not_approved={$aiPending}\n";
