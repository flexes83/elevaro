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

    $pdo->exec("CREATE TABLE IF NOT EXISTS classroom_quiz_sessions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        class_id INT UNSIGNED NOT NULL,
        participant_id INT UNSIGNED NOT NULL,
        quiz_id INT UNSIGNED NOT NULL,
        duel_id INT UNSIGNED NULL,
        session_token VARCHAR(120) NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        total_questions INT UNSIGNED NOT NULL DEFAULT 0,
        answered_questions INT UNSIGNED NOT NULL DEFAULT 0,
        correct_answers INT UNSIGNED NOT NULL DEFAULT 0,
        wrong_answers INT UNSIGNED NOT NULL DEFAULT 0,
        score_points INT UNSIGNED NOT NULL DEFAULT 0,
        best_streak INT UNSIGNED NOT NULL DEFAULT 0,
        percent_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        KEY idx_classroom_quiz_sessions_class (class_id, completed_at),
        KEY idx_classroom_quiz_sessions_participant (participant_id, completed_at),
        KEY idx_classroom_quiz_sessions_duel (duel_id),
        KEY idx_classroom_quiz_sessions_quiz (quiz_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        'duel_id' => "ALTER TABLE classroom_quiz_sessions ADD COLUMN duel_id INT UNSIGNED NULL AFTER quiz_id",
        'best_streak' => "ALTER TABLE classroom_quiz_sessions ADD COLUMN best_streak INT UNSIGNED NOT NULL DEFAULT 0 AFTER score_points",
        'percent_score' => "ALTER TABLE classroom_quiz_sessions ADD COLUMN percent_score DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER best_streak",
    ] as $column => $sql) {
        if (!elevaro_access_column_exists('classroom_quiz_sessions', $column)) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS classroom_answer_events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        class_id INT UNSIGNED NOT NULL,
        participant_id INT UNSIGNED NOT NULL,
        quiz_session_id INT UNSIGNED NULL,
        quiz_id INT UNSIGNED NOT NULL,
        question_id INT UNSIGNED NOT NULL,
        selected_answer TEXT NULL,
        correct_answer TEXT NULL,
        is_correct TINYINT(1) NOT NULL DEFAULT 0,
        points INT UNSIGNED NOT NULL DEFAULT 0,
        answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_classroom_answer_session (quiz_session_id),
        KEY idx_classroom_answer_class (class_id, answered_at),
        KEY idx_classroom_answer_participant (participant_id, answered_at)
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

function classroom_assigned_quizzes(int $classId, ?int $participantId = null): array
{
    $sql = "
        SELECT
            q.*,
            subj.name AS subject_name,
            subj.code AS subject_code,
            best.best_points,
            best.best_correct,
            best.best_total,
            best.best_percent,
            best.rounds_played,
            best.last_completed_at,
            COALESCE(pool.question_pool_total, 0) AS question_pool_total,
            COALESCE(progress.progress_passed, 0) AS progress_passed,
            COALESCE(progress.progress_failed, 0) AS progress_failed,
            COALESCE(progress.progress_attempted, 0) AS progress_attempted
        FROM teacher_class_quizzes tcq
        JOIN quizzes q ON q.id = tcq.quiz_id
        LEFT JOIN subjects subj ON subj.id = q.subject_id
        LEFT JOIN (
            SELECT quiz_id, COUNT(*) AS question_pool_total
            FROM questions
            WHERE status = 'published'
            GROUP BY quiz_id
        ) pool ON pool.quiz_id = q.id
        LEFT JOIN (
            SELECT
                e.quiz_id,
                SUM(CASE WHEN e.was_correct = 1 THEN 1 ELSE 0 END) AS progress_passed,
                SUM(CASE WHEN e.was_correct = 1 THEN 0 ELSE 1 END) AS progress_failed,
                COUNT(*) AS progress_attempted
            FROM (
                SELECT
                    cae.quiz_id,
                    cae.question_id,
                    MAX(cae.is_correct) AS was_correct
                FROM classroom_answer_events cae
                WHERE cae.class_id = :answer_progress_class_id
                  AND cae.participant_id = :answer_progress_participant_id
                GROUP BY cae.quiz_id, cae.question_id
            ) e
            GROUP BY e.quiz_id
        ) progress ON progress.quiz_id = q.id
        LEFT JOIN (
            SELECT
                quiz_id,
                MAX(score_points) AS best_points,
                MAX(correct_answers) AS best_correct,
                MAX(total_questions) AS best_total,
                MAX(percent_score) AS best_percent,
                COUNT(*) AS rounds_played,
                MAX(completed_at) AS last_completed_at
            FROM classroom_quiz_sessions
            WHERE class_id = :progress_class_id
              AND participant_id = :progress_participant_id
              AND completed_at IS NOT NULL
            GROUP BY quiz_id
        ) best ON best.quiz_id = q.id
        WHERE tcq.class_id = :class_id
          AND q.is_active = 1
        ORDER BY tcq.sort_order, q.title
    ";

    $stmt = classroom_db()->prepare($sql);
    $stmt->execute([
        'class_id' => $classId,
        'progress_class_id' => $classId,
        'progress_participant_id' => $participantId ?: 0,
        'answer_progress_class_id' => $classId,
        'answer_progress_participant_id' => $participantId ?: 0,
    ]);
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

function classroom_participant_has_quiz_access(int $classId, int $participantId, int $quizId): bool
{
    if (!$classId || !$participantId || !$quizId) return false;
    $participant = classroom_current_participant($classId);
    if (!$participant || (int)$participant['id'] !== $participantId) return false;

    $stmt = classroom_db()->prepare("SELECT COUNT(*) FROM teacher_class_quizzes WHERE class_id = :class_id AND quiz_id = :quiz_id");
    $stmt->execute(['class_id' => $classId, 'quiz_id' => $quizId]);
    return (int)$stmt->fetchColumn() > 0;
}

function classroom_duel_for_quiz(int $classId, int $participantId, int $duelId, int $quizId): ?array
{
    if (!$duelId) return null;
    $stmt = classroom_db()->prepare("\n        SELECT d.*,\n               c.display_name AS challenger_name, c.avatar_emoji AS challenger_avatar, c.avatar_type AS challenger_avatar_type, c.avatar_gradient AS challenger_avatar_gradient,\n               p.display_name AS challenged_name, p.avatar_emoji AS challenged_avatar, p.avatar_type AS challenged_avatar_type, p.avatar_gradient AS challenged_avatar_gradient\n        FROM classroom_duel_challenges d\n        JOIN classroom_participants c ON c.id = d.challenger_participant_id\n        JOIN classroom_participants p ON p.id = d.challenged_participant_id\n        WHERE d.id = :id\n          AND d.class_id = :class_id\n          AND d.quiz_id = :quiz_id\n          AND d.status = 'accepted'\n          AND (d.challenger_participant_id = :participant_a OR d.challenged_participant_id = :participant_b)\n        LIMIT 1\n    ");
    $stmt->execute([
        'id' => $duelId,
        'class_id' => $classId,
        'quiz_id' => $quizId,
        'participant_a' => $participantId,
        'participant_b' => $participantId,
    ]);
    return $stmt->fetch() ?: null;
}

function classroom_start_quiz_session(int $classId, int $participantId, int $quizId, ?string $sessionToken = null, ?int $roundQuestionCount = null, ?int $duelId = null): int
{
    classroom_ensure_schema();
    if (!classroom_participant_has_quiz_access($classId, $participantId, $quizId)) {
        throw new RuntimeException('Dieses Quiz ist für diesen Klassenraum nicht freigegeben.');
    }
    if ($duelId && !classroom_duel_for_quiz($classId, $participantId, $duelId, $quizId)) {
        throw new RuntimeException('Dieses Quizduell ist nicht mehr aktiv.');
    }

    $total = $roundQuestionCount;
    if ($total === null || $total <= 0) {
        $stmt = classroom_db()->prepare("SELECT COUNT(*) FROM questions WHERE quiz_id = :quiz_id AND status = 'published'");
        $stmt->execute(['quiz_id' => $quizId]);
        $total = (int)$stmt->fetchColumn();
    }

    $stmt = classroom_db()->prepare("\n        INSERT INTO classroom_quiz_sessions (class_id, participant_id, quiz_id, duel_id, session_token, total_questions)\n        VALUES (:class_id, :participant_id, :quiz_id, :duel_id, :session_token, :total_questions)\n    ");
    $stmt->execute([
        'class_id' => $classId,
        'participant_id' => $participantId,
        'quiz_id' => $quizId,
        'duel_id' => $duelId ?: null,
        'session_token' => $sessionToken,
        'total_questions' => max(0, (int)$total),
    ]);
    return (int)classroom_db()->lastInsertId();
}

function classroom_find_or_start_quiz_session(int $classId, int $participantId, int $quizId, ?string $sessionToken = null, ?int $roundQuestionCount = null, ?int $duelId = null): int
{
    classroom_ensure_schema();

    $sessionToken = $sessionToken !== null ? trim($sessionToken) : null;

    if ($sessionToken !== null && $sessionToken !== '') {
        $sql = "
            SELECT id
            FROM classroom_quiz_sessions
            WHERE class_id = :class_id
              AND participant_id = :participant_id
              AND quiz_id = :quiz_id
              AND session_token = :session_token
              AND completed_at IS NULL
        ";
        $params = [
            'class_id' => $classId,
            'participant_id' => $participantId,
            'quiz_id' => $quizId,
            'session_token' => $sessionToken,
        ];

        if ($duelId) {
            $sql .= " AND duel_id = :duel_id";
            $params['duel_id'] = $duelId;
        } else {
            $sql .= " AND duel_id IS NULL";
        }

        $sql .= " ORDER BY id DESC LIMIT 1";
        $stmt = classroom_db()->prepare($sql);
        $stmt->execute($params);
        $existingId = (int)$stmt->fetchColumn();
        if ($existingId > 0) {
            return $existingId;
        }
    }

    return classroom_start_quiz_session($classId, $participantId, $quizId, $sessionToken, $roundQuestionCount, $duelId);
}

function classroom_record_answer(int $classId, int $participantId, int $sessionId, int $quizId, int $questionId, string $selectedAnswer, string $correctAnswer, bool $isCorrect, int $points = 0): void
{
    classroom_ensure_schema();
    $stmt = classroom_db()->prepare("SELECT * FROM classroom_quiz_sessions WHERE id = :id AND class_id = :class_id AND participant_id = :participant_id AND quiz_id = :quiz_id LIMIT 1");
    $stmt->execute(['id' => $sessionId, 'class_id' => $classId, 'participant_id' => $participantId, 'quiz_id' => $quizId]);
    $session = $stmt->fetch();
    if (!$session) throw new RuntimeException('Klassenraum-Quizrunde nicht gefunden.');

    $pdo = classroom_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("\n            INSERT INTO classroom_answer_events (class_id, participant_id, quiz_session_id, quiz_id, question_id, selected_answer, correct_answer, is_correct, points)\n            VALUES (:class_id, :participant_id, :quiz_session_id, :quiz_id, :question_id, :selected_answer, :correct_answer, :is_correct, :points)\n        ");
        $stmt->execute([
            'class_id' => $classId,
            'participant_id' => $participantId,
            'quiz_session_id' => $sessionId,
            'quiz_id' => $quizId,
            'question_id' => $questionId,
            'selected_answer' => $selectedAnswer,
            'correct_answer' => $correctAnswer,
            'is_correct' => $isCorrect ? 1 : 0,
            'points' => max(0, $points),
        ]);

        $stmt = $pdo->prepare("\n            UPDATE classroom_quiz_sessions\n            SET answered_questions = answered_questions + 1,\n                correct_answers = correct_answers + :correct_increment,\n                wrong_answers = wrong_answers + :wrong_increment,\n                score_points = score_points + :points\n            WHERE id = :session_id\n        ");
        $stmt->execute([
            'correct_increment' => $isCorrect ? 1 : 0,
            'wrong_increment' => $isCorrect ? 0 : 1,
            'points' => max(0, $points),
            'session_id' => $sessionId,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function classroom_complete_quiz_session(int $classId, int $participantId, int $sessionId, int $score, int $total, int $points, int $bestStreak): array
{
    classroom_ensure_schema();
    $percent = $total > 0 ? round(($score / $total) * 100, 2) : 0.0;
    $stmt = classroom_db()->prepare("\n        UPDATE classroom_quiz_sessions\n        SET completed_at = IFNULL(completed_at, NOW()),\n            total_questions = :total_questions,\n            correct_answers = :correct_answers,\n            wrong_answers = :wrong_answers,\n            score_points = GREATEST(score_points, :score_points),\n            best_streak = :best_streak,\n            percent_score = :percent_score\n        WHERE id = :id\n          AND class_id = :class_id\n          AND participant_id = :participant_id\n    ");
    $stmt->execute([
        'total_questions' => max(0, $total),
        'correct_answers' => max(0, $score),
        'wrong_answers' => max(0, $total - $score),
        'score_points' => max(0, $points),
        'best_streak' => max(0, $bestStreak),
        'percent_score' => $percent,
        'id' => $sessionId,
        'class_id' => $classId,
        'participant_id' => $participantId,
    ]);

    $stmt = classroom_db()->prepare("SELECT s.*, p.display_name, q.title AS quiz_title FROM classroom_quiz_sessions s JOIN classroom_participants p ON p.id = s.participant_id JOIN quizzes q ON q.id = s.quiz_id WHERE s.id = :id LIMIT 1");
    $stmt->execute(['id' => $sessionId]);
    $session = $stmt->fetch() ?: [];

    if ($session) {
        classroom_log_activity(
            $classId,
            $participantId,
            !empty($session['duel_id']) ? 'duel_finished' : 'quiz_finished',
            $session['display_name'] . ' hat „' . $session['quiz_title'] . '” mit ' . (int)$session['score_points'] . ' Punkten beendet.',
            ''
        );
    }

    $duelResult = null;
    if (!empty($session['duel_id'])) {
        $duelResult = classroom_duel_result((int)$session['duel_id'], $participantId);
    }

    return [
        'session' => $session,
        'duel_result' => $duelResult,
    ];
}

function classroom_duel_result(int $duelId, int $participantId): ?array
{
    $stmt = classroom_db()->prepare("\n        SELECT d.*, c.display_name AS challenger_name, p.display_name AS challenged_name\n        FROM classroom_duel_challenges d\n        JOIN classroom_participants c ON c.id = d.challenger_participant_id\n        JOIN classroom_participants p ON p.id = d.challenged_participant_id\n        WHERE d.id = :id\n        LIMIT 1\n    ");
    $stmt->execute(['id' => $duelId]);
    $duel = $stmt->fetch();
    if (!$duel) return null;

    $stmt = classroom_db()->prepare("\n        SELECT s.*, p.display_name\n        FROM classroom_quiz_sessions s\n        JOIN classroom_participants p ON p.id = s.participant_id\n        WHERE s.duel_id = :duel_id\n          AND s.completed_at IS NOT NULL\n          AND s.participant_id IN (:challenger, :challenged)\n        ORDER BY s.completed_at ASC\n    ");
    $stmt->execute([
        'duel_id' => $duelId,
        'challenger' => (int)$duel['challenger_participant_id'],
        'challenged' => (int)$duel['challenged_participant_id'],
    ]);
    $sessions = $stmt->fetchAll();

    $own = null; $other = null;
    foreach ($sessions as $s) {
        if ((int)$s['participant_id'] === $participantId) $own = $s;
        else $other = $s;
    }
    if (!$own) return null;

    $pending = count($sessions) < 2;
    $winnerId = null;
    if (!$pending && $other) {
        $ownPoints = (int)$own['score_points'];
        $otherPoints = (int)$other['score_points'];
        if ($ownPoints > $otherPoints) $winnerId = (int)$own['participant_id'];
        elseif ($otherPoints > $ownPoints) $winnerId = (int)$other['participant_id'];
        else {
            $ownCorrect = (int)$own['correct_answers'];
            $otherCorrect = (int)$other['correct_answers'];
            if ($ownCorrect > $otherCorrect) $winnerId = (int)$own['participant_id'];
            elseif ($otherCorrect > $ownCorrect) $winnerId = (int)$other['participant_id'];
        }
    }

    return [
        'pending' => $pending,
        'outcome' => $pending ? 'waiting' : ($winnerId === null ? 'draw' : ($winnerId === $participantId ? 'won' : 'lost')),
        'own_name' => (string)$own['display_name'],
        'own_points' => (int)$own['score_points'],
        'own_correct' => (int)$own['correct_answers'],
        'other_name' => $other['display_name'] ?? ($participantId === (int)$duel['challenger_participant_id'] ? $duel['challenged_name'] : $duel['challenger_name']),
        'other_points' => $other ? (int)$other['score_points'] : null,
        'other_correct' => $other ? (int)$other['correct_answers'] : null,
    ];
}

function classroom_highscores(int $classId, ?int $quizId = null, int $limit = 10): array
{
    classroom_ensure_schema();
    $where = "s.class_id = :class_id AND s.completed_at IS NOT NULL";
    $params = ['class_id' => $classId];
    if ($quizId) {
        $where .= " AND s.quiz_id = :quiz_id";
        $params['quiz_id'] = $quizId;
    }
    $stmt = classroom_db()->prepare("\n        SELECT p.display_name, p.avatar_emoji, p.avatar_type, p.avatar_gradient, q.title AS quiz_title,\n               MAX(s.score_points) AS best_points, MAX(s.correct_answers) AS best_correct, MAX(s.percent_score) AS best_percent, COUNT(*) AS rounds\n        FROM classroom_quiz_sessions s\n        JOIN classroom_participants p ON p.id = s.participant_id\n        JOIN quizzes q ON q.id = s.quiz_id\n        WHERE {$where}\n        GROUP BY s.participant_id, s.quiz_id, p.display_name, p.avatar_emoji, p.avatar_type, p.avatar_gradient, q.title\n        ORDER BY best_points DESC, best_correct DESC, best_percent DESC, p.display_name ASC\n        LIMIT " . max(1, min(30, $limit)) . "\n    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

classroom_ensure_schema();

function classroom_question_progress_map(int $participantId, int $quizId): array
{
    classroom_ensure_schema();

    $stmt = classroom_db()->prepare("\n        SELECT question_id, is_correct, answered_at, id\n        FROM classroom_answer_events\n        WHERE participant_id = :participant_id\n          AND quiz_id = :quiz_id\n        ORDER BY question_id ASC, answered_at ASC, id ASC\n    ");
    $stmt->execute([
        'participant_id' => $participantId,
        'quiz_id' => $quizId,
    ]);

    $map = [];
    foreach ($stmt->fetchAll() as $event) {
        $qid = (int)$event['question_id'];
        if (!isset($map[$qid])) {
            $map[$qid] = [
                'answered_count' => 0,
                'correct_count' => 0,
                'wrong_count' => 0,
                'needs_recovery' => 0,
                'is_mastered' => 0,
                'last_answer_correct' => null,
                'last_answered_at' => null,
                'consecutive_correct_after_wrong' => 0,
            ];
        }

        $isCorrect = (int)$event['is_correct'] === 1;
        $map[$qid]['answered_count']++;
        $map[$qid]['correct_count'] += $isCorrect ? 1 : 0;
        $map[$qid]['wrong_count'] += $isCorrect ? 0 : 1;
        $map[$qid]['last_answer_correct'] = $isCorrect ? 1 : 0;
        $map[$qid]['last_answered_at'] = $event['answered_at'] ?? null;

        if ($isCorrect) {
            if ((int)$map[$qid]['needs_recovery'] === 1) {
                $map[$qid]['consecutive_correct_after_wrong']++;
                if ((int)$map[$qid]['consecutive_correct_after_wrong'] >= 2) {
                    $map[$qid]['needs_recovery'] = 0;
                    $map[$qid]['is_mastered'] = 1;
                }
            } else {
                $map[$qid]['is_mastered'] = 1;
                $map[$qid]['consecutive_correct_after_wrong'] = max(1, (int)$map[$qid]['consecutive_correct_after_wrong']);
            }
        } else {
            $map[$qid]['needs_recovery'] = 1;
            $map[$qid]['is_mastered'] = 0;
            $map[$qid]['consecutive_correct_after_wrong'] = 0;
        }
    }

    return $map;
}

function classroom_get_quiz_progress_for_participant(int $participantId, int $quizId): array
{
    $total = function_exists('elevaro_get_quiz_question_count') ? elevaro_get_quiz_question_count($quizId) : 0;
    $progressMap = classroom_question_progress_map($participantId, $quizId);

    $passed = 0;
    $failed = 0;
    $attempted = 0;

    foreach ($progressMap as $progress) {
        $attempted++;
        if ((int)($progress['needs_recovery'] ?? 0) === 1) {
            $failed++;
        } elseif ((int)($progress['is_mastered'] ?? 0) === 1 || (int)($progress['last_answer_correct'] ?? 0) === 1) {
            $passed++;
        }
    }

    return [
        'total' => $total,
        'passed' => $passed,
        'failed' => $failed,
        'unanswered' => max(0, $total - $attempted),
        'attempted' => $attempted,
        'played' => $attempted > 0,
    ];
}

function classroom_get_questions_for_quiz_round(int $quizId, int $participantId, ?int $limit = null): array
{
    classroom_ensure_schema();
    $limit = $limit ?: (function_exists('elevaro_quiz_round_length') ? elevaro_quiz_round_length() : 15);
    $progressMap = classroom_question_progress_map($participantId, $quizId);

    $stmt = classroom_db()->prepare("\n        SELECT\n            q.*,\n            COALESCE(q.difficulty_manual, qs.calculated_difficulty, q.difficulty_calculated, 0.3) AS difficulty\n        FROM questions q\n        LEFT JOIN question_stats qs ON qs.question_id = q.id\n        WHERE q.quiz_id = :quiz_id\n          AND q.status = 'published'\n        ORDER BY COALESCE(q.difficulty_manual, qs.calculated_difficulty, q.difficulty_calculated, 0.3) ASC, q.sort_order ASC, q.id ASC\n    ");
    $stmt->execute(['quiz_id' => $quizId]);
    $questions = $stmt->fetchAll();

    if (!$questions) return [];

    foreach ($questions as &$question) {
        $qid = (int)$question['id'];
        $progress = $progressMap[$qid] ?? [];
        $question['answered_count'] = (int)($progress['answered_count'] ?? 0);
        $question['correct_count'] = (int)($progress['correct_count'] ?? 0);
        $question['wrong_count'] = (int)($progress['wrong_count'] ?? 0);
        $question['needs_recovery'] = (int)($progress['needs_recovery'] ?? 0);
        $question['is_mastered'] = (int)($progress['is_mastered'] ?? 0);
        $question['last_answer_correct'] = $progress['last_answer_correct'] ?? null;
        $question['last_answered_at'] = $progress['last_answered_at'] ?? null;
    }
    unset($question);

    if (function_exists('elevaro_select_premium_question_round')) {
        $questions = elevaro_select_premium_question_round($questions, $limit);
    } else {
        $questions = array_slice($questions, 0, $limit);
    }

    $ids = array_map(static fn($q) => (int)$q['id'], $questions);
    if (!$ids) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $optionsStmt = classroom_db()->prepare("\n        SELECT *\n        FROM question_options\n        WHERE question_id IN ({$placeholders})\n        ORDER BY question_id ASC, sort_order ASC, id ASC\n    ");
    $optionsStmt->execute($ids);

    $optionsByQuestion = [];
    foreach ($optionsStmt->fetchAll() as $option) {
        $qid = (int)$option['question_id'];
        $optionsByQuestion[$qid][] = [
            'text' => (string)$option['option_text'],
            'label' => (string)$option['option_text'],
            'is_correct' => (bool)$option['is_correct'],
            'media' => [
                'type' => $option['media_type'] ?? 'none',
                'path' => $option['media_path'] ?? null,
                'alt' => $option['media_alt'] ?? null,
                'credit' => $option['media_credit'] ?? null,
                'source' => $option['media_source'] ?? null,
            ],
            'media_type' => $option['media_type'] ?? 'none',
            'media_path' => $option['media_path'] ?? null,
            'media_alt' => $option['media_alt'] ?? null,
        ];
    }

    $payload = [];
    foreach ($questions as $question) {
        $qid = (int)$question['id'];
        $options = $optionsByQuestion[$qid] ?? [];
        if (count($options) > 1) shuffle($options);

        $payload[] = [
            'id' => $qid,
            'type' => $question['type'],
            'question' => $question['question_text'],
            'media' => [
                'type' => $question['media_type'] ?? 'none',
                'path' => $question['media_path'] ?? null,
                'alt' => $question['media_alt'] ?? null,
                'credit' => $question['media_credit'] ?? null,
                'source' => $question['media_source'] ?? null,
            ],
            'audio' => [
                'text' => $question['audio_text'] ?? null,
                'path' => $question['audio_path'] ?? null,
                'voice_id' => $question['audio_voice_id'] ?? null,
                'model_id' => $question['audio_model_id'] ?? null,
                'status' => $question['audio_status'] ?? 'none',
            ],
            'options' => array_map(static function ($option) {
                return [
                    'text' => (string)($option['text'] ?? ''),
                    'label' => (string)($option['text'] ?? ''),
                    'media' => $option['media'] ?? ['type' => 'none'],
                    'media_type' => $option['media']['type'] ?? 'none',
                    'media_path' => $option['media']['path'] ?? null,
                    'media_alt' => $option['media']['alt'] ?? null,
                ];
            }, $options),
            'answer' => $question['correct_answer'],
            'fact' => $question['explanation'],
            'difficulty' => (float)$question['difficulty'],
            'source_context' => $question['source_context'] ?? 'general',
            'source_excerpt' => $question['source_excerpt'] ?? null,
            'reports_count' => (int)($question['reports_count'] ?? 0),
            'progress_state' => [
                'answered_count' => (int)($question['answered_count'] ?? 0),
                'correct_count' => (int)($question['correct_count'] ?? 0),
                'wrong_count' => (int)($question['wrong_count'] ?? 0),
                'needs_recovery' => (int)($question['needs_recovery'] ?? 0),
                'is_mastered' => (int)($question['is_mastered'] ?? 0),
            ],
        ];
    }

    return $payload;
}
