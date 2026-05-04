<?php
/**
 * Curriculum Seeder fuer Elevaro / Quizportal
 *
 * Ablage:  /httpdocs/scripts/seed_curriculum_existing_schema.php
 * Start:   php scripts/seed_curriculum_existing_schema.php --state=BW --limit=2 --dry-run
 * Echt:    php scripts/seed_curriculum_existing_schema.php --state=BW --limit=2
 * Alle:    php scripts/seed_curriculum_existing_schema.php --all --limit=50
 *
 * Erwartete Config:
 *   /config/db.php     return ['host'=>..., 'database'=>..., 'username'=>..., 'password'=>..., 'charset'=>'utf8mb4'];
 *   /config/openai.php return ['api_key'=>'sk-...', 'model'=>'gpt-4.1-mini', 'seed_model'=>'gpt-5.5']; // seed_model optional
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

const SOURCE_URL_DEFAULT = 'https://www.bildungsserver.de/';
const IMPORT_VERSION = '2026-05-05-fixed-02';

$options = parseCliOptions($argv);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 1;
$onlyState = strtoupper((string)($options['state'] ?? 'BW'));
$runAllStates = isset($options['all']);
$force = isset($options['force']);

$root = dirname(__DIR__);
$dbConfigFile = $root . '/config/db.php';
$openAiConfigFile = $root . '/config/openai.php';

if (!is_file($dbConfigFile)) {
    fail("DB-Config nicht gefunden: {$dbConfigFile}");
}
if (!is_file($openAiConfigFile)) {
    fail("OpenAI-Config nicht gefunden: {$openAiConfigFile}");
}

$dbConfig = require $dbConfigFile;
$openAiConfig = require $openAiConfigFile;

$pdo = createPdo($dbConfig);
$apiKey = (string)($openAiConfig['api_key'] ?? '');
if ($apiKey === '' || str_starts_with($apiKey, 'sk-xxxxxxxx')) {
    fail('OpenAI API-Key fehlt oder ist noch ein Platzhalter in config/openai.php.');
}

// Fuer diesen Seeder bewusst ein besseres Modell verwenden.
// Falls du es in config/openai.php steuern willst: 'seed_model' => 'gpt-5.5'
$model = (string)($options['model'] ?? $openAiConfig['seed_model'] ?? 'gpt-5.5');
if ($model === 'gpt-4.1-mini') {
    $model = 'gpt-5.5';
}

ensureTables($pdo);

$states = getStates();
$jobs = buildJobs($states, $runAllStates ? null : $onlyState);

println('Curriculum Seeder gestartet');
println('Model: ' . $model);
println('Dry-run: ' . ($dryRun ? 'ja' : 'nein'));
println('Limit: ' . $limit);
println('Jobs gesamt nach Filter: ' . count($jobs));
println(str_repeat('-', 80));

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
        $payload = callOpenAi($apiKey, $model, $prompt);
        $data = parseOpenAiJson($payload);
        validateResponse($data);

        if ($dryRun) {
            println('  Domains: ' . count($data['domains']));
            foreach ($data['domains'] as $domain) {
                println('  - ' . $domain['domain_title'] . ' (' . count($domain['topics']) . ' Themen)');
            }
            updateImportRun($pdo, $runId, 'dry_run', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), null);
            continue;
        }

        $count = importCurriculumContent($pdo, $job, $data);
        updateImportRun($pdo, $runId, 'success', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), null, $count);
        println('  importiert/aktualisiert: ' . $count . ' Themen');
    } catch (Throwable $e) {
        $errors++;
        updateImportRun($pdo, $runId, 'error', null, $e->getMessage());
        println('  FEHLER: ' . $e->getMessage());
    }

    // Mini-Pause gegen Rate-Limits.
    usleep(250000);
}

println(str_repeat('-', 80));
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

    // Wenn explizit ein Socket in config/db.php gesetzt ist, diesen nutzen.
    if (!empty($config['unix_socket'])) {
        $dsn = sprintf(
            'mysql:unix_socket=%s;dbname=%s;charset=%s',
            $config['unix_socket'],
            $config['database'],
            $charset
        );
        return new PDO($dsn, $config['username'], $config['password'], $options);
    }

    $host = (string)$config['host'];
    $port = isset($config['port']) ? (int)$config['port'] : null;

    $buildDsn = static function (string $dsnHost) use ($config, $charset, $port): string {
        $dsn = sprintf('mysql:host=%s;', $dsnHost);
        if ($port) {
            $dsn .= 'port=' . $port . ';';
        }
        $dsn .= sprintf('dbname=%s;charset=%s', $config['database'], $charset);
        return $dsn;
    };

    try {
        return new PDO($buildDsn($host), $config['username'], $config['password'], $options);
    } catch (PDOException $e) {
        // Plesk/MySQL: localhost versucht oft Socket. Wenn dieser nicht gefunden wird, per TCP versuchen.
        if ($host === 'localhost' && str_contains($e->getMessage(), 'No such file or directory')) {
            return new PDO($buildDsn('127.0.0.1'), $config['username'], $config['password'], $options);
        }

        throw $e;
    }
}

function ensureTables(PDO $pdo): void
{
    // Deine Tabelle existiert bereits. Wir legen nur das Import-Log an.
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

    // Falls die Tabelle aus aelteren Dumps ohne sinnvolle Unique-Constraint kommt, ist das ok.
    // Der Seeder prueft selbst vor INSERT und updatet vorhandene Zeilen.
    $needed = ['curriculum_topics_content'];
    foreach ($needed as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        if (!$stmt->fetchColumn()) {
            fail("Tabelle {$table} existiert nicht. Bitte zuerst die bestehende Migration/SQL importieren.");
        }
    }
}

function getStates(): array
{
    return [
        'BW' => 'Baden-Wuerttemberg',
        'BY' => 'Bayern',
        'BE' => 'Berlin',
        'BB' => 'Brandenburg',
        'HB' => 'Bremen',
        'HH' => 'Hamburg',
        'HE' => 'Hessen',
        'MV' => 'Mecklenburg-Vorpommern',
        'NI' => 'Niedersachsen',
        'NW' => 'Nordrhein-Westfalen',
        'RP' => 'Rheinland-Pfalz',
        'SL' => 'Saarland',
        'SN' => 'Sachsen',
        'ST' => 'Sachsen-Anhalt',
        'SH' => 'Schleswig-Holstein',
        'TH' => 'Thueringen',
    ];
}

function getSchoolProfiles(): array
{
    return [
        'grundschule' => [
            'label' => 'Grundschule',
            'grades' => [
                ['key' => '1_2', 'from' => 1, 'to' => 2, 'label' => 'Klasse 1/2'],
                ['key' => '3_4', 'from' => 3, 'to' => 4, 'label' => 'Klasse 3/4'],
            ],
            'subjects' => [
                'deutsch' => 'Deutsch',
                'mathematik' => 'Mathematik',
                'sachunterricht' => 'Sachunterricht',
                'englisch' => 'Englisch',
                'kunst_werken' => 'Kunst/Werken',
                'musik' => 'Musik',
                'bewegung_spiel_und_sport' => 'Bewegung, Spiel und Sport',
                'religion_ethik' => 'Religion/Ethik',
            ],
        ],
        'hauptschule_werkrealschule' => [
            'label' => 'Hauptschule/Werkrealschule',
            'grades' => numericGradeJobs(5, 10),
            'subjects' => secondarySubjects(),
        ],
        'realschule' => [
            'label' => 'Realschule',
            'grades' => numericGradeJobs(5, 10),
            'subjects' => secondarySubjects(),
        ],
        'gemeinschaftsschule' => [
            'label' => 'Gemeinschaftsschule',
            'grades' => numericGradeJobs(5, 10),
            'subjects' => secondarySubjects(),
        ],
        'gymnasium' => [
            'label' => 'Gymnasium',
            'grades' => array_merge(numericGradeJobs(5, 10), [
                ['key' => 'kursstufe_1', 'from' => 11, 'to' => 11, 'label' => 'Kursstufe 1'],
                ['key' => 'kursstufe_2', 'from' => 12, 'to' => 12, 'label' => 'Kursstufe 2'],
            ]),
            'subjects' => array_merge(secondarySubjects(), [
                'latein' => 'Latein',
                'griechisch' => 'Griechisch',
                'informatik' => 'Informatik',
                'nwt' => 'Naturwissenschaft und Technik',
                'wirtschaft' => 'Wirtschaft',
            ]),
        ],
        'berufliches_gymnasium' => [
            'label' => 'Berufliches Gymnasium',
            'grades' => [
                ['key' => 'eingangsklasse', 'from' => 11, 'to' => 11, 'label' => 'Eingangsklasse'],
                ['key' => 'kursstufe_1', 'from' => 12, 'to' => 12, 'label' => 'Kursstufe 1'],
                ['key' => 'kursstufe_2', 'from' => 13, 'to' => 13, 'label' => 'Kursstufe 2'],
            ],
            'subjects' => [
                'deutsch' => 'Deutsch',
                'mathematik' => 'Mathematik',
                'englisch' => 'Englisch',
                'geschichte_gemeinschaftskunde' => 'Geschichte/Gemeinschaftskunde',
                'religion_ethik' => 'Religion/Ethik',
                'biologie' => 'Biologie',
                'chemie' => 'Chemie',
                'physik' => 'Physik',
                'informatik' => 'Informatik',
                'bwl' => 'Betriebswirtschaftslehre',
                'vwl' => 'Volkswirtschaftslehre',
                'profilfach_wirtschaft' => 'Profilfach Wirtschaft',
                'profilfach_technik' => 'Profilfach Technik',
                'profilfach_soziales' => 'Profilfach Soziales',
            ],
        ],
        'berufskolleg' => [
            'label' => 'Berufskolleg',
            'grades' => [
                ['key' => 'bk1', 'from' => null, 'to' => null, 'label' => 'BK I'],
                ['key' => 'bk2', 'from' => null, 'to' => null, 'label' => 'BK II'],
            ],
            'subjects' => [
                'deutsch' => 'Deutsch',
                'mathematik' => 'Mathematik',
                'englisch' => 'Englisch',
                'gemeinschaftskunde' => 'Gemeinschaftskunde',
                'religion_ethik' => 'Religion/Ethik',
                'bwl' => 'Betriebswirtschaftslehre',
                'vwl' => 'Volkswirtschaftslehre',
                'rechnungswesen' => 'Rechnungswesen',
                'datenverarbeitung' => 'Datenverarbeitung',
                'projektkompetenz' => 'Projektkompetenz',
            ],
        ],
        'sbbz' => [
            'label' => 'Sonderpaedagogisches Bildungs- und Beratungszentrum',
            'grades' => [
                ['key' => 'basisstufe', 'from' => null, 'to' => null, 'label' => 'Basisstufe'],
                ['key' => 'hauptstufe', 'from' => null, 'to' => null, 'label' => 'Hauptstufe'],
                ['key' => 'berufsschulstufe', 'from' => null, 'to' => null, 'label' => 'Berufsschulstufe'],
            ],
            'subjects' => [
                'deutsch' => 'Deutsch/Kommunikation',
                'mathematik' => 'Mathematik',
                'sachunterricht' => 'Sachunterricht',
                'lebensgestaltung' => 'Lebensgestaltung',
                'bewegung' => 'Bewegung',
                'kunst_musik' => 'Kunst/Musik',
            ],
        ],
    ];
}

function secondarySubjects(): array
{
    return [
        'deutsch' => 'Deutsch',
        'mathematik' => 'Mathematik',
        'englisch' => 'Englisch',
        'franzoesisch' => 'Franzoesisch',
        'geschichte' => 'Geschichte',
        'geographie' => 'Geographie',
        'gemeinschaftskunde' => 'Gemeinschaftskunde',
        'wirtschaft_berufs_und_studienorientierung' => 'Wirtschaft/Berufs- und Studienorientierung',
        'biologie' => 'Biologie',
        'chemie' => 'Chemie',
        'physik' => 'Physik',
        'bnt' => 'Biologie, Naturphaenomene und Technik',
        'technik' => 'Technik',
        'aes' => 'Alltagskultur, Ernaehrung, Soziales',
        'bildende_kunst' => 'Bildende Kunst',
        'musik' => 'Musik',
        'sport' => 'Sport',
        'religion_ethik' => 'Religion/Ethik',
    ];
}

function numericGradeJobs(int $from, int $to): array
{
    $out = [];
    for ($i = $from; $i <= $to; $i++) {
        $out[] = ['key' => (string)$i, 'from' => $i, 'to' => $i, 'label' => 'Klasse ' . $i];
    }
    return $out;
}

function buildJobs(array $states, ?string $onlyState): array
{
    $jobs = [];
    $profiles = getSchoolProfiles();

    foreach ($states as $stateCode => $stateLabel) {
        if ($onlyState !== null && $stateCode !== $onlyState) {
            continue;
        }
        foreach ($profiles as $schoolKey => $profile) {
            foreach ($profile['grades'] as $grade) {
                foreach ($profile['subjects'] as $subjectKey => $subjectLabel) {
                    $jobs[] = [
                        'state_code' => $stateCode,
                        'state_label' => $stateLabel,
                        'school_type_key' => $schoolKey,
                        'school_type_label' => $profile['label'],
                        'grade_key' => $grade['key'],
                        'grade_label' => $grade['label'],
                        'grade_from' => $grade['from'],
                        'grade_to' => $grade['to'],
                        'subject_key' => $subjectKey,
                        'subject_label' => $subjectLabel,
                    ];
                }
            }
        }
    }

    return $jobs;
}

function buildPrompt(array $job): string
{
    return "Erstelle hochwertige, quiztaugliche Lehrplan-Themen fuer das deutsche Bundesland {$job['state_label']} ({$job['state_code']}).\n"
        . "Kontext:\n"
        . "- Schulart: {$job['school_type_label']} ({$job['school_type_key']})\n"
        . "- Stufe: {$job['grade_label']} ({$job['grade_key']})\n"
        . "- Fach: {$job['subject_label']} ({$job['subject_key']})\n\n"
        . "Ziel: Die Daten sollen spaeter als Grundlage fuer qualitative Schueler-Quizzes dienen.\n"
        . "Bitte keine Fantasie-Faecher und keine Uni-Inhalte. Formuliere allgemein lehrplannah, aber nicht als laengliches offizielles Zitat.\n"
        . "Erzeuge 4 bis 7 fachlich sinnvolle Domains/Oberbereiche. Pro Domain 4 bis 8 konkrete Topics.\n"
        . "Jedes Topic braucht eine klare Beschreibung, Kompetenzbereich, ein konkretes Lernziel und 3 bis 6 Subtopics als kurze Stichpunkte.\n"
        . "Vermeide Copyright-nahe Uebernahmen offizieller Lehrplantexte. Nutze eigene, kurze Formulierungen.\n"
        . "Die Ausgabe muss exakt dem JSON-Schema entsprechen.";
}

function callOpenAi(string $apiKey, string $model, string $prompt): array
{
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'domains' => [
                'type' => 'array',
                'minItems' => 2,
                'maxItems' => 8,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'domain_key' => ['type' => 'string'],
                        'domain_title' => ['type' => 'string'],
                        'topics' => [
                            'type' => 'array',
                            'minItems' => 3,
                            'maxItems' => 10,
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'properties' => [
                                    'topic_key' => ['type' => 'string'],
                                    'topic_title' => ['type' => 'string'],
                                    'topic_description' => ['type' => 'string'],
                                    'competence_area' => ['type' => 'string'],
                                    'learning_goal' => ['type' => 'string'],
                                    'difficulty_level' => ['type' => 'string', 'enum' => ['leicht', 'mittel', 'schwer']],
                                    'question_type_hint' => ['type' => 'string'],
                                    'subtopics' => [
                                        'type' => 'array',
                                        'minItems' => 2,
                                        'maxItems' => 8,
                                        'items' => ['type' => 'string'],
                                    ],
                                ],
                                'required' => ['topic_key', 'topic_title', 'topic_description', 'competence_area', 'learning_goal', 'difficulty_level', 'question_type_hint', 'subtopics'],
                            ],
                        ],
                    ],
                    'required' => ['domain_key', 'domain_title', 'topics'],
                ],
            ],
        ],
        'required' => ['domains'],
    ];

    $body = [
        'model' => $model,
        'input' => [
            [
                'role' => 'system',
                'content' => 'Du bist ein erfahrener deutscher Curriculum- und Didaktik-Redakteur fuer Schulquizze. Antworte ausschliesslich im geforderten JSON-Schema.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'curriculum_seed_response',
                'strict' => true,
                'schema' => $schema,
            ],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 180,
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        throw new RuntimeException('OpenAI Request fehlgeschlagen: ' . $curlError);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('OpenAI Antwort ist kein JSON. HTTP ' . $httpCode . ': ' . substr($raw, 0, 500));
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = $decoded['error']['message'] ?? $raw;
        throw new RuntimeException('OpenAI HTTP ' . $httpCode . ': ' . $message);
    }

    return $decoded;
}

function parseOpenAiJson(array $response): array
{
    $text = $response['output_text'] ?? null;

    if (!$text && isset($response['output']) && is_array($response['output'])) {
        foreach ($response['output'] as $output) {
            foreach (($output['content'] ?? []) as $content) {
                if (isset($content['text'])) {
                    $text = $content['text'];
                    break 2;
                }
            }
        }
    }

    if (!$text || !is_string($text)) {
        throw new RuntimeException('Konnte keinen JSON-Text aus der OpenAI Antwort extrahieren.');
    }

    $data = json_decode($text, true);
    if (!is_array($data)) {
        throw new RuntimeException('Structured Output konnte nicht geparst werden: ' . substr($text, 0, 500));
    }

    return $data;
}

function validateResponse(array $data): void
{
    if (!isset($data['domains']) || !is_array($data['domains'])) {
        throw new RuntimeException('Antwort enthaelt keine domains.');
    }
    foreach ($data['domains'] as $domain) {
        if (empty($domain['domain_title']) || empty($domain['topics']) || !is_array($domain['topics'])) {
            throw new RuntimeException('Domain unvollstaendig.');
        }
        foreach ($domain['topics'] as $topic) {
            foreach (['topic_title', 'topic_description', 'competence_area', 'learning_goal'] as $key) {
                if (empty($topic[$key])) {
                    throw new RuntimeException("Topic unvollstaendig: {$key} fehlt.");
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
        foreach ($domain['topics'] as $topic) {
            $topicKey = normalizeKey($topic['topic_key'] ?: $topic['topic_title']);
            if ($topicKey === '') {
                $topicKey = normalizeKey($domain['domain_title'] . '-' . $topic['topic_title']);
            }

            $description = trim($topic['topic_description']);
            $learningGoal = trim($topic['learning_goal'] ?? '');
            $difficulty = trim($topic['difficulty_level'] ?? 'mittel');
            $questionTypes = trim($topic['question_type_hint'] ?? '');
            $subtopics = isset($topic['subtopics']) && is_array($topic['subtopics']) ? $topic['subtopics'] : [];

            $fullDescription = $description;
            if ($learningGoal !== '') {
                $fullDescription .= "\n\nLernziel: " . $learningGoal;
            }
            if (!empty($subtopics)) {
                $fullDescription .= "\n\nSubtopics: " . implode('; ', array_map('trim', $subtopics));
            }
            if ($difficulty !== '') {
                $fullDescription .= "\n\nSchwierigkeit: " . $difficulty;
            }
            if ($questionTypes !== '') {
                $fullDescription .= "\n\nFragetypen: " . $questionTypes;
            }

            $existingId = findExistingContentId($pdo, $job, $topicKey);
            if ($existingId) {
                $stmt = $pdo->prepare("UPDATE curriculum_topics_content SET
                    topic_title = ?,
                    topic_description = ?,
                    competence_area = ?,
                    source_name = ?,
                    source_url = ?,
                    sort_order = ?,
                    is_active = 1,
                    updated_at = NOW()
                    WHERE id = ?");
                $stmt->execute([
                    trim($topic['topic_title']),
                    $fullDescription,
                    trim($topic['competence_area']),
                    sourceNameForState($job['state_label']),
                    SOURCE_URL_DEFAULT,
                    $sort,
                    $existingId,
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO curriculum_topics_content
                    (state_code, school_type_key, grade_key, grade_from, grade_to, subject_key, subject_label,
                     topic_key, topic_title, topic_description, competence_area, source_name, source_url, sort_order, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
                $stmt->execute([
                    $job['state_code'],
                    $job['school_type_key'],
                    $job['grade_key'],
                    $job['grade_from'],
                    $job['grade_to'],
                    $job['subject_key'],
                    $job['subject_label'],
                    $topicKey,
                    trim($topic['topic_title']),
                    $fullDescription,
                    trim($topic['competence_area']),
                    sourceNameForState($job['state_label']),
                    SOURCE_URL_DEFAULT,
                    $sort,
                ]);
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
        WHERE state_code = ? AND school_type_key = ? AND grade_key = ? AND subject_key = ? AND topic_key = ?
        LIMIT 1");
    $stmt->execute([$job['state_code'], $job['school_type_key'], $job['grade_key'], $job['subject_key'], $topicKey]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function sourceNameForState(string $stateLabel): string
{
    return 'Lehrplan/Bildungsplan ' . $stateLabel;
}

function createImportRun(PDO $pdo, array $job, string $fingerprint, string $model, string $prompt, string $status): int
{
    $stmt = $pdo->prepare("INSERT INTO curriculum_import_runs
        (state_code, school_type_key, grade_key, subject_key, fingerprint, model, prompt, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            model = VALUES(model),
            prompt = VALUES(prompt),
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

    $stmt = $pdo->prepare("SELECT id FROM curriculum_import_runs WHERE fingerprint = ? LIMIT 1");
    $stmt->execute([$fingerprint]);
    return (int)$stmt->fetchColumn();
}

function updateImportRun(PDO $pdo, int $runId, string $status, ?string $rawResponse, ?string $error, int $count = 0): void
{
    $stmt = $pdo->prepare("UPDATE curriculum_import_runs
        SET status = ?, raw_response = COALESCE(?, raw_response), error_message = ?, imported_count = ?, updated_at = NOW()
        WHERE id = ?");
    $stmt->execute([$status, $rawResponse, $error, $count, $runId]);
}

function importAlreadySuccessful(PDO $pdo, string $fingerprint): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM curriculum_import_runs WHERE fingerprint = ? AND status = 'success' LIMIT 1");
    $stmt->execute([$fingerprint]);
    return (bool)$stmt->fetchColumn();
}

function jobFingerprint(array $job, string $model): string
{
    return hash('sha256', implode('|', [
        IMPORT_VERSION,
        $model,
        $job['state_code'],
        $job['school_type_key'],
        $job['grade_key'],
        $job['subject_key'],
    ]));
}

function jobLabel(array $job): string
{
    return sprintf('%s / %s / %s / %s', $job['state_code'], $job['school_type_key'], $job['grade_key'], $job['subject_key']);
}

function normalizeKey(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $map = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a', 'ó' => 'o', 'ò' => 'o',
    ];
    $value = strtr($value, $map);
    $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? '';
    $value = trim($value, '_');
    return substr($value, 0, 180);
}

function println(string $message): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): void
{
    fwrite(STDERR, 'FEHLER: ' . $message . PHP_EOL);
    exit(1);
}
