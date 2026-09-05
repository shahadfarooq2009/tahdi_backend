<?php

namespace App\Services\Ai;

use App\Exceptions\ServiceUnavailableException;
use App\Exceptions\ValidationException;
use App\Services\Play\PlaySetDocumentContextService;
use App\Services\Play\PlaySetQuestionValidationService;

class AiService
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly QuestionPromptService $prompts,
        private readonly QuestionParserService $parser,
        private readonly MockQuestionService $mocks,
        private readonly PlaySetQuestionValidationService $playSetValidator,
        private readonly PlaySetDocumentContextService $documentContext,
    ) {}

    /**
     * @return array{provider: string, configured: bool, ready: bool}
     */
    public function getStatus(): array
    {
        $configured = $this->ai->isConfigured();

        return [
            'provider' => $this->ai->provider(),
            'configured' => $configured,
            'ready' => $configured,
        ];
    }

    /**
     * @return array{questions: array<int, array{question: string, answer: string}>, usedFallback: bool}
     */
    public function generateQuestions(string $category, string $subject, int $points, int $count = 1): array
    {
        if (! $this->ai->isConfigured()) {
            return [
                'questions' => $this->mocks->getMockQuestions($subject, $points, $count),
                'usedFallback' => true,
            ];
        }

        try {
            $prompt = $this->prompts->buildQuestionPrompt($category, $subject, $points, $count);
            $questions = $this->requestFromProvider($prompt, $count > 1);

            return [
                'questions' => array_slice($questions, 0, $count),
                'usedFallback' => false,
            ];
        } catch (ServiceUnavailableException $exception) {
            if (($exception->providerStatus ?? null) === 429) {
                return [
                    'questions' => $this->mocks->getMockQuestions($subject, $points, $count),
                    'usedFallback' => true,
                ];
            }

            throw $exception;
        }
    }

    /**
     * @return array{questions: array<int, array{question: string, answer: string}>, usedFallback: bool}
     */
    public function generateQuestionsFromDocument(string $title, string $content, int $count = 10): array
    {
        $result = $this->generatePlaySetQuestionsFromDocument($title, $content);

        return [
            'questions' => array_map(
                fn (array $row) => [
                    'question' => $row['question'],
                    'answer' => $row['answer'],
                ],
                array_slice($result['questions'], 0, $count)
            ),
            'usedFallback' => $result['usedFallback'],
        ];
    }

    /**
     * @return array{questions: array<int, array{question: string, answer: string, points: int}>, usedFallback: bool}
     */
    public function generatePlaySetQuestionsFromDocument(string $title, string $content): array
    {
        if (trim($content) === '') {
            throw new ServiceUnavailableException('لم يتم العثور على نص قابل للاستخراج من الملف');
        }

        if (! $this->ai->isConfigured()) {
            return [
                'questions' => $this->mocks->getPlaySetQuestions($title),
                'usedFallback' => true,
            ];
        }

        $excerpt = $this->documentContext->excerptForPrompt($content);
        $maxAttempts = (int) config('play_sets.generation_max_attempts', 3);
        $lastError = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $prompt = $this->prompts->buildPlaySetQuestionsFromDocumentPrompt($title, $excerpt);
                $raw = $this->requestJsonFromProvider($prompt, 8000);
                $questions = $this->parser->parsePlaySetJsonResponse($raw);
                $this->playSetValidator->validate($questions);

                return [
                    'questions' => $questions,
                    'usedFallback' => false,
                ];
            } catch (ValidationException $exception) {
                $lastError = $exception;
            } catch (ServiceUnavailableException $exception) {
                if (($exception->providerStatus ?? null) === 429 && $attempt < $maxAttempts - 1) {
                    usleep((2 ** $attempt) * 1000000);

                    continue;
                }

                throw $exception;
            }
        }

        throw new ServiceUnavailableException(
            $lastError?->getMessage() ?? 'تعذر توليد أسئلة صالحة من خدمة الذكاء الاصطناعي بعد عدة محاولات'
        );
    }

    /**
     * @param  array<int, string>  $existingQuestions
     * @return array{question: string, answer: string, points: int, usedFallback: bool}
     */
    public function regeneratePlaySetQuestionFromDocument(
        string $title,
        string $content,
        int $points,
        array $existingQuestions,
    ): array {
        if (trim($content) === '') {
            throw new ServiceUnavailableException('لا يتوفر المحتوى المصدر لإعادة التوليد');
        }

        if (! $this->ai->isConfigured()) {
            $mock = $this->mocks->getSinglePlaySetQuestion($title, $points, $existingQuestions);

            return [
                ...$mock,
                'usedFallback' => true,
            ];
        }

        $excerpt = $this->documentContext->excerptForPrompt($content);
        $maxAttempts = (int) config('play_sets.generation_max_attempts', 3);
        $lastError = null;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $prompt = $this->prompts->buildSingleQuestionFromDocumentPrompt(
                    $title,
                    $excerpt,
                    $points,
                    $existingQuestions,
                );
                $raw = $this->requestJsonFromProvider($prompt, 1200);
                $questions = $this->parser->parsePlaySetJsonResponse($raw);

                if (count($questions) !== 1) {
                    throw new ValidationException('تعذر إعادة توليد سؤال واحد فقط من الاستجابة');
                }

                $question = $questions[0];

                if ($question['points'] !== $points) {
                    $question['points'] = $points;
                }

                $this->playSetValidator->validateSingle($question);
                $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $question['question']) ?? $question['question']);

                foreach ($existingQuestions as $existing) {
                    $existingNormalized = mb_strtolower(preg_replace('/\s+/u', ' ', $existing) ?? $existing);
                    if ($normalized === $existingNormalized) {
                        throw new ValidationException('السؤال المُولَّد مكرر أو مشابه لسؤال موجود');
                    }
                }

                return [
                    ...$question,
                    'usedFallback' => false,
                ];
            } catch (ValidationException $exception) {
                $lastError = $exception;
            } catch (ServiceUnavailableException $exception) {
                if (($exception->providerStatus ?? null) === 429 && $attempt < $maxAttempts - 1) {
                    usleep((2 ** $attempt) * 1000000);

                    continue;
                }

                throw $exception;
            }
        }

        throw new ServiceUnavailableException(
            $lastError?->getMessage() ?? 'تعذر إعادة توليد السؤال بعد عدة محاولات'
        );
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    private function requestFromProvider(string $prompt, bool $isMultipleQuestions, int $retryCount = 0, int $maxTokens = 0): array
    {
        try {
            $content = $this->ai->chat([
                [
                    'role' => 'system',
                    'content' => $isMultipleQuestions
                        ? 'أنت مساعد تعليمي متخصص في إنشاء أسئلة تعليمية باللغة العربية. قم بإنشاء عدة أسئلة مع إجاباتها بناءً على المادة والفئة المحددة.'
                        : 'أنت مساعد تعليمي متخصص في إنشاء أسئلة تعليمية باللغة العربية. قم بإنشاء سؤال واحد مع إجابته بناءً على المادة والفئة المحددة.',
                ],
                ['role' => 'user', 'content' => $prompt],
            ], [
                'model' => $this->ai->legacyModel(),
                'max_tokens' => $maxTokens > 0 ? $maxTokens : ($isMultipleQuestions ? 800 : 300),
                'temperature' => 0.7,
            ]);

            if ($content === '') {
                throw new ServiceUnavailableException('تعذر الحصول على استجابة من خدمة الذكاء الاصطناعي');
            }

            return $this->parser->parseQuestionResponse($content, $isMultipleQuestions);
        } catch (ServiceUnavailableException $exception) {
            if (($exception->providerStatus ?? null) === 429 && $retryCount < 3) {
                usleep((2 ** $retryCount) * 1000000);

                return $this->requestFromProvider($prompt, $isMultipleQuestions, $retryCount + 1, $maxTokens);
            }

            throw $exception;
        }
    }

    private function requestJsonFromProvider(string $prompt, int $maxTokens): string
    {
        $content = $this->ai->chat([
            [
                'role' => 'system',
                'content' => 'أنت مساعد تعليمي متخصص في إنشاء أسئلة تعليمية باللغة العربية من محتوى مرفوع فقط. أعد JSON صالحاً فقط دون أي نص إضافي.',
            ],
            ['role' => 'user', 'content' => $prompt],
        ], [
            'model' => $this->ai->generationModel(),
            'max_tokens' => $maxTokens,
            'temperature' => 0.4,
            'json' => true,
        ]);

        if ($content === '') {
            throw new ServiceUnavailableException('تعذر الحصول على استجابة من خدمة الذكاء الاصطناعي');
        }

        return $content;
    }
}
