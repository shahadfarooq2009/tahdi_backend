<?php

namespace App\Services\Game;

use App\Exceptions\ConflictException;
use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\Curriculum\ReviewSetUsageService;
use App\Support\Game\BoardConfig;
use App\Support\Game\QuestionSelection;
use App\Support\Game\WinDetection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GameSessionService
{
  private const SCHOOL_POWERUP_EFFECTS = [
    'deductPoints' => ['delta' => -50],
    'freePoints' => ['delta' => 100],
    'teacher' => ['delta' => 0],
    'book' => ['delta' => 0],
    'assistant' => ['delta' => 0],
    'change' => ['delta' => 0],
    'shield' => ['delta' => 0],
    'time' => ['delta' => 0],
  ];

  private const ALLOWED_MANUAL_SCORE_DELTAS = [-100, 100];

  public function __construct(
    private readonly SchoolReviewSetSelectionService $schoolReviewSetSelection,
    private readonly SchoolUnitPlaySelectionService $schoolUnitPlaySelection,
    private readonly SchoolUnitProgressService $schoolUnitProgress,
    private readonly ReviewSetUsageService $reviewSetUsage,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function assertSessionHost(string $sessionId, string $actorUserId): array
  {
    $session = $this->getSessionRecord($sessionId);

    if (($session['host_id'] ?? null) !== $actorUserId) {
      throw new ForbiddenException('You do not have access to this game session');
    }

    return $session;
  }

  /**
   * @param  array<string, mixed>  $payload
   * @return array<string, mixed>
   */
  public function createGameSession(array $payload, string $hostUserId): array
  {
    $mode = $payload['mode'];
    $config = BoardConfig::forMode($mode);
    $sessionCode = (string) Str::uuid();

    $sessionId = (string) Str::uuid();
    DB::table('game_sessions')->insert([
      'id' => $sessionId,
      'host_id' => $hostUserId,
      'session_code' => $sessionCode,
      'status' => 'waiting',
      'challenge_mode' => $mode,
      'class_name' => $payload['class_name'] ?? null,
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $session = $this->getSessionRecord($sessionId);

    $subjectRows = [];
    foreach ($payload['subject_ids'] as $index => $subjectId) {
      $subjectRows[] = [
        'game_session_id' => $session['id'],
        'subject_id' => $subjectId,
        'subject_order' => $index,
      ];
    }
    DB::table('game_session_subjects')->insert($subjectRows);

    $teamRecords = [];
    foreach ($payload['teams'] as $team) {
      $teamId = (string) Str::uuid();
      DB::table('teams')->insert([
        'id' => $teamId,
        'name' => $team['name'],
        'avatar_url' => $team['avatar_url'] ?? null,
        'color' => $team['color'] ?? '#6B46C1',
        'creator_id' => $hostUserId,
        'created_at' => now(),
        'updated_at' => now(),
      ]);

      $insertedTeam = (array) DB::table('teams')->where('id', $teamId)->first();

      DB::table('team_game_progress')->insert([
        'game_session_id' => $session['id'],
        'team_id' => $insertedTeam['id'],
        'current_score' => 0,
        'joined_at' => now(),
      ]);

      $teamRecords[] = $insertedTeam;
    }

    $sessionSubjects = DB::table('game_session_subjects')
      ->join('subjects', 'subjects.id', '=', 'game_session_subjects.subject_id')
      ->where('game_session_subjects.game_session_id', $session['id'])
      ->orderBy('game_session_subjects.subject_order')
      ->select(
        'game_session_subjects.subject_id',
        'game_session_subjects.subject_order',
        'subjects.id as subject_ref_id',
        'subjects.name',
        'subjects.icon_url',
        'subjects.color_hex',
        'subjects.challenge_type',
      )
      ->get()
      ->map(fn ($row) => [
        'subject_id' => $row->subject_id,
        'subject_order' => $row->subject_order,
        'subjects' => [
          'id' => $row->subject_ref_id,
          'name' => $row->name,
          'icon_url' => $row->icon_url,
          'color_hex' => $row->color_hex,
          'challenge_type' => $row->challenge_type,
        ],
      ])
      ->all();

    $questionAssignments = [];
    $sessionMetadata = $payload['metadata'] ?? [];
    $schoolReviewSetUsage = null;

    if ($mode === 'school') {
      $subjectRow = $sessionSubjects[0] ?? null;
      if (! $subjectRow) {
        throw new ValidationException('School mode requires at least one subject');
      }

      $subjectId = $subjectRow['subject_id'];
      $metadata = $payload['metadata'] ?? null;
      $totalCells = $config['rows'] * $config['cols'];
      $gameId = is_string($metadata['game_id'] ?? null) ? trim($metadata['game_id']) : '';
      $manualQuestionCount = $this->countSchoolQuestionsFromBank($subjectId, $metadata);

      if ($gameId !== '' && $manualQuestionCount < $totalCells) {
        throw new ValidationException(
          "لا توجد أسئلة كافية لهذه اللعبة (مطلوب {$totalCells} سؤالاً، المتوفر: {$manualQuestionCount})."
        );
      }

      if ($gameId !== '' && $this->schoolUnitProgress->isGameCompleted($hostUserId, $gameId)) {
        throw new ValidationException('لقد أكملت هذه اللعبة مسبقاً.');
      }

      if ($gameId !== '' && $manualQuestionCount >= $totalCells) {
        $manualAssignments = $this->buildSchoolManualAssignments($subjectId, $metadata, $config);

        foreach ($manualAssignments as $assignment) {
          $questionAssignments[] = [
            'game_session_id' => $session['id'],
            'question_id' => $assignment['question_id'],
            'subject_id' => $subjectId,
            'row_position' => $assignment['row_position'],
            'col_position' => $assignment['col_position'],
            'points_value' => $assignment['points_value'],
          ];
        }

        $sessionMetadata = array_merge($metadata ?? [], [
          'question_source' => 'excel_game',
          'game_id' => $gameId,
        ]);
      } elseif ($manualQuestionCount >= $totalCells) {
        $manualAssignments = $this->buildSchoolManualAssignments($subjectId, $metadata, $config);

        foreach ($manualAssignments as $assignment) {
          $questionAssignments[] = [
            'game_session_id' => $session['id'],
            'question_id' => $assignment['question_id'],
            'subject_id' => $subjectId,
            'row_position' => $assignment['row_position'],
            'col_position' => $assignment['col_position'],
            'points_value' => $assignment['points_value'],
          ];
        }

        $sessionMetadata = array_merge($metadata ?? [], [
          'question_source' => 'manual',
        ]);
      } else {
        $dynamicSession = null;

        try {
          $dynamicSession = $this->schoolUnitPlaySelection->tryResolveDynamicSession(
            $hostUserId,
            $metadata,
            $session['id'],
            $config,
          );
        } catch (ValidationException $exception) {
          throw $exception;
        } catch (\Throwable) {
          $dynamicSession = null;
        }

        if ($dynamicSession) {
          $sessionMetadata = $dynamicSession['metadata'];

          foreach ($dynamicSession['assignments'] as $assignment) {
            $questionAssignments[] = [
              'game_session_id' => $session['id'],
              'question_id' => $assignment['question_id'],
              'subject_id' => $subjectId,
              'row_position' => $assignment['row_position'],
              'col_position' => $assignment['col_position'],
              'points_value' => $assignment['points_value'],
            ];
          }
        } else {
        try {
          $schoolSession = $this->schoolReviewSetSelection->resolveSchoolReviewSetSession(
            $subjectId,
            $hostUserId,
            $metadata,
            $payload['class_name'] ?? null,
          );

          $sessionMetadata = $schoolSession['metadata'];
          $schoolReviewSetUsage = [
            'reviewSetId' => $schoolSession['reviewSet']['id'],
            'textbookId' => $schoolSession['context']['textbook']['id'],
            'unitKey' => $schoolSession['context']['unit_key'],
          ];

          foreach ($schoolSession['assignments'] as $assignment) {
            $questionAssignments[] = [
              'game_session_id' => $session['id'],
              'question_id' => $assignment['question_id'],
              'subject_id' => $subjectId,
              'row_position' => $assignment['row_position'],
              'col_position' => $assignment['col_position'],
              'points_value' => $assignment['points_value'],
            ];
          }
        } catch (ValidationException) {
          $manualAssignments = $this->buildSchoolManualAssignments(
            $subjectId,
            $metadata,
            $config,
          );

          foreach ($manualAssignments as $assignment) {
            $questionAssignments[] = [
              'game_session_id' => $session['id'],
              'question_id' => $assignment['question_id'],
              'subject_id' => $subjectId,
              'row_position' => $assignment['row_position'],
              'col_position' => $assignment['col_position'],
              'points_value' => $assignment['points_value'],
            ];
          }

          $sessionMetadata = array_merge($metadata ?? [], [
            'question_source' => 'manual',
          ]);
        }
        }
      }
    } else {
      for ($row = 0; $row < min(count($sessionSubjects), $config['rows']); $row++) {
        $subjectRow = $sessionSubjects[$row];
        $subjectId = $subjectRow['subject_id'];
        $questions = $this->fetchSubjectQuestions($subjectId, $mode, $payload['metadata'] ?? null);
        $selected = QuestionSelection::selectForBoard($questions);

        for ($col = 0; $col < min(count($selected), $config['cols']); $col++) {
          $question = $selected[$col];
          $questionAssignments[] = [
            'game_session_id' => $session['id'],
            'question_id' => $question['id'],
            'subject_id' => $subjectId,
            'row_position' => $row,
            'col_position' => $col,
            'points_value' => $question['points_value'] ?? 100,
          ];
        }
      }
    }

    if ($questionAssignments !== []) {
      DB::table('game_session_questions')->insert($questionAssignments);
    }

    if ($schoolReviewSetUsage) {
      $this->reviewSetUsage->recordReviewSetUsage(
        $schoolReviewSetUsage['reviewSetId'],
        $schoolReviewSetUsage['textbookId'],
        $schoolReviewSetUsage['unitKey'],
        $hostUserId,
        $payload['class_name'] ?? '',
        $session['id'],
      );
    }

    $board = BoardConfig::createInitialBoard($mode);
    foreach ($questionAssignments as $assignment) {
      $board[$assignment['row_position']][$assignment['col_position']]['value'] = $assignment['points_value'];
    }

    DB::table('game_session_state')->insert([
      'game_session_id' => $session['id'],
      'mode' => $mode,
      'class_name' => $payload['class_name'] ?? null,
      'metadata' => json_encode($sessionMetadata),
      'board' => json_encode($board),
      'visited_cells' => json_encode([]),
      'unanswered_cells' => json_encode([]),
      'active_team_index' => 0,
      'win_lines' => json_encode([]),
      'processed_wins' => json_encode([]),
      'used_bonus_cells' => json_encode([]),
      'doubled_teams' => json_encode($payload['metadata']['doubled_teams'] ?? []),
      'updated_at' => now(),
    ]);

    return $this->getGameSession($session['id'], $hostUserId);
  }

  /**
   * @param  array<int, array{name: string, avatar_url?: string|null, color?: string|null}>  $teams
   * @param  array<string, mixed>  $metadata
   * @param  array<int, array{question_id?: string|null, user_play_question_id?: string|null, subject_id?: string|null, row_position: int, col_position: int, points_value: int}>  $questionAssignments
   * @return array<string, mixed>
   */
  public function createPlaySetGameSession(
    string $hostUserId,
    string $className,
    array $teams,
    array $metadata,
    array $questionAssignments,
  ): array {
    $mode = 'school';
    $sessionId = (string) Str::uuid();
    $sessionCode = (string) Str::uuid();

    DB::table('game_sessions')->insert([
      'id' => $sessionId,
      'host_id' => $hostUserId,
      'session_code' => $sessionCode,
      'status' => 'in_progress',
      'challenge_mode' => $mode,
      'class_name' => $className,
      'started_at' => now()->toIso8601String(),
      'created_at' => now(),
      'updated_at' => now(),
    ]);

    $teamRows = [];
    $progressRows = [];
    $joinedAt = now();

    foreach ($teams as $team) {
      $teamId = (string) Str::uuid();
      $teamRows[] = [
        'id' => $teamId,
        'name' => $team['name'],
        'avatar_url' => $team['avatar_url'] ?? null,
        'color' => $team['color'] ?? '#6B46C1',
        'creator_id' => $hostUserId,
        'created_at' => $joinedAt,
        'updated_at' => $joinedAt,
      ];

      $progressRows[] = [
        'game_session_id' => $sessionId,
        'team_id' => $teamId,
        'current_score' => 0,
        'joined_at' => $joinedAt,
      ];
    }

    if ($teamRows !== []) {
      DB::table('teams')->insert($teamRows);
      DB::table('team_game_progress')->insert($progressRows);
    }

    $supportsDirectQuestions = $this->supportsDirectUserPlayQuestions();
    $sessionQuestionRows = array_map(
      function (array $assignment) use ($sessionId, $supportsDirectQuestions) {
        $row = [
        'game_session_id' => $sessionId,
        'question_id' => $assignment['question_id'] ?? null,
        'subject_id' => $assignment['subject_id'] ?? null,
        'row_position' => $assignment['row_position'],
        'col_position' => $assignment['col_position'],
        'points_value' => $assignment['points_value'],
        ];

        if ($supportsDirectQuestions) {
          $row['user_play_question_id'] = $assignment['user_play_question_id'] ?? null;
        }

        return $row;
      },
      $questionAssignments,
    );

    if ($sessionQuestionRows !== []) {
      DB::table('game_session_questions')->insert($sessionQuestionRows);
    }

    $board = BoardConfig::createInitialBoard($mode);
    foreach ($questionAssignments as $assignment) {
      $board[$assignment['row_position']][$assignment['col_position']]['value'] = $assignment['points_value'];
    }

    DB::table('game_session_state')->insert([
      'game_session_id' => $sessionId,
      'mode' => $mode,
      'class_name' => $className,
      'metadata' => json_encode($metadata),
      'board' => json_encode($board),
      'visited_cells' => json_encode([]),
      'unanswered_cells' => json_encode([]),
      'active_team_index' => 0,
      'win_lines' => json_encode([]),
      'processed_wins' => json_encode([]),
      'used_bonus_cells' => json_encode([]),
      'doubled_teams' => json_encode([]),
      'updated_at' => now(),
    ]);

    return $this->getGameSession($sessionId, $hostUserId);
  }

  /**
   * @return array<string, mixed>
   */
  public function getGameSession(string $sessionId, string $actorUserId): array
  {
    $this->assertSessionHost($sessionId, $actorUserId);

    return $this->buildSessionPayload($sessionId);
  }

  /**
   * @param  array<string, mixed>  $payload
   * @return array<string, mixed>
   */
  public function updateGameSession(string $sessionId, array $payload, string $actorUserId): array
  {
    $this->assertSessionHost($sessionId, $actorUserId);

    $updateData = [];

    if (! empty($payload['status'])) {
      $updateData['status'] = $payload['status'];
    }

    if (($payload['status'] ?? null) === 'in_progress') {
      $updateData['started_at'] = now()->toIso8601String();
    }

    if ($updateData !== []) {
      $updateData['updated_at'] = now();
      DB::table('game_sessions')->where('id', $sessionId)->update($updateData);
    }

    if (array_key_exists('active_team_index', $payload)) {
      $this->persistStatePatch($sessionId, ['active_team_index' => $payload['active_team_index']]);
    }

    if (! empty($payload['metadata']) && is_array($payload['metadata'])) {
      $state = $this->getSessionState($sessionId);
      $this->persistStatePatch($sessionId, [
        'metadata' => array_merge($state['metadata'] ?? [], $payload['metadata']),
      ]);
    }

    return $this->getGameSession($sessionId, $actorUserId);
  }

  /**
   * @param  array<string, mixed>  $options
   * @return array<string, mixed>
   */
  public function finishGameSession(string $sessionId, string $actorUserId, array $options = []): array
  {
    $this->assertSessionHost($sessionId, $actorUserId);

    $payload = $this->buildSessionPayload($sessionId);
    $scores = $payload['scores'];
    $maxScore = max($scores === [] ? [0] : $scores);
    $winnerIndex = array_search($maxScore, $scores, true);
    $winnerTeam = ($winnerIndex !== false && $winnerIndex >= 0) ? $payload['teams'][$winnerIndex] : null;

    DB::table('game_sessions')->where('id', $sessionId)->update([
      'status' => 'completed',
      'ended_at' => now()->toIso8601String(),
      'winner_team_id' => $options['winner_team_id'] ?? $winnerTeam['id'] ?? null,
      'updated_at' => now(),
    ]);

    $this->recordSchoolGameCompletionIfNeeded($sessionId, $actorUserId, $payload);

    return array_merge($payload, [
      'winner' => $winnerTeam
        ? [
          'team_id' => $winnerTeam['id'],
          'team_index' => $winnerIndex,
          'score' => $maxScore,
        ]
        : null,
      'completion_reason' => $options['completion_reason'] ?? 'manual',
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function claimBoardTile(string $sessionId, int $row, int $col, string $actorUserId): array
  {
    $this->assertSessionHost($sessionId, $actorUserId);
    $state = $this->getSessionState($sessionId);
    $config = BoardConfig::forMode($state['mode']);
    $key = "{$row},{$col}";

    if ($row < 0 || $col < 0 || $row >= $config['rows'] || $col >= $config['cols']) {
      throw new ValidationException('Invalid board position');
    }

    $board = $this->deepClone($state['board']);
    if (($board[$row][$col]['team'] ?? null) !== null) {
      throw new ConflictException('Tile is already claimed');
    }

    if (in_array($key, $state['unanswered_cells'], true)) {
      throw new ConflictException('Tile is permanently blocked');
    }

    $visited = in_array($key, $state['visited_cells'], true)
      ? $state['visited_cells']
      : [...$state['visited_cells'], $key];

    $assignment = $this->getQuestionAssignment($sessionId, $row, $col);

    $this->persistStatePatch($sessionId, [
      'board' => $board,
      'visited_cells' => $visited,
    ]);

    return [
      'row' => $row,
      'col' => $col,
      'question_id' => $this->resolveAssignmentQuestionId($assignment),
      'points_value' => $assignment['points_value'],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function assignSchoolTileTeam(
    string $sessionId,
    int $row,
    int $col,
    int $teamIndex,
    string $actorUserId,
  ): array {
    $this->assertSessionHost($sessionId, $actorUserId);
    $state = $this->getSessionState($sessionId);

    if ($state['mode'] !== 'school') {
      throw new ValidationException('This action is only available in school mode');
    }

    $config = BoardConfig::forMode('school');
    $key = "{$row},{$col}";

    if ($row < 0 || $col < 0 || $row >= $config['rows'] || $col >= $config['cols']) {
      throw new ValidationException('Invalid board position');
    }

    $board = $this->deepClone($state['board']);
    if (($board[$row][$col]['team'] ?? null) !== null) {
      throw new ConflictException('Tile is already assigned');
    }

    if (in_array($key, $state['unanswered_cells'], true)) {
      throw new ConflictException('Tile is permanently blocked');
    }

    if ($teamIndex !== $state['active_team_index']) {
      throw new ValidationException('It is not this team\'s turn');
    }

    $assignment = $this->getQuestionAssignment($sessionId, $row, $col);
    $questionId = $this->resolveAssignmentQuestionId($assignment);
    $this->assertQuestionNotAnswered($sessionId, $questionId);

    $teamsProgress = DB::table('team_game_progress')
      ->where('game_session_id', $sessionId)
      ->orderBy('joined_at')
      ->get()
      ->map(fn ($row) => (array) $row)
      ->all();

    if ($teamIndex < 0 || $teamIndex >= count($teamsProgress)) {
      throw new ValidationException('Invalid team_index');
    }

    $metadata = array_merge($state['metadata'] ?? [], [
      'pending_question' => [
        'row' => $row,
        'col' => $col,
        'team_index' => $teamIndex,
      ],
    ]);

    $this->persistStatePatch($sessionId, [
      'metadata' => $metadata,
    ]);

    $updated = $this->buildSessionPayload($sessionId);

    return array_merge($updated, [
      'row' => $row,
      'col' => $col,
      'team_index' => $teamIndex,
      'question_id' => $questionId,
      'cell_points_earned' => 0,
      'new_win_lines' => [],
    ]);
  }

  /**
   * @param  array<string, mixed>  $payload
   * @return array<string, mixed>
   */
  public function submitQuestionOutcome(
    string $sessionId,
    string $questionId,
    array $payload,
    string $actorUserId,
  ): array {
    $this->assertSessionHost($sessionId, $actorUserId);
    $assignment = $this->assertQuestionInSession($sessionId, $questionId);
    $this->assertQuestionNotAnswered($sessionId, $questionId);

    $state = $this->getSessionState($sessionId);
    $isSchool = $state['mode'] === 'school';
    $isUserPlaySet = ($state['metadata']['question_source'] ?? null) === 'user_play_set';
    $config = BoardConfig::forMode($state['mode']);
    $row = (int) ($payload['row'] ?? $assignment['row_position']);
    $col = (int) ($payload['col'] ?? $assignment['col_position']);
    $key = "{$row},{$col}";

    if ((int) $assignment['row_position'] !== $row || (int) $assignment['col_position'] !== $col) {
      throw new ValidationException('Question does not match the selected tile');
    }

    $teamsProgress = DB::table('team_game_progress')
      ->where('game_session_id', $sessionId)
      ->orderBy('joined_at')
      ->get()
      ->map(fn ($row) => (array) $row)
      ->all();

    $board = $this->deepClone($state['board']);
    $visited = $state['visited_cells'];
    $unanswered = $state['unanswered_cells'];
    $winLines = $state['win_lines'];
    $processedWins = $state['processed_wins'];
    $usedBonusCells = $state['used_bonus_cells'] ?? [];
    $activeTeamIndex = $state['active_team_index'];
    $doubledTeams = $state['doubled_teams'] ?? [];

    $outcome = $payload['outcome'] ?? null;
    if ($outcome !== 'correct' && $outcome !== 'no_answer') {
      throw new ValidationException('Invalid outcome');
    }

    if (! $isSchool && ($board[$row][$col]['team'] ?? null) !== null) {
      throw new ConflictException('Tile is already claimed');
    }

    if (
      $isSchool
      && ! $isUserPlaySet
    ) {
      $pendingQuestion = $state['metadata']['pending_question'] ?? null;
      if (
        ! is_array($pendingQuestion)
        || (int) ($pendingQuestion['row'] ?? -1) !== $row
        || (int) ($pendingQuestion['col'] ?? -1) !== $col
      ) {
        throw new ConflictException('No open question for this tile');
      }
    }

    if (in_array($key, $unanswered, true) && $outcome === 'correct') {
      throw new ConflictException('Tile is permanently blocked');
    }

    $question = $this->loadQuestionRecord($questionId, $assignment);
    $awardedTeamIndex = null;
    $pointsEarned = 0;
    $teamIdForLog = null;
    $freshWins = [];

    if ($outcome === 'correct') {
      $teamIndex = $payload['team_index'] ?? null;
      if (
        ! is_numeric($teamIndex) ||
        (int) $teamIndex != $teamIndex ||
        (int) $teamIndex < 0 ||
        (int) $teamIndex >= count($teamsProgress)
      ) {
        throw new ValidationException('Invalid team_index');
      }
      $teamIndex = (int) $teamIndex;

      $awardedTeamIndex = $teamIndex;
      $basePoints = $assignment['points_value'] ?? $question['points_value'] ?? 100;
      $pointsEarned = in_array($teamIndex, $doubledTeams, true) ? $basePoints * 2 : $basePoints;

      $board[$row][$col]['team'] = $teamIndex;
      $teamIdForLog = $teamsProgress[$teamIndex]['team_id'];

      $progress = $teamsProgress[$teamIndex];
      DB::table('team_game_progress')
        ->where('id', $progress['id'])
        ->update([
          'current_score' => ($progress['current_score'] ?? 0) + $pointsEarned,
          'questions_answered' => ($progress['questions_answered'] ?? 0) + 1,
          'correct_answers' => ($progress['correct_answers'] ?? 0) + 1,
          'last_answer_at' => now()->toIso8601String(),
        ]);
      $teamsProgress[$teamIndex]['current_score'] = ($progress['current_score'] ?? 0) + $pointsEarned;

      $unanswered = array_values(array_filter($unanswered, fn ($cell) => $cell !== $key));
      if (! in_array($key, $visited, true)) {
        $visited[] = $key;
      }

      if ($isSchool && ! $isUserPlaySet) {
        $newWins = WinDetection::getAllWinLinesForDisplay(
          $board,
          $config['rows'],
          $config['cols'],
          $config['winLineLength'],
          $winLines,
        );

        $freshWins = array_values(array_filter(
          $newWins,
          fn ($win) => ! collect($winLines)->contains(
            fn ($existing) => WinDetection::createWinId($existing) === WinDetection::createWinId($win)
          ),
        ));

        foreach ($freshWins as $win) {
          $winId = WinDetection::createWinId($win);
          if (in_array($winId, $processedWins, true)) {
            continue;
          }

          $bonus = $config['connectionBonus'];
          $progressRow = $teamsProgress[$win['team']] ?? null;
          if ($progressRow) {
            $bonusScore = ($progressRow['current_score'] ?? 0) + $bonus;
            DB::table('team_game_progress')
              ->where('id', $progressRow['id'])
              ->update([
                'current_score' => $bonusScore,
                'connections_made' => ($progressRow['connections_made'] ?? 0) + 1,
              ]);
            $teamsProgress[$win['team']]['current_score'] = $bonusScore;
            if ($win['team'] === $awardedTeamIndex) {
              $pointsEarned += $bonus;
            }
          }

          foreach ($win['cells'] as $cell) {
            $usedBonusCells[] = "{$cell['row']},{$cell['col']}";
          }
          $processedWins[] = $winId;
        }

        $winLines = $newWins;
        $activeTeamIndex = ($state['active_team_index'] + 1) % count($teamsProgress);
      } elseif (! $isSchool) {
        $newWins = WinDetection::getAllWinLinesForDisplay(
          $board,
          $config['rows'],
          $config['cols'],
          $config['winLineLength'],
          $winLines,
        );

        $freshWins = array_values(array_filter(
          $newWins,
          fn ($win) => ! collect($winLines)->contains(
            fn ($existing) => WinDetection::createWinId($existing) === WinDetection::createWinId($win)
          ),
        ));

        foreach ($freshWins as $win) {
          $winId = WinDetection::createWinId($win);
          if (in_array($winId, $processedWins, true)) {
            continue;
          }

          $bonus = $config['connectionBonus'];
          $progressRow = $teamsProgress[$win['team']] ?? null;
          if ($progressRow) {
            DB::table('team_game_progress')
              ->where('id', $progressRow['id'])
              ->update([
                'current_score' => ($progressRow['current_score'] ?? 0) + $bonus,
                'connections_made' => ($progressRow['connections_made'] ?? 0) + 1,
              ]);
            if ($win['team'] === $awardedTeamIndex) {
              $pointsEarned += $bonus;
            }
          }

          foreach ($win['cells'] as $cell) {
            $usedBonusCells[] = "{$cell['row']},{$cell['col']}";
          }
          $processedWins[] = $winId;
        }

        $winLines = $newWins;
        $activeTeamIndex = ($teamIndex + 1) % count($teamsProgress);
      }
    } else {
      if (! in_array($key, $unanswered, true)) {
        $unanswered[] = $key;
      }
      if (! in_array($key, $visited, true)) {
        $visited[] = $key;
      }
      if ($isSchool && ! $isUserPlaySet) {
        $board[$row][$col]['team'] = null;
        $activeTeamIndex = ($state['active_team_index'] + 1) % count($teamsProgress);
      } elseif (! $isSchool) {
        $activeTeamIndex = ($state['active_team_index'] + 1) % count($teamsProgress);
      }
    }

    $metadata = $state['metadata'] ?? [];
    if ($isSchool && ! $isUserPlaySet) {
      unset($metadata['pending_question']);
    }

    $answerRow = $this->buildQuestionAnswerRow(
      $sessionId,
      $questionId,
      $assignment,
      $teamIdForLog,
      $outcome,
      $pointsEarned,
    );

    DB::table('question_answers')->insert($answerRow);

    $this->persistStatePatch($sessionId, [
      'board' => $board,
      'visited_cells' => $visited,
      'unanswered_cells' => $unanswered,
      'active_team_index' => $activeTeamIndex,
      'win_lines' => $winLines,
      'processed_wins' => $processedWins,
      'used_bonus_cells' => $usedBonusCells,
      ...(($isSchool && ! $isUserPlaySet) ? ['metadata' => $metadata] : []),
    ]);

    $updated = $this->buildSessionPayload($sessionId);
    $completed = WinDetection::isBoardComplete($board, $visited, $unanswered);

    if ($completed && ($updated['session']['status'] ?? null) !== 'completed') {
      $this->finishGameSession($sessionId, $actorUserId, ['completion_reason' => 'board_complete']);
    }

    return array_merge($updated, [
      'outcome' => $outcome,
      'awarded_team_index' => $awardedTeamIndex,
      'points_earned' => $pointsEarned,
      'row' => $row,
      'col' => $col,
      'board_complete' => $completed,
      'new_win_lines' => $freshWins,
    ]);
  }

  /**
   * @param  array<string, mixed>  $payload
   * @return array<string, mixed>
   */
  public function applySchoolPowerup(string $sessionId, array $payload, string $actorUserId): array
  {
    $this->assertSessionHost($sessionId, $actorUserId);
    $state = $this->getSessionState($sessionId);

    if ($state['mode'] !== 'school') {
      throw new ValidationException('Power-ups are only available in school mode');
    }

    $powerupId = $payload['powerup_id'] ?? null;
    $teamIndexRaw = $payload['team_index'] ?? null;
    $effect = self::SCHOOL_POWERUP_EFFECTS[$powerupId] ?? null;

    if (! $effect) {
      throw new ValidationException('Invalid or unsupported power-up');
    }

    if (
      ! is_numeric($teamIndexRaw) ||
      (int) $teamIndexRaw != $teamIndexRaw ||
      (int) $teamIndexRaw < 0
    ) {
      throw new ValidationException('Invalid team_index');
    }
    $teamIndex = (int) $teamIndexRaw;

    $teamsProgress = DB::table('team_game_progress')
      ->where('game_session_id', $sessionId)
      ->orderBy('joined_at')
      ->get()
      ->map(fn ($row) => (array) $row)
      ->all();

    if ($teamIndex >= count($teamsProgress)) {
      throw new ValidationException('Invalid team_index');
    }

    $usedPowerups = $state['metadata']['used_powerups_by_team'] ?? [];
    $teamRow = $teamsProgress[$teamIndex];
    $teamId = $teamRow['team_id'];
    $teamUsed = is_array($usedPowerups[$teamId] ?? null) ? $usedPowerups[$teamId] : [];

    if (in_array($powerupId, $teamUsed, true)) {
      throw new ConflictException('Power-up already used by this team');
    }

    $doubledTeams = $state['doubled_teams'] ?? [];
    $delta = $effect['delta'];
    if ($delta > 0 && in_array($teamIndex, $doubledTeams, true)) {
      $delta *= 2;
    }

    if ($delta !== 0) {
      $nextScore = max(0, ($teamRow['current_score'] ?? 0) + $delta);
      DB::table('team_game_progress')
        ->where('id', $teamRow['id'])
        ->update(['current_score' => $nextScore]);
    }

    $this->persistStatePatch($sessionId, [
      'metadata' => array_merge($state['metadata'] ?? [], [
        'used_powerups_by_team' => array_merge($usedPowerups, [
          $teamId => [...$teamUsed, $powerupId],
        ]),
      ]),
    ]);

    $updated = $this->buildSessionPayload($sessionId);

    return array_merge($updated, [
      'powerup_id' => $powerupId,
      'team_index' => $teamIndex,
      'points_delta' => $delta,
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function adjustTeamScore(string $sessionId, int $teamIndex, int $delta, string $actorUserId): array
  {
    $this->assertSessionHost($sessionId, $actorUserId);

    if (! in_array($delta, self::ALLOWED_MANUAL_SCORE_DELTAS, true)) {
      throw new ValidationException('Invalid score adjustment amount');
    }

    $teamsProgress = DB::table('team_game_progress')
      ->where('game_session_id', $sessionId)
      ->orderBy('joined_at')
      ->get()
      ->map(fn ($row) => (array) $row)
      ->all();

    if ($teamIndex < 0 || $teamIndex >= count($teamsProgress)) {
      throw new ValidationException('Invalid team_index');
    }

    $progress = $teamsProgress[$teamIndex];
    $nextScore = max(0, ($progress['current_score'] ?? 0) + $delta);

    DB::table('team_game_progress')
      ->where('id', $progress['id'])
      ->update(['current_score' => $nextScore]);

    return $this->buildSessionPayload($sessionId);
  }

  /**
   * @return array<int, array<string, mixed>>
   */
  public function listSafeSessionQuestions(string $sessionId, string $actorUserId): array
  {
    $this->assertSessionHost($sessionId, $actorUserId);
    $state = $this->getSessionState($sessionId);
    $playSetId = $state['metadata']['play_set_id'] ?? null;
    $isUserPlaySet = ($state['metadata']['question_source'] ?? null) === 'user_play_set';

    if (! $this->supportsDirectUserPlayQuestions()) {
      $rows = DB::table('game_session_questions as gsq')
        ->join('questions', 'questions.id', '=', 'gsq.question_id')
        ->where('gsq.game_session_id', $sessionId)
        ->orderBy('gsq.row_position')
        ->orderBy('gsq.col_position')
        ->select(
          'gsq.row_position',
          'gsq.col_position',
          'gsq.points_value as assignment_points_value',
          'gsq.subject_id',
          'questions.id',
          'questions.question_text',
          'questions.answer_text',
          'questions.answer_image_url',
          'questions.explanation',
          'questions.image_url',
          'questions.question_type',
          'questions.chapter_id',
          'questions.category_id',
          'questions.grade',
          'questions.choice_options',
          'questions.points_value as question_points_value',
        )
        ->get();

      return $rows->map(fn ($row) => [
        'id' => $row->id,
        'question_text' => $row->question_text,
        'answer_text' => $row->answer_text ?? '',
        'answer_image_url' => $row->answer_image_url ?? null,
        'explanation' => $row->explanation ?? null,
        'points_value' => $row->assignment_points_value ?? $row->question_points_value,
        'image_url' => $row->image_url ?? null,
        'question_type' => $row->question_type ?? null,
        'subject_id' => $isUserPlaySet && $playSetId ? $playSetId : $row->subject_id,
        'row' => $row->row_position,
        'col' => $row->col_position,
        'chapter_id' => $row->chapter_id ?? null,
        'category_id' => $row->category_id ?? null,
        'grade' => $row->grade ?? null,
        'unit' => null,
        'choice_options' => $this->decodeChoiceOptions($row->choice_options ?? null),
      ])->all();
    }

    $rows = DB::table('game_session_questions as gsq')
      ->leftJoin('questions', 'questions.id', '=', 'gsq.question_id')
      ->leftJoin('user_play_questions as upq', 'upq.id', '=', 'gsq.user_play_question_id')
      ->where('gsq.game_session_id', $sessionId)
      ->orderBy('gsq.row_position')
      ->orderBy('gsq.col_position')
      ->select(
        'gsq.row_position',
        'gsq.col_position',
        'gsq.points_value as assignment_points_value',
        'gsq.subject_id',
        'questions.id as question_id',
        'questions.question_text as bank_question_text',
        'questions.answer_text as bank_answer_text',
        'questions.answer_image_url as bank_answer_image_url',
        'questions.explanation as bank_explanation',
        'questions.image_url as bank_image_url',
        'questions.question_type as bank_question_type',
        'questions.chapter_id',
        'questions.category_id',
        'questions.grade',
        'questions.choice_options as bank_choice_options',
        'questions.points_value as question_points_value',
        'upq.id as user_play_question_id',
        'upq.question_text as play_question_text',
        'upq.answer_text as play_answer_text',
        'upq.points_value as play_question_points_value',
      )
      ->get();

    return $rows->map(function ($row) use ($isUserPlaySet, $playSetId) {
      $isPlaySetQuestion = is_string($row->user_play_question_id) && $row->user_play_question_id !== '';

      return [
        'id' => $isPlaySetQuestion ? $row->user_play_question_id : $row->question_id,
        'question_text' => $isPlaySetQuestion ? $row->play_question_text : $row->bank_question_text,
        'answer_text' => $isPlaySetQuestion ? ($row->play_answer_text ?? '') : ($row->bank_answer_text ?? ''),
        'answer_image_url' => $isPlaySetQuestion ? null : ($row->bank_answer_image_url ?? null),
        'explanation' => $isPlaySetQuestion ? null : ($row->bank_explanation ?? null),
        'points_value' => $row->assignment_points_value
          ?? ($isPlaySetQuestion ? $row->play_question_points_value : $row->question_points_value),
        'image_url' => $isPlaySetQuestion ? null : ($row->bank_image_url ?? null),
        'question_type' => $isPlaySetQuestion ? null : ($row->bank_question_type ?? null),
        'subject_id' => $isUserPlaySet && $playSetId ? $playSetId : $row->subject_id,
        'row' => $row->row_position,
        'col' => $row->col_position,
        'chapter_id' => $isPlaySetQuestion ? null : ($row->chapter_id ?? null),
        'category_id' => $isPlaySetQuestion ? null : ($row->category_id ?? null),
        'grade' => $isPlaySetQuestion ? null : ($row->grade ?? null),
        'unit' => null,
        'choice_options' => $isPlaySetQuestion ? null : $this->decodeChoiceOptions($row->bank_choice_options ?? null),
      ];
    })->all();
  }

  /**
   * @return array<string, mixed>
   */
  public function revealSessionQuestionAnswer(string $sessionId, string $questionId, string $actorUserId): array
  {
    $this->assertSessionHost($sessionId, $actorUserId);
    $assignment = $this->assertQuestionInSession($sessionId, $questionId);
    $question = $this->loadQuestionRecord($questionId, $assignment);

    return [
      'id' => $question['id'],
      'answer_text' => $question['answer_text'] ?? '',
      'answer_image_url' => $question['answer_image_url'] ?? null,
      'explanation' => $question['explanation'] ?? null,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function getPublicSessionQuestion(string $questionId, string $sessionId): array
  {
    $this->assertQuestionRevealAllowed($questionId, $sessionId);

    if (! $this->supportsDirectUserPlayQuestions()) {
      $assignment = DB::table('game_session_questions')
        ->where('game_session_id', $sessionId)
        ->where('question_id', $questionId)
        ->first();

      if (! $assignment) {
        throw new NotFoundException('Question does not belong to this session');
      }

      $question = DB::table('questions')->where('id', $questionId)->first();
      if (! $question) {
        throw new NotFoundException('Question not found');
      }

      return QuestionSelection::toSafe((array) $question, [
        'row_position' => $assignment->row_position,
        'col_position' => $assignment->col_position,
        'subject_id' => $assignment->subject_id,
      ]);
    }

    $assignment = DB::table('game_session_questions as gsq')
      ->leftJoin('questions', 'questions.id', '=', 'gsq.question_id')
      ->leftJoin('user_play_questions as upq', 'upq.id', '=', 'gsq.user_play_question_id')
      ->where('gsq.game_session_id', $sessionId)
      ->where(function ($query) use ($questionId) {
        $query->where('gsq.question_id', $questionId)
          ->orWhere('gsq.user_play_question_id', $questionId);
      })
      ->select(
        'gsq.row_position',
        'gsq.col_position',
        'gsq.subject_id',
        'gsq.user_play_question_id',
        'questions.*',
        'upq.id as play_question_id',
        'upq.question_text as play_question_text',
        'upq.answer_text as play_answer_text',
        'upq.points_value as play_points_value',
      )
      ->first();

    if (! $assignment) {
      throw new NotFoundException('Question does not belong to this session');
    }

    $assignment = (array) $assignment;
    if (! empty($assignment['user_play_question_id'])) {
      return QuestionSelection::toSafe([
        'id' => $assignment['play_question_id'],
        'question_text' => $assignment['play_question_text'],
        'answer_text' => $assignment['play_answer_text'] ?? '',
        'points_value' => $assignment['play_points_value'] ?? 100,
        'image_url' => null,
        'question_type' => null,
      ], [
        'row_position' => $assignment['row_position'],
        'col_position' => $assignment['col_position'],
        'subject_id' => $assignment['subject_id'],
      ]);
    }

    $question = (array) DB::table('questions')->where('id', $questionId)->first();
    if ($question === []) {
      throw new NotFoundException('Question not found');
    }

    return QuestionSelection::toSafe($question, [
      'row_position' => $assignment['row_position'],
      'col_position' => $assignment['col_position'],
      'subject_id' => $assignment['subject_id'],
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function revealPublicSessionQuestionAnswer(string $questionId, string $sessionId): array
  {
    $this->assertQuestionRevealAllowed($questionId, $sessionId);

    $data = DB::table('questions')
      ->where('id', $questionId)
      ->first();

    if (! $data) {
      throw new NotFoundException('Question not found');
    }

    $data = (array) $data;

    return [
      'id' => $data['id'],
      'answer_text' => $data['answer_text'] ?? '',
      'answer_image_url' => $data['answer_image_url'] ?? null,
      'explanation' => $data['explanation'] ?? null,
    ];
  }

  /**
   * @param  array<int, array<int, array{value: int, team: int|null}>>  $board
   * @param  array<int, array{team: int, cells: array<int, array{row: int, col: int}>, direction: string}>  $existingWinLines
   * @param  array<int, string>  $usedBonusCells
   * @return array{wins: array<int, array{team: int, cells: array<int, array{row: int, col: int}>, direction: string}>, allWinLines: array<int, array{team: int, cells: array<int, array{row: int, col: int}>, direction: string}>}
   */
  public static function verifyConnectFourWin(
    array $board,
    string $mode,
    array $existingWinLines = [],
    array $usedBonusCells = [],
  ): array {
    $config = BoardConfig::forMode($mode);
    $wins = WinDetection::checkForWins(
      $board,
      $config['rows'],
      $config['cols'],
      $config['winLineLength'],
      $usedBonusCells,
    );
    $filtered = WinDetection::filterNonOverlappingWins($wins);
    $all = WinDetection::getAllWinLinesForDisplay(
      $board,
      $config['rows'],
      $config['cols'],
      $config['winLineLength'],
      $existingWinLines,
    );

    return ['wins' => $filtered, 'allWinLines' => $all];
  }

  /**
   * @param  array<string, mixed>  $patch
   */
  private function persistStatePatch(string $sessionId, array $patch): void
  {
    $jsonFields = [
      'metadata',
      'board',
      'visited_cells',
      'unanswered_cells',
      'win_lines',
      'processed_wins',
      'used_bonus_cells',
      'doubled_teams',
    ];

    $updateData = ['updated_at' => now()];

    foreach ($patch as $key => $value) {
      $updateData[$key] = in_array($key, $jsonFields, true) ? json_encode($value) : $value;
    }

    DB::table('game_session_state')
      ->where('game_session_id', $sessionId)
      ->update($updateData);
  }

  /**
   * @return array<string, mixed>
   */
  private function buildSessionPayload(string $sessionId): array
  {
    $session = $this->getSessionRecord($sessionId);
    $state = $this->getSessionState($sessionId);

    $teamProgress = DB::table('team_game_progress')
      ->join('teams', 'teams.id', '=', 'team_game_progress.team_id')
      ->where('team_game_progress.game_session_id', $sessionId)
      ->orderBy('team_game_progress.joined_at')
      ->select(
        'team_game_progress.team_id',
        'team_game_progress.current_score',
        'team_game_progress.questions_answered',
        'team_game_progress.correct_answers',
        'team_game_progress.connections_made',
        'teams.id as team_ref_id',
        'teams.name',
        'teams.avatar_url',
        'teams.color',
      )
      ->get();

    $teams = [];
    foreach ($teamProgress as $index => $row) {
      $teams[] = [
        'id' => $row->team_ref_id,
        'name' => $row->name,
        'avatar_url' => $row->avatar_url,
        'color' => $row->color,
        'index' => $index,
        'current_score' => $row->current_score ?? 0,
        'questions_answered' => $row->questions_answered ?? 0,
        'correct_answers' => $row->correct_answers ?? 0,
        'connections_made' => $row->connections_made ?? 0,
      ];
    }

    $subjects = DB::table('game_session_subjects')
      ->join('subjects', 'subjects.id', '=', 'game_session_subjects.subject_id')
      ->where('game_session_subjects.game_session_id', $sessionId)
      ->orderBy('game_session_subjects.subject_order')
      ->select(
        'game_session_subjects.subject_id',
        'game_session_subjects.subject_order',
        'subjects.id as subject_ref_id',
        'subjects.name',
        'subjects.icon_url',
        'subjects.color_hex',
        'subjects.challenge_type',
      )
      ->get()
      ->map(fn ($row) => [
        'id' => $row->subject_ref_id,
        'subject_id' => $row->subject_id,
        'name' => $row->name,
        'icon_url' => $row->icon_url,
        'color_hex' => $row->color_hex,
        'challenge_type' => $row->challenge_type,
        'subject_order' => $row->subject_order,
      ])
      ->all();

    if (
      $subjects === []
      && ($state['metadata']['question_source'] ?? null) === 'user_play_set'
      && ! empty($state['metadata']['play_set_id'])
    ) {
      $subjects = [[
        'id' => $state['metadata']['play_set_id'],
        'subject_id' => $state['metadata']['play_set_id'],
        'name' => $state['metadata']['play_set_title'] ?? 'لعبتي',
        'icon_url' => null,
        'color_hex' => '#6B46C1',
        'challenge_type' => 'school',
        'subject_order' => 0,
      ]];
    }

    return [
      'session' => [
        'id' => $session['id'],
        'status' => $session['status'],
        'host_id' => $session['host_id'],
        'session_code' => $session['session_code'],
        'mode' => $state['mode'],
        'class_name' => $state['class_name'],
        'started_at' => $session['started_at'] ?? null,
        'ended_at' => $session['ended_at'] ?? null,
        'winner_team_id' => $session['winner_team_id'] ?? null,
        'metadata' => $state['metadata'],
      ],
      'teams' => $teams,
      'subjects' => $subjects,
      'state' => [
        'board' => $state['board'],
        'visited_cells' => $state['visited_cells'],
        'unanswered_cells' => $state['unanswered_cells'],
        'active_team_index' => $state['active_team_index'],
        'win_lines' => $state['win_lines'],
        'processed_wins' => $state['processed_wins'],
        'used_bonus_cells' => $state['used_bonus_cells'],
        'doubled_teams' => $state['doubled_teams'],
        'updated_at' => $state['updated_at'] ?? null,
      ],
      'scores' => array_map(fn ($team) => $team['current_score'], $teams),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function getSessionRecord(string $sessionId): array
  {
    $data = DB::table('game_sessions')->where('id', $sessionId)->first();

    if (! $data) {
      throw new NotFoundException('Game session not found');
    }

    return (array) $data;
  }

  /**
   * @return array<string, mixed>
   */
  private function getSessionState(string $sessionId): array
  {
    $data = DB::table('game_session_state')->where('game_session_id', $sessionId)->first();

    if (! $data) {
      throw new NotFoundException('Game session state not found');
    }

    $state = (array) $data;

    $state['metadata'] = $this->decodeJson($state['metadata'] ?? null, []);
    $state['board'] = $this->decodeJson($state['board'] ?? null, []);
    $state['visited_cells'] = $this->decodeJson($state['visited_cells'] ?? null, []);
    $state['unanswered_cells'] = $this->decodeJson($state['unanswered_cells'] ?? null, []);
    $state['win_lines'] = $this->decodeJson($state['win_lines'] ?? null, []);
    $state['processed_wins'] = $this->decodeJson($state['processed_wins'] ?? null, []);
    $state['used_bonus_cells'] = $this->decodeJson($state['used_bonus_cells'] ?? null, []);
    $state['doubled_teams'] = $this->decodeJson($state['doubled_teams'] ?? null, []);

    return $state;
  }

  /**
   * @param  array<string, mixed>|null  $metadata
   * @return array<int, array{question_id: string, row_position: int, col_position: int, points_value: int}>
   */
  private function buildSchoolManualAssignments(string $subjectId, ?array $metadata, array $boardConfig): array
  {
    $questions = $this->fetchSchoolQuestionsFromBank($subjectId, $metadata);
    $totalCells = $boardConfig['rows'] * $boardConfig['cols'];

    if (count($questions) < $totalCells) {
      throw new ValidationException(
        "لا توجد أسئلة كافية لهذه الوحدة (مطلوب {$totalCells} سؤالاً، المتوفر: ".count($questions).').'
      );
    }

    shuffle($questions);
    $selected = array_slice($questions, 0, $totalCells);
    $assignments = [];

    for ($index = 0; $index < $totalCells; $index++) {
      $question = $selected[$index];
      $assignments[] = [
        'question_id' => $question['id'],
        'row_position' => intdiv($index, $boardConfig['cols']),
        'col_position' => $index % $boardConfig['cols'],
        'points_value' => (int) ($question['points_value'] ?? $boardConfig['circleValues'][$index % count($boardConfig['circleValues'])]),
      ];
    }

    return $assignments;
  }

  /**
   * @param  array<string, mixed>|null  $metadata
   */
  private function countSchoolQuestionsFromBank(string $subjectId, ?array $metadata): int
  {
    return $this->buildSchoolQuestionsQuery($subjectId, $metadata)->count();
  }

  /**
   * @param  array<string, mixed>|null  $metadata
   * @return \Illuminate\Database\Query\Builder
   */
  private function buildSchoolQuestionsQuery(string $subjectId, ?array $metadata)
  {
    $chapterId = is_string($metadata['chapter_id'] ?? null) ? trim($metadata['chapter_id']) : null;
    $unitName = is_string($metadata['unit'] ?? null) ? trim($metadata['unit']) : null;
    $grade = \App\Support\ArabicGradeMapper::gradeToDatabase(
      is_string($metadata['grade'] ?? null) ? $metadata['grade'] : null
    );
    $stage = \App\Support\ArabicGradeMapper::stageToDatabase(
      is_string($metadata['educational_stage'] ?? null) ? $metadata['educational_stage'] : null
    );

    $query = DB::table('questions')
      ->where('subject_id', $subjectId)
      ->whereNull('category_id')
      ->where('is_deleted', false)
      ->where('approval_status', 'approved')
      ->where(function ($builder) {
        $builder->where('is_active', true)->orWhereNull('is_active');
      });

    $gameId = is_string($metadata['game_id'] ?? null) ? trim($metadata['game_id']) : null;

    if ($gameId !== null && $gameId !== '') {
      $query->where('game_id', $gameId);
    } elseif ($chapterId) {
      $query->where('chapter_id', $chapterId);
    } elseif ($unitName) {
      $chapter = $this->findSchoolChapterByName($subjectId, $unitName);
      if ($chapter) {
        $query->where('chapter_id', $chapter->id);
      }
    }

    if ($grade) {
      $query->where('grade', $grade);
    }

    if ($stage) {
      $query->where('educational_stage', $stage);
    }

    return $query;
  }

  /**
   * @param  array<string, mixed>|null  $metadata
   * @return array<int, array<string, mixed>>
   */
  private function fetchSchoolQuestionsFromBank(string $subjectId, ?array $metadata): array
  {
    return $this->buildSchoolQuestionsQuery($subjectId, $metadata)
      ->orderByDesc('points_value')
      ->get()
      ->map(fn ($row) => (array) $row)
      ->all();
  }

  private function findSchoolChapterByName(string $subjectId, string $unitName): ?\App\Models\Chapter
  {
    $target = $this->normalizeArabicLabel($unitName);

    return \App\Models\Chapter::query()
      ->where('subject_id', $subjectId)
      ->get()
      ->first(fn (\App\Models\Chapter $chapter) => $this->normalizeArabicLabel($chapter->name) === $target);
  }

  private function normalizeArabicLabel(string $value): string
  {
    $normalized = trim($value);
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

    return mb_strtolower($normalized);
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  private function recordSchoolGameCompletionIfNeeded(
    string $sessionId,
    string $actorUserId,
    array $payload,
  ): void {
    $metadata = $payload['session']['metadata'] ?? $payload['metadata'] ?? [];
    $gameId = is_string($metadata['game_id'] ?? null) ? trim($metadata['game_id']) : '';

    if ($gameId === '') {
      return;
    }

    if ($this->schoolUnitProgress->isGameCompleted($actorUserId, $gameId)) {
      return;
    }

    $scores = $payload['scores'] ?? [];
    $maxScore = max($scores === [] ? [0] : $scores);

    $this->schoolUnitProgress->markGameCompleted(
      $actorUserId,
      $gameId,
      $sessionId,
      is_numeric($maxScore) ? (int) $maxScore : null,
    );
  }

  /**
   * @param  array<string, mixed>|null  $metadata
   * @return array<int, array<string, mixed>>
   */
  private function fetchSubjectQuestions(string $subjectId, string $mode, ?array $metadata): array
  {
    if ($mode === 'family') {
      return DB::table('questions')
        ->where('subject_id', $subjectId)
        ->whereNotNull('category_id')
        ->where('approval_status', 'approved')
        ->where('is_deleted', false)
        ->orderByDesc('points_value')
        ->get()
        ->map(fn ($q) => (array) $q)
        ->all();
    }

    return $this->fetchSchoolQuestionsFromBank($subjectId, $metadata);
  }

  /**
   * @return array<string, mixed>
   */
  private function getQuestionAssignment(string $sessionId, int $row, int $col): array
  {
    $data = DB::table('game_session_questions')
      ->where('game_session_id', $sessionId)
      ->where('row_position', $row)
      ->where('col_position', $col)
      ->first();

    if (! $data) {
      throw new NotFoundException('Question not assigned to this tile');
    }

    return (array) $data;
  }

  /**
   * @return array<string, mixed>
   */
  private function assertQuestionInSession(string $sessionId, string $questionId): array
  {
    $query = DB::table('game_session_questions')
      ->where('game_session_id', $sessionId);

    if ($this->supportsDirectUserPlayQuestions()) {
      $query->where(function ($builder) use ($questionId) {
        $builder->where('question_id', $questionId)
          ->orWhere('user_play_question_id', $questionId);
      });
    } else {
      $query->where('question_id', $questionId);
    }

    $data = $query->first();

    if (! $data) {
      throw new NotFoundException('Question does not belong to this session');
    }

    return (array) $data;
  }

  private function supportsDirectUserPlayQuestions(): bool
  {
    static $supported = null;

    if ($supported === null) {
      $supported = Schema::hasColumn('game_session_questions', 'user_play_question_id');
    }

    return $supported;
  }

  private function assertQuestionNotAnswered(string $sessionId, string $questionId): void
  {
    $query = DB::table('question_answers')->where('game_session_id', $sessionId);

    if ($this->supportsUserPlayQuestionAnswers()) {
      $query->where(function ($builder) use ($questionId) {
        $builder->where('question_id', $questionId)
          ->orWhere('user_play_question_id', $questionId);
      });
    } else {
      $query->where('question_id', $questionId);
    }

    $data = $query->first();

    if ($data) {
      throw new ConflictException('Question already answered');
    }
  }

  private function supportsUserPlayQuestionAnswers(): bool
  {
    static $supported = null;

    if ($supported === null) {
      $supported = Schema::hasColumn('question_answers', 'user_play_question_id');
    }

    return $supported;
  }

  private function supportsQuestionAnswerTimestamps(): bool
  {
    static $supported = null;

    if ($supported === null) {
      $supported = Schema::hasColumn('question_answers', 'created_at');
    }

    return $supported;
  }

  /**
   * @param  array<string, mixed>  $assignment
   * @return array<string, mixed>
   */
  private function buildQuestionAnswerRow(
    string $sessionId,
    string $questionId,
    array $assignment,
    ?string $teamIdForLog,
    string $outcome,
    int $pointsEarned,
  ): array {
    $answerRow = [
      'game_session_id' => $sessionId,
      'team_id' => $teamIdForLog,
      'user_answer_text' => $outcome === 'correct' ? 'correct' : 'no_answer',
      'is_correct' => $outcome === 'correct',
      'points_earned' => $pointsEarned,
      'answered_at' => now()->toIso8601String(),
    ];

    if ($this->supportsQuestionAnswerTimestamps()) {
      $answerRow['created_at'] = now();
      $answerRow['updated_at'] = now();
    }

    if ($this->isUserPlayQuestionAssignment($assignment) && $this->supportsUserPlayQuestionAnswers()) {
      $answerRow['user_play_question_id'] = $assignment['user_play_question_id'];
      $answerRow['question_id'] = null;
    } else {
      $answerRow['question_id'] = $questionId;
    }

    return $answerRow;
  }

  /**
   * @param  array<string, mixed>  $assignment
   */
  private function resolveAssignmentQuestionId(array $assignment): string
  {
    $userPlayQuestionId = $assignment['user_play_question_id'] ?? null;
    if (is_string($userPlayQuestionId) && $userPlayQuestionId !== '') {
      return $userPlayQuestionId;
    }

    $questionId = $assignment['question_id'] ?? null;
    if (! is_string($questionId) || $questionId === '') {
      throw new NotFoundException('Question not assigned to this tile');
    }

    return $questionId;
  }

  /**
   * @param  array<string, mixed>  $assignment
   * @return array<string, mixed>
   */
  private function loadQuestionRecord(string $questionId, array $assignment): array
  {
    if ($this->isUserPlayQuestionAssignment($assignment)) {
      $data = DB::table('user_play_questions')
        ->where('id', $assignment['user_play_question_id'])
        ->first();

      if (! $data) {
        throw new NotFoundException('Question not found');
      }

      $data = (array) $data;

      return [
        'id' => $data['id'],
        'question_text' => $data['question_text'],
        'answer_text' => $data['answer_text'] ?? '',
        'answer_image_url' => null,
        'explanation' => null,
        'points_value' => $data['points_value'] ?? 100,
        'image_url' => null,
        'question_type' => null,
        'chapter_id' => null,
        'category_id' => null,
        'grade' => null,
      ];
    }

    $data = DB::table('questions')
      ->where('id', $questionId)
      ->first();

    if (! $data) {
      throw new NotFoundException('Question not found');
    }

    return (array) $data;
  }

  /**
   * @param  array<string, mixed>  $assignment
   */
  private function isUserPlayQuestionAssignment(array $assignment): bool
  {
    return is_string($assignment['user_play_question_id'] ?? null)
      && $assignment['user_play_question_id'] !== '';
  }

  /**
   * @return array<string, mixed>
   */
  private function assertQuestionRevealAllowed(string $questionId, string $sessionId): array
  {
    $session = $this->getSessionRecord($sessionId);

    if (! in_array($session['status'] ?? null, ['in_progress', 'completed', 'waiting'], true)) {
      throw new ForbiddenException('Answer reveal is not allowed for this session');
    }

    $this->assertQuestionInSession($sessionId, $questionId);

    return $session;
  }

  /**
   * @return array<mixed>
   */
  private function deepClone(array $data): array
  {
    return json_decode(json_encode($data), true);
  }

  /**
   * @return array<mixed>|mixed
   */
  private function decodeJson(mixed $value, mixed $default = [])
  {
    if (is_string($value)) {
      return json_decode($value, true) ?? $default;
    }

    if ($value === null) {
      return $default;
    }

    return json_decode(json_encode($value), true);
  }

  /**
   * @return list<string>|null
   */
  private function decodeChoiceOptions(mixed $value): ?array
  {
    $decoded = $this->decodeJson($value, null);

    if (! is_array($decoded)) {
      return null;
    }

    $options = array_values(array_filter(
      array_map(fn ($option) => trim((string) $option), $decoded),
      fn (string $option) => $option !== ''
    ));

    return $options !== [] ? $options : null;
  }
}
