<?php

require_once __DIR__ . '/../app/includes/curriculum.php';
require_once __DIR__ . '/../app/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $state = $_GET['state'] ?? '';
    $schoolType = $_GET['school_type'] ?? '';
    $grade = (int)($_GET['grade'] ?? 0);
    $subject = $_GET['subject'] ?? null;
    $topic = $_GET['topic'] ?? null;
    $tags = $_GET['tags'] ?? null;

    if (!$grade) {
        throw new RuntimeException('Klasse fehlt.');
    }

    $items = curriculum_recommendations($state, $schoolType, $grade, $subject ?: null, $topic ?: null, $tags ?: null);

    $canEdit = auth_is_admin();

    foreach ($items as &$item) {
        $item['can_edit'] = $canEdit;

        $total = (int)($item['question_count'] ?? 0);
        $item['progress_total'] = $total;
        $item['progress_passed'] = (int)($item['progress_passed'] ?? 0);
        $item['progress_failed'] = (int)($item['progress_failed'] ?? 0);
        $item['progress_unanswered'] = max($total - $item['progress_passed'] - $item['progress_failed'], 0);
        $item['progress_attempted'] = (int)($item['progress_attempted'] ?? 0);
    }
    unset($item);

    echo json_encode([
        'success' => true,
        'items' => $items
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
