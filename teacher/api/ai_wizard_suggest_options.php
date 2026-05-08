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
    $language = (string)($payload['language'] ?? 'Deutsch');

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'options' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4, 'items' => ['type' => 'string']],
            'answer' => ['type' => 'string'],
            'explanation' => ['type' => 'string'],
        ],
        'required' => ['options', 'answer', 'explanation'],
    ];
    $prompt = "Erstelle vier Multiple-Choice-Antworten für diese Frage. Genau eine Antwort ist richtig. Sprache: {$language}. Frage: {$question}";
    $result = elevaro_openai_chat_json([
        ['role' => 'system', 'content' => 'Du bist Lehrer und erstellst plausible Multiple-Choice-Optionen. Antworte nur als JSON.'],
        ['role' => 'user', 'content' => $prompt],
    ], $schema, 0.4);
    $json = $result['json'];

    // Nutzt denselben Qualitätscheck wie die Vor-Review-Bereinigung:
    // Falls mehrere Antwortkombinationen möglich wären, werden die Distraktoren eindeutig falsch gemacht.
    $candidate = [
        'question' => $question,
        'options' => array_values((array)($json['options'] ?? [])),
        'answer' => (string)($json['answer'] ?? ''),
        'explanation' => (string)($json['explanation'] ?? ''),
        'difficulty' => 0.5,
        'source_reference' => '',
        'listening_segment_text' => '',
        'listening_segment_title' => '',
    ];
    $analysis = [
        'title' => (string)($payload['title'] ?? ''),
        'description' => (string)($payload['description'] ?? ''),
        'language' => $language,
        'material_type' => (string)($payload['material_type'] ?? ''),
        'task_intent' => (string)($payload['task_intent'] ?? ''),
    ];
    $checked = elevaro_teacher_ai_disambiguate_questions_before_review($analysis, [$candidate], (string)($payload['mode'] ?? 'quiz'));
    if (!empty($checked[0])) {
        $json['options'] = array_values((array)$checked[0]['options']);
        $json['answer'] = (string)$checked[0]['answer'];
        $json['explanation'] = (string)($checked[0]['explanation'] ?? $json['explanation']);
        $json['_quality_checked'] = true;
        $json['_quality_changed'] = !empty($checked[0]['_quality_changed']);
        $json['_quality_reason'] = (string)($checked[0]['_quality_reason'] ?? '');
    }

    elevaro_teacher_ai_json_response(['ok' => true, 'suggestion' => $json]);
} catch (Throwable $e) {
    elevaro_teacher_ai_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
