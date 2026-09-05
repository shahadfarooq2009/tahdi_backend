<?php

namespace Tests\Unit;

use App\Services\Curriculum\DuplicateDetectionService;
use App\Services\Curriculum\ReviewSetBuilderService;
use App\Services\Curriculum\UnitGenerationOrchestratorService;
use Tests\TestCase;

class PhaseEAiServicesTest extends TestCase
{
    public function test_duplicate_detection_flags_similar_questions(): void
    {
        $service = new DuplicateDetectionService;

        $result = $service->areQuestionsDuplicates(
            ['question_text' => 'ما هو جمع كلمة كتاب', 'answer_text' => 'كتب'],
            ['question_text' => 'ما هو جمع كلمة كتاب؟', 'answer_text' => 'كتب'],
        );

        $this->assertTrue($result['duplicate']);
    }

    public function test_allocate_lesson_slots_distributes_evenly(): void
    {
        $service = app(UnitGenerationOrchestratorService::class);
        $slots = $service->allocateLessonSlots(['lesson-1', 'lesson-2'], 5);

        $this->assertCount(2, $slots);
        $this->assertSame(5, array_sum(array_column($slots, 'count')));
    }

    public function test_review_set_builder_creates_balanced_playable_set(): void
    {
        $builder = app(ReviewSetBuilderService::class);

        $questions = [];
        $id = 1;

        foreach ([100, 200, 300, 400, 500] as $points) {
            for ($i = 0; $i < 4; $i++) {
                $questions[] = [
                    'id' => (string) $id++,
                    'question_text' => "سؤال {$points}-{$i}",
                    'answer_text' => "إجابة {$points}-{$i}",
                    'points_value' => $points,
                    'lesson_key' => 'lesson-1',
                    'validation_status' => 'validated',
                ];
            }
        }

        $sets = $builder->buildReviewSetsFromQuestions($questions, ['lessonKeys' => ['lesson-1']]);

        $this->assertCount(1, $sets);
        $this->assertTrue($sets[0]['is_playable']);
        $this->assertSame(20, $sets[0]['total_questions']);
        $this->assertTrue($builder->isBalancedPointDistribution($sets[0]['point_distribution']));
    }
}
