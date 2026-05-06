<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/classroom.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $payload = $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (json_decode(file_get_contents('php://input') ?: '[]', true) ?: [])
        : $_GET;

    if (!is_array($payload)) $payload = [];

    $classId = (int)($payload['class_id'] ?? 0);
    $duelId = (int)($payload['duel_id'] ?? 0);
    $action = (string)($payload['action'] ?? 'state');

    $class = $classId ? classroom_by_id($classId) : null;
    if (!$class || !$duelId) {
        throw new RuntimeException('Duell nicht gefunden.');
    }

    $participant = classroom_current_participant($classId);
    if (!$participant) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'classroom_not_joined'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    classroom_touch((int)$participant['id']);

    if ($action === 'answer') {
        $questionId = (int)($payload['question_id'] ?? 0);
        $selected = (string)($payload['selected_answer'] ?? '');
        $responseTimeMs = isset($payload['response_time_ms']) ? max(0, (int)$payload['response_time_ms']) : null;
        if (!$questionId) throw new RuntimeException('question_id fehlt.');
        classroom_record_duel_answer($classId, $duelId, (int)$participant['id'], $questionId, $selected, $responseTimeMs);
    }

    if ($action === 'rematch') {
        classroom_create_rematch($classId, (int)$participant['id'], $duelId);
        echo json_encode([
            'success' => true,
            'redirect' => '/classroom.php?class_id=' . $classId,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['success' => true] + classroom_duel_state($classId, $duelId, (int)$participant['id']), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
