<?php
/**
 * Import JSON quiz files into DB.
 *
 * Usage:
 *   php scripts/import_json_quizzes_to_db.php
 *
 * It scans:
 *   data/quizzes/**.json
 *
 * Expected quiz.json format:
 * {
 *   "id": "quiz_key",
 *   "title": "...",
 *   "description": "...",
 *   "questions_file": "questions.json"
 * }
 */

require_once __DIR__ . '/../app/includes/db.php';

$root = dirname(__DIR__);
$dataRoot = $root . '/data/quizzes';

if (!is_dir($dataRoot)) {
    throw new RuntimeException("Missing data directory: {$dataRoot}");
}

$pdo = elevaro_db();

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dataRoot, FilesystemIterator::SKIP_DOTS)
);

$imported = 0;

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile()) {
        continue;
    }

    $filename = $file->getFilename();

    if (!in_array($filename, ['quiz.json', 'bruchrechnen.json', 'this-that-these-those.json'], true)) {
        continue;
    }

    $quizConfigPath = $file->getPathname();
    $config = json_decode(file_get_contents($quizConfigPath), true);

    if (!is_array($config) || empty($config['id']) || empty($config['questions_file'])) {
        continue;
    }

    $questionsPath = $file->getPath() . '/' . $config['questions_file'];

    if (!file_exists($questionsPath)) {
        echo "Skipping {$quizConfigPath}; missing questions file.\n";
        continue;
    }

    $questions = json_decode(file_get_contents($questionsPath), true);

    if (!is_array($questions)) {
        echo "Skipping {$quizConfigPath}; invalid questions JSON.\n";
        continue;
    }

    $quizKey = (string)$config['id'];

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO quizzes
                (quiz_key, title, description, questions_path, is_active, status, source_type)
            VALUES
                (:quiz_key, :title, :description, :questions_path, 1, 'published', 'json_import')
            ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                description = VALUES(description),
                questions_path = VALUES(questions_path),
                is_active = 1,
                status = 'published'
        ");

        $relativeQuestionsPath = str_replace($root . '/', '', $questionsPath);

        $stmt->execute([
            'quiz_key' => $quizKey,
            'title' => $config['title'] ?? $quizKey,
            'description' => $config['description'] ?? '',
            'questions_path' => $relativeQuestionsPath,
        ]);

        $quizId = (int)$pdo->lastInsertId();

        if (!$quizId) {
            $stmt = $pdo->prepare("SELECT id FROM quizzes WHERE quiz_key = :quiz_key LIMIT 1");
            $stmt->execute(['quiz_key' => $quizKey]);
            $quizId = (int)$stmt->fetchColumn();
        }

        // Re-import cleanly.
        $stmt = $pdo->prepare("DELETE FROM questions WHERE quiz_id = :quiz_id");
        $stmt->execute(['quiz_id' => $quizId]);

        foreach ($questions as $index => $question) {
            $questionText = $question['question'] ?? '';
            $answer = $question['answer'] ?? '';
            $options = $question['options'] ?? [];

            if (!$questionText || !$answer || !is_array($options)) {
                continue;
            }

            $questionKey = $question['id'] ?? slugify($questionText);

            $stmt = $pdo->prepare("
                INSERT INTO questions
                    (quiz_id, question_key, type, question_text, correct_answer, explanation, sort_order, status, ai_generated)
                VALUES
                    (:quiz_id, :question_key, :type, :question_text, :correct_answer, :explanation, :sort_order, 'published', 0)
            ");

            $stmt->execute([
                'quiz_id' => $quizId,
                'question_key' => $questionKey,
                'type' => $question['type'] ?? 'mc',
                'question_text' => $questionText,
                'correct_answer' => $answer,
                'explanation' => $question['fact'] ?? ($question['explanation'] ?? null),
                'sort_order' => $index + 1,
            ]);

            $questionId = (int)$pdo->lastInsertId();

            foreach (array_values($options) as $optionIndex => $optionText) {
                $stmt = $pdo->prepare("
                    INSERT INTO question_options
                        (question_id, option_text, is_correct, sort_order)
                    VALUES
                        (:question_id, :option_text, :is_correct, :sort_order)
                ");

                $stmt->execute([
                    'question_id' => $questionId,
                    'option_text' => (string)$optionText,
                    'is_correct' => ((string)$optionText === (string)$answer) ? 1 : 0,
                    'sort_order' => $optionIndex + 1,
                ]);
            }

            $stmt = $pdo->prepare("
                INSERT IGNORE INTO question_stats (question_id, calculated_difficulty)
                VALUES (:question_id, 0.300)
            ");
            $stmt->execute(['question_id' => $questionId]);
        }

        $pdo->commit();
        $imported++;
        echo "Imported {$quizKey}\n";

    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "Failed {$quizKey}: {$e->getMessage()}\n";
    }
}

echo "Done. Imported {$imported} quiz file(s).\n";

function slugify(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
    $text = trim($text, '-');
    return mb_substr($text, 0, 160, 'UTF-8') ?: uniqid('q_', true);
}
