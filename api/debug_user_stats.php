<?php

require_once __DIR__ . '/../app/includes/user_data.php';

header('Content-Type: application/json; charset=utf-8');

$userId = elevaro_current_user_id();
$quizId = (int)($_GET['quiz_id'] ?? 0);

$out = [
    'logged_in' => (bool)$userId,
    'user_id' => $userId,
    'quiz_id' => $quizId,
    'tables' => [],
    'columns' => [],
    'progress' => null,
    'recent_events' => [],
    'database' => elevaro_db()->query('SELECT DATABASE()')->fetchColumn(),
];

foreach (['user_answer_events','user_question_progress','user_quiz_sessions','auth_users'] as $table) {
    $out['tables'][$table] = elevaro_table_exists($table);
    if ($out['tables'][$table]) {
        $stmt = elevaro_db()->query("SHOW COLUMNS FROM {$table}");
        $out['columns'][$table] = array_column($stmt->fetchAll(), 'Field');
    }
}

if ($userId && $quizId) {
    $out['progress'] = elevaro_get_user_quiz_progress($userId, $quizId);

    if (elevaro_table_exists('user_answer_events')) {
        $stmt = elevaro_db()->prepare("
            SELECT *
            FROM user_answer_events
            WHERE user_id = :user_id
              AND quiz_id = :quiz_id
            ORDER BY id DESC
            LIMIT 10
        ");
        $stmt->execute(['user_id' => $userId, 'quiz_id' => $quizId]);
        $out['recent_events'] = $stmt->fetchAll();
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
