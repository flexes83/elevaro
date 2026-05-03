<?php

require_once __DIR__ . '/db.php';

function elevaro_get_quiz_by_key(string $quizKey): ?array
{
    $stmt = elevaro_db()->prepare("
        SELECT
            q.*
        FROM quizzes q
        WHERE q.quiz_key = :quiz_key
          AND q.is_active = 1
          AND q.status = 'published'
        LIMIT 1
    ");

    $stmt->execute(['quiz_key' => $quizKey]);
    $quiz = $stmt->fetch();

    return $quiz ?: null;
}

function elevaro_get_questions_for_quiz(int $quizId, bool $adaptiveOrder = true): array
{
    $orderSql = $adaptiveOrder
        ? "COALESCE(q.difficulty_manual, qs.calculated_difficulty, q.difficulty_calculated) ASC, q.sort_order ASC, q.id ASC"
        : "q.sort_order ASC, q.id ASC";

    $stmt = elevaro_db()->prepare("
        SELECT
            q.*,
            COALESCE(q.difficulty_manual, qs.calculated_difficulty, q.difficulty_calculated) AS difficulty
        FROM questions q
        LEFT JOIN question_stats qs ON qs.question_id = q.id
        WHERE q.quiz_id = :quiz_id
          AND q.status = 'published'
        ORDER BY {$orderSql}
    ");

    $stmt->execute(['quiz_id' => $quizId]);
    $questions = $stmt->fetchAll();

    if (!$questions) {
        return [];
    }

    $ids = array_column($questions, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $optionsStmt = elevaro_db()->prepare("
        SELECT *
        FROM question_options
        WHERE question_id IN ({$placeholders})
        ORDER BY question_id ASC, sort_order ASC, id ASC
    ");
    $optionsStmt->execute($ids);

    $optionsByQuestion = [];

    foreach ($optionsStmt->fetchAll() as $option) {
        $qid = (int)$option['question_id'];
        $optionsByQuestion[$qid][] = [
            'text' => $option['option_text'],
            'is_correct' => (bool)$option['is_correct'],
            'media' => [
                'type' => $option['media_type'] ?? 'none',
                'path' => $option['media_path'] ?? null,
                'alt' => $option['media_alt'] ?? null,
                'credit' => $option['media_credit'] ?? null,
                'source' => $option['media_source'] ?? null,
            ],
        ];
    }

    $payload = [];

    foreach ($questions as $question) {
        $qid = (int)$question['id'];
        $options = $optionsByQuestion[$qid] ?? [];

        $payload[] = [
            'id' => $qid,
            'type' => $question['type'],
            'question' => $question['question_text'],
            'media' => [
                'type' => $question['media_type'] ?? 'none',
                'path' => $question['media_path'] ?? null,
                'alt' => $question['media_alt'] ?? null,
                'credit' => $question['media_credit'] ?? null,
                'source' => $question['media_source'] ?? null,
            ],
            'options' => array_map(static function ($option) {
                return [
                    'text' => (string)($option['text'] ?? ''),
                    'label' => (string)($option['text'] ?? ''),
                    'media' => $option['media'] ?? ['type' => 'none'],
                    'media_type' => $option['media']['type'] ?? 'none',
                    'media_path' => $option['media']['path'] ?? null,
                    'media_alt' => $option['media']['alt'] ?? null,
                ];
            }, $options),
            'answer' => $question['correct_answer'],
            'fact' => $question['explanation'],
            'difficulty' => (float)$question['difficulty'],
        ];
    }

    return $payload;
}

function elevaro_get_quiz_payload(string $quizKey): ?array
{
    $quiz = elevaro_get_quiz_by_key($quizKey);

    if (!$quiz) {
        return null;
    }

    $quiz['questions'] = elevaro_get_questions_for_quiz((int)$quiz['id']);

    return $quiz;
}

function elevaro_record_answer_event(
    int $quizId,
    int $questionId,
    ?string $selectedAnswer,
    bool $isCorrect,
    ?string $sessionId = null,
    ?int $responseTimeMs = null
): void {
    $pdo = elevaro_db();

    $stmt = $pdo->prepare("
        INSERT INTO question_answer_events
            (question_id, quiz_id, session_id, selected_answer, is_correct, response_time_ms)
        VALUES
            (:question_id, :quiz_id, :session_id, :selected_answer, :is_correct, :response_time_ms)
    ");

    $stmt->execute([
        'question_id' => $questionId,
        'quiz_id' => $quizId,
        'session_id' => $sessionId,
        'selected_answer' => $selectedAnswer,
        'is_correct' => $isCorrect ? 1 : 0,
        'response_time_ms' => $responseTimeMs,
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO question_stats
            (question_id, times_shown, times_answered, times_correct, times_wrong, calculated_difficulty, last_answered_at)
        VALUES
            (:question_id, 1, 1, :correct, :wrong, :difficulty, NOW())
        ON DUPLICATE KEY UPDATE
            times_shown = times_shown + 1,
            times_answered = times_answered + 1,
            times_correct = times_correct + VALUES(times_correct),
            times_wrong = times_wrong + VALUES(times_wrong),
            calculated_difficulty = LEAST(0.950, GREATEST(0.050, (times_wrong + VALUES(times_wrong)) / NULLIF((times_answered + 1), 0))),
            last_answered_at = NOW()
    ");

    $stmt->execute([
        'question_id' => $questionId,
        'correct' => $isCorrect ? 1 : 0,
        'wrong' => $isCorrect ? 0 : 1,
        'difficulty' => $isCorrect ? 0.200 : 0.700,
    ]);
}
