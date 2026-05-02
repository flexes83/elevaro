<?php

require_once __DIR__ . '/../app/includes/curriculum.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $state = $_GET['state'] ?? '';
    $schoolType = $_GET['school_type'] ?? '';
    $grade = (int)($_GET['grade'] ?? 0);
    $subject = $_GET['subject'] ?? null;

    if (!$state || !$schoolType || !$grade) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required parameters'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'items' => curriculum_recommendations($state, $schoolType, $grade, $subject ?: null)
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
