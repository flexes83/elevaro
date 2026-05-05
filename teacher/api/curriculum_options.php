<?php

declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function teacher_api_json(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $state = strtoupper(trim((string)($_GET['state'] ?? 'BW')));
    $schoolType = trim((string)($_GET['school_type'] ?? ''));
    $level = trim((string)($_GET['level'] ?? ''));
    $mode = trim((string)($_GET['mode'] ?? ''));

    if ($mode === 'school_types') {
        teacher_api_json([
            'ok' => true,
            'items' => curriculum_school_types($state),
        ]);
    }

    if ($mode === 'levels') {
        if ($schoolType === '') {
            teacher_api_json(['ok' => true, 'items' => [], 'school_mode' => 'grade', 'label' => 'Klasse']);
        }

        $isVocational = curriculum_is_vocational_school_type($state, $schoolType);
        $items = curriculum_levels($state, $schoolType);

        teacher_api_json([
            'ok' => true,
            'items' => $items,
            'school_mode' => $isVocational ? 'course' : 'grade',
            'label' => $isVocational ? 'Kurs / Stufe' : 'Klasse',
        ]);
    }

    if ($mode === 'subjects') {
        if ($schoolType === '' || $level === '') {
            teacher_api_json(['ok' => true, 'items' => []]);
        }

        teacher_api_json([
            'ok' => true,
            'items' => curriculum_subjects($state, $schoolType, $level),
        ]);
    }

    teacher_api_json(['ok' => false, 'error' => 'Ungültiger API-Modus.']);
} catch (Throwable $e) {
    http_response_code(500);
    teacher_api_json(['ok' => false, 'error' => $e->getMessage()]);
}
