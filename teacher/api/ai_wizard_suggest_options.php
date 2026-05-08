<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/includes/teacher_ai_wizard.php';

try {
    $teacherId = teacher_current_user_id();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $draftId = (int)($input['draft_id'] ?? 0);
    $question = trim((string)($input['question'] ?? ''));
    if ($question === '') throw new RuntimeException('Bitte zuerst eine Frage eingeben.');
    $draft = elevaro_teacher_ai_load_draft($draftId, $teacherId);
    $payload = elevaro_teacher_ai_draft_payload($draft);
    $class = elevaro_teacher_ai_class_for_teacher((int)($draft['class_id'] ?? 0), $teacherId);
    $subject = elevaro_teacher_ai_subject_label($class['subject_code'] ?? '');
    $analysis = (array)($payload['analysis'] ?? $payload['source_analysis'] ?? []);
    $language = (string)($payload['language'] ?? $analysis['target_language'] ?? 'Deutsch');

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'question' => ['type' => 'string'],
            'options' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4, 'items' => ['type' => 'string']],
            'answer' => ['type' => 'string'],
            'explanation' => ['type' => 'string'],
        ],
        'required' => ['question', 'options', 'answer', 'explanation'],
    ];
    $promptLibrary = function_exists('elevaro_prompt_library_build') ? elevaro_prompt_library_build([
        'stage' => 'suggest_options',
        'mode' => (string)($payload['mode'] ?? $draft['mode'] ?? 'quiz'),
        'subject_code' => (string)($class['subject_code'] ?? ''),
        'subject_label' => $subject,
        'material_type' => (string)($analysis['material_type'] ?? ''),
        'task_intent' => (string)($analysis['task_intent'] ?? ''),
        'content_mode' => (string)($analysis['content_mode'] ?? ''),
        'generation_strategy' => (string)($analysis['generation_strategy'] ?? ''),
        'detected_skills' => $analysis['detected_skills'] ?? [],
        'topics' => $analysis['topics'] ?? [],
        'question' => $question,
    ]) : '';

    $prompt = "Formuliere aus der grob eingegebenen Frage eine saubere, altersgerechte Multiple-Choice-Quizfrage und erstelle vier Antwortmöglichkeiten.

" .
        "Kontext:
- Fach: {$subject}
- Sprache: {$language}

" .
        "Regeln:
- Gib im Feld question eine sprachlich saubere, klare Quizfrage zurück.
- Erhalte die inhaltliche Absicht der Lehrkraft.
- Genau eine Antwort ist richtig.
- Wenn mehrere Antworten möglich wären, wandle die falschen Optionen so ab, dass sie eindeutig falsch sind.
- Füge keine Übersetzungen, Hinweise oder Kontextinformationen hinzu, wenn sie nicht bereits Teil der Aufgabe sind.
- Erhalte Stil und Schwierigkeit der Aufgabe.
- Die Antwortoptionen müssen ohne Zusatzmaterial verständlich sein.
- Keine Fangfragen und keine zweite halb-richtige Antwort.

" .
        "Frage:
{$question}
" . $promptLibrary;

    $result = elevaro_openai_chat_json([
        ['role' => 'system', 'content' => 'Du bist Lehrer, Fachdidaktiker und Qualitätsprüfer. Erstelle plausible Multiple-Choice-Optionen. Antworte nur als JSON.'],
        ['role' => 'user', 'content' => $prompt],
    ], $schema, 0.25);
    $json = $result['json'];
    elevaro_teacher_ai_json_response(['ok' => true, 'suggestion' => $json]);
} catch (Throwable $e) {
    elevaro_teacher_ai_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
