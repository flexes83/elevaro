<?php
require_once __DIR__ . '/_layout.php';

function teacher_quiz_tools_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_custom_quizzes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT UNSIGNED NOT NULL,
        class_id INT UNSIGNED NOT NULL,
        source_quiz_id INT UNSIGNED NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_teacher_custom_quizzes_teacher (teacher_id),
        KEY idx_teacher_custom_quizzes_class (class_id),
        KEY idx_teacher_custom_quizzes_source (source_quiz_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_custom_quiz_questions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        custom_quiz_id INT UNSIGNED NOT NULL,
        source_question_id INT UNSIGNED NOT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 100,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_custom_quiz_question (custom_quiz_id, source_question_id),
        KEY idx_teacher_custom_quiz_questions_source (source_question_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

$class = teacher_selected_class();
if (!$class) {
    teacher_header('Quizzes', 'Lege zuerst eine Klasse an.');
    echo '<div class="card card-soft"><div class="card-body p-4"><a class="btn btn-primary" href="classes.php">Klasse anlegen</a></div></div>';
    teacher_footer();
    exit;
}

$pdo = teacher_db();
teacher_quiz_tools_ensure_schema($pdo);
$error = null;
$notice = null;
$classId = (int)$class['id'];
$classLabel = teacher_class_label($class);
$teacherId = teacher_current_user_id();

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
        if ($action === 'copy_selected_questions') {
            $questionIds = array_values(array_unique(array_filter(array_map('intval', $_POST['question_ids'] ?? []))));
            if (!$questionIds) throw new RuntimeException('Bitte mindestens eine Frage auswählen.');

            $ph = implode(',', array_fill(0, count($questionIds), '?'));
            $verify = $pdo->prepare("
                SELECT q.id, q.quiz_id
                FROM questions q
                JOIN teacher_class_quizzes tcq ON tcq.quiz_id = q.quiz_id AND tcq.class_id = ?
                WHERE q.id IN ($ph)
                ORDER BY FIELD(q.id, $ph)
            ");
            $verify->execute(array_merge([$classId], $questionIds, $questionIds));
            $allowedQuestions = $verify->fetchAll();
            if (!$allowedQuestions) throw new RuntimeException('Die ausgewählten Fragen gehören zu keinem freigeschalteten Klassen-Quiz.');

            $sourceQuizIds = array_values(array_unique(array_map(static fn($q) => (int)$q['quiz_id'], $allowedQuestions)));
            $sourceQuizId = count($sourceQuizIds) === 1 ? $sourceQuizIds[0] : null;

            $title = trim((string)($_POST['custom_quiz_title'] ?? ''));
            if ($title === '') {
                $title = count($sourceQuizIds) === 1 ? 'Eigenes Quiz – Auswahl aus Vorlage' : 'Eigenes Quiz – gemischte Auswahl';
            }

            $stmt = $pdo->prepare("INSERT INTO teacher_custom_quizzes (teacher_id, class_id, source_quiz_id, title, description)
                VALUES (:teacher_id, :class_id, :source_quiz_id, :title, :description)");
            $stmt->execute([
                'teacher_id' => $teacherId,
                'class_id' => $classId,
                'source_quiz_id' => $sourceQuizId,
                'title' => $title,
                'description' => 'Aus bestehenden Fragen als Vorlage erstellt.',
            ]);
            $customQuizId = (int)$pdo->lastInsertId();

            $insert = $pdo->prepare("INSERT IGNORE INTO teacher_custom_quiz_questions (custom_quiz_id, source_question_id, sort_order)
                VALUES (:custom_quiz_id, :source_question_id, :sort_order)");
            foreach ($allowedQuestions as $index => $question) {
                $insert->execute([
                    'custom_quiz_id' => $customQuizId,
                    'source_question_id' => (int)$question['id'],
                    'sort_order' => ($index + 1) * 10,
                ]);
            }
            $notice = count($allowedQuestions) . ' Fragen wurden als eigenes Klassen-Quiz gespeichert.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$stmt = $pdo->prepare("\n    SELECT q.*, sub.name AS subject_name\n    FROM teacher_class_quizzes tcq\n    JOIN quizzes q ON q.id = tcq.quiz_id\n    LEFT JOIN subjects sub ON sub.id = q.subject_id\n    WHERE tcq.class_id = :class_id\n    ORDER BY tcq.sort_order, q.title\n");
$stmt->execute(['class_id' => $classId]);
$assigned = $stmt->fetchAll();
$assignedIds = array_map(static fn($q) => (int)$q['id'], $assigned);

$customStmt = $pdo->prepare("SELECT cq.*, COUNT(cqq.id) AS question_count
    FROM teacher_custom_quizzes cq
    LEFT JOIN teacher_custom_quiz_questions cqq ON cqq.custom_quiz_id = cq.id
    WHERE cq.teacher_id = :teacher_id AND cq.class_id = :class_id
    GROUP BY cq.id
    ORDER BY cq.created_at DESC");
$customStmt->execute(['teacher_id' => $teacherId, 'class_id' => $classId]);
$customQuizzes = $customStmt->fetchAll();

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
  .teacher-quiz-card{border-top:1px solid rgba(23,32,51,.12);padding:18px 0}
  .teacher-quiz-row{display:grid;grid-template-columns:minmax(0,1fr) 190px auto;gap:22px;align-items:start}
  .teacher-quiz-title{appearance:none;background:transparent;border:0;padding:0;text-align:left;color:inherit;font:inherit;cursor:pointer;width:100%}
  .teacher-quiz-title:hover strong{text-decoration:underline;text-underline-offset:4px}
  .teacher-quiz-title .toggle-label::after{content:'Details öffnen'}
  .teacher-quiz-title[aria-expanded="true"] .toggle-label::after{content:'Details schließen'}
  .teacher-class-column{font-weight:900;color:#5b6472;padding-top:8px;white-space:normal;line-height:1.2;font-size:.95rem}
  .teacher-quiz-details{background:#f8fafc;border-radius:18px;padding:16px;margin-top:16px}
  .teacher-quiz-meta{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 12px}
  .teacher-quiz-meta span{font-size:.78rem;font-weight:800;background:#fff;border:1px solid rgba(23,32,51,.08);border-radius:999px;padding:5px 9px;color:#5b6472}
  .teacher-question-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0 0 12px;position:sticky;top:0;background:#f8fafc;z-index:2;padding-bottom:8px}
  .teacher-question-toolbar .form-control{max-width:340px;border-radius:999px}
  .teacher-question-list{max-height:460px;overflow:auto;padding-right:4px}
  .teacher-question-item{display:grid;grid-template-columns:28px minmax(0,1fr);gap:10px;background:#fff;border:1px solid rgba(23,32,51,.08);border-radius:14px;padding:10px 12px;margin-bottom:8px}
  .teacher-question-check{padding-top:2px}
  .teacher-question-text{font-weight:900;margin-bottom:7px;line-height:1.22;font-size:.95rem}
  .teacher-answer-list{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:4px}
  .teacher-answer-pill{font-size:.78rem;background:#f1f3f6;border-radius:999px;padding:3px 7px;color:#4f5867}
  .teacher-answer-pill.is-correct{background:#e8f8ef;color:#167347;font-weight:800}
  .teacher-explanation{font-size:.8rem;color:#697385;margin:5px 0 0;line-height:1.3}
  .teacher-custom-list{display:grid;gap:8px;margin-top:12px}
  .teacher-custom-item{background:#f8fafc;border-radius:14px;padding:14px 16px;display:grid;grid-template-columns:minmax(0,1fr) 180px auto;gap:22px;align-items:center;border-top:1px solid rgba(23,32,51,.08)}
  .teacher-global-actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;background:#eef2ff;border-radius:18px;padding:12px;margin:0 0 18px}
  .teacher-selection-count{font-weight:900;color:#172033}
  @media (max-width: 900px){.teacher-custom-item{grid-template-columns:1fr}}
  @media (max-width: 900px){.teacher-quiz-row{grid-template-columns:1fr}.teacher-class-column{padding-top:0}}
</style>
<?php if ($notice): ?><div class="alert alert-success"><?= teacher_h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= teacher_h($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-12">
    <div class="card card-soft"><div class="card-body p-4">
      <h2 class="h5 fw-bold">Freigeschaltete Premium-Quizzes <?= count($assigned) ?>/10</h2>
      <form method="post" id="teacherQuestionSelectionForm" class="m-0">
        <div class="teacher-global-actions mt-3">
          <span class="teacher-selection-count"><span data-selected-count>0</span> Fragen ausgewählt</span>
          <button class="btn btn-sm btn-outline-secondary" type="button" data-global-select-none>Auswahl leeren</button>
          <input class="form-control form-control-sm" style="max-width:340px;border-radius:999px" name="custom_quiz_title" placeholder="Name für eigenes Quiz">
          <button class="btn btn-sm btn-primary" name="action" value="copy_selected_questions">In eigenes Quiz übernehmen</button>
          <button class="btn btn-sm btn-light" formaction="test_pdf.php" formtarget="_blank" name="action" value="test_pdf">Test-PDF herunterladen</button>
        </div>
      <div class="row fw-bold text-muted small mt-4 d-none d-lg-grid" style="grid-template-columns:minmax(0,1fr) 190px auto;gap:22px;">
        <div>Quiz</div><div>Klasse</div><div></div>
      </div>
      <?php foreach ($assigned as $quiz): ?>
        <?php
          $quizId = (int)$quiz['id'];
          $quizQuestions = $questionsByQuiz[$quizId] ?? [];
          $detailsId = 'quizDetails' . $quizId;
        ?>
        <section class="teacher-quiz-card">
          <div class="teacher-quiz-row">
            <button class="teacher-quiz-title" type="button" data-bs-toggle="collapse" data-bs-target="#<?= teacher_h($detailsId) ?>" aria-expanded="false" aria-controls="<?= teacher_h($detailsId) ?>">
              <strong><?= teacher_h(($quiz['theme_emoji'] ?? '🎯') . ' ' . $quiz['title']) ?></strong><br>
              <small class="text-muted"><?= teacher_h($quiz['quiz_key']) ?> · <?= count($quizQuestions) ?> Fragen · <span class="toggle-label"></span></small>
            </button>
            <div class="teacher-class-column"><?= teacher_h($classLabel) ?></div>
            <button class="btn btn-sm btn-light" type="submit" form="removeQuizForm<?= $quizId ?>">Entfernen</button>
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
                <div class="teacher-question-actions">
                  <div class="teacher-question-toolbar">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-select-all>Dieses Quiz auswählen</button>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-select-none>Dieses Quiz leeren</button>
                  </div>
                  <div class="teacher-question-list">
                    <?php foreach ($quizQuestions as $idx => $question): ?>
                      <?php $opts = $optionsByQuestion[(int)$question['id']] ?? []; ?>
                      <label class="teacher-question-item">
                        <span class="teacher-question-check"><input class="form-check-input" type="checkbox" name="question_ids[]" value="<?= (int)$question['id'] ?>"></span>
                        <span>
                          <span class="teacher-question-text"><?= ($idx + 1) ?>. <?= teacher_h($question['question_text']) ?></span>
                          <?php if ($opts): ?>
                            <span class="teacher-answer-list">
                              <?php foreach ($opts as $option): ?>
                                <span class="teacher-answer-pill <?= (int)($option['is_correct'] ?? 0) === 1 ? 'is-correct' : '' ?>">
                                  <?= (int)($option['is_correct'] ?? 0) === 1 ? '✓ ' : '' ?><?= teacher_h($option['option_text']) ?>
                                </span>
                              <?php endforeach; ?>
                            </span>
                          <?php elseif (!empty($question['correct_answer'])): ?>
                            <span class="teacher-answer-list"><span class="teacher-answer-pill is-correct">✓ <?= teacher_h($question['correct_answer']) ?></span></span>
                          <?php endif; ?>
                          <?php if (!empty($question['explanation'])): ?><span class="teacher-explanation d-block"><?= teacher_h($question['explanation']) ?></span><?php endif; ?>
                        </span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php else: ?>
                <div class="text-muted">Für dieses Quiz sind noch keine Fragen hinterlegt.</div>
              <?php endif; ?>
            </div>
          </div>
        </section>
      <?php endforeach; ?>
      <?php if (!$assigned): ?><div class="text-muted pt-4">Noch keine Quizzes hinzugefügt.</div><?php endif; ?>
      </form>
      <?php foreach ($assigned as $quiz): ?>
        <form method="post" id="removeQuizForm<?= (int)$quiz['id'] ?>" class="d-none">
          <input type="hidden" name="action" value="remove_quiz">
          <input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>">
        </form>
      <?php endforeach; ?>
    </div></div>
  </div>
  <div class="col-12 col-xl-6">
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

  <div class="col-12">
    <div class="card card-soft"><div class="card-body p-4">
      <h2 class="h5 fw-bold">Eigene Klassen-Quizzes</h2>
      <p class="text-muted mb-2">Ausgewählte Fragen aus Vorlagen. Sichtbar nur für diese Klasse.</p>
      <div class="teacher-custom-list">
        <?php foreach ($customQuizzes as $custom): ?>
          <div class="teacher-custom-item">
            <div><strong>🧩 <?= teacher_h($custom['title']) ?></strong><br><span class="text-muted">Eigenes Klassen-Quiz · <?= (int)$custom['question_count'] ?> Fragen</span></div>
            <div class="teacher-class-column"><?= teacher_h($classLabel) ?></div>
            <span class="badge text-bg-light"><?= (int)$custom['question_count'] ?> Fragen</span>
          </div>
        <?php endforeach; ?>
        <?php if (!$customQuizzes): ?><div class="text-muted">Noch kein eigenes Quiz erstellt.</div><?php endif; ?>
      </div>
    </div></div>
  </div>
</div>
<script>
const selectionForm = document.getElementById('teacherQuestionSelectionForm');
const countNode = document.querySelector('[data-selected-count]');
function updateSelectedCount(){
  if(!selectionForm || !countNode) return;
  countNode.textContent = selectionForm.querySelectorAll('input[type="checkbox"][name="question_ids[]"]:checked').length;
}
document.querySelectorAll('.teacher-question-actions').forEach((scope) => {
  const boxes = () => Array.from(scope.querySelectorAll('input[type="checkbox"][name="question_ids[]"]'));
  scope.querySelector('[data-select-all]')?.addEventListener('click', () => { boxes().forEach((box) => box.checked = true); updateSelectedCount(); });
  scope.querySelector('[data-select-none]')?.addEventListener('click', () => { boxes().forEach((box) => box.checked = false); updateSelectedCount(); });
});
selectionForm?.querySelectorAll('input[type="checkbox"][name="question_ids[]"]').forEach((box) => box.addEventListener('change', updateSelectedCount));
document.querySelector('[data-global-select-none]')?.addEventListener('click', () => {
  selectionForm?.querySelectorAll('input[type="checkbox"][name="question_ids[]"]').forEach((box) => box.checked = false);
  updateSelectedCount();
});
selectionForm?.addEventListener('submit', (event) => {
  if (selectionForm.querySelectorAll('input[type="checkbox"][name="question_ids[]"]:checked').length === 0) {
    event.preventDefault();
    alert('Bitte zuerst mindestens eine Frage auswählen.');
  }
});
updateSelectedCount();
</script>
<?php teacher_footer(); ?>
