<?php
require_once __DIR__ . '/../app/includes/db.php';

$pdo = elevaro_db();
$quizId = (int)($_GET['quiz_id'] ?? 0);

if (!$quizId) {
    $quizzes = $pdo->query("SELECT id, title, quiz_key FROM quizzes ORDER BY title ASC")->fetchAll();
    ?>
    <!doctype html>
    <html lang="de">
    <head>
      <meta charset="utf-8">
      <title>Quiz Admin – Elevaro</title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="p-4">
      <div class="container">
        <h1>Quiz Admin</h1>
        <p class="text-muted">Wähle ein Quiz zum Bearbeiten.</p>
        <div class="list-group">
          <?php foreach ($quizzes as $quiz): ?>
            <a class="list-group-item list-group-item-action" href="?quiz_id=<?= (int)$quiz['id'] ?>">
              <strong><?= htmlspecialchars($quiz['title']) ?></strong>
              <small class="text-muted d-block"><?= htmlspecialchars($quiz['quiz_key']) ?></small>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($_POST['questions'] ?? [] as $questionId => $data) {
        $stmt = $pdo->prepare("
            UPDATE questions
            SET question_text = :question_text,
                correct_answer = :correct_answer,
                explanation = :explanation,
                status = :status
            WHERE id = :id
        ");
        $stmt->execute([
            'question_text' => $data['question_text'] ?? '',
            'correct_answer' => $data['correct_answer'] ?? '',
            'explanation' => $data['explanation'] ?? '',
            'status' => $data['status'] ?? 'draft',
            'id' => (int)$questionId,
        ]);
    }

    header('Location: ?quiz_id=' . $quizId . '&saved=1');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = :id");
$stmt->execute(['id' => $quizId]);
$quiz = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT *
    FROM questions
    WHERE quiz_id = :quiz_id
    ORDER BY sort_order ASC, id ASC
");
$stmt->execute(['quiz_id' => $quizId]);
$questions = $stmt->fetchAll();
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($quiz['title']) ?> – Quiz Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-4">
  <div class="container">
    <a href="quiz_questions.php" class="btn btn-light mb-3">← alle Quizze</a>
    <h1><?= htmlspecialchars($quiz['title']) ?></h1>
    <p class="text-muted"><?= htmlspecialchars($quiz['quiz_key']) ?></p>

    <?php if (!empty($_GET['saved'])): ?>
      <div class="alert alert-success">Gespeichert.</div>
    <?php endif; ?>

    <form method="post">
      <?php foreach ($questions as $question): ?>
        <div class="card mb-3">
          <div class="card-body">
            <label class="form-label fw-bold">Frage</label>
            <textarea class="form-control mb-3" name="questions[<?= (int)$question['id'] ?>][question_text]" rows="2"><?= htmlspecialchars($question['question_text']) ?></textarea>

            <label class="form-label fw-bold">Richtige Antwort</label>
            <input class="form-control mb-3" name="questions[<?= (int)$question['id'] ?>][correct_answer]" value="<?= htmlspecialchars($question['correct_answer']) ?>">

            <label class="form-label fw-bold">Erklärung</label>
            <textarea class="form-control mb-3" name="questions[<?= (int)$question['id'] ?>][explanation]" rows="2"><?= htmlspecialchars($question['explanation'] ?? '') ?></textarea>

            <label class="form-label fw-bold">Status</label>
            <select class="form-select" name="questions[<?= (int)$question['id'] ?>][status]">
              <option value="draft" <?= $question['status'] === 'draft' ? 'selected' : '' ?>>Entwurf</option>
              <option value="published" <?= $question['status'] === 'published' ? 'selected' : '' ?>>Veröffentlicht</option>
              <option value="archived" <?= $question['status'] === 'archived' ? 'selected' : '' ?>>Archiviert</option>
            </select>
          </div>
        </div>
      <?php endforeach; ?>

      <button class="btn btn-primary btn-lg">Speichern</button>
    </form>
  </div>
</body>
</html>
