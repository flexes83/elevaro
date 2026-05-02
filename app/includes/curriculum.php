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
