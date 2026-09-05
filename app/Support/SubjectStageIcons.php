<?php

namespace App\Support;

use App\Exceptions\ValidationException;

final class SubjectStageIcons
{
  public const STAGES = ['primary', 'middle', 'high'];

  /**
   * @param  array<int, string>  $grades
   * @return array<int, string>
   */
  public static function stagesForGrades(array $grades): array
  {
    $stages = [];

    foreach ($grades as $grade) {
      $normalized = Grades::normalize((string) $grade);
      $stage = self::stageForGrade($normalized);

      if ($stage !== null && ! in_array($stage, $stages, true)) {
        $stages[] = $stage;
      }
    }

    return $stages;
  }

  public static function stageForGrade(string $grade): ?string
  {
    $normalized = Grades::normalize($grade) ?? $grade;

    if (preg_match('/^grade_(\d{1,2})$/', $normalized, $matches) !== 1) {
      return null;
    }

    $number = (int) $matches[1];

    if ($number >= 1 && $number <= 6) {
      return 'primary';
    }

    if ($number >= 7 && $number <= 9) {
      return 'middle';
    }

    if ($number >= 10 && $number <= 12) {
      return 'high';
    }

    return null;
  }

  /**
   * @param  array<string, mixed>|null  $stageIcons
   */
  public static function resolveIcon(?string $fallbackIcon, ?array $stageIcons, ?string $stage): ?string
  {
    if (is_string($stage) && $stage !== '' && is_array($stageIcons) && ! empty($stageIcons[$stage])) {
      return (string) $stageIcons[$stage];
    }

    return $fallbackIcon;
  }

  /**
   * @param  array<int, string>  $grades
   * @param  array<string, mixed>|null  $stageIcons
   */
  public static function assertRequiredStageIcons(array $grades, ?array $stageIcons, ?string $fallbackIcon): void
  {
    $requiredStages = self::stagesForGrades($grades);

    if ($requiredStages === []) {
      return;
    }

    $missing = [];

    foreach ($requiredStages as $stage) {
      $icon = self::resolveIcon($fallbackIcon, $stageIcons, $stage);

      if (count($requiredStages) > 1) {
        $icon = is_array($stageIcons) && ! empty($stageIcons[$stage])
          ? (string) $stageIcons[$stage]
          : null;
      }

      if (! is_string($icon) || trim($icon) === '') {
        $missing[] = self::stageLabel($stage);
      }
    }

    if ($missing !== []) {
      throw new ValidationException(
        'يرجى رفع صورة لكل مرحلة دراسية محددة: '.implode('، ', $missing)
      );
    }
  }

  public static function stageLabel(string $stage): string
  {
    return match ($stage) {
      'primary' => 'المرحلة الابتدائية',
      'middle' => 'المرحلة الإعدادية',
      'high' => 'المرحلة الثانوية',
      default => $stage,
    };
  }
}
