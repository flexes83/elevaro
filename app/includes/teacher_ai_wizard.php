<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/openai_client.php';
require_once __DIR__ . '/image_tools.php';
require_once __DIR__ . '/elevenlabs_client.php';

function elevaro_teacher_ai_wizard_db(): PDO
{
    return elevaro_db();
}

function elevaro_teacher_ai_wizard_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = elevaro_teacher_ai_wizard_db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_ai_quiz_drafts (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT UNSIGNED NOT NULL,
        class_id INT UNSIGNED NOT NULL,
        mode VARCHAR(40) NOT NULL DEFAULT 'quiz',
        status VARCHAR(40) NOT NULL DEFAULT 'draft',
        source_title VARCHAR(255) NULL,
        source_text MEDIUMTEXT NULL,
        source_files_json LONGTEXT NULL,
        prompt MEDIUMTEXT NULL,
        generated_payload_json LONGTEXT NULL,
        image_prompt TEXT NULL,
        image_path VARCHAR(255) NULL,
        image_status VARCHAR(40) NOT NULL DEFAULT 'none',
        published_quiz_id INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_teacher_ai_drafts_teacher (teacher_id),
        KEY idx_teacher_ai_drafts_class (class_id),
        KEY idx_teacher_ai_drafts_published (published_quiz_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function elevaro_teacher_ai_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function elevaro_teacher_ai_slug(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?: 'quiz';
    $text = trim($text, '-');
    return $text !== '' ? mb_substr($text, 0, 120, 'UTF-8') : 'quiz';
}

function elevaro_teacher_ai_current_teacher_id(): int
{
    if (function_exists('teacher_current_user_id')) {
        return teacher_current_user_id();
    }
    if (function_exists('auth_user')) {
        return (int)(auth_user()['id'] ?? 0);
    }
    return 0;
}

function elevaro_teacher_ai_class_for_teacher(int $classId, int $teacherId): array
{
    $stmt = elevaro_teacher_ai_wizard_db()->prepare("SELECT * FROM teacher_classes WHERE id = :id AND teacher_id = :teacher_id LIMIT 1");
    $stmt->execute(['id' => $classId, 'teacher_id' => $teacherId]);
    $class = $stmt->fetch();
    if (!$class) {
        throw new RuntimeException('Klasse wurde nicht gefunden oder gehört nicht zu deinem Account.');
    }
    return $class;
}

function elevaro_teacher_ai_subject_label(?string $subjectCode): string
{
    $code = trim((string)$subjectCode);
    if ($code === '') return 'nicht festgelegt';
    $labels = [
        'deutsch' => 'Deutsch',
        'mathematik' => 'Mathematik',
        'englisch' => 'Englisch',
        'franzoesisch' => 'Französisch',
        'biologie' => 'Biologie',
        'geschichte' => 'Geschichte',
        'geographie' => 'Geographie',
        'sachunterricht' => 'Sachunterricht',
    ];
    return $labels[$code] ?? ucfirst(str_replace(['_', '-'], ' ', $code));
}

function elevaro_teacher_ai_language_for_class(array $class, string $mode): string
{
    $subject = mb_strtolower((string)($class['subject_code'] ?? ''), 'UTF-8');
    if ($mode === 'listening') {
        if (str_contains($subject, 'engl')) return 'Englisch';
        if (str_contains($subject, 'franz')) return 'Französisch';
        if (str_contains($subject, 'span')) return 'Spanisch';
    }
    return 'Deutsch';
}

function elevaro_teacher_ai_upload_dir(): string
{
    $dir = dirname(__DIR__, 2) . '/uploads/teacher_ai/' . date('Ym');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Upload-Verzeichnis konnte nicht erstellt werden.');
    }
    return $dir;
}

function elevaro_teacher_ai_upload_public_prefix(): string
{
    return '/uploads/teacher_ai/' . date('Ym');
}

function elevaro_teacher_ai_collect_files(string $field = 'source_files'): array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return [];
    $files = $_FILES[$field];
    $names = $files['name'] ?? [];
    if (!is_array($names)) return [];

    $stored = [];
    $allowed = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    foreach ($names as $i => $name) {
        $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Eine Datei konnte nicht hochgeladen werden.');
        $tmp = (string)($files['tmp_name'][$i] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Ungültiger Upload.');
        $size = (int)($files['size'][$i] ?? 0);
        if ($size <= 0 || $size > 18 * 1024 * 1024) throw new RuntimeException('Eine Datei ist zu groß. Maximum: 18 MB.');

        $mime = mime_content_type($tmp) ?: '';
        if (!isset($allowed[$mime])) throw new RuntimeException('Erlaubt sind PDF, JPG, PNG oder WebP.');
        $ext = $allowed[$mime];
        $safeName = preg_replace('/[^a-z0-9_.-]+/i', '-', pathinfo((string)$name, PATHINFO_FILENAME)) ?: 'material';
        $filename = $safeName . '-' . date('His') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
        $absolute = elevaro_teacher_ai_upload_dir() . '/' . $filename;
        if (!move_uploaded_file($tmp, $absolute)) throw new RuntimeException('Datei konnte nicht gespeichert werden.');

        $text = '';
        if ($mime === 'application/pdf') {
            $text = elevaro_teacher_ai_extract_pdf_text($absolute);
        }

        $stored[] = [
            'original_name' => (string)$name,
            'path' => elevaro_teacher_ai_upload_public_prefix() . '/' . $filename,
            'absolute_path' => $absolute,
            'mime' => $mime,
            'size' => $size,
            'extracted_text' => $text,
        ];
    }
    return $stored;
}

function elevaro_teacher_ai_extract_pdf_text(string $absolutePath): string
{
    $bin = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
    if ($bin === '') return '';
    $tmp = tempnam(sys_get_temp_dir(), 'elevaro_pdf_');
    if (!$tmp) return '';
    @unlink($tmp);
    $out = $tmp . '.txt';
    $cmd = escapeshellcmd($bin) . ' -layout -enc UTF-8 ' . escapeshellarg($absolutePath) . ' ' . escapeshellarg($out) . ' 2>/dev/null';
    @shell_exec($cmd);
    $text = is_file($out) ? (string)file_get_contents($out) : '';
    @unlink($out);
    $text = preg_replace('/\s+/u', ' ', $text) ?: '';
    return trim(mb_substr($text, 0, 18000, 'UTF-8'));
}

function elevaro_teacher_ai_file_to_data_url(array $file): ?string
{
    $mime = (string)($file['mime'] ?? '');
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
    $absolute = (string)($file['absolute_path'] ?? '');
    if ($absolute === '' || !is_file($absolute)) return null;
    $binary = file_get_contents($absolute);
    if ($binary === false) return null;
    return 'data:' . $mime . ';base64,' . base64_encode($binary);
}

function elevaro_teacher_ai_build_generation_messages(array $class, string $mode, string $sourceText, string $extraPrompt, array $files): array
{
    $subject = elevaro_teacher_ai_subject_label($class['subject_code'] ?? '');
    $grade = (string)($class['grade'] ?? '');
    $level = (string)($class['level_key'] ?? '');
    $school = (string)($class['school_type_code'] ?? '');
    $language = elevaro_teacher_ai_language_for_class($class, $mode);

    $pdfParts = [];
    foreach ($files as $file) {
        if (!empty($file['extracted_text'])) {
            $pdfParts[] = "Datei: " . ($file['original_name'] ?? 'PDF') . "\n" . $file['extracted_text'];
        }
    }

    $userText = trim("Erstelle ein Schülerquiz für den Elevaro-Klassenraum.

Klassenkontext:
- Klasse: {$grade}
- Schulart/Level: {$school} / {$level}
- Fach: {$subject}
- Modus: " . ($mode === 'listening' ? 'Listening + Comprehension' : 'normales Multiple-Choice-Quiz') . "
- Sprache der Fragen: {$language}

Material des Lehrers:
" . ($sourceText !== '' ? $sourceText : '[Kein zusätzlicher Text eingetragen.]') . "

Aus PDF-Dateien extrahierter Text:
" . ($pdfParts ? implode("\n\n---\n\n", $pdfParts) : '[Kein PDF-Text verfügbar oder pdftotext nicht installiert.]') . "

Zusatzwunsch des Lehrers:
" . ($extraPrompt !== '' ? $extraPrompt : '[Keine Zusatzanweisung.]') . "

Aufgabe:
- Erstelle ein fertiges Quiz mit 30 Fragen, davon eher leichte Fragen am Anfang und schwerere später.
- Jede Frage hat exakt 4 Antwortoptionen.
- Genau eine Antwort ist richtig.
- Erkläre knapp, warum die Antwort richtig ist.
- Verwende ausschließlich Informationen, die aus Material/Klassenkontext sinnvoll ableitbar sind.
- Bei unklaren oder fehlenden Fakten: keine erfundenen Details.
- Formuliere altersgerecht.
- Bei Fremdsprachen/Listening: Fragen und Antworten in der Zielsprache.
- Bei Listening zusätzlich einen Sprechertext in der Zielsprache erstellen. Er soll lang genug sein, dass daraus 8–15 Verständnisfragen beantwortet werden können, aber nicht künstlich lang.
- Erstelle außerdem eine kurze Quizbeschreibung und einen konkreten Bildprompt für ein freundliches, modernes Lernkarten-Bild.
");

    $content = [['type' => 'text', 'text' => $userText]];
    foreach ($files as $file) {
        $dataUrl = elevaro_teacher_ai_file_to_data_url($file);
        if ($dataUrl) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]];
        }
    }

    return [
        ['role' => 'system', 'content' => 'Du bist ein erfahrener Lehrer, Fachdidaktiker und Quizautor. Du lieferst ausschließlich valides JSON nach Schema. Keine Halluzinationen, keine erfundenen aktuellen Fakten.'],
        ['role' => 'user', 'content' => $content],
    ];
}

function elevaro_teacher_ai_generation_schema(): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'mode' => ['type' => 'string', 'enum' => ['quiz', 'listening']],
            'language' => ['type' => 'string'],
            'listening_text' => ['type' => 'string'],
            'image_prompt' => ['type' => 'string'],
            'questions' => [
                'type' => 'array',
                'minItems' => 8,
                'maxItems' => 40,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'question' => ['type' => 'string'],
                        'options' => [
                            'type' => 'array',
                            'minItems' => 4,
                            'maxItems' => 4,
                            'items' => ['type' => 'string'],
                        ],
                        'answer' => ['type' => 'string'],
                        'explanation' => ['type' => 'string'],
                        'difficulty' => ['type' => 'number'],
                    ],
                    'required' => ['question', 'options', 'answer', 'explanation', 'difficulty'],
                ],
            ],
        ],
        'required' => ['title', 'description', 'mode', 'language', 'listening_text', 'image_prompt', 'questions'],
    ];
}

function elevaro_teacher_ai_normalize_payload(array $payload, string $mode): array
{
    $payload['title'] = trim((string)($payload['title'] ?? 'KI-Quiz')) ?: 'KI-Quiz';
    $payload['description'] = trim((string)($payload['description'] ?? ''));
    $payload['mode'] = $mode === 'listening' ? 'listening' : 'quiz';
    $payload['language'] = trim((string)($payload['language'] ?? 'Deutsch')) ?: 'Deutsch';
    $payload['listening_text'] = $mode === 'listening' ? trim((string)($payload['listening_text'] ?? '')) : '';
    $payload['image_prompt'] = trim((string)($payload['image_prompt'] ?? 'Modernes Lernquiz Bild'));

    $questions = [];
    foreach (($payload['questions'] ?? []) as $q) {
        $question = trim((string)($q['question'] ?? ''));
        $options = array_values(array_filter(array_map(static fn($o) => trim((string)$o), (array)($q['options'] ?? [])), static fn($o) => $o !== ''));
        $answer = trim((string)($q['answer'] ?? ''));
        if ($question === '' || $answer === '') continue;
        if (!in_array($answer, $options, true)) array_unshift($options, $answer);
        $options = array_values(array_unique($options));
        while (count($options) < 4) $options[] = 'Antwort ' . (count($options) + 1);
        $options = array_slice($options, 0, 4);
        if (!in_array($answer, $options, true)) $options[0] = $answer;
        $questions[] = [
            'question' => $question,
            'options' => $options,
            'answer' => $answer,
            'explanation' => trim((string)($q['explanation'] ?? '')),
            'difficulty' => max(0.05, min(0.95, (float)($q['difficulty'] ?? 0.35))),
        ];
    }
    $payload['questions'] = $questions;
    return $payload;
}

function elevaro_teacher_ai_create_draft(int $teacherId, int $classId, string $mode, string $sourceText, string $extraPrompt, array $files, array $payload, string $promptLog = ''): int
{
    elevaro_teacher_ai_wizard_ensure_schema();
    $pdo = elevaro_teacher_ai_wizard_db();
    $stmt = $pdo->prepare("INSERT INTO teacher_ai_quiz_drafts
        (teacher_id, class_id, mode, status, source_title, source_text, source_files_json, prompt, generated_payload_json, image_prompt, image_status)
        VALUES (:teacher_id, :class_id, :mode, 'draft', :source_title, :source_text, :source_files_json, :prompt, :payload, :image_prompt, 'pending')");
    $stmt->execute([
        'teacher_id' => $teacherId,
        'class_id' => $classId,
        'mode' => $mode,
        'source_title' => $payload['title'] ?? null,
        'source_text' => $sourceText,
        'source_files_json' => json_encode(array_map(static function ($file) {
            unset($file['absolute_path']);
            return $file;
        }, $files), JSON_UNESCAPED_UNICODE),
        'prompt' => $promptLog,
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'image_prompt' => $payload['image_prompt'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

function elevaro_teacher_ai_load_draft(int $draftId, int $teacherId): array
{
    elevaro_teacher_ai_wizard_ensure_schema();
    $stmt = elevaro_teacher_ai_wizard_db()->prepare("SELECT * FROM teacher_ai_quiz_drafts WHERE id = :id AND teacher_id = :teacher_id LIMIT 1");
    $stmt->execute(['id' => $draftId, 'teacher_id' => $teacherId]);
    $draft = $stmt->fetch();
    if (!$draft) throw new RuntimeException('Entwurf wurde nicht gefunden.');
    return $draft;
}

function elevaro_teacher_ai_draft_payload(array $draft): array
{
    $payload = json_decode((string)($draft['generated_payload_json'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function elevaro_teacher_ai_save_payload(int $draftId, int $teacherId, array $payload): void
{
    $draft = elevaro_teacher_ai_load_draft($draftId, $teacherId);
    $mode = (string)($draft['mode'] ?? 'quiz');
    $payload = elevaro_teacher_ai_normalize_payload($payload, $mode);
    $stmt = elevaro_teacher_ai_wizard_db()->prepare("UPDATE teacher_ai_quiz_drafts SET generated_payload_json = :payload, source_title = :title, image_prompt = :image_prompt WHERE id = :id AND teacher_id = :teacher_id");
    $stmt->execute([
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'title' => $payload['title'] ?? null,
        'image_prompt' => $payload['image_prompt'] ?? null,
        'id' => $draftId,
        'teacher_id' => $teacherId,
    ]);
}

function elevaro_teacher_ai_publish_draft(int $draftId, int $teacherId): int
{
    $draft = elevaro_teacher_ai_load_draft($draftId, $teacherId);
    if (!empty($draft['published_quiz_id'])) return (int)$draft['published_quiz_id'];
    $class = elevaro_teacher_ai_class_for_teacher((int)$draft['class_id'], $teacherId);
    $payload = elevaro_teacher_ai_normalize_payload(elevaro_teacher_ai_draft_payload($draft), (string)$draft['mode']);
    if (count($payload['questions']) < 1) throw new RuntimeException('Der Entwurf enthält keine Fragen.');

    $pdo = elevaro_teacher_ai_wizard_db();
    $pdo->beginTransaction();
    try {
        $subjectId = null;
        if (!empty($class['subject_code'])) {
            $sub = $pdo->prepare("SELECT id FROM subjects WHERE code = :code LIMIT 1");
            $sub->execute(['code' => $class['subject_code']]);
            $subjectId = $sub->fetchColumn() ?: null;
        }

        $quizKeyBase = elevaro_teacher_ai_slug($payload['title']);
        $quizKey = $quizKeyBase . '-' . substr(sha1($draftId . '-' . microtime(true)), 0, 6);
        $isListening = ((string)$draft['mode'] === 'listening');

        $stmt = $pdo->prepare("INSERT INTO quizzes
            (quiz_key, title, description, image_path, image_source, image_credit, image_prompt, image_status,
             listening_mode, listening_text, listening_status, ai_context_source_text, ai_context_notes,
             theme_color_1, theme_color_2, theme_emoji, subject_id, grade, difficulty_base, questions_path,
             is_active, status, source_type, ai_generated, created_by_user_id)
            VALUES
            (:quiz_key, :title, :description, :image_path, :image_source, :image_credit, :image_prompt, :image_status,
             :listening_mode, :listening_text, :listening_status, :source_text, :context_notes,
             :theme_color_1, :theme_color_2, :theme_emoji, :subject_id, :grade, 0.300, '',
             1, 'draft', 'teacher', 1, :created_by_user_id)");
        $stmt->execute([
            'quiz_key' => $quizKey,
            'title' => $payload['title'],
            'description' => $payload['description'],
            'image_path' => $draft['image_path'] ?: null,
            'image_source' => $draft['image_path'] ? 'ai' : null,
            'image_credit' => $draft['image_path'] ? 'KI-generiert' : null,
            'image_prompt' => $payload['image_prompt'] ?? null,
            'image_status' => $draft['image_path'] ? 'draft' : 'none',
            'listening_mode' => $isListening ? 1 : 0,
            'listening_text' => $isListening ? ($payload['listening_text'] ?? '') : null,
            'listening_status' => $isListening ? 'text_generated' : 'none',
            'source_text' => $draft['source_text'] ?: null,
            'context_notes' => 'Erstellt über Lehrer-KI-Wizard aus eigenem Unterrichtsmaterial.',
            'theme_color_1' => '#5a4ff3',
            'theme_color_2' => '#8b7cff',
            'theme_emoji' => $isListening ? '🎧' : '✨',
            'subject_id' => $subjectId ?: null,
            'grade' => $class['grade'] ?: null,
            'created_by_user_id' => $teacherId,
        ]);
        $quizId = (int)$pdo->lastInsertId();

        $sort = 0;
        foreach ($payload['questions'] as $q) {
            $sort += 10;
            $stmt = $pdo->prepare("INSERT INTO questions
                (quiz_id, question_key, type, question_text, correct_answer, explanation, difficulty_manual,
                 difficulty_calculated, status, ai_generated, source_context, sort_order)
                VALUES
                (:quiz_id, :question_key, 'mc', :question_text, :correct_answer, :explanation, :difficulty_manual,
                 :difficulty_calculated, 'draft', 1, :source_context, :sort_order)");
            $stmt->execute([
                'quiz_id' => $quizId,
                'question_key' => elevaro_teacher_ai_slug($q['question']) . '-' . substr(sha1($quizId . '-' . $sort), 0, 6),
                'question_text' => $q['question'],
                'correct_answer' => $q['answer'],
                'explanation' => $q['explanation'],
                'difficulty_manual' => $q['difficulty'],
                'difficulty_calculated' => $q['difficulty'],
                'source_context' => $isListening ? 'listening_text' : 'general',
                'sort_order' => $sort,
            ]);
            $questionId = (int)$pdo->lastInsertId();
            foreach ($q['options'] as $idx => $option) {
                $pdo->prepare("INSERT INTO question_options (question_id, option_text, is_correct, sort_order)
                    VALUES (:question_id, :option_text, :is_correct, :sort_order)")
                    ->execute([
                        'question_id' => $questionId,
                        'option_text' => $option,
                        'is_correct' => $option === $q['answer'] ? 1 : 0,
                        'sort_order' => $idx + 1,
                    ]);
            }
            $pdo->prepare("INSERT IGNORE INTO question_stats (question_id, calculated_difficulty) VALUES (:question_id, :difficulty)")
                ->execute(['question_id' => $questionId, 'difficulty' => $q['difficulty']]);
        }

        $pdo->prepare("INSERT IGNORE INTO teacher_class_quizzes (class_id, quiz_id, sort_order) VALUES (:class_id, :quiz_id, 100)")
            ->execute(['class_id' => (int)$draft['class_id'], 'quiz_id' => $quizId]);
        $pdo->prepare("UPDATE teacher_ai_quiz_drafts SET status = 'published', published_quiz_id = :quiz_id WHERE id = :id")
            ->execute(['quiz_id' => $quizId, 'id' => $draftId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if ($isListening && trim((string)($payload['listening_text'] ?? '')) !== '') {
        try {
            $audio = elevaro_generate_audio_file((string)$payload['listening_text'], 'listening_quiz_' . $quizId);
            $pdo->prepare("UPDATE quizzes SET listening_audio_path = :path, listening_voice_id = :voice_id, listening_model_id = :model_id, listening_status = 'audio_generated', listening_generated_at = NOW() WHERE id = :id")
                ->execute(['path' => $audio['path'], 'voice_id' => $audio['voice_id'], 'model_id' => $audio['model_id'], 'id' => $quizId]);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE quizzes SET listening_status = 'error', listening_error = :err WHERE id = :id")
                ->execute(['err' => $e->getMessage(), 'id' => $quizId]);
        }
    }

    return $quizId;
}

function elevaro_teacher_ai_json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
