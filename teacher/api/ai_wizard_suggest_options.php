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
    elevaro_teacher_ai_json_response(['ok' => true, 'suggestion' => $json]);
} catch (Throwable $e) {
    elevaro_teacher_ai_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
