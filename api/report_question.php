<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/quality_review.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON.');
    }

    $user = auth_user();
    $payload['user_id'] = $user['id'] ?? null;

    $id = elevaro_report_question($payload);

    echo json_encode([
        'success' => true,
        'report_id' => $id,
        'message' => 'Danke, die Frage wurde gemeldet und wird geprüft.'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
