<?php

require_once __DIR__ . '/db.php';

function elevaro_get_quiz_by_key(string $quizKey): ?array
{
    $stmt = elevaro_db()->prepare("\n        SELECT\n            q.id,\n            q.quiz_key,\n            q.title,\n            q.description,\n            q.status,\n            q.is_active\n        FROM quizzes q\n        WHERE q.quiz_key = :quiz_key\n          AND q.is_active = 1\n          AND q.status = 'published'\n        LIMIT 1\n    ");

    $stmt->execute(['quiz_key' => $quizKey]);
    $quiz = $stmt->fetch();

    return $quiz ?: null;
}

function elevaro_get_questions_for_quiz(int $quizId, bool $adaptiveOrder = true): array
{
    $orderSql = $adaptiveOrder
        ? "COALESCE(q.difficulty_manual, qs.calculated_difficulty, q.difficulty_calculated) ASC, q.sort_order ASC, q.id ASC"
        : "q.sort_order ASC, q.id ASC";

    $stmt = elevaro_db()->prepare("\n        SELECT\n            q.id,\n            q.question_key,\n            q.type,\n            q.question_text,\n            q.media_type,\n            q.media_path,\n            q.media_alt,\n            q.media_credit,\n            q.media_source,\n            q.correct_answer,\n            q.explanation,\n            COALESCE(q.difficulty_manual, qs.calculated_difficulty, q.difficulty_calculated) AS difficulty\n        FROM questions q\n        LEFT JOIN question_stats qs ON qs.question_id = q.id\n        WHERE q.quiz_id = :quiz_id\n          AND q.status = 'published'\n        ORDER BY {$orderSql}\n    ");

    $stmt->execute(['quiz_id' => $quizId]);
    $questions = $stmt->fetchAll();

    if (!$questions) {
        return [];
    }

    $ids = array_column($questions, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $optionsStmt = elevaro_db()->prepare("\n        SELECT\n            question_id,\n            option_text,\n            is_correct,\n            media_type,\n            media_path,\n            media_alt,\n            media_credit,\n            media_source\n        FROM question_options\n        WHERE question_id IN ({$placeholders})\n        ORDER BY question_id ASC, sort_order ASC, id ASC\n    ");
    $optionsStmt->execute($ids);

    $optionsByQuestion = [];

    foreach ($optionsStmt->fetchAll() as $option) {
        $qid = (int)$option['question_id'];
        $optionsByQuestion[$qid][] = [
            'text' => $option['option_text'],
            'is_correct' => (bool)$option['is_correct'],
            'media_type' => $option['media_type'] ?: 'none',
            'media_path' => $option['media_path'],
            'media_alt' => $option['media_alt'],
            'media_credit' => $option['media_credit'],
            'media_source' => $option['media_source'],
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
                'type' => $question['media_type'] ?: 'none',
                'path' => $question['media_path'],
                'alt' => $question['media_alt'],
                'credit' => $question['media_credit'],
                'source' => $question['media_source'],
            ],
            'options' => array_map(static function (array $option): array {
                return [
                    'text' => $option['text'],
                    'media' => [
                        'type' => $option['media_type'] ?: 'none',
                        'path' => $option['media_path'],
                        'alt' => $option['media_alt'],
                        'credit' => $option['media_credit'],
                        'source' => $option['media_source'],
                    ],
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

    $stmt = $pdo->prepare("\n        INSERT INTO question_answer_events\n            (question_id, quiz_id, session_id, selected_answer, is_correct, response_time_ms)\n        VALUES\n            (:question_id, :quiz_id, :session_id, :selected_answer, :is_correct, :response_time_ms)\n    ");

    $stmt->execute([
        'question_id' => $questionId,
        'quiz_id' => $quizId,
        'session_id' => $sessionId,
        'selected_answer' => $selectedAnswer,
        'is_correct' => $isCorrect ? 1 : 0,
        'response_time_ms' => $responseTimeMs,
    ]);

    $stmt = $pdo->prepare("\n        INSERT INTO question_stats\n            (question_id, times_shown, times_answered, times_correct, times_wrong, calculated_difficulty, last_answered_at)\n        VALUES\n            (:question_id, 1, 1, :correct, :wrong, :difficulty, NOW())\n        ON DUPLICATE KEY UPDATE\n            times_shown = times_shown + 1,\n            times_answered = times_answered + 1,\n            times_correct = times_correct + VALUES(times_correct),\n            times_wrong = times_wrong + VALUES(times_wrong),\n            calculated_difficulty = LEAST(0.950, GREATEST(0.050, (times_wrong + VALUES(times_wrong)) / NULLIF((times_answered + 1), 0))),\n            last_answered_at = NOW()\n    ");

    $stmt->execute([
        'question_id' => $questionId,
        'correct' => $isCorrect ? 1 : 0,
        'wrong' => $isCorrect ? 0 : 1,
        'difficulty' => $isCorrect ? 0.200 : 0.700,
    ]);
}
