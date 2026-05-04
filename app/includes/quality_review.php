<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/openai_client.php';

function elevaro_quality_column_exists(string $table, string $column): bool
{
    try {
        $stmt = elevaro_db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute(['table_name' => $table, 'column_name' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function elevaro_quality_ready(): bool
{
    return elevaro_quality_column_exists('questions', 'ai_validation_status')
        && elevaro_quality_column_exists('questions', 'moderator_status')
        && elevaro_quality_table_exists('question_reports');
}

function elevaro_quality_table_exists(string $table): bool
{
    try {
        $stmt = elevaro_db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
        ");
        $stmt->execute(['table_name' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function elevaro_load_question_for_validation(PDO $pdo, int $questionId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
          q.*,
          quiz.title AS quiz_title,
          quiz.description AS quiz_description,
          quiz.grade,
          sub.name AS subject_name
        FROM questions q
        JOIN quizzes quiz ON quiz.id = q.quiz_id
        LEFT JOIN subjects sub ON sub.id = quiz.subject_id
        WHERE q.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $questionId]);
    $question = $stmt->fetch();

    if (!$question) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT option_text, is_correct FROM question_options WHERE question_id = :id ORDER BY sort_order, id");
    $stmt->execute(['id' => $questionId]);
    $question['options'] = $stmt->fetchAll();

    return $question;
}

function elevaro_validate_question_with_ai(PDO $pdo, int $questionId): array
{
    $question = elevaro_load_question_for_validation($pdo, $questionId);

    if (!$question) {
        throw new RuntimeException('Frage nicht gefunden.');
    }

    $optionTexts = [];
    foreach ($question['options'] as $option) {
        $optionTexts[] = ($option['is_correct'] ? '[korrekt] ' : '') . $option['option_text'];
    }

    $prompt = trim("
Prüfe eine Schulquiz-Frage fachlich und didaktisch.

Kontext:
- Quiz: {$question['quiz_title']}
- Beschreibung: {$question['quiz_description']}
- Fach: {$question['subject_name']}
- Klasse: {$question['grade']}

Frage:
{$question['question_text']}

Antwortoptionen:
" . implode("\n", $optionTexts) . "

Als korrekt hinterlegte Antwort:
{$question['correct_answer']}

Erklärung:
{$question['explanation']}

Aufgabe:
- Prüfe, ob die hinterlegte richtige Antwort fachlich korrekt ist.
- Prüfe, ob die Erklärung fachlich korrekt ist.
- Prüfe, ob es mehrere richtige Antworten gibt.
- Prüfe, ob die Frage eindeutig und altersgerecht ist.
- Wenn etwas falsch ist, gib eine konkrete Korrektur oder Empfehlung.
- Wenn du nicht sicher bist, setze confidence niedrig und needs_review auf true.
- Erfinde keine Fakten. Markiere Unsicherheit klar.
");

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'is_valid' => ['type' => 'boolean'],
            'needs_review' => ['type' => 'boolean'],
            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            'issues' => [
                'type' => 'array',
                'items' => ['type' => 'string']
            ],
            'correct_answer_should_be' => ['type' => 'string'],
            'corrected_explanation' => ['type' => 'string'],
            'suggestion' => ['type' => 'string']
        ],
        'required' => ['is_valid','needs_review','confidence','issues','correct_answer_should_be','corrected_explanation','suggestion']
    ];

    $result = elevaro_openai_chat_json([
        [
            'role' => 'system',
            'content' => 'Du bist ein strenger Faktenprüfer für Schulquizze. Du prüfst nüchtern, fachlich und konservativ. Du lieferst ausschließlich JSON.'
        ],
        [
            'role' => 'user',
            'content' => $prompt
        ],
    ], $schema, 0.1);

    $json = $result['json'];

    $confidence = (float)($json['confidence'] ?? 0);
    $issues = $json['issues'] ?? [];
    if (!is_array($issues)) {
        $issues = [];
    }

    $status = 'valid';
    if (!empty($json['needs_review']) || $confidence < 0.82) {
        $status = 'needs_review';
    }
    if (empty($json['is_valid'])) {
        $status = 'invalid';
    }

    $suggestionParts = [];
    if (!empty($json['correct_answer_should_be'])) {
        $suggestionParts[] = 'Korrekte Antwort laut Prüfung: ' . $json['correct_answer_should_be'];
    }
    if (!empty($json['corrected_explanation'])) {
        $suggestionParts[] = 'Korrigierte Erklärung: ' . $json['corrected_explanation'];
    }
    if (!empty($json['suggestion'])) {
        $suggestionParts[] = 'Hinweis: ' . $json['suggestion'];
    }

    $moderatorStatus = $status === 'valid' ? 'pending' : 'needs_review';

    $stmt = $pdo->prepare("
        UPDATE questions
        SET ai_validation_status = :status,
            ai_validation_confidence = :confidence,
            ai_validation_issues = :issues,
            ai_validation_suggestion = :suggestion,
            ai_validation_checked_at = NOW(),
            moderator_status = CASE
              WHEN moderator_status = 'approved' AND :status_check = 'valid' THEN moderator_status
              ELSE :moderator_status
            END
        WHERE id = :id
    ");
    $stmt->execute([
        'status' => $status,
        'status_check' => $status,
        'confidence' => $confidence,
        'issues' => implode("\n", $issues),
        'suggestion' => implode("\n\n", $suggestionParts),
        'moderator_status' => $moderatorStatus,
        'id' => $questionId,
    ]);

    return [
        'status' => $status,
        'confidence' => $confidence,
        'issues' => $issues,
        'suggestion' => implode("\n\n", $suggestionParts),
        'raw' => $json,
    ];
}

function elevaro_report_question(array $payload): int
{
    if (!elevaro_quality_table_exists('question_reports')) {
        throw new RuntimeException('question_reports Tabelle fehlt.');
    }

    $pdo = elevaro_db();

    $questionId = (int)($payload['question_id'] ?? 0);
    $quizId = (int)($payload['quiz_id'] ?? 0);

    if (!$questionId || !$quizId) {
        throw new RuntimeException('question_id oder quiz_id fehlt.');
    }

    $allowedReasons = ['wrong_answer','bad_explanation','typo','unclear','technical','other'];
    $reason = (string)($payload['reason'] ?? 'other');
    if (!in_array($reason, $allowedReasons, true)) {
        $reason = 'other';
    }

    $stmt = $pdo->prepare("
        INSERT INTO question_reports
          (question_id, quiz_id, user_id, quiz_session_id, selected_answer, reason, message, page_url, user_agent)
        VALUES
          (:question_id, :quiz_id, :user_id, :quiz_session_id, :selected_answer, :reason, :message, :page_url, :user_agent)
    ");

    $stmt->execute([
        'question_id' => $questionId,
        'quiz_id' => $quizId,
        'user_id' => $payload['user_id'] ?? null,
        'quiz_session_id' => $payload['quiz_session_id'] ?? null,
        'selected_answer' => $payload['selected_answer'] ?? null,
        'reason' => $reason,
        'message' => trim((string)($payload['message'] ?? '')) ?: null,
        'page_url' => substr((string)($payload['page_url'] ?? ''), 0, 500) ?: null,
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
    ]);

    $reportId = (int)$pdo->lastInsertId();

    if (elevaro_quality_column_exists('questions', 'reports_count')) {
        $pdo->prepare("
            UPDATE questions
            SET reports_count = reports_count + 1,
                status = CASE WHEN reports_count + 1 >= 2 THEN 'flagged' ELSE status END,
                moderator_status = 'needs_review',
                hidden_reason = CASE WHEN reports_count + 1 >= 2 THEN 'Mehrfach gemeldet' ELSE hidden_reason END
            WHERE id = :id
        ")->execute(['id' => $questionId]);
    }

    return $reportId;
}
