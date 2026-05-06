<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/includes/teacher_ai_wizard.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Ungültige Anfrage.');
    $teacherId = teacher_current_user_id();
    $classId = (int)($_POST['class_id'] ?? 0);
    $mode = ($_POST['mode'] ?? 'quiz') === 'listening' ? 'listening' : 'quiz';
    $class = elevaro_teacher_ai_class_for_teacher($classId, $teacherId);
    $sourceText = trim((string)($_POST['source_text'] ?? ''));
    $extraPrompt = trim((string)($_POST['extra_prompt'] ?? ''));
    $files = elevaro_teacher_ai_collect_files('source_files');

    if ($sourceText === '' && !$files) {
        throw new RuntimeException('Bitte Material hochladen oder einen Text/eine Aufgabenstellung eingeben.');
    }

    $result = elevaro_teacher_ai_generate_from_material($class, $mode, $sourceText, $extraPrompt, $files);
    $payload = elevaro_teacher_ai_normalize_payload($result['json'], $mode);
    $draftId = elevaro_teacher_ai_create_draft($teacherId, $classId, $mode, $sourceText, $extraPrompt, $files, $payload, $result['prompt_log'] ?? $result['content']);

    elevaro_teacher_ai_json_response([
        'ok' => true,
        'draft_id' => $draftId,
        'payload' => $payload,
        'message' => 'Quiz-Entwurf wurde erstellt.',
    ]);
} catch (Throwable $e) {
    elevaro_teacher_ai_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
