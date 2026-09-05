<?php

namespace App\Support\Game;

final class BoardConfig
{
    public const FAMILY = [
        'rows' => 5,
        'cols' => 6,
        'circleValues' => [100, 200, 300, 400, 500, 600],
        'winLineLength' => 4,
        'connectionBonus' => 200,
    ];

    public const SCHOOL = [
        'rows' => 4,
        'cols' => 5,
        'circleValues' => [100, 200, 300, 400, 500],
        'winLineLength' => 3,
        'connectionBonus' => 300,
    ];

    public static function forMode(string $mode): array
    {
        return $mode === 'school' ? self::SCHOOL : self::FAMILY;
    }

    public static function createInitialBoard(string $mode): array
    {
        $config = self::forMode($mode);

        return $mode === 'school'
            ? self::createSchoolBoard($config)
            : self::createFamilyBoard($config);
    }

    public static function cellKey(int $row, int $col): string
    {
        return "{$row},{$col}";
    }

    private static function createFamilyBoard(array $config): array
    {
        $board = [];

        for ($row = 0; $row < $config['rows']; $row++) {
            $boardRow = [];
            for ($col = 0; $col < $config['cols']; $col++) {
                $boardRow[] = [
                    'value' => $config['circleValues'][$row % count($config['circleValues'])],
                    'team' => null,
                ];
            }
            $board[] = $boardRow;
        }

        return $board;
    }

    private static function createSchoolBoard(array $config): array
    {
        $board = [];

        for ($row = 0; $row < $config['rows']; $row++) {
            $boardRow = [];
            for ($col = 0; $col < $config['cols']; $col++) {
                $boardRow[] = [
                    'value' => $config['circleValues'][array_rand($config['circleValues'])],
                    'team' => null,
                ];
            }
            $board[] = $boardRow;
        }

        return $board;
    }
}
