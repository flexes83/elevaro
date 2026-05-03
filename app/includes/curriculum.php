<?php

require_once __DIR__ . '/db.php';

function curriculum_states(): array
{
    $stmt = elevaro_db()->query("
        SELECT code, name
        FROM states
        ORDER BY sort_order ASC, name ASC
    ");

    return $stmt->fetchAll();
}

function curriculum_school_types(string $stateCode): array
{
    $stmt = elevaro_db()->prepare("
        SELECT st.code, st.name, sst.min_grade, sst.max_grade
        FROM state_school_types sst
        JOIN states s ON s.id = sst.state_id
        JOIN school_types st ON st.id = sst.school_type_id
        WHERE s.code = :state
        ORDER BY sst.sort_order ASC, st.name ASC
    ");

    $stmt->execute(['state' => $stateCode]);

    return $stmt->fetchAll();
}

function curriculum_grades(string $stateCode, string $schoolTypeCode): array
{
    $stmt = elevaro_db()->prepare("
        SELECT sst.min_grade, sst.max_grade
        FROM state_school_types sst
        JOIN states s ON s.id = sst.state_id
        JOIN school_types st ON st.id = sst.school_type_id
        WHERE s.code = :state
          AND st.code = :school_type
        LIMIT 1
    ");

    $stmt->execute([
        'state' => $stateCode,
        'school_type' => $schoolTypeCode
    ]);

    $row = $stmt->fetch();

    if (!$row) {
        return [];
    }

    $grades = [];

    for ($i = (int)$row['min_grade']; $i <= (int)$row['max_grade']; $i++) {
        $grades[] = [
            'code' => (string)$i,
            'name' => $i . '. Klasse'
        ];
    }

    return $grades;
}

function curriculum_subjects(string $stateCode, string $schoolTypeCode, int $grade): array
{
    // Prefer subjects with existing curriculum topics.
    $stmt = elevaro_db()->prepare("
        SELECT DISTINCT sub.code, sub.name, sub.icon
        FROM curriculum_topics t
        JOIN states s ON s.id = t.state_id
        JOIN school_types st ON st.id = t.school_type_id
        JOIN subjects sub ON sub.id = t.subject_id
        WHERE s.code = :state
          AND st.code = :school_type
          AND t.grade = :grade
        ORDER BY sub.sort_order ASC, sub.name ASC
    ");

    $stmt->execute([
        'state' => $stateCode,
        'school_type' => $schoolTypeCode,
        'grade' => $grade
    ]);

    $subjects = $stmt->fetchAll();

    if (!empty($subjects)) {
        return $subjects;
    }

    // Fallback by grade until all curricula are imported.
    if ($grade <= 4) {
        $codes = ['deutsch', 'mathe', 'sachunterricht'];
    } elseif ($grade <= 6) {
        $codes = ['deutsch', 'mathe', 'englisch'];
    } else {
        $codes = ['deutsch', 'mathe', 'englisch', 'biologie', 'physik', 'chemie', 'geschichte', 'geographie'];
    }

    $placeholders = implode(',', array_fill(0, count($codes), '?'));

    $stmt = elevaro_db()->prepare("
        SELECT code, name, icon
        FROM subjects
        WHERE code IN ($placeholders)
        ORDER BY sort_order ASC, name ASC
    ");

    $stmt->execute($codes);

    return $stmt->fetchAll();
}

function curriculum_topics(string $stateCode, string $schoolTypeCode, int $grade, string $subjectCode): array
{
    $stmt = elevaro_db()->prepare("
        SELECT
            t.code,
            t.title,
            t.description,
            q.quiz_key,
            q.title AS quiz_title
        FROM curriculum_topics t
        JOIN states s ON s.id = t.state_id
        JOIN school_types st ON st.id = t.school_type_id
        JOIN subjects sub ON sub.id = t.subject_id
        LEFT JOIN quiz_topic_map qtm ON qtm.topic_id = t.id
        LEFT JOIN quizzes q ON q.id = qtm.quiz_id AND q.is_active = 1
        WHERE s.code = :state
          AND st.code = :school_type
          AND t.grade = :grade
          AND sub.code = :subject
        ORDER BY t.sort_order ASC, t.title ASC
    ");

    $stmt->execute([
        'state' => $stateCode,
        'school_type' => $schoolTypeCode,
        'grade' => $grade,
        'subject' => $subjectCode
    ]);

    return $stmt->fetchAll();
}


function curriculum_recommendations(string $stateCode, string $schoolTypeCode, int $grade, ?string $subjectCode = null, ?string $topicCode = null, ?string $tagsCsv = null): array
{
    $pdo = elevaro_db();

    $tags = array_values(array_filter(array_map(static function ($tag) {
        $tag = trim(mb_strtolower($tag, 'UTF-8'));
        $tag = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $tag);
        $tag = preg_replace('/[^a-z0-9\-]+/', '-', $tag);
        return trim($tag, '-');
    }, explode(',', (string)$tagsCsv))));

    if (!empty($tags)) {
        $params = [
            'grade' => $grade,
        ];

        $subjectWhere = '';
        if ($subjectCode) {
            $subjectWhere = 'AND sub.code = :subject';
            $params['subject'] = $subjectCode;
        }

        $tagPlaceholders = [];
        foreach ($tags as $i => $tag) {
            $key = 'tag_' . $i;
            $tagPlaceholders[] = ':' . $key;
            $params[$key] = $tag;
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT
                t.code AS topic_code,
                t.title AS topic_title,
                t.description AS topic_description,
                sub.code AS subject_code,
                sub.name AS subject_name,
                sub.icon AS subject_icon,
                q.quiz_key,
                q.title AS quiz_title,
                q.description AS quiz_description,
                q.theme_color_1,
                q.theme_color_2,
                q.theme_emoji,
                q.image_path,
                q.image_status,
                GROUP_CONCAT(DISTINCT tag.name ORDER BY tag.name SEPARATOR ', ') AS tag_names
            FROM quizzes q
            JOIN subjects sub ON sub.id = q.subject_id
            LEFT JOIN quiz_topic_map qtm ON qtm.quiz_id = q.id
            LEFT JOIN curriculum_topics t ON t.id = qtm.topic_id
            JOIN quiz_tag_map qtag ON qtag.quiz_id = q.id
            JOIN tags tag ON tag.id = qtag.tag_id
            WHERE q.grade = :grade
              {$subjectWhere}
              AND q.is_active = 1
              AND (q.status = 'published' OR q.status IS NULL OR q.status = '')
              AND tag.slug IN (" . implode(',', $tagPlaceholders) . ")
            GROUP BY q.id, t.id, sub.id
            ORDER BY COUNT(DISTINCT tag.id) DESC, q.title ASC
            LIMIT 12
        ");

        $stmt->execute($params);
        $items = $stmt->fetchAll();

        if (!empty($items)) {
            return $items;
        }
    }

    $params = [
        'state' => $stateCode,
        'school_type' => $schoolTypeCode,
        'grade' => $grade
    ];

    $subjectWhere = '';
    if ($subjectCode) {
        $subjectWhere = 'AND sub.code = :subject';
        $params['subject'] = $subjectCode;
    }

    $topicWhere = '';
    if ($topicCode) {
        $topicWhere = 'AND t.code = :topic';
        $params['topic'] = $topicCode;
    }

    $stmt = $pdo->prepare("
        SELECT
            t.code AS topic_code,
            t.title AS topic_title,
            t.description AS topic_description,
            sub.code AS subject_code,
            sub.name AS subject_name,
            sub.icon AS subject_icon,
            q.quiz_key,
            q.title AS quiz_title,
            q.description AS quiz_description,
            q.theme_color_1,
            q.theme_color_2,
            q.theme_emoji,
            q.image_path,
            q.image_status,
            GROUP_CONCAT(DISTINCT tag.name ORDER BY tag.name SEPARATOR ', ') AS tag_names
        FROM curriculum_topics t
        JOIN states s ON s.id = t.state_id
        JOIN school_types st ON st.id = t.school_type_id
        JOIN subjects sub ON sub.id = t.subject_id
        LEFT JOIN quiz_topic_map qtm ON qtm.topic_id = t.id
        LEFT JOIN quizzes q
          ON q.id = qtm.quiz_id
         AND q.is_active = 1
         AND (q.status = 'published' OR q.status IS NULL OR q.status = '')
        LEFT JOIN quiz_tag_map qtag ON qtag.quiz_id = q.id
        LEFT JOIN tags tag ON tag.id = qtag.tag_id
        WHERE s.code = :state
          AND st.code = :school_type
          AND t.grade = :grade
          {$subjectWhere}
          {$topicWhere}
        GROUP BY t.id, q.id, sub.id
        ORDER BY
          CASE WHEN q.quiz_key IS NULL THEN 1 ELSE 0 END,
          sub.sort_order ASC,
          t.sort_order ASC,
          q.title ASC
    ");

    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $hasUsableQuiz = false;
    foreach ($items as $item) {
        if (!empty($item['quiz_key'])) {
            $hasUsableQuiz = true;
            break;
        }
    }

    if ($hasUsableQuiz) {
        return $items;
    }

    if ($subjectCode) {
        $stmt = $pdo->prepare("
            SELECT
                NULL AS topic_code,
                'Passende Quizze' AS topic_title,
                NULL AS topic_description,
                sub.code AS subject_code,
                sub.name AS subject_name,
                sub.icon AS subject_icon,
                q.quiz_key,
                q.title AS quiz_title,
                q.description AS quiz_description,
                q.theme_color_1,
                q.theme_color_2,
                q.theme_emoji,
                q.image_path,
                q.image_status,
                GROUP_CONCAT(DISTINCT tag.name ORDER BY tag.name SEPARATOR ', ') AS tag_names
            FROM quizzes q
            JOIN subjects sub ON sub.id = q.subject_id
            LEFT JOIN quiz_tag_map qtag ON qtag.quiz_id = q.id
            LEFT JOIN tags tag ON tag.id = qtag.tag_id
            WHERE sub.code = :subject
              AND q.grade = :grade
              AND q.is_active = 1
              AND (q.status = 'published' OR q.status IS NULL OR q.status = '')
            GROUP BY q.id, sub.id
            ORDER BY q.title ASC
            LIMIT 12
        ");

        $stmt->execute([
            'subject' => $subjectCode,
            'grade' => $grade
        ]);

        $directItems = $stmt->fetchAll();

        if (!empty($directItems)) {
            return $directItems;
        }
    }

    return $items;
}

