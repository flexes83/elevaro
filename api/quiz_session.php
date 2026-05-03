<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/includes/user_data.php';
header('Content-Type: application/json; charset=utf-8');
$userId = elevaro_current_user_id();
if (!$userId) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'not_logged_in']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'method_not_allowed']); exit; }
try {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $quizId = (int)($payload['quiz_id'] ?? 0);
    if (!$quizId) throw new RuntimeException('quiz_id fehlt.');
    $token = isset($payload['session_token']) ? (string)$payload['session_token'] : null;
    echo json_encode(['success'=>true,'logged_in'=>true,'user_id'=>$userId,'quiz_session_id'=>elevaro_start_quiz_session($userId,$quizId,$token)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) { http_response_code(500); echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE); }
