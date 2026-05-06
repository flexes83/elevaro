<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/includes/teacher_ai_wizard.php';

try {
    $teacherId = teacher_current_user_id();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $draftId = (int)($input['draft_id'] ?? 0);
    if (!empty($input['payload']) && is_array($input['payload'])) {
        elevaro_teacher_ai_save_payload($draftId, $teacherId, (array)$input['payload']);
    }
    $quizId = elevaro_teacher_ai_publish_draft($draftId, $teacherId);
    elevaro_teacher_ai_json_response([
        'ok' => true,
        'quiz_id' => $quizId,
        'edit_url' => '/admin/quiz_questions.php?quiz_id=' . $quizId,
        'class_quizzes_url' => '/teacher/quizzes.php?class_id=' . (int)(elevaro_teacher_ai_load_draft($draftId, $teacherId)['class_id'] ?? 0),
    ]);
} catch (Throwable $e) {
    elevaro_teacher_ai_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
