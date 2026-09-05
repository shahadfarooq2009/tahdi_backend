<?php

namespace App\Support\Game;

final class WinDetection
{
    private const DIRECTION_PRIORITY = [
        'horizontal' => 0,
        'vertical' => 1,
        'diagonal-right' => 2,
        'diagonal-left' => 3,
    ];

    /**
     * @param  array<int, array<int, array{value: int, team: int|null}>>  $board
     * @param  array<int, string>|null  $usedBonusCells
     * @return array<int, array{team: int, cells: array<int, array{row: int, col: int}>, direction: string}>
     */
    public static function checkForWins(
        array $board,
        int $rows,
        int $cols,
        int $lineLength,
        ?array $usedBonusCells = null,
    ): array {
        $used = $usedBonusCells ? array_flip($usedBonusCells) : [];
        $directions = ['horizontal', 'vertical', 'diagonal-right', 'diagonal-left'];
        $wins = [];

        foreach ($directions as $direction) {
            $wins = array_merge(
                $wins,
                self::scanDirection($board, $rows, $cols, $lineLength, $direction, $used)
            );
        }

        return $wins;
    }

    /**
     * @param  array<int, array{team: int, cells: array<int, array{row: int, col: int}>, direction: string}>  $wins
     * @return array<int, array{team: int, cells: array<int, array{row: int, col: int}>, direction: string}>
     */
    public static function filterNonOverlappingWins(array $wins): array
    {
        $usedCells = [];
        $filtered = [];

        usort($wins, fn ($a, $b) => self::DIRECTION_PRIORITY[$a['direction']] <=> self::DIRECTION_PRIORITY[$b['direction']]);

        foreach ($wins as $win) {
            $winCells = array_map(fn ($cell) => "{$cell['row']},{$cell['col']}", $win['cells']);
            $hasOverlap = collect($winCells)->contains(fn ($key) => isset($usedCells[$key]));

            if (! $hasOverlap) {
                foreach ($winCells as $key) {
                    $usedCells[$key] = true;
                }
                $filtered[] = $win;
            }
        }

        return $filtered;
    }

    public static function createWinId(array $win): string
    {
        $cells = $win['cells'];
        usort($cells, fn ($a, $b) => $a['row'] !== $b['row'] ? $a['row'] <=> $b['row'] : $a['col'] <=> $b['col']);

        return $win['team'].'-'.$win['direction'].'-'.implode('-', array_map(
            fn ($c) => "{$c['row']},{$c['col']}",
            $cells
        ));
    }

    /**
     * @param  array<int, array{team: int, cells: array<int, array{row: int, col: int}>, direction: string}>  $existingWinLines
     * @return array<int, array{team: int, cells: array<int, array{row: int, col: int}>, direction: string}>
     */
    public static function getAllWinLinesForDisplay(
        array $board,
        int $rows,
        int $cols,
        int $lineLength,
        array $existingWinLines = [],
    ): array {
        $usedCells = [];

        foreach ($existingWinLines as $win) {
            foreach ($win['cells'] as $cell) {
                $usedCells["{$cell['row']},{$cell['col']}"] = true;
            }
        }

        $newWins = self::checkForWins($board, $rows, $cols, $lineLength, array_keys($usedCells));
        $newFiltered = self::filterNonOverlappingWins($newWins);

        return array_merge($existingWinLines, $newFiltered);
    }

    /**
     * @param  array<int, array<int, array{value: int, team: int|null}>>  $board
     * @param  array<int, string>  $visitedCells
     * @param  array<int, string>  $unansweredCells
     */
    public static function isBoardComplete(array $board, array $visitedCells, array $unansweredCells): bool
    {
        if ($board === []) {
            return false;
        }

        $visited = array_flip($visitedCells);
        $unanswered = array_flip($unansweredCells);

        foreach ($board as $rowIndex => $row) {
            foreach ($row as $colIndex => $cell) {
                $key = "{$rowIndex},{$colIndex}";
                $hasTeam = $cell['team'] !== null;
                $isVisited = isset($visited[$key]);
                $isUnanswered = isset($unanswered[$key]);

                if (! $hasTeam && ! $isVisited && ! $isUnanswered) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, true>  $usedBonusCells
     * @return array<int, array{team: int, cells: array<int, array{row: int, col: int}>, direction: string}>
     */
    private static function scanDirection(
        array $board,
        int $rows,
        int $cols,
        int $lineLength,
        string $direction,
        array $usedBonusCells,
    ): array {
        $wins = [];
        $hasUsed = fn (array $cells) => collect($cells)->contains(
            fn ($cell) => isset($usedBonusCells["{$cell['row']},{$cell['col']}"])
        );

        if ($direction === 'horizontal') {
            for ($row = 0; $row < $rows; $row++) {
                for ($col = 0; $col <= $cols - $lineLength; $col++) {
                    $team = $board[$row][$col]['team'] ?? null;
                    if ($team === null) {
                        continue;
                    }

                    $matched = true;
                    for ($offset = 1; $offset < $lineLength; $offset++) {
                        if (($board[$row][$col + $offset]['team'] ?? null) !== $team) {
                            $matched = false;
                            break;
                        }
                    }

                    if (! $matched || ! self::isExactlyConnected($board, $row, $col, $direction, $team, $lineLength, $rows, $cols)) {
                        continue;
                    }

                    $cells = array_map(fn ($i) => ['row' => $row, 'col' => $col + $i], range(0, $lineLength - 1));
                    if (! $hasUsed($cells)) {
                        $wins[] = ['team' => $team, 'cells' => $cells, 'direction' => $direction];
                    }
                }
            }

            return $wins;
        }

        if ($direction === 'vertical') {
            for ($row = 0; $row <= $rows - $lineLength; $row++) {
                for ($col = 0; $col < $cols; $col++) {
                    $team = $board[$row][$col]['team'] ?? null;
                    if ($team === null) {
                        continue;
                    }

                    $matched = true;
                    for ($offset = 1; $offset < $lineLength; $offset++) {
                        if (($board[$row + $offset][$col]['team'] ?? null) !== $team) {
                            $matched = false;
                            break;
                        }
                    }

                    if (! $matched || ! self::isExactlyConnected($board, $row, $col, $direction, $team, $lineLength, $rows, $cols)) {
                        continue;
                    }

                    $cells = array_map(fn ($i) => ['row' => $row + $i, 'col' => $col], range(0, $lineLength - 1));
                    if (! $hasUsed($cells)) {
                        $wins[] = ['team' => $team, 'cells' => $cells, 'direction' => $direction];
                    }
                }
            }

            return $wins;
        }

        if ($direction === 'diagonal-right') {
            for ($row = 0; $row <= $rows - $lineLength; $row++) {
                for ($col = 0; $col <= $cols - $lineLength; $col++) {
                    $team = $board[$row][$col]['team'] ?? null;
                    if ($team === null) {
                        continue;
                    }

                    $matched = true;
                    for ($offset = 1; $offset < $lineLength; $offset++) {
                        if (($board[$row + $offset][$col + $offset]['team'] ?? null) !== $team) {
                            $matched = false;
                            break;
                        }
                    }

                    if (! $matched || ! self::isExactlyConnected($board, $row, $col, $direction, $team, $lineLength, $rows, $cols)) {
                        continue;
                    }

                    $cells = array_map(fn ($i) => ['row' => $row + $i, 'col' => $col + $i], range(0, $lineLength - 1));
                    if (! $hasUsed($cells)) {
                        $wins[] = ['team' => $team, 'cells' => $cells, 'direction' => $direction];
                    }
                }
            }

            return $wins;
        }

        for ($row = 0; $row <= $rows - $lineLength; $row++) {
            for ($col = $lineLength - 1; $col < $cols; $col++) {
                $team = $board[$row][$col]['team'] ?? null;
                if ($team === null) {
                    continue;
                }

                $matched = true;
                for ($offset = 1; $offset < $lineLength; $offset++) {
                    if (($board[$row + $offset][$col - $offset]['team'] ?? null) !== $team) {
                        $matched = false;
                        break;
                    }
                }

                if (! $matched || ! self::isExactlyConnected($board, $row, $col, $direction, $team, $lineLength, $rows, $cols)) {
                    continue;
                }

                $cells = array_map(fn ($i) => ['row' => $row + $i, 'col' => $col - $i], range(0, $lineLength - 1));
                if (! $hasUsed($cells)) {
                    $wins[] = ['team' => $team, 'cells' => $cells, 'direction' => $direction];
                }
            }
        }

        return $wins;
    }

    private static function isExactlyConnected(
        array $board,
        int $row,
        int $col,
        string $direction,
        int $team,
        int $lineLength,
        int $rows,
        int $cols,
    ): bool {
        if ($lineLength >= 4) {
            return true;
        }

        return match ($direction) {
            'horizontal' => ! ($col + $lineLength < $cols && ($board[$row][$col + $lineLength]['team'] ?? null) === $team),
            'vertical' => ! ($row + $lineLength < $rows && ($board[$row + $lineLength][$col]['team'] ?? null) === $team),
            'diagonal-right' => ! (
                $row + $lineLength < $rows &&
                $col + $lineLength < $cols &&
                ($board[$row + $lineLength][$col + $lineLength]['team'] ?? null) === $team
            ),
            default => ! (
                $row + $lineLength < $rows &&
                $col - $lineLength >= 0 &&
                ($board[$row + $lineLength][$col - $lineLength]['team'] ?? null) === $team
            ),
        };
    }
}
