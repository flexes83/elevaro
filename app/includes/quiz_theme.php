<?php

function elevaro_subject_theme(string $subjectCodeOrName = '', string $quizTitle = ''): array
{
    $key = mb_strtolower(trim($subjectCodeOrName), 'UTF-8');
    $title = mb_strtolower(trim($quizTitle), 'UTF-8');

    $themes = [
        'mathe' => ['#6c5ce7', '#a29bfe', '➗'],
        'mathematik' => ['#6c5ce7', '#a29bfe', '➗'],
        'deutsch' => ['#fdcb6e', '#ffeaa7', '📖'],
        'englisch' => ['#0984e3', '#74b9ff', '🇬🇧'],
        'geographie' => ['#00b894', '#55efc4', '🌍'],
        'erdkunde' => ['#00b894', '#55efc4', '🌍'],
        'biologie' => ['#00cec9', '#81ecec', '🌱'],
        'bio' => ['#00cec9', '#81ecec', '🌱'],
        'physik' => ['#2d3436', '#74b9ff', '🧲'],
        'chemie' => ['#e17055', '#fab1a0', '⚗️'],
        'geschichte' => ['#a76f3d', '#e0b084', '🏛️'],
        'sachunterricht' => ['#00b894', '#ffeaa7', '🔎'],
    ];

    foreach ($themes as $needle => $theme) {
        if (str_contains($key, $needle)) {
            return [
                'color_1' => $theme[0],
                'color_2' => $theme[1],
                'emoji' => elevaro_theme_emoji_by_title($title, $theme[2]),
            ];
        }
    }

    return [
        'color_1' => '#5a4ff3',
        'color_2' => '#8b7cff',
        'emoji' => elevaro_theme_emoji_by_title($title, '🎯'),
    ];
}

function elevaro_theme_emoji_by_title(string $title, string $fallback): string
{
    $map = [
        'bruch' => '➗',
        'zahl' => '🔢',
        'geometr' => '📐',
        'wortart' => '📖',
        'grammatik' => '✍️',
        'these' => '🇬🇧',
        'those' => '🇬🇧',
        'kontinent' => '🌍',
        'karte' => '🗺️',
        'wetter' => '⛅',
        'klima' => '🌦️',
        'stadt' => '🏙️',
        'umwelt' => '🌱',
        'experiment' => '🧪',
    ];

    foreach ($map as $needle => $emoji) {
        if (str_contains($title, $needle)) {
            return $emoji;
        }
    }

    return $fallback;
}

function elevaro_apply_quiz_theme(PDO $pdo, int $quizId): void
{
    $stmt = $pdo->prepare("
        SELECT q.title, sub.code AS subject_code, sub.name AS subject_name
        FROM quizzes q
        LEFT JOIN subjects sub ON sub.id = q.subject_id
        WHERE q.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $quizId]);
    $quiz = $stmt->fetch();

    if (!$quiz) {
        return;
    }

    $theme = elevaro_subject_theme($quiz['subject_code'] ?: $quiz['subject_name'] ?: '', $quiz['title']);

    $stmt = $pdo->prepare("
        UPDATE quizzes
        SET theme_color_1 = COALESCE(theme_color_1, :c1),
            theme_color_2 = COALESCE(theme_color_2, :c2),
            theme_emoji = COALESCE(theme_emoji, :emoji)
        WHERE id = :id
    ");

    $stmt->execute([
        'c1' => $theme['color_1'],
        'c2' => $theme['color_2'],
        'emoji' => $theme['emoji'],
        'id' => $quizId,
    ]);
}

function elevaro_image_prompt_for_quiz(array $quiz): string
{
    $title = $quiz['title'] ?? '';
    $subject = $quiz['subject_name'] ?? '';
    $grade = $quiz['grade'] ?? '';

    return trim("
Freundliche moderne flache Illustration für ein Lernquiz.
Thema: {$title}
Fach: {$subject}
Klasse: {$grade}
Stil: schülergerecht, motivierend, nicht babyhaft, helle Farben, klare Formen, kein Text im Bild, keine Logos, keine realistischen Personen.
");
}
