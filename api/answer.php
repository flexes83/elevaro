<?php

require_once __DIR__ . '/../app/includes/quiz_repository.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON payload.');
    }

    $quizId = (int)($payload['quiz_id'] ?? 0);
    $questionId = (int)($payload['question_id'] ?? 0);
    $selectedAnswer = isset($payload['selected_answer']) ? (string)$payload['selected_answer'] : null;
    $isCorrect = !empty($payload['is_correct']);
    $sessionId = isset($payload['session_id']) ? (string)$payload['session_id'] : null;
    $responseTimeMs = isset($payload['response_time_ms']) ? (int)$payload['response_time_ms'] : null;

    if (!$quizId || !$questionId) {
        throw new RuntimeException('Missing quiz_id or question_id.');
    }

    elevaro_record_answer_event(
        $quizId,
        $questionId,
        $selectedAnswer,
        $isCorrect,
        $sessionId,
        $responseTimeMs
    );

    echo json_encode([
        'success' => true
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
