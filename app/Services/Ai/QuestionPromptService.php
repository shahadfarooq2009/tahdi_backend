<?php

namespace App\Services\Ai;

class QuestionPromptService
{
    public function getDifficultyLevel(int $points): string
    {
        return match ($points) {
            100 => 'سهل جداً — استرجاع مباشر من النص (تعريف، اسم، حقيقة صريحة)',
            200 => 'سهل — فهم أساسي للمحتوى وليس حفظاً حرفياً فقط',
            300 => 'متوسط — شرح أو ربط معلومات من النص',
            400 => 'صعب — تحليل أو مقارنة أو تفسير أعمق',
            500 => 'صعب جداً — استنتاج وتحليل يجمع أكثر من فكرة من النص',
            default => 'متوسط',
        };
    }

    public function buildQuestionPrompt(string $category, string $subject, int $points, int $count = 1): string
    {
        $difficultyLevel = $this->getDifficultyLevel($points);

        if ($count > 1) {
            return "قم بإنشاء {$count} أسئلة تعليمية في مادة \"{$subject}\" ضمن فئة \"{$category}\".

مستوى الصعوبة: {$difficultyLevel} ({$points} نقطة)

يجب أن تكون الأسئلة:
- مناسبة لمستوى الصعوبة المحدد ({$difficultyLevel})
- واضحة ومفهومة
- لها إجابات محددة وواحدة
- تتناسب مع المستوى التعليمي المطلوب

أجب بالتنسيق التالي (قائمة مرقمة):
1. السؤال: [نص السؤال الأول]
الجواب: [الإجابة الصحيحة للسؤال الأول]

2. السؤال: [نص السؤال الثاني]
الجواب: [الإجابة الصحيحة للسؤال الثاني]

وهكذا حتى {$count} أسئلة...";
        }

        return "قم بإنشاء سؤال تعليمي في مادة \"{$subject}\" ضمن فئة \"{$category}\".

مستوى الصعوبة: {$difficultyLevel} ({$points} نقطة)

يجب أن يكون السؤال:
- مناسب لمستوى الصعوبة المحدد ({$difficultyLevel})
- واضح ومفهوم
- له إجابة محددة وواحدة
- يتناسب مع المستوى التعليمي المطلوب

أجب بالتنسيق التالي:
السؤال: [نص السؤال]
الإجابة: [الإجابة الصحيحة]";
    }

    public function buildQuestionsFromDocumentPrompt(string $title, string $content, int $count = 10): string
    {
        return $this->buildPlaySetQuestionsFromDocumentPrompt($title, $content);
    }

    public function buildPlaySetQuestionsFromDocumentPrompt(string $title, string $content): string
    {
        $count = (int) config('play_sets.question_count', 20);
        $perTier = (int) config('play_sets.questions_per_point_tier', 4);

        return <<<PROMPT
أنت مساعد تعليمي. اقرأ النص التالي المستخرج من ملف بعنوان "{$title}" ثم أنشئ بالضبط {$count} سؤالاً تعليمياً باللغة العربية مع إجابات نموذجية.

قواعد صارمة:
- استخدم فقط المعلومات الواردة في النص. لا تستخدم معرفة خارجية ولا تخترع حقائق.
- الأسئلة ليست اختياراً من متعدد.
- لكل سؤال إجابة نموذجية واحدة واضحة ومختصرة وكاملة.
- لا تكرر الأسئلة أو تعيد صياغة نفس الفكرة بشكل متشابه.
- الصياغة واضحة ومناسبة للطلاب.

توزيع النقاط (الصعوبة) — يجب الالتزام به بالضبط:
- {$perTier} أسئلة بقيمة 100 نقطة: {$this->getDifficultyLevel(100)}
- {$perTier} أسئلة بقيمة 200 نقطة: {$this->getDifficultyLevel(200)}
- {$perTier} أسئلة بقيمة 300 نقطة: {$this->getDifficultyLevel(300)}
- {$perTier} أسئلة بقيمة 400 نقطة: {$this->getDifficultyLevel(400)}
- {$perTier} أسئلة بقيمة 500 نقطة: {$this->getDifficultyLevel(500)}

أعد JSON فقط بالشكل التالي دون أي نص إضافي:
{
  "questions": [
    { "question": "نص السؤال", "answer": "الإجابة النموذجية", "points": 100 }
  ]
}

النص:
---
{$content}
---
PROMPT;
    }

    /**
     * @param  array<int, string>  $existingQuestions
     */
    public function buildSingleQuestionFromDocumentPrompt(
        string $title,
        string $content,
        int $points,
        array $existingQuestions,
    ): string {
        $difficulty = $this->getDifficultyLevel($points);
        $existingList = $existingQuestions === []
            ? 'لا توجد أسئلة حالية.'
            : implode("\n", array_map(
                fn (string $question, int $index) => ($index + 1).'. '.$question,
                $existingQuestions,
                array_keys($existingQuestions)
            ));

        return <<<PROMPT
أنت مساعد تعليمي. اقرأ النص التالي من ملف "{$title}" وأنشئ سؤالاً واحداً جديداً باللغة العربية مع إجابة نموذجية.

قواعد صارمة:
- استخدم فقط المعلومات الواردة في النص.
- السؤال ليس اختياراً من متعدد.
- قيمة النقاط يجب أن تكون {$points} بالضبط.
- مستوى الصعوبة: {$difficulty}
- لا يجوز أن يكون السؤال مكرراً أو مشابهاً لأي سؤال من الأسئلة الحالية أدناه.
- الإجابة مختصرة وكاملة وواضحة.

الأسئلة الحالية (تجنب التكرار معها):
{$existingList}

أعد JSON فقط بالشكل التالي:
{
  "questions": [
    { "question": "نص السؤال", "answer": "الإجابة النموذجية", "points": {$points} }
  ]
}

النص:
---
{$content}
---
PROMPT;
    }
}
