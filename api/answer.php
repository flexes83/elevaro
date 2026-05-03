<?php

require_once __DIR__ . '/../app/includes/quiz_repository.php';
require_once __DIR__ . '/../app/includes/user_data.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON payload.');
    }

    $quizId = (int)($payload['quiz_id'] ?? 0);
    $questionId = (int)($payload['question_id'] ?? 0);
    $selectedAnswer = (string)($payload['selected_answer'] ?? '');
    $correctAnswer = (string)($payload['correct_answer'] ?? '');
    $isCorrect = !empty($payload['is_correct']);
    $sessionId = isset($payload['session_id']) && is_numeric($payload['session_id']) ? (int)$payload['session_id'] : null;
    $legacySessionId = isset($payload['session_token']) ? (string)$payload['session_token'] : (isset($payload['session_id']) ? (string)$payload['session_id'] : null);
    $responseTimeMs = isset($payload['response_time_ms']) ? (int)$payload['response_time_ms'] : null;
    $points = isset($payload['points']) ? (int)$payload['points'] : ($isCorrect ? 10 : 0);

    if (!$quizId || !$questionId) {
        throw new RuntimeException('Missing quiz_id or question_id.');
    }

    try {
        elevaro_record_answer_event($quizId, $questionId, $selectedAnswer, $isCorrect, $legacySessionId, $responseTimeMs);
    } catch (Throwable $legacyError) {
        error_log('Elevaro legacy answer tracking failed: ' . $legacyError->getMessage());
    }

    $progress = null;
    $userId = elevaro_current_user_id();
    $userError = null;

    if ($userId) {
        try {
            $progress = elevaro_record_user_answer(
                $userId,
                $quizId,
                $questionId,
                $selectedAnswer,
                $correctAnswer,
                $isCorrect,
                $sessionId,
                $points,
                $responseTimeMs
            );
        } catch (Throwable $e) {
            $userError = $e->getMessage();
            error_log('Elevaro user stats failed: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'logged_in' => (bool)$userId,
        'user_id' => $userId,
        'user_stats_error' => $userError,
        'progress' => $progress,
        'tables' => [
            'user_answer_events' => elevaro_table_exists('user_answer_events'),
            'user_question_progress' => elevaro_table_exists('user_question_progress'),
            'user_quiz_sessions' => elevaro_table_exists('user_quiz_sessions'),
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
