<?php
$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => json_encode([
            'email' => 'algannas@gmail.com',
            'password' => 'wrong-password-test',
        ]),
        'ignore_errors' => true,
    ],
]);
$raw = file_get_contents('http://127.0.0.1:8000/api/auth/login', false, $ctx);
preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0] ?? '', $m);
echo 'HTTP '.($m[1] ?? '?')."\n";
echo $raw."\n";
