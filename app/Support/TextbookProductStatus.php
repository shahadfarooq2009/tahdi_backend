<?php

namespace App\Support;

/**
 * Product-level textbook workflow states exposed to the admin UI.
 *
 * Internal pipeline statuses (queued, extracting, textbook-extraction, etc.)
 * are mapped here and must not leak to the frontend.
 */
final class TextbookProductStatus
{
    public const UPLOAD = 'upload';

    public const ANALYZING = 'analyzing';

    public const UNIT_REVIEW = 'unit_review';

    public const GENERATING_QUESTIONS = 'generating_questions';

    public const READY = 'ready';

    public const ERROR = 'error';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::UPLOAD,
            self::ANALYZING,
            self::UNIT_REVIEW,
            self::GENERATING_QUESTIONS,
            self::READY,
            self::ERROR,
        ];
    }

    public static function fromInternal(?string $internalStatus): string
    {
        $status = TextbookProcessingStatus::normalize($internalStatus);

        return match ($status) {
            TextbookProcessingStatus::UPLOADED => self::UPLOAD,
            TextbookProcessingStatus::QUEUED,
            TextbookProcessingStatus::EXTRACTING,
            TextbookProcessingStatus::ANALYZING_CONTENTS,
            TextbookProcessingStatus::UNITS_DETECTED => self::ANALYZING,
            TextbookProcessingStatus::AWAITING_UNIT_REVIEW,
            TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED => self::UNIT_REVIEW,
            TextbookProcessingStatus::UNITS_APPROVED,
            TextbookProcessingStatus::GENERATING_QUESTIONS => self::GENERATING_QUESTIONS,
            TextbookProcessingStatus::AWAITING_QUESTION_REVIEW,
            TextbookProcessingStatus::READY => self::READY,
            TextbookProcessingStatus::FAILED => self::ERROR,
            default => self::UPLOAD,
        };
    }

    public static function message(string $productStatus, ?string $lastError = null): string
    {
        if ($productStatus === self::ERROR) {
            return $lastError !== null && $lastError !== ''
                ? $lastError
                : 'تعذر معالجة الكتاب. يمكنك إعادة المحاولة.';
        }

        return match ($productStatus) {
            self::UPLOAD => 'تم رفع الكتاب',
            self::ANALYZING => 'جاري تحليل الكتاب واستخراج الوحدات...',
            self::UNIT_REVIEW => 'راجع الوحدات ثم اضغط «اعتماد وتوليد الأسئلة»',
            self::GENERATING_QUESTIONS => 'جاري توليد أسئلة الوحدات...',
            self::READY => 'الأسئلة جاهزة للمراجعة',
            default => 'جاري معالجة الكتاب...',
        };
    }

    public static function isActive(string $productStatus): bool
    {
        return in_array($productStatus, [self::ANALYZING, self::GENERATING_QUESTIONS], true);
    }

    public static function allowsUnitReview(string $productStatus): bool
    {
        return $productStatus === self::UNIT_REVIEW;
    }
}
