<?php
require_once __DIR__ . '/../app/includes/classroom.php';
header('Content-Type: application/json; charset=utf-8');

function classroom_api_avatar(array $p): array
{
    return [
        'type' => (string)($p['avatar_type'] ?? 'emoji'),
        'value' => (string)($p['avatar_emoji'] ?? '🙂'),
        'gradient' => (string)($p['avatar_gradient'] ?? 'grad-1'),
    ];
}

function classroom_api_state(int $classId, array $participant, array $extra = []): array
{
    $online = array_map(static fn($p) => [
        'id' => (int)$p['id'],
        'name' => $p['display_name'],
        'avatar' => classroom_api_avatar($p),
        'is_me' => (int)$p['id'] === (int)$participant['id'],
    ], classroom_online_participants($classId));

    $activities = array_map(static fn($a) => [
        'title' => $a['title'],
        'avatar' => [
            'type' => (string)($a['avatar_type'] ?? 'emoji'),
            'value' => (string)($a['avatar_emoji'] ?: '✨'),
            'gradient' => (string)($a['avatar_gradient'] ?? 'grad-1'),
        ],
        'time' => date('H:i', strtotime((string)$a['created_at'])),
    ], classroom_recent_activities($classId));

    $duels = array_map(static function ($d) use ($participant) {
        $isChallenged = (int)$d['challenged_participant_id'] === (int)$participant['id'];
        return [
            'id' => (int)$d['id'],
            'status' => $d['status'],
            'is_challenged' => $isChallenged,
            'title' => $isChallenged ? ($d['challenger_name'] . ' fordert dich heraus') : ('Duell mit ' . $d['challenged_name']),
            'quiz_title' => $d['quiz_title'] ?: 'Erstes Klassenquiz',
            'url' => classroom_duel_url($d),
        ];
    }, classroom_duels_for_participant($classId, (int)$participant['id']));

    return array_merge(['ok' => true, 'online' => $online, 'activities' => $activities, 'duels' => $duels], $extra);
}

try {
    $classId = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
    $class = $classId ? classroom_by_id($classId) : null;
    if (!$class) throw new RuntimeException('Klassenraum nicht gefunden.');
    $participant = classroom_current_participant($classId);
    if (!$participant) throw new RuntimeException('Nicht im Klassenraum.');
    classroom_touch((int)$participant['id']);

    $extra = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'duel') {
            classroom_create_duel($classId, $participant, (int)($_POST['target_id'] ?? 0));
        }

        if ($action === 'duel_accept' || $action === 'duel_decline') {
            $duel = classroom_respond_duel($classId, (int)$participant['id'], (int)($_POST['duel_id'] ?? 0), $action === 'duel_accept' ? 'accepted' : 'declined');
            if ($duel && $action === 'duel_accept') {
                $extra['duel_start_url'] = classroom_duel_url($duel);
            }
        }

        if ($action === 'avatar') {
            $participant = classroom_update_avatar(
                (int)$participant['id'],
                $classId,
                (string)($_POST['avatar_type'] ?? 'emoji'),
                (string)($_POST['avatar_value'] ?? ''),
                (string)($_POST['avatar_gradient'] ?? 'grad-1')
            );
            $extra['me'] = ['avatar' => classroom_api_avatar($participant), 'name' => $participant['display_name']];
        }
    }

    echo json_encode(classroom_api_state($classId, $participant, $extra), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
