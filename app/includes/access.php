<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function elevaro_access_column_exists(string $table, string $column): bool
{
    try {
        $stmt = elevaro_db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function elevaro_access_table_exists(string $table): bool
{
    try {
        $stmt = elevaro_db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute(['table_name' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function elevaro_user_is_premium(?array $user = null): bool
{
    $user = $user ?: auth_user();

    if (!$user) {
        return false;
    }

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    $plan = (string)($user['plan'] ?? 'free');
    if (in_array($plan, ['premium', 'teacher'], true)) {
        return true;
    }

    if (!empty($user['plan_expires_at']) && strtotime((string)$user['plan_expires_at']) > time()) {
        return true;
    }

    return elevaro_user_has_class_access((int)$user['id']);
}

function elevaro_user_has_class_access(int $userId): bool
{
    if (!$userId || !elevaro_access_table_exists('class_code_users')) {
        return false;
    }

    $stmt = elevaro_db()->prepare("
        SELECT COUNT(*)
        FROM class_code_users ccu
        JOIN class_codes cc ON cc.id = ccu.class_code_id
        WHERE ccu.user_id = :user_id
          AND cc.is_active = 1
          AND (cc.expires_at IS NULL OR cc.expires_at > NOW())
    ");
    $stmt->execute(['user_id' => $userId]);

    return (int)$stmt->fetchColumn() > 0;
}


function elevaro_user_has_premium_for_quiz(?array $user, int $quizId): bool
{
    $user = $user ?: auth_user();

    if (!$user) {
        return false;
    }

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    $plan = (string)($user['plan'] ?? 'free');
    if (in_array($plan, ['premium', 'teacher'], true)) {
        return true;
    }

    if (!empty($user['plan_expires_at']) && strtotime((string)$user['plan_expires_at']) > time()) {
        return true;
    }

    if (!$quizId || !elevaro_access_table_exists('class_code_users') || !elevaro_access_table_exists('class_codes')) {
        return false;
    }

    // New teacher backend: only quizzes assigned to the joined class become premium.
    if (elevaro_access_column_exists('class_codes', 'class_id') && elevaro_access_table_exists('teacher_class_quizzes')) {
        $stmt = elevaro_db()->prepare("
            SELECT COUNT(*)
            FROM class_code_users ccu
            JOIN class_codes cc ON cc.id = ccu.class_code_id
            JOIN teacher_class_quizzes tcq ON tcq.class_id = cc.class_id
            WHERE ccu.user_id = :user_id
              AND tcq.quiz_id = :quiz_id
              AND cc.is_active = 1
              AND (cc.expires_at IS NULL OR cc.expires_at > NOW())
        ");
        $stmt->execute([
            'user_id' => (int)$user['id'],
            'quiz_id' => $quizId,
        ]);

        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }
    }

    return false;
}

function elevaro_free_quiz_limit(): int
{
    return 2;
}

function elevaro_user_quiz_starts_today(int $userId): int
{
    if (!$userId || !elevaro_access_table_exists('user_daily_usage')) {
        return 0;
    }

    $stmt = elevaro_db()->prepare("
        SELECT quiz_starts
        FROM user_daily_usage
        WHERE user_id = :user_id
          AND usage_date = CURDATE()
        LIMIT 1
    ");
    $stmt->execute(['user_id' => $userId]);

    return (int)($stmt->fetchColumn() ?: 0);
}

function elevaro_track_quiz_start(int $userId): void
{
    if (!$userId || !elevaro_access_table_exists('user_daily_usage')) {
        return;
    }

    $stmt = elevaro_db()->prepare("
        INSERT INTO user_daily_usage (user_id, usage_date, quiz_starts)
        VALUES (:user_id, CURDATE(), 1)
        ON DUPLICATE KEY UPDATE
          quiz_starts = quiz_starts + 1,
          updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute(['user_id' => $userId]);
}

function elevaro_can_start_additional_quiz(?array $user = null): bool
{
    $user = $user ?: auth_user();

    if (!$user) {
        return true; // guests may test public quizzes
    }

    if (elevaro_user_is_premium($user)) {
        return true;
    }

    return elevaro_user_quiz_starts_today((int)$user['id']) < elevaro_free_quiz_limit();
}

function elevaro_apply_access_code(int $userId, string $code): array
{
    $code = strtoupper(trim($code));

    if ($code === '') {
        throw new RuntimeException('Bitte Code eingeben.');
    }

    $pdo = elevaro_db();

    if (!elevaro_access_table_exists('premium_access_codes')) {
        throw new RuntimeException('Code-System ist noch nicht eingerichtet.');
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM premium_access_codes
        WHERE code = :code
          AND is_active = 1
          AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 1
    ");
    $stmt->execute(['code' => $code]);
    $accessCode = $stmt->fetch();

    if ($accessCode) {
        if ((int)$accessCode['used_count'] >= (int)$accessCode['max_uses']) {
            throw new RuntimeException('Dieser Code wurde bereits vollständig eingelöst.');
        }

        $months = max(1, (int)$accessCode['months']);
        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE auth_users
            SET plan = 'premium',
                plan_expires_at = CASE
                  WHEN plan_expires_at IS NOT NULL AND plan_expires_at > NOW()
                    THEN DATE_ADD(plan_expires_at, INTERVAL :months MONTH)
                  ELSE DATE_ADD(NOW(), INTERVAL :months2 MONTH)
                END
            WHERE id = :user_id
        ")->execute([
            'months' => $months,
            'months2' => $months,
            'user_id' => $userId,
        ]);

        $pdo->prepare("UPDATE premium_access_codes SET used_count = used_count + 1 WHERE id = :id")
            ->execute(['id' => (int)$accessCode['id']]);

        $pdo->commit();

        return ['type' => 'premium_code', 'months' => $months];
    }

    if (!elevaro_access_table_exists('class_codes')) {
        throw new RuntimeException('Code nicht gefunden.');
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM class_codes
        WHERE code = :code
          AND is_active = 1
          AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 1
    ");
    $stmt->execute(['code' => $code]);
    $classCode = $stmt->fetch();

    if (!$classCode) {
        throw new RuntimeException('Code nicht gefunden oder nicht mehr gültig.');
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM class_code_users WHERE class_code_id = :id");
    $stmt->execute(['id' => (int)$classCode['id']]);
    if ((int)$stmt->fetchColumn() >= (int)$classCode['max_students']) {
        throw new RuntimeException('Diese Klasse ist bereits voll.');
    }

    $pdo->prepare("
        INSERT IGNORE INTO class_code_users (class_code_id, user_id)
        VALUES (:class_code_id, :user_id)
    ")->execute([
        'class_code_id' => (int)$classCode['id'],
        'user_id' => $userId,
    ]);

    if (elevaro_access_table_exists('teacher_class_students') && !empty($classCode['class_id'])) {
        $pdo->prepare("
            INSERT IGNORE INTO teacher_class_students (class_id, user_id)
            VALUES (:class_id, :user_id)
        ")->execute([
            'class_id' => (int)$classCode['class_id'],
            'user_id' => $userId,
        ]);
    }

    return ['type' => 'class_code', 'label' => $classCode['label'] ?? 'Klasse'];
}
