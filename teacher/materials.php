<?php
require_once __DIR__ . '/_layout.php';

$pdo = teacher_db();
$teacherId = teacher_current_user_id();

function teacher_library_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_units (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        subject_code VARCHAR(64) NULL,
        grade VARCHAR(32) NULL,
        state_code VARCHAR(16) NULL,
        school_type_code VARCHAR(64) NULL,
        level_key VARCHAR(64) NULL,
        curriculum_topic_content_id INT UNSIGNED NULL,
        curriculum_topic_subtopic_id INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_teacher_units_teacher (teacher_id),
        KEY idx_teacher_units_subject_grade (subject_code, grade),
        KEY idx_teacher_units_curriculum (curriculum_topic_content_id, curriculum_topic_subtopic_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_unit_classes (
        unit_id INT UNSIGNED NOT NULL,
        class_id INT UNSIGNED NOT NULL,
        teacher_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (unit_id, class_id),
        KEY idx_teacher_unit_classes_teacher (teacher_id),
        KEY idx_teacher_unit_classes_class (class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function teacher_library_subject_label(PDO $pdo, ?string $subjectCode): string
{
    $subjectCode = trim((string)$subjectCode);
    if ($subjectCode === '') return 'Ohne Fach';
    try {
        $stmt = $pdo->prepare("SELECT name FROM subjects WHERE code = :code LIMIT 1");
        $stmt->execute(['code' => $subjectCode]);
        $name = $stmt->fetchColumn();
        if ($name) return (string)$name;
    } catch (Throwable $e) {}
    return strtoupper($subjectCode);
}

function teacher_library_is_language_subject(?string $subjectCode): bool
{
    $code = mb_strtolower(trim((string)$subjectCode));
    return in_array($code, ['englisch','english','en','franzoesisch','französisch','french','fr','spanisch','spanish','es','italienisch','italian','it','latein','latin','la'], true);
}

function teacher_library_asset_label(string $type): string
{
    return match ($type) {
        'listening_quiz' => 'Listening-Quiz',
        'worksheet' => 'Arbeitsblatt',
        'listening_comprehension' => 'Hörverständnis',
        'reading_comprehension' => 'Leseverständnis',
        default => 'Quiz',
    };
}

function teacher_library_asset_icon(string $type): string
{
    return match ($type) {
        'listening_quiz' => '🎮🎧',
        'worksheet' => '📄',
        'listening_comprehension' => '🎧',
        'reading_comprehension' => '📖',
        default => '🎮',
    };
}

function teacher_library_asset_group(string $type): string
{
    return match ($type) {
        'worksheet', 'reading_comprehension' => 'learning_material',
        'listening_comprehension' => 'listening',
        default => 'quiz',
    };
}

function teacher_library_parse_keywords(?string $json, int $limit = 8): array
{
    if (!$json) return [];
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return [];
    return array_slice(array_values(array_filter(array_map('strval', $decoded))), 0, $limit);
}

function teacher_library_fetch_topics(PDO $pdo, ?array $class = null): array
{
    try {
        $where = "WHERE COALESCE(c.is_active, 1) = 1";
        $params = [];
        if ($class) {
            $where .= " AND c.state_code = :state_code AND c.school_type_key = :school_type AND c.grade_key = :level_key AND c.subject_key = :subject";
            $params = [
                'state_code' => (string)($class['state_code'] ?? ''),
                'school_type' => (string)($class['school_type_code'] ?? ''),
                'level_key' => (string)($class['level_key'] ?? ''),
                'subject' => (string)($class['subject_code'] ?? ''),
            ];
        }
        $stmt = $pdo->prepare("SELECT c.* FROM curriculum_topics_content c {$where} ORDER BY c.subject_key, c.grade_key, c.domain_title, c.sort_order, c.topic_title LIMIT 600");
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function teacher_library_fetch_subtopics(PDO $pdo, array $topicIds): array
{
    if (!$topicIds) return [];
    try {
        $ph = implode(',', array_fill(0, count($topicIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM curriculum_topic_subtopics WHERE curriculum_topic_content_id IN ({$ph}) AND COALESCE(is_active, 1) = 1 ORDER BY curriculum_topic_content_id, sort_order, subtopic_title");
        $stmt->execute($topicIds);
        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $out[(int)$row['curriculum_topic_content_id']][] = $row;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function teacher_library_topic_label(array $topic): string
{
    return (string)(($topic['title_short'] ?? '') ?: ($topic['topic_title'] ?? '') ?: ('Thema #' . (int)($topic['id'] ?? 0)));
}

function teacher_library_subtopic_label(array $subtopic): string
{
    return (string)(($subtopic['title_short'] ?? '') ?: ($subtopic['subtopic_title'] ?? '') ?: ('Skill #' . (int)($subtopic['id'] ?? 0)));
}

teacher_library_ensure_schema($pdo);
$notice = null;
$error = null;
$selectedClass = teacher_selected_class();
$classes = teacher_classes();

$topicsForSelect = teacher_library_fetch_topics($pdo, $selectedClass ?: null);
$subtopicsByTopic = teacher_library_fetch_subtopics($pdo, array_map(static fn($t) => (int)$t['id'], $topicsForSelect));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_unit') {
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title === '') throw new RuntimeException('Bitte gib einen Titel für die Unit ein.');
            $classId = (int)($_POST['class_id'] ?? 0);
            $class = null;
            foreach ($classes as $row) {
                if ((int)$row['id'] === $classId) $class = $row;
            }
            $topicId = (int)($_POST['curriculum_topic_content_id'] ?? 0) ?: null;
            $subtopicId = (int)($_POST['curriculum_topic_subtopic_id'] ?? 0) ?: null;
            $subjectCode = trim((string)($_POST['subject_code'] ?? ''));
            $grade = trim((string)($_POST['grade'] ?? ''));
            if ($class) {
                $subjectCode = (string)($class['subject_code'] ?? $subjectCode);
                $grade = (string)(($class['grade'] ?? '') ?: ($class['level_key'] ?? $grade));
            }

            $stmt = $pdo->prepare("INSERT INTO teacher_units
                (teacher_id, title, description, subject_code, grade, state_code, school_type_code, level_key, curriculum_topic_content_id, curriculum_topic_subtopic_id)
                VALUES (:teacher_id, :title, :description, :subject_code, :grade, :state_code, :school_type_code, :level_key, :topic_id, :subtopic_id)");
            $stmt->execute([
                'teacher_id' => $teacherId,
                'title' => $title,
                'description' => trim((string)($_POST['description'] ?? '')),
                'subject_code' => $subjectCode ?: null,
                'grade' => $grade ?: null,
                'state_code' => $class['state_code'] ?? null,
                'school_type_code' => $class['school_type_code'] ?? null,
                'level_key' => $class['level_key'] ?? null,
                'topic_id' => $topicId,
                'subtopic_id' => $subtopicId,
            ]);
            $unitId = (int)$pdo->lastInsertId();
            if ($classId > 0) {
                $link = $pdo->prepare("INSERT IGNORE INTO teacher_unit_classes (unit_id, class_id, teacher_id) VALUES (:unit_id, :class_id, :teacher_id)");
                $link->execute(['unit_id' => $unitId, 'class_id' => $classId, 'teacher_id' => $teacherId]);
            }
            $notice = 'Unit wurde angelegt. Du kannst jetzt Inhalte dafür erstellen.';
        }

        if ($action === 'assign_classes') {
            $unitId = (int)($_POST['unit_id'] ?? 0);
            $classIds = array_values(array_unique(array_filter(array_map('intval', $_POST['class_ids'] ?? []))));
            $check = $pdo->prepare("SELECT id FROM teacher_units WHERE id = :id AND teacher_id = :teacher_id");
            $check->execute(['id' => $unitId, 'teacher_id' => $teacherId]);
            if (!$check->fetchColumn()) throw new RuntimeException('Unit nicht gefunden.');
            $pdo->prepare("DELETE FROM teacher_unit_classes WHERE unit_id = :unit_id AND teacher_id = :teacher_id")->execute(['unit_id' => $unitId, 'teacher_id' => $teacherId]);
            if ($classIds) {
                $allowed = [];
                foreach ($classes as $class) $allowed[(int)$class['id']] = true;
                $insert = $pdo->prepare("INSERT IGNORE INTO teacher_unit_classes (unit_id, class_id, teacher_id) VALUES (:unit_id, :class_id, :teacher_id)");
                foreach ($classIds as $classId) {
                    if (!isset($allowed[$classId])) continue;
                    $insert->execute(['unit_id' => $unitId, 'class_id' => $classId, 'teacher_id' => $teacherId]);
                }
            }
            $notice = 'Klassenzuordnung wurde aktualisiert.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$topicMap = [];
foreach ($topicsForSelect as $topic) $topicMap[(int)$topic['id']] = $topic;

$stmt = $pdo->prepare("SELECT u.*,
    ct.topic_title, ct.title_short AS topic_title_short, ct.domain_title,
    st.subtopic_title, st.title_short AS subtopic_title_short,
    COUNT(DISTINCT a.id) AS asset_count,
    COUNT(DISTINCT uc.class_id) AS class_count
    FROM teacher_units u
    LEFT JOIN curriculum_topics_content ct ON ct.id = u.curriculum_topic_content_id
    LEFT JOIN curriculum_topic_subtopics st ON st.id = u.curriculum_topic_subtopic_id
    LEFT JOIN teacher_unit_assets a ON a.unit_id = u.id
    LEFT JOIN teacher_unit_classes uc ON uc.unit_id = u.id
    WHERE u.teacher_id = :teacher_id
    GROUP BY u.id, ct.id, st.id
    ORDER BY COALESCE(u.updated_at, u.created_at) DESC, u.id DESC");
$stmt->execute(['teacher_id' => $teacherId]);
$units = $stmt->fetchAll() ?: [];

$unitIds = array_map(static fn($u) => (int)$u['id'], $units);
$assetsByUnit = [];
$classesByUnit = [];
if ($unitIds) {
    $ph = implode(',', array_fill(0, count($unitIds), '?'));
    $assetStmt = $pdo->prepare("SELECT * FROM teacher_unit_assets WHERE unit_id IN ({$ph}) ORDER BY updated_at DESC, id DESC");
    $assetStmt->execute($unitIds);
    foreach ($assetStmt->fetchAll() ?: [] as $asset) {
        $assetsByUnit[(int)$asset['unit_id']][] = $asset;
    }

    $classStmt = $pdo->prepare("SELECT uc.unit_id, tc.* FROM teacher_unit_classes uc JOIN teacher_classes tc ON tc.id = uc.class_id WHERE uc.unit_id IN ({$ph}) ORDER BY tc.name");
    $classStmt->execute($unitIds);
    foreach ($classStmt->fetchAll() ?: [] as $class) {
        $classesByUnit[(int)$class['unit_id']][] = $class;
    }
}

$legacyItems = [];
if (teacher_column_exists('quizzes', 'created_by_user_id')) {
    $stmt = $pdo->prepare("SELECT q.id, q.quiz_key, q.title, q.description, q.grade, q.created_at, q.updated_at, q.theme_emoji, q.listening_mode, q.status,
        sub.code AS subject_code, sub.name AS subject_name, COUNT(qq.id) AS question_count
        FROM quizzes q
        LEFT JOIN subjects sub ON sub.id = q.subject_id
        LEFT JOIN questions qq ON qq.quiz_id = q.id
        WHERE q.created_by_user_id = :teacher_id
          AND NOT EXISTS (SELECT 1 FROM teacher_unit_assets tua WHERE tua.quiz_id = q.id AND tua.teacher_id = :teacher_id2)
        GROUP BY q.id, sub.id
        ORDER BY COALESCE(q.updated_at, q.created_at) DESC
        LIMIT 24");
    $stmt->execute(['teacher_id' => $teacherId, 'teacher_id2' => $teacherId]);
    $legacyItems = $stmt->fetchAll() ?: [];
}

$subjectFilters = [];
$gradeFilters = [];
$topicFilters = [];
foreach ($units as $unit) {
    $subject = teacher_library_subject_label($pdo, $unit['subject_code'] ?? '');
    if ($subject) $subjectFilters[$subject] = true;
    if (!empty($unit['grade'])) $gradeFilters[(string)$unit['grade']] = true;
    $topicTitle = (string)(($unit['topic_title_short'] ?? '') ?: ($unit['topic_title'] ?? ''));
    if ($topicTitle !== '') $topicFilters[$topicTitle] = true;
}
$subjectFilters = array_keys($subjectFilters); sort($subjectFilters, SORT_NATURAL | SORT_FLAG_CASE);
$gradeFilters = array_keys($gradeFilters); sort($gradeFilters, SORT_NATURAL | SORT_FLAG_CASE);
$topicFilters = array_keys($topicFilters); sort($topicFilters, SORT_NATURAL | SORT_FLAG_CASE);

teacher_header('Bibliothek', 'Deine persönlichen Units, Quizzes und Lernmaterialien – unabhängig von einzelnen Klassen.');
?>
<style>
  .unit-hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:22px;align-items:center;margin-bottom:22px;padding:26px;border-radius:32px;background:linear-gradient(135deg,#f7f7ff 0%,#eef2ff 48%,#fff 100%);border:1px solid rgba(90,79,243,.14);box-shadow:0 24px 70px rgba(23,32,51,.08)}
  .unit-hero h2{margin:0 0 7px;font-size:1.55rem;font-weight:950;color:#172033}.unit-hero p{margin:0;color:#64748b;max-width:760px;line-height:1.45}.unit-hero-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.unit-hero-actions .btn{border-radius:999px;font-weight:900}
  .unit-filter-bar{position:sticky;top:0;z-index:5;display:grid;grid-template-columns:minmax(220px,1.4fr) repeat(4,minmax(135px,.7fr)) auto;gap:10px;align-items:center;padding:12px;margin-bottom:18px;border-radius:26px;background:rgba(255,255,255,.9);border:1px solid rgba(23,32,51,.08);box-shadow:0 14px 42px rgba(23,32,51,.075);backdrop-filter:blur(16px)}
  .unit-filter-bar .form-control,.unit-filter-bar .form-select{border-radius:999px;border-color:rgba(23,32,51,.10);font-weight:750}.unit-filter-bar .btn{border-radius:999px;font-weight:900}
  .unit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.unit-card{background:#fff;border:1px solid rgba(23,32,51,.08);border-radius:30px;box-shadow:0 18px 52px rgba(23,32,51,.07);overflow:hidden;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}.unit-card:hover{transform:translateY(-2px);box-shadow:0 24px 70px rgba(23,32,51,.1);border-color:rgba(90,79,243,.2)}
  .unit-card-head{display:grid;grid-template-columns:56px minmax(0,1fr);gap:14px;padding:20px 20px 14px}.unit-icon{width:56px;height:56px;border-radius:20px;background:linear-gradient(135deg,#5a4ff3,#8b7cff);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.55rem;box-shadow:0 14px 32px rgba(90,79,243,.22)}
  .unit-kicker{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}.unit-kicker span{font-size:.72rem;font-weight:900;color:#4f46e5;background:#eef2ff;border-radius:999px;padding:5px 8px}.unit-card h3{font-size:1.2rem;font-weight:950;line-height:1.14;color:#172033;margin:0 0 6px}.unit-card p{margin:0;color:#64748b;line-height:1.42}.unit-description{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .unit-formats{display:flex;gap:7px;flex-wrap:wrap;padding:0 20px 16px}.unit-format{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;background:#f8fafc;border:1px solid rgba(23,32,51,.07);font-size:.78rem;font-weight:900;color:#475569}.unit-format.is-empty{color:#94a3b8;background:#fbfcff}
  .unit-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;padding:14px 20px;background:#fbfcff;border-top:1px solid rgba(23,32,51,.06)}.unit-actions .btn{border-radius:16px;font-weight:900;white-space:normal;text-align:left;line-height:1.15;padding:10px 12px}.unit-actions small{display:block;font-weight:750;opacity:.72;margin-top:3px}
  .unit-class-row{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:12px 20px;border-top:1px solid rgba(23,32,51,.06)}.unit-class-list{display:flex;gap:6px;flex-wrap:wrap}.unit-class-list span{font-size:.75rem;font-weight:850;border-radius:999px;background:#f1f5f9;color:#475569;padding:5px 8px}.unit-class-row .btn{border-radius:999px;font-weight:850}
  .unit-empty{padding:38px;border-radius:30px;background:#fff;border:1px dashed rgba(90,79,243,.28);text-align:center;box-shadow:0 18px 52px rgba(23,32,51,.06)}.unit-empty h3{font-weight:950;color:#172033}.unit-empty p{color:#64748b;margin:0 auto 18px;max-width:620px}
  .legacy-box{margin-top:22px;padding:18px;border-radius:28px;background:#fff8ed;border:1px solid #fed7aa;color:#9a3412}.legacy-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}.legacy-item{background:#fff;border:1px solid rgba(154,52,18,.12);border-radius:18px;padding:12px}.legacy-item strong{display:block;color:#172033;line-height:1.2}.legacy-item small{color:#9a3412;font-weight:750}
  .unit-modal-card{border-radius:24px;background:#f8fafc;border:1px solid rgba(23,32,51,.06);padding:16px}.unit-modal-card h6{font-weight:950;color:#172033;margin-bottom:10px}.unit-class-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.unit-class-checks label{display:flex;gap:8px;align-items:flex-start;background:#fff;border:1px solid rgba(23,32,51,.08);border-radius:16px;padding:10px;font-weight:850}.unit-class-checks small{display:block;color:#64748b;font-weight:700}
  @media(max-width:1050px){.unit-filter-bar{grid-template-columns:1fr 1fr}.unit-grid{grid-template-columns:1fr}.unit-hero{grid-template-columns:1fr}.unit-hero-actions{justify-content:flex-start}.unit-actions{grid-template-columns:1fr}.legacy-grid{grid-template-columns:1fr}.unit-class-checks{grid-template-columns:1fr}}
</style>

<?php if ($notice): ?><div class="alert alert-success"><?= teacher_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= teacher_h($error) ?></div><?php endif; ?>

<section class="unit-hero">
  <div>
    <h2>Meine Units</h2>
    <p>Eine Unit ist dein eigenes Inhaltspaket zu einem Lernziel. Daraus kannst du spielbare Quizzes, Arbeitsblätter, Hörverständnis und Leseverständnis erstellen und anschließend einer oder mehreren Klassen zuordnen.</p>
  </div>
  <div class="unit-hero-actions">
    <button class="btn btn-primary btn-lg" type="button" data-bs-toggle="modal" data-bs-target="#createUnitModal">＋ Neue Unit anlegen</button>
  </div>
</section>

<div class="unit-filter-bar" data-unit-filters>
  <input class="form-control" type="search" placeholder="Units durchsuchen…" data-filter-search>
  <select class="form-select" data-filter-subject><option value="">Alle Fächer</option><?php foreach ($subjectFilters as $subject): ?><option><?= teacher_h($subject) ?></option><?php endforeach; ?></select>
  <select class="form-select" data-filter-grade><option value="">Alle Klassen</option><?php foreach ($gradeFilters as $grade): ?><option><?= teacher_h($grade) ?></option><?php endforeach; ?></select>
  <select class="form-select" data-filter-topic><option value="">Alle Lehrplanthemen</option><?php foreach ($topicFilters as $topic): ?><option><?= teacher_h($topic) ?></option><?php endforeach; ?></select>
  <select class="form-select" data-filter-format><option value="">Alle Formate</option><option value="quiz">Quiz</option><option value="learning_material">Lernmaterial</option><option value="listening">Hörverständnis</option></select>
  <button class="btn btn-light" type="button" data-filter-reset>Reset</button>
</div>

<?php if (!$units): ?>
  <div class="unit-empty">
    <h3>Noch keine Unit angelegt</h3>
    <p>Starte mit einer Unit, wähle optional ein Lehrplanthema und erstelle danach daraus Quizzes oder Lernmaterialien.</p>
    <button class="btn btn-primary btn-lg" type="button" data-bs-toggle="modal" data-bs-target="#createUnitModal">Erste Unit anlegen</button>
  </div>
<?php else: ?>
  <div class="unit-grid" id="unitGrid">
    <?php foreach ($units as $unit): ?>
      <?php
        $unitId = (int)$unit['id'];
        $subjectLabel = teacher_library_subject_label($pdo, $unit['subject_code'] ?? '');
        $grade = (string)($unit['grade'] ?? '');
        $topicTitle = (string)(($unit['topic_title_short'] ?? '') ?: ($unit['topic_title'] ?? ''));
        $subtopicTitle = (string)(($unit['subtopic_title_short'] ?? '') ?: ($unit['subtopic_title'] ?? ''));
        $assets = $assetsByUnit[$unitId] ?? [];
        $assetTypes = array_values(array_unique(array_map(static fn($a) => (string)$a['asset_type'], $assets)));
        $groups = array_values(array_unique(array_map('teacher_library_asset_group', $assetTypes)));
        $assignedClasses = $classesByUnit[$unitId] ?? [];
        $firstClassId = $assignedClasses ? (int)$assignedClasses[0]['id'] : ((int)($selectedClass['id'] ?? 0));
        $isLanguage = teacher_library_is_language_subject($unit['subject_code'] ?? '');
        $searchHaystack = trim(($unit['title'] ?? '') . ' ' . ($unit['description'] ?? '') . ' ' . $subjectLabel . ' ' . $grade . ' ' . $topicTitle . ' ' . $subtopicTitle);
      ?>
      <article class="unit-card" data-unit-card data-search="<?= teacher_h(mb_strtolower($searchHaystack)) ?>" data-subject="<?= teacher_h($subjectLabel) ?>" data-grade="<?= teacher_h($grade) ?>" data-topic="<?= teacher_h($topicTitle) ?>" data-formats="<?= teacher_h(implode(' ', $groups)) ?>">
        <div class="unit-card-head">
          <div class="unit-icon"><?= $isLanguage ? '💬' : '✨' ?></div>
          <div>
            <div class="unit-kicker">
              <span><?= teacher_h($subjectLabel) ?></span>
              <?php if ($grade !== ''): ?><span>Klasse <?= teacher_h($grade) ?></span><?php endif; ?>
              <?php if ($topicTitle !== ''): ?><span><?= teacher_h($topicTitle) ?></span><?php endif; ?>
            </div>
            <h3><?= teacher_h($unit['title']) ?></h3>
            <?php if ($subtopicTitle !== ''): ?><p><strong><?= teacher_h($subtopicTitle) ?></strong></p><?php endif; ?>
            <?php if (!empty($unit['description'])): ?><p class="unit-description"><?= teacher_h($unit['description']) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="unit-formats">
          <?php if (!$assets): ?>
            <span class="unit-format is-empty">Noch keine Inhalte</span>
          <?php else: ?>
            <?php foreach ($assetTypes as $type): ?>
              <span class="unit-format"><?= teacher_h(teacher_library_asset_icon($type)) ?> <?= teacher_h(teacher_library_asset_label($type)) ?></span>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div class="unit-actions">
          <a class="btn btn-outline-primary" href="ai_wizard.php?unit_id=<?= $unitId ?><?= $firstClassId ? '&class_id=' . $firstClassId : '' ?>&mode=quiz">🎮 Quiz erstellen<small>spielbar im Klassenraum</small></a>
          <a class="btn btn-outline-primary" href="worksheet_editor.php?unit_id=<?= $unitId ?>">📄 Lernmaterial<small>Worksheet / Reading</small></a>
          <?php if ($isLanguage): ?>
            <a class="btn btn-outline-primary" href="listening_wizard.php?unit_id=<?= $unitId ?>">🎧 Listening<small>Audio + Comprehension</small></a>
          <?php else: ?>
            <button class="btn btn-light" type="button" disabled>🎧 Listening<small>nur Fremdsprachen</small></button>
          <?php endif; ?>
        </div>

        <div class="unit-class-row">
          <div class="unit-class-list">
            <?php if (!$assignedClasses): ?>
              <span>keiner Klasse zugeordnet</span>
            <?php else: ?>
              <?php foreach (array_slice($assignedClasses, 0, 3) as $class): ?><span><?= teacher_h(teacher_class_label($class)) ?></span><?php endforeach; ?>
              <?php if (count($assignedClasses) > 3): ?><span>+<?= count($assignedClasses) - 3 ?></span><?php endif; ?>
            <?php endif; ?>
          </div>
          <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#assignUnitModal<?= $unitId ?>">Zuordnen</button>
        </div>
      </article>

      <div class="modal fade" id="assignUnitModal<?= $unitId ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <form class="modal-content" method="post">
            <input type="hidden" name="action" value="assign_classes">
            <input type="hidden" name="unit_id" value="<?= $unitId ?>">
            <div class="modal-header"><h5 class="modal-title fw-black">Unit Klassen zuordnen</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button></div>
            <div class="modal-body">
              <p class="text-muted">Wähle aus, in welchen Klassen diese Unit genutzt werden soll.</p>
              <div class="unit-class-checks">
                <?php $assignedMap = array_fill_keys(array_map(static fn($c) => (int)$c['id'], $assignedClasses), true); ?>
                <?php foreach ($classes as $class): ?>
                  <label><input class="form-check-input mt-1" type="checkbox" name="class_ids[]" value="<?= (int)$class['id'] ?>" <?= isset($assignedMap[(int)$class['id']]) ? 'checked' : '' ?>><span><?= teacher_h(teacher_class_label($class)) ?><small><?= teacher_h((string)($class['subject_code'] ?? '')) ?></small></span></label>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Abbrechen</button><button class="btn btn-primary" type="submit">Speichern</button></div>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($legacyItems): ?>
  <section class="legacy-box">
    <strong>Noch nicht zugeordnete ältere Materialien</strong><br>
    Diese Inhalte wurden bereits von dir erstellt, sind aber noch keiner Unit zugeordnet. Sie bleiben sichtbar, bis wir sie später sauber migrieren oder du sie einer Unit zuordnest.
    <div class="legacy-grid">
      <?php foreach ($legacyItems as $item): ?>
        <div class="legacy-item">
          <strong><?= teacher_h($item['theme_emoji'] ?: '🧠') ?> <?= teacher_h($item['title'] ?? 'Unbenannt') ?></strong>
          <small><?= ((int)($item['listening_mode'] ?? 0) === 1) ? 'Listening-Quiz' : 'Quiz' ?> · <?= (int)($item['question_count'] ?? 0) ?> Fragen</small>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<div class="modal fade" id="createUnitModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form class="modal-content" method="post">
      <input type="hidden" name="action" value="create_unit">
      <div class="modal-header">
        <div><h5 class="modal-title fw-black">Neue Unit anlegen</h5><div class="text-muted small">Geführt erstellen: erst Unit, danach Inhalte, danach Klassenfreigabe.</div></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-7">
            <div class="unit-modal-card h-100">
              <h6>1 · Grunddaten</h6>
              <label class="form-label fw-bold">Titel der Unit</label>
              <input class="form-control form-control-lg" name="title" placeholder="z. B. Schwarzwald" required>
              <label class="form-label fw-bold mt-3">Beschreibung / Ziel</label>
              <textarea class="form-control" name="description" rows="4" placeholder="Optional: Was sollen Schülerinnen und Schüler hier lernen?"></textarea>
            </div>
          </div>
          <div class="col-md-5">
            <div class="unit-modal-card h-100">
              <h6>2 · Klasse & Kontext</h6>
              <label class="form-label fw-bold">Direkt Klasse zuordnen</label>
              <select class="form-select" name="class_id" id="unitClassSelect">
                <option value="0">Noch keiner Klasse zuordnen</option>
                <?php foreach ($classes as $class): ?><option value="<?= (int)$class['id'] ?>" <?= $selectedClass && (int)$selectedClass['id'] === (int)$class['id'] ? 'selected' : '' ?>><?= teacher_h(teacher_class_label($class)) ?></option><?php endforeach; ?>
              </select>
              <label class="form-label fw-bold mt-3">Fach, falls ohne Klasse</label>
              <input class="form-control" name="subject_code" placeholder="z. B. englisch, geographie">
              <label class="form-label fw-bold mt-3">Klassenstufe, falls ohne Klasse</label>
              <input class="form-control" name="grade" placeholder="z. B. 5">
            </div>
          </div>
          <div class="col-12">
            <div class="unit-modal-card">
              <h6>3 · Lehrplanthema optional</h6>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label fw-bold">Lehrplanthema</label>
                  <select class="form-select" name="curriculum_topic_content_id" id="unitTopicSelect">
                    <option value="">Kein Lehrplanthema wählen</option>
                    <?php foreach ($topicsForSelect as $topic): ?><option value="<?= (int)$topic['id'] ?>"><?= teacher_h(teacher_library_topic_label($topic)) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label fw-bold">Unterthema / Skill</label>
                  <select class="form-select" name="curriculum_topic_subtopic_id" id="unitSubtopicSelect"><option value="">Ganzes Thema oder kein Skill</option></select>
                </div>
              </div>
              <div class="form-text mt-2">Das Thema hilft später dem KI-Wizard, zielgenau Quizzes, Worksheets oder Listenings zu erzeugen.</div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Abbrechen</button><button class="btn btn-primary btn-lg" type="submit">Unit anlegen</button></div>
    </form>
  </div>
</div>

<script>
(function(){
  const subtopicsByTopic = <?= json_encode(array_map(static function($rows){ return array_map(static function($row){ return ['id'=>(int)$row['id'],'title'=>teacher_library_subtopic_label($row)]; }, $rows); }, $subtopicsByTopic), JSON_UNESCAPED_UNICODE) ?>;
  const topicSelect = document.getElementById('unitTopicSelect');
  const subtopicSelect = document.getElementById('unitSubtopicSelect');
  function refreshSubtopics(){
    if(!topicSelect || !subtopicSelect) return;
    const rows = subtopicsByTopic[topicSelect.value] || [];
    subtopicSelect.innerHTML = '<option value="">Ganzes Thema oder kein Skill</option>';
    rows.forEach(row => {
      const opt = document.createElement('option');
      opt.value = row.id;
      opt.textContent = row.title;
      subtopicSelect.appendChild(opt);
    });
  }
  if(topicSelect) topicSelect.addEventListener('change', refreshSubtopics);

  const root = document.querySelector('[data-unit-filters]');
  const cards = Array.from(document.querySelectorAll('[data-unit-card]'));
  if(!root || !cards.length) return;
  const search = root.querySelector('[data-filter-search]');
  const subject = root.querySelector('[data-filter-subject]');
  const grade = root.querySelector('[data-filter-grade]');
  const topic = root.querySelector('[data-filter-topic]');
  const format = root.querySelector('[data-filter-format]');
  const reset = root.querySelector('[data-filter-reset]');
  function apply(){
    const q = (search.value || '').toLowerCase().trim();
    cards.forEach(card => {
      const ok = (!q || (card.dataset.search || '').includes(q))
        && (!subject.value || card.dataset.subject === subject.value)
        && (!grade.value || card.dataset.grade === grade.value)
        && (!topic.value || card.dataset.topic === topic.value)
        && (!format.value || (card.dataset.formats || '').includes(format.value));
      card.classList.toggle('d-none', !ok);
    });
  }
  [search,subject,grade,topic,format].forEach(el => el && el.addEventListener('input', apply));
  reset && reset.addEventListener('click', () => { [search,subject,grade,topic,format].forEach(el => { if(el) el.value=''; }); apply(); });
})();
</script>
<?php teacher_footer(); ?>
