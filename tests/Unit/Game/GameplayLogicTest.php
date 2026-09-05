<?php

namespace Tests\Unit\Game;

use App\Services\Game\GameSessionService;
use App\Support\Game\BoardConfig;
use App\Support\Game\QuestionSelection;
use App\Support\Game\WinDetection;
use Tests\TestCase;

class GameplayLogicTest extends TestCase
{
    public function test_connect_four_detection_for_family_and_school(): void
    {
        $family = $this->buildBoard(BoardConfig::FAMILY['rows'], BoardConfig::FAMILY['cols']);
        for ($col = 0; $col < BoardConfig::FAMILY['winLineLength']; $col++) {
            $family[0][$col]['team'] = 0;
        }
        $familyResult = GameSessionService::verifyConnectFourWin($family, 'family');
        $this->assertNotEmpty($familyResult['wins']);

        $school = $this->buildBoard(BoardConfig::SCHOOL['rows'], BoardConfig::SCHOOL['cols']);
        for ($col = 0; $col < BoardConfig::SCHOOL['winLineLength']; $col++) {
            $school[0][$col]['team'] = 0;
        }
        $schoolResult = GameSessionService::verifyConnectFourWin($school, 'school');
        $this->assertNotEmpty($schoolResult['wins']);
    }

    public function test_answer_protection_payloads(): void
    {
        $question = [
            'id' => 'q-1',
            'question_text' => 'سؤال',
            'answer_text' => 'جواب',
            'explanation' => 'شرح',
            'points_value' => 100,
            'subject_id' => 'subject-1',
        ];

        $safe = QuestionSelection::toSafe($question, [
            'row_position' => 0,
            'col_position' => 0,
            'subject_id' => 'subject-1',
        ]);

        $this->assertArrayNotHasKey('answer_text', $safe);
        $this->assertArrayNotHasKey('explanation', $safe);

        $reveal = QuestionSelection::toReveal($question);
        $this->assertSame('جواب', $reveal['answer_text']);
        $this->assertSame('شرح', $reveal['explanation']);
    }

    public function test_processed_win_ids_block_duplicate_bonus(): void
    {
        $win = [
            'team' => 0,
            'direction' => 'horizontal',
            'cells' => [
                ['row' => 0, 'col' => 0],
                ['row' => 0, 'col' => 1],
                ['row' => 0, 'col' => 2],
                ['row' => 0, 'col' => 3],
            ],
        ];

        $winId = WinDetection::createWinId($win);
        $fresh = array_filter([$win], fn ($candidate) => ! in_array(WinDetection::createWinId($candidate), [$winId], true));

        $this->assertCount(0, $fresh);
    }

    /**
     * @return array<int, array<int, array{value: int, team: int|null}>>
     */
    private function buildBoard(int $rows, int $cols): array
    {
        $board = [];
        for ($row = 0; $row < $rows; $row++) {
            $boardRow = [];
            for ($col = 0; $col < $cols; $col++) {
                $boardRow[] = ['value' => 100, 'team' => null];
            }
            $board[] = $boardRow;
        }

        return $board;
    }
}
