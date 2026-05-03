<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/user_data.php';

header('Content-Type: application/json; charset=utf-8');

$userId = elevaro_current_user_id();

if (!$userId) {
    echo json_encode([
        'success' => true,
        'logged_in' => false,
        'progress' => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $quizId = (int)($_GET['quiz_id'] ?? 0);

    if (!$quizId) {
        throw new RuntimeException('quiz_id fehlt.');
    }

    echo json_encode([
        'success' => true,
        'logged_in' => true,
        'progress' => elevaro_get_user_quiz_progress($userId, $quizId),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
