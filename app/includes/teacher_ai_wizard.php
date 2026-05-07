<?php

declare(strict_types=1);


function elevaro_teacher_ai_debug_enabled(): bool
{
    if (defined('ELEVARO_AI_WIZARD_DEBUG') && ELEVARO_AI_WIZARD_DEBUG === true) {
        return true;
    }

    // Praktisch zum Testen, falls die zentrale Config an dieser Stelle nicht greift:
    // /teacher/ai_wizard.php?class_id=2&ai_debug=1
    if (isset($_GET['ai_debug']) && (string)$_GET['ai_debug'] === '1') {
        $_SESSION['elevaro_ai_wizard_debug'] = true;
        return true;
    }

    if (!empty($_SESSION['elevaro_ai_wizard_debug'])) {
        return true;
    }

    // Notfall-Schalter ohne Codeänderung:
    // Datei im Projektroot anlegen: storage/ai_wizard_debug.enabled
    $rootFlag = dirname(__DIR__, 3) . '/storage/ai_wizard_debug.enabled';
    $appFlag = dirname(__DIR__, 2) . '/storage/ai_wizard_debug.enabled';

    return is_file($rootFlag) || is_file($appFlag);
}

function elevaro_teacher_ai_debug_dir(): string
{
    $candidates = [
        dirname(__DIR__, 3) . '/storage/ai_wizard_debug', // Projektroot/storage
        dirname(__DIR__, 2) . '/storage/ai_wizard_debug', // app/storage
        sys_get_temp_dir() . '/elevaro_ai_wizard_debug',
    ];

    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }
    }

    return sys_get_temp_dir();
}

function elevaro_teacher_ai_debug_log(string $stage, array $context = []): void
{
    if (!elevaro_teacher_ai_debug_enabled()) {
        return;
    }

    $dir = elevaro_teacher_ai_debug_dir();
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $stage . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $target = rtrim($dir, '/') . '/debug.log';

    $written = @file_put_contents($target, $line, FILE_APPEND);
    if ($written === false) {
        error_log('[Elevaro AI Wizard Debug] could not write ' . $target . ' :: ' . trim($line));
    } else {
        error_log('[Elevaro AI Wizard Debug] wrote ' . $target . ' :: ' . $stage);
    }
}

function elevaro_teacher_ai_debug_dump_response(string $stage, string $content, array $meta = []): string
{
    if (!elevaro_teacher_ai_debug_enabled()) {
        // JSON-Fehler sollen trotzdem im Serverlog sichtbar sein, auch wenn Debug nicht aktiv ist.
        error_log('[Elevaro AI Wizard] JSON debug disabled. Stage=' . $stage . ', length=' . strlen($content) . ', preview=' . mb_substr(preg_replace('/\s+/', ' ', $content), 0, 500));
        return '';
    }

    $dir = elevaro_teacher_ai_debug_dir();
    $safeStage = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $stage);
    $file = $safeStage . '_' . date('Ymd_His') . '_' . substr(sha1($content . microtime(true)), 0, 8) . '.txt';

    $header = "STAGE: {$stage}\n";
    $header .= "TIME: " . date('c') . "\n";
    $header .= "DIR: {$dir}\n";
    $header .= "LENGTH: " . strlen($content) . " bytes\n";
    $header .= "META: " . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $header .= str_repeat('-', 80) . "\n\n";

    $target = rtrim($dir, '/') . '/' . $file;
    $written = @file_put_contents($target, $header . $content);

    if ($written === false) {
        error_log('[Elevaro AI Wizard Debug] could not write response dump: ' . $target);
        error_log('[Elevaro AI Wizard Debug] response preview: ' . mb_substr(preg_replace('/\s+/', ' ', $content), 0, 1200));
        return '';
    }

    error_log('[Elevaro AI Wizard Debug] wrote response dump: ' . $target);
    return $target;
}


require_once __DIR__ . '/db.php';
require_once __DIR__ . '/openai_client.php';
require_once __DIR__ . '/image_tools.php';
require_once __DIR__ . '/elevenlabs_client.php';

function elevaro_teacher_ai_wizard_db(): PDO
{
    return elevaro_db();
}

function elevaro_teacher_ai_wizard_column_exists(string $table, string $column): bool
{
    try {
        $stmt = elevaro_teacher_ai_wizard_db()->prepare("SHOW COLUMNS FROM `{$table}` LIKE :column");
        $stmt->execute(['column' => $column]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function elevaro_teacher_ai_wizard_add_column_if_missing(string $table, string $column, string $definition): void
{
    if (elevaro_teacher_ai_wizard_column_exists($table, $column)) {
        return;
    }

    try {
        elevaro_teacher_ai_wizard_db()->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
    } catch (PDOException $e) {
        // MySQL 1060 = duplicate column. This can still happen if an older migration already
        // created the column or if two requests run the schema check at the same time.
        if (($e->errorInfo[1] ?? null) === 1060) {
            return;
        }

        throw $e;
    }
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

    // Bestehende Installationen können die Tabelle bereits aus einer früheren Migration haben.
    // Deshalb ergänzen wir fehlende Spalten defensiv, statt bei INSERTs später mit 400/500 zu scheitern.
    elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'mode', "mode VARCHAR(40) NOT NULL DEFAULT 'quiz' AFTER class_id");
    elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'status', "status VARCHAR(40) NOT NULL DEFAULT 'draft' AFTER mode");
    elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'source_title', "source_title VARCHAR(255) NULL AFTER status");
    elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'source_text', "source_text MEDIUMTEXT NULL AFTER source_title");
    elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'source_files_json', "source_files_json LONGTEXT NULL AFTER source_text");
    elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'prompt', "prompt MEDIUMTEXT NULL AFTER source_files_json");
    elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'generated_payload_json', "generated_payload_json LONGTEXT NULL AFTER prompt");
    elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'image_prompt', "image_prompt TEXT NULL AFTER generated_payload_json");
    elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'image_path', "image_path VARCHAR(255) NULL AFTER image_prompt");
    elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'image_status', "image_status VARCHAR(40) NOT NULL DEFAULT 'none' AFTER image_path");
    elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'published_quiz_id', "published_quiz_id INT UNSIGNED NULL AFTER image_status");
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
        $pdfPreviewImages = [];
        if ($mime === 'application/pdf') {
            $text = elevaro_teacher_ai_extract_pdf_text($absolute);
            // Wichtig für eingescannte PDFs / abfotografierte Buchseiten als PDF:
            // Wenn kein Text extrahierbar ist, senden wir die ersten Seiten als Bilder an das Vision-Modell.
            if (mb_strlen(trim($text), 'UTF-8') < 80) {
                $pdfPreviewImages = elevaro_teacher_ai_extract_pdf_preview_images($absolute, 4);
            }
        }

        $stored[] = [
            'original_name' => (string)$name,
            'path' => elevaro_teacher_ai_upload_public_prefix() . '/' . $filename,
            'absolute_path' => $absolute,
            'mime' => $mime,
            'size' => $size,
            'extracted_text' => $text,
            'pdf_preview_images' => $pdfPreviewImages,
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

function elevaro_teacher_ai_extract_pdf_preview_images(string $absolutePath, int $maxPages = 4): array
{
    $maxPages = max(1, min(6, $maxPages));
    $dir = elevaro_teacher_ai_upload_dir() . '/pdf_pages';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return [];

    $prefix = $dir . '/page-' . date('His') . '-' . bin2hex(random_bytes(3));
    $created = [];

    $pdftoppm = trim((string)@shell_exec('command -v pdftoppm 2>/dev/null'));
    if ($pdftoppm !== '') {
        $cmd = escapeshellcmd($pdftoppm) . ' -f 1 -l ' . $maxPages . ' -jpeg -r 150 ' . escapeshellarg($absolutePath) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null';
        @shell_exec($cmd);
        $created = glob($prefix . '-*.jpg') ?: [];
    }

    // Fallback für Server mit ImageMagick statt Poppler. Funktioniert nur, wenn PDF-Rendering erlaubt ist.
    if (!$created) {
        $magick = trim((string)@shell_exec('command -v magick 2>/dev/null')) ?: trim((string)@shell_exec('command -v convert 2>/dev/null'));
        if ($magick !== '') {
            $out = $prefix . '-%02d.jpg';
            $cmd = escapeshellcmd($magick) . ' ' . escapeshellarg($absolutePath . '[0-' . ($maxPages - 1) . ']') . ' -density 150 -quality 82 ' . escapeshellarg($out) . ' 2>/dev/null';
            @shell_exec($cmd);
            $created = glob($prefix . '-*.jpg') ?: [];
        }
    }

    sort($created);
    return array_slice(array_values(array_filter($created, static fn($file) => is_file($file) && filesize($file) > 0)), 0, $maxPages);
}

function elevaro_teacher_ai_absolute_file_to_data_url(string $absolute, string $mime = 'image/jpeg'): ?string
{
    if ($absolute === '' || !is_file($absolute)) return null;
    $binary = file_get_contents($absolute);
    if ($binary === false) return null;
    return 'data:' . $mime . ';base64,' . base64_encode($binary);
}

function elevaro_teacher_ai_file_to_data_url(array $file): ?string
{
    $mime = (string)($file['mime'] ?? '');
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
    $absolute = (string)($file['absolute_path'] ?? '');
    return elevaro_teacher_ai_absolute_file_to_data_url($absolute, $mime);
}

function elevaro_teacher_ai_has_readable_material(string $sourceText, array $files): bool
{
    if (mb_strlen(trim($sourceText), 'UTF-8') >= 40) return true;
    foreach ($files as $file) {
        if ((string)($file['mime'] ?? '') === 'application/pdf') return true;
        if (mb_strlen(trim((string)($file['extracted_text'] ?? '')), 'UTF-8') >= 80) return true;
        if (in_array((string)($file['mime'] ?? ''), ['image/jpeg', 'image/png', 'image/webp'], true)) return true;
        if (!empty($file['pdf_preview_images'])) return true;
    }
    return false;
}

function elevaro_teacher_ai_responses_content_for_material(array $files): array
{
    $content = [];
    foreach ($files as $file) {
        $mime = (string)($file['mime'] ?? '');
        $name = (string)($file['original_name'] ?? 'Material');
        $absolute = (string)($file['absolute_path'] ?? '');

        if ($mime === 'application/pdf') {
            $fileId = (string)($file['openai_file_id'] ?? '');
            if ($fileId === '' && $absolute !== '' && is_file($absolute)) {
                $fileId = elevaro_openai_upload_file($absolute, 'application/pdf', 'user_data');
            }
            if ($fileId !== '') {
                $content[] = ['type' => 'input_text', 'text' => 'Direkt hochgeladenes PDF des Lehrers: ' . $name . '. Lies alle sichtbaren Inhalte, auch Scans, abfotografierte Seiten, Layout, Tabellen und Aufgaben, soweit erkennbar.'];
                $content[] = ['type' => 'input_file', 'file_id' => $fileId];
                continue;
            }
        }

        $dataUrl = elevaro_teacher_ai_file_to_data_url($file);
        if ($dataUrl) {
            $content[] = ['type' => 'input_text', 'text' => 'Bildmaterial des Lehrers: ' . $name . '. Lies Text, Aufgaben, Tabellen und sichtbare Markierungen, soweit erkennbar.'];
            $content[] = ['type' => 'input_image', 'image_url' => $dataUrl, 'detail' => 'high'];
        }
    }
    return $content;
}

function elevaro_teacher_ai_build_generation_prompt(array $class, string $mode, string $sourceText, string $extraPrompt, array $files): string
{
    $subject = elevaro_teacher_ai_subject_label($class['subject_code'] ?? '');
    $grade = (string)($class['grade'] ?? '');
    $level = (string)($class['level_key'] ?? '');
    $school = (string)($class['school_type_code'] ?? '');
    $language = elevaro_teacher_ai_language_for_class($class, $mode);

    $fileList = [];
    foreach ($files as $file) {
        $fileList[] = '- ' . (string)($file['original_name'] ?? 'Material') . ' (' . (string)($file['mime'] ?? 'Datei') . ')';
    }

    return trim("Erstelle ein Schülerquiz für den Elevaro-Klassenraum.

Klassenkontext:
- Klasse: {$grade}
- Schulart/Level: {$school} / {$level}
- Fach: {$subject}
- Modus: " . ($mode === 'listening' ? 'Listening + Comprehension' : 'normales Multiple-Choice-Quiz') . "
- Sprache der Fragen: {$language}

Vom Lehrer hochgeladene Dateien:
" . ($fileList ? implode("\n", $fileList) : '[Keine Datei hochgeladen – ggf. Lehrplanthema als Quelle.]') . "

Zusätzlicher Lehrertext / Aufgabenstellung:
" . ($sourceText !== '' ? $sourceText : '[Kein zusätzlicher Text eingetragen.]') . "

Zusatzwunsch des Lehrers:
" . ($extraPrompt !== '' ? $extraPrompt : '[Keine Zusatzanweisung.]') . "

Aufgabe:
- Analysiere zuerst das direkt bereitgestellte Material. PDFs und Bilder sind die Primärquelle.
- Berücksichtige bei PDFs auch visuelle Seiteninhalte, Scans, Fotos von Buchseiten, Tabellen und Aufgabenstellungen, soweit lesbar.
- Erstelle ein fertiges Quiz mit 30 Fragen, davon eher leichte Fragen am Anfang und schwerere später.
- Jede Frage hat exakt 4 Antwortoptionen.
- Genau eine Antwort ist richtig.
- Erkläre knapp, warum die Antwort richtig ist.
- Strikte Quellenbindung: Jede Frage muss eindeutig aus dem hochgeladenen Material oder dem ausdrücklich eingegebenen Lehrertext ableitbar sein.
- Der Klassenkontext dient nur zur Anpassung von Niveau und Sprache, nicht als Ersatzquelle für Fakten.
- Wenn zu wenig Material lesbar ist: Erstelle nur Fragen zu sicher lesbaren Inhalten und erwähne die Einschränkung in der Beschreibung. Erfinde keine Inhalte.
- Formuliere altersgerecht.
- Bei Fremdsprachen/Listening: Fragen und Antworten in der Zielsprache.
- Bei Listening zusätzlich einen Sprechertext in der Zielsprache erstellen. Dieser Sprechertext muss inhaltlich auf dem Material basieren.
- Erstelle außerdem eine kurze Quizbeschreibung und einen konkreten Bildprompt für ein freundliches, modernes Lernkarten-Bild.");
}

function elevaro_teacher_ai_generate_from_material(array $class, string $mode, string $sourceText, string $extraPrompt, array $files): array
{
    if (!elevaro_teacher_ai_has_readable_material($sourceText, $files)) {
        throw new RuntimeException('Bitte lade ein PDF/Bild hoch oder gib Material als Text ein. Ohne Unterrichtsmaterial wird kein Quiz generiert, damit keine Fantasiefragen entstehen.');
    }

    $prompt = elevaro_teacher_ai_build_generation_prompt($class, $mode, $sourceText, $extraPrompt, $files);
    $content = [['type' => 'input_text', 'text' => $prompt]];
    $content = array_merge($content, elevaro_teacher_ai_responses_content_for_material($files));

    $system = 'Du bist ein erfahrener Lehrer, Fachdidaktiker und Quizautor. Du lieferst ausschließlich valides JSON nach Schema. Du darfst keine Fragen erfinden, die nicht aus dem bereitgestellten Material ableitbar sind. PDFs und Bilder sind als Primärquelle auszuwerten; lies alle sichtbaren Inhalte sorgfältig, soweit erkennbar.';
    $result = elevaro_openai_responses_json($system, $content, elevaro_teacher_ai_generation_schema(), 0.22, 300);
    $result['prompt_log'] = $prompt;
    return $result;
}

function elevaro_teacher_ai_build_generation_messages(array $class, string $mode, string $sourceText, string $extraPrompt, array $files): array
{
    if (!elevaro_teacher_ai_has_readable_material($sourceText, $files)) {
        throw new RuntimeException('Das hochgeladene Material konnte nicht zuverlässig gelesen werden. Bitte lade ein PDF mit auswählbarem Text hoch, fotografiere die Seiten als JPG/PNG oder installiere Poppler/pdftotext bzw. pdftoppm auf dem Server. So verhindern wir, dass die KI Fragen ohne Bezug zum Material erfindet.');
    }

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
- Strikte Quellenbindung: Die Fragen müssen sich inhaltlich auf das hochgeladene Material oder den ausdrücklich eingegebenen Lehrertext beziehen.
- Der Klassenkontext dient nur zur Anpassung von Niveau und Sprache, nicht als Ersatzquelle für Fakten.
- Bei unklaren oder fehlenden Fakten: keine erfundenen Details und keine allgemeinen Platzhalterfragen.
- Formuliere altersgerecht.
- Bei Fremdsprachen/Listening: Fragen und Antworten in der Zielsprache.
- Bei Listening zusätzlich einen Sprechertext in der Zielsprache erstellen. Dieser Sprechertext muss inhaltlich auf dem Material basieren.
- Erstelle außerdem eine kurze Quizbeschreibung und einen konkreten Bildprompt für ein freundliches, modernes Lernkarten-Bild.
");

    $content = [['type' => 'text', 'text' => $userText]];
    foreach ($files as $file) {
        $dataUrl = elevaro_teacher_ai_file_to_data_url($file);
        if ($dataUrl) {
            $content[] = ['type' => 'text', 'text' => 'Bildmaterial des Lehrers: ' . (string)($file['original_name'] ?? 'Bild') . '. Lies den sichtbaren Inhalt und verwende ihn als Quelle.'];
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => 'high']];
        }
        foreach ((array)($file['pdf_preview_images'] ?? []) as $idx => $previewPath) {
            $previewUrl = elevaro_teacher_ai_absolute_file_to_data_url((string)$previewPath, 'image/jpeg');
            if ($previewUrl) {
                $content[] = ['type' => 'text', 'text' => 'PDF-Seite ' . ($idx + 1) . ' aus ' . (string)($file['original_name'] ?? 'PDF') . '. Lies den sichtbaren Inhalt und verwende ihn als Quelle.'];
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => $previewUrl, 'detail' => 'high']];
            }
        }
    }

    return [
        ['role' => 'system', 'content' => 'Du bist ein erfahrener Lehrer, Fachdidaktiker und Quizautor. Du lieferst ausschließlich valides JSON nach Schema. Du darfst keine Fragen erfinden, die nicht aus dem bereitgestellten Material ableitbar sind. Wenn Bildmaterial vorhanden ist, lies es sorgfältig wie ein Arbeitsblatt.'],
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


function elevaro_teacher_ai_extract_first_json_object(string $content): ?string
{
    $length = strlen($content);
    $start = strpos($content, '{');
    if ($start === false) {
        return null;
    }

    $depth = 0;
    $inString = false;
    $escape = false;

    for ($i = $start; $i < $length; $i++) {
        $char = $content[$i];

        if ($inString) {
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($char === '\\') {
                $escape = true;
                continue;
            }
            if ($char === '"') {
                $inString = false;
            }
            continue;
        }

        if ($char === '"') {
            $inString = true;
            continue;
        }

        if ($char === '{') {
            $depth++;
            continue;
        }

        if ($char === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($content, $start, $i - $start + 1);
            }
        }
    }

    return null;
}

function elevaro_teacher_ai_decode_json_candidate(string $candidate): ?array
{
    $decoded = json_decode($candidate, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    // Manche Responses enthalten ein JSON-Objekt als JSON-kodierten String.
    if (is_string($decoded)) {
        $decodedAgain = json_decode($decoded, true);
        if (is_array($decodedAgain)) {
            return $decodedAgain;
        }
    }

    return null;
}

function elevaro_teacher_ai_decode_openai_json(string $content, string $debugStage = 'unknown'): array
{
    $content = trim($content);
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $content);
    elevaro_teacher_ai_debug_log('decode_attempt', [
        'stage' => $debugStage,
        'length' => strlen($content),
        'starts_with' => mb_substr($content, 0, 160),
        'ends_with' => mb_substr($content, max(0, mb_strlen($content) - 160)),
    ]);

    if ($content === '') {
        elevaro_teacher_ai_debug_dump_response($debugStage . '_empty', $content);
        throw new RuntimeException('OpenAI hat keine auswertbare Antwort geliefert.');
    }

    $decoded = elevaro_teacher_ai_decode_json_candidate($content);
    if (is_array($decoded)) {
        return $decoded;
    }

    // Manche Modelle/Background-Responses liefern versehentlich mehrere vollständige
    // JSON-Objekte direkt hintereinander: { ... }{ ... }. In diesem Fall ist das erste
    // Objekt bereits vollständig und verwendbar. Wir extrahieren deshalb balanciert nur
    // das erste Objekt, statt von "abgeschnitten" auszugehen.
    $firstObject = elevaro_teacher_ai_extract_first_json_object($content);
    if ($firstObject !== null && $firstObject !== $content) {
        $decoded = elevaro_teacher_ai_decode_json_candidate($firstObject);
        if (is_array($decoded)) {
            elevaro_teacher_ai_debug_log('decode_first_json_object_used', [
                'stage' => $debugStage,
                'original_length' => strlen($content),
                'first_object_length' => strlen($firstObject),
                'remaining_length' => strlen($content) - strlen($firstObject),
            ]);
            return $decoded;
        }
    }

    // Some models still wrap JSON in Markdown fences or add a short leading note.
    if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $content, $match)) {
        $fenced = trim($match[1]);
        $decoded = elevaro_teacher_ai_decode_json_candidate($fenced);
        if (is_array($decoded)) {
            return $decoded;
        }
        $firstFencedObject = elevaro_teacher_ai_extract_first_json_object($fenced);
        if ($firstFencedObject !== null) {
            $decoded = elevaro_teacher_ai_decode_json_candidate($firstFencedObject);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    $preview = mb_substr(preg_replace('/\s+/', ' ', $content), 0, 700);
    $firstBalancedObject = elevaro_teacher_ai_extract_first_json_object($content);
    $looksTruncated = (strpos($content, '{') !== false && $firstBalancedObject === null);
    $dumpFile = elevaro_teacher_ai_debug_dump_response($debugStage . '_invalid_json', $content, [
        'looks_truncated' => $looksTruncated,
        'first_char' => mb_substr($content, 0, 1),
        'last_char' => mb_substr(rtrim($content), -1),
    ]);
    $hint = $looksTruncated
        ? ' Die Antwort wirkt abgeschnitten.'
        : '';
    $debugHint = $dumpFile ? ' Debug-Datei: ' . $dumpFile . '.' : '';
    throw new RuntimeException('OpenAI-Antwort war kein valides JSON.' . $hint . $debugHint . ' Antwortauszug: ' . $preview);
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
        $questionPayload = [
            'question' => $question,
            'options' => $options,
            'answer' => $answer,
            'explanation' => trim((string)($q['explanation'] ?? '')),
            'difficulty' => max(0.05, min(0.95, (float)($q['difficulty'] ?? 0.35))),
            'type' => $mode === 'listening' ? 'listening_mc' : 'mc',
            'listening_segment_text' => $mode === 'listening' ? trim((string)($q['listening_segment_text'] ?? ($q['audio']['text'] ?? ''))) : '',
            'listening_segment_title' => $mode === 'listening' ? trim((string)($q['listening_segment_title'] ?? '')) : '',
        ];

        if ($mode === 'listening') {
            $questionPayload['audio'] = [
                'text' => $questionPayload['listening_segment_text'],
                'path' => $q['audio']['path'] ?? null,
                'status' => $q['audio']['status'] ?? 'none',
                'voice_id' => $q['audio']['voice_id'] ?? null,
                'model_id' => $q['audio']['model_id'] ?? null,
            ];
        }

        $questions[] = $questionPayload;
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
            unset($file['pdf_preview_images']);
            unset($file['openai_file_id']);
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
            'listening_status' => $isListening ? 'segment_audio' : 'none',
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

        $selectedTopicId = !empty($draft['curriculum_topic_content_id']) ? (int)$draft['curriculum_topic_content_id'] : 0;
        $selectedSubtopicId = !empty($draft['curriculum_topic_subtopic_id']) ? (int)$draft['curriculum_topic_subtopic_id'] : 0;
        if ($selectedTopicId > 0) {
            elevaro_teacher_ai_store_curriculum_mapping($quizId, $selectedTopicId, $selectedSubtopicId ?: null, 'selected', 100.0);
        } else {
            elevaro_teacher_ai_auto_match_curriculum($quizId, $class, $payload);
        }

        $sort = 0;
        foreach ($payload['questions'] as $q) {
            $sort += 10;
            $questionType = $isListening ? 'listening_mc' : 'mc';
            $segmentText = $isListening ? trim((string)($q['listening_segment_text'] ?? '')) : '';
            $segmentTitle = $isListening ? trim((string)($q['listening_segment_title'] ?? '')) : '';
            $audioPath = null;
            $audioStatus = 'none';
            $audioVoiceId = null;
            $audioModelId = null;

            if ($isListening && $segmentText !== '') {
                $existingAudio = is_array($q['audio'] ?? null) ? $q['audio'] : [];
                $audioPath = trim((string)($existingAudio['path'] ?? '')) ?: null;
                $audioVoiceId = $existingAudio['voice_id'] ?? null;
                $audioModelId = $existingAudio['model_id'] ?? null;
                $audioStatus = $audioPath ? 'generated' : 'text_generated';

                if (!$audioPath) {
                    try {
                        $generatedAudio = elevaro_generate_audio_file($segmentText, 'listening_segment');
                        $audioPath = $generatedAudio['path'] ?? null;
                        $audioVoiceId = $generatedAudio['voice_id'] ?? null;
                        $audioModelId = $generatedAudio['model_id'] ?? null;
                        $audioStatus = $audioPath ? 'generated' : 'text_generated';
                    } catch (Throwable $audioError) {
                        error_log('[Elevaro AI Wizard Listening] Audio generation failed: ' . $audioError->getMessage());
                        $audioStatus = 'text_generated';
                    }
                }
            }

            $stmt = $pdo->prepare("INSERT INTO questions
                (quiz_id, question_key, type, question_text, correct_answer, explanation, difficulty_manual,
                 difficulty_calculated, status, ai_generated, source_context, source_excerpt,
                 audio_text, audio_path, audio_status, audio_voice_id, audio_model_id, sort_order)
                VALUES
                (:quiz_id, :question_key, :type, :question_text, :correct_answer, :explanation, :difficulty_manual,
                 :difficulty_calculated, 'draft', 1, :source_context, :source_excerpt,
                 :audio_text, :audio_path, :audio_status, :audio_voice_id, :audio_model_id, :sort_order)");
            $stmt->execute([
                'quiz_id' => $quizId,
                'question_key' => elevaro_teacher_ai_slug($q['question']) . '-' . substr(sha1($quizId . '-' . $sort), 0, 6),
                'type' => $questionType,
                'question_text' => $q['question'],
                'correct_answer' => $q['answer'],
                'explanation' => $q['explanation'],
                'difficulty_manual' => $q['difficulty'],
                'difficulty_calculated' => $q['difficulty'],
                'source_context' => $isListening ? 'listening_segment' : 'general',
                'source_excerpt' => $isListening ? ($segmentTitle ?: ('Abschnitt ' . (int)($sort / 10))) : null,
                'audio_text' => $segmentText ?: null,
                'audio_path' => $audioPath,
                'audio_status' => $audioStatus,
                'audio_voice_id' => $audioVoiceId,
                'audio_model_id' => $audioModelId,
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

if (!function_exists('elevaro_teacher_ai_create_background_draft')) {
    function elevaro_teacher_ai_create_background_draft(int $teacherId, int $classId, string $mode, string $sourceText, string $extraPrompt, array $files, string $promptLog, string $openaiResponseId): int
    {
        elevaro_teacher_ai_wizard_ensure_schema();
        elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'openai_response_id', "openai_response_id VARCHAR(120) NULL AFTER status");
        elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'generation_error', "generation_error TEXT NULL AFTER openai_response_id");
        $pdo = elevaro_teacher_ai_wizard_db();
        $stmt = $pdo->prepare("INSERT INTO teacher_ai_quiz_drafts
            (teacher_id, class_id, mode, status, openai_response_id, source_title, source_text, source_files_json, prompt, generated_payload_json, image_prompt, image_status)
            VALUES (:teacher_id, :class_id, :mode, 'generating', :openai_response_id, NULL, :source_text, :source_files_json, :prompt, NULL, NULL, 'none')");
        $stmt->execute([
            'teacher_id' => $teacherId,
            'class_id' => $classId,
            'mode' => $mode,
            'openai_response_id' => $openaiResponseId,
            'source_text' => $sourceText,
            'source_files_json' => json_encode(array_map(static function ($file) {
                unset($file['absolute_path'], $file['pdf_preview_images'], $file['openai_file_id']);
                return $file;
            }, $files), JSON_UNESCAPED_UNICODE),
            'prompt' => $promptLog,
        ]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('elevaro_teacher_ai_start_background_generation')) {
    function elevaro_teacher_ai_start_background_generation(array $class, string $mode, string $sourceText, string $extraPrompt, array $files): array
    {
        if (!elevaro_teacher_ai_has_readable_material($sourceText, $files)) {
            throw new RuntimeException('Bitte lade ein PDF/Bild hoch oder gib Material als Text ein. Ohne Unterrichtsmaterial wird kein Quiz generiert, damit keine Fantasiefragen entstehen.');
        }

        $prompt = elevaro_teacher_ai_build_generation_prompt($class, $mode, $sourceText, $extraPrompt, $files);
        $content = [['type' => 'input_text', 'text' => $prompt]];
        $content = array_merge($content, elevaro_teacher_ai_responses_content_for_material($files));
        $system = 'Du bist ein erfahrener Lehrer, Fachdidaktiker und Quizautor. Du lieferst ausschließlich valides JSON nach Schema. Du darfst keine Fragen erfinden, die nicht aus dem bereitgestellten Material ableitbar sind. PDFs und Bilder sind als Primärquelle auszuwerten; lies alle sichtbaren Inhalte sorgfältig, soweit erkennbar.';
        $response = elevaro_openai_responses_create_background_json($system, $content, elevaro_teacher_ai_generation_schema(), 0.22);
        $response['prompt_log'] = $prompt;
        return $response;
    }
}

if (!function_exists('elevaro_teacher_ai_poll_background_draft')) {
    function elevaro_teacher_ai_poll_background_draft(int $draftId, int $teacherId): array
    {
        elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'openai_response_id', "openai_response_id VARCHAR(120) NULL AFTER status");
        elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'generation_error', "generation_error TEXT NULL AFTER openai_response_id");
        $draft = elevaro_teacher_ai_load_draft($draftId, $teacherId);

        if (($draft['status'] ?? '') === 'draft' && !empty($draft['generated_payload_json'])) {
            return ['ok' => true, 'done' => true, 'draft_id' => $draftId, 'payload' => elevaro_teacher_ai_draft_payload($draft)];
        }
        if (!empty($draft['generation_error'])) {
            throw new RuntimeException((string)$draft['generation_error']);
        }

        $responseId = (string)($draft['openai_response_id'] ?? '');
        if ($responseId === '') {
            throw new RuntimeException('Für diesen Entwurf wurde keine OpenAI-Background-ID gespeichert. Bitte starte die Generierung neu.');
        }

        $data = elevaro_openai_responses_retrieve($responseId);
        $status = (string)($data['status'] ?? 'in_progress');

        if (in_array($status, ['queued', 'in_progress', 'requires_action'], true)) {
            return ['ok' => true, 'done' => false, 'draft_id' => $draftId, 'status' => $status];
        }

        if ($status !== 'completed') {
            $message = $data['error']['message'] ?? ('OpenAI-Generierung wurde mit Status ' . $status . ' beendet.');
            elevaro_teacher_ai_wizard_db()->prepare("UPDATE teacher_ai_quiz_drafts SET status = 'failed', generation_error = :error WHERE id = :id AND teacher_id = :teacher_id")
                ->execute(['error' => $message, 'id' => $draftId, 'teacher_id' => $teacherId]);
            throw new RuntimeException((string)$message);
        }

        $content = elevaro_openai_extract_response_text($data);
        if ($content === '') {
            throw new RuntimeException('OpenAI hat keine auswertbare Antwort geliefert.');
        }
        $json = elevaro_teacher_ai_decode_openai_json($content, $debugStage ?? 'unknown');
        $payload = elevaro_teacher_ai_normalize_payload($json, (string)($draft['mode'] ?? 'quiz'));

        $stmt = elevaro_teacher_ai_wizard_db()->prepare("UPDATE teacher_ai_quiz_drafts
            SET status = 'draft', generated_payload_json = :payload, source_title = :title, image_prompt = :image_prompt, image_status = 'pending'
            WHERE id = :id AND teacher_id = :teacher_id");
        $stmt->execute([
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'title' => $payload['title'] ?? null,
            'image_prompt' => $payload['image_prompt'] ?? null,
            'id' => $draftId,
            'teacher_id' => $teacherId,
        ]);

        return ['ok' => true, 'done' => true, 'draft_id' => $draftId, 'payload' => $payload];
    }
}

if (!function_exists('elevaro_teacher_ai_prepare_openai_material_files')) {
    function elevaro_teacher_ai_prepare_openai_material_files(array $files): array
    {
        foreach ($files as &$file) {
            $mime = (string)($file['mime'] ?? '');
            $absolute = (string)($file['absolute_path'] ?? '');
            if ($mime === 'application/pdf' && empty($file['openai_file_id']) && $absolute !== '' && is_file($absolute)) {
                $file['openai_file_id'] = elevaro_openai_upload_file($absolute, 'application/pdf', 'user_data');
            }
        }
        unset($file);
        return $files;
    }
}

if (!function_exists('elevaro_teacher_ai_analysis_schema')) {
    function elevaro_teacher_ai_analysis_schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'mode' => ['type' => 'string', 'enum' => ['quiz', 'listening']],
                'language' => ['type' => 'string'],
                'source_summary' => ['type' => 'string'],
                'usable_material_note' => ['type' => 'string'],
                'material_type' => ['type' => 'string', 'enum' => ['reading_text', 'worksheet', 'vocabulary_list', 'grammar_exercise', 'mixed', 'image_based_task']],
                'task_intent' => ['type' => 'string', 'enum' => ['quiz_about_content', 'practice_same_skill', 'vocabulary_training', 'grammar_training', 'listening_comprehension']],
                'target_language' => ['type' => 'string'],
                'content_map' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'area' => ['type' => 'string'],
                            'learning_goal' => ['type' => 'string'],
                            'question_strategy' => ['type' => 'string'],
                        ],
                        'required' => ['area', 'learning_goal', 'question_strategy'],
                    ],
                ],
                'topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                'difficulty_progression' => ['type' => 'array', 'items' => ['type' => 'string']],
                'image_prompt' => ['type' => 'string'],
                'listening_text' => ['type' => 'string'],
                'listening_segments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'segment_number' => ['type' => 'integer'],
                            'segment_summary' => ['type' => 'string'],
                            'question_goal' => ['type' => 'string'],
                        ],
                        'required' => ['segment_number', 'segment_summary', 'question_goal'],
                    ],
                ],
                'question_plan' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'block' => ['type' => 'integer'],
                            'focus' => ['type' => 'string'],
                            'difficulty' => ['type' => 'string'],
                            'source_reference' => ['type' => 'string'],
                        ],
                        'required' => ['block', 'focus', 'difficulty', 'source_reference'],
                    ],
                ],
            ],
            'required' => ['title', 'description', 'mode', 'language', 'source_summary', 'usable_material_note', 'material_type', 'task_intent', 'target_language', 'content_map', 'topics', 'difficulty_progression', 'image_prompt', 'listening_text', 'listening_segments', 'question_plan'],
        ];
    }
}

if (!function_exists('elevaro_teacher_ai_questions_block_schema')) {
    function elevaro_teacher_ai_questions_block_schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 10,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'question' => ['type' => 'string'],
                            'options' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4, 'items' => ['type' => 'string']],
                            'answer' => ['type' => 'string'],
                            'explanation' => ['type' => 'string'],
                            'difficulty' => ['type' => 'number'],
                            'source_reference' => ['type' => 'string'],
                            'listening_segment_text' => ['type' => 'string'],
                            'listening_segment_title' => ['type' => 'string'],
                        ],
                        'required' => ['question', 'options', 'answer', 'explanation', 'difficulty', 'source_reference', 'listening_segment_text', 'listening_segment_title'],
                    ],
                ],
            ],
            'required' => ['questions'],
        ];
    }
}

if (!function_exists('elevaro_teacher_ai_split_ensure_schema')) {
    function elevaro_teacher_ai_split_ensure_schema(): void
    {
        elevaro_teacher_ai_wizard_ensure_schema();
        elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'generation_step', "generation_step VARCHAR(60) NULL AFTER status");
        elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'analysis_json', "analysis_json LONGTEXT NULL AFTER generated_payload_json");
        elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'question_blocks_json', "question_blocks_json LONGTEXT NULL AFTER analysis_json");
        elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'generation_error', "generation_error TEXT NULL AFTER question_blocks_json");
        elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'source_kind', "source_kind VARCHAR(40) NOT NULL DEFAULT 'material' AFTER mode");
        elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'curriculum_topic_content_id', "curriculum_topic_content_id INT UNSIGNED NULL AFTER source_kind");
        elevaro_teacher_ai_wizard_add_column_if_missing('teacher_ai_quiz_drafts', 'curriculum_topic_subtopic_id', "curriculum_topic_subtopic_id INT UNSIGNED NULL AFTER curriculum_topic_content_id");
        elevaro_teacher_ai_wizard_db()->exec("CREATE TABLE IF NOT EXISTS quiz_curriculum_topics (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            quiz_id INT UNSIGNED NOT NULL,
            curriculum_topic_content_id INT UNSIGNED NOT NULL,
            curriculum_topic_subtopic_id INT UNSIGNED NULL,
            match_type ENUM('selected','auto','manual') NOT NULL DEFAULT 'selected',
            confidence DECIMAL(5,2) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_quiz_curriculum_topic (quiz_id, curriculum_topic_content_id, curriculum_topic_subtopic_id),
            KEY idx_quiz_curriculum_quiz (quiz_id),
            KEY idx_quiz_curriculum_topic (curriculum_topic_content_id),
            KEY idx_quiz_curriculum_subtopic (curriculum_topic_subtopic_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}


if (!function_exists('elevaro_teacher_ai_curriculum_topics_for_class')) {
    function elevaro_teacher_ai_curriculum_topics_for_class(array $class): array
    {
        $state = (string)($class['state_code'] ?? '');
        $school = (string)($class['school_type_code'] ?? '');
        $gradeKey = (string)(($class['level_key'] ?? '') ?: ($class['grade'] ?? ''));
        $subject = (string)($class['subject_code'] ?? '');
        if ($state === '' || $school === '' || $gradeKey === '' || $subject === '') return [];

        $pdo = elevaro_teacher_ai_wizard_db();
        $stmt = $pdo->prepare("SELECT * FROM curriculum_topics_content
            WHERE state_code = :state AND school_type_key = :school AND grade_key = :grade AND subject_key = :subject
              AND COALESCE(is_active, 1) = 1
            ORDER BY domain_title ASC, sort_order ASC, topic_title ASC");
        $stmt->execute(['state'=>$state,'school'=>$school,'grade'=>$gradeKey,'subject'=>$subject]);
        $topics = $stmt->fetchAll() ?: [];
        if (!$topics) return [];
        $ids = array_map(static fn($row)=>(int)$row['id'],$topics);
        $subtopicsByTopic=[];
        if ($ids) {
            $ph = implode(',', array_fill(0,count($ids),'?'));
            $sub = $pdo->prepare("SELECT * FROM curriculum_topic_subtopics
                WHERE curriculum_topic_content_id IN ({$ph}) AND COALESCE(is_active, 1) = 1
                ORDER BY curriculum_topic_content_id ASC, sort_order ASC, subtopic_title ASC");
            $sub->execute($ids);
            foreach ($sub->fetchAll() ?: [] as $row) $subtopicsByTopic[(int)$row['curriculum_topic_content_id']][]=$row;
        }
        foreach ($topics as &$topic) $topic['subtopics']=$subtopicsByTopic[(int)$topic['id']] ?? [];
        unset($topic);
        return $topics;
    }
}

if (!function_exists('elevaro_teacher_ai_curriculum_context')) {
    function elevaro_teacher_ai_curriculum_context(int $topicId, ?int $subtopicId, array $class): array
    {
        $pdo = elevaro_teacher_ai_wizard_db();
        $stmt=$pdo->prepare("SELECT * FROM curriculum_topics_content WHERE id=:id LIMIT 1");
        $stmt->execute(['id'=>$topicId]);
        $topic=$stmt->fetch();
        if (!$topic) throw new RuntimeException('Das ausgewählte Lehrplanthema wurde nicht gefunden.');
        $classGrade=(string)(($class['level_key'] ?? '') ?: ($class['grade'] ?? ''));
        if ((string)$topic['state_code'] !== (string)($class['state_code'] ?? '') || (string)$topic['school_type_key'] !== (string)($class['school_type_code'] ?? '') || (string)$topic['grade_key'] !== $classGrade || (string)$topic['subject_key'] !== (string)($class['subject_code'] ?? '')) {
            throw new RuntimeException('Das ausgewählte Lehrplanthema passt nicht zur aktuellen Klasse.');
        }
        $subtopic=null;
        if ($subtopicId) {
            $sub=$pdo->prepare("SELECT * FROM curriculum_topic_subtopics WHERE id=:id AND curriculum_topic_content_id=:topic_id LIMIT 1");
            $sub->execute(['id'=>$subtopicId,'topic_id'=>$topicId]);
            $subtopic=$sub->fetch() ?: null;
        }
        return [
            'topic'=>$topic,
            'subtopic'=>$subtopic,
            'aliases'=>json_decode((string)($topic['aliases_json'] ?? '[]'), true) ?: [],
            'keywords'=>json_decode((string)($topic['keywords_json'] ?? '[]'), true) ?: [],
            'subtopic_aliases'=>$subtopic ? (json_decode((string)($subtopic['aliases_json'] ?? '[]'), true) ?: []) : [],
            'subtopic_keywords'=>$subtopic ? (json_decode((string)($subtopic['keywords_json'] ?? '[]'), true) ?: []) : [],
        ];
    }
}

if (!function_exists('elevaro_teacher_ai_curriculum_prompt_block')) {
    function elevaro_teacher_ai_curriculum_prompt_block(array $context): string
    {
        $t=$context['topic']; $s=$context['subtopic'];
        $keywords=array_filter(array_merge((array)$context['keywords'],(array)$context['subtopic_keywords']));
        $aliases=array_filter(array_merge((array)$context['aliases'],(array)$context['subtopic_aliases']));
        $lines=[
            'Lehrplanthema als verbindliche Quelle:',
            '- Bereich: '.(string)($t['domain_title'] ?? ''),
            '- Thema kurz: '.(string)(($t['title_short'] ?? '') ?: ($t['topic_title'] ?? '')),
            '- Thema lang: '.(string)($t['title_long'] ?? ''),
            '- Beschreibung: '.(string)($t['topic_description'] ?? ''),
            '- Lernziel: '.(string)($t['learning_goal'] ?? ''),
        ];
        if ($s) {
            $lines[]='- Ausgewähltes Unterthema kurz: '.(string)(($s['title_short'] ?? '') ?: ($s['subtopic_title'] ?? ''));
            $lines[]='- Ausgewähltes Unterthema lang: '.(string)($s['title_long'] ?? '');
            $lines[]='- Unterthema-Lernziel: '.(string)($s['learning_goal'] ?? '');
        }
        if ($keywords) $lines[]='- Keywords: '.implode(', ', array_slice($keywords,0,18));
        if ($aliases) $lines[]='- Aliasbegriffe: '.implode(', ', array_slice($aliases,0,12));
        return implode("\n",$lines);
    }
}

if (!function_exists('elevaro_teacher_ai_store_curriculum_mapping')) {
    function elevaro_teacher_ai_store_curriculum_mapping(int $quizId, ?int $topicId, ?int $subtopicId, string $matchType='selected', ?float $confidence=null): void
    {
        if (!$topicId) return;
        elevaro_teacher_ai_split_ensure_schema();
        $stmt=elevaro_teacher_ai_wizard_db()->prepare("INSERT INTO quiz_curriculum_topics (quiz_id,curriculum_topic_content_id,curriculum_topic_subtopic_id,match_type,confidence)
            VALUES (:quiz_id,:topic_id,:subtopic_id,:match_type,:confidence)
            ON DUPLICATE KEY UPDATE match_type=VALUES(match_type), confidence=VALUES(confidence)");
        $stmt->execute(['quiz_id'=>$quizId,'topic_id'=>$topicId,'subtopic_id'=>$subtopicId ?: null,'match_type'=>$matchType,'confidence'=>$confidence]);
    }
}

if (!function_exists('elevaro_teacher_ai_auto_match_curriculum')) {
    function elevaro_teacher_ai_auto_match_curriculum(int $quizId, array $class, array $payload): void
    {
        $topics=elevaro_teacher_ai_curriculum_topics_for_class($class);
        if (!$topics) return;
        $haystack=mb_strtolower(implode(' ',array_filter([
            $payload['title'] ?? '', $payload['description'] ?? '', implode(' ',(array)($payload['topics'] ?? [])),
            implode(' ',array_map(static fn($q)=>(string)($q['question'] ?? ''),array_slice((array)($payload['questions'] ?? []),0,10))),
        ])),'UTF-8');
        $bestTopic=null; $bestSubtopic=null; $bestScore=0;
        foreach ($topics as $topic) {
            $score=0;
            foreach ([($topic['title_short'] ?? ''),($topic['title_long'] ?? ''),($topic['topic_title'] ?? ''),($topic['domain_title'] ?? '')] as $term) {
                $term=mb_strtolower(trim((string)$term),'UTF-8'); if ($term !== '' && str_contains($haystack,$term)) $score+=4;
            }
            foreach ((json_decode((string)($topic['keywords_json'] ?? '[]'),true) ?: []) as $kw) { $kw=mb_strtolower(trim((string)$kw),'UTF-8'); if ($kw !== '' && str_contains($haystack,$kw)) $score+=2; }
            foreach (($topic['subtopics'] ?? []) as $sub) {
                $subScore=$score;
                foreach ([($sub['title_short'] ?? ''),($sub['title_long'] ?? ''),($sub['subtopic_title'] ?? '')] as $term) { $term=mb_strtolower(trim((string)$term),'UTF-8'); if ($term !== '' && str_contains($haystack,$term)) $subScore+=3; }
                foreach ((json_decode((string)($sub['keywords_json'] ?? '[]'),true) ?: []) as $kw) { $kw=mb_strtolower(trim((string)$kw),'UTF-8'); if ($kw !== '' && str_contains($haystack,$kw)) $subScore+=2; }
                if ($subScore > $bestScore) { $bestScore=$subScore; $bestTopic=$topic; $bestSubtopic=$sub; }
            }
            if ($score > $bestScore) { $bestScore=$score; $bestTopic=$topic; $bestSubtopic=null; }
        }
        if ($bestTopic && $bestScore >= 4) elevaro_teacher_ai_store_curriculum_mapping($quizId,(int)$bestTopic['id'],$bestSubtopic ? (int)$bestSubtopic['id'] : null,'auto',min(99,$bestScore*10));
    }
}

if (!function_exists('elevaro_teacher_ai_create_split_draft')) {
    function elevaro_teacher_ai_create_split_draft(int $teacherId, int $classId, string $mode, string $sourceText, string $extraPrompt, array $files, array $options = []): int
    {
        elevaro_teacher_ai_split_ensure_schema();
        $sourceKind = (string)($options['source_kind'] ?? 'material');
        $topicId = isset($options['curriculum_topic_content_id']) ? (int)$options['curriculum_topic_content_id'] : 0;
        $subtopicId = isset($options['curriculum_topic_subtopic_id']) ? (int)$options['curriculum_topic_subtopic_id'] : 0;
        $class = elevaro_teacher_ai_class_for_teacher($classId, $teacherId);

        if ($sourceKind === 'curriculum') {
            if ($topicId <= 0) throw new RuntimeException('Bitte ein Lehrplanthema auswählen.');
            $ctx = elevaro_teacher_ai_curriculum_context($topicId, $subtopicId ?: null, $class);
            $sourceText = trim($sourceText . "

" . elevaro_teacher_ai_curriculum_prompt_block($ctx));
            $extraPrompt = trim($extraPrompt . "

Quelle: Lehrplanthema ohne zusätzliches Material. Erstelle ein Quiz auf Basis dieses Lehrplanthemas, der Langbeschreibung, Lernziele, Keywords und des Klassenkontexts. Keine Quellenverweise im Fragetext.");
            $files = [];
        } elseif (!elevaro_teacher_ai_has_readable_material($sourceText, $files)) {
            throw new RuntimeException('Bitte Material hochladen, Text eingeben oder ein Lehrplanthema auswählen.');
        }

        $files = elevaro_teacher_ai_prepare_openai_material_files($files);
        $promptLog = elevaro_teacher_ai_build_generation_prompt($class, $mode, $sourceText, $extraPrompt, $files);

        $pdo = elevaro_teacher_ai_wizard_db();
        $stmt = $pdo->prepare("INSERT INTO teacher_ai_quiz_drafts
            (teacher_id, class_id, mode, source_kind, curriculum_topic_content_id, curriculum_topic_subtopic_id, status, generation_step, source_title, source_text, source_files_json, prompt, generated_payload_json, image_prompt, image_status)
            VALUES (:teacher_id, :class_id, :mode, :source_kind, :curriculum_topic_content_id, :curriculum_topic_subtopic_id, 'generating', 'analysis', NULL, :source_text, :source_files_json, :prompt, NULL, NULL, 'none')");
        $stmt->execute([
            'teacher_id' => $teacherId,
            'class_id' => $classId,
            'mode' => $mode,
            'source_kind' => $sourceKind,
            'curriculum_topic_content_id' => $topicId ?: null,
            'curriculum_topic_subtopic_id' => $subtopicId ?: null,
            'source_text' => $sourceText,
            'source_files_json' => json_encode($files, JSON_UNESCAPED_UNICODE),
            'prompt' => $promptLog,
        ]);
        return (int)$pdo->lastInsertId();
    }
}


if (!function_exists('elevaro_teacher_ai_target_question_count')) {
    function elevaro_teacher_ai_target_question_count(string $mode): int
    {
        return $mode === 'listening' ? 5 : 15;
    }
}

if (!function_exists('elevaro_teacher_ai_generation_block_size')) {
    function elevaro_teacher_ai_generation_block_size(string $mode): int
    {
        return $mode === 'listening' ? 1 : 5;
    }
}

if (!function_exists('elevaro_teacher_ai_generation_block_count')) {
    function elevaro_teacher_ai_generation_block_count(string $mode): int
    {
        return (int)ceil(elevaro_teacher_ai_target_question_count($mode) / elevaro_teacher_ai_generation_block_size($mode));
    }
}

if (!function_exists('elevaro_teacher_ai_listening_story_instruction')) {
    function elevaro_teacher_ai_listening_story_instruction(int $targetQuestions): string
    {
        return "Listening-Speziallogik:\n"
            . "- Erstelle keine lange Audiodatei für das ganze Quiz.\n"
            . "- Plane eine zusammenhängende kleine Story oder Situation in exakt {$targetQuestions} kurzen Hörabschnitten.\n"
            . "- Jeder Abschnitt bekommt später genau eine Verständnisfrage.\n"
            . "- Die Abschnitte müssen in fester Reihenfolge funktionieren und dürfen nicht zufällig gemischt werden.\n"
            . "- Jeder Abschnitt soll kurz genug sein, um direkt vor der jeweiligen Frage abgespielt zu werden.\n"
            . "- Die Fragen, Antwortoptionen und Erklärungen bleiben in der Ziel-/Fremdsprache, sofern der Lehrer nichts anderes verlangt.\n";
    }
}


if (!function_exists('elevaro_teacher_ai_split_build_analysis_prompt')) {
    function elevaro_teacher_ai_split_build_analysis_prompt(array $class, string $mode, string $sourceText, string $extraPrompt, array $files): string
    {
        $subject = elevaro_teacher_ai_subject_label($class['subject_code'] ?? '');
        $grade = (string)($class['grade'] ?? '');
        $level = (string)($class['level_key'] ?? '');
        $school = (string)($class['school_type_code'] ?? '');
        $language = elevaro_teacher_ai_language_for_class($class, $mode);
        $fileList = array_map(static fn($file) => '- ' . (string)($file['original_name'] ?? 'Material') . ' (' . (string)($file['mime'] ?? 'Datei') . ')', $files);

        return trim("Analysiere die Quelle für einen Elevaro-KI-Quiz-Wizard. Die Quelle kann hochgeladenes Unterrichtsmaterial oder ein ausgewähltes Lehrplanthema sein.\n\n" .
            "Klassenkontext:\n- Klasse: {$grade}\n- Schulart/Level: {$school} / {$level}\n- Fach: {$subject}\n- Modus: " . ($mode === 'listening' ? 'Listening + Comprehension' : 'normales Multiple-Choice-Quiz') . "\n- Erwartete Sprache der Fragen: {$language}\n\n" .
            "Dateien:\n" . ($fileList ? implode("\n", $fileList) : '[Keine Datei hochgeladen.]') . "\n\n" .
            "Lehrertext / Aufgabenstellung:\n" . ($sourceText !== '' ? $sourceText : '[Kein zusätzlicher Text eingetragen.]') . "\n\n" .
            "Zusatzwunsch des Lehrers:\n" . ($extraPrompt !== '' ? $extraPrompt : '[Keine Zusatzanweisung.]') . "\n\n" .
            "Aufgabe für diesen Schritt: Erstelle KEINE fertigen Fragen. Analysiere nur die Quelle und entscheide zuerst, welche Art von Material vorliegt.\n" .
            "Zielumfang: " . elevaro_teacher_ai_target_question_count($mode) . " Fragen insgesamt. Bei normalen Quizzen 15 Fragen. Bei Listening 5 Fragen mit je einem kurzen Hörabschnitt.\n" .
            "Klassifiziere material_type und task_intent besonders sorgfältig:\n" .
            "- Wenn die Quelle ein Lehrplanthema ohne Material ist: material_type = mixed, task_intent = quiz_about_content. Erstelle eine vollständige, lehrplanorientierte Themenabdeckung, keine Materialbeschreibung.\n" .
            "- reading_text + quiz_about_content: Der Inhalt selbst soll verstanden und abgefragt werden.\n" .
            "- worksheet, grammar_exercise oder vocabulary_list: Das Blatt ist meist Übungsmaterial. Dann darf NICHT zufälliger Beispielsatz-Inhalt abgefragt werden. Stattdessen sollen die geübten Lernziele, Vokabeln, Satzmuster, Grammatikstrukturen oder Kompetenzen trainiert werden.\n" .
            "- Bei Fremdsprachen-Arbeitsblättern: Übersetze die späteren Fragen nicht automatisch ins Deutsche. Bewahre die Sprache und den Aufgabentyp des Materials, sofern der Lehrer nichts anderes verlangt.\n" .
            "- Bei Lückentexten: Erkenne, ob es um Vokabeln, Grammatik, Wortpaare, Monate, Ordnungszahlen, Datum, Pronomen o. ä. geht. Frage nicht nach irrelevanten Inhalten aus Beispielsätzen.\n\n" .
            "Erstelle eine content_map mit den wichtigsten Lernzielen und Frage-Strategien. Diese content_map ist die verbindliche Grundlage für die späteren Fragen und soll die relevanten Inhalte bzw. Kompetenzen möglichst vollständig abdecken.\n" .
            ($mode === 'listening'
                ? elevaro_teacher_ai_listening_story_instruction(elevaro_teacher_ai_target_question_count($mode)) . "Erstelle eine Fragenplanung für 5 Abschnitte in fester Reihenfolge. listening_text ist nur eine kurze Gesamtzusammenfassung; die eigentlichen Hörtexte kommen später pro Frage als listening_segment_text.\n"
                : "Erstelle eine belastbare Fragenplanung für 15 spätere Multiple-Choice-Fragen in 3 Blöcken à 5 Fragen. Verteile die Blöcke über die content_map, damit nichts Wesentliches verloren geht.\n") .
            "description ist eine kurze, motivierende Quizbeschreibung für Schülerinnen und Schüler, keine Beschreibung der hochgeladenen Quelle.\n" .
            "image_prompt beschreibt ein passendes Elevaro-Quizbild im bestehenden modernen, freundlichen, spielerisch-edukativen Stil für die Zielgruppe; beschreibe NICHT das PDF, Arbeitsblatt oder Handschriften.\n" .
            "Der Klassenkontext darf Niveau und Sprache steuern, aber keine Fakten ergänzen. Wenn Inhalte unleserlich sind, benenne das offen.\n" .
            "Bei Listening: Erstelle keinen langen Sprechertext für das ganze Quiz, sondern nutze listening_segments als Story-/Abschnittsplan. listening_text darf nur eine kurze Zusammenfassung der Story sein.");
    }
}

if (!function_exists('elevaro_teacher_ai_split_build_questions_prompt')) {
    function elevaro_teacher_ai_split_build_questions_prompt(array $class, array $analysis, int $blockIndex, int $blockSize, string $mode, string $sourceText, string $extraPrompt, array $existingQuestions): string
    {
        $start = $blockIndex * $blockSize + 1;
        $end = $start + $blockSize - 1;
        $language = (string)($analysis['target_language'] ?? $analysis['language'] ?? elevaro_teacher_ai_language_for_class($class, $mode));
        $materialType = (string)($analysis['material_type'] ?? 'mixed');
        $taskIntent = (string)($analysis['task_intent'] ?? 'quiz_about_content');
        $existing = array_map(static fn($q) => (string)($q['question'] ?? ''), $existingQuestions);
        $focus = '';
        foreach (($analysis['question_plan'] ?? []) as $plan) {
            if ((int)($plan['block'] ?? 0) === $blockIndex + 1) {
                $focus .= '- Fokus: ' . (string)($plan['focus'] ?? '') . ' | Schwierigkeit: ' . (string)($plan['difficulty'] ?? '') . ' | interne Quelle: ' . (string)($plan['source_reference'] ?? '') . "\n";
            }
        }
        if ($focus === '') $focus = '- Nutze content_map, Analyse und Originalmaterial für diesen Block.\n';

        $listeningRules = '';
        if ($mode === 'listening') {
            $segmentNumber = $blockIndex + 1;
            $segmentPlan = '';
            foreach (($analysis['listening_segments'] ?? []) as $segment) {
                if ((int)($segment['segment_number'] ?? 0) === $segmentNumber) {
                    $segmentPlan = '- Abschnitt ' . $segmentNumber . ': ' . (string)($segment['segment_summary'] ?? '') . ' | Frageziel: ' . (string)($segment['question_goal'] ?? '');
                    break;
                }
            }
            if ($segmentPlan === '') {
                $segmentPlan = '- Abschnitt ' . $segmentNumber . ': Nutze die Story- und Fragenplanung aus der Analyse.';
            }

            $listeningRules = "\nListening-Regeln für diesen Block:\n"
                . "- Erzeuge exakt eine Frage für Abschnitt {$segmentNumber}.\n"
                . "- Erzeuge dazu ein Feld listening_segment_text: ein kurzer Hörabschnitt in der Zielsprache.\n"
                . "- Der Hörabschnitt muss vor der Frage verständlich sein und darf nicht länger als ca. 35–55 Wörter sein.\n"
                . "- Die Story muss in Reihenfolge funktionieren. Dieser Abschnitt darf sich sinnvoll an vorherige Abschnitte anschließen.\n"
                . "- Frage, Optionen und Erklärung beziehen sich nur auf diesen Hörabschnitt und bleiben in der Zielsprache.\n"
                . "- Setze listening_segment_title kurz, z. B. 'Abschnitt {$segmentNumber}'.\n"
                . $segmentPlan . "\n";
        }

        $worksheetRules = '';
        if ($mode !== 'listening' && (in_array($taskIntent, ['practice_same_skill', 'vocabulary_training', 'grammar_training'], true) || in_array($materialType, ['worksheet', 'grammar_exercise', 'vocabulary_list'], true))) {
            $worksheetRules = "\nSpezialregeln für Arbeitsblätter / Sprachübungen:\n" .
                "- Erzeuge Übungsfragen zum gleichen Lernziel, nicht Verständnisfragen über zufällige Beispielsatz-Inhalte.\n" .
                "- Wenn das Material englische Übungen enthält, bleiben Frage, Antwortoptionen und Erklärung grundsätzlich auf Englisch, sofern der Lehrer nichts anderes verlangt.\n" .
                "- Bei Lückentexten sollen die Schülerinnen und Schüler die passende Ergänzung wählen.\n" .
                "- Frage NICHT: 'In welcher Jahreszeit kommen die Blumen heraus?', wenn der Satz nur Vokabelmaterial für 'spring' ist. Frage stattdessen z. B. nach der passenden englischen Vokabel oder Satzergänzung.\n" .
                "- Erstelle gern neue, gleichartige Beispielsätze, damit nicht nur die Lösungen des Arbeitsblatts abgefragt werden.\n";
        }

        return trim("Erstelle Fragen {$start} bis {$end} für ein Elevaro-Schülerquiz.\n\n" .
            "Sprache der sichtbaren Fragen: {$language}\n" .
            "Modus: {$mode}\n" .
            "Materialtyp: {$materialType}\n" .
            "Aufgabenabsicht: {$taskIntent}\n\n" .
            "Verbindliche Materialanalyse:\n" . json_encode($analysis, JSON_UNESCAPED_UNICODE) . "\n\n" .
            "Block-Fokus:\n{$focus}\n" .
            "Bereits erzeugte Fragen, die NICHT wiederholt werden dürfen:\n" . ($existing ? implode("\n", array_slice($existing, -24)) : '[Noch keine]') . "\n\n" .
            "Lehrertext / Zusatzwunsch als Zusatzkontext:\n" . ($sourceText !== '' ? $sourceText : '[leer]') . "\n" . ($extraPrompt !== '' ? $extraPrompt : '') . "\n" .
            $worksheetRules . $listeningRules . "\n" .
            "Erzeuge exakt {$blockSize} neue Multiple-Choice-Fragen. Jede Frage hat exakt 4 Optionen und genau eine richtige Antwort. " .
            "Alle Fragen müssen aus Originalmaterial, content_map oder Analyse ableitbar sein. Prüfe im Zweifel wieder das direkt angehängte PDF/Bildmaterial. " .
            ($mode === 'listening'
                ? "Die Listening-Fragen sollen in Story-Reihenfolge bleiben und nicht zufällig gemischt werden. "
                : "Die Schwierigkeit soll über alle 3 Blöcke sanft ansteigen: Block 1 eher leicht, Block 2 mittel, Block 3 etwas anspruchsvoller. ") .
            "Decke in diesem Block den angegebenen Fokus ab und vermeide Wiederholungen. " .
            "Wichtig: In Frage, Antwortoptionen und Erklärung dürfen keine Materialverweise stehen wie 'laut Mindmap', 'im Arbeitsblatt', 'auf der Seite', 'in der Quelle', 'im Material' oder ähnliche Formulierungen, weil Schülerinnen und Schüler das Material später nicht sehen. source_reference darf intern knapp bleiben.");
    }
}

if (!function_exists('elevaro_teacher_ai_plausibility_schema')) {
    function elevaro_teacher_ai_plausibility_schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'overall_status' => ['type' => 'string', 'enum' => ['ok', 'needs_review']],
                'coverage_summary' => ['type' => 'string'],
                'teacher_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
                'issues' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => [
                            'question_number' => ['type' => 'integer'],
                            'severity' => ['type' => 'string', 'enum' => ['info', 'warning', 'critical']],
                            'message' => ['type' => 'string'],
                            'suggestion' => ['type' => 'string'],
                        ],
                        'required' => ['question_number', 'severity', 'message', 'suggestion'],
                    ],
                ],
            ],
            'required' => ['overall_status', 'coverage_summary', 'teacher_notes', 'issues'],
        ];
    }
}

if (!function_exists('elevaro_teacher_ai_sanitize_student_visible_question')) {
    function elevaro_teacher_ai_sanitize_student_visible_question(array $question): array
    {
        $patterns = [
            '/\b[Ll]aut\s+(der\s+|dem\s+|des\s+)?(Mindmap|Arbeitsblatt|Quelle|Material|Text|Seite|Notiz)\b/u',
            '/\b[Ii]m\s+(Arbeitsblatt|Material|Text|Dokument|PDF)\b/u',
            '/\b[Aa]uf\s+(der\s+)?(Seite|Quelle)\b/u',
            '/\b[Ww]ie\s+in\s+(der\s+)?(Mindmap|Quelle|Material)\b/u',
        ];
        $replacement = 'Im Unterricht';
        foreach (['question', 'explanation'] as $key) {
            if (isset($question[$key]) && is_string($question[$key])) {
                $question[$key] = trim(preg_replace($patterns, $replacement, $question[$key]));
            }
        }
        if (!empty($question['options']) && is_array($question['options'])) {
            foreach ($question['options'] as $i => $option) {
                if (is_string($option)) {
                    $question['options'][$i] = trim(preg_replace($patterns, $replacement, $option));
                }
            }
        }
        return $question;
    }
}

if (!function_exists('elevaro_teacher_ai_build_plausibility_prompt')) {
    function elevaro_teacher_ai_build_plausibility_prompt(array $analysis, array $questions, string $mode): string
    {
        $compactQuestions = [];
        foreach ($questions as $idx => $q) {
            $compactQuestions[] = [
                'nr' => $idx + 1,
                'question' => (string)($q['question'] ?? ''),
                'options' => array_values((array)($q['options'] ?? [])),
                'answer' => (string)($q['answer'] ?? ''),
            ];
        }

        $compactAnalysis = [
            'title' => $analysis['title'] ?? '',
            'description' => $analysis['description'] ?? '',
            'topics' => $analysis['topics'] ?? [],
            'content_map' => $analysis['content_map'] ?? [],
            'question_plan' => $analysis['question_plan'] ?? [],
        ];

        return trim("Prüfe diesen Elevaro-Quizentwurf abschließend kurz und fachlich.\n\n" .
            "Modus: {$mode}\n\n" .
            "Verbindliche Inhaltslandkarte aus dem Unterrichtsmaterial:\n" . json_encode($compactAnalysis, JSON_UNESCAPED_UNICODE) . "\n\n" .
            "Zu prüfende Fragen:\n" . json_encode($compactQuestions, JSON_UNESCAPED_UNICODE) . "\n\n" .
            "Aufgaben:\n" .
            "1. Prüfe, ob die Fragen die relevanten Inhalte ausgewogen abdecken.\n" .
            "2. Markiere nur echte Probleme: nicht ableitbar, fachlich falsch, mehrdeutig, doppelt oder zu schwer/zu leicht.\n" .
            "3. Achte auf sichtbare Materialverweise wie 'laut Mindmap', 'im Arbeitsblatt', 'auf der Seite', 'im Material' oder 'in der Quelle'. Schüler sehen das Material später nicht.\n" .
            "4. Wenn material_type ein Arbeitsblatt, eine Grammatikübung oder Vokabelliste ist: Prüfe, ob die Fragen das Lernziel trainieren und nicht zufällige Beispielsatz-Inhalte abfragen oder unnötig übersetzen.\n" .
            "5. Gib nur kurze Lehrerhinweise und Issues zurück. Schreibe NICHT alle Fragen neu aus. Liefere ausschließlich valides JSON.");
    }
}

if (!function_exists('elevaro_teacher_ai_apply_plausibility_review')) {
    function elevaro_teacher_ai_apply_plausibility_review(array $analysis, array $questions, string $mode, array $files): array
    {
        $questions = array_map('elevaro_teacher_ai_sanitize_student_visible_question', $questions);

        $fallbackReview = [
            'overall_status' => 'ok',
            'coverage_summary' => 'Der Quizentwurf wurde automatisch auf sichtbare Materialverweise bereinigt und für den Lehrer-Review vorbereitet.',
            'teacher_notes' => ['Bitte prüfe die Fragen vor der Veröffentlichung noch einmal fachlich.'],
            'issues' => [],
        ];

        try {
            @set_time_limit(45);
            $prompt = elevaro_teacher_ai_build_plausibility_prompt($analysis, $questions, $mode);
            $content = [['type' => 'input_text', 'text' => $prompt]];
            // Wichtig: Hier wird bewusst NICHT erneut das komplette Original-PDF angehängt.
            // Die Prüfung läuft gegen die zuvor erstellte Inhaltslandkarte, damit der letzte Schritt kurz bleibt und nicht timeoutet.
            $system = 'Du bist eine knappe fachliche Prüfinstanz für Lehrer-Quizentwürfe. Gib nur Hinweise und Issues zurück, keine vollständige Fragenliste. Liefere ausschließlich valides JSON nach Schema.';
            $result = elevaro_openai_responses_json($system, $content, elevaro_teacher_ai_plausibility_schema(), 0.1, 45);
            $review = $result['json'];
            if (!is_array($review)) {
                $review = $fallbackReview;
            }
            $review['teacher_notes'] = array_values(array_unique(array_filter(array_merge(
                (array)($review['teacher_notes'] ?? []),
                ['Automatisch entfernte sichtbare Materialverweise wurden bereinigt, falls vorhanden.']
            ))));
            return ['questions' => $questions, 'review' => $review];
        } catch (Throwable $e) {
            $fallbackReview['overall_status'] = 'needs_review';
            $fallbackReview['teacher_notes'][] = 'Die zusätzliche KI-Plausibilitätsprüfung wurde wegen Zeitüberschreitung oder API-Fehler übersprungen. Der Entwurf ist trotzdem nutzbar, sollte aber vom Lehrer geprüft werden.';
            $fallbackReview['issues'][] = [
                'question_number' => 0,
                'severity' => 'info',
                'message' => 'Automatischer Abschlusscheck konnte nicht vollständig ausgeführt werden.',
                'suggestion' => 'Bitte den Entwurf im Review kurz prüfen oder die Generierung mit weniger Material erneut starten.',
            ];
            return ['questions' => $questions, 'review' => $fallbackReview];
        }
    }
}

if (!function_exists('elevaro_teacher_ai_split_base_payload')) {
    function elevaro_teacher_ai_split_base_payload(array $analysis, string $mode, array $questions): array
    {
        return elevaro_teacher_ai_normalize_payload([
            'title' => (string)($analysis['title'] ?? 'KI-Quiz'),
            'description' => (string)($analysis['description'] ?? ''),
            'mode' => $mode,
            'language' => (string)($analysis['language'] ?? 'Deutsch'),
            'listening_text' => $mode === 'listening'
                ? trim((string)($analysis['listening_text'] ?? ''))
                : '',
            'image_prompt' => (string)($analysis['image_prompt'] ?? ''),
            'questions' => $questions,
            'material_type' => (string)($analysis['material_type'] ?? ''),
            'task_intent' => (string)($analysis['task_intent'] ?? ''),
        ], $mode);
    }
}


if (!function_exists('elevaro_teacher_ai_generate_listening_preview_audio')) {
    function elevaro_teacher_ai_generate_listening_preview_audio(array $payload): array
    {
        if (($payload['mode'] ?? '') !== 'listening') {
            return $payload;
        }

        foreach (($payload['questions'] ?? []) as $index => $question) {
            $segmentText = trim((string)($question['listening_segment_text'] ?? ($question['audio']['text'] ?? '')));
            if ($segmentText === '') {
                continue;
            }

            $payload['questions'][$index]['type'] = 'listening_mc';
            $payload['questions'][$index]['listening_segment_text'] = $segmentText;
            $payload['questions'][$index]['listening_segment_title'] = trim((string)($question['listening_segment_title'] ?? ('Abschnitt ' . ($index + 1))));

            $existingPath = trim((string)($question['audio']['path'] ?? ''));
            if ($existingPath !== '') {
                $payload['questions'][$index]['audio'] = [
                    'text' => $segmentText,
                    'path' => $existingPath,
                    'status' => (string)($question['audio']['status'] ?? 'generated'),
                    'voice_id' => $question['audio']['voice_id'] ?? null,
                    'model_id' => $question['audio']['model_id'] ?? null,
                ];
                continue;
            }

            try {
                $generated = elevaro_generate_audio_file($segmentText, 'listening_preview_' . ($index + 1));
                $payload['questions'][$index]['audio'] = [
                    'text' => $segmentText,
                    'path' => $generated['path'] ?? null,
                    'status' => !empty($generated['path']) ? 'generated' : 'text_generated',
                    'voice_id' => $generated['voice_id'] ?? null,
                    'model_id' => $generated['model_id'] ?? null,
                ];
            } catch (Throwable $e) {
                error_log('[Elevaro AI Wizard Listening Preview] Audio generation failed: ' . $e->getMessage());
                $payload['questions'][$index]['audio'] = [
                    'text' => $segmentText,
                    'path' => null,
                    'status' => 'text_generated',
                    'voice_id' => null,
                    'model_id' => null,
                ];
            }
        }

        return $payload;
    }
}


if (!function_exists('elevaro_teacher_ai_poll_split_draft')) {
    function elevaro_teacher_ai_poll_split_draft(int $draftId, int $teacherId): array
    {
        @set_time_limit(100);
        elevaro_teacher_ai_split_ensure_schema();
        $draft = elevaro_teacher_ai_load_draft($draftId, $teacherId);

        if (($draft['status'] ?? '') === 'draft' && !empty($draft['generated_payload_json'])) {
            return ['ok' => true, 'done' => true, 'draft_id' => $draftId, 'payload' => elevaro_teacher_ai_draft_payload($draft)];
        }
        if (($draft['status'] ?? '') === 'failed' && !empty($draft['generation_error'])) {
            throw new RuntimeException((string)$draft['generation_error']);
        }

        $class = elevaro_teacher_ai_class_for_teacher((int)$draft['class_id'], $teacherId);
        $mode = (string)($draft['mode'] ?? 'quiz');
        $sourceText = (string)($draft['source_text'] ?? '');
        $extraPrompt = '';
        $files = json_decode((string)($draft['source_files_json'] ?? '[]'), true);
        if (!is_array($files)) $files = [];
        $files = elevaro_teacher_ai_prepare_openai_material_files($files);

        $analysis = json_decode((string)($draft['analysis_json'] ?? ''), true);
        $blocks = json_decode((string)($draft['question_blocks_json'] ?? '[]'), true);
        if (!is_array($blocks)) $blocks = [];
        $allQuestions = [];
        foreach ($blocks as $block) {
            foreach (($block['questions'] ?? []) as $q) $allQuestions[] = $q;
        }

        try {
            if (!is_array($analysis)) {
                $prompt = elevaro_teacher_ai_split_build_analysis_prompt($class, $mode, $sourceText, $extraPrompt, $files);
                $content = [['type' => 'input_text', 'text' => $prompt]];
                $content = array_merge($content, elevaro_teacher_ai_responses_content_for_material($files));
                $system = 'Du bist ein erfahrener Lehrer und Fachdidaktiker. Analysiere Unterrichtsmaterial quellengebunden. Keine Fantasieinhalte. Liefere ausschließlich valides JSON nach Schema.';
                $result = elevaro_openai_responses_json($system, $content, elevaro_teacher_ai_analysis_schema(), 0.15, 90);
                $analysis = $result['json'];
                elevaro_teacher_ai_wizard_db()->prepare("UPDATE teacher_ai_quiz_drafts SET analysis_json = :analysis, generation_step = 'questions_1', source_title = :title, image_prompt = :image_prompt WHERE id = :id AND teacher_id = :teacher_id")
                    ->execute([
                        'analysis' => json_encode($analysis, JSON_UNESCAPED_UNICODE),
                        'title' => (string)($analysis['title'] ?? null),
                        'image_prompt' => (string)($analysis['image_prompt'] ?? null),
                        'id' => $draftId,
                        'teacher_id' => $teacherId,
                    ]);
                return ['ok' => true, 'done' => false, 'draft_id' => $draftId, 'status' => 'analysis_done', 'status_label' => 'Inhalte wurden strukturiert. Die Fragen werden jetzt erstellt…'];
            }

            $blockSize = elevaro_teacher_ai_generation_block_size($mode);
            $targetBlocks = elevaro_teacher_ai_generation_block_count($mode);
            if (count($blocks) < $targetBlocks) {
                $blockIndex = count($blocks);
                $prompt = elevaro_teacher_ai_split_build_questions_prompt($class, $analysis, $blockIndex, $blockSize, $mode, $sourceText, $extraPrompt, $allQuestions);
                $content = [['type' => 'input_text', 'text' => $prompt]];
                $content = array_merge($content, elevaro_teacher_ai_responses_content_for_material($files));
                $system = 'Du bist ein präziser Quizautor. Erzeuge ausschließlich valides JSON. Jede Frage muss quellengebunden aus dem Material ableitbar sein. Keine Wiederholungen.';
                $result = elevaro_openai_responses_json($system, $content, elevaro_teacher_ai_questions_block_schema(), 0.2, 90);
                $block = $result['json'];
                $block['block'] = $blockIndex + 1;
                $blocks[] = $block;
                foreach (($block['questions'] ?? []) as $q) $allQuestions[] = $q;

                elevaro_teacher_ai_wizard_db()->prepare("UPDATE teacher_ai_quiz_drafts SET question_blocks_json = :blocks, generation_step = :step WHERE id = :id AND teacher_id = :teacher_id")
                    ->execute([
                        'blocks' => json_encode($blocks, JSON_UNESCAPED_UNICODE),
                        'step' => count($blocks) < $targetBlocks ? 'questions_' . (count($blocks) + 1) : 'assemble',
                        'id' => $draftId,
                        'teacher_id' => $teacherId,
                    ]);
                $progress = 25 + (int)round((count($blocks) / max(1, $targetBlocks)) * 62);
                return ['ok' => true, 'done' => false, 'draft_id' => $draftId, 'status' => 'questions_' . count($blocks), 'progress' => $progress, 'status_label' => ($mode === 'listening' ? 'Hörabschnitt ' : 'Fragenblock ') . count($blocks) . '/' . $targetBlocks . ' ist fertig…'];
            }

            if (($draft['generation_step'] ?? '') !== 'plausibility') {
                elevaro_teacher_ai_wizard_db()->prepare("UPDATE teacher_ai_quiz_drafts SET generation_step = 'plausibility' WHERE id = :id AND teacher_id = :teacher_id")
                    ->execute(['id' => $draftId, 'teacher_id' => $teacherId]);
                return ['ok' => true, 'done' => false, 'draft_id' => $draftId, 'status' => 'plausibility', 'progress' => 93, 'status_label' => 'Prüfe fachliche Richtigkeit und Plausibilität…'];
            }

            $plausibility = elevaro_teacher_ai_apply_plausibility_review($analysis, $allQuestions, $mode, $files);
            $checkedQuestions = $plausibility['questions'];
            $payload = elevaro_teacher_ai_split_base_payload($analysis, $mode, $checkedQuestions);
            $payload = elevaro_teacher_ai_generate_listening_preview_audio($payload);
            $payload['plausibility_review'] = $plausibility['review'];
            $stmt = elevaro_teacher_ai_wizard_db()->prepare("UPDATE teacher_ai_quiz_drafts
                SET status = 'draft', generation_step = 'done', generated_payload_json = :payload, source_title = :title, image_prompt = :image_prompt, image_status = 'pending'
                WHERE id = :id AND teacher_id = :teacher_id");
            $stmt->execute([
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'title' => $payload['title'] ?? null,
                'image_prompt' => $payload['image_prompt'] ?? null,
                'id' => $draftId,
                'teacher_id' => $teacherId,
            ]);
            return ['ok' => true, 'done' => true, 'draft_id' => $draftId, 'payload' => $payload];
        } catch (Throwable $e) {
            elevaro_teacher_ai_wizard_db()->prepare("UPDATE teacher_ai_quiz_drafts SET status = 'failed', generation_error = :error WHERE id = :id AND teacher_id = :teacher_id")
                ->execute(['error' => $e->getMessage(), 'id' => $draftId, 'teacher_id' => $teacherId]);
            throw $e;
        }
    }
}

