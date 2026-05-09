<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

function teacher_library_norm(?string $value): string
{
    $value = trim(mb_strtolower((string)$value, 'UTF-8'));
    $value = preg_replace('/[^a-z0-9äöüß]+/u', '-', $value) ?: '';
    return trim($value, '-');
}

function teacher_library_subject_label(?string $code, ?string $name = null): string
{
    $name = trim((string)$name);
    if ($name !== '') return $name;
    $code = trim((string)$code);
    return $code !== '' ? strtoupper($code) : 'Ohne Fach';
}

function teacher_library_type_label(string $type): string
{
    return match ($type) {
        'worksheet' => 'Arbeitsblatt',
        'listening' => 'Listening',
        'reading' => 'Leseverständnis',
        default => 'Quiz',
    };
}

function teacher_library_type_icon(string $type): string
{
    return match ($type) {
        'worksheet' => '📄',
        'listening' => '🎧',
        'reading' => '📖',
        default => '🎮',
    };
}

function teacher_library_is_foreign_language(?string $subjectCode, ?string $subjectLabel = null): bool
{
    $haystack = teacher_library_norm(($subjectCode ?? '') . ' ' . ($subjectLabel ?? ''));
    foreach (['englisch', 'english', 'en', 'franzoesisch', 'franzosisch', 'french', 'fr', 'spanisch', 'spanish', 'es', 'italienisch', 'italian', 'it', 'latein', 'latin', 'la'] as $term) {
        if ($haystack === $term || str_contains($haystack, $term)) return true;
    }
    return false;
}

function teacher_library_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column");
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function teacher_library_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :idx");
    $stmt->execute(['table' => $table, 'idx' => $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function teacher_library_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!teacher_library_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
    }
}

function teacher_library_add_index(PDO $pdo, string $table, string $index, string $definition): void
{
    if (!teacher_library_index_exists($pdo, $table, $index)) {
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
        } catch (Throwable $e) {
            error_log('ELEVARO teacher library index migration failed: ' . $table . '.' . $index . ' - ' . $e->getMessage());
        }
    }
}

function teacher_library_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = teacher_db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_units (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT UNSIGNED NOT NULL,
        unit_key VARCHAR(191) NULL,
        title VARCHAR(220) NOT NULL,
        description TEXT NULL,
        subject_code VARCHAR(64) NULL,
        subject_label VARCHAR(160) NULL,
        grade VARCHAR(64) NULL,
        curriculum_topic_content_id INT UNSIGNED NULL,
        curriculum_topic_subtopic_id INT UNSIGNED NULL,
        curriculum_topic_label VARCHAR(220) NULL,
        curriculum_subtopic_label VARCHAR(220) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_teacher_units_teacher (teacher_id),
        KEY idx_teacher_units_curriculum (curriculum_topic_content_id, curriculum_topic_subtopic_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Existing installations may already have teacher_units from an earlier patch.
    // Bring that table forward without assuming a fresh schema.
    teacher_library_add_column($pdo, 'teacher_units', 'teacher_id', '`teacher_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `id`');
    teacher_library_add_column($pdo, 'teacher_units', 'unit_key', '`unit_key` VARCHAR(191) NULL AFTER `teacher_id`');
    teacher_library_add_column($pdo, 'teacher_units', 'title', '`title` VARCHAR(220) NOT NULL DEFAULT \'Unbenannte Unit\' AFTER `unit_key`');
    teacher_library_add_column($pdo, 'teacher_units', 'description', '`description` TEXT NULL AFTER `title`');
    teacher_library_add_column($pdo, 'teacher_units', 'subject_code', '`subject_code` VARCHAR(64) NULL AFTER `description`');
    teacher_library_add_column($pdo, 'teacher_units', 'subject_label', '`subject_label` VARCHAR(160) NULL AFTER `subject_code`');
    teacher_library_add_column($pdo, 'teacher_units', 'grade', '`grade` VARCHAR(64) NULL AFTER `subject_label`');
    teacher_library_add_column($pdo, 'teacher_units', 'curriculum_topic_content_id', '`curriculum_topic_content_id` INT UNSIGNED NULL AFTER `grade`');
    teacher_library_add_column($pdo, 'teacher_units', 'curriculum_topic_subtopic_id', '`curriculum_topic_subtopic_id` INT UNSIGNED NULL AFTER `curriculum_topic_content_id`');
    teacher_library_add_column($pdo, 'teacher_units', 'curriculum_topic_label', '`curriculum_topic_label` VARCHAR(220) NULL AFTER `curriculum_topic_subtopic_id`');
    teacher_library_add_column($pdo, 'teacher_units', 'curriculum_subtopic_label', '`curriculum_subtopic_label` VARCHAR(220) NULL AFTER `curriculum_topic_label`');
    teacher_library_add_column($pdo, 'teacher_units', 'created_at', '`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
    teacher_library_add_column($pdo, 'teacher_units', 'updated_at', '`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

    // Give legacy rows a deterministic unit_key before adding the unique key.
    if (teacher_library_column_exists($pdo, 'teacher_units', 'unit_key')) {
        $pdo->exec("UPDATE teacher_units SET unit_key = CONCAT('legacy:', teacher_id, ':', id) WHERE unit_key IS NULL OR unit_key = ''");
    }

    teacher_library_add_index($pdo, 'teacher_units', 'uniq_teacher_unit_key', 'UNIQUE KEY `uniq_teacher_unit_key` (`teacher_id`, `unit_key`)');
    teacher_library_add_index($pdo, 'teacher_units', 'idx_teacher_units_teacher', 'KEY `idx_teacher_units_teacher` (`teacher_id`)');
    teacher_library_add_index($pdo, 'teacher_units', 'idx_teacher_units_curriculum', 'KEY `idx_teacher_units_curriculum` (`curriculum_topic_content_id`, `curriculum_topic_subtopic_id`)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_unit_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        unit_id INT UNSIGNED NOT NULL,
        teacher_id INT UNSIGNED NOT NULL,
        item_type VARCHAR(32) NOT NULL,
        quiz_id INT UNSIGNED NULL,
        custom_quiz_id INT UNSIGNED NULL,
        title VARCHAR(220) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_teacher_unit_items_unit (unit_id),
        KEY idx_teacher_unit_items_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    teacher_library_add_column($pdo, 'teacher_unit_items', 'unit_id', '`unit_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `id`');
    teacher_library_add_column($pdo, 'teacher_unit_items', 'teacher_id', '`teacher_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `unit_id`');
    teacher_library_add_column($pdo, 'teacher_unit_items', 'item_type', '`item_type` VARCHAR(32) NOT NULL DEFAULT \'quiz\' AFTER `teacher_id`');
    teacher_library_add_column($pdo, 'teacher_unit_items', 'quiz_id', '`quiz_id` INT UNSIGNED NULL AFTER `item_type`');
    teacher_library_add_column($pdo, 'teacher_unit_items', 'custom_quiz_id', '`custom_quiz_id` INT UNSIGNED NULL AFTER `quiz_id`');
    teacher_library_add_column($pdo, 'teacher_unit_items', 'title', '`title` VARCHAR(220) NOT NULL DEFAULT \'Unbenannter Inhalt\' AFTER `custom_quiz_id`');
    teacher_library_add_column($pdo, 'teacher_unit_items', 'created_at', '`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
    teacher_library_add_column($pdo, 'teacher_unit_items', 'updated_at', '`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    teacher_library_add_index($pdo, 'teacher_unit_items', 'uniq_unit_quiz', 'UNIQUE KEY `uniq_unit_quiz` (`unit_id`, `item_type`, `quiz_id`)');
    teacher_library_add_index($pdo, 'teacher_unit_items', 'uniq_unit_custom_quiz', 'UNIQUE KEY `uniq_unit_custom_quiz` (`unit_id`, `item_type`, `custom_quiz_id`)');
    teacher_library_add_index($pdo, 'teacher_unit_items', 'idx_teacher_unit_items_unit', 'KEY `idx_teacher_unit_items_unit` (`unit_id`)');
    teacher_library_add_index($pdo, 'teacher_unit_items', 'idx_teacher_unit_items_teacher', 'KEY `idx_teacher_unit_items_teacher` (`teacher_id`)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_unit_class_links (
        unit_id INT UNSIGNED NOT NULL,
        class_id INT UNSIGNED NOT NULL,
        teacher_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (unit_id, class_id),
        KEY idx_unit_class_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    teacher_library_add_column($pdo, 'teacher_unit_class_links', 'unit_id', '`unit_id` INT UNSIGNED NOT NULL DEFAULT 0 FIRST');
    teacher_library_add_column($pdo, 'teacher_unit_class_links', 'class_id', '`class_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `unit_id`');
    teacher_library_add_column($pdo, 'teacher_unit_class_links', 'teacher_id', '`teacher_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `class_id`');
    teacher_library_add_column($pdo, 'teacher_unit_class_links', 'created_at', '`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
    teacher_library_add_index($pdo, 'teacher_unit_class_links', 'idx_unit_class_teacher', 'KEY `idx_unit_class_teacher` (`teacher_id`)');
}

function teacher_library_item_type(array $row): string
{
    return ((int)($row['listening_mode'] ?? 0) === 1) ? 'listening' : 'quiz';
}

function teacher_library_unit_title_from_item(array $item): string
{
    foreach (['subtopic_label', 'topic_label', 'title'] as $key) {
        $value = trim((string)($item[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return 'Unbenannte Unit';
}

function teacher_library_unit_key(array $item): string
{
    $teacherId = teacher_current_user_id();
    $subject = teacher_library_norm((string)($item['subject_code'] ?: $item['subject_label']));
    $grade = teacher_library_norm((string)($item['grade'] ?? ''));
    $topicId = (int)($item['curriculum_topic_content_id'] ?? 0);
    $subtopicId = (int)($item['curriculum_topic_subtopic_id'] ?? 0);

    if ($topicId > 0) {
        return implode(':', ['cur', $teacherId, $subject ?: 'subject', $grade ?: 'grade', $topicId, $subtopicId ?: 0]);
    }

    $title = teacher_library_norm(teacher_library_unit_title_from_item($item));
    $title = $title !== '' ? mb_substr($title, 0, 80, 'UTF-8') : 'unit';
    return implode(':', ['auto', $teacherId, $subject ?: 'subject', $grade ?: 'grade', $title]);
}

function teacher_library_upsert_unit(array $item): int
{
    teacher_library_ensure_schema();
    $pdo = teacher_db();
    $teacherId = teacher_current_user_id();
    $unitKey = teacher_library_unit_key($item);
    $title = teacher_library_unit_title_from_item($item);
    $description = trim((string)($item['description'] ?? ''));
    $topicLabel = trim((string)($item['topic_label'] ?? ''));
    $subtopicLabel = trim((string)($item['subtopic_label'] ?? ''));

    $stmt = $pdo->prepare("INSERT INTO teacher_units
        (teacher_id, unit_key, title, description, subject_code, subject_label, grade, curriculum_topic_content_id, curriculum_topic_subtopic_id, curriculum_topic_label, curriculum_subtopic_label)
        VALUES (:teacher_id, :unit_key, :title, :description, :subject_code, :subject_label, :grade, :topic_id, :subtopic_id, :topic_label, :subtopic_label)
        ON DUPLICATE KEY UPDATE
            title = COALESCE(NULLIF(VALUES(title), ''), title),
            description = COALESCE(NULLIF(VALUES(description), ''), description),
            subject_code = COALESCE(NULLIF(VALUES(subject_code), ''), subject_code),
            subject_label = COALESCE(NULLIF(VALUES(subject_label), ''), subject_label),
            grade = COALESCE(NULLIF(VALUES(grade), ''), grade),
            curriculum_topic_label = COALESCE(NULLIF(VALUES(curriculum_topic_label), ''), curriculum_topic_label),
            curriculum_subtopic_label = COALESCE(NULLIF(VALUES(curriculum_subtopic_label), ''), curriculum_subtopic_label),
            updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([
        'teacher_id' => $teacherId,
        'unit_key' => $unitKey,
        'title' => $title,
        'description' => $description,
        'subject_code' => (string)($item['subject_code'] ?? ''),
        'subject_label' => (string)($item['subject_label'] ?? ''),
        'grade' => (string)($item['grade'] ?? ''),
        'topic_id' => !empty($item['curriculum_topic_content_id']) ? (int)$item['curriculum_topic_content_id'] : null,
        'subtopic_id' => !empty($item['curriculum_topic_subtopic_id']) ? (int)$item['curriculum_topic_subtopic_id'] : null,
        'topic_label' => $topicLabel,
        'subtopic_label' => $subtopicLabel,
    ]);

    $lookup = $pdo->prepare("SELECT id FROM teacher_units WHERE teacher_id = :teacher_id AND unit_key = :unit_key LIMIT 1");
    $lookup->execute(['teacher_id' => $teacherId, 'unit_key' => $unitKey]);
    $unitId = (int)$lookup->fetchColumn();

    if ($unitId > 0) {
        $itemStmt = $pdo->prepare("INSERT INTO teacher_unit_items
            (unit_id, teacher_id, item_type, quiz_id, custom_quiz_id, title)
            VALUES (:unit_id, :teacher_id, :item_type, :quiz_id, :custom_quiz_id, :title)
            ON DUPLICATE KEY UPDATE title = VALUES(title), updated_at = CURRENT_TIMESTAMP");
        $itemStmt->execute([
            'unit_id' => $unitId,
            'teacher_id' => $teacherId,
            'item_type' => (string)($item['type'] ?? 'quiz'),
            'quiz_id' => (($item['type'] ?? '') !== 'worksheet' && !empty($item['id'])) ? (int)$item['id'] : null,
            'custom_quiz_id' => (($item['type'] ?? '') === 'worksheet' && !empty($item['id'])) ? (int)$item['id'] : null,
            'title' => (string)($item['title'] ?? $title),
        ]);
    }

    return $unitId;
}

function teacher_library_classes_matching_unit(array $unit, array $classes): array
{
    $subject = teacher_library_norm((string)($unit['subject_code'] ?? $unit['subject_label'] ?? ''));
    $grade = teacher_library_norm((string)($unit['grade'] ?? ''));
    $matches = [];
    foreach ($classes as $class) {
        $classSubject = teacher_library_norm((string)($class['subject_code'] ?? ''));
        $classGrade = teacher_library_norm((string)(($class['grade'] ?? '') ?: ($class['level_key'] ?? '')));
        if (($subject === '' || $classSubject === '' || $subject === $classSubject || str_contains($subject, $classSubject) || str_contains($classSubject, $subject))
            && ($grade === '' || $classGrade === '' || $grade === $classGrade || str_contains($classGrade, $grade))) {
            $matches[] = $class;
        }
    }
    return $matches ?: $classes;
}

function teacher_library_first_class_url_param(array $unit, array $classes): string
{
    $matches = teacher_library_classes_matching_unit($unit, $classes);
    $classId = !empty($matches[0]['id']) ? (int)$matches[0]['id'] : 0;
    return $classId ? '&class_id=' . $classId : '';
}

function teacher_library_unit_by_id(int $unitId): ?array
{
    teacher_library_ensure_schema();
    $stmt = teacher_db()->prepare("SELECT * FROM teacher_units WHERE id = :id AND teacher_id = :teacher_id LIMIT 1");
    $stmt->execute(['id' => $unitId, 'teacher_id' => teacher_current_user_id()]);
    $unit = $stmt->fetch();
    return $unit ?: null;
}

function teacher_library_absolute_url(string $path): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'elevaro.app';
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function teacher_library_ensure_share_schema(): void
{
    teacher_library_ensure_schema();
    $pdo = teacher_db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_unit_item_class_links (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        unit_id INT UNSIGNED NOT NULL,
        class_id INT UNSIGNED NOT NULL,
        teacher_id INT UNSIGNED NOT NULL,
        item_type VARCHAR(32) NOT NULL,
        item_ref_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_unit_item_class (unit_id, class_id, item_type, item_ref_id),
        KEY idx_unit_item_class_teacher (teacher_id),
        KEY idx_unit_item_class_class (class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_unit_colleague_shares (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        unit_id INT UNSIGNED NOT NULL,
        teacher_id INT UNSIGNED NOT NULL,
        recipient_email VARCHAR(190) NOT NULL,
        token VARCHAR(96) NOT NULL,
        item_refs_json TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_colleague_share_token (token),
        KEY idx_colleague_share_teacher (teacher_id),
        KEY idx_colleague_share_unit (unit_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function teacher_library_parse_item_ref(string $ref): ?array
{
    $ref = trim($ref);
    if (!preg_match('/^(quiz|worksheet|listening|reading):(\d+)$/', $ref, $m)) {
        return null;
    }
    return ['type' => $m[1], 'id' => (int)$m[2], 'ref' => $m[1] . ':' . (int)$m[2]];
}

function teacher_library_unit_share_mail_html(array $unit, array $selectedItems, string $buttonUrl): string
{
    require_once __DIR__ . '/../app/includes/email.php';

    $title = htmlspecialchars((string)($unit['title'] ?? 'Elevaro-Unit'), ENT_QUOTES, 'UTF-8');
    $subject = htmlspecialchars((string)($unit['subject_label'] ?? ''), ENT_QUOTES, 'UTF-8');
    $grade = htmlspecialchars((string)($unit['grade'] ?? ''), ENT_QUOTES, 'UTF-8');
    $topic = htmlspecialchars((string)(($unit['curriculum_subtopic_label'] ?? '') ?: ($unit['curriculum_topic_label'] ?? '')), ENT_QUOTES, 'UTF-8');
    $preview = '';
    foreach ($selectedItems as $item) {
        $preview .= '<li style="margin:6px 0;"><strong>' . htmlspecialchars(teacher_library_type_icon((string)$item['type']) . ' ' . (string)$item['title'], ENT_QUOTES, 'UTF-8') . '</strong></li>';
    }
    if ($preview === '') {
        $preview = '<li>Alle Inhalte dieser Unit</li>';
    }

    $body = '<div style="border:1px solid #eceafe;background:#fafaff;border-radius:18px;padding:18px;margin:14px 0 18px;">'
        . '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#5a4ff3;font-weight:800;margin-bottom:8px;">Geteilte Unit</div>'
        . '<div style="font-size:24px;line-height:1.1;color:#172033;font-weight:900;margin-bottom:8px;">' . $title . '</div>'
        . '<div style="color:#6c7482;font-weight:700;">' . trim($subject . ($grade !== '' ? ' · Klasse ' . $grade : '') . ($topic !== '' ? ' · ' . $topic : '')) . '</div>'
        . '<ul style="padding-left:18px;margin:16px 0 0;color:#172033;">' . $preview . '</ul>'
        . '</div>'
        . '<p>Eine Kollegin oder ein Kollege hat Elevaro-Inhalte mit dir geteilt. Du kannst dir die Unit ansehen und die Inhalte für deinen Unterricht übernehmen.</p>';

    return elevaro_mail_layout('Elevaro-Unit geteilt', $body, 'Inhalt anzeigen', $buttonUrl);
}

function teacher_library_send_unit_share_mail(string $to, array $unit, array $selectedItems, string $buttonUrl): bool
{
    require_once __DIR__ . '/../app/includes/email.php';
    $subject = 'Elevaro-Unit geteilt: ' . (string)($unit['title'] ?? 'Unterrichtsmaterial');
    return elevaro_send_mail($to, $subject, teacher_library_unit_share_mail_html($unit, $selectedItems, $buttonUrl));
}
