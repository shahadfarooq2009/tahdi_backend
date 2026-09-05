<?php

namespace App\Services\Admin;

use App\Exceptions\ValidationException;
use App\Models\Chapter;
use App\Models\Question;
use App\Models\SchoolCourse;
use App\Models\SchoolGame;
use App\Models\SchoolUnit;
use App\Models\Subject;
use App\Support\ArabicGradeMapper;
use App\Support\QuestionConstants;
use App\Support\Spreadsheet\SimpleXlsxReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchoolExcelImportService
{
    private const INDEX_SHEET_NAMES = ['الفهرس', 'فهرس', 'index', 'Index', 'INDEX'];

    /**
     * @return array<string, mixed>
     */
    public function import(
        UploadedFile $file,
        string $subjectId,
        ?string $educationalStage,
        ?string $gradeArabic,
        string $actorUserId,
        string $actorRole,
        ?string $courseId = null,
    ): array {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new ValidationException('يجب رفع ملف Excel بصيغة xlsx أو csv.');
        }

        $subject = Subject::query()
            ->where('id', $subjectId)
            ->where('is_deleted', false)
            ->first();

        if (! $subject) {
            throw new ValidationException('المادة المحددة غير موجودة.');
        }

        if ($subject->challenge_type !== 'school') {
            throw new ValidationException('يجب اختيار مادة من نوع تحدي المدرسة لاستيراد الوحدات من إكسل.');
        }

        $dbStage = ArabicGradeMapper::stageToDatabase($educationalStage);
        $dbGrade = ArabicGradeMapper::gradeToDatabase($gradeArabic);

        $importSource = trim((string) $file->getClientOriginalName());

        $sheets = $extension === 'csv'
            ? [['name' => 'csv', 'rows' => $this->parseCsv($file->getRealPath() ?: '')]]
            : (new SimpleXlsxReader($file->getRealPath() ?: ''))->sheets();

        $parsedSheets = [];
        $skippedSheets = [];
        $allSheetNames = [];

        foreach ($sheets as $sheet) {
            $sheetName = trim((string) ($sheet['name'] ?? ''));
            $allSheetNames[] = $sheetName;

            if ($this->shouldSkipSheet($sheetName)) {
                $skippedSheets[] = $sheetName;

                continue;
            }

            $unitGame = $this->parseUnitGameFromSheetName($sheetName);

            if ($unitGame === null) {
                $skippedSheets[] = $sheetName;

                continue;
            }

            $rows = $sheet['rows'] ?? [];

            if ($rows === []) {
                continue;
            }

            $parsedSheets[] = [
                'sheet_name' => $sheetName,
                'unit_name' => $unitGame['unit_name'],
                'game_number' => $unitGame['game_number'],
                'questions' => $this->parseQuestionRows($rows),
            ];
        }

        if ($parsedSheets === []) {
            $foundSheets = array_values(array_filter(array_unique($allSheetNames)));
            $sheetHint = $foundSheets !== []
                ? ' الأوراق الموجودة في الملف: '.implode('، ', $foundSheets).'.'
                : '';

            throw new ValidationException(
                'لم يتم التعرف على أوراق صالحة للاستيراد.'.$sheetHint
                .' يجب أن ينتهي اسم كل ورقة برقم اللعبة، مثل: مبادئ وقيم نظام الحكم 1 أو الوحدة1-اللعبة1 أو اسم الوحدة - اللعبة 2 (يُتجاهل الفهرس).'
            );
        }

        $summary = [
            'units_touched' => 0,
            'games_touched' => 0,
            'questions_imported' => 0,
            'skipped_sheets' => $skippedSheets,
            'import_file' => $importSource !== '' ? $importSource : null,
        ];

        DB::transaction(function () use (
            $parsedSheets,
            $subjectId,
            $dbStage,
            $dbGrade,
            $courseId,
            $actorUserId,
            $actorRole,
            $importSource,
            &$summary,
        ) {
            $unitContext = $this->loadUnitContext($subjectId, $dbStage, $dbGrade, $courseId);
            $gamesCache = [];
            $touchedUnitIds = [];
            $affectedGameIds = [];
            $questionRows = [];
            $isAdmin = $actorRole === 'admin';
            $now = now();

            foreach ($parsedSheets as $sheetData) {
                $unit = $this->resolveUnit(
                    $unitContext,
                    $subjectId,
                    $dbStage,
                    $dbGrade,
                    $sheetData['unit_name'],
                    count($touchedUnitIds) + 1,
                    $actorUserId,
                    $courseId,
                );

                if (! in_array($unit->id, $touchedUnitIds, true)) {
                    $touchedUnitIds[] = $unit->id;
                    $summary['units_touched']++;
                }

                $game = $this->resolveGame($gamesCache, $unit, $sheetData['game_number']);
                $affectedGameIds[] = $game->id;

                foreach ($this->buildQuestionRows(
                    $sheetData['questions'],
                    $game,
                    $unit,
                    $subjectId,
                    $dbStage,
                    $dbGrade,
                    $actorUserId,
                    $isAdmin,
                    $now,
                    $importSource,
                ) as $row) {
                    $questionRows[] = $row;
                }

                $summary['games_touched']++;
            }

            if ($affectedGameIds !== []) {
                Question::query()
                    ->whereIn('game_id', array_values(array_unique($affectedGameIds)))
                    ->delete();
            }

            foreach (array_chunk($questionRows, 250) as $chunk) {
                DB::table('questions')->insert($chunk);
            }

            if ($importSource !== '') {
                if ($courseId) {
                    $this->applyImportSourceToCourse($courseId, $importSource);
                } elseif ($touchedUnitIds !== []) {
                    $this->applyImportSourceToUnits($touchedUnitIds, $importSource);
                }
            }

            $summary['questions_imported'] = count($questionRows);
            $summary['import_file'] = $importSource !== '' ? $importSource : null;
        });

        if ($summary['questions_imported'] === 0) {
            throw new ValidationException(
                'تم التعرف على الأوراق لكن لم يُستورد أي سؤال. تأكد من وجود الأعمدة: علامة، السؤال، الجواب، وأن العلامة واحدة من: 100، 200، 300، 400، 500.'
            );
        }

        return $summary;
    }

    /**
     * @return array{import_file: string, units_updated: int, questions_updated: int}
     */
    public function backfillImportSource(
        string $importSource,
        string $subjectId,
        ?string $educationalStage,
        ?string $gradeArabic,
        ?string $courseId = null,
    ): array {
        $importSource = trim($importSource);

        if ($importSource === '') {
            throw new ValidationException('اسم ملف المصدر مطلوب.');
        }

        $subject = Subject::query()
            ->where('id', $subjectId)
            ->where('is_deleted', false)
            ->first();

        if (! $subject) {
            throw new ValidationException('المادة المحددة غير موجودة.');
        }

        if ($courseId) {
            $courseExists = SchoolCourse::query()
                ->where('id', $courseId)
                ->where('parent_subject_id', $subjectId)
                ->exists();

            if (! $courseExists) {
                throw new ValidationException('المقرر المحدد غير موجود تحت هذه المادة.');
            }

            return $this->applyImportSourceToCourse($courseId, $importSource);
        }

        $dbStage = ArabicGradeMapper::stageToDatabase($educationalStage);
        $dbGrade = ArabicGradeMapper::gradeToDatabase($gradeArabic);

        if ($dbStage === null || $dbGrade === null) {
            throw new ValidationException('يجب تحديد المرحلة والصف لتحديث اسم المصدر.');
        }

        $unitIds = SchoolUnit::query()
            ->where('subject_id', $subjectId)
            ->where('educational_stage', $dbStage)
            ->where('grade', $dbGrade)
            ->whereNull('course_id')
            ->pluck('id')
            ->all();

        if ($unitIds === []) {
            throw new ValidationException('لا توجد وحدات مطابقة لتحديث المصدر.');
        }

        return $this->applyImportSourceToUnits($unitIds, $importSource);
    }

    private function shouldSkipSheet(string $sheetName): bool
    {
        $normalized = $this->normalizeUnitKey($sheetName);

        foreach (self::INDEX_SHEET_NAMES as $indexName) {
            if ($normalized === $this->normalizeUnitKey($indexName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{unit_name: string, game_number: int}|null
     */
    private function parseUnitGameFromSheetName(string $sheetName): ?array
    {
        $clean = $this->cleanSheetName($sheetName);

        if ($clean === '') {
            return null;
        }

        if (preg_match(
            '/^(الوحدة|وحدة)\s*([^\d\-–—]+|\d+)\s*[-–—]\s*(?:ال)?لعبة\s*(\d+)\s*$/ui',
            $clean,
            $matches,
        )) {
            $unitName = $this->normalizeUnitTitle(trim($matches[1].' '.trim($matches[2])));

            return [
                'unit_name' => $unitName,
                'game_number' => (int) $matches[3],
            ];
        }

        if (preg_match('/^(.+?)\s*[-–—]\s*(?:ال)?لعبة\s*(\d+)\s*$/ui', $clean, $matches)) {
            $unitName = $this->normalizeUnitTitle(trim($matches[1], " \t\n\r\0\x0B-–—"));

            if ($unitName === '') {
                return null;
            }

            return [
                'unit_name' => $unitName,
                'game_number' => (int) $matches[2],
            ];
        }

        if (preg_match('/^(.+?)(?:\s+|[-–—]+)(\d+)\s*$/u', $clean, $matches)) {
            $unitName = $this->normalizeUnitTitle(trim($matches[1], " \t\n\r\0\x0B-–—"));
            $gameNumber = (int) $matches[2];

            if ($unitName === '' || $gameNumber < 1) {
                return null;
            }

            return [
                'unit_name' => $unitName,
                'game_number' => $gameNumber,
            ];
        }

        return null;
    }

    private function cleanSheetName(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{FEFF}]/u', '', $value) ?? $value;
        $value = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $value) ?? $value;

        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function normalizeUnitTitle(string $name): string
    {
        $name = trim($name);

        return preg_replace('/\s+/u', ' ', $name) ?? $name;
    }

    private function normalizeUnitKey(string $name): string
    {
        $name = mb_strtolower($this->normalizeUnitTitle($name));

        return strtr($name, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ى' => 'ي',
            'ة' => 'ه',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseQuestionRows(array $rows): array
    {
        $headerRow = array_shift($rows);

        if (! is_array($headerRow)) {
            return [];
        }

        $columnMap = $this->mapHeaderColumns($headerRow);
        $questions = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $points = $this->cellValue($row, $columnMap, ['علامة', 'points', 'point', 'score']);
            $questionText = $this->cellValue($row, $columnMap, ['السؤال', 'question', 'question_text']);
            $answerText = $this->cellValue($row, $columnMap, ['الجواب', 'answer', 'answer_text', 'correct_answer']);

            if ($questionText === '' || $answerText === '') {
                continue;
            }

            $pointsValue = (int) preg_replace('/\D+/', '', $points);

            if (! in_array($pointsValue, QuestionConstants::POINT_VALUES, true)) {
                continue;
            }

            $questions[] = [
                'question_text' => $questionText,
                'answer_text' => $answerText,
                'points_value' => $pointsValue,
            ];
        }

        return $questions;
    }

    /**
     * @param  array<int, string>  $headerRow
     * @return array<string, int>
     */
    private function mapHeaderColumns(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $index => $label) {
            $normalized = $this->normalizeHeader((string) $label);

            if ($normalized !== '') {
                $map[$normalized] = $index;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, int>  $columnMap
     * @param  string[]  $aliases
     */
    private function cellValue(array $row, array $columnMap, array $aliases): string
    {
        foreach ($aliases as $alias) {
            $key = $this->normalizeHeader($alias);

            if (isset($columnMap[$key])) {
                return trim((string) ($row[$columnMap[$key]] ?? ''));
            }
        }

        return '';
    }

    private function normalizeHeader(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = str_replace([' ', '_', '-'], '', $value);

        return strtr($value, [
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ى' => 'ي',
            'ة' => 'ه',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
        ]);
    }

    /**
     * @return array{
     *   units_by_key: array<string, SchoolUnit>,
     *   next_unit_number: int,
     *   chapters_by_number: \Illuminate\Support\Collection<int, Chapter>
     * }
     */
    private function loadUnitContext(string $subjectId, ?string $dbStage, ?string $dbGrade, ?string $courseId = null): array
    {
        $unitsQuery = SchoolUnit::query()
            ->where('subject_id', $subjectId)
            ->where('educational_stage', $dbStage)
            ->where('grade', $dbGrade);

        if ($courseId) {
            $unitsQuery->where('course_id', $courseId);
        } else {
            $unitsQuery->whereNull('course_id');
        }

        $units = $unitsQuery->get();

        $unitsByKey = [];

        foreach ($units as $unit) {
            $unitsByKey[$this->normalizeUnitKey($unit->title)] = $unit;
        }

        return [
            'units_by_key' => $unitsByKey,
            'next_unit_number' => max(1, ((int) $units->max('unit_number')) + 1),
            'chapters_by_number' => Chapter::query()
                ->where('subject_id', $subjectId)
                ->get()
                ->keyBy('chapter_number'),
        ];
    }

    /**
     * @param  array{
     *   units_by_key: array<string, SchoolUnit>,
     *   next_unit_number: int,
     *   chapters_by_number: \Illuminate\Support\Collection<int, Chapter>
     * }  $context
     */
    private function resolveUnit(
        array &$context,
        string $subjectId,
        ?string $dbStage,
        ?string $dbGrade,
        string $unitName,
        int $displayOrderHint,
        string $actorUserId,
        ?string $courseId = null,
    ): SchoolUnit {
        $title = $this->normalizeUnitTitle($unitName);
        $unitKey = $this->normalizeUnitKey($title);

        if (isset($context['units_by_key'][$unitKey])) {
            return $context['units_by_key'][$unitKey];
        }

        $unitNumber = $context['next_unit_number'];
        $context['next_unit_number']++;

        $unit = SchoolUnit::query()->create([
            'subject_id' => $subjectId,
            'course_id' => $courseId,
            'educational_stage' => $dbStage,
            'grade' => $dbGrade,
            'unit_number' => $unitNumber,
            'title' => $title,
            'display_order' => $displayOrderHint,
        ]);

        $chapter = $context['chapters_by_number']->get($unitNumber);

        if (! $chapter) {
            $chapter = Chapter::query()->create([
                'subject_id' => $subjectId,
                'name' => $title,
                'chapter_number' => $unitNumber,
                'created_by' => $actorUserId,
            ]);
            $context['chapters_by_number']->put($unitNumber, $chapter);
        } elseif ($chapter->name !== $title) {
            $chapter->update(['name' => $title]);
        }

        if ($unit->chapter_id !== $chapter->id) {
            $unit->update(['chapter_id' => $chapter->id]);
            $unit->chapter_id = $chapter->id;
        }

        $context['units_by_key'][$unitKey] = $unit;

        return $unit;
    }

    /**
     * @param  array<string, array<int, SchoolGame>>  $gamesCache
     */
    private function resolveGame(array &$gamesCache, SchoolUnit $unit, int $gameNumber): SchoolGame
    {
        if (! isset($gamesCache[$unit->id])) {
            $gamesCache[$unit->id] = SchoolGame::query()
                ->where('unit_id', $unit->id)
                ->get()
                ->keyBy('game_number')
                ->all();
        }

        if (isset($gamesCache[$unit->id][$gameNumber])) {
            return $gamesCache[$unit->id][$gameNumber];
        }

        $title = "اللعبة {$gameNumber}";
        $game = SchoolGame::query()->create([
            'unit_id' => $unit->id,
            'game_number' => $gameNumber,
            'title' => $title,
            'display_order' => $gameNumber,
        ]);

        $gamesCache[$unit->id][$gameNumber] = $game;

        return $game;
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<int, array<string, mixed>>
     */
    private function buildQuestionRows(
        array $questions,
        SchoolGame $game,
        SchoolUnit $unit,
        string $subjectId,
        ?string $dbStage,
        ?string $dbGrade,
        string $actorUserId,
        bool $isAdmin,
        \Illuminate\Support\Carbon $now,
        string $importSource = '',
    ): array {
        if ($questions === []) {
            return [];
        }

        $rows = [];

        foreach ($questions as $question) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'subject_id' => $subjectId,
                'category_id' => null,
                'chapter_id' => $unit->chapter_id,
                'game_id' => $game->id,
                'question_text' => $question['question_text'],
                'answer_text' => $question['answer_text'],
                'points_value' => $question['points_value'],
                'choice_options' => null,
                'educational_stage' => $dbStage,
                'grade' => $dbGrade,
                'question_source' => 'excel',
                'import_source' => $importSource !== '' ? $importSource : null,
                'ai_generated' => false,
                'is_active' => true,
                'is_deleted' => false,
                'approval_status' => $isAdmin ? 'approved' : 'pending',
                'approved_by' => $isAdmin ? $actorUserId : null,
                'approved_at' => $isAdmin ? $now : null,
                'submitted_by' => $actorUserId,
                'submitted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parseCsv(string $path): array
    {
        if ($path === '' || ! is_file($path)) {
            return [];
        }

        $rows = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = array_map(fn ($cell) => trim((string) $cell), $data);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  string[]  $unitIds
     * @return array{import_file: string, units_updated: int, questions_updated: int}
     */
    private function applyImportSourceToUnits(array $unitIds, string $importSource): array
    {
        if ($unitIds === []) {
            return [
                'import_file' => $importSource,
                'units_updated' => 0,
                'questions_updated' => 0,
            ];
        }

        $unitsUpdated = SchoolUnit::query()
            ->whereIn('id', $unitIds)
            ->update(['import_source' => $importSource]);

        $gameIds = SchoolGame::query()
            ->whereIn('unit_id', $unitIds)
            ->pluck('id')
            ->all();

        $questionsUpdated = 0;

        if ($gameIds !== []) {
            $questionsUpdated = Question::query()
                ->whereIn('game_id', $gameIds)
                ->where('question_source', 'excel')
                ->update(['import_source' => $importSource]);
        }

        return [
            'import_file' => $importSource,
            'units_updated' => $unitsUpdated,
            'questions_updated' => $questionsUpdated,
        ];
    }

    /**
     * @return array{import_file: string, units_updated: int, questions_updated: int}
     */
    private function applyImportSourceToCourse(string $courseId, string $importSource): array
    {
        SchoolCourse::query()
            ->where('id', $courseId)
            ->update(['import_source' => $importSource]);

        $unitIds = SchoolUnit::query()
            ->where('course_id', $courseId)
            ->pluck('id')
            ->all();

        return $this->applyImportSourceToUnits($unitIds, $importSource);
    }
}
