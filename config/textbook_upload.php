<?php

return [

    /** Bytes per upload chunk (8–16 MB recommended). */
    'chunk_size' => (int) env('TEXTBOOK_UPLOAD_CHUNK_SIZE', 10 * 1024 * 1024),

    /** Max bytes accepted for a single chunk HTTP request (chunk + multipart overhead). */
    'chunk_request_max_bytes' => (int) env('TEXTBOOK_UPLOAD_CHUNK_REQUEST_MAX_BYTES', 17 * 1024 * 1024),

    /** Incomplete upload sessions expire after this many hours. */
    'session_ttl_hours' => (int) env('TEXTBOOK_UPLOAD_SESSION_TTL_HOURS', 24),

    /** Max retry attempts per chunk on the client (documented default). */
    'client_chunk_max_retries' => 3,

];
