<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/includes/user_data.php';
require_once __DIR__ . '/../app/includes/classroom.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $classId = (int)($payload['class_id'] ?? 0);
    $classroomSessionId = (int)($payload['classroom_session_id'] ?? 0);
    $quizId = (int)($payload['quiz_id'] ?? 0);
    $duelId = (int)($payload['duel_id'] ?? 0);
    $sessionToken = isset($payload['session_token']) ? (string)$payload['session_token'] : null;
    $roundQuestionCount = isset($payload['question_count']) ? max(1, min(50, (int)$payload['question_count'])) : null;

    if ($classId) {
        $participant = classroom_current_participant($classId);
        if (!$participant) {
            http_response_code(401);
            echo json_encode(['success'=>false,'error'=>'classroom_not_joined'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!$classroomSessionId) {
            if (!$quizId) {
                throw new RuntimeException('quiz_id fehlt für Klassenraum-Highscore.');
            }
            $classroomSessionId = classroom_find_or_start_quiz_session(
                $classId,
                (int)$participant['id'],
                $quizId,
                $sessionToken,
                $roundQuestionCount,
                $duelId ?: null
            );
        }

        $result = classroom_complete_quiz_session(
            $classId,
            (int)$participant['id'],
            $classroomSessionId,
            (int)($payload['score'] ?? 0),
            (int)($payload['total'] ?? 0),
            (int)($payload['points'] ?? 0),
            (int)($payload['best_streak'] ?? 0)
        );

        echo json_encode(['success'=>true,'classroom'=>true,'classroom_session_id'=>$classroomSessionId] + $result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = elevaro_current_user_id();
    if (!$userId) { echo json_encode(['success'=>true,'logged_in'=>false]); exit; }

    $sessionId = (int)($payload['quiz_session_id'] ?? 0);
    if ($sessionId) elevaro_complete_quiz_session($userId, $sessionId);
    echo json_encode(['success'=>true,'logged_in'=>true]);
} catch (Throwable $e) { http_response_code(500); echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE); }
