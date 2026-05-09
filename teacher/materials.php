<?php
require_once __DIR__ . '/_layout.php';

function teacher_library_label_subject(?string $code, ?string $name): string
{
    $name = trim((string)$name);
    if ($name !== '') return $name;
    $code = trim((string)$code);
    return $code !== '' ? strtoupper($code) : 'Ohne Fach';
}

function teacher_library_material_type(array $row): string
{
    return ((int)($row['listening_mode'] ?? 0) === 1) ? 'listening' : 'quiz';
}

function teacher_library_type_label(string $type): string
{
    return match ($type) {
        'worksheet' => 'Arbeitsblatt',
        'listening' => 'Listening',
        default => 'Quiz',
    };
}

function teacher_library_type_icon(string $type): string
{
    return match ($type) {
        'worksheet' => '📄',
        'listening' => '🎧',
        default => '🧠',
    };
}

function teacher_library_type_hint(string $type): string
{
    return match ($type) {
        'worksheet' => 'PDF für den Unterricht',
        'listening' => 'Hörverstehen mit Audio/Text',
        default => 'Interaktives Klassenraum-Quiz',
    };
}

function teacher_library_filter_value(string $value): string
{
    return trim(mb_strtolower($value));
}

$pdo = teacher_db();
$teacherId = teacher_current_user_id();
$items = [];

if (teacher_column_exists('quizzes', 'created_by_user_id')) {
    $stmt = $pdo->prepare("\n        SELECT\n            q.id, q.quiz_key, q.title, q.description, q.grade, q.created_at, q.updated_at,\n            q.image_path, q.theme_emoji, q.listening_mode, q.listening_status, q.status,\n            sub.code AS subject_code, sub.name AS subject_name,\n            COUNT(qq.id) AS question_count\n        FROM quizzes q\n        LEFT JOIN subjects sub ON sub.id = q.subject_id\n        LEFT JOIN questions qq ON qq.quiz_id = q.id\n        WHERE q.created_by_user_id = :teacher_id\n          AND (q.source_type = 'teacher' OR q.ai_generated = 1 OR q.created_by_user_id IS NOT NULL)\n        GROUP BY q.id, sub.id\n        ORDER BY COALESCE(q.updated_at, q.created_at) DESC, q.id DESC\n    ");
    $stmt->execute(['teacher_id' => $teacherId]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $type = teacher_library_material_type($row);
        $subjectLabel = teacher_library_label_subject($row['subject_code'] ?? null, $row['subject_name'] ?? null);
        $items[] = [
            'type' => $type,
            'id' => (int)$row['id'],
            'title' => (string)($row['title'] ?? 'Unbenanntes Quiz'),
            'description' => (string)($row['description'] ?? ''),
            'subject_code' => (string)($row['subject_code'] ?? ''),
            'subject_label' => $subjectLabel,
            'grade' => (string)($row['grade'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
            'question_count' => (int)($row['question_count'] ?? 0),
            'image_path' => (string)($row['image_path'] ?? ''),
            'emoji' => (string)(($row['theme_emoji'] ?? '') ?: ($type === 'listening' ? '🎧' : '🧠')),
            'status' => (string)($row['status'] ?? ''),
            'url' => !empty($row['quiz_key']) ? '/quiz.php?key=' . urlencode((string)$row['quiz_key']) : '',
            'edit_url' => '/admin/quiz_questions.php?quiz_id=' . (int)$row['id'],
            'pdf_url' => '',
        ];
    }
}

if (teacher_table_exists('teacher_custom_quizzes')) {
    $stmt = $pdo->prepare("\n        SELECT\n            cq.id, cq.title, cq.description, cq.created_at, cq.updated_at, cq.class_id, cq.source_quiz_id,\n            tc.name AS class_name, tc.grade AS class_grade, tc.subject_code AS class_subject_code,\n            sq.grade AS source_grade, sq.theme_emoji AS source_emoji, sq.quiz_key AS source_quiz_key,\n            sub.code AS subject_code, sub.name AS subject_name,\n            COUNT(cqq.id) AS question_count\n        FROM teacher_custom_quizzes cq\n        LEFT JOIN teacher_custom_quiz_questions cqq ON cqq.custom_quiz_id = cq.id\n        LEFT JOIN teacher_classes tc ON tc.id = cq.class_id\n        LEFT JOIN quizzes sq ON sq.id = cq.source_quiz_id\n        LEFT JOIN subjects sub ON sub.id = sq.subject_id OR sub.code = tc.subject_code\n        WHERE cq.teacher_id = :teacher_id\n        GROUP BY cq.id, tc.id, sq.id, sub.id\n        ORDER BY COALESCE(cq.updated_at, cq.created_at) DESC, cq.id DESC\n    ");
    $stmt->execute(['teacher_id' => $teacherId]);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $subjectLabel = teacher_library_label_subject($row['subject_code'] ?? $row['class_subject_code'] ?? null, $row['subject_name'] ?? null);
        $grade = (string)(($row['class_grade'] ?? '') ?: ($row['source_grade'] ?? ''));
        $items[] = [
            'type' => 'worksheet',
            'id' => (int)$row['id'],
            'title' => (string)($row['title'] ?? 'Arbeitsblatt'),
            'description' => (string)(($row['description'] ?? '') ?: 'Ausgewählte Fragen als PDF-Arbeitsblatt.'),
            'subject_code' => (string)(($row['subject_code'] ?? '') ?: ($row['class_subject_code'] ?? '')),
            'subject_label' => $subjectLabel,
            'grade' => $grade,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
            'question_count' => (int)($row['question_count'] ?? 0),
            'image_path' => '',
            'emoji' => '📄',
            'status' => 'saved',
            'url' => '',
            'edit_url' => '',
            'pdf_url' => 'material_pdf.php?custom_quiz_id=' . (int)$row['id'],
            'class_name' => (string)($row['class_name'] ?? ''),
        ];
    }
}

usort($items, static function (array $a, array $b): int {
    return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
});

$subjects = [];
$grades = [];
$counts = ['quiz' => 0, 'worksheet' => 0, 'listening' => 0];
foreach ($items as $item) {
    $counts[$item['type']] = ($counts[$item['type']] ?? 0) + 1;
    if (!empty($item['subject_label'])) $subjects[$item['subject_label']] = true;
    if ((string)($item['grade'] ?? '') !== '') $grades[(string)$item['grade']] = true;
}
$subjects = array_keys($subjects);
sort($subjects, SORT_NATURAL | SORT_FLAG_CASE);
$grades = array_keys($grades);
sort($grades, SORT_NATURAL | SORT_FLAG_CASE);

teacher_header('Meine Quizzes + Materialien', 'Deine dauerhaft gespeicherte Unterrichtsbibliothek – unabhängig von einzelnen Klassen.');
?>
<style>
  .library-hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:22px;align-items:end;margin-bottom:22px;padding:24px;border-radius:30px;background:linear-gradient(135deg,#f7f7ff 0%,#eef2ff 48%,#fff 100%);border:1px solid rgba(90,79,243,.14);box-shadow:0 22px 60px rgba(23,32,51,.07)}
  .library-hero h2{font-weight:950;color:#172033;margin:0 0 6px;font-size:1.45rem}.library-hero p{margin:0;color:#64748b;max-width:760px;line-height:1.45}.library-hero-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
  .library-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:18px}.library-stat{padding:16px 17px;border-radius:24px;background:#fff;border:1px solid rgba(23,32,51,.08);box-shadow:0 14px 36px rgba(23,32,51,.055)}.library-stat span{display:block;font-weight:900;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:#7c8494}.library-stat strong{display:flex;align-items:center;gap:8px;margin-top:6px;font-size:1.45rem;color:#172033;font-weight:950}
  .library-filters{position:sticky;top:0;z-index:4;display:grid;grid-template-columns:minmax(220px,1.3fr) repeat(3,minmax(145px,.55fr)) auto;gap:10px;align-items:center;padding:12px;margin-bottom:18px;border-radius:24px;background:rgba(255,255,255,.88);border:1px solid rgba(23,32,51,.08);box-shadow:0 14px 42px rgba(23,32,51,.075);backdrop-filter:blur(16px)}.library-filters .form-control,.library-filters .form-select{border-radius:999px;border-color:rgba(23,32,51,.10);font-weight:750}.library-reset{border-radius:999px;white-space:nowrap}
  .library-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.library-card{position:relative;display:flex;flex-direction:column;min-height:265px;overflow:hidden;border-radius:28px;background:#fff;border:1px solid rgba(23,32,51,.08);box-shadow:0 18px 48px rgba(23,32,51,.065);transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}.library-card:hover{transform:translateY(-3px);box-shadow:0 24px 65px rgba(23,32,51,.1);border-color:rgba(90,79,243,.22)}
  .library-thumb{height:96px;background:linear-gradient(135deg,rgba(90,79,243,.13),rgba(139,124,255,.06));display:flex;align-items:center;justify-content:center;font-size:2.2rem;color:#5a4ff3}.library-thumb.has-image{background-size:cover;background-position:center}.library-thumb.has-image span{display:none}.library-body{padding:17px 18px 16px;display:flex;flex-direction:column;gap:12px;flex:1}.library-type{display:flex;align-items:center;justify-content:space-between;gap:8px}.library-type-badge{display:inline-flex;align-items:center;gap:7px;border-radius:999px;padding:7px 10px;font-size:.78rem;font-weight:950;background:#f1f5ff;color:#4f46e5}.library-status{font-size:.72rem;font-weight:900;border-radius:999px;padding:5px 8px;background:#f8fafc;color:#64748b;border:1px solid rgba(23,32,51,.07);text-transform:uppercase;letter-spacing:.03em}.library-card h3{font-size:1.04rem;line-height:1.2;margin:0;font-weight:950;color:#172033}.library-card p{margin:0;color:#667085;line-height:1.38;font-size:.9rem;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.library-meta{display:flex;gap:6px;flex-wrap:wrap;margin-top:auto}.library-meta span{font-size:.76rem;font-weight:850;color:#475569;background:#f8fafc;border:1px solid rgba(23,32,51,.06);border-radius:999px;padding:5px 8px}.library-actions{display:flex;gap:8px;flex-wrap:wrap;padding-top:2px}.library-actions .btn{border-radius:999px;font-weight:850}.library-empty{padding:38px;border-radius:28px;text-align:center;background:#fff;border:1px dashed rgba(90,79,243,.28);color:#64748b}.library-empty strong{display:block;color:#172033;font-size:1.2rem;margin-bottom:6px}.library-hidden{display:none!important}
  @media(max-width:1150px){.library-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.library-filters{grid-template-columns:1fr 1fr}.library-hero{grid-template-columns:1fr}.library-hero-actions{justify-content:flex-start}}
  @media(max-width:720px){.library-grid,.library-stats{grid-template-columns:1fr}.library-filters{grid-template-columns:1fr}.library-hero{padding:20px;border-radius:24px}}
</style>

<div class="library-hero">
  <div>
    <h2>Alles, was du erstellt hast, bleibt hier gespeichert.</h2>
    <p>Quizzes, Arbeitsblatt-PDFs und Listenings werden deinem Lehreraccount zugeordnet. Die Bibliothek bleibt erhalten, auch wenn du später eine Klasse entfernst.</p>
  </div>
  <div class="library-hero-actions">
    <a class="btn btn-primary" href="ai_wizard.php">✨ Neues Material erstellen</a>
    <a class="btn btn-outline-primary" href="classes.php">🏫 Klassen verwalten</a>
  </div>
</div>

<div class="library-stats">
  <div class="library-stat"><span>Quizzes</span><strong>🧠 <?= (int)$counts['quiz'] ?></strong></div>
  <div class="library-stat"><span>Arbeitsblätter</span><strong>📄 <?= (int)$counts['worksheet'] ?></strong></div>
  <div class="library-stat"><span>Listenings</span><strong>🎧 <?= (int)$counts['listening'] ?></strong></div>
</div>

<div class="library-filters" data-library-filters>
  <input class="form-control" type="search" placeholder="Titel, Beschreibung oder Fach suchen …" data-filter-search>
  <select class="form-select" data-filter-type>
    <option value="">Alle Kategorien</option>
    <option value="quiz">Quizzes</option>
    <option value="worksheet">Arbeitsblätter</option>
    <option value="listening">Listenings</option>
  </select>
  <select class="form-select" data-filter-subject>
    <option value="">Alle Fächer</option>
    <?php foreach ($subjects as $subject): ?><option value="<?= teacher_h(teacher_library_filter_value($subject)) ?>"><?= teacher_h($subject) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select" data-filter-grade>
    <option value="">Alle Klassen</option>
    <?php foreach ($grades as $grade): ?><option value="<?= teacher_h((string)$grade) ?>">Klasse <?= teacher_h((string)$grade) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-light library-reset" type="button" data-filter-reset>Zurücksetzen</button>
</div>

<?php if (!$items): ?>
  <div class="library-empty">
    <strong>Noch keine eigenen Materialien gespeichert.</strong>
    Starte im KI-Wizard ein Quiz, Listening oder Arbeitsblatt. Danach erscheint es dauerhaft in deiner Bibliothek.
  </div>
<?php else: ?>
  <div class="library-grid" data-library-grid>
    <?php foreach ($items as $item): ?>
      <?php
        $type = (string)$item['type'];
        $searchText = teacher_library_filter_value(($item['title'] ?? '') . ' ' . ($item['description'] ?? '') . ' ' . ($item['subject_label'] ?? '') . ' ' . teacher_library_type_label($type));
        $gradeValue = (string)($item['grade'] ?? '');
        $imagePath = trim((string)($item['image_path'] ?? ''));
        $updated = !empty($item['updated_at']) ? date('d.m.Y', strtotime((string)$item['updated_at'])) : '';
      ?>
      <article class="library-card"
        data-library-item
        data-type="<?= teacher_h($type) ?>"
        data-subject="<?= teacher_h(teacher_library_filter_value((string)$item['subject_label'])) ?>"
        data-grade="<?= teacher_h($gradeValue) ?>"
        data-search="<?= teacher_h($searchText) ?>">
        <div class="library-thumb <?= $imagePath !== '' ? 'has-image' : '' ?>" <?= $imagePath !== '' ? 'style="background-image:url(' . teacher_h($imagePath) . ')"' : '' ?>><span><?= teacher_h((string)$item['emoji']) ?></span></div>
        <div class="library-body">
          <div class="library-type">
            <span class="library-type-badge"><?= teacher_library_type_icon($type) ?> <?= teacher_h(teacher_library_type_label($type)) ?></span>
            <?php if (!empty($item['status'])): ?><span class="library-status"><?= teacher_h((string)$item['status']) ?></span><?php endif; ?>
          </div>
          <div>
            <h3><?= teacher_h((string)$item['title']) ?></h3>
            <p><?= teacher_h((string)($item['description'] ?: teacher_library_type_hint($type))) ?></p>
          </div>
          <div class="library-meta">
            <span><?= teacher_h((string)$item['subject_label']) ?></span>
            <?php if ($gradeValue !== ''): ?><span>Klasse <?= teacher_h($gradeValue) ?></span><?php endif; ?>
            <span><?= (int)$item['question_count'] ?> Fragen</span>
            <?php if ($updated): ?><span><?= teacher_h($updated) ?></span><?php endif; ?>
          </div>
          <div class="library-actions">
            <?php if (!empty($item['url'])): ?><a class="btn btn-sm btn-primary" href="<?= teacher_h((string)$item['url']) ?>" target="_blank" rel="noopener">Öffnen</a><?php endif; ?>
            <?php if (!empty($item['pdf_url'])): ?><a class="btn btn-sm btn-primary" href="<?= teacher_h((string)$item['pdf_url']) ?>" target="_blank" rel="noopener">PDF laden</a><?php endif; ?>
            <?php if (!empty($item['edit_url'])): ?><a class="btn btn-sm btn-light" href="<?= teacher_h((string)$item['edit_url']) ?>">Bearbeiten</a><?php endif; ?>
          </div>
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
  const items = Array.from(document.querySelectorAll('[data-library-item]'));
  const noResults = document.querySelector('[data-library-no-results]');
  if (!root || !items.length) return;
  const search = root.querySelector('[data-filter-search]');
  const type = root.querySelector('[data-filter-type]');
  const subject = root.querySelector('[data-filter-subject]');
  const grade = root.querySelector('[data-filter-grade]');
  const norm = (value) => (value || '').toString().trim().toLowerCase();
  const apply = () => {
    const q = norm(search.value);
    const t = norm(type.value);
    const s = norm(subject.value);
    const g = norm(grade.value);
    let visible = 0;
    items.forEach((item) => {
      const matches = (!q || item.dataset.search.includes(q))
        && (!t || item.dataset.type === t)
        && (!s || item.dataset.subject === s)
        && (!g || item.dataset.grade === g);
      item.classList.toggle('library-hidden', !matches);
      if (matches) visible++;
    });
    noResults?.classList.toggle('library-hidden', visible > 0);
  };
  [search, type, subject, grade].forEach((el) => el?.addEventListener('input', apply));
  root.querySelector('[data-filter-reset]')?.addEventListener('click', () => {
    search.value = ''; type.value = ''; subject.value = ''; grade.value = '';
    apply();
  });
  apply();
})();
</script>
<?php teacher_footer(); ?>
