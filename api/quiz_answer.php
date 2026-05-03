<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/user_data.php';

header('Content-Type: application/json; charset=utf-8');

$userId = elevaro_current_user_id();

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);

    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON.');
    }

    $quizId = (int)($payload['quiz_id'] ?? 0);
    $questionId = (int)($payload['question_id'] ?? 0);
    $sessionId = isset($payload['quiz_session_id']) ? (int)$payload['quiz_session_id'] : null;
    $selectedAnswer = (string)($payload['selected_answer'] ?? '');
    $correctAnswer = (string)($payload['correct_answer'] ?? '');
    $isCorrect = (bool)($payload['is_correct'] ?? false);
    $points = (int)($payload['points'] ?? 0);

    if (!$quizId || !$questionId) {
        throw new RuntimeException('quiz_id und question_id sind erforderlich.');
    }

    $progress = elevaro_record_user_answer(
        $userId,
        $quizId,
        $questionId,
        $selectedAnswer,
        $correctAnswer,
        $isCorrect,
        $sessionId,
        $points
    );

    echo json_encode([
        'success' => true,
        'progress' => $progress,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
