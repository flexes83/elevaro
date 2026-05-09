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

function teacher_library_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = teacher_db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_units (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT UNSIGNED NOT NULL,
        unit_key VARCHAR(191) NOT NULL,
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
        UNIQUE KEY uniq_teacher_unit_key (teacher_id, unit_key),
        KEY idx_teacher_units_teacher (teacher_id),
        KEY idx_teacher_units_curriculum (curriculum_topic_content_id, curriculum_topic_subtopic_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
        UNIQUE KEY uniq_unit_quiz (unit_id, item_type, quiz_id),
        UNIQUE KEY uniq_unit_custom_quiz (unit_id, item_type, custom_quiz_id),
        KEY idx_teacher_unit_items_unit (unit_id),
        KEY idx_teacher_unit_items_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_unit_class_links (
        unit_id INT UNSIGNED NOT NULL,
        class_id INT UNSIGNED NOT NULL,
        teacher_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (unit_id, class_id),
        KEY idx_unit_class_teacher (teacher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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
