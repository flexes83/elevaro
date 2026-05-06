<?php
require_once __DIR__ . '/../app/includes/classroom.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $classId = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
    $class = $classId ? classroom_by_id($classId) : null;
    if (!$class) throw new RuntimeException('Klassenraum nicht gefunden.');
    $participant = classroom_current_participant($classId);
    if (!$participant) throw new RuntimeException('Nicht im Klassenraum.');
    classroom_touch((int)$participant['id']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'duel') {
        $targetId = (int)($_POST['target_id'] ?? 0);
        if ($targetId && $targetId !== (int)$participant['id']) {
            $pdo = classroom_db();
            $check = $pdo->prepare("SELECT display_name FROM classroom_participants WHERE id = :id AND class_id = :class_id LIMIT 1");
            $check->execute(['id' => $targetId, 'class_id' => $classId]);
            $target = $check->fetch();
            if ($target) {
                $pdo->prepare("INSERT INTO classroom_duel_challenges (class_id, challenger_participant_id, challenged_participant_id) VALUES (:class_id, :challenger, :challenged)")
                    ->execute(['class_id' => $classId, 'challenger' => (int)$participant['id'], 'challenged' => $targetId]);
                classroom_log_activity($classId, (int)$participant['id'], 'duel', $participant['display_name'] . ' fordert ' . $target['display_name'] . ' zum Quizduell heraus.', '');
            }
        }
    }

    $online = array_map(static fn($p) => [
        'id' => (int)$p['id'],
        'name' => $p['display_name'],
        'avatar' => $p['avatar_emoji'],
        'is_me' => (int)$p['id'] === (int)$participant['id'],
    ], classroom_online_participants($classId));
    $activities = array_map(static fn($a) => [
        'title' => $a['title'],
        'avatar' => $a['avatar_emoji'] ?: '✨',
        'time' => date('H:i', strtotime((string)$a['created_at'])),
    ], classroom_recent_activities($classId));
    echo json_encode(['ok' => true, 'online' => $online, 'activities' => $activities], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
