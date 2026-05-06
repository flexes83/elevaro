<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/includes/teacher_ai_wizard.php';

try {
    $teacherId = teacher_current_user_id();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $draftId = (int)($input['draft_id'] ?? 0);
    $payload = (array)($input['payload'] ?? []);
    elevaro_teacher_ai_save_payload($draftId, $teacherId, $payload);
    elevaro_teacher_ai_json_response(['ok' => true]);
} catch (Throwable $e) {
    elevaro_teacher_ai_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
