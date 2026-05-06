<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/user_data.php';
require_once __DIR__ . '/../app/includes/access.php';
require_once __DIR__ . '/../app/includes/classroom.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($payload)) $payload = [];

    $quizId = (int)($payload['quiz_id'] ?? 0);
    $sessionToken = isset($payload['session_token']) ? (string)$payload['session_token'] : null;
    $roundQuestionCount = isset($payload['question_count']) ? max(1, min(50, (int)$payload['question_count'])) : null;
    $classId = (int)($payload['class_id'] ?? 0);
    $duelId = (int)($payload['duel_id'] ?? 0);

    if (!$quizId) {
        throw new RuntimeException('quiz_id fehlt.');
    }

    if ($classId) {
        $participant = classroom_current_participant($classId);
        if (!$participant) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'classroom_not_joined'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $classroomSessionId = classroom_find_or_start_quiz_session(
            $classId,
            (int)$participant['id'],
            $quizId,
            $sessionToken,
            $roundQuestionCount,
            $duelId ?: null
        );

        echo json_encode([
            'success' => true,
            'classroom' => true,
            'classroom_session_id' => $classroomSessionId,
            'quiz_session_id' => null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = elevaro_current_user_id();
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'not_logged_in'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    elevaro_track_quiz_start($userId);

    echo json_encode([
        'success' => true,
        'quiz_session_id' => elevaro_start_quiz_session($userId, $quizId, $sessionToken, $roundQuestionCount),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
