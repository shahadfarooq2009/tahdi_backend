<?php

namespace App\Http\Controllers;

use App\Support\DatabaseConfigured;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'databaseConnected' => DatabaseConfigured::check(),
            'php' => [
                'ini_loaded_file' => php_ini_loaded_file() ?: null,
                'upload_max_filesize' => ini_get('upload_max_filesize') ?: null,
                'post_max_size' => ini_get('post_max_size') ?: null,
                'memory_limit' => ini_get('memory_limit') ?: null,
                'max_execution_time' => ini_get('max_execution_time') ?: null,
                'max_input_time' => ini_get('max_input_time') ?: null,
            ],
        ]);
    }
}
