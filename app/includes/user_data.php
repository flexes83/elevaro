<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function elevaro_current_user_id(): ?int
{
    $user = auth_user();
    return $user ? (int)$user['id'] : null;
}

function elevaro_user_has_active_access(?array $user = null): bool
{
    $user = $user ?: auth_user();

    if (!$user) {
        return false;
    }

    // Admins always have access.
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    return (int)($user['has_active_access'] ?? 1) === 1;
}

function elevaro_save_user_learning_profile(int $userId, array $profile): void
{
    $pdo = elevaro_db();

    $values = $profile['values'] ?? [];
    $labels = $profile['labels'] ?? [];

    $stateCode = (string)($values['state'] ?? '');
    $schoolTypeCode = (string)($values['school_type'] ?? '');
    $subjectCode = (string)($values['subject'] ?? '');
    $grade = isset($values['grade']) ? (int)$values['grade'] : null;

    $stateId = elevaro_lookup_id_by_code($pdo, 'states', $stateCode);
    $schoolTypeId = elevaro_lookup_id_by_code($pdo, 'school_types', $schoolTypeCode);
    $subjectId = elevaro_lookup_id_by_code($pdo, 'subjects', $subjectCode);

    $focusTags = $values['focus_tags'] ?? [];
    if (!is_array($focusTags)) {
        $focusTags = [];
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_learning_profiles
          (user_id, state_id, school_type_id, grade, subject_id,
           state_code, school_type_code, subject_code,
           focus_label, focus_tags_json, labels_json, raw_profile_json)
        VALUES
          (:user_id, :state_id, :school_type_id, :grade, :subject_id,
           :state_code, :school_type_code, :subject_code,
           :focus_label, :focus_tags_json, :labels_json, :raw_profile_json)
        ON DUPLICATE KEY UPDATE
          state_id = VALUES(state_id),
          school_type_id = VALUES(school_type_id),
          grade = VALUES(grade),
          subject_id = VALUES(subject_id),
          state_code = VALUES(state_code),
          school_type_code = VALUES(school_type_code),
          subject_code = VALUES(subject_code),
          focus_label = VALUES(focus_label),
          focus_tags_json = VALUES(focus_tags_json),
          labels_json = VALUES(labels_json),
          raw_profile_json = VALUES(raw_profile_json),
          updated_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        'user_id' => $userId,
        'state_id' => $stateId,
        'school_type_id' => $schoolTypeId,
        'grade' => $grade,
        'subject_id' => $subjectId,
        'state_code' => $stateCode ?: null,
        'school_type_code' => $schoolTypeCode ?: null,
        'subject_code' => $subjectCode ?: null,
        'focus_label' => (string)($labels['focus'] ?? $labels['topic'] ?? ''),
        'focus_tags_json' => json_encode(array_values($focusTags), JSON_UNESCAPED_UNICODE),
        'labels_json' => json_encode($labels, JSON_UNESCAPED_UNICODE),
        'raw_profile_json' => json_encode($profile, JSON_UNESCAPED_UNICODE),
    ]);
}

function elevaro_get_user_learning_profile(int $userId): ?array
{
    $pdo = elevaro_db();

    $stmt = $pdo->prepare("SELECT * FROM user_learning_profiles WHERE user_id = :user_id LIMIT 1");
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    $raw = json_decode((string)($row['raw_profile_json'] ?? ''), true);

    if (is_array($raw)) {
        return $raw;
    }

    return [
        'values' => [
            'state' => $row['state_code'],
            'school_type' => $row['school_type_code'],
            'grade' => $row['grade'] ? (int)$row['grade'] : null,
            'subject' => $row['subject_code'],
            'focus_tags' => json_decode((string)($row['focus_tags_json'] ?? '[]'), true) ?: [],
        ],
        'labels' => json_decode((string)($row['labels_json'] ?? '{}'), true) ?: [],
    ];
}

function elevaro_lookup_id_by_code(PDO $pdo, string $table, string $code): ?int
{
    if ($code === '') {
        return null;
    }

    $allowed = ['states', 'school_types', 'subjects'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Invalid lookup table.');
    }

    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE code = :code LIMIT 1");
    $stmt->execute(['code' => $code]);
    $id = $stmt->fetchColumn();

    return $id ? (int)$id : null;
}

function elevaro_start_quiz_session(int $userId, int $quizId): int
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

    $stmt = $pdo->prepare("
        INSERT INTO user_quiz_sessions (user_id, quiz_id, total_questions)
        VALUES (:user_id, :quiz_id, :total_questions)
    ");
    $stmt->execute([
        'user_id' => $userId,
        'quiz_id' => $quizId,
        'total_questions' => $total,
    ]);

    return (int)$pdo->lastInsertId();
}

function elevaro_record_user_answer(int $userId, int $quizId, int $questionId, string $selectedAnswer, string $correctAnswer, bool $isCorrect, ?int $sessionId = null, int $points = 0): array
{
    $pdo = elevaro_db();

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_answer_events
              (user_id, quiz_id, question_id, quiz_session_id, selected_answer, correct_answer, is_correct, points)
            VALUES
              (:user_id, :quiz_id, :question_id, :quiz_session_id, :selected_answer, :correct_answer, :is_correct, :points)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'quiz_id' => $quizId,
            'question_id' => $questionId,
            'quiz_session_id' => $sessionId,
            'selected_answer' => $selectedAnswer,
            'correct_answer' => $correctAnswer,
            'is_correct' => $isCorrect ? 1 : 0,
            'points' => $points,
        ]);

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
                // First-time correct answer, or already clean: passed.
                $isMastered = 1;
                $consecutive = max($consecutive, 1);
            }
        } else {
            // Once wrong, question must be answered correctly twice in a row.
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

        if ($sessionId) {
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
        throw $e;
    }
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
    $unanswered = max($total - $mastered - $failedPending, 0);

    return [
        'progress_total' => $total,
        'progress_passed' => $mastered,
        'progress_failed' => $failedPending,
        'progress_unanswered' => $unanswered,
        'progress_attempted' => $attempted,
    ];
}

function elevaro_get_user_progress_for_quizzes(int $userId, array $quizIds): array
{
    $result = [];

    foreach ($quizIds as $quizId) {
        $quizId = (int)$quizId;
        if ($quizId > 0) {
            $result[$quizId] = elevaro_get_user_quiz_progress($userId, $quizId);
        }
    }

    return $result;
}
