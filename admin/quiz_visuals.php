<?php
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/quiz_theme.php';

$pdo = elevaro_db();
$quizId = (int)($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);

if (!$quizId) {
    die('quiz_id fehlt.');
}

$stmt = $pdo->prepare("
    SELECT q.*, sub.name AS subject_name
    FROM quizzes q
    LEFT JOIN subjects sub ON sub.id = q.subject_id
    WHERE q.id = :id
    LIMIT 1
");
$stmt->execute(['id' => $quizId]);
$quiz = $stmt->fetch();

if (!$quiz) {
    die('Quiz nicht gefunden.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['auto_theme'])) {
        $theme = elevaro_subject_theme($quiz['subject_name'] ?? '', $quiz['title']);
        $stmt = $pdo->prepare("
            UPDATE quizzes
            SET theme_color_1 = :c1,
                theme_color_2 = :c2,
                theme_emoji = :emoji
            WHERE id = :id
        ");
        $stmt->execute([
            'c1' => $theme['color_1'],
            'c2' => $theme['color_2'],
            'emoji' => $theme['emoji'],
            'id' => $quizId,
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE quizzes
            SET image_path = :image_path,
                image_source = :image_source,
                image_credit = :image_credit,
                image_prompt = :image_prompt,
                image_status = :image_status,
                theme_color_1 = :theme_color_1,
                theme_color_2 = :theme_color_2,
                theme_emoji = :theme_emoji
            WHERE id = :id
        ");

        $stmt->execute([
            'image_path' => trim($_POST['image_path'] ?? '') ?: null,
            'image_source' => trim($_POST['image_source'] ?? '') ?: null,
            'image_credit' => trim($_POST['image_credit'] ?? '') ?: null,
            'image_prompt' => trim($_POST['image_prompt'] ?? '') ?: null,
            'image_status' => $_POST['image_status'] ?? 'none',
            'theme_color_1' => trim($_POST['theme_color_1'] ?? '') ?: '#5a4ff3',
            'theme_color_2' => trim($_POST['theme_color_2'] ?? '') ?: '#8b7cff',
            'theme_emoji' => trim($_POST['theme_emoji'] ?? '') ?: '🎯',
            'id' => $quizId,
        ]);
    }

    header('Location: quiz_visuals.php?quiz_id=' . $quizId . '&saved=1');
    exit;
}

$imagePrompt = $quiz['image_prompt'] ?: elevaro_image_prompt_for_quiz($quiz);

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Quiz Visuals – Elevaro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="../assets/css/quiz_cards.css" rel="stylesheet">
</head>
<body class="bg-light p-4">
  <div class="container" style="max-width: 1060px;">
    <a href="quiz_questions.php?quiz_id=<?= (int)$quizId ?>" class="btn btn-light mb-3">← zurück zum Quiz</a>

    <?php if (!empty($_GET['saved'])): ?>
      <div class="alert alert-success">Gespeichert.</div>
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-lg-5">
        <h1 class="fw-bold">Visuals</h1>
        <p class="text-muted"><?= h($quiz['title']) ?></p>

        <article class="elevaro-quiz-card mb-4" style="--quiz-c1: <?= h($quiz['theme_color_1'] ?: '#5a4ff3') ?>; --quiz-c2: <?= h($quiz['theme_color_2'] ?: '#8b7cff') ?>;">
          <?php if (!empty($quiz['image_path']) && $quiz['image_status'] === 'approved'): ?>
            <img src="<?= h($quiz['image_path']) ?>" alt="" class="elevaro-quiz-card-img">
          <?php else: ?>
            <div class="elevaro-quiz-card-visual">
              <span><?= h($quiz['theme_emoji'] ?: '🎯') ?></span>
            </div>
          <?php endif; ?>
          <div class="elevaro-quiz-card-body">
            <span class="elevaro-quiz-card-badge"><?= h($quiz['subject_name'] ?? 'Quiz') ?> · Klasse <?= h($quiz['grade'] ?? '') ?></span>
            <h2><?= h($quiz['title']) ?></h2>
            <p><?= h($quiz['description'] ?? '') ?></p>
          </div>
        </article>

        <form method="post">
          <input type="hidden" name="auto_theme" value="1">
          <button class="btn btn-outline-primary">🎨 Theme automatisch neu setzen</button>
        </form>
      </div>

      <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
          <div class="card-body p-4">
            <form method="post">
              <h2 class="h4 fw-bold">Darstellung bearbeiten</h2>

              <div class="row g-3">
                <div class="col-md-5">
                  <label class="form-label fw-bold">Farbe 1</label>
                  <input class="form-control form-control-color w-100" type="color" name="theme_color_1" value="<?= h($quiz['theme_color_1'] ?: '#5a4ff3') ?>">
                </div>
                <div class="col-md-5">
                  <label class="form-label fw-bold">Farbe 2</label>
                  <input class="form-control form-control-color w-100" type="color" name="theme_color_2" value="<?= h($quiz['theme_color_2'] ?: '#8b7cff') ?>">
                </div>
                <div class="col-md-2">
                  <label class="form-label fw-bold">Emoji</label>
                  <input class="form-control" name="theme_emoji" value="<?= h($quiz['theme_emoji'] ?: '🎯') ?>">
                </div>

                <div class="col-12">
                  <label class="form-label fw-bold">Bildpfad</label>
                  <input class="form-control" name="image_path" value="<?= h($quiz['image_path'] ?? '') ?>" placeholder="/uploads/quizzes/bild.jpg oder Freepik-Download">
                </div>

                <div class="col-md-6">
                  <label class="form-label fw-bold">Bildquelle</label>
                  <select class="form-select" name="image_source">
                    <?php foreach (['none','ai','freepik','upload'] as $source): ?>
                      <option value="<?= h($source) ?>" <?= ($quiz['image_source'] ?? 'none') === $source ? 'selected' : '' ?>><?= h($source) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label fw-bold">Bildstatus</label>
                  <select class="form-select" name="image_status">
                    <?php foreach (['none','draft','approved','rejected'] as $status): ?>
                      <option value="<?= h($status) ?>" <?= ($quiz['image_status'] ?? 'none') === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>

                <div class="col-12">
                  <label class="form-label fw-bold">Credit / Lizenzhinweis</label>
                  <input class="form-control" name="image_credit" value="<?= h($quiz['image_credit'] ?? '') ?>">
                </div>

                <div class="col-12">
                  <label class="form-label fw-bold">KI-Bildprompt</label>
                  <textarea class="form-control" rows="6" name="image_prompt"><?= h($imagePrompt) ?></textarea>
                  <div class="form-text">Kann für einen späteren Bildgenerator oder manuell in einem Bildtool genutzt werden.</div>
                </div>
              </div>

              <button class="btn btn-primary btn-lg mt-4">Speichern</button>
            </form>

            <hr class="my-4">

            <div class="alert alert-info mb-0">
              Nächster Ausbauschritt: Button „KI-Bild generieren“ oder „Freepik suchen“ direkt hier anbinden.
              Die Datenstruktur ist dafür vorbereitet.
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
