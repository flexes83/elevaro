<?php
require_once __DIR__ . '/_layout.php';

$class = teacher_selected_class();
if (!$class) {
    teacher_header('Quizzes', 'Lege zuerst eine Klasse an.');
    echo '<div class="card card-soft"><div class="card-body p-4"><a class="btn btn-primary" href="classes.php">Klasse anlegen</a></div></div>';
    teacher_footer();
    exit;
}

$pdo = teacher_db();
$error = null;
$notice = null;
$classId = (int)$class['id'];
$classLabel = teacher_class_label($class);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'add_quiz') {
            if (teacher_class_quiz_count($classId) >= 10) {
                throw new RuntimeException('Pro Klasse können maximal 10 Quizzes freigeschaltet werden.');
            }
            $quizId = (int)($_POST['quiz_id'] ?? 0);
            if (!$quizId) throw new RuntimeException('Bitte Quiz auswählen.');
            $pdo->prepare("INSERT IGNORE INTO teacher_class_quizzes (class_id, quiz_id, sort_order) VALUES (:class_id, :quiz_id, 100)")
                ->execute(['class_id' => $classId, 'quiz_id' => $quizId]);
            $notice = 'Quiz wurde der Klasse hinzugefügt.';
        }
        if ($action === 'remove_quiz') {
            $pdo->prepare("DELETE FROM teacher_class_quizzes WHERE class_id = :class_id AND quiz_id = :quiz_id")
                ->execute(['class_id' => $classId, 'quiz_id' => (int)($_POST['quiz_id'] ?? 0)]);
            $notice = 'Quiz wurde entfernt.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = $pdo->prepare("\n    SELECT q.*, sub.name AS subject_name\n    FROM teacher_class_quizzes tcq\n    JOIN quizzes q ON q.id = tcq.quiz_id\n    LEFT JOIN subjects sub ON sub.id = q.subject_id\n    WHERE tcq.class_id = :class_id\n    ORDER BY tcq.sort_order, q.title\n");
$stmt->execute(['class_id' => $classId]);
$assigned = $stmt->fetchAll();
$assignedIds = array_map(static fn($q) => (int)$q['id'], $assigned);

$questionsByQuiz = [];
$optionsByQuestion = [];
if ($assignedIds) {
    $ph = implode(',', array_fill(0, count($assignedIds), '?'));
    $qStmt = $pdo->prepare("\n        SELECT id, quiz_id, type, question_text, correct_answer, explanation, difficulty_manual, difficulty_calculated, sort_order\n        FROM questions\n        WHERE quiz_id IN ($ph)\n        ORDER BY quiz_id, sort_order, id\n    ");
    $qStmt->execute($assignedIds);
    $questions = $qStmt->fetchAll();

    $questionIds = [];
    foreach ($questions as $question) {
        $questionsByQuiz[(int)$question['quiz_id']][] = $question;
        $questionIds[] = (int)$question['id'];
    }

    if ($questionIds) {
        $oph = implode(',', array_fill(0, count($questionIds), '?'));
        $oStmt = $pdo->prepare("\n            SELECT question_id, option_text, is_correct, sort_order\n            FROM question_options\n            WHERE question_id IN ($oph)\n            ORDER BY question_id, sort_order, id\n        ");
        $oStmt->execute($questionIds);
        foreach ($oStmt->fetchAll() as $option) {
            $optionsByQuestion[(int)$option['question_id']][] = $option;
        }
    }
}

$params = ['subject' => $class['subject_code'] ?? '', 'subject_empty' => empty($class['subject_code']) ? 1 : 0];
$gradeWhere = '';
if (!empty($class['grade'])) {
    $gradeWhere = 'AND (q.grade = :grade OR q.grade IS NULL)';
    $params['grade'] = (int)$class['grade'];
}
$stmt = $pdo->prepare("\n    SELECT q.id, q.quiz_key, q.title, q.description, q.grade, q.theme_emoji, sub.name AS subject_name\n    FROM quizzes q\n    LEFT JOIN subjects sub ON sub.id = q.subject_id\n    WHERE (sub.code = :subject OR :subject_empty = 1)\n      {$gradeWhere}\n      AND q.is_active = 1\n      AND (q.status IN ('published','draft') OR q.status IS NULL OR q.status = '')\n    ORDER BY q.grade, q.title\n    LIMIT 80\n");
$stmt->execute($params);
$available = $stmt->fetchAll();

teacher_header('Quizzes', 'Bis zu 10 Quizzes für ' . $classLabel . ' freischalten.');
?>
<style>
  .teacher-quiz-title{appearance:none;background:transparent;border:0;padding:0;text-align:left;color:inherit;font:inherit;cursor:pointer}
  .teacher-quiz-title:hover strong{text-decoration:underline;text-underline-offset:4px}
  .teacher-quiz-details{background:#f8fafc;border-radius:18px;padding:16px;margin-top:14px}
  .teacher-quiz-meta{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 12px}
  .teacher-quiz-meta span{font-size:.78rem;font-weight:800;background:#fff;border:1px solid rgba(23,32,51,.08);border-radius:999px;padding:5px 9px;color:#5b6472}
  .teacher-question-list{max-height:430px;overflow:auto;padding-right:4px}
  .teacher-question-item{background:#fff;border:1px solid rgba(23,32,51,.08);border-radius:14px;padding:12px 14px;margin-bottom:10px}
  .teacher-question-text{font-weight:900;margin-bottom:8px;line-height:1.25}
  .teacher-answer-list{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:6px}
  .teacher-answer-pill{font-size:.82rem;background:#f1f3f6;border-radius:999px;padding:4px 8px;color:#4f5867}
  .teacher-answer-pill.is-correct{background:#e8f8ef;color:#167347;font-weight:800}
  .teacher-explanation{font-size:.86rem;color:#697385;margin:6px 0 0;line-height:1.35}
</style>
<?php if ($notice): ?><div class="alert alert-success"><?= teacher_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= teacher_h($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card card-soft admin-table-card"><div class="card-body p-4">
      <h2 class="h5 fw-bold">Freigeschaltete Premium-Quizzes <?= count($assigned) ?>/10</h2>
      <table class="table align-middle"><thead><tr><th>Quiz</th><th>Klasse</th><th></th></tr></thead><tbody>
      <?php foreach ($assigned as $quiz): ?>
        <?php
          $quizId = (int)$quiz['id'];
          $quizQuestions = $questionsByQuiz[$quizId] ?? [];
          $detailsId = 'quizDetails' . $quizId;
        ?>
        <tr>
          <td colspan="3">
            <div class="d-flex justify-content-between gap-3 align-items-start">
              <button class="teacher-quiz-title" type="button" data-bs-toggle="collapse" data-bs-target="#<?= teacher_h($detailsId) ?>" aria-expanded="false" aria-controls="<?= teacher_h($detailsId) ?>">
                <strong><?= teacher_h(($quiz['theme_emoji'] ?? '🎯') . ' ' . $quiz['title']) ?></strong><br>
                <small class="text-muted"><?= teacher_h($quiz['quiz_key']) ?> · <?= count($quizQuestions) ?> Fragen · Details öffnen</small>
              </button>
              <div class="d-flex gap-3 align-items-center flex-shrink-0">
                <strong class="text-muted"><?= teacher_h($classLabel) ?></strong>
                <form method="post" class="m-0">
                  <input type="hidden" name="action" value="remove_quiz">
                  <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
                  <button class="btn btn-sm btn-light">Entfernen</button>
                </form>
              </div>
            </div>
            <div class="collapse" id="<?= teacher_h($detailsId) ?>">
              <div class="teacher-quiz-details">
                <div class="teacher-quiz-meta">
                  <?php if (!empty($quiz['subject_name'])): ?><span>Fach: <?= teacher_h($quiz['subject_name']) ?></span><?php endif; ?>
                  <span>Klasse: <?= teacher_h($classLabel) ?></span>
                  <?php if (!empty($quiz['grade'])): ?><span>Quiz-Stufe: <?= teacher_h((string)$quiz['grade']) ?></span><?php endif; ?>
                  <span><?= count($quizQuestions) ?> Fragen</span>
                </div>
                <?php if (!empty($quiz['description'])): ?><p class="text-muted mb-3"><?= teacher_h($quiz['description']) ?></p><?php endif; ?>
                <?php if ($quizQuestions): ?>
                  <div class="teacher-question-list">
                    <?php foreach ($quizQuestions as $idx => $question): ?>
                      <?php $opts = $optionsByQuestion[(int)$question['id']] ?? []; ?>
                      <div class="teacher-question-item">
                        <div class="teacher-question-text"><?= ($idx + 1) ?>. <?= teacher_h($question['question_text']) ?></div>
                        <?php if ($opts): ?>
                          <div class="teacher-answer-list">
                            <?php foreach ($opts as $option): ?>
                              <span class="teacher-answer-pill <?= (int)($option['is_correct'] ?? 0) === 1 ? 'is-correct' : '' ?>">
                                <?= (int)($option['is_correct'] ?? 0) === 1 ? '✓ ' : '' ?><?= teacher_h($option['option_text']) ?>
                              </span>
                            <?php endforeach; ?>
                          </div>
                        <?php elseif (!empty($question['correct_answer'])): ?>
                          <div class="teacher-answer-list"><span class="teacher-answer-pill is-correct">✓ <?= teacher_h($question['correct_answer']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($question['explanation'])): ?><p class="teacher-explanation"><?= teacher_h($question['explanation']) ?></p><?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php else: ?>
                  <div class="text-muted">Für dieses Quiz sind noch keine Fragen hinterlegt.</div>
                <?php endif; ?>
              </div>
            </div>
          </td>
        </tr>
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
