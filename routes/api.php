<?php

use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ChapterController;
use App\Http\Controllers\Admin\QuestionController;
use App\Http\Controllers\Admin\SchoolCourseController;
use App\Http\Controllers\Admin\SchoolUnitImportController;
use App\Http\Controllers\Admin\SubjectController;
use App\Http\Controllers\Admin\TextbookChunkedUploadController;
use App\Http\Controllers\Admin\TextbookController;
use App\Http\Controllers\Admin\UploadController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Ai\AiController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Me\UserGameHistoryController;
use App\Http\Controllers\Me\UserPlaySetController;
use App\Http\Controllers\Me\ViewedQuestionController;
use App\Http\Controllers\Game\GameCatalogController;
use App\Http\Controllers\Game\GameSessionController;
use App\Http\Controllers\Game\PublicQuestionController;
use App\Http\Controllers\Game\ReviewSetController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'show']);

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('/google/redirect', [AuthController::class, 'googleRedirect']);
    Route::get('/google/callback', [AuthController::class, 'googleCallback']);

    Route::middleware(['auth:sanctum', 'api.auth'])->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me', [AuthController::class, 'updateProfile']);
        Route::post('/me/avatar', [AuthController::class, 'uploadAvatar']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::middleware('permission:canManageUsers')->get('/permission-check', function () {
            return response()->json(['data' => ['allowed' => true]]);
        });
    });
});
Route::get('/game-assets/avatars/{avatar}', function (string $avatar) {
    abort_unless(preg_match('/^[1-6]\.png$/', $avatar) === 1, 404);

    $path = base_path("../frontend/public/assets/avatars/{$avatar}");
    abort_unless(is_file($path), 404);

    return response()->file($path, [
        'Cache-Control' => 'public, max-age=86400',
    ]);
});

Route::middleware(['auth:sanctum', 'api.auth'])->group(function () {
    Route::get('/me/game-sessions', [UserGameHistoryController::class, 'index']);
    Route::delete('/me/game-sessions', [UserGameHistoryController::class, 'destroy']);
    Route::get('/me/viewed-questions', [ViewedQuestionController::class, 'index']);
    Route::post('/me/viewed-questions', [ViewedQuestionController::class, 'store']);
    Route::delete('/me/viewed-questions', [ViewedQuestionController::class, 'destroy']);

    Route::prefix('me/play-sets')->group(function () {
        Route::get('/', [UserPlaySetController::class, 'index']);
        Route::get('/{id}', [UserPlaySetController::class, 'show']);
        Route::put('/{id}', [UserPlaySetController::class, 'updateDraft']);
        Route::post('/{id}/save', [UserPlaySetController::class, 'save']);
        Route::post('/{id}/start-game', [UserPlaySetController::class, 'startGame']);
        Route::delete('/{id}', [UserPlaySetController::class, 'destroy']);

        Route::middleware('throttle:ai')->group(function () {
            Route::post('/inspect', [UserPlaySetController::class, 'inspect']);
            Route::post('/generate', [UserPlaySetController::class, 'generate']);
            Route::post('/{id}/questions/{questionId}/regenerate', [UserPlaySetController::class, 'regenerateQuestion']);
        });
    });

});

Route::prefix('ai')
    ->middleware(['auth:sanctum', 'api.auth', 'throttle:ai'])
    ->group(function () {
        Route::middleware('permission:canUseAI')->group(function () {
            Route::get('/status', [AiController::class, 'status']);
            Route::post('/generate-question', [AiController::class, 'generateQuestion']);
        });
    });

Route::prefix('admin')
    ->middleware(['admin.timing', 'auth:sanctum', 'api.auth', 'throttle:admin'])
    ->group(function () {
        Route::middleware('permission.any:canManageUsers')->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::patch('/users/{userId}/role', [UserController::class, 'updateRole']);
            Route::patch('/users/{id}/status', [UserController::class, 'updateStatus']);
            Route::delete('/users/{id}/permanent', [UserController::class, 'permanentDestroy']);
            Route::post('/users/{id}/restore', [UserController::class, 'restore']);
            Route::delete('/users/{id}', [UserController::class, 'destroy']);
        });

        Route::middleware('permission.any:canManageUsers,canEditQuestions')->group(function () {
            Route::get('/users/{id}', [UserController::class, 'show']);
        });

        Route::middleware('permission.any:canAddQuestions,canEditQuestions,canDeleteQuestions')->group(function () {
            Route::get('/chapters', [ChapterController::class, 'index']);
            Route::get('/questions', [QuestionController::class, 'index']);
            Route::post('/questions/import', [QuestionController::class, 'import']);
            Route::post('/school-units/import-excel', [SchoolUnitImportController::class, 'import']);
            Route::post('/school-units/backfill-import-source', [SchoolUnitImportController::class, 'backfillImportSource']);
            Route::post('/questions/bulk-delete', [QuestionController::class, 'bulkDelete']);
            Route::patch('/questions/bulk-approve', [QuestionController::class, 'bulkApprove']);
            Route::patch('/questions/bulk-reject', [QuestionController::class, 'bulkReject']);
            Route::get('/questions/{id}', [QuestionController::class, 'show']);
            Route::patch('/questions/{id}/approve', [QuestionController::class, 'approve']);
            Route::patch('/questions/{id}/reject', [QuestionController::class, 'reject']);
            Route::post('/questions/{id}/restore', [QuestionController::class, 'restore']);
            Route::patch('/questions/{id}', [QuestionController::class, 'update']);
        });

        Route::middleware('permission:canAddQuestions')->group(function () {
            Route::post('/questions', [QuestionController::class, 'store']);
            Route::post('/chapters/resolve', [QuestionController::class, 'resolveChapter']);
        });

        Route::middleware('permission:canDeleteQuestions')->group(function () {
            Route::delete('/questions/{id}', [QuestionController::class, 'destroy']);
        });

        Route::middleware('permission.any:canAddSubjects,canEditSubjects,canDeleteSubjects,canAddQuestions,canEditQuestions')->group(function () {
            Route::get('/subjects', [SubjectController::class, 'index']);
            Route::get('/subjects/{id}', [SubjectController::class, 'show']);
        });

        Route::middleware('permission.any:canAddSubjects,canEditSubjects,canDeleteSubjects')->group(function () {
            Route::get('/subjects/question-stats', [SubjectController::class, 'questionStats']);
            Route::get('/subjects/{id}/courses', [SchoolCourseController::class, 'index']);
            Route::post('/subjects/{id}/courses', [SchoolCourseController::class, 'store']);
            Route::patch('/school-courses/{courseId}', [SchoolCourseController::class, 'update']);
            Route::delete('/school-courses/{courseId}', [SchoolCourseController::class, 'destroy']);
            Route::patch('/subjects/{id}/grades', [SubjectController::class, 'updateGrades']);
            Route::post('/subjects/{id}/grades/{grade}/toggle-completion', [SubjectController::class, 'toggleCompletion']);
            Route::post('/subjects/{id}/restore', [SubjectController::class, 'restore']);
            Route::patch('/subjects/{id}', [SubjectController::class, 'update']);
        });

        Route::middleware('permission:canAddSubjects')->post('/subjects', [SubjectController::class, 'store']);
        Route::middleware('permission:canDeleteSubjects')->delete('/subjects/{id}', [SubjectController::class, 'destroy']);

        Route::middleware('permission.any:canAddCategories,canEditCategories,canDeleteCategories')->group(function () {
            Route::get('/categories', [CategoryController::class, 'index']);
            Route::patch('/categories/{id}', [CategoryController::class, 'update']);
            Route::post('/categories/{id}/restore', [CategoryController::class, 'restore']);
        });

        Route::middleware('permission:canAddCategories')->post('/categories', [CategoryController::class, 'store']);
        Route::middleware('permission:canDeleteCategories')->delete('/categories/{id}', [CategoryController::class, 'destroy']);

        Route::middleware('permission:canAddQuestions')->post('/uploads/sign', [UploadController::class, 'sign']);
        Route::middleware('permission:canAddQuestions')->post('/uploads', [UploadController::class, 'store']);

        Route::middleware('permission:canManageCurriculum')->group(function () {
            Route::post('/textbooks/uploads/init', [TextbookChunkedUploadController::class, 'init']);
            Route::get('/textbooks/uploads/{uploadId}', [TextbookChunkedUploadController::class, 'show']);
            Route::post('/textbooks/uploads/{uploadId}/chunks/{chunkIndex}', [TextbookChunkedUploadController::class, 'storeChunk']);
            Route::post('/textbooks/uploads/{uploadId}/complete', [TextbookChunkedUploadController::class, 'complete']);
            Route::delete('/textbooks/uploads/{uploadId}', [TextbookChunkedUploadController::class, 'destroy']);
            Route::get('/textbooks', [TextbookController::class, 'index']);
            Route::post('/textbooks', [TextbookController::class, 'store']);
            Route::post('/textbooks/{id}/process', [TextbookController::class, 'process']);
            Route::post('/textbooks/{id}/confirm-upload', [TextbookController::class, 'confirmUpload']);
            Route::post('/textbooks/{id}/upload', [TextbookController::class, 'uploadFile']);
            Route::post('/textbooks/{id}/file', [TextbookController::class, 'uploadFile']);
            Route::get('/textbooks/{id}/status', [TextbookController::class, 'status']);
            Route::get('/textbooks/{id}/processing-status', [TextbookController::class, 'processingStatus']);
            Route::get('/textbooks/{id}/analysis', [TextbookController::class, 'analysis']);
            Route::patch('/textbooks/{id}/structure', [TextbookController::class, 'patchStructure']);
            Route::post('/textbooks/{id}/approve-structure', [TextbookController::class, 'approveStructure']);
            Route::post('/textbooks/{id}/retry', [TextbookController::class, 'retry']);
            Route::post('/textbooks/{id}/generate-questions', [TextbookController::class, 'generateQuestions']);
            Route::post('/textbooks/{id}/generate-unit-questions', [TextbookController::class, 'generateUnitQuestions']);
            Route::get('/textbooks/{id}/units/generation-status', [TextbookController::class, 'unitGenerationStatus']);
            Route::get('/textbooks/{id}/units/{unitKey}/review-sets', [TextbookController::class, 'unitReviewSets']);
            Route::get('/textbooks/{id}/units/{unitKey}/review-sets/remaining', [TextbookController::class, 'unitRemainingReviewSets']);
            Route::get('/textbooks/{id}/review-sets/{reviewSetId}', [TextbookController::class, 'reviewSetDetails']);
            Route::get('/textbooks/{id}/generated-questions', [TextbookController::class, 'listGeneratedQuestions']);
            Route::get('/textbooks/{id}/generated-questions/{generatedQuestionId}/provenance', [TextbookController::class, 'generatedQuestionProvenance']);
            Route::get('/textbooks/{id}/chapter-mapping', [TextbookController::class, 'chapterMapping']);
            Route::get('/textbooks/{id}/unit-mappings', [TextbookController::class, 'unitMappings']);
            Route::put('/textbooks/{id}/unit-mappings', [TextbookController::class, 'saveUnitMapping']);
            Route::post('/textbooks/{id}/generated-questions/bulk-review', [TextbookController::class, 'bulkReviewGeneratedQuestions']);
            Route::post('/textbooks/{id}/generated-questions/{generatedQuestionId}/review', [TextbookController::class, 'reviewGeneratedQuestion']);
        });
    });

Route::prefix('game')->middleware('throttle:game')->group(function () {
    Route::get('/categories', [GameCatalogController::class, 'categories']);
    Route::get('/subjects', [GameCatalogController::class, 'subjects']);
    Route::get('/subjects/{subjectId}/courses', [GameCatalogController::class, 'subjectCourses']);
    Route::get('/subjects/chapters', [GameCatalogController::class, 'subjectChapters']);
    Route::get('/units/{unitId}/games', [GameCatalogController::class, 'unitGames']);

    Route::get('/subjects/family-question-counts', [GameCatalogController::class, 'familySubjectCounts']);

    Route::get('/questions/{questionId}', [PublicQuestionController::class, 'show']);
    Route::get('/questions/{questionId}/answer', [PublicQuestionController::class, 'revealAnswer']);

    Route::middleware(['auth:sanctum', 'api.auth'])->group(function () {
        Route::post('/sessions', [GameSessionController::class, 'store']);
        Route::get('/sessions/{id}', [GameSessionController::class, 'show']);
        Route::patch('/sessions/{id}', [GameSessionController::class, 'update']);
        Route::post('/sessions/{id}/finish', [GameSessionController::class, 'finish']);

        Route::get('/sessions/{sessionId}/questions', [GameSessionController::class, 'questions']);
        Route::get('/sessions/{sessionId}/questions/{questionId}/answer', [GameSessionController::class, 'revealAnswer']);
        Route::post('/sessions/{sessionId}/questions/{questionId}/answer', [GameSessionController::class, 'submitAnswer']);

        Route::post('/sessions/{sessionId}/board/claim', [GameSessionController::class, 'claimTile']);
        Route::post('/sessions/{sessionId}/board/assign', [GameSessionController::class, 'assignTile']);
        Route::post('/sessions/{sessionId}/powerups', [GameSessionController::class, 'applyPowerup']);

        Route::get('/sessions/{sessionId}/scores', [GameSessionController::class, 'scores']);
        Route::post('/sessions/{sessionId}/scores/adjust', [GameSessionController::class, 'adjustScore']);

        Route::get('/textbooks/{textbookId}/units/{unitKey}/review-sets/next', [ReviewSetController::class, 'next']);
        Route::get('/textbooks/{textbookId}/units/{unitKey}/review-sets/remaining', [ReviewSetController::class, 'remaining']);
        Route::post('/review-sets/{reviewSetId}/usage', [ReviewSetController::class, 'markUsed']);
    });
});
