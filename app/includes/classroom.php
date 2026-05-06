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
        status VARCHAR(32) NOT NULL DEFAULT 'online',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NULL,
        UNIQUE KEY uniq_classroom_guest_token (guest_token),
        KEY idx_classroom_participants_class (class_id),
        KEY idx_classroom_participants_user (user_id),
        KEY idx_classroom_participants_seen (last_seen_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
        KEY idx_classroom_duels_challenged (challenged_participant_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
    // QR-/Einladungslink: Der Code ist direkt in der Klassenraum-URL enthalten.
    // Ohne aktive Session leitet classroom.php automatisch auf den Namens-Beitritt weiter.
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

    // Backwards compatibility: older teacher/admin flows created class_codes.
    // If such a code is linked to a teacher_class, resolve it to the Klassenraum.
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

function classroom_pick_avatar(string $name): string
{
    $avatars = ['🦊','🐼','🐧','🦉','🐸','🐯','🐨','🦁','🐢','🐝','🐬','🦄'];
    return $avatars[abs(crc32(mb_strtolower($name, 'UTF-8'))) % count($avatars)];
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
    $stmt = classroom_db()->prepare("INSERT INTO classroom_participants (class_id, user_id, display_name, guest_token, avatar_emoji, last_seen_at)
        VALUES (:class_id, :user_id, :display_name, :guest_token, :avatar_emoji, NOW())");
    $stmt->execute([
        'class_id' => (int)$class['id'],
        'user_id' => $user ? (int)$user['id'] : null,
        'display_name' => $displayName,
        'guest_token' => $token,
        'avatar_emoji' => classroom_pick_avatar($displayName),
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
    $stmt = classroom_db()->prepare("SELECT a.*, p.display_name, p.avatar_emoji FROM classroom_activities a LEFT JOIN classroom_participants p ON p.id = a.participant_id WHERE a.class_id = :class_id ORDER BY a.created_at DESC, a.id DESC LIMIT " . max(1, min(50, $limit)));
    $stmt->execute(['class_id' => $classId]);
    return $stmt->fetchAll();
}

function classroom_assigned_quizzes(int $classId): array
{
    $stmt = classroom_db()->prepare("SELECT q.* FROM teacher_class_quizzes tcq JOIN quizzes q ON q.id = tcq.quiz_id WHERE tcq.class_id = :class_id AND q.is_active = 1 ORDER BY tcq.sort_order, q.title");
    $stmt->execute(['class_id' => $classId]);
    return $stmt->fetchAll();
}

classroom_ensure_schema();
