<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/library_units.php';

teacher_library_ensure_share_schema();
$pdo = teacher_db();
$teacherId = teacher_current_user_id();
$currentUser = auth_user();
$currentUserEmail = trim((string)($currentUser['email'] ?? ''));
$libraryView = (($_GET['view'] ?? 'own') === 'shared') ? 'shared' : 'own';
$classes = teacher_classes();
$notice = '';
$error = '';

function teacher_library_fetch_items(PDO $pdo, int $teacherId): array
{
    $items = [];

    if (teacher_column_exists('quizzes', 'created_by_user_id')) {
        $curriculumJoin = '';
        $curriculumSelect = "NULL AS curriculum_topic_content_id, NULL AS curriculum_topic_subtopic_id, NULL AS topic_label, NULL AS subtopic_label";
        $curriculumGroup = '';
        if (teacher_table_exists('quiz_curriculum_topics') && teacher_table_exists('curriculum_topics_content')) {
            $curriculumJoin = "
                LEFT JOIN quiz_curriculum_topics qct ON qct.quiz_id = q.id
                LEFT JOIN curriculum_topics_content ctc ON ctc.id = qct.curriculum_topic_content_id
                LEFT JOIN curriculum_topic_subtopics cts ON cts.id = qct.curriculum_topic_subtopic_id
            ";
            $curriculumSelect = "
                qct.curriculum_topic_content_id AS curriculum_topic_content_id,
                qct.curriculum_topic_subtopic_id AS curriculum_topic_subtopic_id,
                COALESCE(NULLIF(ctc.title_short, ''), NULLIF(ctc.topic_title, ''), NULLIF(ctc.title_long, '')) AS topic_label,
                COALESCE(NULLIF(cts.title_short, ''), NULLIF(cts.subtopic_title, ''), NULLIF(cts.title_long, '')) AS subtopic_label
            ";
            $curriculumGroup = ', qct.curriculum_topic_content_id, qct.curriculum_topic_subtopic_id, ctc.id, cts.id';
        }

        $stmt = $pdo->prepare("SELECT
                q.id, q.quiz_key, q.title, q.description, q.grade, q.created_at, q.updated_at,
                q.image_path, q.theme_emoji, q.listening_mode, q.listening_status, q.status,
                sub.code AS subject_code, sub.name AS subject_name,
                {$curriculumSelect},
                COUNT(qq.id) AS question_count
            FROM quizzes q
            LEFT JOIN subjects sub ON sub.id = q.subject_id
            LEFT JOIN questions qq ON qq.quiz_id = q.id
            {$curriculumJoin}
            WHERE q.created_by_user_id = :teacher_id
              AND (q.source_type = 'teacher' OR q.ai_generated = 1 OR q.created_by_user_id IS NOT NULL)
            GROUP BY q.id, sub.id{$curriculumGroup}
            ORDER BY COALESCE(q.updated_at, q.created_at) DESC, q.id DESC");
        $stmt->execute(['teacher_id' => $teacherId]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $type = teacher_library_item_type($row);
            $subjectLabel = teacher_library_subject_label($row['subject_code'] ?? null, $row['subject_name'] ?? null);
            $items[] = [
                'type' => $type,
                'id' => (int)$row['id'],
                'ref' => $type . ':' . (int)$row['id'],
                'title' => (string)($row['title'] ?? 'Unbenanntes Quiz'),
                'description' => (string)($row['description'] ?? ''),
                'subject_code' => (string)($row['subject_code'] ?? ''),
                'subject_label' => $subjectLabel,
                'grade' => (string)($row['grade'] ?? ''),
                'curriculum_topic_content_id' => (int)($row['curriculum_topic_content_id'] ?? 0),
                'curriculum_topic_subtopic_id' => (int)($row['curriculum_topic_subtopic_id'] ?? 0),
                'topic_label' => (string)($row['topic_label'] ?? ''),
                'subtopic_label' => (string)($row['subtopic_label'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
                'question_count' => (int)($row['question_count'] ?? 0),
                'image_path' => (string)($row['image_path'] ?? ''),
                'emoji' => (string)(($row['theme_emoji'] ?? '') ?: ($type === 'listening' ? '🎧' : '🎮')),
                'status' => (string)($row['status'] ?? ''),
                'url' => !empty($row['quiz_key']) ? '/quiz.php?key=' . urlencode((string)$row['quiz_key']) : '',
                'edit_url' => '/admin/quiz_questions.php?quiz_id=' . (int)$row['id'],
                'pdf_url' => '',
            ];
        }
    }

    if (teacher_table_exists('teacher_custom_quizzes')) {
        $customCurriculumJoin = '';
        $customCurriculumSelect = "NULL AS curriculum_topic_content_id, NULL AS curriculum_topic_subtopic_id, NULL AS topic_label, NULL AS subtopic_label";
        $customCurriculumGroup = '';
        if (teacher_table_exists('quiz_curriculum_topics') && teacher_table_exists('curriculum_topics_content')) {
            $customCurriculumJoin = "
            LEFT JOIN quiz_curriculum_topics qct ON qct.quiz_id = sq.id
            LEFT JOIN curriculum_topics_content ctc ON ctc.id = qct.curriculum_topic_content_id
            LEFT JOIN curriculum_topic_subtopics cts ON cts.id = qct.curriculum_topic_subtopic_id";
            $customCurriculumSelect = "
                qct.curriculum_topic_content_id AS curriculum_topic_content_id,
                qct.curriculum_topic_subtopic_id AS curriculum_topic_subtopic_id,
                COALESCE(NULLIF(ctc.title_short, ''), NULLIF(ctc.topic_title, ''), NULLIF(ctc.title_long, '')) AS topic_label,
                COALESCE(NULLIF(cts.title_short, ''), NULLIF(cts.subtopic_title, ''), NULLIF(cts.title_long, '')) AS subtopic_label";
            $customCurriculumGroup = ', qct.curriculum_topic_content_id, qct.curriculum_topic_subtopic_id, ctc.id, cts.id';
        }
        $stmt = $pdo->prepare("SELECT
                cq.id, cq.title, cq.description, cq.created_at, cq.updated_at, cq.class_id, cq.source_quiz_id,
                tc.name AS class_name, tc.grade AS class_grade, tc.subject_code AS class_subject_code,
                sq.grade AS source_grade, sq.theme_emoji AS source_emoji, sq.quiz_key AS source_quiz_key,
                sub.code AS subject_code, sub.name AS subject_name,
                {$customCurriculumSelect},
                COUNT(cqq.id) AS question_count
            FROM teacher_custom_quizzes cq
            LEFT JOIN teacher_custom_quiz_questions cqq ON cqq.custom_quiz_id = cq.id
            LEFT JOIN teacher_classes tc ON tc.id = cq.class_id
            LEFT JOIN quizzes sq ON sq.id = cq.source_quiz_id
            LEFT JOIN subjects sub ON sub.id = sq.subject_id OR sub.code = tc.subject_code
            {$customCurriculumJoin}
            WHERE cq.teacher_id = :teacher_id
            GROUP BY cq.id, tc.id, sq.id, sub.id{$customCurriculumGroup}
            ORDER BY COALESCE(cq.updated_at, cq.created_at) DESC, cq.id DESC");
        $stmt->execute(['teacher_id' => $teacherId]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $subjectLabel = teacher_library_subject_label($row['subject_code'] ?? $row['class_subject_code'] ?? null, $row['subject_name'] ?? null);
            $grade = (string)(($row['class_grade'] ?? '') ?: ($row['source_grade'] ?? ''));
            $items[] = [
                'type' => 'worksheet',
                'id' => (int)$row['id'],
                'ref' => 'worksheet:' . (int)$row['id'],
                'title' => (string)($row['title'] ?? 'Arbeitsblatt'),
                'description' => (string)(($row['description'] ?? '') ?: 'Ausgewählte Fragen als PDF-Arbeitsblatt.'),
                'subject_code' => (string)(($row['subject_code'] ?? '') ?: ($row['class_subject_code'] ?? '')),
                'subject_label' => $subjectLabel,
                'grade' => $grade,
                'curriculum_topic_content_id' => (int)($row['curriculum_topic_content_id'] ?? 0),
                'curriculum_topic_subtopic_id' => (int)($row['curriculum_topic_subtopic_id'] ?? 0),
                'topic_label' => (string)($row['topic_label'] ?? ''),
                'subtopic_label' => (string)($row['subtopic_label'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
                'question_count' => (int)($row['question_count'] ?? 0),
                'image_path' => '',
                'emoji' => '📄',
                'status' => 'saved',
                'url' => '',
                'edit_url' => 'worksheet_editor.php?custom_quiz_id=' . (int)$row['id'],
                'pdf_url' => 'material_pdf.php?custom_quiz_id=' . (int)$row['id'],
                'class_name' => (string)($row['class_name'] ?? ''),
            ];
        }
    }

    return $items;
}

function teacher_library_build_units(array $items, array $classes): array
{
    $units = [];
    foreach ($items as $item) {
        $unitId = teacher_library_upsert_unit($item);
        $unitKey = $unitId > 0 ? 'unit-' . $unitId : teacher_library_unit_key($item);
        if (!isset($units[$unitKey])) {
            $units[$unitKey] = [
                'id' => $unitId,
                'title' => teacher_library_unit_title_from_item($item),
                'description' => (string)($item['description'] ?? ''),
                'subject_code' => (string)($item['subject_code'] ?? ''),
                'subject_label' => (string)($item['subject_label'] ?? ''),
                'grade' => (string)($item['grade'] ?? ''),
                'topic_label' => (string)($item['topic_label'] ?? ''),
                'subtopic_label' => (string)($item['subtopic_label'] ?? ''),
                'curriculum_topic_content_id' => (int)($item['curriculum_topic_content_id'] ?? 0),
                'curriculum_topic_subtopic_id' => (int)($item['curriculum_topic_subtopic_id'] ?? 0),
                'items' => [],
                'updated_at' => (string)($item['updated_at'] ?? ''),
                'image_path' => (string)($item['image_path'] ?? ''),
                'emoji' => (string)(($item['emoji'] ?? '') ?: '🧩'),
            ];
        }
        $units[$unitKey]['items'][] = $item;
        if (empty($units[$unitKey]['image_path']) && !empty($item['image_path'])) {
            $units[$unitKey]['image_path'] = (string)$item['image_path'];
        }
        if (strcmp((string)($item['updated_at'] ?? ''), (string)($units[$unitKey]['updated_at'] ?? '')) > 0) {
            $units[$unitKey]['updated_at'] = (string)$item['updated_at'];
        }
    }
    usort($units, static fn(array $a, array $b): int => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    return $units;
}

$items = teacher_library_fetch_items($pdo, $teacherId);
$ownUnits = teacher_library_build_units($items, $classes);
$sharedUnits = teacher_library_shared_units_for_user($teacherId, $currentUserEmail);
$units = $libraryView === 'shared' ? $sharedUnits : $ownUnits;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        $unitId = (int)($_POST['unit_id'] ?? 0);
        $unit = teacher_library_unit_by_id($unitId);
        if (!$unit) {
            throw new RuntimeException('Unit wurde nicht gefunden.');
        }
        $selectedRefs = array_values(array_filter(array_map('strval', (array)($_POST['item_refs'] ?? []))));
        $selectedParsed = [];
        foreach ($selectedRefs as $ref) {
            $parsed = teacher_library_parse_item_ref($ref);
            if ($parsed) $selectedParsed[$parsed['ref']] = $parsed;
        }
        if (!$selectedParsed) {
            throw new RuntimeException('Bitte mindestens einen Inhalt auswählen.');
        }
        $unitItems = [];
        foreach ($units as $candidate) {
            if ((int)$candidate['id'] === $unitId) {
                foreach ($candidate['items'] as $item) {
                    $unitItems[(string)$item['ref']] = $item;
                }
                break;
            }
        }
        $selectedItems = [];
        foreach (array_keys($selectedParsed) as $ref) {
            if (!isset($unitItems[$ref])) {
                throw new RuntimeException('Ein ausgewählter Inhalt gehört nicht zu dieser Unit.');
            }
            $selectedItems[] = $unitItems[$ref];
        }

        if ($action === 'share_unit_class') {
            $classId = (int)($_POST['class_id'] ?? 0);
            if ($classId <= 0) throw new RuntimeException('Bitte eine Klasse auswählen.');
            $stmt = $pdo->prepare('SELECT id FROM teacher_classes WHERE id = :class_id AND teacher_id = :teacher_id LIMIT 1');
            $stmt->execute(['class_id' => $classId, 'teacher_id' => $teacherId]);
            if (!$stmt->fetchColumn()) throw new RuntimeException('Diese Klasse gehört nicht zu deinem Account.');

            $pdo->prepare('INSERT IGNORE INTO teacher_unit_class_links (unit_id, class_id, teacher_id) VALUES (:unit_id, :class_id, :teacher_id)')
                ->execute(['unit_id' => $unitId, 'class_id' => $classId, 'teacher_id' => $teacherId]);

            $linkItem = $pdo->prepare('INSERT IGNORE INTO teacher_unit_item_class_links (unit_id, class_id, teacher_id, item_type, item_ref_id) VALUES (:unit_id, :class_id, :teacher_id, :item_type, :item_ref_id)');
            foreach ($selectedParsed as $parsed) {
                $linkItem->execute([
                    'unit_id' => $unitId,
                    'class_id' => $classId,
                    'teacher_id' => $teacherId,
                    'item_type' => $parsed['type'],
                    'item_ref_id' => $parsed['id'],
                ]);
            }
            $notice = 'Die ausgewählten Inhalte wurden für die Klasse freigegeben.';
        } elseif ($action === 'share_unit_colleague') {
            $email = trim((string)($_POST['recipient_email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Bitte eine gültige E-Mail-Adresse eingeben.');
            }
            $token = bin2hex(random_bytes(24));
            $stmt = $pdo->prepare('INSERT INTO teacher_unit_colleague_shares (unit_id, teacher_id, recipient_email, token, item_refs_json, guest_expires_at, expires_at) VALUES (:unit_id, :teacher_id, :recipient_email, :token, :item_refs_json, DATE_ADD(NOW(), INTERVAL 24 HOUR), DATE_ADD(NOW(), INTERVAL 30 DAY))');
            $stmt->execute([
                'unit_id' => $unitId,
                'teacher_id' => $teacherId,
                'recipient_email' => $email,
                'token' => $token,
                'item_refs_json' => json_encode(array_keys($selectedParsed), JSON_UNESCAPED_UNICODE),
            ]);
            $url = teacher_library_share_public_url($token);
            teacher_library_send_unit_share_mail($email, $unit, $selectedItems, $url);
            $notice = 'Die Unit-Vorschau wurde per E-Mail verschickt.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $items = teacher_library_fetch_items($pdo, $teacherId);
    $ownUnits = teacher_library_build_units($items, $classes);
    $sharedUnits = teacher_library_shared_units_for_user($teacherId, $currentUserEmail);
    $units = $libraryView === 'shared' ? $sharedUnits : $ownUnits;
}

$subjects = [];
$grades = [];
$topics = [];
$formatCounts = ['quiz' => 0, 'worksheet' => 0, 'listening' => 0, 'reading' => 0];
foreach ($units as $unit) {
    if (!empty($unit['subject_label'])) $subjects[$unit['subject_label']] = true;
    if ((string)($unit['grade'] ?? '') !== '') $grades[(string)$unit['grade']] = true;
    $topicFilter = trim((string)(($unit['subtopic_label'] ?? '') ?: ($unit['topic_label'] ?? '')));
    if ($topicFilter !== '') $topics[$topicFilter] = true;
    foreach ($unit['items'] as $item) $formatCounts[$item['type']] = ($formatCounts[$item['type']] ?? 0) + 1;
}
$subjects = array_keys($subjects); sort($subjects, SORT_NATURAL | SORT_FLAG_CASE);
$grades = array_keys($grades); sort($grades, SORT_NATURAL | SORT_FLAG_CASE);
$topics = array_keys($topics); sort($topics, SORT_NATURAL | SORT_FLAG_CASE);

teacher_header('Meine Bibliothek', $libraryView === 'shared' ? 'Inhalte, die Kolleginnen und Kollegen mit dir geteilt haben.' : 'Deine selbst erstellten Units und Materialien – unabhängig von einzelnen Klassen.');
?>
<style>
  .library-hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:22px;align-items:center;margin-bottom:22px;padding:26px;border-radius:32px;background:linear-gradient(135deg,#f7f7ff 0%,#eef2ff 52%,#fff 100%);border:1px solid rgba(90,79,243,.14);box-shadow:0 22px 60px rgba(23,32,51,.07)}
  .library-hero h2{font-weight:950;color:#172033;margin:0 0 7px;font-size:1.55rem}.library-hero p{margin:0;color:#64748b;max-width:820px;line-height:1.45}.library-hero-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.library-hero-actions .btn{border-radius:999px;font-weight:850}
  .library-view-tabs{display:inline-flex;gap:6px;padding:6px;border-radius:999px;background:#fff;border:1px solid rgba(23,32,51,.08);box-shadow:0 12px 34px rgba(23,32,51,.06);margin-bottom:18px}.library-view-tabs a{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;font-weight:900;color:#64748b;text-decoration:none}.library-view-tabs a.active{background:#172033;color:#fff}
  .library-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}.library-stat{padding:16px 17px;border-radius:24px;background:#fff;border:1px solid rgba(23,32,51,.08);box-shadow:0 14px 36px rgba(23,32,51,.055)}.library-stat span{display:block;font-weight:900;font-size:.76rem;text-transform:uppercase;letter-spacing:.05em;color:#7c8494}.library-stat strong{display:flex;align-items:center;gap:8px;margin-top:6px;font-size:1.35rem;color:#172033;font-weight:950}
  .library-filters{position:sticky;top:0;z-index:4;display:grid;grid-template-columns:minmax(220px,1.25fr) repeat(4,minmax(130px,.55fr)) auto;gap:10px;align-items:center;padding:12px;margin-bottom:18px;border-radius:24px;background:rgba(255,255,255,.9);border:1px solid rgba(23,32,51,.08);box-shadow:0 14px 42px rgba(23,32,51,.075);backdrop-filter:blur(16px)}.library-filters .form-control,.library-filters .form-select{border-radius:999px;border-color:rgba(23,32,51,.10);font-weight:750}.library-reset{border-radius:999px;white-space:nowrap}
  .unit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}.unit-card{position:relative;overflow:hidden;border-radius:32px;background:#fff;border:1px solid rgba(23,32,51,.08);box-shadow:0 18px 48px rgba(23,32,51,.065);transition:transform .16s ease,box-shadow .16s ease}.unit-card:hover{transform:translateY(-2px);box-shadow:0 26px 70px rgba(23,32,51,.10)}.unit-cover{height:170px;background:linear-gradient(135deg,#5a4ff3,#8b7cff 58%,#22d3ee);background-size:cover;background-position:center;position:relative}.unit-cover::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(23,32,51,0) 30%,rgba(23,32,51,.46) 100%)}.unit-cover-symbol{position:absolute;left:22px;bottom:18px;z-index:1;width:54px;height:54px;border-radius:20px;background:rgba(255,255,255,.92);display:flex;align-items:center;justify-content:center;font-size:1.55rem;box-shadow:0 14px 38px rgba(23,32,51,.16)}.unit-menu{position:absolute;right:16px;top:16px;z-index:2}.unit-menu .btn{width:42px;height:42px;border-radius:999px;background:rgba(255,255,255,.9);border:1px solid rgba(255,255,255,.75);font-weight:950;box-shadow:0 12px 32px rgba(23,32,51,.18)}.unit-body{padding:20px 22px 22px}.unit-kicker{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:11px}.unit-kicker span{font-size:.73rem;font-weight:900;color:#4f46e5;background:#f1f5ff;border-radius:999px;padding:5px 8px}.unit-title{margin:0;font-size:1.35rem;line-height:1.08;font-weight:950;color:#172033}.unit-shared-by{display:inline-flex;gap:6px;align-items:center;margin-top:10px;padding:6px 9px;border-radius:999px;background:#f8fafc;color:#64748b;font-size:.76rem;font-weight:850}.unit-sub{margin-top:8px;color:#64748b;font-size:.92rem;line-height:1.35}.unit-format-strip{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px}.format-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:7px 10px;font-size:.77rem;font-weight:950;background:#f8fafc;border:1px solid rgba(23,32,51,.07);color:#334155}.unit-cta{margin-top:18px;padding-top:17px;border-top:1px solid rgba(23,32,51,.07)}.unit-add-toggle{width:100%;border-radius:20px;padding:13px 16px;font-weight:950;text-align:left;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#f7f6ff,#fff);border:1px solid rgba(90,79,243,.18);color:#4037c9}.unit-add-panel{display:none;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:10px}.unit-add-panel.is-open{display:grid}.unit-add-panel .btn{border-radius:18px;font-weight:900;padding:12px 10px}.library-empty{padding:38px;border-radius:28px;text-align:center;background:#fff;border:1px dashed rgba(90,79,243,.28);color:#64748b}.library-empty strong{display:block;color:#172033;font-size:1.2rem;margin-bottom:6px}.library-hidden{display:none!important}.share-modal-list{display:flex;flex-direction:column;gap:8px}.share-modal-item{display:flex;gap:10px;align-items:center;padding:11px;border:1px solid rgba(23,32,51,.08);border-radius:16px;background:#f8fafc}.share-modal-item strong{display:block;line-height:1.2}.share-modal-item small{color:#64748b;font-weight:750}.dropdown-menu.unit-actions-menu{border:0;border-radius:20px;padding:8px;box-shadow:0 22px 54px rgba(23,32,51,.16)}.unit-actions-menu .dropdown-item{border-radius:14px;font-weight:850;padding:10px 12px}
  @media(max-width:1180px){.unit-grid{grid-template-columns:1fr}.library-filters{grid-template-columns:1fr 1fr}.library-hero{grid-template-columns:1fr}.library-hero-actions{justify-content:flex-start}}
  @media(max-width:720px){.library-stats{grid-template-columns:1fr 1fr}.library-filters{grid-template-columns:1fr}.unit-add-panel{grid-template-columns:1fr}.unit-cover{height:145px}}
</style>

<?php if ($notice): ?><div class="alert alert-success rounded-4"><?= teacher_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger rounded-4"><?= teacher_h($error) ?></div><?php endif; ?>

<div class="library-hero">
  <div>
    <h2>Deine Units bündeln alles, was du selbst erstellt hast.</h2>
    <p>Elevaro clustert deine Quizze, Arbeitsblätter und Listenings automatisch nach Fach, Klasse und Lehrplanthema. Du kannst pro Unit neue Inhalte erstellen oder gezielt Inhalte für Klassen und Kollegen freigeben.</p>
  </div>
  <div class="library-hero-actions">
    <a class="btn btn-primary" href="ai_wizard.php">✨ Neues Material erstellen</a>
    <a class="btn btn-outline-primary" href="classes.php">🏫 Klassen verwalten</a>
  </div>
</div>

<div class="library-view-tabs" role="tablist" aria-label="Bibliothek Ansicht">
  <a class="<?= $libraryView === 'own' ? 'active' : '' ?>" href="materials.php?view=own">Meine Inhalte</a>
  <a class="<?= $libraryView === 'shared' ? 'active' : '' ?>" href="materials.php?view=shared">Für mich freigegeben<?php if ($sharedUnits): ?> <span class="badge rounded-pill text-bg-light text-dark"><?= count($sharedUnits) ?></span><?php endif; ?></a>
</div>

<div class="library-stats">
  <div class="library-stat"><span>Units</span><strong>🧩 <?= count($units) ?></strong></div>
  <div class="library-stat"><span>Quizze</span><strong>🎮 <?= (int)$formatCounts['quiz'] ?></strong></div>
  <div class="library-stat"><span>Arbeitsblätter</span><strong>📄 <?= (int)$formatCounts['worksheet'] ?></strong></div>
  <div class="library-stat"><span>Listenings</span><strong>🎧 <?= (int)$formatCounts['listening'] ?></strong></div>
</div>

<div class="library-filters" data-library-filters>
  <input class="form-control" type="search" placeholder="Unit, Inhalt oder Fach suchen …" data-filter-search>
  <select class="form-select" data-filter-format>
    <option value="">Alle Formate</option><option value="quiz">Quiz</option><option value="worksheet">Arbeitsblatt</option><option value="listening">Listening</option><option value="reading">Leseverständnis</option>
  </select>
  <select class="form-select" data-filter-subject><option value="">Alle Fächer</option><?php foreach ($subjects as $subject): ?><option value="<?= teacher_h(teacher_library_norm($subject)) ?>"><?= teacher_h($subject) ?></option><?php endforeach; ?></select>
  <select class="form-select" data-filter-grade><option value="">Alle Klassen</option><?php foreach ($grades as $grade): ?><option value="<?= teacher_h(teacher_library_norm((string)$grade)) ?>">Klasse <?= teacher_h((string)$grade) ?></option><?php endforeach; ?></select>
  <select class="form-select" data-filter-topic><option value="">Alle Lehrplanthemen</option><?php foreach ($topics as $topic): ?><option value="<?= teacher_h(teacher_library_norm($topic)) ?>"><?= teacher_h($topic) ?></option><?php endforeach; ?></select>
  <button class="btn btn-light library-reset" type="button" data-filter-reset>Zurücksetzen</button>
</div>

<?php if (!$units): ?>
  <div class="library-empty">
    <?php if ($libraryView === 'shared'): ?>
      <strong>Noch keine Freigaben.</strong>Wenn Kolleginnen oder Kollegen Inhalte mit dir teilen, erscheinen sie hier.
    <?php else: ?>
      <strong>Noch keine eigenen Materialien gespeichert.</strong>Starte mit „Neues Material erstellen“. Danach erscheinen deine Inhalte hier automatisch als Units.
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="unit-grid" data-library-grid>
    <?php foreach ($units as $unit): ?>
      <?php
        $unitId = (int)$unit['id'];
        $formatTypes = array_values(array_unique(array_map(static fn($i) => (string)$i['type'], $unit['items'])));
        $formatFilter = implode(' ', array_map('teacher_library_norm', $formatTypes));
        $topicText = trim((string)(($unit['subtopic_label'] ?? '') ?: ($unit['topic_label'] ?? '')));
        $searchText = teacher_library_norm(($unit['title'] ?? '') . ' ' . ($unit['description'] ?? '') . ' ' . ($unit['subject_label'] ?? '') . ' ' . $topicText . ' ' . implode(' ', array_column($unit['items'], 'title')));
        $classParam = teacher_library_first_class_url_param($unit, $classes);
        $commonParams = 'unit_id=' . $unitId . $classParam;
        $canListening = teacher_library_is_foreign_language((string)$unit['subject_code'], (string)$unit['subject_label']);
        $updated = !empty($unit['updated_at']) ? date('d.m.Y', strtotime((string)$unit['updated_at'])) : '';
        $cover = trim((string)($unit['image_path'] ?? ''));
      ?>
      <article class="unit-card" data-library-unit data-search="<?= teacher_h($searchText) ?>" data-format="<?= teacher_h($formatFilter) ?>" data-subject="<?= teacher_h(teacher_library_norm((string)$unit['subject_label'])) ?>" data-grade="<?= teacher_h(teacher_library_norm((string)$unit['grade'])) ?>" data-topic="<?= teacher_h(teacher_library_norm($topicText)) ?>">
        <div class="unit-cover" <?= $cover !== '' ? 'style="background-image:url(' . teacher_h($cover) . ')"' : '' ?>>
          <div class="unit-cover-symbol"><?= teacher_h((string)(($unit['emoji'] ?? '') ?: '🧩')) ?></div>
          <?php if (empty($unit['is_shared'])): ?><div class="dropdown unit-menu">
            <button class="btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">⋯</button>
            <ul class="dropdown-menu dropdown-menu-end unit-actions-menu">
              <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#shareClass<?= $unitId ?>">🏫 Inhalte für Klasse freigeben</button></li>
              <li><button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#shareColleague<?= $unitId ?>">🤝 Inhalte für Kollegen freigeben</button></li>
            </ul>
          </div><?php endif; ?>
        </div>
        <div class="unit-body">
          <div class="unit-kicker">
            <span><?= teacher_h((string)$unit['subject_label']) ?></span>
            <?php if ((string)$unit['grade'] !== ''): ?><span>Klasse <?= teacher_h((string)$unit['grade']) ?></span><?php endif; ?>
            <?php if ($topicText !== ''): ?><span><?= teacher_h($topicText) ?></span><?php endif; ?>
          </div>
          <h3 class="unit-title"><?= teacher_h((string)$unit['title']) ?></h3>
          <div class="unit-sub"><?= count($unit['items']) ?> Inhalt<?= count($unit['items']) === 1 ? '' : 'e' ?> in dieser Unit<?= $updated ? ' · aktualisiert am ' . teacher_h($updated) : '' ?></div>
          <?php if (!empty($unit['is_shared'])): ?><div class="unit-shared-by">🤝 geteilt von <?= teacher_h((string)($unit['owner_name'] ?? 'Kolleg:in')) ?></div><?php endif; ?>
          <div class="unit-format-strip"><?php foreach ($formatTypes as $format): ?><span class="format-pill"><?= teacher_library_type_icon($format) ?> <?= teacher_h(teacher_library_type_label($format)) ?></span><?php endforeach; ?></div>
          <?php if (empty($unit['is_shared'])): ?>
            <div class="unit-cta">
              <button class="unit-add-toggle" type="button" data-unit-add-toggle>+ Neuen Inhalt für diese Unit erstellen <span>⌄</span></button>
              <div class="unit-add-panel" data-unit-add-panel>
                <a class="btn btn-primary" href="ai_wizard.php?<?= teacher_h($commonParams) ?>&content_type=quiz">🎮 Quiz</a>
                <a class="btn btn-outline-primary" href="worksheet_editor.php?<?= teacher_h($commonParams) ?>">📄 Arbeitsblatt</a>
                <?php if ($canListening): ?><a class="btn btn-outline-primary" href="listening_wizard.php?<?= teacher_h($commonParams) ?>">🎧 Listening</a><?php endif; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="unit-cta">
              <a class="btn btn-primary w-100 rounded-pill fw-bold" href="/shared_unit.php?token=<?= urlencode((string)$unit['share_token']) ?>">Freigabe öffnen</a>
            </div>
          <?php endif; ?>
        </div>
      </article>

      <?php if (empty($unit['is_shared'])): ?>
      <div class="modal fade" id="shareClass<?= $unitId ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content border-0 rounded-5 shadow-lg">
          <input type="hidden" name="action" value="share_unit_class"><input type="hidden" name="unit_id" value="<?= $unitId ?>">
          <div class="modal-header border-0 pb-0"><div><div class="small text-uppercase fw-black text-primary">Unit freigeben</div><h5 class="modal-title fw-black">Inhalte für Klasse freigeben</h5></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button></div>
          <div class="modal-body">
            <div class="share-modal-list mb-3">
              <?php foreach ($unit['items'] as $item): ?><label class="share-modal-item"><input class="form-check-input mt-0" type="checkbox" name="item_refs[]" value="<?= teacher_h((string)$item['ref']) ?>" checked><span><strong><?= teacher_h((string)$item['title']) ?></strong><small><?= teacher_h(teacher_library_type_label((string)$item['type'])) ?> · <?= (int)$item['question_count'] ?> Fragen</small></span></label><?php endforeach; ?>
            </div>
            <label class="form-label fw-bold">Klasse auswählen</label><select class="form-select rounded-pill" name="class_id" required><option value="">Bitte wählen …</option><?php foreach (teacher_library_classes_matching_unit($unit, $classes) as $class): ?><option value="<?= (int)$class['id'] ?>"><?= teacher_h(teacher_class_label($class)) ?></option><?php endforeach; ?></select>
          </div>
          <div class="modal-footer border-0 pt-0"><button class="btn btn-light rounded-pill" type="button" data-bs-dismiss="modal">Abbrechen</button><button class="btn btn-primary rounded-pill" type="submit">Freigeben</button></div>
        </form></div>
      </div>

      <div class="modal fade" id="shareColleague<?= $unitId ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content border-0 rounded-5 shadow-lg">
          <input type="hidden" name="action" value="share_unit_colleague"><input type="hidden" name="unit_id" value="<?= $unitId ?>">
          <div class="modal-header border-0 pb-0"><div><div class="small text-uppercase fw-black text-primary">Kollegenfreigabe</div><h5 class="modal-title fw-black">Unit per E-Mail teilen</h5></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button></div>
          <div class="modal-body">
            <div class="share-modal-list mb-3">
              <?php foreach ($unit['items'] as $item): ?><label class="share-modal-item"><input class="form-check-input mt-0" type="checkbox" name="item_refs[]" value="<?= teacher_h((string)$item['ref']) ?>" checked><span><strong><?= teacher_h((string)$item['title']) ?></strong><small><?= teacher_h(teacher_library_type_label((string)$item['type'])) ?> · <?= (int)$item['question_count'] ?> Fragen</small></span></label><?php endforeach; ?>
            </div>
            <label class="form-label fw-bold">E-Mail-Adresse</label><input class="form-control rounded-pill" name="recipient_email" type="email" placeholder="kollege@schule.de" required>
            <div class="form-text mt-2">Elevaro sendet eine gestaltete Vorschau mit CTA „Inhalt anzeigen“.</div>
          </div>
          <div class="modal-footer border-0 pt-0"><button class="btn btn-light rounded-pill" type="button" data-bs-dismiss="modal">Abbrechen</button><button class="btn btn-primary rounded-pill" type="submit">E-Mail senden</button></div>
        </form></div>
      </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <div class="library-empty library-hidden mt-3" data-library-no-results><strong>Keine Treffer.</strong>Passe Suche oder Filter an.</div>
<?php endif; ?>

<script>
(() => {
  document.querySelectorAll('[data-unit-add-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const panel = button.parentElement.querySelector('[data-unit-add-panel]');
      panel?.classList.toggle('is-open');
    });
  });
  const root = document.querySelector('[data-library-filters]');
  const units = Array.from(document.querySelectorAll('[data-library-unit]'));
  const noResults = document.querySelector('[data-library-no-results]');
  if (!root || !units.length) return;
  const search = root.querySelector('[data-filter-search]');
  const format = root.querySelector('[data-filter-format]');
  const subject = root.querySelector('[data-filter-subject]');
  const grade = root.querySelector('[data-filter-grade]');
  const topic = root.querySelector('[data-filter-topic]');
  const norm = (value) => (value || '').toString().trim().toLowerCase();
  const apply = () => {
    const q = norm(search.value), f = norm(format.value), s = norm(subject.value), g = norm(grade.value), t = norm(topic.value);
    let visible = 0;
    units.forEach((unit) => {
      const matches = (!q || unit.dataset.search.includes(q)) && (!f || unit.dataset.format.includes(f)) && (!s || unit.dataset.subject === s) && (!g || unit.dataset.grade === g) && (!t || unit.dataset.topic === t);
      unit.classList.toggle('library-hidden', !matches);
      if (matches) visible++;
    });
    noResults?.classList.toggle('library-hidden', visible > 0);
  };
  [search, format, subject, grade, topic].forEach((el) => el?.addEventListener('input', apply));
  root.querySelector('[data-filter-reset]')?.addEventListener('click', () => { search.value=''; format.value=''; subject.value=''; grade.value=''; topic.value=''; apply(); });
  apply();
})();
</script>
<?php teacher_footer(); ?>
