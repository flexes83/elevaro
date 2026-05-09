<?php
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/library_units.php';

teacher_library_ensure_schema();
$pdo = teacher_db();
$teacherId = teacher_current_user_id();
$classes = teacher_classes();
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'assign_unit_class') {
            $unitId = (int)($_POST['unit_id'] ?? 0);
            $classId = (int)($_POST['class_id'] ?? 0);
            if ($unitId <= 0 || $classId <= 0 || !teacher_library_unit_by_id($unitId)) {
                throw new RuntimeException('Unit oder Klasse wurde nicht gefunden.');
            }
            $stmt = $pdo->prepare("SELECT id FROM teacher_classes WHERE id = :class_id AND teacher_id = :teacher_id LIMIT 1");
            $stmt->execute(['class_id' => $classId, 'teacher_id' => $teacherId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('Diese Klasse gehört nicht zu deinem Account.');
            }
            $link = $pdo->prepare("INSERT IGNORE INTO teacher_unit_class_links (unit_id, class_id, teacher_id) VALUES (:unit_id, :class_id, :teacher_id)");
            $link->execute(['unit_id' => $unitId, 'class_id' => $classId, 'teacher_id' => $teacherId]);
            $notice = 'Unit wurde der Klasse zugeordnet.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function teacher_library_fetch_items(PDO $pdo, int $teacherId): array
{
    $items = [];

    if (teacher_column_exists('quizzes', 'created_by_user_id')) {
        $curriculumJoin = '';
        $curriculumSelect = "NULL AS curriculum_topic_content_id, NULL AS curriculum_topic_subtopic_id, NULL AS topic_label, NULL AS subtopic_label";
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
        }

        $stmt = $pdo->prepare("
            SELECT
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
            GROUP BY q.id, sub.id, qct.curriculum_topic_content_id, qct.curriculum_topic_subtopic_id, ctc.id, cts.id
            ORDER BY COALESCE(q.updated_at, q.created_at) DESC, q.id DESC
        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $type = teacher_library_item_type($row);
            $subjectLabel = teacher_library_subject_label($row['subject_code'] ?? null, $row['subject_name'] ?? null);
            $items[] = [
                'type' => $type,
                'id' => (int)$row['id'],
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
        $stmt = $pdo->prepare("
            SELECT
                cq.id, cq.title, cq.description, cq.created_at, cq.updated_at, cq.class_id, cq.source_quiz_id,
                tc.name AS class_name, tc.grade AS class_grade, tc.subject_code AS class_subject_code,
                sq.grade AS source_grade, sq.theme_emoji AS source_emoji, sq.quiz_key AS source_quiz_key,
                sub.code AS subject_code, sub.name AS subject_name,
                qct.curriculum_topic_content_id AS curriculum_topic_content_id,
                qct.curriculum_topic_subtopic_id AS curriculum_topic_subtopic_id,
                COALESCE(NULLIF(ctc.title_short, ''), NULLIF(ctc.topic_title, ''), NULLIF(ctc.title_long, '')) AS topic_label,
                COALESCE(NULLIF(cts.title_short, ''), NULLIF(cts.subtopic_title, ''), NULLIF(cts.title_long, '')) AS subtopic_label,
                COUNT(cqq.id) AS question_count
            FROM teacher_custom_quizzes cq
            LEFT JOIN teacher_custom_quiz_questions cqq ON cqq.custom_quiz_id = cq.id
            LEFT JOIN teacher_classes tc ON tc.id = cq.class_id
            LEFT JOIN quizzes sq ON sq.id = cq.source_quiz_id
            LEFT JOIN subjects sub ON sub.id = sq.subject_id OR sub.code = tc.subject_code
            LEFT JOIN quiz_curriculum_topics qct ON qct.quiz_id = sq.id
            LEFT JOIN curriculum_topics_content ctc ON ctc.id = qct.curriculum_topic_content_id
            LEFT JOIN curriculum_topic_subtopics cts ON cts.id = qct.curriculum_topic_subtopic_id
            WHERE cq.teacher_id = :teacher_id
            GROUP BY cq.id, tc.id, sq.id, sub.id, qct.curriculum_topic_content_id, qct.curriculum_topic_subtopic_id, ctc.id, cts.id
            ORDER BY COALESCE(cq.updated_at, cq.created_at) DESC, cq.id DESC
        ");
        $stmt->execute(['teacher_id' => $teacherId]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $subjectLabel = teacher_library_subject_label($row['subject_code'] ?? $row['class_subject_code'] ?? null, $row['subject_name'] ?? null);
            $grade = (string)(($row['class_grade'] ?? '') ?: ($row['source_grade'] ?? ''));
            $items[] = [
                'type' => 'worksheet',
                'id' => (int)$row['id'],
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

$items = teacher_library_fetch_items($pdo, $teacherId);
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
        ];
    }
    $units[$unitKey]['items'][] = $item;
    if (strcmp((string)($item['updated_at'] ?? ''), (string)($units[$unitKey]['updated_at'] ?? '')) > 0) {
        $units[$unitKey]['updated_at'] = (string)$item['updated_at'];
    }
}

usort($units, static fn(array $a, array $b): int => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));

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

teacher_header('Meine Bibliothek', 'Deine selbst erstellten Units und Materialien – unabhängig von einzelnen Klassen.');
?>
<style>
  .library-hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:22px;align-items:center;margin-bottom:22px;padding:26px;border-radius:32px;background:linear-gradient(135deg,#f7f7ff 0%,#eef2ff 52%,#fff 100%);border:1px solid rgba(90,79,243,.14);box-shadow:0 22px 60px rgba(23,32,51,.07)}
  .library-hero h2{font-weight:950;color:#172033;margin:0 0 7px;font-size:1.55rem}.library-hero p{margin:0;color:#64748b;max-width:820px;line-height:1.45}.library-hero-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.library-hero-actions .btn{border-radius:999px;font-weight:850}
  .library-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}.library-stat{padding:16px 17px;border-radius:24px;background:#fff;border:1px solid rgba(23,32,51,.08);box-shadow:0 14px 36px rgba(23,32,51,.055)}.library-stat span{display:block;font-weight:900;font-size:.76rem;text-transform:uppercase;letter-spacing:.05em;color:#7c8494}.library-stat strong{display:flex;align-items:center;gap:8px;margin-top:6px;font-size:1.35rem;color:#172033;font-weight:950}
  .library-filters{position:sticky;top:0;z-index:4;display:grid;grid-template-columns:minmax(220px,1.25fr) repeat(4,minmax(130px,.55fr)) auto;gap:10px;align-items:center;padding:12px;margin-bottom:18px;border-radius:24px;background:rgba(255,255,255,.9);border:1px solid rgba(23,32,51,.08);box-shadow:0 14px 42px rgba(23,32,51,.075);backdrop-filter:blur(16px)}.library-filters .form-control,.library-filters .form-select{border-radius:999px;border-color:rgba(23,32,51,.10);font-weight:750}.library-reset{border-radius:999px;white-space:nowrap}
  .unit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.unit-card{position:relative;overflow:hidden;border-radius:30px;background:#fff;border:1px solid rgba(23,32,51,.08);box-shadow:0 18px 48px rgba(23,32,51,.065)}.unit-card::before{content:"";position:absolute;inset:0 0 auto;height:5px;background:linear-gradient(90deg,#5a4ff3,#8b7cff,#22c55e)}.unit-head{padding:20px 20px 16px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;border-bottom:1px solid rgba(23,32,51,.07);background:linear-gradient(180deg,#fff,#fbfbff)}.unit-kicker{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:9px}.unit-kicker span{font-size:.73rem;font-weight:900;color:#4f46e5;background:#f1f5ff;border-radius:999px;padding:5px 8px}.unit-title{display:flex;align-items:center;gap:10px}.unit-title .unit-emoji{width:38px;height:38px;border-radius:15px;background:#f3f1ff;display:inline-flex;align-items:center;justify-content:center;font-size:1.25rem}.unit-title h3{margin:0;font-size:1.17rem;line-height:1.15;font-weight:950;color:#172033}.unit-sub{margin-top:8px;color:#64748b;font-size:.9rem;line-height:1.35}.unit-format-strip{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.format-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:7px 9px;font-size:.76rem;font-weight:950;background:#f8fafc;border:1px solid rgba(23,32,51,.07);color:#334155}.unit-body{padding:16px 20px}.unit-items{display:flex;flex-direction:column;gap:10px}.unit-item{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:10px;align-items:center;padding:10px;border-radius:18px;background:#f8fafc;border:1px solid rgba(23,32,51,.055)}.unit-item-icon{width:36px;height:36px;border-radius:13px;background:#fff;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 6px 16px rgba(23,32,51,.06)}.unit-item strong{display:block;font-size:.9rem;line-height:1.2;color:#172033}.unit-item small{display:block;color:#64748b;font-weight:750;margin-top:2px}.unit-item-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.unit-item-actions .btn{border-radius:999px;font-size:.76rem;font-weight:850}.unit-footer{padding:16px 20px 20px;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center}.unit-add{display:flex;gap:8px;flex-wrap:wrap}.unit-add .btn{border-radius:999px;font-weight:900}.unit-assign{display:flex;gap:8px;align-items:center;justify-content:flex-end}.unit-assign select{border-radius:999px;min-width:190px;font-weight:750}.library-empty{padding:38px;border-radius:28px;text-align:center;background:#fff;border:1px dashed rgba(90,79,243,.28);color:#64748b}.library-empty strong{display:block;color:#172033;font-size:1.2rem;margin-bottom:6px}.library-hidden{display:none!important}
  @media(max-width:1180px){.unit-grid{grid-template-columns:1fr}.library-filters{grid-template-columns:1fr 1fr}.library-hero{grid-template-columns:1fr}.library-hero-actions{justify-content:flex-start}.unit-footer{grid-template-columns:1fr}.unit-assign{justify-content:flex-start}}
  @media(max-width:720px){.library-stats{grid-template-columns:1fr 1fr}.library-filters{grid-template-columns:1fr}.unit-head{grid-template-columns:1fr}.unit-format-strip{justify-content:flex-start}.unit-item{grid-template-columns:auto 1fr}.unit-item-actions{grid-column:1/-1;justify-content:flex-start}}
</style>

<?php if ($notice): ?><div class="alert alert-success rounded-4"><?= teacher_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger rounded-4"><?= teacher_h($error) ?></div><?php endif; ?>

<div class="library-hero">
  <div>
    <h2>Deine Units entstehen automatisch aus deinen Materialien.</h2>
    <p>Erstelle Quizze, Arbeitsblätter oder Listenings direkt aus der Bibliothek. Elevaro clustert deine eigenen Inhalte nach Fach, Klasse und Lehrplanthema zu Units. Du kannst eine ganze Unit oder später einzelne Inhalte Klassen zuordnen.</p>
  </div>
  <div class="library-hero-actions">
    <a class="btn btn-primary" href="ai_wizard.php">✨ Neues Material erstellen</a>
    <a class="btn btn-outline-primary" href="classes.php">🏫 Klassen verwalten</a>
  </div>
</div>

<div class="library-stats">
  <div class="library-stat"><span>Units</span><strong>🧩 <?= count($units) ?></strong></div>
  <div class="library-stat"><span>Quizze</span><strong>🎮 <?= (int)$formatCounts['quiz'] ?></strong></div>
  <div class="library-stat"><span>Arbeitsblätter</span><strong>📄 <?= (int)$formatCounts['worksheet'] ?></strong></div>
  <div class="library-stat"><span>Listenings</span><strong>🎧 <?= (int)$formatCounts['listening'] ?></strong></div>
</div>

<div class="library-filters" data-library-filters>
  <input class="form-control" type="search" placeholder="Unit, Material oder Fach suchen …" data-filter-search>
  <select class="form-select" data-filter-format>
    <option value="">Alle Formate</option>
    <option value="quiz">Quiz</option>
    <option value="worksheet">Arbeitsblatt</option>
    <option value="listening">Listening</option>
    <option value="reading">Leseverständnis</option>
  </select>
  <select class="form-select" data-filter-subject>
    <option value="">Alle Fächer</option>
    <?php foreach ($subjects as $subject): ?><option value="<?= teacher_h(teacher_library_norm($subject)) ?>"><?= teacher_h($subject) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select" data-filter-grade>
    <option value="">Alle Klassen</option>
    <?php foreach ($grades as $grade): ?><option value="<?= teacher_h(teacher_library_norm((string)$grade)) ?>">Klasse <?= teacher_h((string)$grade) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select" data-filter-topic>
    <option value="">Alle Lehrplanthemen</option>
    <?php foreach ($topics as $topic): ?><option value="<?= teacher_h(teacher_library_norm($topic)) ?>"><?= teacher_h($topic) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-light library-reset" type="button" data-filter-reset>Zurücksetzen</button>
</div>

<?php if (!$units): ?>
  <div class="library-empty">
    <strong>Noch keine eigenen Materialien gespeichert.</strong>
    Starte mit „Neues Material erstellen“. Danach erscheinen deine Inhalte hier automatisch als Units.
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
      ?>
      <article class="unit-card"
        data-library-unit
        data-search="<?= teacher_h($searchText) ?>"
        data-format="<?= teacher_h($formatFilter) ?>"
        data-subject="<?= teacher_h(teacher_library_norm((string)$unit['subject_label'])) ?>"
        data-grade="<?= teacher_h(teacher_library_norm((string)$unit['grade'])) ?>"
        data-topic="<?= teacher_h(teacher_library_norm($topicText)) ?>">
        <div class="unit-head">
          <div>
            <div class="unit-kicker">
              <span><?= teacher_h((string)$unit['subject_label']) ?></span>
              <?php if ((string)$unit['grade'] !== ''): ?><span>Klasse <?= teacher_h((string)$unit['grade']) ?></span><?php endif; ?>
              <?php if ($topicText !== ''): ?><span><?= teacher_h($topicText) ?></span><?php endif; ?>
            </div>
            <div class="unit-title"><span class="unit-emoji">🧩</span><h3><?= teacher_h((string)$unit['title']) ?></h3></div>
            <div class="unit-sub">
              <?= count($unit['items']) ?> Inhalt<?= count($unit['items']) === 1 ? '' : 'e' ?> in dieser Unit<?= $updated ? ' · aktualisiert am ' . teacher_h($updated) : '' ?>
            </div>
          </div>
          <div class="unit-format-strip">
            <?php foreach ($formatTypes as $format): ?><span class="format-pill"><?= teacher_library_type_icon($format) ?> <?= teacher_h(teacher_library_type_label($format)) ?></span><?php endforeach; ?>
          </div>
        </div>

        <div class="unit-body">
          <div class="unit-items">
            <?php foreach ($unit['items'] as $item): ?>
              <div class="unit-item">
                <span class="unit-item-icon"><?= teacher_library_type_icon((string)$item['type']) ?></span>
                <div>
                  <strong><?= teacher_h((string)$item['title']) ?></strong>
                  <small><?= teacher_h(teacher_library_type_label((string)$item['type'])) ?> · <?= (int)$item['question_count'] ?> Fragen</small>
                </div>
                <div class="unit-item-actions">
                  <?php if (!empty($item['url'])): ?><a class="btn btn-sm btn-primary" href="<?= teacher_h((string)$item['url']) ?>" target="_blank" rel="noopener">Öffnen</a><?php endif; ?>
                  <?php if (!empty($item['pdf_url'])): ?><a class="btn btn-sm btn-primary" href="<?= teacher_h((string)$item['pdf_url']) ?>" target="_blank" rel="noopener">PDF</a><?php endif; ?>
                  <?php if (!empty($item['edit_url'])): ?><a class="btn btn-sm btn-light" href="<?= teacher_h((string)$item['edit_url']) ?>">Bearbeiten</a><?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="unit-footer">
          <div>
            <div class="fw-black mb-2">+ Inhalt hinzufügen</div>
            <div class="unit-add">
              <a class="btn btn-sm btn-primary" href="ai_wizard.php?<?= teacher_h($commonParams) ?>&content_type=quiz">🎮 Quiz</a>
              <a class="btn btn-sm btn-outline-primary" href="worksheet_editor.php?<?= teacher_h($commonParams) ?>">📄 Arbeitsblatt</a>
              <?php if ($canListening): ?>
                <a class="btn btn-sm btn-outline-primary" href="listening_wizard.php?<?= teacher_h($commonParams) ?>">🎧 Listening</a>
              <?php else: ?>
                <span class="btn btn-sm btn-light disabled" title="Listenings sind nur für Fremdsprachen aktiv">🎧 Listening</span>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($classes): ?>
            <form method="post" class="unit-assign">
              <input type="hidden" name="action" value="assign_unit_class">
              <input type="hidden" name="unit_id" value="<?= $unitId ?>">
              <select class="form-select form-select-sm" name="class_id" required>
                <option value="">Unit Klasse zuordnen…</option>
                <?php foreach (teacher_library_classes_matching_unit($unit, $classes) as $class): ?>
                  <option value="<?= (int)$class['id'] ?>"><?= teacher_h(teacher_class_label($class)) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-light" type="submit">Zuordnen</button>
            </form>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <div class="library-empty library-hidden mt-3" data-library-no-results>
    <strong>Keine Treffer.</strong>
    Passe Suche oder Filter an.
  </div>
<?php endif; ?>

<script>
(() => {
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
    const q = norm(search.value);
    const f = norm(format.value);
    const s = norm(subject.value);
    const g = norm(grade.value);
    const t = norm(topic.value);
    let visible = 0;
    units.forEach((unit) => {
      const matches = (!q || unit.dataset.search.includes(q))
        && (!f || unit.dataset.format.includes(f))
        && (!s || unit.dataset.subject === s)
        && (!g || unit.dataset.grade === g)
        && (!t || unit.dataset.topic === t);
      unit.classList.toggle('library-hidden', !matches);
      if (matches) visible++;
    });
    noResults?.classList.toggle('library-hidden', visible > 0);
  };
  [search, format, subject, grade, topic].forEach((el) => el?.addEventListener('input', apply));
  root.querySelector('[data-filter-reset]')?.addEventListener('click', () => {
    search.value = ''; format.value = ''; subject.value = ''; grade.value = ''; topic.value = '';
    apply();
  });
  apply();
})();
</script>
<?php teacher_footer(); ?>
