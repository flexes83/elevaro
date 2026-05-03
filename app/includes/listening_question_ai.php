<?php

declare(strict_types=1);

require_once __DIR__ . '/openai_client.php';

function elevaro_build_listening_question_text_prompt(array $quiz, array $question, array $options = []): string
{
    $subject = (string)($quiz['subject_name'] ?? '');
    $grade = (int)($quiz['grade'] ?? 0);
    $title = (string)($quiz['title'] ?? '');
    $description = (string)($quiz['description'] ?? '');
    $questionText = (string)($question['question_text'] ?? '');
    $correctAnswer = (string)($question['correct_answer'] ?? '');

    $optionTexts = [];
    foreach ($options as $option) {
        $optionTexts[] = (string)($option['option_text'] ?? '');
    }

    $language = str_contains(mb_strtolower($subject, 'UTF-8'), 'engl') ? 'Englisch' : 'Deutsch';

    return trim("
Erstelle einen kurzen Listening-Text für eine Multiple-Choice-Frage.

Kontext:
- Fach: {$subject}
- Klasse: {$grade}
- Quiz: {$title}
- Quizbeschreibung: {$description}
- Frage: {$questionText}
- Richtige Antwort: {$correctAnswer}
- Antwortoptionen: " . implode(', ', array_filter($optionTexts)) . "

Sprache des Hörtexts:
{$language}

Regeln:
- 1 bis 3 kurze Sätze.
- Der Text darf die richtige Antwort NICHT plump als isoliertes Wort nennen.
- Der Text muss genügend Hinweise enthalten, damit die richtige Antwort ableitbar ist.
- Altersgerecht und klar.
- Bei Englisch: einfache, native klingende Alltagssprache.
- Bei Deutsch: weich, ruhig und grundschultauglich formulieren.
- Keine Erklärung, keine Meta-Sätze, kein Markdown.

Gib ausschließlich den Hörtext zurück.
");
}

function elevaro_generate_listening_question_text(array $quiz, array $question, array $options = []): string
{
    $prompt = elevaro_build_listening_question_text_prompt($quiz, $question, $options);

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'audio_text' => ['type' => 'string']
        ],
        'required' => ['audio_text']
    ];

    $result = elevaro_openai_chat_json([
        [
            'role' => 'system',
            'content' => 'Du bist ein erfahrener Lehrer und erstellst kurze Listening-Texte für Schüler. Du lieferst ausschließlich JSON.'
        ],
        [
            'role' => 'user',
            'content' => $prompt
        ],
    ], $schema, 0.45);

    return trim((string)($result['json']['audio_text'] ?? ''));
}
