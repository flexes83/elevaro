<?php
require_once __DIR__ . '/_layout.php';

function teacher_library_label_subject(?string $code, ?string $name): string
{
    $name = trim((string)$name);
    if ($name !== '') return $name;
    $code = trim((string)$code);
    return $code !== '' ? strtoupper($code) : 'Ohne Fach';
}

function teacher_library_norm(string $value): string
{
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
    return $value;
}

function teacher_library_date_label(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') return 'ohne Datum';
    $ts = strtotime($date);
    return $ts ? date('d.m.Y', $ts) : $date;
}

function teacher_library_asset_meta(string $type): array
{
    return match ($type) {
        'listening_quiz' => ['icon' => '🎧', 'label' => 'Listening-Quiz', 'hint' => 'Online spielbar mit Audio'],
        'worksheet' => ['icon' => '📄', 'label' => 'Arbeitsblatt', 'hint' => 'PDF / Ausdruck'],
        'reading_comprehension' => ['icon' => '📖', 'label' => 'Leseverständnis', 'hint' => 'Transcript + Fragen'],
        'listening_comprehension' => ['icon' => '🔊', 'label' => 'Hörverständnis', 'hint' => 'Audio + Aufgaben'],
        default => ['icon' => '🎮', 'label' => 'Quiz', 'hint' => 'Online spielbar'],
    };
}

function teacher_library_asset_rank(string $type): int
{
    return match ($type) {
        'quiz' => 10,
        'listening_quiz' => 20,
        'worksheet' => 30,
        'reading_comprehension' => 40,
        'listening_comprehension' => 50,
        default => 90,
    };
}

function teacher_library_topic_label(array $item): string
{
    $subtopic = trim((string)($item['curriculum_subtopic_title'] ?? ''));
    if ($subtopic !== '') return $subtopic;
    $topic = trim((string)($item['curriculum_topic_title'] ?? ''));
    if ($topic !== '') return $topic;
    return 'Ohne Lehrplanthema';
}

function teacher_library_unit_key(array $item): string
{
    $subtopicId = (int)($item['curriculum_subtopic_id'] ?? 0);
    if ($subtopicId > 0) return 'subtopic:' . $subtopicId;
    $topicId = (int)($item['curriculum_topic_id'] ?? 0);
    if ($topicId > 0) return 'topic:' . $topicId;

    return 'free:' . md5(teacher_library_norm(($item['subject_label'] ?? '') . '|' . ($item['grade'] ?? '') . '|' . ($item['title'] ?? '')));
}

function teacher_library_add_asset(array &$units, array $item, array $asset): void
{
    $key = teacher_library_unit_key($item);
    $topicLabel = teacher_library_topic_label($item);
    $title = trim((string)($item['unit_title'] ?? '')) ?: $topicLabel;
    if ($title === 'Ohne Lehrplanthema') {
        $title = trim((string)($item['title'] ?? '')) ?: 'Unbenannte Unterrichtseinheit';
    }

    if (!isset($units[$key])) {
        $units[$key] = [
            'key' => $key,
            'title' => $title,
            'description' => trim((string)($item['unit_description'] ?? $item['description'] ?? '')),
            'subject_label' => (string)($item['subject_label'] ?? 'Ohne Fach'),
            'subject_code' => (string)($item['subject_code'] ?? ''),
            'grade' => (string)($item['grade'] ?? ''),
            'topic_label' => $topicLabel,
            'topic_id' => (int)($item['curriculum_topic_id'] ?? 0),
            'subtopic_id' => (int)($item['curriculum_subtopic_id'] ?? 0),
            'domain' => (string)($item['curriculum_domain'] ?? ''),
            'emoji' => trim((string)($item['emoji'] ?? '')) ?: '✨',
            'image_path' => trim((string)($item['image_path'] ?? '')),
            'updated_at' => (string)($item['updated_at'] ?? $item['created_at'] ?? ''),
            'assets' => [],
        ];
    }

    if (trim((string)($item['updated_at'] ?? '')) > trim((string)($units[$key]['updated_at'] ?? ''))) {
        $units[$key]['updated_at'] = (string)$item['updated_at'];
    }

    if (empty($units[$key]['image_path']) && !empty($item['image_path'])) {
        $units[$key]['image_path'] = (string)$item['image_path'];
    }

    $asset['sort'] = teacher_library_asset_rank((string)$asset['type']);
    $units[$key]['assets'][] = $asset;
}

$pdo = teacher_db();
$teacherId = teacher_current_user_id();
$units = [];

$quizTopicMap = [];
if (teacher_table_exists('quiz_curriculum_topics')) {
    $topicJoin = "
        SELECT
            qct.quiz_id,
            qct.curriculum_topic_content_id AS topic_id,
            qct.curriculum_topic_subtopic_id AS subtopic_id,
            c.domain_title,
            COALESCE(NULLIF(c.title_short, ''), NULLIF(c.topic_title, ''), NULLIF(c.title_long, '')) AS topic_title,
            COALESCE(NULLIF(c.title_long, ''), NULLIF(c.topic_description, ''), NULLIF(c.learning_goal, '')) AS topic_description,
            COALESCE(NULLIF(st.title_short, ''), NULLIF(st.subtopic_title, ''), NULLIF(st.title_long, '')) AS subtopic_title,
            COALESCE(NULLIF(st.title_long, ''), NULLIF(st.learning_goal, '')) AS subtopic_description
        FROM quiz_curriculum_topics qct
        LEFT JOIN curriculum_topics_content c ON c.id = qct.curriculum_topic_content_id
        LEFT JOIN curriculum_topic_subtopics st ON st.id = qct.curriculum_topic_subtopic_id
    ";
    foreach (($pdo->query($topicJoin)->fetchAll() ?: []) as $row) {
        $quizTopicMap[(int)$row['quiz_id']] = $row;
    }
}

if (teacher_column_exists('quizzes', 'created_by_user_id')) {
    $selectListeningAudio = teacher_column_exists('quizzes', 'listening_audio_path') ? 'q.listening_audio_path' : "NULL AS listening_audio_path";
    $selectListeningText = teacher_column_exists('quizzes', 'listening_text') ? 'q.listening_text' : "NULL AS listening_text";

    $stmt = $pdo->prepare("
        SELECT
            q.id, q.quiz_key, q.title, q.description, q.grade, q.created_at, q.updated_at,
            q.image_path, q.theme_emoji, q.listening_mode, q.listening_status, q.status,
            {$selectListeningText}, {$selectListeningAudio},
            sub.code AS subject_code, sub.name AS subject_name,
            COUNT(qq.id) AS question_count
        FROM quizzes q
        LEFT JOIN subjects sub ON sub.id = q.subject_id
        LEFT JOIN questions qq ON qq.quiz_id = q.id
        WHERE q.created_by_user_id = :teacher_id
          AND (q.source_type = 'teacher' OR q.ai_generated = 1 OR q.created_by_user_id IS NOT NULL)
        GROUP BY q.id, sub.id
        ORDER BY COALESCE(q.updated_at, q.created_at) DESC, q.id DESC
    ");
    $stmt->execute(['teacher_id' => $teacherId]);

    foreach ($stmt->fetchAll() ?: [] as $row) {
        $isListening = (int)($row['listening_mode'] ?? 0) === 1;
        $map = $quizTopicMap[(int)$row['id']] ?? [];
        $topicTitle = trim((string)($map['subtopic_title'] ?? '')) ?: trim((string)($map['topic_title'] ?? ''));
        $unitTitle = $topicTitle ?: (string)($row['title'] ?? 'Unbenanntes Quiz');
        $unitDescription = trim((string)($map['subtopic_description'] ?? '')) ?: trim((string)($map['topic_description'] ?? '')) ?: (string)($row['description'] ?? '');
        $subjectLabel = teacher_library_label_subject($row['subject_code'] ?? null, $row['subject_name'] ?? null);
        $base = [
            'title' => (string)($row['title'] ?? 'Unbenanntes Quiz'),
            'unit_title' => $unitTitle,
            'unit_description' => $unitDescription,
            'description' => (string)($row['description'] ?? ''),
            'subject_code' => (string)($row['subject_code'] ?? ''),
            'subject_label' => $subjectLabel,
            'grade' => (string)($row['grade'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
            'image_path' => (string)($row['image_path'] ?? ''),
            'emoji' => (string)(($row['theme_emoji'] ?? '') ?: ($isListening ? '🎧' : '🎮')),
            'curriculum_topic_id' => (int)($map['topic_id'] ?? 0),
            'curriculum_subtopic_id' => (int)($map['subtopic_id'] ?? 0),
            'curriculum_topic_title' => (string)($map['topic_title'] ?? ''),
            'curriculum_subtopic_title' => (string)($map['subtopic_title'] ?? ''),
            'curriculum_domain' => (string)($map['domain_title'] ?? ''),
        ];

        teacher_library_add_asset($units, $base, [
            'type' => $isListening ? 'listening_quiz' : 'quiz',
            'title' => (string)($row['title'] ?? ''),
            'meta' => ((int)($row['question_count'] ?? 0)) . ' Fragen',
            'primary_url' => !empty($row['quiz_key']) ? '/quiz.php?key=' . urlencode((string)$row['quiz_key']) : '',
            'secondary_url' => '/admin/quiz_questions.php?quiz_id=' . (int)$row['id'],
            'secondary_label' => 'Bearbeiten',
        ]);

        $listeningText = trim((string)($row['listening_text'] ?? ''));
        if ($isListening && $listeningText !== '') {
            teacher_library_add_asset($units, $base, [
                'type' => 'reading_comprehension',
                'title' => 'Transcript als Leseverständnis',
                'meta' => mb_strlen($listeningText, 'UTF-8') . ' Zeichen Transcript',
                'primary_url' => '/admin/quiz_listening.php?quiz_id=' . (int)$row['id'],
                'secondary_url' => '/admin/quiz_questions.php?quiz_id=' . (int)$row['id'],
                'secondary_label' => 'Fragen',
            ]);

            teacher_library_add_asset($units, $base, [
                'type' => 'listening_comprehension',
                'title' => 'Hörtext mit Aufgaben',
                'meta' => !empty($row['listening_audio_path']) ? 'Audio vorhanden' : 'Audio noch erzeugen',
                'primary_url' => '/admin/quiz_listening.php?quiz_id=' . (int)$row['id'],
                'secondary_url' => '',
                'secondary_label' => '',
            ]);
        }
    }
}

if (teacher_table_exists('teacher_custom_quizzes')) {
    $stmt = $pdo->prepare("
        SELECT
            cq.id, cq.title, cq.description, cq.created_at, cq.updated_at, cq.class_id, cq.source_quiz_id,
            tc.name AS class_name, tc.grade AS class_grade, tc.subject_code AS class_subject_code,
            sq.grade AS source_grade, sq.theme_emoji AS source_emoji, sq.quiz_key AS source_quiz_key, sq.image_path AS source_image_path,
            sub.code AS subject_code, sub.name AS subject_name,
            COUNT(cqq.id) AS question_count
        FROM teacher_custom_quizzes cq
        LEFT JOIN teacher_custom_quiz_questions cqq ON cqq.custom_quiz_id = cq.id
        LEFT JOIN teacher_classes tc ON tc.id = cq.class_id
        LEFT JOIN quizzes sq ON sq.id = cq.source_quiz_id
        LEFT JOIN subjects sub ON sub.id = sq.subject_id OR sub.code = tc.subject_code
        WHERE cq.teacher_id = :teacher_id
        GROUP BY cq.id, tc.id, sq.id, sub.id
        ORDER BY COALESCE(cq.updated_at, cq.created_at) DESC, cq.id DESC
    ");
    $stmt->execute(['teacher_id' => $teacherId]);

    foreach ($stmt->fetchAll() ?: [] as $row) {
        $map = $quizTopicMap[(int)($row['source_quiz_id'] ?? 0)] ?? [];
        $topicTitle = trim((string)($map['subtopic_title'] ?? '')) ?: trim((string)($map['topic_title'] ?? ''));
        $subjectLabel = teacher_library_label_subject($row['subject_code'] ?? $row['class_subject_code'] ?? null, $row['subject_name'] ?? null);
        $grade = (string)(($row['class_grade'] ?? '') ?: ($row['source_grade'] ?? ''));
        $base = [
            'title' => (string)($row['title'] ?? 'Arbeitsblatt'),
            'unit_title' => $topicTitle ?: (string)($row['title'] ?? 'Arbeitsblatt'),
            'unit_description' => trim((string)($map['subtopic_description'] ?? '')) ?: trim((string)($map['topic_description'] ?? '')) ?: (string)($row['description'] ?? 'Ausgewählte Fragen als Arbeitsblatt.'),
            'description' => (string)(($row['description'] ?? '') ?: 'Ausgewählte Fragen als PDF-Arbeitsblatt.'),
            'subject_code' => (string)(($row['subject_code'] ?? '') ?: ($row['class_subject_code'] ?? '')),
            'subject_label' => $subjectLabel,
            'grade' => $grade,
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
            'image_path' => (string)($row['source_image_path'] ?? ''),
            'emoji' => '📄',
            'curriculum_topic_id' => (int)($map['topic_id'] ?? 0),
            'curriculum_subtopic_id' => (int)($map['subtopic_id'] ?? 0),
            'curriculum_topic_title' => (string)($map['topic_title'] ?? ''),
            'curriculum_subtopic_title' => (string)($map['subtopic_title'] ?? ''),
            'curriculum_domain' => (string)($map['domain_title'] ?? ''),
        ];

        teacher_library_add_asset($units, $base, [
            'type' => 'worksheet',
            'title' => (string)($row['title'] ?? 'Arbeitsblatt'),
            'meta' => ((int)($row['question_count'] ?? 0)) . ' Aufgaben',
            'primary_url' => 'material_pdf.php?custom_quiz_id=' . (int)$row['id'],
            'secondary_url' => '',
            'secondary_label' => '',
        ]);
    }
}

foreach ($units as &$unit) {
    usort($unit['assets'], static fn(array $a, array $b): int => ($a['sort'] <=> $b['sort']));
    $seen = [];
    $unit['formats'] = [];
    foreach ($unit['assets'] as $asset) {
        $seen[(string)$asset['type']] = true;
    }
    $unit['formats'] = array_keys($seen);
}
unset($unit);

usort($units, static function (array $a, array $b): int {
    return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
});

$subjects = [];
$grades = [];
$topics = [];
$formats = [];
foreach ($units as $unit) {
    if (!empty($unit['subject_label'])) $subjects[$unit['subject_label']] = true;
    if ((string)($unit['grade'] ?? '') !== '') $grades[(string)$unit['grade']] = true;
    if (!empty($unit['topic_label']) && $unit['topic_label'] !== 'Ohne Lehrplanthema') $topics[$unit['topic_label']] = true;
    foreach ($unit['formats'] as $format) $formats[$format] = true;
}
$subjects = array_keys($subjects); sort($subjects, SORT_NATURAL | SORT_FLAG_CASE);
$grades = array_keys($grades); sort($grades, SORT_NATURAL | SORT_FLAG_CASE);
$topics = array_keys($topics); sort($topics, SORT_NATURAL | SORT_FLAG_CASE);

$formatOrder = ['quiz', 'listening_quiz', 'worksheet', 'reading_comprehension', 'listening_comprehension'];
$formatOptions = array_values(array_filter($formatOrder, static fn(string $format): bool => isset($formats[$format])));

$counts = ['units' => count($units), 'quiz' => 0, 'worksheet' => 0, 'listening' => 0];
foreach ($units as $unit) {
    if (in_array('quiz', $unit['formats'], true) || in_array('listening_quiz', $unit['formats'], true)) $counts['quiz']++;
    if (in_array('worksheet', $unit['formats'], true) || in_array('reading_comprehension', $unit['formats'], true)) $counts['worksheet']++;
    if (in_array('listening_quiz', $unit['formats'], true) || in_array('listening_comprehension', $unit['formats'], true)) $counts['listening']++;
}

teacher_header('Meine Bibliothek', 'Alle von dir erstellten Unterrichtseinheiten – unabhängig von einzelnen Klassen.');
?>
<style>
  .library-hero{position:relative;overflow:hidden;display:grid;grid-template-columns:minmax(0,1fr) auto;gap:22px;align-items:end;margin-bottom:22px;padding:28px;border-radius:34px;background:radial-gradient(circle at 20% 0%,rgba(139,124,255,.28),transparent 34%),linear-gradient(135deg,#f8f7ff 0%,#eef2ff 54%,#fff 100%);border:1px solid rgba(90,79,243,.16);box-shadow:0 24px 70px rgba(23,32,51,.075)}
  .library-hero h2{font-weight:950;color:#172033;margin:0 0 7px;font-size:1.65rem;letter-spacing:-.04em}.library-hero p{margin:0;color:#64748b;max-width:800px;line-height:1.48}.library-hero-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.library-pill-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px}.library-pill{display:inline-flex;align-items:center;gap:7px;border-radius:999px;padding:7px 11px;background:#fff;border:1px solid rgba(23,32,51,.08);font-size:.8rem;font-weight:900;color:#475569;box-shadow:0 8px 22px rgba(23,32,51,.045)}
  .library-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}.library-stat{padding:16px 17px;border-radius:24px;background:#fff;border:1px solid rgba(23,32,51,.08);box-shadow:0 14px 36px rgba(23,32,51,.055)}.library-stat span{display:block;font-weight:900;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:#7c8494}.library-stat strong{display:flex;align-items:center;gap:8px;margin-top:6px;font-size:1.4rem;color:#172033;font-weight:950}
  .library-filters{position:sticky;top:0;z-index:4;display:grid;grid-template-columns:minmax(220px,1.3fr) repeat(4,minmax(135px,.55fr)) auto;gap:10px;align-items:center;padding:12px;margin-bottom:18px;border-radius:26px;background:rgba(255,255,255,.9);border:1px solid rgba(23,32,51,.08);box-shadow:0 14px 42px rgba(23,32,51,.075);backdrop-filter:blur(16px)}.library-filters .form-control,.library-filters .form-select{border-radius:999px;border-color:rgba(23,32,51,.10);font-weight:750}.library-reset{border-radius:999px;white-space:nowrap}
  .unit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.unit-card{position:relative;overflow:hidden;border-radius:32px;background:rgba(255,255,255,.96);border:1px solid rgba(23,32,51,.08);box-shadow:0 20px 58px rgba(23,32,51,.07);transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}.unit-card:hover{transform:translateY(-3px);box-shadow:0 28px 72px rgba(23,32,51,.105);border-color:rgba(90,79,243,.22)}.unit-top{display:grid;grid-template-columns:96px minmax(0,1fr);gap:16px;padding:18px 18px 12px}.unit-thumb{width:96px;height:96px;border-radius:25px;background:linear-gradient(135deg,rgba(90,79,243,.16),rgba(139,124,255,.07));display:flex;align-items:center;justify-content:center;font-size:2.35rem;color:#5a4ff3}.unit-thumb.has-image{background-size:cover;background-position:center}.unit-thumb.has-image span{display:none}.unit-kicker{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:9px}.unit-kicker span{font-size:.73rem;font-weight:900;color:#475569;background:#f8fafc;border:1px solid rgba(23,32,51,.07);border-radius:999px;padding:5px 8px}.unit-card h3{font-size:1.17rem;line-height:1.15;margin:0;font-weight:950;color:#172033;letter-spacing:-.025em}.unit-card p{margin:8px 0 0;color:#667085;line-height:1.4;font-size:.92rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.unit-assets{display:grid;gap:8px;padding:12px 18px 18px}.asset-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;border:1px solid rgba(23,32,51,.07);border-radius:20px;background:#fbfcff;padding:11px 12px}.asset-main{min-width:0}.asset-label{display:flex;align-items:center;gap:8px;font-weight:950;color:#172033}.asset-label small{font-weight:800;color:#8a93a3}.asset-hint{display:block;margin-top:2px;color:#6b7280;font-size:.78rem;font-weight:750;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.asset-actions{display:flex;gap:7px;align-items:center}.asset-actions .btn{padding:.42rem .72rem;font-size:.78rem}.format-strip{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}.format-dot{width:30px;height:30px;border-radius:999px;display:inline-flex;align-items:center;justify-content:center;background:#f1f5ff;border:1px solid rgba(90,79,243,.13);font-size:.95rem}.library-empty{padding:38px;border-radius:30px;background:#fff;border:1px dashed rgba(90,79,243,.28);text-align:center;color:#64748b}.library-empty strong{display:block;color:#172033;font-size:1.25rem;margin-bottom:6px}
  @media(max-width:1100px){.library-filters{grid-template-columns:1fr 1fr}.unit-grid{grid-template-columns:1fr}.library-stats{grid-template-columns:repeat(2,1fr)}}
  @media(max-width:720px){.library-hero{grid-template-columns:1fr}.library-hero-actions{justify-content:flex-start}.library-filters{grid-template-columns:1fr}.library-stats{grid-template-columns:1fr}.unit-top{grid-template-columns:1fr}.unit-thumb{width:100%;height:140px}.asset-row{grid-template-columns:1fr}.asset-actions{justify-content:flex-start}}
</style>

<div class="library-hero">
  <div>
    <h2>Deine Unterrichtseinheiten</h2>
    <p>Die Bibliothek gruppiert deine Inhalte nach Thema. Eine Einheit kann mehrere Formate enthalten: spielbares Quiz, Arbeitsblatt, Listening-Quiz sowie Hör- oder Leseverständnis aus demselben Transcript.</p>
    <div class="library-pill-row">
      <span class="library-pill">🎮 Quizze spielen</span>
      <span class="library-pill">📄 Arbeitsblätter nutzen</span>
      <span class="library-pill">🎧 Hör- & Leseverständnis bündeln</span>
    </div>
  </div>
  <div class="library-hero-actions">
    <a class="btn btn-primary" href="ai_wizard.php">✨ Neue Einheit erstellen</a>
    <a class="btn btn-outline-primary" href="classes.php">🏫 Zu meinen Klassen</a>
  </div>
</div>

<div class="library-stats">
  <div class="library-stat"><span>Einheiten</span><strong>✨ <?= (int)$counts['units'] ?></strong></div>
  <div class="library-stat"><span>Spielbar</span><strong>🎮 <?= (int)$counts['quiz'] ?></strong></div>
  <div class="library-stat"><span>Arbeitsmaterial</span><strong>📄 <?= (int)$counts['worksheet'] ?></strong></div>
  <div class="library-stat"><span>Audio/Text</span><strong>🎧 <?= (int)$counts['listening'] ?></strong></div>
</div>

<div class="library-filters" data-library-filters>
  <input class="form-control" type="search" placeholder="Einheit suchen …" data-library-search>
  <select class="form-select" data-library-filter="subject">
    <option value="">Alle Fächer</option>
    <?php foreach ($subjects as $subject): ?><option value="<?= teacher_h(teacher_library_norm($subject)) ?>"><?= teacher_h($subject) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select" data-library-filter="grade">
    <option value="">Alle Klassen</option>
    <?php foreach ($grades as $grade): ?><option value="<?= teacher_h(teacher_library_norm($grade)) ?>"><?= teacher_h($grade) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select" data-library-filter="topic">
    <option value="">Alle Lehrplanthemen</option>
    <?php foreach ($topics as $topic): ?><option value="<?= teacher_h(teacher_library_norm($topic)) ?>"><?= teacher_h($topic) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select" data-library-filter="format">
    <option value="">Alle Formate</option>
    <?php foreach ($formatOptions as $format): $meta = teacher_library_asset_meta($format); ?>
      <option value="<?= teacher_h($format) ?>"><?= teacher_h($meta['icon'] . ' ' . $meta['label']) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-light library-reset" type="button" data-library-reset>Zurücksetzen</button>
</div>

<?php if (!$units): ?>
  <div class="library-empty">
    <strong>Noch keine Inhalte in deiner Bibliothek.</strong>
    Erstelle dein erstes Quiz oder Arbeitsblatt über den KI-Wizard. Die Inhalte bleiben hier erhalten, auch wenn du später eine Klasse löschst.
  </div>
<?php else: ?>
  <div class="unit-grid" data-library-grid>
    <?php foreach ($units as $unit): ?>
      <?php
        $formatData = implode(',', $unit['formats']);
        $search = teacher_library_norm($unit['title'] . ' ' . $unit['description'] . ' ' . $unit['subject_label'] . ' ' . $unit['grade'] . ' ' . $unit['topic_label'] . ' ' . $unit['domain']);
      ?>
      <article class="unit-card"
        data-library-item
        data-search="<?= teacher_h($search) ?>"
        data-subject="<?= teacher_h(teacher_library_norm((string)$unit['subject_label'])) ?>"
        data-grade="<?= teacher_h(teacher_library_norm((string)$unit['grade'])) ?>"
        data-topic="<?= teacher_h(teacher_library_norm((string)$unit['topic_label'])) ?>"
        data-formats="<?= teacher_h($formatData) ?>">
        <div class="unit-top">
          <?php $imagePath = trim((string)$unit['image_path']); ?>
          <div class="unit-thumb<?= $imagePath !== '' ? ' has-image' : '' ?>" <?= $imagePath !== '' ? 'style="background-image:url(' . teacher_h($imagePath) . ')"' : '' ?>><span><?= teacher_h((string)$unit['emoji']) ?></span></div>
          <div>
            <div class="unit-kicker">
              <span><?= teacher_h((string)$unit['subject_label']) ?></span>
              <?php if ((string)$unit['grade'] !== ''): ?><span>Klasse <?= teacher_h((string)$unit['grade']) ?></span><?php endif; ?>
              <?php if ((string)$unit['topic_label'] !== 'Ohne Lehrplanthema'): ?><span><?= teacher_h((string)$unit['topic_label']) ?></span><?php endif; ?>
            </div>
            <h3><?= teacher_h((string)$unit['title']) ?></h3>
            <?php if (!empty($unit['description'])): ?><p><?= teacher_h((string)$unit['description']) ?></p><?php endif; ?>
            <div class="format-strip" aria-label="Verfügbare Formate">
              <?php foreach ($unit['formats'] as $format): $meta = teacher_library_asset_meta($format); ?>
                <span class="format-dot" title="<?= teacher_h($meta['label']) ?>"><?= teacher_h($meta['icon']) ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="unit-assets">
          <?php foreach ($unit['assets'] as $asset): $meta = teacher_library_asset_meta((string)$asset['type']); ?>
            <div class="asset-row">
              <div class="asset-main">
                <div class="asset-label"><?= teacher_h($meta['icon']) ?> <?= teacher_h($meta['label']) ?> <small><?= teacher_h((string)($asset['meta'] ?? '')) ?></small></div>
                <span class="asset-hint"><?= teacher_h((string)($asset['title'] ?? $meta['hint'])) ?></span>
              </div>
              <div class="asset-actions">
                <?php if (!empty($asset['primary_url'])): ?><a class="btn btn-sm btn-primary" href="<?= teacher_h((string)$asset['primary_url']) ?>"><?= $asset['type'] === 'worksheet' ? 'PDF öffnen' : 'Öffnen' ?></a><?php endif; ?>
                <?php if (!empty($asset['secondary_url'])): ?><a class="btn btn-sm btn-outline-primary" href="<?= teacher_h((string)$asset['secondary_url']) ?>"><?= teacher_h((string)($asset['secondary_label'] ?: 'Bearbeiten')) ?></a><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
(() => {
  const root = document.querySelector('[data-library-filters]');
  const items = Array.from(document.querySelectorAll('[data-library-item]'));
  if (!root || !items.length) return;

  const search = root.querySelector('[data-library-search]');
  const selects = Array.from(root.querySelectorAll('[data-library-filter]'));
  const reset = root.querySelector('[data-library-reset]');

  const apply = () => {
    const q = (search?.value || '').trim().toLowerCase();
    const filters = {};
    selects.forEach(select => filters[select.dataset.libraryFilter] = select.value);

    items.forEach(item => {
      const matchesSearch = !q || (item.dataset.search || '').includes(q);
      const matchesSubject = !filters.subject || item.dataset.subject === filters.subject;
      const matchesGrade = !filters.grade || item.dataset.grade === filters.grade;
      const matchesTopic = !filters.topic || item.dataset.topic === filters.topic;
      const matchesFormat = !filters.format || (item.dataset.formats || '').split(',').includes(filters.format);
      item.style.display = matchesSearch && matchesSubject && matchesGrade && matchesTopic && matchesFormat ? '' : 'none';
    });
  };

  search?.addEventListener('input', apply);
  selects.forEach(select => select.addEventListener('change', apply));
  reset?.addEventListener('click', () => {
    if (search) search.value = '';
    selects.forEach(select => select.value = '');
    apply();
  });
})();
</script>

<?php teacher_footer(); ?>
