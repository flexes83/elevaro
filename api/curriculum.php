<?php

require_once __DIR__ . '/../app/includes/curriculum.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'states':
            echo json_encode(['success' => true, 'items' => curriculum_states()], JSON_UNESCAPED_UNICODE);
            break;

        case 'school_types':
            echo json_encode(['success' => true, 'items' => curriculum_school_types($_GET['state'] ?? '')], JSON_UNESCAPED_UNICODE);
            break;


        case 'education_tracks':
            echo json_encode([
                'success' => true,
                'items' => curriculum_education_tracks($_GET['state'] ?? '', $_GET['school_type'] ?? '')
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'education_track_levels':
            echo json_encode([
                'success' => true,
                'items' => curriculum_education_track_levels($_GET['state'] ?? '', $_GET['school_type'] ?? '', $_GET['track'] ?? '')
            ], JSON_UNESCAPED_UNICODE);
            break;

        
        case 'levels':
            echo json_encode([
                'success' => true,
                'items' => curriculum_levels($_GET['state'] ?? '', $_GET['school_type'] ?? '')
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'grades':

            echo json_encode(['success' => true, 'items' => curriculum_grades($_GET['state'] ?? '', $_GET['school_type'] ?? '')], JSON_UNESCAPED_UNICODE);
            break;

        case 'subjects':
            echo json_encode([
                'success' => true,
                'items' => curriculum_subjects($_GET['state'] ?? '', $_GET['school_type'] ?? '', (int)($_GET['grade'] ?? 0))
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'topics':
            echo json_encode([
                'success' => true,
                'items' => curriculum_learning_areas(
                    $_GET['state'] ?? '',
                    $_GET['school_type'] ?? '',
                    (int)($_GET['grade'] ?? 0),
                    $_GET['subject'] ?? ''
                )
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
