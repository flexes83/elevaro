<?php
// Add this function to app/includes/curriculum.php

function curriculum_recommendations(string $stateCode, string $schoolTypeCode, int $grade, ?string $subjectCode = null): array
{
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

    $stmt = elevaro_db()->prepare("
        SELECT
            t.code AS topic_code,
            t.title AS topic_title,
            t.description AS topic_description,
            sub.code AS subject_code,
            sub.name AS subject_name,
            sub.icon AS subject_icon,
            q.quiz_key,
            q.title AS quiz_title,
            q.description AS quiz_description
        FROM curriculum_topics t
        JOIN states s ON s.id = t.state_id
        JOIN school_types st ON st.id = t.school_type_id
        JOIN subjects sub ON sub.id = t.subject_id
        LEFT JOIN quiz_topic_map qtm ON qtm.topic_id = t.id
        LEFT JOIN quizzes q ON q.id = qtm.quiz_id AND q.is_active = 1
        WHERE s.code = :state
          AND st.code = :school_type
          AND t.grade = :grade
          {$subjectWhere}
        ORDER BY sub.sort_order ASC, t.sort_order ASC, q.title ASC
    ");

    $stmt->execute($params);

    return $stmt->fetchAll();
}
