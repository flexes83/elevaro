<?php

require_once __DIR__ . '/db.php';


function curriculum_column_exists(string $tableName, string $columnName): bool
{
    try {
        $stmt = elevaro_db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

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
    $hasCategory = curriculum_column_exists('school_types', 'school_category');
    $categorySelect = $hasCategory ? "st.school_category" : "'general' AS school_category";

    $stmt = elevaro_db()->prepare("
        SELECT st.code, st.name, {$categorySelect}, sst.min_grade, sst.max_grade
        FROM state_school_types sst
        JOIN states s ON s.id = sst.state_id
        JOIN school_types st ON st.id = sst.school_type_id
        WHERE s.code = :state
        ORDER BY sst.sort_order ASC, st.name ASC
    ");

    $stmt->execute(['state' => $stateCode]);

    return $stmt->fetchAll();
}

function curriculum_is_vocational_school_type(string $stateCode, string $schoolTypeCode): bool
{
    if (!curriculum_column_exists('school_types', 'school_category')) {
        return false;
    }

    $stmt = elevaro_db()->prepare("
        SELECT st.school_category
        FROM state_school_types sst
        JOIN states s ON s.id = sst.state_id
        JOIN school_types st ON st.id = sst.school_type_id
        WHERE s.code = :state
          AND st.code = :school_type
        LIMIT 1
    ");

    $stmt->execute([
        'state' => $stateCode,
        'school_type' => $schoolTypeCode,
    ]);

    return (string)$stmt->fetchColumn() === 'vocational';
}

function curriculum_education_tracks(string $stateCode, string $schoolTypeCode): array
{
    if (!curriculum_table_exists('education_tracks')) {
        return [];
    }

    $stmt = elevaro_db()->prepare("
        SELECT et.code, et.name, et.min_level, et.max_level
        FROM education_tracks et
        JOIN states s ON s.id = et.state_id
        JOIN school_types st ON st.id = et.school_type_id
        WHERE s.code = :state
          AND st.code = :school_type
        ORDER BY et.sort_order ASC, et.name ASC
    ");

    $stmt->execute([
        'state' => $stateCode,
        'school_type' => $schoolTypeCode,
    ]);

    return $stmt->fetchAll();
}

function curriculum_education_track_levels(string $stateCode, string $schoolTypeCode, string $trackCode): array
{
    if (!curriculum_table_exists('education_tracks') || !curriculum_table_exists('education_track_levels')) {
        return [];
    }

    $stmt = elevaro_db()->prepare("
        SELECT etl.code, etl.name, etl.level_order
        FROM education_track_levels etl
        JOIN education_tracks et ON et.id = etl.track_id
        JOIN states s ON s.id = et.state_id
        JOIN school_types st ON st.id = et.school_type_id
        WHERE s.code = :state
          AND st.code = :school_type
          AND et.code = :track
        ORDER BY etl.level_order ASC, etl.name ASC
    ");

    $stmt->execute([
        'state' => $stateCode,
        'school_type' => $schoolTypeCode,
        'track' => $trackCode,
    ]);

    return $stmt->fetchAll();
}

function curriculum_grades(string $stateCode, string $schoolTypeCode): array
{
    // Berufliche Schulen nutzen künftig Bildungsgang + Stufe statt normaler Klassen.
    // Der bestehende allgemeinbildende Flow bleibt unverändert.
    if (curriculum_is_vocational_school_type($stateCode, $schoolTypeCode)) {
        return [];
    }

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
            q.id AS quiz_id,
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

function curriculum_table_exists(string $tableName): bool
{
    try {
        $stmt = elevaro_db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute(['table_name' => $tableName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}


function curriculum_learning_areas(string $stateCode, string $schoolTypeCode, int $grade, string $subjectCode): array
{
    $pdo = elevaro_db();

    // Prefer real quiz tags. This is the desired model: broad tags like Ornithologie, Bruchrechnen, Simple Past.
    if (curriculum_table_exists('tags') && curriculum_table_exists('quiz_tag_map')) {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    tag.slug AS code,
                    tag.name AS title,
                    CONCAT('Quizze und Übungen zum Lernbereich ', tag.name, '.') AS description,
                    GROUP_CONCAT(DISTINCT t.code ORDER BY t.sort_order ASC SEPARATOR ',') AS topic_codes,
                    COUNT(DISTINCT q.id) AS quiz_count,
                    COUNT(DISTINCT t.id) AS topic_count
                FROM tags tag
                JOIN quiz_tag_map qtag ON qtag.tag_id = tag.id
                JOIN quizzes q ON q.id = qtag.quiz_id
                JOIN subjects sub ON sub.id = q.subject_id
                LEFT JOIN quiz_topic_map qtm ON qtm.quiz_id = q.id
                LEFT JOIN curriculum_topics t ON t.id = qtm.topic_id
                LEFT JOIN states s ON s.id = t.state_id
                LEFT JOIN school_types st ON st.id = t.school_type_id
                WHERE q.grade = :grade
                  AND sub.code = :subject
                  AND q.is_active = 1
                  AND (q.status = 'published' OR q.status = 'draft' OR q.status IS NULL OR q.status = '')
                  AND (s.code = :state OR s.code IS NULL)
                  AND (st.code = :school_type OR st.code IS NULL)
                GROUP BY tag.id
                ORDER BY quiz_count DESC, tag.name ASC
                LIMIT 12
            ");
            $stmt->execute([
                'state' => $stateCode,
                'school_type' => $schoolTypeCode,
                'grade' => $grade,
                'subject' => $subjectCode,
            ]);

            $areas = array_map(static function (array $row): array {
                return [
                    'code' => $row['code'],
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'tags' => [$row['code']],
                    'topic_codes' => $row['topic_codes'] ? explode(',', $row['topic_codes']) : [],
                    'quiz_count' => (int)$row['quiz_count'],
                    'topic_count' => (int)$row['topic_count'],
                ];
            }, $stmt->fetchAll());

            if (!empty($areas)) {
                return $areas;
            }
        } catch (Throwable $e) {
            // Fall through to heuristic grouping.
        }
    }

    // Fallback while older quizzes have no tags yet: group fine curriculum topics into broad learning areas.
    $topics = curriculum_topics($stateCode, $schoolTypeCode, $grade, $subjectCode);
    $groups = [];

    foreach ($topics as $topic) {
        $area = curriculum_area_from_topic($topic, $subjectCode);
        $code = $area['code'];

        if (!isset($groups[$code])) {
            $groups[$code] = [
                'code' => $code,
                'title' => $area['title'],
                'description' => $area['description'],
                'tags' => $area['tags'],
                'topic_codes' => [],
                'topic_count' => 0,
                'quiz_count' => 0,
            ];
        }

        if (!empty($topic['code'])) {
            $groups[$code]['topic_codes'][] = $topic['code'];
        }

        if (!empty($topic['quiz_key'])) {
            $groups[$code]['quiz_count']++;
        }

        $groups[$code]['topic_count']++;
    }

    $items = array_values($groups);
    usort($items, static function ($a, $b) {
        return ($b['quiz_count'] <=> $a['quiz_count'])
            ?: ($b['topic_count'] <=> $a['topic_count'])
            ?: strcmp($a['title'], $b['title']);
    });

    return $items;
}

function curriculum_area_from_topic(array $topic, string $subjectCode): array
{
    $raw = mb_strtolower(trim(implode(' ', [
        $topic['code'] ?? '',
        $topic['title'] ?? '',
        $topic['name'] ?? '',
        $topic['description'] ?? '',
        $subjectCode,
    ])), 'UTF-8');

    $areas = [
        [
            'code' => 'ornithologie',
            'title' => 'Ornithologie',
            'description' => 'Vogelarten, Merkmale und Lebensräume erkennen und verstehen.',
            'tags' => ['ornithologie', 'vogelarten'],
            'needles' => ['vogel', 'vögel', 'feder', 'schnabel', 'flügel', 'lebensraum']
        ],
        [
            'code' => 'kartenkunde',
            'title' => 'Kartenkunde',
            'description' => 'Karten, Himmelsrichtungen und Orientierung sicher verstehen.',
            'tags' => ['kartenkunde', 'orientierung'],
            'needles' => ['karte', 'landkarte', 'orientierung', 'himmelsrichtung', 'norden', 'süden', 'osten', 'westen']
        ],
        [
            'code' => 'grundbegriffe',
            'title' => 'Grundbegriffe',
            'description' => 'Wichtige Begriffe sicher erkennen, erklären und zuordnen.',
            'tags' => ['grundbegriffe', 'grundlagen'],
            'needles' => ['grundbegriff', 'begriff', 'kontinent', 'land', 'stadt', 'fluss', 'gebirge', 'definition']
        ],
        [
            'code' => 'demonstrativpronomen',
            'title' => 'Demonstrativpronomen',
            'description' => 'this, that, these und those sicher unterscheiden und anwenden.',
            'tags' => ['demonstrativpronomen', 'englisch-grammatik'],
            'needles' => ['this', 'that', 'these', 'those', 'demonstrativ']
        ],
        [
            'code' => 'simple-past',
            'title' => 'Simple Past',
            'description' => 'Vergangenheit im Englischen erkennen und korrekt verwenden.',
            'tags' => ['simple-past', 'englisch-grammatik'],
            'needles' => ['simple past', 'past tense', 'vergangenheit']
        ],
        [
            'code' => 'bruchrechnen',
            'title' => 'Bruchrechnen',
            'description' => 'Brüche verstehen, vergleichen und berechnen.',
            'tags' => ['bruchrechnen', 'mathematik'],
            'needles' => ['bruch', 'brüche', 'zähler', 'nenner']
        ],
    ];

    foreach ($areas as $area) {
        foreach ($area['needles'] as $needle) {
            if (str_contains($raw, $needle)) {
                unset($area['needles']);
                return $area;
            }
        }
    }

    return [
        'code' => 'grundlagen-' . preg_replace('/[^a-z0-9\-]+/i', '', mb_strtolower($subjectCode ?: 'lernen', 'UTF-8')),
        'title' => 'Grundlagen & Wiederholung',
        'description' => 'Starte mit passenden Grundlagen und wiederhole wichtige Inhalte.',
        'tags' => ['grundlagen', mb_strtolower($subjectCode ?: 'lernen', 'UTF-8')],
    ];
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

    if (!empty($tags) && curriculum_table_exists('tags') && curriculum_table_exists('quiz_tag_map')) {
        try {
            $params = ['grade' => $grade];
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
                    COALESCE(t.title, tag.name) AS topic_title,
                    t.description AS topic_description,
                    sub.code AS subject_code,
                    sub.name AS subject_name,
                    sub.icon AS subject_icon,
                    q.id AS quiz_id,
                    q.quiz_key,
                    q.title AS quiz_title,
                    q.description AS quiz_description,
                    q.theme_color_1,
                    q.theme_color_2,
                    q.theme_emoji,
                    q.image_path,
                    q.image_status,
                    (SELECT COUNT(*) FROM questions qq WHERE qq.quiz_id = q.id AND qq.status IN ('published','draft')) AS question_count,
                    GROUP_CONCAT(DISTINCT tag.name ORDER BY tag.name SEPARATOR ', ') AS tag_names
                FROM quizzes q
                JOIN subjects sub ON sub.id = q.subject_id
                JOIN quiz_tag_map qtag ON qtag.quiz_id = q.id
                JOIN tags tag ON tag.id = qtag.tag_id
                LEFT JOIN quiz_topic_map qtm ON qtm.quiz_id = q.id
                LEFT JOIN curriculum_topics t ON t.id = qtm.topic_id
                WHERE q.grade = :grade
                  {$subjectWhere}
                  AND q.is_active = 1
                  AND (q.status = 'published' OR q.status = 'draft' OR q.status IS NULL OR q.status = '')
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
        } catch (Throwable $e) {
            // Fall through to topic/fach fallback.
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
            q.id AS quiz_id,
            q.quiz_key,
            q.title AS quiz_title,
            q.description AS quiz_description,
            q.theme_color_1,
            q.theme_color_2,
            q.theme_emoji,
            q.image_path,
            q.image_status,
                    (SELECT COUNT(*) FROM questions qq WHERE qq.quiz_id = q.id AND qq.status IN ('published','draft')) AS question_count
        FROM curriculum_topics t
        JOIN states s ON s.id = t.state_id
        JOIN school_types st ON st.id = t.school_type_id
        JOIN subjects sub ON sub.id = t.subject_id
        LEFT JOIN quiz_topic_map qtm ON qtm.topic_id = t.id
        LEFT JOIN quizzes q
          ON q.id = qtm.quiz_id
         AND q.is_active = 1
         AND (q.status = 'published' OR q.status = 'draft' OR q.status IS NULL OR q.status = '')
        WHERE s.code = :state
          AND st.code = :school_type
          AND t.grade = :grade
          {$subjectWhere}
          {$topicWhere}
        ORDER BY CASE WHEN q.quiz_key IS NULL THEN 1 ELSE 0 END, t.sort_order ASC, q.title ASC
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
                q.id AS quiz_id,
                q.quiz_key,
                q.title AS quiz_title,
                q.description AS quiz_description,
                q.theme_color_1,
                q.theme_color_2,
                q.theme_emoji,
                q.image_path,
                q.image_status,
                    (SELECT COUNT(*) FROM questions qq WHERE qq.quiz_id = q.id AND qq.status IN ('published','draft')) AS question_count
            FROM quizzes q
            JOIN subjects sub ON sub.id = q.subject_id
            WHERE sub.code = :subject
              AND q.grade = :grade
              AND q.is_active = 1
              AND (q.status = 'published' OR q.status = 'draft' OR q.status IS NULL OR q.status = '')
            ORDER BY q.title ASC
            LIMIT 12
        ");

        $stmt->execute(['subject' => $subjectCode, 'grade' => $grade]);
        return $stmt->fetchAll();
    }

    return $items;
}


function curriculum_levels(string $stateCode, string $schoolTypeCode): array
{
    try {
        $stmt = elevaro_db()->prepare("
            SELECT
                l.id,
                l.code,
                l.name,
                l.numeric_grade
            FROM school_type_levels l
            JOIN states s ON s.id = l.state_id
            JOIN school_types st ON st.id = l.school_type_id
            WHERE s.code = :state
              AND st.code = :school_type
            ORDER BY l.sort_order ASC, l.name ASC
        ");

        $stmt->execute([
            'state' => $stateCode,
            'school_type' => $schoolTypeCode,
        ]);

        $levels = $stmt->fetchAll();

        if (!empty($levels)) {
            return array_map(static function (array $level): array {
                return [
                    'id' => $level['id'],
                    'code' => $level['code'],
                    'name' => $level['name'],
                    'numeric_grade' => $level['numeric_grade'],
                    // Compatibility: existing onboarding stores the selected code in values.grade.
                    'grade' => $level['numeric_grade'],
                ];
            }, $levels);
        }
    } catch (Throwable $e) {
        // Fall back below for older databases without school_type_levels.
    }

    return curriculum_grades($stateCode, $schoolTypeCode);
}

