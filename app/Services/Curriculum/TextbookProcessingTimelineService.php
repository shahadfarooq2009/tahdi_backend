<?php

namespace App\Services\Curriculum;

use App\Models\Textbook;
use App\Support\TextbookProcessingStage;
use App\Support\TextbookProcessingStatus;

class TextbookProcessingTimelineService
{
    /** @var array<string, array{at: float, pages: int}> */
    private array $progressWriteThrottle = [];

    /** @var array<string, array<string, mixed>> */
    private array $metaCache = [];

    /**
     * @param  array<string, mixed>  $meta
     */
    public function advanceToStage(string $textbookId, string $stage, array $meta = [], bool $force = false): void
    {
        $existing = $this->loadMeta($textbookId, $force);

        $merged = array_merge($existing, $meta, [
            'updated_at' => now()->toIso8601String(),
            'heartbeat_at' => now()->toIso8601String(),
        ]);

        $this->persistMeta($textbookId, $stage, $merged);
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    public function updateExtractionProgress(string $textbookId, array $progress, bool $force = false): void
    {
        $processed = (int) ($progress['processed_pages'] ?? 0);
        $total = isset($progress['total_pages']) ? (int) $progress['total_pages'] : null;
        $phase = (string) ($progress['phase'] ?? 'extracting');

        if (! $force && ! $this->shouldWriteProgress($textbookId, $processed)) {
            return;
        }

        $this->progressWriteThrottle[$textbookId] = [
            'at' => microtime(true),
            'pages' => $processed,
        ];

        $existing = $this->loadMeta($textbookId, $force);

        $progressPercent = null;
        if ($phase === 'ocr') {
            $ocrProcessed = (int) ($progress['ocr_processed'] ?? $processed);
            $ocrTotal = max(1, (int) ($progress['ocr_total'] ?? 0));
            $progressPercent = (int) min(99, round(($ocrProcessed / $ocrTotal) * 100));
        } elseif ($total !== null && $total > 0) {
            $progressPercent = (int) min(99, round(($processed / $total) * 100));
        }

        $stageMessage = $progress['stage_message'] ?? $this->buildExtractionStageMessage($phase, $processed, $total, $progress);
        $stage = $phase === 'ocr'
            ? TextbookProcessingStage::OCR_ENHANCE
            : TextbookProcessingStage::EXTRACT_TEXT;

        $payload = array_merge($existing, $progress, array_filter([
            'phase' => $phase,
            'processed_pages' => $processed,
            'total_pages' => $total,
            'progress_percent' => $progressPercent,
            'stage_message' => $stageMessage,
            'updated_at' => now()->toIso8601String(),
            'heartbeat_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null));

        if ($phase === 'ocr') {
            $payload['ocr_triggered'] = true;
        }

        $this->persistMeta($textbookId, $stage, $payload);
    }

    public function markFailed(string $textbookId, string $stage, string $error): void
    {
        $existing = $this->loadMeta($textbookId, true);

        $this->persistMeta($textbookId, $stage, array_merge($existing, [
            'failed_stage' => $stage,
            'error' => $error,
            'updated_at' => now()->toIso8601String(),
            'heartbeat_at' => now()->toIso8601String(),
        ]));
    }

    public function initializeAfterSave(string $textbookId, ?int $totalPages = null): void
    {
        $this->advanceToStage($textbookId, TextbookProcessingStage::EXTRACT_TEXT, array_filter([
            'upload_completed_at' => now()->toIso8601String(),
            'save_completed_at' => now()->toIso8601String(),
            'total_pages' => $totalPages,
            'processed_pages' => 0,
            'phase' => 'extracting',
            'stage_message' => 'جاري استخراج محتوى الكتاب',
        ]), true);
    }

    /**
     * Read-only timeline for polling endpoints (no writes, no queue recovery).
     *
     * @return array<string, mixed>
     */
    public function build(string $textbookId): array
    {
        $textbook = Textbook::query()
            ->select([
                'id',
                'processing_status',
                'processing_current_stage',
                'processing_stage_meta',
                'last_error',
            ])
            ->find($textbookId);

        if (! $textbook) {
            return [
                'status' => 'not_found',
                'current_stage' => null,
                'stages' => [],
            ];
        }

        $meta = is_array($textbook->processing_stage_meta)
            ? $textbook->processing_stage_meta
            : [];

        $internalStatus = TextbookProcessingStatus::normalize($textbook->processing_status);
        $isFailed = $internalStatus === TextbookProcessingStatus::FAILED;
        $failedStage = (string) ($meta['failed_stage'] ?? TextbookProcessingStage::EXTRACT_TEXT);
        $currentStage = $this->inferCurrentStage($textbook, $meta);

        $overallStatus = $isFailed
            ? 'failed'
            : ($currentStage === TextbookProcessingStage::UNIT_REVIEW ? 'unit_review' : 'processing');

        $stages = [];

        foreach (TextbookProcessingStage::orderedKeys() as $stageKey) {
            if ($stageKey === TextbookProcessingStage::OCR_ENHANCE && empty($meta['ocr_triggered'])) {
                continue;
            }

            $stages[] = $this->buildStagePayload(
                $textbook,
                $stageKey,
                $currentStage,
                $meta,
                $isFailed,
                $failedStage,
            );
        }

        return [
            'status' => $overallStatus,
            'current_stage' => $currentStage,
            'stages' => $stages,
            'error' => $isFailed ? ($textbook->last_error ?: ($meta['error'] ?? null)) : null,
            'heartbeat_at' => $meta['heartbeat_at'] ?? $meta['updated_at'] ?? null,
        ];
    }

    private function shouldWriteProgress(string $textbookId, int $processedPages): bool
    {
        $pageInterval = max(1, (int) config('textbook_extraction.progress_page_interval', 10));
        $secondsInterval = max(1, (int) config('textbook_extraction.progress_seconds_interval', 2));
        $last = $this->progressWriteThrottle[$textbookId] ?? ['at' => 0.0, 'pages' => -1];

        if ($processedPages <= 0) {
            return true;
        }

        if ($last['pages'] < 0) {
            return true;
        }

        $pagesDelta = $processedPages - $last['pages'];
        $secondsDelta = microtime(true) - $last['at'];

        return $pagesDelta >= $pageInterval || $secondsDelta >= $secondsInterval;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadMeta(string $textbookId, bool $force): array
    {
        if (! $force && isset($this->metaCache[$textbookId])) {
            return $this->metaCache[$textbookId];
        }

        $textbook = Textbook::query()
            ->select(['processing_stage_meta'])
            ->find($textbookId);

        $meta = is_array($textbook?->processing_stage_meta)
            ? $textbook->processing_stage_meta
            : [];

        $this->metaCache[$textbookId] = $meta;

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function persistMeta(string $textbookId, string $stage, array $meta): void
    {
        $this->metaCache[$textbookId] = $meta;

        Textbook::query()
            ->where('id', $textbookId)
            ->update([
                'processing_current_stage' => $stage,
                'processing_stage_meta' => $meta,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function inferCurrentStage(Textbook $textbook, array $meta): string
    {
        $status = TextbookProcessingStatus::normalize($textbook->processing_status);
        $current = (string) ($textbook->processing_current_stage ?? '');

        if (in_array($status, [
            TextbookProcessingStatus::AWAITING_UNIT_REVIEW,
            TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED,
        ], true)) {
            return TextbookProcessingStage::UNIT_REVIEW;
        }

        if ($current !== '') {
            return $current;
        }

        return match ($status) {
            TextbookProcessingStatus::UPLOADED, TextbookProcessingStatus::QUEUED => TextbookProcessingStage::EXTRACT_TEXT,
            TextbookProcessingStatus::EXTRACTING => TextbookProcessingStage::EXTRACT_TEXT,
            TextbookProcessingStatus::ANALYZING_CONTENTS => TextbookProcessingStage::DETECT_TOC,
            TextbookProcessingStatus::UNITS_DETECTED => TextbookProcessingStage::DETECT_UNITS,
            default => TextbookProcessingStage::UPLOAD,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function buildStagePayload(
        Textbook $textbook,
        string $stageKey,
        string $currentStage,
        array $meta,
        bool $isFailed,
        string $failedStage,
    ): array {
        $labels = TextbookProcessingStage::labels();
        $order = TextbookProcessingStage::orderedKeys();
        $stageIndex = array_search($stageKey, $order, true);
        $currentIndex = $currentStage === TextbookProcessingStage::UNIT_REVIEW
            ? count($order)
            : array_search($currentStage, $order, true);

        if ($currentIndex === false) {
            $currentIndex = 0;
        }

        $status = TextbookProcessingStage::STATUS_PENDING;

        if ($isFailed && $stageKey === $failedStage) {
            $status = TextbookProcessingStage::STATUS_FAILED;
        } elseif ($currentStage === TextbookProcessingStage::UNIT_REVIEW || $stageIndex < $currentIndex) {
            $status = TextbookProcessingStage::STATUS_COMPLETED;
        } elseif ($stageKey === $currentStage) {
            $status = $isFailed ? TextbookProcessingStage::STATUS_FAILED : TextbookProcessingStage::STATUS_RUNNING;
        }

        $payload = [
            'key' => $stageKey,
            'label' => $labels[$stageKey] ?? $stageKey,
            'status' => $status,
        ];

        $message = $this->stageMessage($stageKey, $status, $meta, $textbook);
        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($status === TextbookProcessingStage::STATUS_FAILED) {
            $payload['error'] = $meta['error'] ?? $textbook->last_error;
        }

        if (in_array($stageKey, [TextbookProcessingStage::EXTRACT_TEXT, TextbookProcessingStage::OCR_ENHANCE], true)
            && $status === TextbookProcessingStage::STATUS_RUNNING) {
            $progressData = $this->extractionProgress($meta, $stageKey);
            if ($progressData['progress'] !== null) {
                $payload['progress'] = $progressData['progress'];
            }
            if ($progressData['details'] !== null) {
                $payload['details'] = $progressData['details'];
            }
        }

        if ($stageKey === TextbookProcessingStage::DETECT_UNITS && $status === TextbookProcessingStage::STATUS_COMPLETED) {
            $units = (int) ($meta['units_detected'] ?? 0);
            if ($units > 0) {
                $payload['message'] = "تم اكتشاف {$units} وحدات";
                $payload['details'] = "{$units} وحدات";
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{progress: int|null, details: string|null}
     */
    private function extractionProgress(array $meta, string $stageKey): array
    {
        if ($stageKey === TextbookProcessingStage::OCR_ENHANCE || ($meta['phase'] ?? '') === 'ocr') {
            $ocrProcessed = (int) ($meta['ocr_processed'] ?? 0);
            $ocrTotal = max(1, (int) ($meta['ocr_total'] ?? 0));
            $progress = (int) min(99, round(($ocrProcessed / $ocrTotal) * 100));

            return [
                'progress' => $progress,
                'details' => "{$ocrProcessed} من {$ocrTotal}",
            ];
        }

        if (isset($meta['progress_percent']) && is_numeric($meta['progress_percent'])) {
            $processed = (int) ($meta['processed_pages'] ?? 0);
            $total = (int) ($meta['total_pages'] ?? 0);
            $details = $total > 0 && $processed > 0
                ? "{$processed} من {$total} صفحة"
                : null;

            return [
                'progress' => (int) $meta['progress_percent'],
                'details' => $details,
            ];
        }

        $processed = (int) ($meta['processed_pages'] ?? 0);
        $total = (int) ($meta['total_pages'] ?? 0);

        if ($total <= 0 || $processed <= 0) {
            return ['progress' => null, 'details' => null];
        }

        $progress = (int) min(99, round(($processed / max(1, $total)) * 100));

        return [
            'progress' => $progress,
            'details' => "{$processed} من {$total} صفحة",
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function stageMessage(string $stageKey, string $status, array $meta, Textbook $textbook): ?string
    {
        if ($status === TextbookProcessingStage::STATUS_RUNNING && isset($meta['stage_message']) && is_string($meta['stage_message'])) {
            if (in_array($stageKey, [TextbookProcessingStage::EXTRACT_TEXT, TextbookProcessingStage::OCR_ENHANCE], true)) {
                return $meta['stage_message'];
            }
        }

        $labels = TextbookProcessingStage::labels();

        if ($status === TextbookProcessingStage::STATUS_COMPLETED) {
            return match ($stageKey) {
                TextbookProcessingStage::UPLOAD => 'تم رفع الكتاب',
                TextbookProcessingStage::SAVE => 'تم حفظ الكتاب',
                TextbookProcessingStage::EXTRACT_TEXT => 'تم استخراج محتوى الكتاب',
                TextbookProcessingStage::OCR_ENHANCE => 'تم تحسين قراءة الصفحات',
                TextbookProcessingStage::DETECT_TOC => 'تم تحليل الفهرس',
                TextbookProcessingStage::DETECT_UNITS => isset($meta['units_detected'])
                    ? 'تم اكتشاف '.(int) $meta['units_detected'].' وحدات'
                    : 'تم اكتشاف الوحدات',
                TextbookProcessingStage::PREPARE_REVIEW => 'الوحدات جاهزة للمراجعة',
                default => $labels[$stageKey] ?? null,
            };
        }

        if ($status === TextbookProcessingStage::STATUS_RUNNING) {
            return match ($stageKey) {
                TextbookProcessingStage::EXTRACT_TEXT => 'جاري استخراج محتوى الكتاب',
                TextbookProcessingStage::OCR_ENHANCE => 'جاري تحسين قراءة الصفحات',
                TextbookProcessingStage::DETECT_TOC => 'جاري تحليل الفهرس',
                TextbookProcessingStage::DETECT_UNITS => 'جاري اكتشاف الوحدات',
                TextbookProcessingStage::PREPARE_REVIEW => 'جاري تجهيز الوحدات للمراجعة',
                default => 'جاري '.($labels[$stageKey] ?? $stageKey),
            };
        }

        if ($status === TextbookProcessingStage::STATUS_FAILED) {
            return $textbook->last_error ?: ($meta['error'] ?? 'فشلت المعالجة');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function buildExtractionStageMessage(string $phase, int $processed, ?int $total, array $progress): string
    {
        if ($phase === 'ocr') {
            return 'جاري تحسين قراءة الصفحات';
        }

        return 'جاري استخراج محتوى الكتاب';
    }
}
