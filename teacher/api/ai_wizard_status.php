<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/includes/teacher_ai_wizard.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Ungültige Anfrage.');
    $input = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $draftId = (int)($input['draft_id'] ?? 0);
    if ($draftId <= 0) throw new RuntimeException('Entwurf fehlt.');

    $teacherId = teacher_current_user_id();
    elevaro_teacher_ai_json_response(elevaro_teacher_ai_poll_split_draft($draftId, $teacherId));
} catch (Throwable $e) {
    elevaro_teacher_ai_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
