<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/includes/teacher_ai_wizard.php';

try {
    $teacherId = teacher_current_user_id();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $draftId = (int)($input['draft_id'] ?? 0);
    $question = trim((string)($input['question'] ?? ''));
    $refineQuestion = !empty($input['refine_question']);
    $clientPayload = is_array($input['payload'] ?? null) ? $input['payload'] : [];

    if ($question === '') {
        throw new RuntimeException('Bitte zuerst eine Frage eingeben.');
    }

    $draft = elevaro_teacher_ai_load_draft($draftId, $teacherId);
    $payload = elevaro_teacher_ai_draft_payload($draft);
    if ($clientPayload) {
        $payload = array_merge($payload, $clientPayload);
    }

    $language = (string)($payload['language'] ?? 'Deutsch');
    $title = trim((string)($payload['title'] ?? ''));
    $description = trim((string)($payload['description'] ?? ''));
    $existingQuestions = [];
    foreach (($payload['questions'] ?? []) as $q) {
        $txt = trim((string)($q['question'] ?? ''));
        if ($txt !== '') {
            $existingQuestions[] = $txt;
        }
        if (count($existingQuestions) >= 8) break;
    }

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

    $task = $refineQuestion
        ? "Formuliere die eingegebene Lehrerfrage zu einer klaren, altersgerechten Multiple-Choice-Frage um und erstelle 4 Antwortmöglichkeiten."
        : "Erstelle vier Multiple-Choice-Antworten für diese Frage. Gib die Frage unverändert oder nur minimal geglättet zurück.";

    $prompt = $task . "\n\n" .
        "Regeln:\n" .
        "- Genau eine Antwort ist eindeutig richtig.\n" .
        "- Die drei Distraktoren sind plausibel, aber eindeutig falsch.\n" .
        "- Keine Fangfragen, keine Mehrdeutigkeiten.\n" .
        "- Sprache der Ausgabe: {$language}.\n" .
        "- Die Erklärung ist kurz, fachlich korrekt und passt exakt zur richtigen Antwort.\n" .
        "- Halte dich an Thema, Niveau und Stil des bestehenden Quiz.\n\n" .
        "Quiz-Titel: " . ($title !== '' ? $title : 'Unbenanntes Quiz') . "\n" .
        "Quiz-Beschreibung: " . ($description !== '' ? $description : '-') . "\n" .
        "Vorhandene Fragen als Kontext:\n- " . ($existingQuestions ? implode("\n- ", $existingQuestions) : '-') . "\n\n" .
        "Lehrer-Eingabe / Frage:\n{$question}\n\n" .
        "Gib ausschließlich JSON nach Schema zurück.";

    $result = elevaro_openai_chat_json([
        ['role' => 'system', 'content' => 'Du bist ein präziser Fachdidaktiker und Quizautor. Du reparierst und formulierst Multiple-Choice-Fragen sauber. Antworte nur als JSON.'],
        ['role' => 'user', 'content' => $prompt],
    ], $schema, 0.25);

    $json = $result['json'];
    if (!in_array($json['answer'] ?? '', $json['options'] ?? [], true)) {
        $json['answer'] = $json['options'][0] ?? '';
    }

    elevaro_teacher_ai_json_response(['ok' => true, 'suggestion' => $json]);
} catch (Throwable $e) {
    elevaro_teacher_ai_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
