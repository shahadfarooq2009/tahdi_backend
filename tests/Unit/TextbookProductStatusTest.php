<?php

namespace Tests\Unit;

use App\Support\TextbookProductStatus;
use App\Support\TextbookProcessingStatus;
use PHPUnit\Framework\TestCase;

class TextbookProductStatusTest extends TestCase
{
    public function test_maps_internal_pipeline_statuses_to_product_states(): void
    {
        $this->assertSame(
            TextbookProductStatus::UPLOAD,
            TextbookProductStatus::fromInternal(TextbookProcessingStatus::UPLOADED)
        );
        $this->assertSame(
            TextbookProductStatus::ANALYZING,
            TextbookProductStatus::fromInternal(TextbookProcessingStatus::QUEUED)
        );
        $this->assertSame(
            TextbookProductStatus::ANALYZING,
            TextbookProductStatus::fromInternal(TextbookProcessingStatus::EXTRACTING)
        );
        $this->assertSame(
            TextbookProductStatus::UNIT_REVIEW,
            TextbookProductStatus::fromInternal(TextbookProcessingStatus::AWAITING_UNIT_REVIEW)
        );
        $this->assertSame(
            TextbookProductStatus::UNIT_REVIEW,
            TextbookProductStatus::fromInternal(TextbookProcessingStatus::MANUAL_STRUCTURE_REQUIRED)
        );
        $this->assertSame(
            TextbookProductStatus::GENERATING_QUESTIONS,
            TextbookProductStatus::fromInternal(TextbookProcessingStatus::UNITS_APPROVED)
        );
        $this->assertSame(
            TextbookProductStatus::READY,
            TextbookProductStatus::fromInternal(TextbookProcessingStatus::AWAITING_QUESTION_REVIEW)
        );
        $this->assertSame(
            TextbookProductStatus::ERROR,
            TextbookProductStatus::fromInternal(TextbookProcessingStatus::FAILED)
        );
    }

    public function test_active_states_exclude_review_and_ready(): void
    {
        $this->assertTrue(TextbookProductStatus::isActive(TextbookProductStatus::ANALYZING));
        $this->assertTrue(TextbookProductStatus::isActive(TextbookProductStatus::GENERATING_QUESTIONS));
        $this->assertFalse(TextbookProductStatus::isActive(TextbookProductStatus::UNIT_REVIEW));
        $this->assertFalse(TextbookProductStatus::isActive(TextbookProductStatus::READY));
    }
}
