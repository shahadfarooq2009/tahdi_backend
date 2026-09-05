<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$base = rtrim((string) config('supabase.url'), '/');
$key = (string) config('supabase.service_role_key');
$path = 'pdfs/test-upload-debug.pdf';
$enc = implode('/', array_map('rawurlencode', explode('/', $path)));

$response = Illuminate\Support\Facades\Http::withHeaders([
    'apikey' => $key,
    'Authorization' => 'Bearer '.$key,
])->post("{$base}/storage/v1/object/upload/sign/textbooks/{$enc}");

echo 'status='.$response->status().PHP_EOL;
echo $response->body().PHP_EOL;
