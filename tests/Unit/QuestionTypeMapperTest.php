<?php

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Support\QuestionTypeMapper;
use Tests\TestCase;

class QuestionTypeMapperTest extends TestCase
{
    public function test_single_answer_maps_to_multiple_choice_for_bank(): void
    {
        $this->assertSame('multiple_choice', QuestionTypeMapper::toBankType('single_answer'));
    }

    public function test_multiple_choice_and_true_false_map_unchanged(): void
    {
        $this->assertSame('multiple_choice', QuestionTypeMapper::toBankType('multiple_choice'));
        $this->assertSame('true_false', QuestionTypeMapper::toBankType('true_false'));
    }

    public function test_unsupported_ai_question_type_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        QuestionTypeMapper::toBankType('audio_clip');
    }

    public function test_bank_types_match_database_enum(): void
    {
        $this->assertSame(['multiple_choice', 'true_false'], QuestionTypeMapper::bankTypes());
    }

    public function test_numeric_grade_maps_to_grade_prefix(): void
    {
        $this->assertSame('grade7', \App\Support\QuestionGradeMapper::toBankGrade('7'));
        $this->assertSame('grade10', \App\Support\QuestionGradeMapper::toBankGrade('grade10'));
        $this->assertNull(\App\Support\QuestionGradeMapper::toBankGrade(null));
    }
}
