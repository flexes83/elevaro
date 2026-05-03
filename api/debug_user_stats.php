<?php
require_once __DIR__ . '/../app/includes/user_data.php';
header('Content-Type: application/json; charset=utf-8');
$userId = elevaro_current_user_id();
$quizId = (int)($_GET['quiz_id'] ?? 0);
echo json_encode([
  'logged_in' => (bool)$userId,
  'user_id' => $userId,
  'quiz_id' => $quizId,
  'tables' => [
    'user_answer_events' => elevaro_table_exists('user_answer_events'),
    'user_question_progress' => elevaro_table_exists('user_question_progress'),
    'user_quiz_sessions' => elevaro_table_exists('user_quiz_sessions'),
  ],
  'progress' => ($userId && $quizId) ? elevaro_get_user_quiz_progress($userId, $quizId) : null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
