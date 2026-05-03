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

try {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    $quizId = (int)($payload['quiz_id'] ?? 0);
    $sessionToken = isset($payload['session_token']) ? (string)$payload['session_token'] : null;

    if (!$quizId) {
        throw new RuntimeException('quiz_id fehlt.');
    }

    echo json_encode([
        'success' => true,
        'quiz_session_id' => elevaro_start_quiz_session($userId, $quizId, $sessionToken),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
