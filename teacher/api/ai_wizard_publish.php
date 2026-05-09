<?php
declare(strict_types=1);

ob_start();

$__elevaroAiPublishResponded = false;

register_shutdown_function(static function () use (&$__elevaroAiPublishResponded): void {
    if ($__elevaroAiPublishResponded) {
        return;
    }

    $error = error_get_last();
    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)$error['type'], $fatalTypes, true)) {
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Beim Veröffentlichen ist ein Serverfehler aufgetreten: ' . ($error['message'] ?? 'Unbekannter Fehler'),
        'file' => basename((string)($error['file'] ?? '')),
        'line' => (int)($error['line'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/includes/teacher_ai_wizard.php';

function elevaro_teacher_ai_publish_column_exists_safe(string $table, string $column): bool
{
    if (function_exists('elevaro_teacher_ai_wizard_column_exists')) {
        return elevaro_teacher_ai_wizard_column_exists($table, $column);
    }

    $stmt = elevaro_teacher_ai_wizard_db()->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column"
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}


function elevaro_teacher_ai_publish_link_unit_asset(int $teacherId, int $quizId, array $draft, array $payload): void
{
    $unitId = (int)($draft['teacher_unit_id'] ?? 0);
    if ($unitId <= 0) return;

    $pdo = elevaro_teacher_ai_wizard_db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_unit_assets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        unit_id INT UNSIGNED NOT NULL,
        teacher_id INT UNSIGNED NOT NULL,
        asset_type ENUM('quiz','listening_quiz','worksheet','listening_comprehension','reading_comprehension') NOT NULL,
        title VARCHAR(255) NOT NULL,
        quiz_id INT UNSIGNED NULL,
        custom_quiz_id INT UNSIGNED NULL,
        pdf_path VARCHAR(500) NULL,
        audio_path VARCHAR(500) NULL,
        transcript MEDIUMTEXT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'draft',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_teacher_unit_assets_unit (unit_id),
        KEY idx_teacher_unit_assets_teacher (teacher_id),
        KEY idx_teacher_unit_assets_type (asset_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $check = $pdo->prepare("SELECT id FROM teacher_units WHERE id = :unit_id AND teacher_id = :teacher_id LIMIT 1");
    $check->execute(['unit_id' => $unitId, 'teacher_id' => $teacherId]);
    if (!$check->fetchColumn()) return;

    $mode = (string)($draft['mode'] ?? 'quiz');
    $assetType = $mode === 'listening' ? 'listening_quiz' : 'quiz';
    $title = trim((string)($payload['title'] ?? $draft['source_title'] ?? 'KI-Quiz'));
    if ($title === '') $title = 'KI-Quiz';

    $existing = $pdo->prepare("SELECT id FROM teacher_unit_assets WHERE teacher_id = :teacher_id AND unit_id = :unit_id AND quiz_id = :quiz_id LIMIT 1");
    $existing->execute(['teacher_id' => $teacherId, 'unit_id' => $unitId, 'quiz_id' => $quizId]);
    if ($existing->fetchColumn()) return;

    $stmt = $pdo->prepare("INSERT INTO teacher_unit_assets (unit_id, teacher_id, asset_type, title, quiz_id, status)
        VALUES (:unit_id, :teacher_id, :asset_type, :title, :quiz_id, 'published')");
    $stmt->execute([
        'unit_id' => $unitId,
        'teacher_id' => $teacherId,
        'asset_type' => $assetType,
        'title' => $title,
        'quiz_id' => $quizId,
    ]);
}

function elevaro_teacher_ai_publish_finalize_quiz(int $quizId, array $draft, array $payload): void
{
    $pdo = elevaro_teacher_ai_wizard_db();

    $imagePath = trim((string)($draft['image_path'] ?? ''));
    $imagePrompt = trim((string)(($payload['image_prompt'] ?? '') ?: ($draft['image_prompt'] ?? '')));

    // Veröffentlichen darf nicht an der Bildgenerierung hängen.
    // Falls das Bild zu diesem Zeitpunkt schon existiert, wird es übernommen.
    // Falls nicht, bleibt das Quiz trotzdem spielbar und kann später ein Bild bekommen.
    $set = [
        "status = 'published'",
        "is_active = 1",
    ];
    $params = ['quiz_id' => $quizId];

    if (elevaro_teacher_ai_publish_column_exists_safe('quizzes', 'image_prompt')) {
        $set[] = "image_prompt = COALESCE(NULLIF(:image_prompt, ''), image_prompt)";
        $params['image_prompt'] = $imagePrompt;
    }

    if ($imagePath !== '' && elevaro_teacher_ai_publish_column_exists_safe('quizzes', 'image_path')) {
        $set[] = "image_path = :image_path";
        $params['image_path'] = $imagePath;

        if (elevaro_teacher_ai_publish_column_exists_safe('quizzes', 'image_source')) {
            $set[] = "image_source = 'ai'";
        }
        if (elevaro_teacher_ai_publish_column_exists_safe('quizzes', 'image_credit')) {
            $set[] = "image_credit = 'KI-generiert'";
        }
        if (elevaro_teacher_ai_publish_column_exists_safe('quizzes', 'image_status')) {
            $set[] = "image_status = 'approved'";
        }
    }

    $pdo->prepare("UPDATE quizzes SET " . implode(', ', $set) . " WHERE id = :quiz_id LIMIT 1")
        ->execute($params);

    $questionSet = ["status = 'published'"];
    if (elevaro_teacher_ai_publish_column_exists_safe('questions', 'moderator_status')) {
        $questionSet[] = "moderator_status = 'approved'";
    }

    $pdo->prepare("UPDATE questions SET " . implode(', ', $questionSet) . " WHERE quiz_id = :quiz_id")
        ->execute(['quiz_id' => $quizId]);

    // Sicherheitsnetz: Exakt eine richtige Antwort pro Frage markieren.
    $questionStmt = $pdo->prepare("SELECT id, correct_answer FROM questions WHERE quiz_id = :quiz_id");
    $questionStmt->execute(['quiz_id' => $quizId]);
    foreach ($questionStmt->fetchAll() as $question) {
        $questionId = (int)$question['id'];
        $answer = trim((string)$question['correct_answer']);

        $pdo->prepare("UPDATE question_options SET is_correct = 0 WHERE question_id = :question_id")
            ->execute(['question_id' => $questionId]);

        $mark = $pdo->prepare("UPDATE question_options
            SET is_correct = 1
            WHERE question_id = :question_id AND TRIM(option_text) = :answer
            LIMIT 1");
        $mark->execute(['question_id' => $questionId, 'answer' => $answer]);

        if ($mark->rowCount() < 1) {
            $pdo->prepare("UPDATE question_options
                SET is_correct = 1
                WHERE question_id = :question_id
                ORDER BY sort_order ASC, id ASC
                LIMIT 1")->execute(['question_id' => $questionId]);
        }
    }
}

try {
    $teacherId = teacher_current_user_id();
    $rawInput = file_get_contents('php://input') ?: '{}';
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        throw new RuntimeException('Ungültige Veröffentlichungsdaten.');
    }

    $draftId = (int)($input['draft_id'] ?? 0);
    if ($draftId <= 0) {
        throw new RuntimeException('Entwurf fehlt.');
    }

    if (!empty($input['payload']) && is_array($input['payload'])) {
        elevaro_teacher_ai_save_payload($draftId, $teacherId, (array)$input['payload']);
    }

    $quizId = elevaro_teacher_ai_publish_draft($draftId, $teacherId);
    $draft = elevaro_teacher_ai_load_draft($draftId, $teacherId);
    $payload = elevaro_teacher_ai_draft_payload($draft);

    elevaro_teacher_ai_publish_finalize_quiz($quizId, $draft, $payload);
    elevaro_teacher_ai_publish_link_unit_asset($teacherId, $quizId, $draft, $payload);

    $__elevaroAiPublishResponded = true;
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    elevaro_teacher_ai_json_response([
        'ok' => true,
        'quiz_id' => $quizId,
        'edit_url' => '/admin/quiz_questions.php?quiz_id=' . $quizId,
        'class_quizzes_url' => '/teacher/quizzes.php?class_id=' . (int)($draft['class_id'] ?? 0),
        'classroom_url' => '/classroom.php?class_id=' . (int)($draft['class_id'] ?? 0),
        'image_pending' => empty($draft['image_path']),
    ]);
} catch (Throwable $e) {
    $__elevaroAiPublishResponded = true;
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    error_log('[Elevaro AI Wizard Publish] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    elevaro_teacher_ai_json_response([
        'ok' => false,
        'error' => 'Veröffentlichen fehlgeschlagen: ' . $e->getMessage(),
    ], 500);
}
