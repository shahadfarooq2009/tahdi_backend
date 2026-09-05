<?php

namespace Tests\Unit;

use App\Services\Curriculum\TextbookProcessingTimelineService;
use App\Support\TextbookProcessingStage;
use PHPUnit\Framework\TestCase;

class TextbookProcessingTimelineServiceTest extends TestCase
{
    public function test_stage_labels_cover_required_timeline(): void
    {
        $labels = TextbookProcessingStage::labels();

        $this->assertSame('رفع الكتاب', $labels[TextbookProcessingStage::UPLOAD]);
        $this->assertSame('حفظ الكتاب', $labels[TextbookProcessingStage::SAVE]);
        $this->assertSame('استخراج محتوى الكتاب', $labels[TextbookProcessingStage::EXTRACT_TEXT]);
        $this->assertSame('تحليل الفهرس', $labels[TextbookProcessingStage::DETECT_TOC]);
        $this->assertSame('اكتشاف الوحدات', $labels[TextbookProcessingStage::DETECT_UNITS]);
        $this->assertSame('تجهيز الوحدات للمراجعة', $labels[TextbookProcessingStage::PREPARE_REVIEW]);
    }

    public function test_ordered_keys_match_admin_timeline(): void
    {
        $this->assertSame(
            ['upload', 'save', 'extract_text', 'ocr_enhance', 'detect_toc', 'detect_units', 'prepare_review'],
            TextbookProcessingStage::orderedKeys()
        );
    }
}
