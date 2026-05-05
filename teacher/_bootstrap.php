<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/access.php';
require_once __DIR__ . '/../app/includes/curriculum.php';

auth_require_login();

$role = auth_effective_role();
if ($role === 'admin') {
    // Admins may use the teacher backend through role simulation.
} elseif ($role !== 'lehrer') {
    header('Location: /student_dashboard.php');
    exit;
}

function teacher_db(): PDO
{
    return elevaro_db();
}

function teacher_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function teacher_active(string $file): string
{
    return basename($_SERVER['SCRIPT_NAME']) === $file ? 'active' : '';
}

function teacher_current_user_id(): int
{
    return (int)(auth_user()['id'] ?? 0);
}

function teacher_table_exists(string $table): bool
{
    return elevaro_access_table_exists($table);
}

function teacher_column_exists(string $table, string $column): bool
{
    return elevaro_access_column_exists($table, $column);
}

function teacher_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = teacher_db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_classes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT UNSIGNED NOT NULL,
        name VARCHAR(160) NOT NULL,
        state_code VARCHAR(16) NOT NULL,
        school_type_code VARCHAR(64) NOT NULL,
        level_key VARCHAR(64) NOT NULL,
        grade INT NULL,
        subject_code VARCHAR(64) NOT NULL,
        invite_code VARCHAR(24) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_teacher_class_code (invite_code),
        KEY idx_teacher_classes_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_class_quizzes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        class_id INT UNSIGNED NOT NULL,
        quiz_id INT UNSIGNED NOT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 100,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_teacher_class_quiz (class_id, quiz_id),
        KEY idx_teacher_class_quizzes_class (class_id),
        KEY idx_teacher_class_quizzes_quiz (quiz_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Keep compatibility with the existing low-threshold class-code redeem flow.
    $pdo->exec("CREATE TABLE IF NOT EXISTS class_codes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(32) NOT NULL,
        teacher_id INT UNSIGNED NOT NULL,
        label VARCHAR(160) NULL,
        max_students INT UNSIGNED NOT NULL DEFAULT 90,
        max_classes INT UNSIGNED NOT NULL DEFAULT 3,
        max_quizzes_per_class INT UNSIGNED NOT NULL DEFAULT 10,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        expires_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_class_code_code (code),
        KEY idx_class_codes_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS class_code_users (
        class_code_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (class_code_id, user_id),
        KEY idx_class_code_users_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!teacher_column_exists('class_codes', 'class_id')) {
        try {
            $pdo->exec("ALTER TABLE class_codes ADD COLUMN class_id INT UNSIGNED NULL AFTER teacher_id, ADD KEY idx_class_codes_class (class_id)");
        } catch (Throwable $e) {
            // Existing DBs may differ. The backend still works through the code itself.
        }
    }
}

function teacher_make_invite_code(): string
{
    $pdo = teacher_db();
    do {
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM teacher_classes WHERE invite_code = :code");
        $stmt->execute(['code' => $code]);
    } while ((int)$stmt->fetchColumn() > 0);
    return $code;
}

function teacher_classes(): array
{
    teacher_ensure_schema();
    $stmt = teacher_db()->prepare("SELECT * FROM teacher_classes WHERE teacher_id = :teacher_id AND is_active = 1 ORDER BY created_at ASC");
    $stmt->execute(['teacher_id' => teacher_current_user_id()]);
    return $stmt->fetchAll();
}

function teacher_selected_class(?int $requestedId = null): ?array
{
    $classes = teacher_classes();
    if (!$classes) return null;
    $requestedId = $requestedId ?: (int)($_GET['class_id'] ?? 0);
    foreach ($classes as $class) {
        if ((int)$class['id'] === $requestedId) return $class;
    }
    return $classes[0];
}

function teacher_class_quiz_count(int $classId): int
{
    $stmt = teacher_db()->prepare("SELECT COUNT(*) FROM teacher_class_quizzes WHERE class_id = :class_id");
    $stmt->execute(['class_id' => $classId]);
    return (int)$stmt->fetchColumn();
}

function teacher_class_student_count(int $classId): int
{
    $stmt = teacher_db()->prepare("SELECT COUNT(*) FROM class_code_users ccu JOIN class_codes cc ON cc.id = ccu.class_code_id WHERE cc.class_id = :class_id");
    $stmt->execute(['class_id' => $classId]);
    return (int)$stmt->fetchColumn();
}

function teacher_invite_url(array $class): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'elevaro.app';
    return $scheme . '://' . $host . '/redeem_code.php?code=' . urlencode((string)$class['invite_code']);
}

function teacher_class_label(array $class): string
{
    return $class['name'] ?: trim(($class['subject_code'] ?? '') . ' ' . ($class['level_key'] ?? ''));
}

teacher_ensure_schema();
