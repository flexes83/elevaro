<?php
require_once __DIR__ . '/_layout.php';

$class = teacher_selected_class();
if (!$class) { teacher_header('Quizzes', 'Lege zuerst eine Klasse an.'); echo '<div class="card card-soft"><div class="card-body p-4"><a class="btn btn-primary" href="classes.php">Klasse anlegen</a></div></div>'; teacher_footer(); exit; }

$pdo = teacher_db();
$error = null;
$notice = null;
$classId = (int)$class['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'add_quiz') {
            if (teacher_class_quiz_count($classId) >= 10) {
                throw new RuntimeException('Pro Klasse können maximal 10 Quizzes freigeschaltet werden.');
            }
            $quizId = (int)($_POST['quiz_id'] ?? 0);
            if (!$quizId) throw new RuntimeException('Bitte Quiz auswählen.');
            $pdo->prepare("INSERT IGNORE INTO teacher_class_quizzes (class_id, quiz_id, sort_order) VALUES (:class_id, :quiz_id, 100)")->execute(['class_id' => $classId, 'quiz_id' => $quizId]);
            $notice = 'Quiz wurde der Klasse hinzugefügt.';
        }
        if ($action === 'remove_quiz') {
            $pdo->prepare("DELETE FROM teacher_class_quizzes WHERE class_id = :class_id AND quiz_id = :quiz_id")->execute(['class_id' => $classId, 'quiz_id' => (int)($_POST['quiz_id'] ?? 0)]);
            $notice = 'Quiz wurde entfernt.';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$stmt = $pdo->prepare("SELECT q.* FROM teacher_class_quizzes tcq JOIN quizzes q ON q.id = tcq.quiz_id WHERE tcq.class_id = :class_id ORDER BY tcq.sort_order, q.title");
$stmt->execute(['class_id' => $classId]);
$assigned = $stmt->fetchAll();

$params = ['subject' => $class['subject_code'] ?? '', 'subject_empty' => empty($class['subject_code']) ? 1 : 0];
$gradeWhere = '';
if (!empty($class['grade'])) { $gradeWhere = 'AND (q.grade = :grade OR q.grade IS NULL)'; $params['grade'] = (int)$class['grade']; }
$stmt = $pdo->prepare("SELECT q.id, q.quiz_key, q.title, q.description, q.grade, q.theme_emoji, sub.name AS subject_name FROM quizzes q LEFT JOIN subjects sub ON sub.id = q.subject_id WHERE (sub.code = :subject OR :subject_empty = 1) {$gradeWhere} AND q.is_active = 1 AND (q.status IN ('published','draft') OR q.status IS NULL OR q.status = '') ORDER BY q.grade, q.title LIMIT 80");
$stmt->execute($params);
$available = $stmt->fetchAll();
$assignedIds = array_map(static fn($q) => (int)$q['id'], $assigned);

teacher_header('Quizzes', 'Bis zu 10 Quizzes für ' . teacher_class_label($class) . ' freischalten.');
?>
<?php if ($notice): ?><div class="alert alert-success"><?= teacher_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= teacher_h($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card card-soft admin-table-card"><div class="card-body p-4">
      <h2 class="h5 fw-bold">Freigeschaltete Premium-Quizzes <?= count($assigned) ?>/10</h2>
      <table class="table"><thead><tr><th>Quiz</th><th>Klasse</th><th></th></tr></thead><tbody>
      <?php foreach ($assigned as $quiz): ?>
        <tr><td><strong><?= teacher_h(($quiz['theme_emoji'] ?? '🎯') . ' ' . $quiz['title']) ?></strong><br><small class="text-muted"><?= teacher_h($quiz['quiz_key']) ?></small></td><td><?= teacher_h($quiz['grade'] ? $quiz['grade'] . '. Klasse' : '–') ?></td><td class="text-end"><form method="post"><input type="hidden" name="action" value="remove_quiz"><input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>"><button class="btn btn-sm btn-light">Entfernen</button></form></td></tr>
      <?php endforeach; ?>
      <?php if (!$assigned): ?><tr><td colspan="3" class="text-muted">Noch keine Quizzes hinzugefügt.</td></tr><?php endif; ?>
      </tbody></table>
    </div></div>
  </div>
  <div class="col-lg-5">
    <div class="card card-soft"><div class="card-body p-4">
      <h2 class="h5 fw-bold">Quiz hinzufügen</h2>
      <p class="text-muted">Die Auswahl ist nach Fach und Klasse der aktuellen Klasse vorgefiltert.</p>
      <form method="post" class="row g-3">
        <input type="hidden" name="action" value="add_quiz">
        <div class="col-12"><select class="form-select" name="quiz_id" required><option value="">Bitte wählen</option><?php foreach($available as $quiz): if (in_array((int)$quiz['id'], $assignedIds, true)) continue; ?><option value="<?= (int)$quiz['id'] ?>"><?= teacher_h(($quiz['theme_emoji'] ?? '🎯') . ' ' . $quiz['title']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><button class="btn btn-primary" <?= count($assigned) >= 10 ? 'disabled' : '' ?>>Hinzufügen</button></div>
      </form>
    </div></div>
  </div>
</div>
<?php teacher_footer(); ?>
