<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
require_once __DIR__ . '/../../app/includes/teacher_ai_wizard.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $teacherId = elevaro_teacher_ai_current_teacher_id();
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Ungültige Anfrage.');
    }

    $draftId = (int)($data['draft_id'] ?? 0);
    if ($draftId <= 0) {
        throw new RuntimeException('Entwurf fehlt.');
    }

    $analysis = $data['analysis'] ?? [];
    if (!is_array($analysis)) {
        throw new RuntimeException('Analyse fehlt.');
    }

    echo json_encode(elevaro_teacher_ai_confirm_analysis($draftId, $teacherId, $analysis), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
