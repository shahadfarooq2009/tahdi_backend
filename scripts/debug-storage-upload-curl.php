<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pdf = __DIR__.'/../../backend-node-legacy/scripts/fixtures/staging-arabic-textbook.pdf';
$upload = app(App\Services\Storage\SupabaseStorageService::class)
    ->createSignedUploadUrl('textbooks', 'pdfs/e2e-test-'.time().'.pdf', 'application/pdf', filesize($pdf));

$url = $upload['signed_url'];
$token = $upload['token'];
$bytes = file_get_contents($pdf);

$attempts = [
    ['headers' => [], 'label' => 'no headers'],
    ['headers' => ['Content-Type: application/pdf'], 'label' => 'content-type pdf'],
    ['headers' => ['Authorization: Bearer '.$token], 'label' => 'bearer token'],
    ['headers' => ['Content-Type: application/pdf', 'Authorization: Bearer '.$token], 'label' => 'both'],
];

foreach ($attempts as $attempt) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $attempt['headers'],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo $attempt['label'].' => '.$status.' '.$body.PHP_EOL;
}
