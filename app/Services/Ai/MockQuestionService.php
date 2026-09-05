<?php

namespace App\Services\Ai;

use App\Support\QuestionConstants;

class MockQuestionService
{
    /**
     * @return array<int, array{question: string, answer: string}>
     */
    public function getMockQuestions(string $subject, int $points, int $count = 1): array
    {
        $difficultyMap = [
            100 => [
                'اللغة العربية' => [
                    ['question' => 'ما هو جمع كلمة "كتاب"؟', 'answer' => 'كتب'],
                    ['question' => 'ما هو مفرد كلمة "أقلام"؟', 'answer' => 'قلم'],
                ],
                'الرياضيات' => [
                    ['question' => 'ما هو ناتج 5 + 3؟', 'answer' => '8'],
                    ['question' => 'ما هو ناتج 10 × 2؟', 'answer' => '20'],
                ],
                'العلوم' => [
                    ['question' => 'ما هو لون السماء؟', 'answer' => 'أزرق'],
                    ['question' => 'كم عدد الكواكب في المجموعة الشمسية؟', 'answer' => '8 كواكب'],
                ],
                'اللغة الانجليزية' => [
                    ['question' => 'What is the plural of "book"?', 'answer' => 'books'],
                    ['question' => 'What is the opposite of "big"?', 'answer' => 'small'],
                ],
            ],
            300 => [
                'الرياضيات' => [
                    ['question' => 'ما هو ناتج 15²؟', 'answer' => '225'],
                    ['question' => 'كم يساوي 30% من 150؟', 'answer' => '45'],
                ],
                'العلوم' => [
                    ['question' => 'ما هو العنصر الكيميائي الذي رمزه Au؟', 'answer' => 'الذهب'],
                    ['question' => 'ما هو أقرب كوكب للشمس؟', 'answer' => 'عطارد'],
                ],
            ],
        ];

        $byPoints = $difficultyMap[$points] ?? $difficultyMap[300];
        $subjectQuestions = $byPoints[$subject] ?? $byPoints['الرياضيات'];
        $count = min($count, count($subjectQuestions));
        $selected = [];

        for ($i = 0; $i < $count; $i++) {
            $selected[] = $subjectQuestions[$i % count($subjectQuestions)];
        }

        return $selected;
    }

    /**
     * @return array<int, array{question: string, answer: string, points: int}>
     */
    public function getPlaySetQuestions(string $title, ?int $count = null): array
    {
        $targetCount = $count ?? (int) config('play_sets.question_count', 20);
        $allowedPoints = config('play_sets.allowed_points', [100, 200, 300, 400, 500]);
        $perTier = (int) config('play_sets.questions_per_point_tier', 4);
        $questions = [];
        $topic = trim($title) !== '' ? $title : 'المحتوى التعليمي';

        foreach ($allowedPoints as $points) {
            for ($index = 1; $index <= $perTier; $index++) {
                $questions[] = [
                    'question' => "ما المعلومة الأساسية رقم {$index} المتعلقة بـ «{$topic}» ({$points} نقطة)؟",
                    'answer' => "إجابة نموذجية مستمدة من الملف حول «{$topic}».",
                    'points' => $points,
                ];
            }
        }

        return array_slice($questions, 0, $targetCount);
    }

    /**
     * @param  array<int, string>  $existingQuestions
     * @return array{question: string, answer: string, points: int}
     */
    public function getSinglePlaySetQuestion(string $title, int $points, array $existingQuestions = []): array
    {
        $topic = trim($title) !== '' ? $title : 'المحتوى التعليمي';
        $suffix = count($existingQuestions) + 1;

        return [
            'question' => "سؤال بديل رقم {$suffix} عن «{$topic}» بمستوى {$points} نقطة؟",
            'answer' => 'إجابة نموذجية مستمدة من الملف.',
            'points' => $points,
        ];
    }
}
