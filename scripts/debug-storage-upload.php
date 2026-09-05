<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pdf = __DIR__.'/../../backend-node-legacy/scripts/fixtures/staging-arabic-textbook.pdf';
$upload = app(App\Services\Storage\SupabaseStorageService::class)
    ->createSignedUploadUrl('textbooks', 'pdfs/e2e-test-'.time().'.pdf', 'application/pdf', filesize($pdf));
$url = $upload['signed_url'];
$bytes = file_get_contents($pdf);

foreach (['application/pdf', 'application/octet-stream'] as $contentType) {
    $response = Illuminate\Support\Facades\Http::withBody($bytes, $contentType)
        ->withHeaders(['Content-Type' => $contentType, 'x-upsert' => 'true'])
        ->put($url);

    echo $contentType.' => '.$response->status().' '.$response->body().PHP_EOL;
}
