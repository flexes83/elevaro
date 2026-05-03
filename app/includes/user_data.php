<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (!function_exists('elevaro_current_user_id')) {
function elevaro_current_user_id(): ?int
{
    $user = auth_user();
    return $user ? (int)$user['id'] : null;
}
}

if (!function_exists('elevaro_table_exists')) {
function elevaro_table_exists(string $tableName): bool
{
    try {
        $stmt = elevaro_db()->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute(['table_name' => $tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
}

if (!function_exists('elevaro_column_exists')) {
function elevaro_column_exists(string $tableName, string $columnName): bool
{
    try {
        $stmt = elevaro_db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
}

function elevaro_user_has_active_access(?array $user = null): bool
{
    $user = $user ?: auth_user();
    if (!$user) return false;
    if (($user['role'] ?? '') === 'admin') return true;
    return (int)($user['has_active_access'] ?? 1) === 1;
}

function elevaro_start_quiz_session(int $userId, int $quizId, ?string $sessionToken = null): int
{
    if (!elevaro_table_exists('user_quiz_sessions')) {
        error_log('Elevaro stats: user_quiz_sessions table missing');
        return 0;
    }

    $pdo = elevaro_db();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM questions
        WHERE quiz_id = :quiz_id
          AND status = 'published'
    ");
    $stmt->execute(['quiz_id' => $quizId]);
    $total = (int)$stmt->fetchColumn();

    $hasSessionToken = elevaro_column_exists('user_quiz_sessions', 'session_token');

    if ($hasSessionToken) {
        $stmt = $pdo->prepare("
            INSERT INTO user_quiz_sessions (user_id, quiz_id, session_token, total_questions)
            VALUES (:user_id, :quiz_id, :session_token, :total_questions)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'quiz_id' => $quizId,
            'session_token' => $sessionToken,
            'total_questions' => $total,
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO user_quiz_sessions (user_id, quiz_id, total_questions)
            VALUES (:user_id, :quiz_id, :total_questions)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'quiz_id' => $quizId,
            'total_questions' => $total,
        ]);
    }

    return (int)$pdo->lastInsertId();
}

function elevaro_record_user_answer(
    int $userId,
    int $quizId,
    int $questionId,
    string $selectedAnswer,
    string $correctAnswer,
    bool $isCorrect,
    ?int $sessionId = null,
    int $points = 0,
    ?int $responseTimeMs = null
): array {
    if (!elevaro_table_exists('user_question_progress')) {
        error_log('Elevaro stats: user_question_progress table missing');
        return elevaro_get_user_quiz_progress($userId, $quizId);
    }

    $pdo = elevaro_db();
    $pdo->beginTransaction();

    try {
        if (elevaro_table_exists('user_answer_events')) {
            $columns = ['user_id', 'quiz_id', 'question_id', 'selected_answer', 'correct_answer', 'is_correct', 'points'];
            $params = [':user_id', ':quiz_id', ':question_id', ':selected_answer', ':correct_answer', ':is_correct', ':points'];
            $values = [
                'user_id' => $userId,
                'quiz_id' => $quizId,
                'question_id' => $questionId,
                'selected_answer' => $selectedAnswer,
                'correct_answer' => $correctAnswer,
                'is_correct' => $isCorrect ? 1 : 0,
                'points' => $points,
            ];

            if (elevaro_column_exists('user_answer_events', 'quiz_session_id')) {
                $columns[] = 'quiz_session_id';
                $params[] = ':quiz_session_id';
                $values['quiz_session_id'] = $sessionId ?: null;
            }

            if (elevaro_column_exists('user_answer_events', 'response_time_ms')) {
                $columns[] = 'response_time_ms';
                $params[] = ':response_time_ms';
                $values['response_time_ms'] = $responseTimeMs;
            }

            $sql = "INSERT INTO user_answer_events (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $params) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM user_question_progress
            WHERE user_id = :user_id
              AND question_id = :question_id
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([
            'user_id' => $userId,
            'question_id' => $questionId,
        ]);
        $progress = $stmt->fetch();

        $answeredCount = (int)($progress['answered_count'] ?? 0) + 1;
        $correctCount = (int)($progress['correct_count'] ?? 0) + ($isCorrect ? 1 : 0);
        $wrongCount = (int)($progress['wrong_count'] ?? 0) + ($isCorrect ? 0 : 1);
        $needsRecovery = (int)($progress['needs_recovery'] ?? 0);
        $consecutive = (int)($progress['consecutive_correct_after_wrong'] ?? 0);
        $isMastered = (int)($progress['is_mastered'] ?? 0);

        if ($isCorrect) {
            if ($needsRecovery) {
                $consecutive++;
                if ($consecutive >= 2) {
                    $needsRecovery = 0;
                    $isMastered = 1;
                }
            } else {
                $isMastered = 1;
                $consecutive = max($consecutive, 1);
            }
        } else {
            $needsRecovery = 1;
            $consecutive = 0;
            $isMastered = 0;
        }

        $stmt = $pdo->prepare("
            INSERT INTO user_question_progress
              (user_id, quiz_id, question_id, answered_count, correct_count, wrong_count,
               last_answer_correct, consecutive_correct_after_wrong, needs_recovery, is_mastered, last_answered_at)
            VALUES
              (:user_id, :quiz_id, :question_id, :answered_count, :correct_count, :wrong_count,
               :last_answer_correct, :consecutive_correct_after_wrong, :needs_recovery, :is_mastered, NOW())
            ON DUPLICATE KEY UPDATE
              quiz_id = VALUES(quiz_id),
              answered_count = VALUES(answered_count),
              correct_count = VALUES(correct_count),
              wrong_count = VALUES(wrong_count),
              last_answer_correct = VALUES(last_answer_correct),
              consecutive_correct_after_wrong = VALUES(consecutive_correct_after_wrong),
              needs_recovery = VALUES(needs_recovery),
              is_mastered = VALUES(is_mastered),
              last_answered_at = NOW(),
              updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'user_id' => $userId,
            'quiz_id' => $quizId,
            'question_id' => $questionId,
            'answered_count' => $answeredCount,
            'correct_count' => $correctCount,
            'wrong_count' => $wrongCount,
            'last_answer_correct' => $isCorrect ? 1 : 0,
            'consecutive_correct_after_wrong' => $consecutive,
            'needs_recovery' => $needsRecovery,
            'is_mastered' => $isMastered,
        ]);

        if ($sessionId && elevaro_table_exists('user_quiz_sessions')) {
            $stmt = $pdo->prepare("
                UPDATE user_quiz_sessions
                SET answered_questions = answered_questions + 1,
                    correct_answers = correct_answers + :correct_increment,
                    wrong_answers = wrong_answers + :wrong_increment,
                    score_points = score_points + :points
                WHERE id = :session_id
                  AND user_id = :user_id
            ");
            $stmt->execute([
                'correct_increment' => $isCorrect ? 1 : 0,
                'wrong_increment' => $isCorrect ? 0 : 1,
                'points' => $points,
                'session_id' => $sessionId,
                'user_id' => $userId,
            ]);
        }

        $pdo->commit();
        return elevaro_get_user_quiz_progress($userId, $quizId);
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Elevaro user answer tracking failed: ' . $e->getMessage());
        throw $e;
    }
}

function elevaro_complete_quiz_session(int $userId, int $sessionId): void
{
    if (!$sessionId || !elevaro_table_exists('user_quiz_sessions')) return;

    $stmt = elevaro_db()->prepare("
        UPDATE user_quiz_sessions
        SET completed_at = NOW()
        WHERE id = :session_id
          AND user_id = :user_id
          AND completed_at IS NULL
    ");
    $stmt->execute([
        'session_id' => $sessionId,
        'user_id' => $userId,
    ]);
}

function elevaro_get_user_quiz_progress(int $userId, int $quizId): array
{
    $pdo = elevaro_db();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM questions
        WHERE quiz_id = :quiz_id
          AND status = 'published'
    ");
    $stmt->execute(['quiz_id' => $quizId]);
    $total = (int)$stmt->fetchColumn();

    if (!elevaro_table_exists('user_question_progress')) {
        return [
            'progress_total' => $total,
            'progress_passed' => 0,
            'progress_failed' => 0,
            'progress_unanswered' => $total,
            'progress_attempted' => 0,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT
          SUM(CASE WHEN is_mastered = 1 THEN 1 ELSE 0 END) AS mastered,
          SUM(CASE WHEN needs_recovery = 1 THEN 1 ELSE 0 END) AS failed_pending,
          SUM(CASE WHEN answered_count > 0 THEN 1 ELSE 0 END) AS attempted
        FROM user_question_progress
        WHERE user_id = :user_id
          AND quiz_id = :quiz_id
    ");
    $stmt->execute([
        'user_id' => $userId,
        'quiz_id' => $quizId,
    ]);
    $row = $stmt->fetch() ?: [];

    $mastered = (int)($row['mastered'] ?? 0);
    $failedPending = (int)($row['failed_pending'] ?? 0);
    $attempted = (int)($row['attempted'] ?? 0);

    return [
        'progress_total' => $total,
        'progress_passed' => $mastered,
        'progress_failed' => $failedPending,
        'progress_unanswered' => max($total - $mastered - $failedPending, 0),
        'progress_attempted' => $attempted,
    ];
}
