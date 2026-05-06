<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/access.php';

const ELEVARO_CLASSROOM_SESSION_KEY = 'elevaro_classroom_participant_id';

function classroom_db(): PDO { return elevaro_db(); }
function classroom_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function classroom_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = classroom_db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS classroom_participants (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        class_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NULL,
        display_name VARCHAR(160) NOT NULL,
        guest_token VARCHAR(96) NULL,
        avatar_emoji VARCHAR(16) NOT NULL DEFAULT '🙂',
        avatar_type VARCHAR(20) NOT NULL DEFAULT 'emoji',
        avatar_gradient VARCHAR(32) NOT NULL DEFAULT 'grad-1',
        status VARCHAR(32) NOT NULL DEFAULT 'online',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NULL,
        UNIQUE KEY uniq_classroom_guest_token (guest_token),
        KEY idx_classroom_participants_class (class_id),
        KEY idx_classroom_participants_user (user_id),
        KEY idx_classroom_participants_seen (last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        'avatar_type' => "ALTER TABLE classroom_participants ADD COLUMN avatar_type VARCHAR(20) NOT NULL DEFAULT 'emoji' AFTER avatar_emoji",
        'avatar_gradient' => "ALTER TABLE classroom_participants ADD COLUMN avatar_gradient VARCHAR(32) NOT NULL DEFAULT 'grad-1' AFTER avatar_type",
    ] as $column => $sql) {
        if (!elevaro_access_column_exists('classroom_participants', $column)) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS classroom_activities (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        class_id INT UNSIGNED NOT NULL,
        participant_id INT UNSIGNED NULL,
        type VARCHAR(64) NOT NULL,
        title VARCHAR(190) NOT NULL,
        body TEXT NULL,
        payload JSON NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_classroom_activities_class (class_id, created_at),
        KEY idx_classroom_activities_participant (participant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS classroom_duel_challenges (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        class_id INT UNSIGNED NOT NULL,
        challenger_participant_id INT UNSIGNED NOT NULL,
        challenged_participant_id INT UNSIGNED NOT NULL,
        quiz_id INT UNSIGNED NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        responded_at DATETIME NULL,
        KEY idx_classroom_duels_class (class_id, created_at),
        KEY idx_classroom_duels_challenged (challenged_participant_id, status),
        KEY idx_classroom_duels_challenger (challenger_participant_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        'quiz_id' => "ALTER TABLE classroom_duel_challenges ADD COLUMN quiz_id INT UNSIGNED NULL AFTER challenged_participant_id",
        'responded_at' => "ALTER TABLE classroom_duel_challenges ADD COLUMN responded_at DATETIME NULL AFTER created_at",
    ] as $column => $sql) {
        if (!elevaro_access_column_exists('classroom_duel_challenges', $column)) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
    }

    if (elevaro_access_column_exists('teacher_classes', 'allow_guest_join') === false) {
        try { $pdo->exec("ALTER TABLE teacher_classes ADD COLUMN allow_guest_join TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active"); } catch (Throwable $e) {}
    }
}

function classroom_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'elevaro.app';
    return $scheme . '://' . $host;
}

function classroom_join_url(array $class): string
{
    return classroom_base_url() . '/classroom.php?code=' . urlencode((string)$class['invite_code']);
}

function classroom_by_code(string $code): ?array
{
    classroom_ensure_schema();
    $code = strtoupper(trim($code));
    if ($code === '') return null;

    $stmt = classroom_db()->prepare("SELECT * FROM teacher_classes WHERE invite_code = :code AND is_active = 1 LIMIT 1");
    $stmt->execute(['code' => $code]);
    $class = $stmt->fetch();
    if ($class) return $class;

    if (elevaro_access_table_exists('class_codes') && elevaro_access_column_exists('class_codes', 'class_id')) {
        $stmt = classroom_db()->prepare("
            SELECT tc.*
            FROM class_codes cc
            JOIN teacher_classes tc ON tc.id = cc.class_id
            WHERE cc.code = :code
              AND cc.is_active = 1
              AND (cc.expires_at IS NULL OR cc.expires_at >= NOW())
              AND tc.is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['code' => $code]);
        $class = $stmt->fetch();
        if ($class) return $class;
    }

    return null;
}

function classroom_by_id(int $classId): ?array
{
    classroom_ensure_schema();
    $stmt = classroom_db()->prepare("SELECT * FROM teacher_classes WHERE id = :id AND is_active = 1 LIMIT 1");
    $stmt->execute(['id' => $classId]);
    return $stmt->fetch() ?: null;
}

function classroom_label(array $class): string
{
    return (string)($class['name'] ?? ('Klasse ' . ($class['invite_code'] ?? '')));
}

function classroom_current_participant(?int $classId = null): ?array
{
    classroom_ensure_schema();
    auth_start_session();
    $participantId = (int)($_SESSION[ELEVARO_CLASSROOM_SESSION_KEY] ?? 0);
    if (!$participantId) return null;
    $sql = "SELECT * FROM classroom_participants WHERE id = :id" . ($classId ? " AND class_id = :class_id" : "") . " LIMIT 1";
    $params = ['id' => $participantId];
    if ($classId) $params['class_id'] = $classId;
    $stmt = classroom_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function classroom_avatar_options(): array
{
    return ['🦊','🐼','🐧','🦉','🐸','🐯','🐨','🦁','🐢','🐝','🐬','🦄','🐙','🦖','🐳','🦋','🌟','⚡','🚀','🎧'];
}

function classroom_gradient_options(): array
{
    return ['grad-1','grad-2','grad-3','grad-4','grad-5','grad-6','grad-7','grad-8'];
}

function classroom_initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '') $letters .= mb_substr($part, 0, 1, 'UTF-8');
        if (mb_strlen($letters, 'UTF-8') >= 2) break;
    }
    return mb_strtoupper($letters ?: '🙂', 'UTF-8');
}

function classroom_pick_avatar(string $name): string
{
    $avatars = classroom_avatar_options();
    return $avatars[abs(crc32(mb_strtolower($name, 'UTF-8'))) % count($avatars)];
}

function classroom_pick_gradient(string $name): string
{
    $gradients = classroom_gradient_options();
    return $gradients[abs(crc32('gradient-' . mb_strtolower($name, 'UTF-8'))) % count($gradients)];
}

function classroom_avatar_payload(array $participant): array
{
    return [
        'type' => (string)($participant['avatar_type'] ?? 'emoji'),
        'value' => (string)($participant['avatar_emoji'] ?? '🙂'),
        'gradient' => (string)($participant['avatar_gradient'] ?? 'grad-1'),
    ];
}

function classroom_update_avatar(int $participantId, int $classId, string $type, string $value, string $gradient): array
{
    classroom_ensure_schema();
    $type = $type === 'initials' ? 'initials' : 'emoji';
    $gradient = in_array($gradient, classroom_gradient_options(), true) ? $gradient : 'grad-1';

    if ($type === 'emoji') {
        $value = trim($value);
        if (!in_array($value, classroom_avatar_options(), true)) {
            throw new RuntimeException('Dieses Emoji steht nicht zur Auswahl.');
        }
    } else {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]/u', '', $value) ?: '';
        if ($value === '' || mb_strlen($value, 'UTF-8') > 3) {
            throw new RuntimeException('Bitte nutze 1 bis 3 Initialen.');
        }
    }

    $stmt = classroom_db()->prepare("SELECT id FROM classroom_participants WHERE class_id = :class_id AND id <> :id AND avatar_type = :type AND BINARY avatar_emoji = BINARY :value LIMIT 1");
    $stmt->execute(['class_id' => $classId, 'id' => $participantId, 'type' => $type, 'value' => $value]);
    if ($stmt->fetch()) {
        throw new RuntimeException('Dieser Avatar ist im Klassenraum schon vergeben.');
    }

    classroom_db()->prepare("UPDATE classroom_participants SET avatar_type = :type, avatar_emoji = :value, avatar_gradient = :gradient WHERE id = :id AND class_id = :class_id")
        ->execute(['type' => $type, 'value' => $value, 'gradient' => $gradient, 'id' => $participantId, 'class_id' => $classId]);

    $participant = classroom_current_participant($classId);
    if ($participant) {
        classroom_log_activity($classId, $participantId, 'avatar', $participant['display_name'] . ' hat den Avatar geändert.', '');
    }
    return classroom_current_participant($classId) ?: [];
}

function classroom_join_guest(array $class, string $displayName): array
{
    classroom_ensure_schema();
    auth_start_session();
    $displayName = trim(preg_replace('/\s+/', ' ', $displayName));
    if ($displayName === '' || mb_strlen($displayName, 'UTF-8') < 2) throw new RuntimeException('Bitte gib deinen Namen ein.');
    if (mb_strlen($displayName, 'UTF-8') > 80) $displayName = mb_substr($displayName, 0, 80, 'UTF-8');

    $user = auth_user();
    $token = $user ? null : bin2hex(random_bytes(24));
    $avatar = classroom_pick_avatar($displayName);
    $gradient = classroom_pick_gradient($displayName);
    $type = 'emoji';

    $stmt = classroom_db()->prepare("SELECT id FROM classroom_participants WHERE class_id = :class_id AND avatar_type = 'emoji' AND BINARY avatar_emoji = BINARY :avatar LIMIT 1");
    $stmt->execute(['class_id' => (int)$class['id'], 'avatar' => $avatar]);
    if ($stmt->fetch()) {
        $type = 'initials';
        $avatar = classroom_initials($displayName);
    }

    $stmt = classroom_db()->prepare("INSERT INTO classroom_participants (class_id, user_id, display_name, guest_token, avatar_emoji, avatar_type, avatar_gradient, last_seen_at)
        VALUES (:class_id, :user_id, :display_name, :guest_token, :avatar_emoji, :avatar_type, :avatar_gradient, NOW())");
    $stmt->execute([
        'class_id' => (int)$class['id'],
        'user_id' => $user ? (int)$user['id'] : null,
        'display_name' => $displayName,
        'guest_token' => $token,
        'avatar_emoji' => $avatar,
        'avatar_type' => $type,
        'avatar_gradient' => $gradient,
    ]);
    $id = (int)classroom_db()->lastInsertId();
    $_SESSION[ELEVARO_CLASSROOM_SESSION_KEY] = $id;
    classroom_log_activity((int)$class['id'], $id, 'join', $displayName . ' ist dem Klassenraum beigetreten.', '');
    return classroom_current_participant((int)$class['id']);
}

function classroom_touch(int $participantId): void
{
    classroom_db()->prepare("UPDATE classroom_participants SET last_seen_at = NOW(), status = 'online' WHERE id = :id")->execute(['id' => $participantId]);
}

function classroom_log_activity(int $classId, ?int $participantId, string $type, string $title, string $body = '', array $payload = []): void
{
    classroom_ensure_schema();
    $stmt = classroom_db()->prepare("INSERT INTO classroom_activities (class_id, participant_id, type, title, body, payload) VALUES (:class_id, :participant_id, :type, :title, :body, :payload)");
    $stmt->execute([
        'class_id' => $classId,
        'participant_id' => $participantId,
        'type' => $type,
        'title' => $title,
        'body' => $body,
        'payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

function classroom_online_participants(int $classId): array
{
    $stmt = classroom_db()->prepare("SELECT * FROM classroom_participants WHERE class_id = :class_id AND last_seen_at >= (NOW() - INTERVAL 3 MINUTE) ORDER BY last_seen_at DESC, display_name ASC LIMIT 30");
    $stmt->execute(['class_id' => $classId]);
    return $stmt->fetchAll();
}

function classroom_recent_activities(int $classId, int $limit = 20): array
{
    $stmt = classroom_db()->prepare("SELECT a.*, p.display_name, p.avatar_emoji, p.avatar_type, p.avatar_gradient FROM classroom_activities a LEFT JOIN classroom_participants p ON p.id = a.participant_id WHERE a.class_id = :class_id ORDER BY a.created_at DESC, a.id DESC LIMIT " . max(1, min(50, $limit)));
    $stmt->execute(['class_id' => $classId]);
    return $stmt->fetchAll();
}

function classroom_assigned_quizzes(int $classId): array
{
    $stmt = classroom_db()->prepare("SELECT q.* FROM teacher_class_quizzes tcq JOIN quizzes q ON q.id = tcq.quiz_id WHERE tcq.class_id = :class_id AND q.is_active = 1 ORDER BY tcq.sort_order, q.title");
    $stmt->execute(['class_id' => $classId]);
    return $stmt->fetchAll();
}

function classroom_first_quiz(int $classId): ?array
{
    $quizzes = classroom_assigned_quizzes($classId);
    return $quizzes[0] ?? null;
}

function classroom_create_duel(int $classId, array $challenger, int $targetId): void
{
    $pdo = classroom_db();
    $stmt = $pdo->prepare("SELECT * FROM classroom_participants WHERE id = :id AND class_id = :class_id LIMIT 1");
    $stmt->execute(['id' => $targetId, 'class_id' => $classId]);
    $target = $stmt->fetch();
    if (!$target || (int)$target['id'] === (int)$challenger['id']) return;

    $stmt = $pdo->prepare("SELECT id FROM classroom_duel_challenges WHERE class_id = :class_id AND challenger_participant_id = :challenger AND challenged_participant_id = :challenged AND status = 'pending' AND created_at >= (NOW() - INTERVAL 2 MINUTE) LIMIT 1");
    $stmt->execute(['class_id' => $classId, 'challenger' => (int)$challenger['id'], 'challenged' => $targetId]);
    if ($stmt->fetch()) return;

    $quiz = classroom_first_quiz($classId);
    $pdo->prepare("INSERT INTO classroom_duel_challenges (class_id, challenger_participant_id, challenged_participant_id, quiz_id) VALUES (:class_id, :challenger, :challenged, :quiz_id)")
        ->execute(['class_id' => $classId, 'challenger' => (int)$challenger['id'], 'challenged' => $targetId, 'quiz_id' => $quiz ? (int)$quiz['id'] : null]);
    classroom_log_activity($classId, (int)$challenger['id'], 'duel', $challenger['display_name'] . ' fordert ' . $target['display_name'] . ' zum Quizduell heraus.', '');
}

function classroom_duel_url(array $duel): ?string
{
    $quizId = (int)($duel['quiz_id'] ?? 0);
    if (!$quizId) return null;
    $stmt = classroom_db()->prepare("SELECT quiz_key FROM quizzes WHERE id = :id AND is_active = 1 LIMIT 1");
    $stmt->execute(['id' => $quizId]);
    $quizKey = (string)($stmt->fetchColumn() ?: '');
    if ($quizKey === '') return null;
    return '/quiz.php?key=' . urlencode($quizKey) . '&class_id=' . (int)$duel['class_id'] . '&duel_id=' . (int)$duel['id'];
}

function classroom_duels_for_participant(int $classId, int $participantId): array
{
    $stmt = classroom_db()->prepare("
        SELECT d.*, challenger.display_name AS challenger_name, challenger.avatar_emoji AS challenger_avatar, challenger.avatar_type AS challenger_avatar_type, challenger.avatar_gradient AS challenger_avatar_gradient,
               challenged.display_name AS challenged_name, challenged.avatar_emoji AS challenged_avatar, challenged.avatar_type AS challenged_avatar_type, challenged.avatar_gradient AS challenged_avatar_gradient,
               q.title AS quiz_title, q.quiz_key
        FROM classroom_duel_challenges d
        JOIN classroom_participants challenger ON challenger.id = d.challenger_participant_id
        JOIN classroom_participants challenged ON challenged.id = d.challenged_participant_id
        LEFT JOIN quizzes q ON q.id = d.quiz_id
        WHERE d.class_id = :class_id
          AND (d.challenger_participant_id = :participant_id_challenger OR d.challenged_participant_id = :participant_id_challenged)
          AND d.status IN ('pending','accepted')
          AND d.created_at >= (NOW() - INTERVAL 20 MINUTE)
        ORDER BY d.created_at DESC, d.id DESC
        LIMIT 10
    ");
    $stmt->execute(['class_id' => $classId, 'participant_id_challenger' => $participantId, 'participant_id_challenged' => $participantId]);
    return $stmt->fetchAll();
}

function classroom_respond_duel(int $classId, int $participantId, int $duelId, string $status): ?array
{
    $status = $status === 'accepted' ? 'accepted' : 'declined';
    $stmt = classroom_db()->prepare("SELECT d.*, c.display_name AS challenger_name, p.display_name AS challenged_name FROM classroom_duel_challenges d JOIN classroom_participants c ON c.id = d.challenger_participant_id JOIN classroom_participants p ON p.id = d.challenged_participant_id WHERE d.id = :id AND d.class_id = :class_id AND d.challenged_participant_id = :participant_id AND d.status = 'pending' LIMIT 1");
    $stmt->execute(['id' => $duelId, 'class_id' => $classId, 'participant_id' => $participantId]);
    $duel = $stmt->fetch();
    if (!$duel) return null;
    classroom_db()->prepare("UPDATE classroom_duel_challenges SET status = :status, responded_at = NOW() WHERE id = :id")->execute(['status' => $status, 'id' => $duelId]);
    $title = $status === 'accepted'
        ? $duel['challenged_name'] . ' nimmt das Quizduell gegen ' . $duel['challenger_name'] . ' an.'
        : $duel['challenged_name'] . ' lehnt das Quizduell ab.';
    classroom_log_activity($classId, $participantId, 'duel_' . $status, $title, '');
    $duel['status'] = $status;
    return $duel;
}

classroom_ensure_schema();
