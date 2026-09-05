<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ChapterListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChapterController extends Controller
{
    public function __construct(
        private readonly ChapterListService $chapters,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [];

        if ($request->filled('subject_id')) {
            $filters['subject_id'] = (string) $request->query('subject_id');
        }

        if ($request->filled('created_by')) {
            $filters['created_by'] = (string) $request->query('created_by');
        }

        return $this->success($this->chapters->list($filters));
    }
}
