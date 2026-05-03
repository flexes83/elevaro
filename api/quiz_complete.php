<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/user_data.php';

header('Content-Type: application/json; charset=utf-8');

$userId = elevaro_current_user_id();

if (!$userId) {
    echo json_encode(['success' => true, 'logged_in' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    $sessionId = (int)($payload['quiz_session_id'] ?? 0);

    if ($sessionId) {
        elevaro_complete_quiz_session($userId, $sessionId);
    }

    echo json_encode(['success' => true, 'logged_in' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
