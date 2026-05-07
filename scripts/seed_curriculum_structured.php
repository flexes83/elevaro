<?php
/**
 * Strukturierter Elevaro-Curriculum-Seeder
 *
 * Beispiele:
 *   php scripts/seed_curriculum_structured.php --list-jobs --state=BW --school-type=gymnasium --grade=6
 *   php scripts/seed_curriculum_structured.php --dry-run --state=BW --school-type=gymnasium --grade=6 --subject=englisch
 *   php scripts/seed_curriculum_structured.php --state=BW --school-type=gymnasium --grade=6 --subject=englisch
 *   php scripts/seed_curriculum_structured.php --state=BW --limit=20
 *
 * Wichtig:
 * - nutzt eine feste Schulstruktur-Matrix aus app/includes/curriculum_structure.php
 * - erzeugt keine unsinnigen Kombinationen
 * - schreibt zuerst content_status = draft
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

const IMPORT_VERSION = '2026-05-07-structured-curriculum-v1';
const SOURCE_URL_DEFAULT = 'https://www.bildungsserver.de/';

$root = dirname(__DIR__);

require_once $root . '/app/includes/curriculum_structure.php';

$options = parseCliOptions($argv);
$dryRun = isset($options['dry-run']);
$listJobs = isset($options['list-jobs']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 1;
$force = isset($options['force']);

$dbConfigFile = $root . '/config/db.php';
$openAiConfigFile = $root . '/config/openai.php';

if (!is_file($dbConfigFile)) {
    fail("DB-Config nicht gefunden: {$dbConfigFile}");
}

$dbConfig = require $dbConfigFile;
$pdo = createPdo($dbConfig);
ensureTables($pdo);

$onlyState = isset($options['state']) ? strtoupper((string)$options['state']) : 'BW';
$onlySchoolType = isset($options['school-type']) ? (string)$options['school-type'] : null;
$onlyGrade = isset($options['grade']) ? (string)$options['grade'] : null;
$onlySubject = isset($options['subject']) ? (string)$options['subject'] : null;

$jobs = elevaro_curriculum_build_jobs($onlyState, $onlySchoolType, $onlyGrade, $onlySubject);

if ($onlySubject !== null && $onlySchoolType !== null && $onlyGrade !== null) {
    if (!elevaro_curriculum_is_valid_combination($onlyState, $onlySchoolType, $onlyGrade, $onlySubject)) {
        fail("Ungueltige Kombination: {$onlyState} / {$onlySchoolType} / {$onlyGrade} / {$onlySubject}");
    }
}

if ($listJobs) {
    println('Valide Jobs: ' . count($jobs));
    foreach ($jobs as $job) {
        println(jobLabel($job));
    }
    exit(0);
}

if (!is_file($openAiConfigFile)) {
    fail("OpenAI-Config nicht gefunden: {$openAiConfigFile}");
}

$openAiConfig = require $openAiConfigFile;
$apiKey = (string)($openAiConfig['api_key'] ?? '');
if ($apiKey === '' || str_starts_with($apiKey, 'sk-xxxxxxxx')) {
    fail('OpenAI API-Key fehlt oder ist noch ein Platzhalter in config/openai.php.');
}

$model = (string)($options['model'] ?? $openAiConfig['seed_model'] ?? 'gpt-5.5');
$timeout = isset($options['timeout']) ? max(60, (int)$options['timeout']) : 300;
$maxOutputTokens = isset($options['max-output-tokens']) ? max(2000, (int)$options['max-output-tokens']) : 9000;
$complexity = (string)($options['complexity'] ?? 'compact'); // compact | full

println('Strukturierter Curriculum Seeder gestartet');
println('Model: ' . $model);
println('Dry-run: ' . ($dryRun ? 'ja' : 'nein'));
println('Jobs: ' . count($jobs));
println('Limit: ' . $limit);
println('Timeout: ' . $timeout . 's');
println('Max output tokens: ' . $maxOutputTokens);
println('Complexity: ' . $complexity);
println(str_repeat('-', 90));

$processed = 0;
$skipped = 0;
$errors = 0;

foreach ($jobs as $job) {
    if ($processed >= $limit) {
        break;
    }

    $fingerprint = jobFingerprint($job, $model);

    if (!$force && importAlreadySuccessful($pdo, $fingerprint)) {
        $skipped++;
        println('[skip] ' . jobLabel($job));
        continue;
    }

    $processed++;
    println('[job ' . $processed . '] ' . jobLabel($job));

    $prompt = buildPrompt($job);
    $runId = createImportRun($pdo, $job, $fingerprint, $model, $prompt, $dryRun ? 'dry_run' : 'pending');

    try {
        $response = callOpenAi($apiKey, $model, $prompt, $timeout, $maxOutputTokens, $complexity);
        $data = parseOpenAiJson($response);
        validateResponse($data);

        if ($dryRun) {
            println('  Domains: ' . count($data['domains']));
            foreach ($data['domains'] as $domain) {
                println('  - ' . $domain['title_short'] . ' (' . count($domain['topics']) . ' Themen)');
                foreach ($domain['topics'] as $topic) {
                    println('    · ' . $topic['title_short'] . ' / ' . ($topic['title_long'] ?? ''));
                    foreach (($topic['subtopics'] ?? []) as $sub) {
                        println('       - ' . $sub['title_short']);
                    }
                }
            }
            updateImportRun($pdo, $runId, 'dry_run', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), null);
            continue;
        }

        $count = importCurriculumContent($pdo, $job, $data);
        updateImportRun($pdo, $runId, 'success', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), null, $count);
        println('  importiert/aktualisiert: ' . $count . ' Eintraege');
    } catch (Throwable $e) {
        $errors++;
        updateImportRun($pdo, $runId, 'error', null, $e->getMessage());
        println('  FEHLER: ' . $e->getMessage());
    }

    usleep(250000);
}

println(str_repeat('-', 90));
println("Fertig. Verarbeitet: {$processed}, uebersprungen: {$skipped}, Fehler: {$errors}");
exit($errors > 0 ? 1 : 0);

function parseCliOptions(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $out[$k] = $v;
        } else {
            $out[$arg] = true;
        }
    }
    return $out;
}

function createPdo(array $config): PDO
{
    foreach (['host', 'database', 'username', 'password'] as $key) {
        if (!array_key_exists($key, $config)) {
            fail("DB-Config unvollstaendig: '{$key}' fehlt.");
        }
    }

    $charset = $config['charset'] ?? 'utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset}",
    ];

    if (!empty($config['unix_socket'])) {
        return new PDO(
            sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $config['unix_socket'], $config['database'], $charset),
            $config['username'],
            $config['password'],
            $options
        );
    }

    $dsn = sprintf('mysql:host=%s;', (string)$config['host']);
    if (!empty($config['port'])) {
        $dsn .= 'port=' . (int)$config['port'] . ';';
    }
    $dsn .= sprintf('dbname=%s;charset=%s', $config['database'], $charset);

    return new PDO($dsn, $config['username'], $config['password'], $options);
}

function ensureTables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS curriculum_import_runs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        state_code CHAR(2) NOT NULL,
        school_type_key VARCHAR(100) NOT NULL,
        grade_key VARCHAR(50) NOT NULL,
        subject_key VARCHAR(120) NOT NULL,
        fingerprint CHAR(64) NOT NULL,
        model VARCHAR(80) NOT NULL,
        prompt MEDIUMTEXT NULL,
        raw_response LONGTEXT NULL,
        status ENUM('pending','success','error','dry_run') NOT NULL DEFAULT 'pending',
        imported_count INT UNSIGNED NOT NULL DEFAULT 0,
        error_message TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_curriculum_import_fingerprint (fingerprint),
        KEY idx_curriculum_import_context (state_code, school_type_key, grade_key, subject_key, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach (['curriculum_topics_content', 'curriculum_topic_subtopics'] as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) {
            fail("Tabelle {$table} existiert nicht. Bitte zuerst die bestehende DB-Migration importieren.");
        }
    }

    ensureColumn($pdo, 'curriculum_topics_content', 'domain_key', "VARCHAR(180) NULL AFTER subject_label");
    ensureColumn($pdo, 'curriculum_topics_content', 'domain_title', "VARCHAR(255) NULL AFTER domain_key");
    ensureColumn($pdo, 'curriculum_topics_content', 'learning_goal', "TEXT NULL AFTER topic_description");
    ensureColumn($pdo, 'curriculum_topics_content', 'difficulty_level', "VARCHAR(20) NULL AFTER learning_goal");
    ensureColumn($pdo, 'curriculum_topics_content', 'question_type_hint', "VARCHAR(255) NULL AFTER difficulty_level");
    ensureColumn($pdo, 'curriculum_topics_content', 'title_short', "VARCHAR(160) NULL AFTER topic_title");
    ensureColumn($pdo, 'curriculum_topics_content', 'title_long', "VARCHAR(255) NULL AFTER title_short");
    ensureColumn($pdo, 'curriculum_topics_content', 'aliases_json', "JSON NULL AFTER title_long");
    ensureColumn($pdo, 'curriculum_topics_content', 'keywords_json', "JSON NULL AFTER aliases_json");
    ensureColumn($pdo, 'curriculum_topics_content', 'content_status', "ENUM('draft','review','approved') NOT NULL DEFAULT 'draft' AFTER keywords_json");

    ensureColumn($pdo, 'curriculum_topic_subtopics', 'title_short', "VARCHAR(160) NULL AFTER subtopic_title");
    ensureColumn($pdo, 'curriculum_topic_subtopics', 'title_long', "VARCHAR(255) NULL AFTER title_short");
    ensureColumn($pdo, 'curriculum_topic_subtopics', 'aliases_json', "JSON NULL AFTER title_long");
    ensureColumn($pdo, 'curriculum_topic_subtopics', 'keywords_json', "JSON NULL AFTER aliases_json");
    ensureColumn($pdo, 'curriculum_topic_subtopics', 'content_status', "ENUM('draft','review','approved') NOT NULL DEFAULT 'draft' AFTER keywords_json");
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
    $stmt->execute([$column]);
    if (!$stmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function buildPrompt(array $job): string
{
    return "Du erstellst eine strukturierte, quiztaugliche Themenstruktur fuer Elevaro.\n\n"
        . "Kontext:\n"
        . "- Bundesland: {$job['state_label']} ({$job['state_code']})\n"
        . "- Schulart: {$job['school_type_label']} ({$job['school_type_key']})\n"
        . "- Stufe: {$job['grade_label']} ({$job['grade_key']})\n"
        . "- Fach: {$job['subject_label']} ({$job['subject_key']})\n\n"
        . "Wichtig:\n"
        . "- Erstelle keine offiziellen Lehrplan-Zitate und kopiere keine Formulierungen.\n"
        . "- Nutze eigene, kurze, allgemein lehrplannahe Formulierungen.\n"
        . "- Die Kurzversion ist fuer UI/Suche: z.B. 'Brueche kuerzen'.\n"
        . "- Die Langversion ist fuer KI-Prompts: z.B. 'Brueche durch gemeinsame Teiler vereinfachen und die Gleichwertigkeit erkennen'.\n"
        . "- Domains sind Oberbereiche. Topics sind sichtbare Lehrplanthemen. Subtopics sind konkrete Skills/Unterthemen.\n"
        . "- Es geht nicht um vollstaendige amtliche Lehrplanabbildung, sondern um eine plausible, hochwertige Quiz-Navigation.\n"
        . "- Vermeide unpassende Inhalte fuer die Stufe.\n\n"
        . "Erzeuge im Modus compact eine erste stabile Themenbasis: 3 bis 5 Domains, pro Domain 3 bis 5 Topics, pro Topic 2 bis 4 Subtopics.\n"
        . "Erzeuge im Modus full: 4 bis 7 Domains, pro Domain 4 bis 8 Topics, pro Topic 3 bis 8 Subtopics.\n"
        . "Lieber weniger, dafür sauber, eindeutig und ohne Dopplungen.\n"
        . "Die Ausgabe muss exakt dem JSON-Schema entsprechen.";
}

function callOpenAi(string $apiKey, string $model, string $prompt, int $timeout = 300, int $maxOutputTokens = 9000, string $complexity = 'compact'): array
{
    $isFull = $complexity === 'full';
    $domainMax = $isFull ? 8 : 5;
    $topicMax = $isFull ? 10 : 6;
    $subtopicMax = $isFull ? 10 : 5;

    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'domains' => [
                'type' => 'array',
                'minItems' => 2,
                'maxItems' => $domainMax,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'key' => ['type' => 'string'],
                        'title_short' => ['type' => 'string'],
                        'title_long' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'topics' => [
                            'type' => 'array',
                            'minItems' => 3,
                            'maxItems' => $topicMax,
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'key' => ['type' => 'string'],
                                    'title_short' => ['type' => 'string'],
                                    'title_long' => ['type' => 'string'],
                                    'description' => ['type' => 'string'],
                                    'competence_area' => ['type' => 'string'],
                                    'learning_goal' => ['type' => 'string'],
                                    'difficulty_level' => ['type' => 'string', 'enum' => ['leicht', 'mittel', 'schwer']],
                                    'question_type_hint' => ['type' => 'string'],
                                    'aliases' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                        'maxItems' => 8,
                                    ],
                                    'keywords' => [
                                        'type' => 'array',
                                        'items' => ['type' => 'string'],
                                        'maxItems' => 12,
                                    ],
                                    'subtopics' => [
                                        'type' => 'array',
                                        'minItems' => 2,
                                        'maxItems' => $subtopicMax,
                                        'items' => [
                                            'type' => 'object',
                                            'additionalProperties' => false,
                                            'properties' => [
                                                'key' => ['type' => 'string'],
                                                'title_short' => ['type' => 'string'],
                                                'title_long' => ['type' => 'string'],
                                                'learning_goal' => ['type' => 'string'],
                                                'difficulty_level' => ['type' => 'string', 'enum' => ['leicht', 'mittel', 'schwer']],
                                                'question_type_hint' => ['type' => 'string'],
                                                'aliases' => [
                                                    'type' => 'array',
                                                    'items' => ['type' => 'string'],
                                                    'maxItems' => 8,
                                                ],
                                                'keywords' => [
                                                    'type' => 'array',
                                                    'items' => ['type' => 'string'],
                                                    'maxItems' => 12,
                                                ],
                                            ],
                                            'required' => ['key', 'title_short', 'title_long', 'learning_goal', 'difficulty_level', 'question_type_hint', 'aliases', 'keywords'],
                                        ],
                                    ],
                                ],
                                'required' => ['key', 'title_short', 'title_long', 'description', 'competence_area', 'learning_goal', 'difficulty_level', 'question_type_hint', 'aliases', 'keywords', 'subtopics'],
                            ],
                        ],
                    ],
                    'required' => ['key', 'title_short', 'title_long', 'description', 'topics'],
                ],
            ],
        ],
        'required' => ['domains'],
    ];

    $payload = [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => 'Du bist Curriculum-Architekt fuer eine deutsche Lernplattform. Antworte nur im verlangten JSON-Schema.',
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $prompt . "\n\nAktueller Modus: " . $complexity,
                    ],
                ],
            ],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'elevaro_curriculum_topics',
                'strict' => true,
                'schema' => $schema,
            ],
        ],
        'max_output_tokens' => $maxOutputTokens,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $timeout,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        throw new RuntimeException('OpenAI Request fehlgeschlagen' . ($errno ? ' (cURL ' . $errno . ')' : '') . ': ' . $error . '. Tipp: mit --complexity=compact, --timeout=300 oder kleinerem Modell erneut versuchen.');
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $data = json_decode($raw, true);
    if ($status >= 400) {
        throw new RuntimeException('OpenAI Fehler HTTP ' . $status . ': ' . mb_substr($raw, 0, 1200));
    }

    if (!is_array($data)) {
        throw new RuntimeException('OpenAI Antwort war kein JSON: ' . mb_substr($raw, 0, 1200));
    }

    return $data;
}

function parseOpenAiJson(array $response): array
{
    $content = '';

    if (isset($response['output_text']) && is_string($response['output_text'])) {
        $content = $response['output_text'];
    } else {
        foreach (($response['output'] ?? []) as $item) {
            foreach (($item['content'] ?? []) as $contentItem) {
                if (($contentItem['type'] ?? '') === 'output_text' && isset($contentItem['text'])) {
                    $content .= (string)$contentItem['text'];
                }
            }
        }
    }

    $content = trim($content);
    if ($content === '') {
        throw new RuntimeException('OpenAI Antwort enthielt keinen Text.');
    }

    $decoded = json_decode($content, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    $first = extractFirstJsonObject($content);
    if ($first !== null) {
        $decoded = json_decode($first, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    throw new RuntimeException('OpenAI Antwort war kein valides Curriculum-JSON: ' . mb_substr($content, 0, 1000));
}

function extractFirstJsonObject(string $content): ?string
{
    $start = strpos($content, '{');
    if ($start === false) {
        return null;
    }

    $depth = 0;
    $inString = false;
    $escape = false;
    $length = strlen($content);

    for ($i = $start; $i < $length; $i++) {
        $char = $content[$i];

        if ($escape) {
            $escape = false;
            continue;
        }

        if ($char === '\\') {
            $escape = true;
            continue;
        }

        if ($char === '"') {
            $inString = !$inString;
            continue;
        }

        if ($inString) {
            continue;
        }

        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($content, $start, $i - $start + 1);
            }
        }
    }

    return null;
}

function validateResponse(array $data): void
{
    if (empty($data['domains']) || !is_array($data['domains'])) {
        throw new RuntimeException('domains fehlt oder ist leer.');
    }

    foreach ($data['domains'] as $domain) {
        foreach (['key', 'title_short', 'title_long', 'topics'] as $field) {
            if (!array_key_exists($field, $domain)) {
                throw new RuntimeException("Domain-Feld fehlt: {$field}");
            }
        }

        if (empty($domain['topics']) || !is_array($domain['topics'])) {
            throw new RuntimeException('Domain ohne Topics: ' . ($domain['title_short'] ?? 'unbekannt'));
        }

        foreach ($domain['topics'] as $topic) {
            foreach (['key', 'title_short', 'title_long', 'description', 'learning_goal', 'subtopics'] as $field) {
                if (!array_key_exists($field, $topic)) {
                    throw new RuntimeException("Topic-Feld fehlt: {$field}");
                }
            }
        }
    }
}

function importCurriculumContent(PDO $pdo, array $job, array $data): int
{
    $count = 0;
    $sort = 1;

    foreach ($data['domains'] as $domain) {
        $domainKey = normalizeKey($domain['key'] ?: $domain['title_short']);
        $domainTitle = trim((string)$domain['title_short']);

        foreach ($domain['topics'] as $topic) {
            $topicKey = normalizeKey($topic['key'] ?: $topic['title_short']);
            if ($topicKey === '') {
                $topicKey = normalizeKey($domainTitle . '-' . $topic['title_short']);
            }

            $existingId = findExistingContentId($pdo, $job, $topicKey);

            $fields = [
                'state_code' => $job['state_code'],
                'school_type_key' => $job['school_type_key'],
                'grade_key' => $job['grade_key'],
                'grade_from' => $job['grade_from'],
                'grade_to' => $job['grade_to'],
                'subject_key' => $job['subject_key'],
                'subject_label' => $job['subject_label'],
                'domain_key' => $domainKey,
                'domain_title' => $domainTitle,
                'topic_key' => $topicKey,
                'topic_title' => trim((string)$topic['title_short']),
                'title_short' => trim((string)$topic['title_short']),
                'title_long' => trim((string)$topic['title_long']),
                'topic_description' => trim((string)$topic['description']),
                'learning_goal' => trim((string)$topic['learning_goal']),
                'difficulty_level' => trim((string)($topic['difficulty_level'] ?? 'mittel')),
                'question_type_hint' => trim((string)($topic['question_type_hint'] ?? 'Multiple Choice')),
                'competence_area' => trim((string)($topic['competence_area'] ?? '')),
                'aliases_json' => json_encode(array_values((array)($topic['aliases'] ?? [])), JSON_UNESCAPED_UNICODE),
                'keywords_json' => json_encode(array_values((array)($topic['keywords'] ?? [])), JSON_UNESCAPED_UNICODE),
                'source_name' => 'KI-Strukturvorschlag / ' . $job['state_label'],
                'source_url' => SOURCE_URL_DEFAULT,
                'sort_order' => $sort,
                'content_status' => 'draft',
            ];

            if ($existingId) {
                $set = [];
                foreach ($fields as $column => $_) {
                    if (in_array($column, ['state_code', 'school_type_key', 'grade_key', 'grade_from', 'grade_to', 'subject_key', 'subject_label', 'topic_key'], true)) {
                        continue;
                    }
                    $set[] = "{$column} = :{$column}";
                }
                $fields['id'] = $existingId;
                $stmt = $pdo->prepare("UPDATE curriculum_topics_content SET " . implode(', ', $set) . ", is_active = 1, updated_at = NOW() WHERE id = :id");
                $stmt->execute($fields);
                $contentId = (int)$existingId;
            } else {
                $columns = array_keys($fields);
                $placeholders = array_map(static fn($column) => ':' . $column, $columns);
                $stmt = $pdo->prepare("INSERT INTO curriculum_topics_content (" . implode(', ', $columns) . ", is_active, created_at) VALUES (" . implode(', ', $placeholders) . ", 1, NOW())");
                $stmt->execute($fields);
                $contentId = (int)$pdo->lastInsertId();
            }

            $subSort = 1;
            foreach ((array)($topic['subtopics'] ?? []) as $subtopic) {
                $subtopicKey = normalizeKey(($subtopic['key'] ?? '') ?: ($subtopic['title_short'] ?? ''));
                if ($subtopicKey === '') {
                    continue;
                }

                $stmt = $pdo->prepare("INSERT INTO curriculum_topic_subtopics
                    (curriculum_topic_content_id, subtopic_key, subtopic_title, title_short, title_long, learning_goal, difficulty_level, question_type_hint, aliases_json, keywords_json, sort_order, content_status, is_active, created_at)
                    VALUES
                    (:topic_id, :subtopic_key, :subtopic_title, :title_short, :title_long, :learning_goal, :difficulty_level, :question_type_hint, :aliases_json, :keywords_json, :sort_order, 'draft', 1, NOW())
                    ON DUPLICATE KEY UPDATE
                        subtopic_title = VALUES(subtopic_title),
                        title_short = VALUES(title_short),
                        title_long = VALUES(title_long),
                        learning_goal = VALUES(learning_goal),
                        difficulty_level = VALUES(difficulty_level),
                        question_type_hint = VALUES(question_type_hint),
                        aliases_json = VALUES(aliases_json),
                        keywords_json = VALUES(keywords_json),
                        sort_order = VALUES(sort_order),
                        content_status = 'draft',
                        is_active = 1,
                        updated_at = NOW()");

                $stmt->execute([
                    'topic_id' => $contentId,
                    'subtopic_key' => $subtopicKey,
                    'subtopic_title' => trim((string)$subtopic['title_short']),
                    'title_short' => trim((string)$subtopic['title_short']),
                    'title_long' => trim((string)$subtopic['title_long']),
                    'learning_goal' => trim((string)$subtopic['learning_goal']),
                    'difficulty_level' => trim((string)($subtopic['difficulty_level'] ?? 'mittel')),
                    'question_type_hint' => trim((string)($subtopic['question_type_hint'] ?? 'Multiple Choice')),
                    'aliases_json' => json_encode(array_values((array)($subtopic['aliases'] ?? [])), JSON_UNESCAPED_UNICODE),
                    'keywords_json' => json_encode(array_values((array)($subtopic['keywords'] ?? [])), JSON_UNESCAPED_UNICODE),
                    'sort_order' => $subSort++,
                ]);
                $count++;
            }

            $sort++;
            $count++;
        }
    }

    return $count;
}

function findExistingContentId(PDO $pdo, array $job, string $topicKey): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM curriculum_topics_content
        WHERE state_code = ?
          AND school_type_key = ?
          AND grade_key = ?
          AND subject_key = ?
          AND topic_key = ?
        LIMIT 1");
    $stmt->execute([
        $job['state_code'],
        $job['school_type_key'],
        $job['grade_key'],
        $job['subject_key'],
        $topicKey,
    ]);

    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function jobFingerprint(array $job, string $model): string
{
    return hash('sha256', IMPORT_VERSION . '|' . $model . '|' . implode('|', [
        $job['state_code'],
        $job['school_type_key'],
        $job['grade_key'],
        $job['subject_key'],
    ]));
}

function importAlreadySuccessful(PDO $pdo, string $fingerprint): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM curriculum_import_runs WHERE fingerprint = ? AND status = 'success'");
    $stmt->execute([$fingerprint]);
    return (int)$stmt->fetchColumn() > 0;
}

function createImportRun(PDO $pdo, array $job, string $fingerprint, string $model, string $prompt, string $status): int
{
    $stmt = $pdo->prepare("INSERT INTO curriculum_import_runs
        (state_code, school_type_key, grade_key, subject_key, fingerprint, model, prompt, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            prompt = VALUES(prompt),
            model = VALUES(model),
            status = VALUES(status),
            error_message = NULL,
            updated_at = NOW()");

    $stmt->execute([
        $job['state_code'],
        $job['school_type_key'],
        $job['grade_key'],
        $job['subject_key'],
        $fingerprint,
        $model,
        $prompt,
        $status,
    ]);

    return (int)$pdo->lastInsertId();
}

function updateImportRun(PDO $pdo, int $runId, string $status, ?string $rawResponse, ?string $errorMessage, int $importedCount = 0): void
{
    if ($runId > 0) {
        $stmt = $pdo->prepare("UPDATE curriculum_import_runs
            SET status = ?, raw_response = ?, error_message = ?, imported_count = ?, updated_at = NOW()
            WHERE id = ?");
        $stmt->execute([$status, $rawResponse, $errorMessage, $importedCount, $runId]);
        return;
    }

    // Bei ON DUPLICATE kann lastInsertId 0 sein. Dann reicht der Log in stdout.
}

function normalizeKey(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $map = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'é' => 'e', 'è' => 'e', 'ê' => 'e',
        'á' => 'a', 'à' => 'a', 'â' => 'a',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
        'í' => 'i', 'ì' => 'i', 'î' => 'i',
    ];
    $value = strtr($value, $map);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    $value = trim((string)$value, '_');
    return mb_substr($value, 0, 160, 'UTF-8');
}

function jobLabel(array $job): string
{
    return "{$job['state_code']} / {$job['school_type_key']} / {$job['grade_key']} / {$job['subject_key']}";
}

function println(string $message): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): never
{
    fwrite(STDERR, 'FEHLER: ' . $message . PHP_EOL);
    exit(1);
}
