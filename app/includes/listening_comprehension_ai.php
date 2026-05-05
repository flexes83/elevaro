<?php

declare(strict_types=1);

require_once __DIR__ . '/openai_client.php';
require_once __DIR__ . '/ai_source_context.php';

function elevaro_build_listening_comprehension_prompt(array $quiz, int $questionCount = 12, array $sourceContext = []): string
{
    $subject = (string)($quiz['subject_name'] ?? '');
    $grade = (int)($quiz['grade'] ?? 0);
    $title = (string)($quiz['title'] ?? '');
    $description = (string)($quiz['description'] ?? '');

    $isEnglish = str_contains(mb_strtolower($subject, 'UTF-8'), 'engl');
    $language = $isEnglish ? 'Englisch' : 'Deutsch';

    $durationHint = $isEnglish
        ? 'ca. 430 bis 560 englische Wörter, je nach Satzlänge'
        : 'ca. 520 bis 700 deutsche Wörter, je nach Satzlänge';

    $sourceBlock = elevaro_ai_build_context_block($sourceContext);

    return trim("
Erstelle ein komplettes Listening-Comprehension-Quiz.

Kontext:
- Fach: {$subject}
- Klasse: {$grade}
- Quiztitel: {$title}
- Beschreibung: {$description}
- Gewünschte Anzahl Fragen: {$questionCount}

Sprache des Hörtexts:
{$language}

Aufgabe:
1. Erstelle einen zusammenhängenden 3- bis 4-minütigen Infotext.
2. Erstelle dazu {$questionCount} Multiple-Choice-Fragen.
3. Die Fragen dürfen ausschließlich mit Informationen aus dem Hörtext beantwortbar sein.

Länge des Hörtexts:
- {$durationHint}
- Wenn die Klasse sehr niedrig ist, eher kürzer und einfacher.
- Wenn die Klasse höher ist, etwas dichter und informativer.

Didaktische Regeln:
- Der Text soll informativ, klar gegliedert und gut hörbar sein.
- Kurze bis mittlere Sätze.
- Keine Listen mit Aufzählungszeichen im Hörtext.
- Keine Zwischenüberschriften im Hörtext.
- Der Text soll wie ein gut gesprochener Audiobeitrag klingen.
- Fragen sollen verschiedene Ebenen abdecken:
  - direktes Wiederfinden
  - Verstehen
  - Reihenfolge/Zusammenhang
  - einfache Schlussfolgerung
- Genau 4 Antwortmöglichkeiten pro Frage.
- Genau eine Antwort ist korrekt.
- Falsche Antworten sollen plausibel sein, aber klar falsch.
- Jede Frage bekommt eine kurze Erklärung.
- Jede Frage bekommt ein kurzes source_excerpt aus dem Hörtext, worauf sie sich bezieht.
- Schwierigkeitsmix: ca. 40% leicht, 40% mittel, 20% schwer.

{$sourceBlock}

Gib ausschließlich JSON zurück.
");
}

function elevaro_generate_listening_comprehension(array $quiz, int $questionCount = 12, array $sourceContext = []): array
{
    $questionCount = max(6, min(20, $questionCount));
    $prompt = elevaro_build_listening_comprehension_prompt($quiz, $questionCount, $sourceContext);

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'listening_text' => ['type' => 'string'],
            'summary' => ['type' => 'string'],
            'questions' => [
                'type' => 'array',
                'minItems' => $questionCount,
                'maxItems' => $questionCount,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'options' => [
                            'type' => 'array',
                            'minItems' => 4,
                            'maxItems' => 4,
                            'items' => ['type' => 'string']
                        ],
                        'answer' => ['type' => 'string'],
                        'explanation' => ['type' => 'string'],
                        'source_excerpt' => ['type' => 'string'],
                        'difficulty' => ['type' => 'number', 'minimum' => 0.05, 'maximum' => 0.95],
                        'difficulty_label' => ['type' => 'string', 'enum' => ['leicht','mittel','schwer']]
                    ],
                    'required' => [
                        'question',
                        'options',
                        'answer',
                        'explanation',
                        'source_excerpt',
                        'difficulty',
                        'difficulty_label'
                    ]
                ]
            ]
        ],
        'required' => ['listening_text','summary','questions']
    ];

    $result = elevaro_openai_chat_json([
        [
            'role' => 'system',
            'content' => 'Du bist ein erfahrener Lehrer und erstellst didaktisch saubere Listening-Comprehension-Quizze. Du lieferst ausschließlich valides JSON. Bei aktuellen, politischen oder strittigen Themen bist du besonders konservativ: keine erfundenen Fakten, keine Spekulationen, keine unbelegten Entwicklungen.'
        ],
        [
            'role' => 'user',
            'content' => $prompt
        ],
    ], $schema, 0.42);

    return [
        'prompt' => $prompt,
        'json' => $result['json'],
        'raw' => $result['raw'] ?? null,
        'content' => $result['content'] ?? null,
    ];
}

function elevaro_insert_listening_questions(PDO $pdo, int $quizId, array $questions): void
{
    // Listening-Comprehension is a full quiz mode.
    // Existing questions must be replaced completely, otherwise old general questions
    // appear before the listening questions and have no relation to the audio text.
    $pdo->prepare("
        DELETE qo
        FROM question_options qo
        JOIN questions q ON q.id = qo.question_id
        WHERE q.quiz_id = :quiz_id
    ")->execute(['quiz_id' => $quizId]);

    $pdo->prepare("DELETE FROM question_stats WHERE question_id NOT IN (SELECT id FROM questions)")
        ->execute();

    $pdo->prepare("DELETE FROM questions WHERE quiz_id = :quiz_id")
        ->execute(['quiz_id' => $quizId]);

    $sort = 0;

    foreach ($questions as $q) {
        $sort++;
        $questionText = trim((string)($q['question'] ?? ''));
        $answer = trim((string)($q['answer'] ?? ''));
        $options = $q['options'] ?? [];

        if ($questionText === '' || $answer === '' || !is_array($options) || count($options) < 4) {
            continue;
        }

        $options = array_values(array_map(static fn($v) => trim((string)$v), $options));
        $options = array_values(array_filter($options, static fn($v) => $v !== ''));

        if (!in_array($answer, $options, true)) {
            $options[0] = $answer;
        }

        $options = array_slice(array_values(array_unique($options)), 0, 4);
        while (count($options) < 4) {
            $options[] = 'Keine der Antworten';
        }

        shuffle($options);

        $stmt = $pdo->prepare("
            INSERT INTO questions
              (quiz_id, question_key, type, question_text,
               media_type, media_path, media_alt,
               correct_answer, explanation,
               difficulty_manual, difficulty_calculated,
               status, ai_generated, sort_order,
               source_context, source_excerpt)
            VALUES
              (:quiz_id, :question_key, 'mc', :question_text,
               'none', NULL, NULL,
               :correct_answer, :explanation,
               :difficulty_manual, :difficulty_calculated,
               'draft', 1, :sort_order,
               'listening_text', :source_excerpt)
        ");

        $stmt->execute([
            'quiz_id' => $quizId,
            'question_key' => elevaro_listening_slug($questionText) . '-' . substr(md5(uniqid('', true)), 0, 6),
            'question_text' => $questionText,
            'correct_answer' => $answer,
            'explanation' => trim((string)($q['explanation'] ?? '')),
            'difficulty_manual' => (float)($q['difficulty'] ?? 0.5),
            'difficulty_calculated' => (float)($q['difficulty'] ?? 0.5),
            'sort_order' => $sort,
            'source_excerpt' => trim((string)($q['source_excerpt'] ?? '')),
        ]);

        $questionId = (int)$pdo->lastInsertId();

        foreach ($options as $i => $optionText) {
            $pdo->prepare("
                INSERT INTO question_options
                  (question_id, option_text, media_type, media_path, media_alt, is_correct, sort_order)
                VALUES
                  (:question_id, :option_text, 'none', NULL, NULL, :is_correct, :sort_order)
            ")->execute([
                'question_id' => $questionId,
                'option_text' => $optionText,
                'is_correct' => $optionText === $answer ? 1 : 0,
                'sort_order' => $i + 1,
            ]);
        }

        $pdo->prepare("
            INSERT IGNORE INTO question_stats (question_id, calculated_difficulty)
            VALUES (:question_id, :difficulty)
        ")->execute([
            'question_id' => $questionId,
            'difficulty' => (float)($q['difficulty'] ?? 0.5),
        ]);
    }
}

function elevaro_listening_slug(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim((string)$text, '-');
    return mb_substr($text ?: 'listening-question', 0, 120, 'UTF-8');
}
