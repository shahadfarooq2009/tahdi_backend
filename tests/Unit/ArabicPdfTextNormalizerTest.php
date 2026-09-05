<?php

namespace Tests\Unit;

use App\Services\Curriculum\ArabicExtractionQualityService;
use App\Services\Curriculum\ArabicPdfTextNormalizer;
use App\Services\Curriculum\ArabicTextService;
use Tests\TestCase;

class ArabicPdfTextNormalizerTest extends TestCase
{
    public function test_reverses_garbled_arabic_line_and_improves_known_tokens(): void
    {
        $normalizer = app(ArabicPdfTextNormalizer::class);

        $garbled = 'ةّيوناّثلا اهسردم في باتكلا اذه سيردت';
        $repaired = $normalizer->repairLine($garbled);
        $normalized = ArabicTextService::normalizeArabicText($repaired);

        $this->assertGreaterThan(
            $normalizer->scoreLine($garbled),
            $normalizer->scoreLine($repaired)
        );
        $this->assertStringContainsString('الكتاب', $normalized);
    }

    public function test_strips_indesign_footer_noise(): void
    {
        $normalizer = app(ArabicPdfTextNormalizer::class);

        $text = "الكتاب\nDeen101-2025-1.indd   1\t10/9/25   9:16 AM";

        $clean = $normalizer->stripPublisherNoise($text);

        $this->assertStringNotContainsString('indd', $clean);
        $this->assertStringContainsString('الكتاب', $clean);
    }

    public function test_detects_printed_page_number_from_first_line(): void
    {
        $normalizer = app(ArabicPdfTextNormalizer::class);

        $this->assertSame(59, $normalizer->detectPrintedPageNumber("59\nمحتوى الصفحة"));
    }

    public function test_quality_service_marks_repaired_text_more_acceptable(): void
    {
        $normalizer = app(ArabicPdfTextNormalizer::class);
        $quality = app(ArabicExtractionQualityService::class);

        $raw = 'ةّيوناّثلا اهسردم في باتكلا';
        $repaired = $normalizer->normalizePageText($raw);

        $this->assertGreaterThan(
            $quality->assessPage($raw)['score'],
            $quality->assessPage($repaired, $raw)['score']
        );
    }
}
