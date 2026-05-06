<?php
require_once __DIR__ . '/_layout.php';

$classes = teacher_classes();
$class = teacher_selected_class();
$studentCount = $class ? teacher_class_student_count((int)$class['id']) : 0;
$quizCount = $class ? teacher_class_quiz_count((int)$class['id']) : 0;

teacher_header('Lehrer-Dashboard', 'Klassen, Schüler und Premium-Quizsets verwalten.');
?>

<?php if (!$class): ?>
  <div class="card card-soft"><div class="card-body p-4">
    <h2 class="h4 fw-bold">Noch keine Klasse angelegt</h2>
    <p class="text-muted">Lehrer-Accounts sind kostenpflichtig und können bis zu 3 Klassen anlegen. Pro Klasse lassen sich bis zu 10 Quizzes freischalten.</p>
    <a class="btn btn-primary" href="classes.php">Erste Klasse anlegen</a>
  </div></div>
<?php else: ?>
  <div class="row g-4">
    <div class="col-md-4"><div class="card card-soft stat-card"><span>Schüler</span><strong><?= $studentCount ?></strong></div></div>
    <div class="col-md-4"><div class="card card-soft stat-card"><span>Premium-Quizzes</span><strong><?= $quizCount ?>/10</strong></div></div>
    <div class="col-md-4"><div class="card card-soft stat-card"><span>Klassen</span><strong><?= count($classes) ?>/3</strong></div></div>
  </div>

  <div class="row g-4 mt-1">
    <div class="col-lg-7">
      <div class="card card-soft"><div class="card-body p-4">
        <h2 class="h5 fw-bold">Einladung für <?= teacher_h(teacher_class_label($class)) ?></h2>
        <p class="text-muted">Schüler treten per QR-Code oder Link bei und geben nur ihren Namen ein. Ein Konto ist optional – ideal für den schnellen Einsatz im Unterricht.</p>
        <div class="invite-code mb-3"><?= teacher_h($class['invite_code']) ?></div>
        <div class="input-group">
          <input class="form-control" value="<?= teacher_h(teacher_invite_url($class)) ?>" readonly>
          <a class="btn btn-primary" href="<?= teacher_h(teacher_invite_url($class)) ?>" target="_blank">Öffnen</a>
        </div>
      </div></div>
    </div>
    <div class="col-lg-5">
      <div class="card card-soft"><div class="card-body p-4">
        <h2 class="h5 fw-bold">Nächste Schritte</h2>
        <div class="quick-actions mt-3 d-grid gap-2">
          <a class="btn btn-primary" href="quizzes.php?class_id=<?= (int)$class['id'] ?>">📝 Quizzes hinzufügen</a>
          <a class="btn btn-light" href="students.php?class_id=<?= (int)$class['id'] ?>">👧 Schüler ansehen</a>
          <a class="btn btn-light" href="settings.php?class_id=<?= (int)$class['id'] ?>">⚙️ Einladung & QR-Code</a>
          <a class="btn btn-light" href="<?= teacher_h(teacher_invite_url($class)) ?>" target="_blank">👀 Klassenraum ansehen</a>
        </div>
      </div></div>
    </div>
  </div>
<?php endif; ?>

<?php teacher_footer(); ?>
