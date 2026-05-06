<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/includes/teacher_ai_wizard.php';

try {
    $teacherId = teacher_current_user_id();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $draftId = (int)($input['draft_id'] ?? 0);
    $prompt = trim((string)($input['image_prompt'] ?? ''));
    $draft = elevaro_teacher_ai_load_draft($draftId, $teacherId);
    $payload = elevaro_teacher_ai_draft_payload($draft);
    if ($prompt === '') $prompt = trim((string)($payload['image_prompt'] ?? $draft['image_prompt'] ?? 'Modernes Lernkarten-Bild'));
    $generated = elevaro_generate_and_store_image($prompt, 'teacher-ai-quiz-cards', $draftId);
    elevaro_teacher_ai_wizard_db()->prepare("UPDATE teacher_ai_quiz_drafts SET image_path = :path, image_prompt = :prompt, image_status = 'draft' WHERE id = :id AND teacher_id = :teacher_id")
        ->execute(['path' => $generated['path'], 'prompt' => $prompt, 'id' => $draftId, 'teacher_id' => $teacherId]);
    elevaro_teacher_ai_json_response(['ok' => true, 'image_path' => $generated['path'], 'image_prompt' => $prompt]);
} catch (Throwable $e) {
    elevaro_teacher_ai_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
