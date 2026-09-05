<?php

return [
    'purposes' => [
        'question-image' => [
            'bucket' => 'question-images',
            'folder' => 'questions',
            'max_bytes' => 5 * 1024 * 1024,
            'mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        ],
        'answer-image' => [
            'bucket' => 'question-images',
            'folder' => 'answers',
            'max_bytes' => 5 * 1024 * 1024,
            'mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        ],
        'subject-icon' => [
            'bucket' => 'question-images',
            'folder' => 'subject-icons',
            'max_bytes' => 2 * 1024 * 1024,
            'mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        ],
        'user-avatar' => [
            'bucket' => 'public',
            'folder' => 'avatars',
            'max_bytes' => 2 * 1024 * 1024,
            'mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        ],
        'textbook-pdf' => [
            'bucket' => 'textbooks',
            'folder' => 'pdfs',
            // 1 GB application limit; chunked uploads send 8–16 MB per request.
            'max_bytes' => (int) env('TEXTBOOK_PDF_MAX_BYTES', 1024 * 1024 * 1024),
            'mime_types' => ['application/pdf'],
        ],
    ],
];
