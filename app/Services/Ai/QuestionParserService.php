<?php

namespace App\Services\Ai;

use App\Exceptions\ServiceUnavailableException;

class QuestionParserService
{
    /**
     * @return array{question: string, answer: string}
     */
    public function parseSingleQuestion(string $content): array
    {
        $question = '';
        $answer = '';

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (str_contains($line, 'السؤال:')) {
                $question = trim(str_replace('السؤال:', '', $line));
            } elseif (str_contains($line, 'الإجابة:') || str_contains($line, 'الجواب:')) {
                $answer = trim(str_replace(['الإجابة:', 'الجواب:'], '', $line));
            }
        }

        if ($question === '' || $answer === '') {
            throw new ServiceUnavailableException('تعذر معالجة استجابة الذكاء الاصطناعي');
        }

        return ['question' => $question, 'answer' => $answer];
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    public function parseMultipleQuestions(string $content): array
    {
        $questions = [];
        $lines = array_values(array_filter(preg_split('/\R/', $content) ?: [], fn ($line) => trim($line) !== ''));
        $currentQuestion = '';
        $currentAnswer = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^\d+\./', $trimmed)) {
                if ($currentQuestion !== '' && $currentAnswer !== '') {
                    $questions[] = [
                        'question' => trim(preg_replace('/^\d+\.\s*السؤال:\s*/u', '', $currentQuestion) ?? $currentQuestion),
                        'answer' => trim(preg_replace('/^(الجواب|الإجابة):\s*/u', '', $currentAnswer) ?? $currentAnswer),
                    ];
                }

                $currentQuestion = $trimmed;
                $currentAnswer = '';
            } elseif (str_contains($trimmed, 'الجواب:') || str_contains($trimmed, 'الإجابة:')) {
                $currentAnswer = $trimmed;
            } elseif ($currentQuestion !== '' && $currentAnswer === '') {
                $currentQuestion .= ' '.$trimmed;
            } elseif ($currentAnswer !== '') {
                $currentAnswer .= ' '.$trimmed;
            }
        }

        if ($currentQuestion !== '' && $currentAnswer !== '') {
            $questions[] = [
                'question' => trim(preg_replace('/^\d+\.\s*السؤال:\s*/u', '', $currentQuestion) ?? $currentQuestion),
                'answer' => trim(preg_replace('/^(الجواب|الإجابة):\s*/u', '', $currentAnswer) ?? $currentAnswer),
            ];
        }

        if ($questions === []) {
            throw new ServiceUnavailableException('تعذر معالجة استجابة الذكاء الاصطناعي');
        }

        return $questions;
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    public function parseQuestionResponse(string $content, bool $isMultipleQuestions): array
    {
        return $isMultipleQuestions
            ? $this->parseMultipleQuestions($content)
            : [$this->parseSingleQuestion($content)];
    }

    /**
     * @return array<int, array{question: string, answer: string, points: int}>
     */
    public function parsePlaySetJsonResponse(string $content): array
    {
        $decoded = $this->decodeJsonPayload($content);

        if (! is_array($decoded)) {
            throw new ServiceUnavailableException('تعذر معالجة استجابة الذكاء الاصطناعي: JSON غير صالح');
        }

        $rows = $decoded['questions'] ?? null;

        if (! is_array($rows)) {
            throw new ServiceUnavailableException('تعذر معالجة استجابة الذكاء الاصطناعي: بنية غير صحيحة');
        }

        $questions = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new ServiceUnavailableException('تعذر معالجة استجابة الذكاء الاصطناعي: عنصر سؤال غير صالح');
            }

            $question = trim((string) ($row['question'] ?? $row['question_text'] ?? ''));
            $answer = trim((string) ($row['answer'] ?? $row['answer_text'] ?? ''));
            $points = (int) ($row['points'] ?? $row['points_value'] ?? 0);

            if ($question === '' || $answer === '' || $points <= 0) {
                throw new ServiceUnavailableException('تعذر معالجة استجابة الذكاء الاصطناعي: حقول ناقصة');
            }

            $questions[] = [
                'question' => $question,
                'answer' => $answer,
                'points' => $points,
            ];
        }

        return $questions;
    }

    private function decodeJsonPayload(string $content): mixed
    {
        $trimmed = trim($content);

        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $trimmed, $matches) === 1) {
            $trimmed = trim($matches[1]);
        }

        $decoded = json_decode($trimmed, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return json_decode(substr($trimmed, $start, $end - $start + 1), true);
    }
}
