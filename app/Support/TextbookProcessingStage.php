<?php

namespace App\Support;

/**
 * Persistent UI timeline stages for textbook analysis (pre–unit-review).
 */
final class TextbookProcessingStage
{
    public const UPLOAD = 'upload';

    public const SAVE = 'save';

    public const EXTRACT_TEXT = 'extract_text';

    public const OCR_ENHANCE = 'ocr_enhance';

    public const DETECT_TOC = 'detect_toc';

    public const DETECT_UNITS = 'detect_units';

    public const PREPARE_REVIEW = 'prepare_review';

    /** Timeline complete — show unit review UI. */
    public const UNIT_REVIEW = 'unit_review';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @return list<string> */
    public static function orderedKeys(): array
    {
        return [
            self::UPLOAD,
            self::SAVE,
            self::EXTRACT_TEXT,
            self::OCR_ENHANCE,
            self::DETECT_TOC,
            self::DETECT_UNITS,
            self::PREPARE_REVIEW,
        ];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::UPLOAD => 'رفع الكتاب',
            self::SAVE => 'حفظ الكتاب',
            self::EXTRACT_TEXT => 'استخراج محتوى الكتاب',
            self::OCR_ENHANCE => 'تحسين قراءة الصفحات',
            self::DETECT_TOC => 'تحليل الفهرس',
            self::DETECT_UNITS => 'اكتشاف الوحدات',
            self::PREPARE_REVIEW => 'تجهيز الوحدات للمراجعة',
        ];
    }
}
