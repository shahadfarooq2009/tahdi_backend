<?php

namespace Tests\Unit;

use App\Services\Curriculum\ArabicExtractionQualityService;
use App\Services\Curriculum\ArabicPdfTextNormalizer;
use App\Services\Curriculum\TextbookExtractionFallbackSelector;
use Tests\TestCase;

class TextbookExtractionFallbackSelectorTest extends TestCase
{
    private TextbookExtractionFallbackSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->selector = new TextbookExtractionFallbackSelector(
            new ArabicExtractionQualityService(new ArabicPdfTextNormalizer),
        );
    }

    public function test_selects_low_quality_front_matter_pages_without_keywords(): void
    {
        $pages = [];

        for ($pageNumber = 1; $pageNumber <= 30; $pageNumber++) {
            $pages[] = [
                'page_number' => $pageNumber,
                'extraction_quality' => ['score' => 0.55],
            ];
        }

        $pages[0]['extraction_quality'] = ['score' => 0.21];
        $pages[4]['extraction_quality'] = ['score' => 0.30];

        $selected = $this->selector->selectOcrPages($pages, 30, 25);

        $this->assertContains(1, $selected);
        $this->assertContains(5, $selected);
        $this->assertNotEmpty($selected);
    }

    public function test_selects_worst_front_matter_pages_when_all_above_threshold(): void
    {
        $pages = [];

        for ($pageNumber = 1; $pageNumber <= 30; $pageNumber++) {
            $pages[] = [
                'page_number' => $pageNumber,
                'extraction_quality' => ['score' => 0.50 + ($pageNumber * 0.001)],
            ];
        }

        $selected = $this->selector->selectOcrPages($pages, 30, 5);

        $this->assertCount(5, $selected);
        $this->assertSame([1, 2, 3, 4, 5], $selected);
    }

    public function test_ignores_pages_beyond_front_matter_limit(): void
    {
        $pages = [];

        for ($pageNumber = 1; $pageNumber <= 40; $pageNumber++) {
            $pages[] = [
                'page_number' => $pageNumber,
                'extraction_quality' => ['score' => $pageNumber <= 30 ? 0.55 : 0.10],
            ];
        }

        $selected = $this->selector->selectOcrPages($pages, 30, 25);

        foreach ($selected as $pageNumber) {
            $this->assertLessThanOrEqual(30, $pageNumber);
        }
    }

    public function test_includes_early_front_matter_when_fallback_required(): void
    {
        $pages = [];

        for ($pageNumber = 1; $pageNumber <= 30; $pageNumber++) {
            $pages[] = [
                'page_number' => $pageNumber,
                'extraction_quality' => ['score' => 0.55],
            ];
        }

        $pages[2]['extraction_quality'] = ['score' => 0.10];

        $selected = $this->selector->selectOcrPages($pages, 30, 25, true);

        $this->assertContains(1, $selected);
        $this->assertContains(3, $selected);
        $this->assertContains(12, $selected);
    }
}
