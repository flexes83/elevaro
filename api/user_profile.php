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
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            'success' => true,
            'profile' => elevaro_get_user_learning_profile($userId),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode(file_get_contents('php://input') ?: '[]', true);

        if (!is_array($payload)) {
            throw new RuntimeException('Invalid JSON.');
        }

        $profile = $payload['profile'] ?? $payload;
        if (!is_array($profile)) {
            throw new RuntimeException('Invalid profile.');
        }

        elevaro_save_user_learning_profile($userId, $profile);

        echo json_encode([
            'success' => true,
            'profile' => elevaro_get_user_learning_profile($userId),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
