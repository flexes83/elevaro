<?php
/**
 * Elevaro Curriculum Seeder
 *
 * Legt/erweitert die vorhandenen Curriculum-Tabellen und befüllt sie per OpenAI API.
 * Ablage empfohlen: scripts/seed_curriculum_existing_schema.php
 *
 * Beispiele:
 *   php scripts/seed_curriculum_existing_schema.php --state=BW --limit=5 --dry-run
 *   php scripts/seed_curriculum_existing_schema.php --state=BW --limit=20
 *   php scripts/seed_curriculum_existing_schema.php --all --limit=100
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("This script is CLI only.\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);

$root = dirname(__DIR__);
$scriptDir = __DIR__;

// -----------------------------------------------------------------------------
// CLI Options
// -----------------------------------------------------------------------------
$options = getopt('', [
    'state::',       // BW, BY, NRW ...
    'all',           // alle Bundesländer
    'limit::',       // max. Kombinationen
    'dry-run',       // keine DB-Writes, keine API-Writes? API wird auch übersprungen
    'force',         // vorhandene Imports erneut abfragen
    'model::',       // OpenAI model override
    'sleep::',       // Pause zwischen API Calls in Sekunden
]);

$dryRun = array_key_exists('dry-run', $options);
$force = array_key_exists('force', $options);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 0;
$model = $options['model'] ?? getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini';
$sleepSeconds = isset($options['sleep']) ? max(0, (int)$options['sleep']) : 1;

// -----------------------------------------------------------------------------
// Config laden
// -----------------------------------------------------------------------------
$possibleConfigFiles = [
    $root . '/app/config.php',
    $root . '/config/config.php',
    $root . '/config/db.php',
    $scriptDir . '/../app/config.php',
    $scriptDir . '/../config/config.php',
    $scriptDir . '/../config/database.php',
];

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/openai.php';

foreach ($possibleConfigFiles as $file) {
    if (is_file($file)) {
        require_once $file;
    }
}

$possibleOpenAiConfigFiles = [
    $root . '/config/openaiconfig.php',
    $root . '/app/openaiconfig.php',
    $scriptDir . '/../config/openaiconfig.php',
    $scriptDir . '/../app/openaiconfig.php',
];

foreach ($possibleOpenAiConfigFiles as $file) {
    if (is_file($file)) {
        require_once $file;
    }
}

$pdo = $pdo ?? $db ?? null;
if (!$pdo instanceof PDO) {
    $pdo = createPdoFromKnownConstants();
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$apiKey = resolveOpenAiApiKey();
if (!$apiKey && !$dryRun) {
    throw new RuntimeException('Kein OpenAI API-Key gefunden. Erwartet OPENAI_API_KEY in ENV oder config/openaiconfig.php.');
}

// -----------------------------------------------------------------------------
// Basisdaten
// -----------------------------------------------------------------------------
$states = [
    'BW' => 'Baden-Württemberg',
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
    'TH' => 'Thüringen',
];

$subjects = [
    'deutsch' => 'Deutsch',
    'mathematik' => 'Mathematik',
    'englisch' => 'Englisch',
    'sachunterricht' => 'Sachunterricht',
    'biologie' => 'Biologie',
    'chemie' => 'Chemie',
    'physik' => 'Physik',
    'geschichte' => 'Geschichte',
    'geographie' => 'Geographie',
    'gemeinschaftskunde' => 'Gemeinschaftskunde',
    'wirtschaft' => 'Wirtschaft',
    'informatik' => 'Informatik',
    'franzoesisch' => 'Französisch',
    'spanisch' => 'Spanisch',
    'religion_ethik' => 'Religion / Ethik',
    'kunst' => 'Kunst',
    'musik' => 'Musik',
    'sport' => 'Sport',
    'bwl' => 'BWL',
    'vwl' => 'VWL',
    'rechnungswesen' => 'Rechnungswesen',
];

$schoolPlans = [
    'grundschule' => [
        'label' => 'Grundschule',
        'grades' => [['key' => '1_2', 'from' => 1, 'to' => 2], ['key' => '3_4', 'from' => 3, 'to' => 4]],
        'subjects' => ['deutsch', 'mathematik', 'sachunterricht', 'englisch', 'religion_ethik', 'kunst', 'musik', 'sport'],
    ],
    'hauptschule' => [
        'label' => 'Hauptschule',
        'grades' => rangeGrades(5, 10),
        'subjects' => ['deutsch','mathematik','englisch','biologie','chemie','physik','geschichte','geographie','gemeinschaftskunde','wirtschaft','informatik','religion_ethik','kunst','musik','sport'],
    ],
    'werkrealschule' => [
        'label' => 'Werkrealschule',
        'grades' => rangeGrades(5, 10),
        'subjects' => ['deutsch','mathematik','englisch','biologie','chemie','physik','geschichte','geographie','gemeinschaftskunde','wirtschaft','informatik','religion_ethik','kunst','musik','sport'],
    ],
    'realschule' => [
        'label' => 'Realschule',
        'grades' => rangeGrades(5, 10),
        'subjects' => ['deutsch','mathematik','englisch','franzoesisch','biologie','chemie','physik','geschichte','geographie','gemeinschaftskunde','wirtschaft','informatik','religion_ethik','kunst','musik','sport'],
    ],
    'gemeinschaftsschule' => [
        'label' => 'Gemeinschaftsschule',
        'grades' => rangeGrades(5, 10),
        'subjects' => ['deutsch','mathematik','englisch','franzoesisch','biologie','chemie','physik','geschichte','geographie','gemeinschaftskunde','wirtschaft','informatik','religion_ethik','kunst','musik','sport'],
    ],
    'gymnasium' => [
        'label' => 'Gymnasium',
        'grades' => array_merge(rangeGrades(5, 10), [['key' => 'kursstufe_1', 'from' => 11, 'to' => 11], ['key' => 'kursstufe_2', 'from' => 12, 'to' => 12]]),
        'subjects' => ['deutsch','mathematik','englisch','franzoesisch','spanisch','biologie','chemie','physik','geschichte','geographie','gemeinschaftskunde','wirtschaft','informatik','religion_ethik','kunst','musik','sport'],
    ],
    'berufliches_gymnasium' => [
        'label' => 'Berufliches Gymnasium',
        'grades' => [['key' => 'eingangsklasse', 'from' => 11, 'to' => 11], ['key' => 'jahrgangsstufe_1', 'from' => 12, 'to' => 12], ['key' => 'jahrgangsstufe_2', 'from' => 13, 'to' => 13]],
        'subjects' => ['deutsch','mathematik','englisch','biologie','chemie','physik','geschichte','gemeinschaftskunde','wirtschaft','informatik','bwl','vwl','rechnungswesen','religion_ethik','sport'],
    ],
    'berufskolleg' => [
        'label' => 'Berufskolleg',
        'grades' => [['key' => 'bk1', 'from' => 11, 'to' => 11], ['key' => 'bk2', 'from' => 12, 'to' => 12]],
        'subjects' => ['deutsch','mathematik','englisch','wirtschaft','informatik','bwl','vwl','rechnungswesen','gemeinschaftskunde','religion_ethik','sport'],
    ],
    'sbbz' => [
        'label' => 'SBBZ',
        'grades' => rangeGrades(1, 10),
        'subjects' => ['deutsch','mathematik','sachunterricht','englisch','biologie','geschichte','geographie','religion_ethik','kunst','musik','sport'],
    ],
];

// -----------------------------------------------------------------------------
// Schema vorbereiten
// -----------------------------------------------------------------------------
ensureSchema($pdo);

$targetStateCodes = [];
if (array_key_exists('all', $options)) {
    $targetStateCodes = array_keys($states);
} else {
    $targetStateCodes = [strtoupper((string)($options['state'] ?? 'BW'))];
}

$processed = 0;
$skipped = 0;
$errors = 0;

foreach ($targetStateCodes as $stateCode) {
    if (!isset($states[$stateCode])) {
        echo "[skip] Unbekanntes Bundesland: {$stateCode}\n";
        continue;
    }

    $stateId = resolveStateId($pdo, $stateCode, $states[$stateCode]);
    if (!$stateId) {
        echo "[skip] state_id für {$stateCode} nicht gefunden. Bitte states/Bundesland-Tabelle prüfen.\n";
        continue;
    }

    foreach ($schoolPlans as $schoolKey => $schoolPlan) {
        $schoolTypeId = resolveSchoolTypeId($pdo, $schoolKey, $schoolPlan['label']);
        if (!$schoolTypeId) {
            echo "[skip] school_type_id für {$schoolKey} nicht gefunden.\n";
            continue;
        }

        foreach ($schoolPlan['grades'] as $gradeInfo) {
            foreach ($schoolPlan['subjects'] as $subjectKey) {
                $subjectId = resolveSubjectId($pdo, $subjectKey, $subjects[$subjectKey] ?? $subjectKey);
                if (!$subjectId) {
                    echo "[skip] subject_id für {$subjectKey} nicht gefunden.\n";
                    continue;
                }

                if ($limit > 0 && $processed >= $limit) {
                    echo "\nFertig: Limit {$limit} erreicht.\n";
                    printStats($processed, $skipped, $errors);
                    exit(0);
                }

                $gradeKey = $gradeInfo['key'];
                $grade = (int)$gradeInfo['from'];
                $schoolTypeLevelId = resolveSchoolTypeLevelId($pdo, $schoolTypeId, $gradeKey, $grade);
                $sourceId = getOrCreateSource($pdo, $stateId, $schoolTypeId, $subjectId, $gradeInfo, $stateCode, $schoolPlan['label'], $subjects[$subjectKey] ?? $subjectKey, $dryRun);

                if (!$force && importAlreadySuccessful($pdo, $stateCode, $schoolKey, $gradeKey, $subjectKey)) {
                    $skipped++;
                    echo "[skip] {$stateCode} / {$schoolKey} / {$gradeKey} / {$subjectKey}\n";
                    continue;
                }

                echo "[seed] {$stateCode} / {$schoolKey} / {$gradeKey} / {$subjectKey}\n";

                if ($dryRun) {
                    $processed++;
                    continue;
                }

                $runId = createImportRun($pdo, $stateCode, $schoolKey, $gradeKey, $subjectKey, $model);

                try {
                    $payload = generateCurriculumPayload(
                        $apiKey,
                        $model,
                        $stateCode,
                        $states[$stateCode],
                        $schoolKey,
                        $schoolPlan['label'],
                        $gradeInfo,
                        $subjectKey,
                        $subjects[$subjectKey] ?? $subjectKey
                    );

                    importPayload(
                        $pdo,
                        $payload,
                        [
                            'state_id' => $stateId,
                            'state_code' => $stateCode,
                            'school_type_id' => $schoolTypeId,
                            'school_type_key' => $schoolKey,
                            'school_type_label' => $schoolPlan['label'],
                            'school_type_level_id' => $schoolTypeLevelId,
                            'subject_id' => $subjectId,
                            'subject_key' => $subjectKey,
                            'subject_label' => $subjects[$subjectKey] ?? $subjectKey,
                            'grade_key' => $gradeKey,
                            'grade' => $grade,
                            'grade_from' => $gradeInfo['from'],
                            'grade_to' => $gradeInfo['to'],
                            'source_id' => $sourceId,
                        ]
                    );

                    finishImportRun($pdo, $runId, 'success', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                    $processed++;
                    if ($sleepSeconds > 0) {
                        sleep($sleepSeconds);
                    }
                } catch (Throwable $e) {
                    $errors++;
                    finishImportRun($pdo, $runId, 'error', null, $e->getMessage());
                    echo "[error] " . $e->getMessage() . "\n";
                }
            }
        }
    }
}

printStats($processed, $skipped, $errors);

// -----------------------------------------------------------------------------
// Funktionen
// -----------------------------------------------------------------------------
function createPdoFromKnownConstants(): PDO
{
    $host = defined('DB_HOST') ? DB_HOST : (defined('MYSQL_HOST') ? MYSQL_HOST : 'localhost');
    $dbName = defined('DB_NAME') ? DB_NAME : (defined('MYSQL_DATABASE') ? MYSQL_DATABASE : null);
    $user = defined('DB_USER') ? DB_USER : (defined('MYSQL_USER') ? MYSQL_USER : null);
    $pass = defined('DB_PASS') ? DB_PASS : (defined('DB_PASSWORD') ? DB_PASSWORD : '');
    $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

    if (!$dbName || !$user) {
        throw new RuntimeException('Keine PDO-Verbindung und keine DB_* Konstanten gefunden.');
    }

    return new PDO("mysql:host={$host};dbname={$dbName};charset={$charset}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function resolveOpenAiApiKey(): ?string
{
    if (getenv('OPENAI_API_KEY')) {
        return getenv('OPENAI_API_KEY');
    }

    foreach (['OPENAI_API_KEY', 'OPENAI_KEY', 'OPENAI_SECRET_KEY'] as $constant) {
        if (defined($constant)) {
            return (string)constant($constant);
        }
    }

    foreach (['openaiApiKey', 'openai_api_key', 'apiKey'] as $varName) {
        if (isset($GLOBALS[$varName]) && is_string($GLOBALS[$varName]) && $GLOBALS[$varName] !== '') {
            return $GLOBALS[$varName];
        }
    }

    return null;
}

function rangeGrades(int $from, int $to): array
{
    $out = [];
    for ($i = $from; $i <= $to; $i++) {
        $out[] = ['key' => (string)$i, 'from' => $i, 'to' => $i];
    }
    return $out;
}

function ensureSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS curriculum_import_runs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        state_code CHAR(2) NOT NULL,
        school_type_key VARCHAR(100) NOT NULL,
        grade_key VARCHAR(50) NOT NULL,
        subject_key VARCHAR(120) NOT NULL,
        model VARCHAR(120) DEFAULT NULL,
        status ENUM('pending','success','error') NOT NULL DEFAULT 'pending',
        raw_response LONGTEXT DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uniq_curriculum_import (state_code, school_type_key, grade_key, subject_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    addColumnIfMissing($pdo, 'curriculum_topics_content', 'topic_id', "INT(10) UNSIGNED DEFAULT NULL");
    addColumnIfMissing($pdo, 'curriculum_topics_content', 'content_key', "VARCHAR(180) DEFAULT NULL");
    addColumnIfMissing($pdo, 'curriculum_topics_content', 'content_title', "VARCHAR(255) DEFAULT NULL");
    addColumnIfMissing($pdo, 'curriculum_topics_content', 'learning_goal', "TEXT DEFAULT NULL");
    addColumnIfMissing($pdo, 'curriculum_topics_content', 'difficulty_level', "ENUM('leicht','mittel','schwer') DEFAULT 'mittel'");
    addColumnIfMissing($pdo, 'curriculum_topics_content', 'question_type_hint', "VARCHAR(255) DEFAULT NULL");
    addColumnIfMissing($pdo, 'curriculum_topics_content', 'example_prompt', "TEXT DEFAULT NULL");
    addColumnIfMissing($pdo, 'curriculum_topics_content', 'ai_generated', "TINYINT(1) NOT NULL DEFAULT 1");
    addColumnIfMissing($pdo, 'curriculum_topics_content', 'raw_json', "LONGTEXT DEFAULT NULL");

    addIndexIfMissing($pdo, 'curriculum_topics_content', 'idx_topic_id', 'topic_id');
    addIndexIfMissing($pdo, 'curriculum_topics_content', 'idx_content_key', 'content_key');
}

function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        echo "[schema] {$table}.{$column} ergänzt\n";
    }
}

function addIndexIfMissing(PDO $pdo, string $table, string $index, string $column): void
{
    $stmt = $pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = ?");
    $stmt->execute([$index]);
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`{$column}`)");
    }
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function resolveStateId(PDO $pdo, string $code, string $label): ?int
{
    foreach (['states', 'federal_states', 'bundeslaender'] as $table) {
        if (!tableExists($pdo, $table)) continue;
        $columns = tableColumns($pdo, $table);
        foreach (['code', 'state_code', 'slug', 'shortcode'] as $col) {
            if (in_array($col, $columns, true)) {
                $stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE UPPER(`{$col}`) = ? LIMIT 1");
                $stmt->execute([$code]);
                if ($id = $stmt->fetchColumn()) return (int)$id;
            }
        }
        foreach (['name', 'title', 'label'] as $col) {
            if (in_array($col, $columns, true)) {
                $stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE `{$col}` = ? LIMIT 1");
                $stmt->execute([$label]);
                if ($id = $stmt->fetchColumn()) return (int)$id;
            }
        }
    }

    // Fallback aus deinem Dump: BW ist bereits state_id 1.
    return $code === 'BW' ? 1 : null;
}

function resolveSchoolTypeId(PDO $pdo, string $key, string $label): ?int
{
    foreach (['school_types', 'schooltype', 'school_type'] as $table) {
        if (!tableExists($pdo, $table)) continue;
        $columns = tableColumns($pdo, $table);
        foreach (['code', 'slug', 'key', 'school_type_key'] as $col) {
            if (in_array($col, $columns, true)) {
                $stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE `{$col}` = ? LIMIT 1");
                $stmt->execute([$key]);
                if ($id = $stmt->fetchColumn()) return (int)$id;
            }
        }
        foreach (['name', 'title', 'label'] as $col) {
            if (in_array($col, $columns, true)) {
                $stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE LOWER(`{$col}`) = LOWER(?) LIMIT 1");
                $stmt->execute([$label]);
                if ($id = $stmt->fetchColumn()) return (int)$id;
            }
        }
    }

    // Fallbacks aus bisheriger Elevaro-Logik. Bei Abweichung bitte in school_types sauber mappen.
    $fallback = [
        'grundschule' => 1,
        'hauptschule' => 2,
        'werkrealschule' => 3,
        'realschule' => 4,
        'gemeinschaftsschule' => 5,
        'gymnasium' => 6,
        'berufliches_gymnasium' => 7,
        'berufskolleg' => 8,
        'sbbz' => 9,
    ];
    return $fallback[$key] ?? null;
}

function resolveSubjectId(PDO $pdo, string $key, string $label): ?int
{
    foreach (['subjects', 'school_subjects'] as $table) {
        if (!tableExists($pdo, $table)) continue;
        $columns = tableColumns($pdo, $table);
        foreach (['code', 'slug', 'key', 'subject_key'] as $col) {
            if (in_array($col, $columns, true)) {
                $stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE `{$col}` = ? LIMIT 1");
                $stmt->execute([$key]);
                if ($id = $stmt->fetchColumn()) return (int)$id;
            }
        }
        foreach (['name', 'title', 'label'] as $col) {
            if (in_array($col, $columns, true)) {
                $stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE LOWER(`{$col}`) = LOWER(?) LIMIT 1");
                $stmt->execute([$label]);
                if ($id = $stmt->fetchColumn()) return (int)$id;
            }
        }
    }

    $fallback = [
        'deutsch' => 1,
        'mathematik' => 2,
        'englisch' => 3,
        'sachunterricht' => 4,
        'biologie' => 5,
        'physik' => 6,
        'chemie' => 7,
        'geschichte' => 8,
        'geographie' => 9,
        'gemeinschaftskunde' => 10,
        'wirtschaft' => 11,
        'informatik' => 12,
        'franzoesisch' => 13,
        'spanisch' => 14,
        'religion_ethik' => 15,
        'kunst' => 16,
        'musik' => 17,
        'sport' => 18,
        'bwl' => 19,
        'vwl' => 20,
        'rechnungswesen' => 21,
    ];
    return $fallback[$key] ?? null;
}

function resolveSchoolTypeLevelId(PDO $pdo, int $schoolTypeId, string $gradeKey, int $grade): ?int
{
    if (!tableExists($pdo, 'school_type_levels')) return null;
    $columns = tableColumns($pdo, 'school_type_levels');
    $candidates = [$gradeKey, (string)$grade, 'klasse_' . $grade];

    foreach (['code', 'slug', 'key'] as $col) {
        if (!in_array($col, $columns, true)) continue;
        foreach ($candidates as $candidate) {
            $stmt = $pdo->prepare("SELECT id FROM school_type_levels WHERE school_type_id = ? AND `{$col}` = ? LIMIT 1");
            $stmt->execute([$schoolTypeId, $candidate]);
            if ($id = $stmt->fetchColumn()) return (int)$id;
        }
    }

    if (in_array('grade', $columns, true)) {
        $stmt = $pdo->prepare("SELECT id FROM school_type_levels WHERE school_type_id = ? AND grade = ? LIMIT 1");
        $stmt->execute([$schoolTypeId, $grade]);
        if ($id = $stmt->fetchColumn()) return (int)$id;
    }

    return null;
}

function tableColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    return $cache[$table] = array_column($stmt->fetchAll(), 'Field');
}

function getOrCreateSource(PDO $pdo, int $stateId, int $schoolTypeId, int $subjectId, array $gradeInfo, string $stateCode, string $schoolLabel, string $subjectLabel, bool $dryRun): ?int
{
    $title = "{$stateCode} Lehrplan {$schoolLabel} {$subjectLabel} {$gradeInfo['key']}";
    $stmt = $pdo->prepare("SELECT id FROM curriculum_sources WHERE state_id = ? AND school_type_id = ? AND subject_id = ? AND grade_min <=> ? AND grade_max <=> ? LIMIT 1");
    $stmt->execute([$stateId, $schoolTypeId, $subjectId, $gradeInfo['from'], $gradeInfo['to']]);
    if ($id = $stmt->fetchColumn()) return (int)$id;
    if ($dryRun) return null;

    $stmt = $pdo->prepare("INSERT INTO curriculum_sources
        (state_id, school_type_id, subject_id, grade_min, grade_max, title, url, notes, is_official)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
    $stmt->execute([
        $stateId,
        $schoolTypeId,
        $subjectId,
        $gradeInfo['from'],
        $gradeInfo['to'],
        $title,
        null,
        'Automatisch angelegter Quellenkontext für KI-Seeding. Bitte später mit offizieller Quelle verfeinern.',
    ]);
    return (int)$pdo->lastInsertId();
}

function importAlreadySuccessful(PDO $pdo, string $stateCode, string $schoolKey, string $gradeKey, string $subjectKey): bool
{
    $stmt = $pdo->prepare("SELECT id FROM curriculum_import_runs WHERE state_code = ? AND school_type_key = ? AND grade_key = ? AND subject_key = ? AND status = 'success' LIMIT 1");
    $stmt->execute([$stateCode, $schoolKey, $gradeKey, $subjectKey]);
    return (bool)$stmt->fetchColumn();
}

function createImportRun(PDO $pdo, string $stateCode, string $schoolKey, string $gradeKey, string $subjectKey, string $model): int
{
    $stmt = $pdo->prepare("INSERT INTO curriculum_import_runs
        (state_code, school_type_key, grade_key, subject_key, model, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
        ON DUPLICATE KEY UPDATE status = 'pending', model = VALUES(model), error_message = NULL, raw_response = NULL, created_at = CURRENT_TIMESTAMP, finished_at = NULL");
    $stmt->execute([$stateCode, $schoolKey, $gradeKey, $subjectKey, $model]);

    $stmt = $pdo->prepare("SELECT id FROM curriculum_import_runs WHERE state_code = ? AND school_type_key = ? AND grade_key = ? AND subject_key = ? LIMIT 1");
    $stmt->execute([$stateCode, $schoolKey, $gradeKey, $subjectKey]);
    return (int)$stmt->fetchColumn();
}

function finishImportRun(PDO $pdo, int $runId, string $status, ?string $rawResponse = null, ?string $errorMessage = null): void
{
    $stmt = $pdo->prepare("UPDATE curriculum_import_runs SET status = ?, raw_response = ?, error_message = ?, finished_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$status, $rawResponse, $errorMessage, $runId]);
}

function generateCurriculumPayload(string $apiKey, string $model, string $stateCode, string $stateName, string $schoolKey, string $schoolLabel, array $gradeInfo, string $subjectKey, string $subjectLabel): array
{
    $schema = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['topics'],
        'properties' => [
            'topics' => [
                'type' => 'array',
                'minItems' => 4,
                'maxItems' => 10,
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['topic_key','topic_title','topic_description','competence_area','learning_goal','subtopics'],
                    'properties' => [
                        'topic_key' => ['type' => 'string'],
                        'topic_title' => ['type' => 'string'],
                        'topic_description' => ['type' => 'string'],
                        'competence_area' => ['type' => 'string'],
                        'learning_goal' => ['type' => 'string'],
                        'subtopics' => [
                            'type' => 'array',
                            'minItems' => 3,
                            'maxItems' => 8,
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['content_key','content_title','learning_goal','difficulty_level','question_type_hint','example_prompt'],
                                'properties' => [
                                    'content_key' => ['type' => 'string'],
                                    'content_title' => ['type' => 'string'],
                                    'learning_goal' => ['type' => 'string'],
                                    'difficulty_level' => ['type' => 'string', 'enum' => ['leicht','mittel','schwer']],
                                    'question_type_hint' => ['type' => 'string'],
                                    'example_prompt' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $prompt = "Erstelle einen praxistauglichen Lehrplan-Themenkatalog für Quiz-Erstellung.\n"
        . "Bundesland: {$stateName} ({$stateCode})\n"
        . "Schulart: {$schoolLabel} ({$schoolKey})\n"
        . "Stufe/Klasse: {$gradeInfo['key']} (numerisch {$gradeInfo['from']} bis {$gradeInfo['to']})\n"
        . "Fach: {$subjectLabel} ({$subjectKey})\n\n"
        . "Wichtig: Keine erfundenen exotischen Spezialthemen. Orientiere dich an typischen deutschen/Bundesland-Lehrplaninhalten. "
        . "Die Topics sollen breit genug für Navigation sein, die Subtopics konkret genug für automatische Quizfragen. "
        . "Formuliere auf Deutsch. Keys nur lowercase ASCII mit Bindestrichen oder Unterstrichen.";

    $body = [
        'model' => $model,
        'input' => [
            ['role' => 'system', 'content' => 'Du bist ein präziser Curriculum- und Didaktik-Editor für eine deutsche Lernplattform.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'curriculum_seed_payload',
                'strict' => true,
                'schema' => $schema,
            ],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 120,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("OpenAI API Fehler HTTP {$httpCode}: " . ($response ?: $curlError));
    }

    $decoded = json_decode($response, true);
    $text = extractResponseText($decoded);
    $payload = json_decode($text, true);

    if (!is_array($payload) || !isset($payload['topics']) || !is_array($payload['topics'])) {
        throw new RuntimeException('Ungültige JSON-Antwort: ' . mb_substr($text, 0, 500));
    }

    return $payload;
}

function extractResponseText(array $response): string
{
    if (isset($response['output_text']) && is_string($response['output_text'])) {
        return $response['output_text'];
    }

    $parts = [];
    foreach ($response['output'] ?? [] as $output) {
        foreach ($output['content'] ?? [] as $content) {
            if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                $parts[] = $content['text'];
            }
        }
    }

    $text = trim(implode("\n", $parts));
    if ($text === '') {
        throw new RuntimeException('Konnte keinen output_text aus OpenAI-Antwort extrahieren.');
    }
    return $text;
}

function importPayload(PDO $pdo, array $payload, array $ctx): void
{
    $pdo->beginTransaction();
    try {
        $topicSort = 0;
        foreach ($payload['topics'] as $topic) {
            $topicSort++;
            $topicKey = slugify($topic['topic_key'] ?: $topic['topic_title']);
            $topicId = upsertTopic($pdo, $ctx, $topic, $topicKey, $topicSort);

            $contentSort = 0;
            foreach ($topic['subtopics'] as $sub) {
                $contentSort++;
                upsertTopicContent($pdo, $ctx, $topicId, $topic, $topicKey, $sub, $contentSort);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function upsertTopic(PDO $pdo, array $ctx, array $topic, string $topicKey, int $sortOrder): int
{
    $stmt = $pdo->prepare("INSERT INTO curriculum_topics
        (state_id, school_type_id, education_track_id, education_track_level_id, grade, school_type_level_id, subject_id, code, title, description, learning_goal, ai_generated, sort_order)
        VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            description = VALUES(description),
            learning_goal = VALUES(learning_goal),
            ai_generated = 1,
            sort_order = VALUES(sort_order)");
    $stmt->execute([
        $ctx['state_id'],
        $ctx['school_type_id'],
        $ctx['grade'],
        $ctx['school_type_level_id'],
        $ctx['subject_id'],
        $topicKey,
        $topic['topic_title'],
        $topic['topic_description'],
        $topic['learning_goal'],
        $sortOrder,
    ]);

    $stmt = $pdo->prepare("SELECT id FROM curriculum_topics WHERE state_id = ? AND school_type_id = ? AND grade = ? AND subject_id = ? AND code = ? LIMIT 1");
    $stmt->execute([$ctx['state_id'], $ctx['school_type_id'], $ctx['grade'], $ctx['subject_id'], $topicKey]);
    return (int)$stmt->fetchColumn();
}

function upsertTopicContent(PDO $pdo, array $ctx, int $topicId, array $topic, string $topicKey, array $sub, int $sortOrder): void
{
    $contentKey = slugify($sub['content_key'] ?: $sub['content_title']);
    $rawJson = json_encode(['topic' => $topic, 'subtopic' => $sub], JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("SELECT id FROM curriculum_topics_content
        WHERE state_code = ? AND school_type_key = ? AND grade_key = ? AND subject_key = ? AND topic_key = ? AND content_key = ?
        LIMIT 1");
    $stmt->execute([$ctx['state_code'], $ctx['school_type_key'], $ctx['grade_key'], $ctx['subject_key'], $topicKey, $contentKey]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $stmt = $pdo->prepare("UPDATE curriculum_topics_content SET
            topic_id = ?,
            topic_title = ?,
            topic_description = ?,
            competence_area = ?,
            content_title = ?,
            learning_goal = ?,
            difficulty_level = ?,
            question_type_hint = ?,
            example_prompt = ?,
            source_name = ?,
            source_url = ?,
            sort_order = ?,
            is_active = 1,
            ai_generated = 1,
            raw_json = ?,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = ?");
        $stmt->execute([
            $topicId,
            $topic['topic_title'],
            $topic['topic_description'],
            $topic['competence_area'],
            $sub['content_title'],
            $sub['learning_goal'],
            $sub['difficulty_level'],
            $sub['question_type_hint'],
            $sub['example_prompt'],
            'KI-Seeding / später mit offizieller Quelle verfeinern',
            null,
            $sortOrder,
            $rawJson,
            $existingId,
        ]);
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO curriculum_topics_content
        (state_code, school_type_key, grade_key, grade_from, grade_to, subject_key, subject_label,
         topic_key, topic_title, topic_description, competence_area, source_name, source_url,
         sort_order, is_active, topic_id, content_key, content_title, learning_goal,
         difficulty_level, question_type_hint, example_prompt, ai_generated, raw_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, 1, ?)");
    $stmt->execute([
        $ctx['state_code'],
        $ctx['school_type_key'],
        $ctx['grade_key'],
        $ctx['grade_from'],
        $ctx['grade_to'],
        $ctx['subject_key'],
        $ctx['subject_label'],
        $topicKey,
        $topic['topic_title'],
        $topic['topic_description'],
        $topic['competence_area'],
        'KI-Seeding / später mit offizieller Quelle verfeinern',
        null,
        $sortOrder,
        $topicId,
        $contentKey,
        $sub['content_title'],
        $sub['learning_goal'],
        $sub['difficulty_level'],
        $sub['question_type_hint'],
        $sub['example_prompt'],
        $rawJson,
    ]);
}

function slugify(string $value): string
{
    $value = trim(mb_strtolower($value));
    $map = ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss'];
    $value = strtr($value, $map);
    $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? $value;
    $value = trim($value, '-');
    return $value !== '' ? mb_substr($value, 0, 170) : 'topic-' . bin2hex(random_bytes(3));
}

function printStats(int $processed, int $skipped, int $errors): void
{
    echo "\nDone. Processed: {$processed}, skipped: {$skipped}, errors: {$errors}\n";
}
