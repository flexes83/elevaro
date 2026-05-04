<?php

require_once __DIR__ . '/../app/includes/curriculum.php';
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/user_data.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $state = $_GET['state'] ?? '';
    $schoolType = $_GET['school_type'] ?? '';
    $grade = $_GET['grade'] ?? '';
    $subject = $_GET['subject'] ?? null;
    $topic = $_GET['topic'] ?? null;
    $tags = $_GET['tags'] ?? null;
    if ($grade === '') throw new RuntimeException('Klasse/Stufe fehlt.');
    $items = curriculum_recommendations($state, $schoolType, $grade, $subject ?: null, $topic ?: null, $tags ?: null);
    $canEdit = auth_is_admin();
    $userId = elevaro_current_user_id();
    foreach ($items as &$item) {
        $item['can_edit'] = $canEdit;
        $total = (int)($item['question_count'] ?? 0);
        $item['progress_total'] = $total;
        $item['progress_passed'] = 0;
        $item['progress_failed'] = 0;
        $item['progress_unanswered'] = $total;
        $item['progress_attempted'] = 0;
        if ($userId && !empty($item['quiz_id'])) {
            foreach (elevaro_get_user_quiz_progress($userId, (int)$item['quiz_id']) as $key=>$value) $item[$key] = $value;
        }
    }
    unset($item);
    echo json_encode(['success'=>true,'logged_in'=>(bool)$userId,'user_id'=>$userId,'items'=>$items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
