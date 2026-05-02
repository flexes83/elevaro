<?php
require_once __DIR__ . '/_layout.php';
$pdo = admin_db();
admin_header('Dashboard', 'Zentrale Übersicht für Curriculum, KI-Auswahlen, Quizze und Review.');
$counts = [
  'batches'=>admin_count($pdo,'ai_topic_batches'),
  'topics'=>admin_count($pdo,'curriculum_topics'),
  'quizzes'=>admin_count($pdo,'quizzes'),
  'questions'=>admin_count($pdo,'questions')
];
$recent = [];
try {
  $recent = $pdo->query("SELECT q.id,q.title,q.status,q.theme_color_1,q.theme_color_2,q.theme_emoji, sub.name subject_name, q.grade FROM quizzes q LEFT JOIN subjects sub ON sub.id=q.subject_id ORDER BY q.created_at DESC, q.id DESC LIMIT 6")->fetchAll();
} catch(Throwable $e) {}
?>
<div class="row g-3 mb-4">
  <div class="col-md-3"><div class="card-soft stat-card"><span>KI-Auswahlen</span><strong><?= $counts['batches'] ?></strong></div></div>
  <div class="col-md-3"><div class="card-soft stat-card"><span>Curriculum-Themen</span><strong><?= $counts['topics'] ?></strong></div></div>
  <div class="col-md-3"><div class="card-soft stat-card"><span>Quizze</span><strong><?= $counts['quizzes'] ?></strong></div></div>
  <div class="col-md-3"><div class="card-soft stat-card"><span>Fragen</span><strong><?= $counts['questions'] ?></strong></div></div>
</div>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card-soft p-4 h-100">
      <h2 class="h4 fw-bold">Empfohlener Workflow</h2>
      <ol class="mb-0 text-muted">
        <li>Im <a href="ai_curriculum_wizard.php">KI Wizard</a> Kontext wählen und Themen generieren.</li>
        <li>Gespeicherte KI-Auswahl öffnen und mehrere Quizze daraus erstellen.</li>
        <li>Fragen im Review prüfen und veröffentlichen.</li>
        <li>Visuals setzen: Farben, Emoji, Bildprompt oder Freepik/Upload.</li>
      </ol>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card-soft p-4 h-100">
      <h2 class="h4 fw-bold">Schnellstart</h2>
      <div class="d-grid gap-2">
        <a class="btn btn-primary" href="ai_curriculum_wizard.php">✨ Neue KI-Auswahl erstellen</a>
        <a class="btn btn-light" href="ai_batches.php">📦 Gespeicherte Auswahlen öffnen</a>
        <a class="btn btn-light" href="quizzes.php">📝 Quizze verwalten</a>
      </div>
    </div>
  </div>
</div>
<div class="card-soft admin-table-card mt-4">
  <div class="p-4 pb-2"><h2 class="h4 fw-bold">Neueste Quizze</h2></div>
  <table class="table table-hover">
    <tbody>
    <?php foreach($recent as $q): ?>
      <tr>
        <td style="width:90px"><div class="quiz-visual-mini" style="--c1:<?=admin_h($q['theme_color_1']?:'#5a4ff3')?>;--c2:<?=admin_h($q['theme_color_2']?:'#8b7cff')?>"><?=admin_h($q['theme_emoji']?:'🎯')?></div></td>
        <td><strong><?=admin_h($q['title'])?></strong><small class="text-muted d-block"><?=admin_h($q['subject_name'])?> · Klasse <?=admin_h($q['grade'])?> · <?=admin_h($q['status'])?></small></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="quiz_questions.php?quiz_id=<?=(int)$q['id']?>">Review</a></td>
      </tr>
    <?php endforeach; if(!$recent): ?><tr><td class="p-4 text-muted">Noch keine Quizze vorhanden.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php admin_footer(); ?>