<?php
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../app/includes/teacher_ai_wizard.php';

function elevaro_teacher_ai_publish_finalize_quiz(int $quizId, array $draft, array $payload): void
{
    $pdo = elevaro_teacher_ai_wizard_db();

    $imagePath = trim((string)($draft['image_path'] ?? ''));
    $imagePrompt = trim((string)(($payload['image_prompt'] ?? '') ?: ($draft['image_prompt'] ?? '')));

    // Falls das automatisch gestartete Bild noch nicht fertig war, erzeugen wir es beim Veröffentlichen nach.
    // Scheitert die Bildgenerierung, wird das Quiz trotzdem veröffentlicht und nutzt den Emoji-Fallback.
    if ($imagePath === '' && $imagePrompt !== '' && function_exists('elevaro_generate_and_store_image')) {
        try {
            $generated = elevaro_generate_and_store_image($imagePrompt, 'teacher-ai-quiz-cards', $quizId);
            $imagePath = (string)($generated['path'] ?? '');
            if ($imagePath !== '') {
                $pdo->prepare("UPDATE teacher_ai_quiz_drafts
                    SET image_path = :path, image_prompt = :prompt, image_status = 'approved'
                    WHERE id = :id AND teacher_id = :teacher_id")
                    ->execute([
                        'path' => $imagePath,
                        'prompt' => $imagePrompt,
                        'id' => (int)$draft['id'],
                        'teacher_id' => (int)$draft['teacher_id'],
                    ]);
            }
        } catch (Throwable $e) {
            // Nicht blockierend: Der Lehrer kann das Bild später neu erzeugen/ersetzen.
        }
    }

    // Wichtig: Der normale Quiz-Player lädt nur veröffentlichte Quizze und veröffentlichte Fragen.
    // Der Wizard darf daher keine draft-Objekte in die Klassenfreigabe hängen.
    $quizUpdateSql = "UPDATE quizzes
        SET status = 'published',
            is_active = 1,
            image_prompt = COALESCE(NULLIF(:image_prompt, ''), image_prompt),
            image_path = CASE WHEN :image_path_a <> '' THEN :image_path_b ELSE image_path END,
            image_source = CASE WHEN :image_path_c <> '' THEN 'ai' ELSE image_source END,
            image_credit = CASE WHEN :image_path_d <> '' THEN 'KI-generiert' ELSE image_credit END,
            image_status = CASE WHEN :image_path_e <> '' THEN 'approved' ELSE image_status END
        WHERE id = :quiz_id
        LIMIT 1";
    $pdo->prepare($quizUpdateSql)->execute([
        'quiz_id' => $quizId,
        'image_prompt' => $imagePrompt,
        'image_path_a' => $imagePath,
        'image_path_b' => $imagePath,
        'image_path_c' => $imagePath,
        'image_path_d' => $imagePath,
        'image_path_e' => $imagePath,
    ]);

    $pdo->prepare("UPDATE questions
        SET status = 'published', moderator_status = 'approved'
        WHERE quiz_id = :quiz_id")
        ->execute(['quiz_id' => $quizId]);

    // Sicherheitsnetz: Exakt eine richtige Antwort pro Frage markieren.
    $questionStmt = $pdo->prepare("SELECT id, correct_answer FROM questions WHERE quiz_id = :quiz_id");
    $questionStmt->execute(['quiz_id' => $quizId]);
    foreach ($questionStmt->fetchAll() as $question) {
        $questionId = (int)$question['id'];
        $answer = trim((string)$question['correct_answer']);
        $pdo->prepare("UPDATE question_options SET is_correct = 0 WHERE question_id = :question_id")
            ->execute(['question_id' => $questionId]);
        $pdo->prepare("UPDATE question_options
            SET is_correct = 1
            WHERE question_id = :question_id AND TRIM(option_text) = :answer
            LIMIT 1")
            ->execute(['question_id' => $questionId, 'answer' => $answer]);
    }
}

try {
    $teacherId = teacher_current_user_id();
    $input = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $draftId = (int)($input['draft_id'] ?? 0);
    if (!$draftId) {
        throw new RuntimeException('Entwurf fehlt.');
    }

    if (!empty($input['payload']) && is_array($input['payload'])) {
        elevaro_teacher_ai_save_payload($draftId, $teacherId, (array)$input['payload']);
    }

    $quizId = elevaro_teacher_ai_publish_draft($draftId, $teacherId);
    $draft = elevaro_teacher_ai_load_draft($draftId, $teacherId);
    $payload = elevaro_teacher_ai_draft_payload($draft);
    elevaro_teacher_ai_publish_finalize_quiz($quizId, $draft, $payload);

    elevaro_teacher_ai_json_response([
        'ok' => true,
        'quiz_id' => $quizId,
        'edit_url' => '/admin/quiz_questions.php?quiz_id=' . $quizId,
        'class_quizzes_url' => '/teacher/quizzes.php?class_id=' . (int)($draft['class_id'] ?? 0),
        'classroom_url' => '/classroom.php?class_id=' . (int)($draft['class_id'] ?? 0),
    ]);
} catch (Throwable $e) {
    elevaro_teacher_ai_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
}
