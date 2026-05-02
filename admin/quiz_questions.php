<?php
require_once __DIR__ . '/../app/includes/db.php';

$pdo = elevaro_db();
$quizId = (int)($_GET['quiz_id'] ?? 0);

if (!$quizId) {
    $quizzes = $pdo->query("SELECT id, title, quiz_key, status FROM quizzes ORDER BY id DESC")->fetchAll();
    ?>
    <!doctype html>
    <html lang="de">
    <head>
      <meta charset="utf-8">
      <title>Quizze – Elevaro Admin</title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light p-4">
      <div class="container">
        <h1 class="fw-bold">Quizze</h1>
        <div class="list-group">
          <?php foreach ($quizzes as $quiz): ?>
            <a class="list-group-item list-group-item-action" href="?quiz_id=<?= (int)$quiz['id'] ?>">
              <strong><?= htmlspecialchars($quiz['title']) ?></strong>
              <small class="text-muted d-block"><?= htmlspecialchars($quiz['quiz_key']) ?> · <?= htmlspecialchars($quiz['status']) ?></small>
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
    if (isset($_POST['publish_all'])) {
        $stmt = $pdo->prepare("UPDATE questions SET status = 'published' WHERE quiz_id = :quiz_id");
        $stmt->execute(['quiz_id' => $quizId]);

        $stmt = $pdo->prepare("UPDATE quizzes SET status = 'published', is_active = 1 WHERE id = :quiz_id");
        $stmt->execute(['quiz_id' => $quizId]);

        header('Location: quiz_questions.php?quiz_id=' . $quizId . '&published=1');
        exit;
    }

    foreach ($_POST['questions'] ?? [] as $questionId => $data) {
        $stmt = $pdo->prepare("
            UPDATE questions
            SET question_text = :question_text,
                media_type = :media_type,
                media_path = :media_path,
                media_alt = :media_alt,
                media_recommendation = :media_recommendation,
                media_prompt = :media_prompt,
                media_search_terms = :media_search_terms,
                correct_answer = :correct_answer,
                explanation = :explanation,
                difficulty_manual = :difficulty_manual,
                status = :status
            WHERE id = :id
        ");

        $stmt->execute([
            'question_text' => $data['question_text'] ?? '',
            'media_type' => $data['media_type'] ?? 'none',
            'media_path' => trim($data['media_path'] ?? '') ?: null,
            'media_alt' => trim($data['media_alt'] ?? '') ?: null,
            'media_recommendation' => $data['media_recommendation'] ?? 'none',
            'media_prompt' => trim($data['media_prompt'] ?? '') ?: null,
            'media_search_terms' => trim($data['media_search_terms'] ?? '') ?: null,
            'correct_answer' => $data['correct_answer'] ?? '',
            'explanation' => $data['explanation'] ?? '',
            'difficulty_manual' => ($data['difficulty_manual'] ?? '') !== '' ? (float)$data['difficulty_manual'] : null,
            'status' => $data['status'] ?? 'draft',
            'id' => (int)$questionId,
        ]);

        foreach ($data['options'] ?? [] as $optionId => $optionData) {
            $stmt = $pdo->prepare("
                UPDATE question_options
                SET option_text = :option_text,
                    media_type = :media_type,
                    media_path = :media_path,
                    media_alt = :media_alt,
                    media_prompt = :media_prompt,
                    media_search_terms = :media_search_terms,
                    is_correct = :is_correct
                WHERE id = :id
            ");

            $stmt->execute([
                'option_text' => $optionData['option_text'] ?? '',
                'media_type' => $optionData['media_type'] ?? 'none',
                'media_path' => trim($optionData['media_path'] ?? '') ?: null,
                'media_alt' => trim($optionData['media_alt'] ?? '') ?: null,
                'media_prompt' => trim($optionData['media_prompt'] ?? '') ?: null,
                'media_search_terms' => trim($optionData['media_search_terms'] ?? '') ?: null,
                'is_correct' => (($data['correct_answer'] ?? '') === ($optionData['option_text'] ?? '')) ? 1 : 0,
                'id' => (int)$optionId,
            ]);
        }
    }

    header('Location: quiz_questions.php?quiz_id=' . $quizId . '&saved=1');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = :id");
$stmt->execute(['id' => $quizId]);
$quiz = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT q.*, qs.times_answered, qs.times_correct, qs.times_wrong, qs.calculated_difficulty
    FROM questions q
    LEFT JOIN question_stats qs ON qs.question_id = q.id
    WHERE q.quiz_id = :quiz_id
    ORDER BY q.sort_order ASC, q.id ASC
");
$stmt->execute(['quiz_id' => $quizId]);
$questions = $stmt->fetchAll();

$optionsByQuestion = [];

if ($questions) {
    $ids = array_column($questions, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM question_options WHERE question_id IN ($placeholders) ORDER BY question_id, sort_order, id");
    $stmt->execute($ids);

    foreach ($stmt->fetchAll() as $option) {
        $optionsByQuestion[(int)$option['question_id']][] = $option;
    }
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function mediaBadge(string $recommendation): string
{
    return match ($recommendation) {
        'question_image' => 'Bild zur Frage empfohlen',
        'answer_images' => 'Bilder als Antworten empfohlen',
        default => 'Kein Bild empfohlen',
    };
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title><?= h($quiz['title']) ?> – Fragen Review</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-4">
  <div class="container">
    <a href="quiz_questions.php" class="btn btn-light mb-3">← alle Quizze</a>

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
      <div>
        <h1 class="fw-bold"><?= h($quiz['title']) ?></h1>
        <p class="text-muted mb-0"><?= h($quiz['quiz_key']) ?> · <?= h($quiz['status']) ?></p>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <a href="quiz_visuals.php?quiz_id=<?= (int)$quizId ?>" class="btn btn-outline-primary">🎨 Visuals</a>
        <a href="../quiz.php?key=<?= h($quiz['quiz_key']) ?>" class="btn btn-outline-secondary" target="_blank">Vorschau</a>
        <form method="post" class="d-inline">
          <button name="publish_all" value="1" class="btn btn-success">Alles veröffentlichen</button>
        </form>
      </div>
    </div>

    <?php if (!empty($_GET['saved'])): ?>
      <div class="alert alert-success">Gespeichert.</div>
    <?php endif; ?>

    <?php if (!empty($_GET['published'])): ?>
      <div class="alert alert-success">Quiz und Fragen veröffentlicht.</div>
    <?php endif; ?>

    <?php if (!empty($_GET['ai_generated'])): ?>
      <div class="alert alert-info">KI-Fragen wurden als Entwurf gespeichert. Bitte prüfen, Medien ergänzen und veröffentlichen.</div>
    <?php endif; ?>

    <form method="post">
      <?php foreach ($questions as $question): ?>
        <div class="card mb-4 border-0 shadow-sm">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between gap-3 flex-wrap mb-3">
              <div>
                <span class="badge text-bg-<?= $question['status'] === 'published' ? 'success' : 'secondary' ?>">
                  <?= h($question['status']) ?>
                </span>
                <?php if ((int)$question['ai_generated'] === 1): ?>
                  <span class="badge text-bg-primary">KI</span>
                <?php endif; ?>
                <span class="badge text-bg-light text-dark"><?= h(mediaBadge($question['media_recommendation'] ?? 'none')) ?></span>
              </div>
              <small class="text-muted">
                Statistik:
                <?= (int)($question['times_correct'] ?? 0) ?> richtig /
                <?= (int)($question['times_wrong'] ?? 0) ?> falsch ·
                Schwierigkeit <?= h($question['calculated_difficulty'] ?? $question['difficulty_calculated'] ?? '') ?>
              </small>
            </div>

            <label class="form-label fw-bold">Frage</label>
            <textarea class="form-control mb-3" name="questions[<?= (int)$question['id'] ?>][question_text]" rows="2"><?= h($question['question_text']) ?></textarea>

            <div class="border rounded p-3 mb-3 bg-light">
              <h6 class="fw-bold">Medien zur Frage</h6>

              <?php if (($question['media_recommendation'] ?? 'none') !== 'none'): ?>
                <div class="alert alert-info py-2">
                  <strong>KI-Empfehlung:</strong> <?= h(mediaBadge($question['media_recommendation'])) ?><br>
                  <?php if (!empty($question['media_search_terms'])): ?>
                    <strong>Suchbegriffe:</strong> <?= h($question['media_search_terms']) ?><br>
                  <?php endif; ?>
                  <?php if (!empty($question['media_prompt'])): ?>
                    <strong>Bildprompt:</strong> <?= h($question['media_prompt']) ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <input type="hidden" name="questions[<?= (int)$question['id'] ?>][media_recommendation]" value="<?= h($question['media_recommendation'] ?? 'none') ?>">
              <input type="hidden" name="questions[<?= (int)$question['id'] ?>][media_prompt]" value="<?= h($question['media_prompt'] ?? '') ?>">
              <input type="hidden" name="questions[<?= (int)$question['id'] ?>][media_search_terms]" value="<?= h($question['media_search_terms'] ?? '') ?>">

              <div class="row g-2">
                <div class="col-md-3">
                  <label class="form-label">Typ</label>
                  <select class="form-select" name="questions[<?= (int)$question['id'] ?>][media_type]">
                    <option value="none" <?= ($question['media_type'] ?? 'none') === 'none' ? 'selected' : '' ?>>kein Medium</option>
                    <option value="image" <?= ($question['media_type'] ?? 'none') === 'image' ? 'selected' : '' ?>>Bild</option>
                    <option value="audio" <?= ($question['media_type'] ?? 'none') === 'audio' ? 'selected' : '' ?>>Audio</option>
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label">Pfad / URL</label>
                  <input class="form-control" name="questions[<?= (int)$question['id'] ?>][media_path]" value="<?= h($question['media_path'] ?? '') ?>" placeholder="/uploads/questions/elster.jpg">
                </div>

                <div class="col-md-3">
                  <label class="form-label">Alt-Text</label>
                  <input class="form-control" name="questions[<?= (int)$question['id'] ?>][media_alt]" value="<?= h($question['media_alt'] ?? '') ?>">
                </div>
              </div>
            </div>

            <div class="row g-3">
              <div class="col-md-8">
                <label class="form-label fw-bold">Richtige Antwort</label>
                <input class="form-control mb-3" name="questions[<?= (int)$question['id'] ?>][correct_answer]" value="<?= h($question['correct_answer']) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label fw-bold">Manuelle Schwierigkeit</label>
                <input class="form-control mb-3" name="questions[<?= (int)$question['id'] ?>][difficulty_manual]" value="<?= h($question['difficulty_manual'] ?? '') ?>" placeholder="0.05–0.95">
              </div>
            </div>

            <label class="form-label fw-bold">Antwortoptionen</label>
            <?php foreach (($optionsByQuestion[(int)$question['id']] ?? []) as $option): ?>
              <div class="border rounded p-3 mb-2 bg-white">
                <input class="form-control mb-2" name="questions[<?= (int)$question['id'] ?>][options][<?= (int)$option['id'] ?>][option_text]" value="<?= h($option['option_text']) ?>">

                <?php if (($question['media_recommendation'] ?? '') === 'answer_images'): ?>
                  <div class="alert alert-info py-2">
                    <strong>Bild für diese Antwort empfohlen.</strong><br>
                    <?php if (!empty($option['media_search_terms'])): ?>
                      <strong>Suchbegriffe:</strong> <?= h($option['media_search_terms']) ?><br>
                    <?php endif; ?>
                    <?php if (!empty($option['media_prompt'])): ?>
                      <strong>Bildprompt:</strong> <?= h($option['media_prompt']) ?>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <input type="hidden" name="questions[<?= (int)$question['id'] ?>][options][<?= (int)$option['id'] ?>][media_prompt]" value="<?= h($option['media_prompt'] ?? '') ?>">
                <input type="hidden" name="questions[<?= (int)$question['id'] ?>][options][<?= (int)$option['id'] ?>][media_search_terms]" value="<?= h($option['media_search_terms'] ?? '') ?>">

                <div class="row g-2">
                  <div class="col-md-3">
                    <select class="form-select" name="questions[<?= (int)$question['id'] ?>][options][<?= (int)$option['id'] ?>][media_type]">
                      <option value="none" <?= ($option['media_type'] ?? 'none') === 'none' ? 'selected' : '' ?>>kein Bild</option>
                      <option value="image" <?= ($option['media_type'] ?? 'none') === 'image' ? 'selected' : '' ?>>Bild</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <input class="form-control" name="questions[<?= (int)$question['id'] ?>][options][<?= (int)$option['id'] ?>][media_path]" value="<?= h($option['media_path'] ?? '') ?>" placeholder="/uploads/options/elster.jpg">
                  </div>
                  <div class="col-md-3">
                    <input class="form-control" name="questions[<?= (int)$question['id'] ?>][options][<?= (int)$option['id'] ?>][media_alt]" value="<?= h($option['media_alt'] ?? '') ?>" placeholder="Alt-Text">
                  </div>
                </div>
              </div>
            <?php endforeach; ?>

            <label class="form-label fw-bold mt-3">Erklärung</label>
            <textarea class="form-control mb-3" name="questions[<?= (int)$question['id'] ?>][explanation]" rows="3"><?= h($question['explanation'] ?? '') ?></textarea>

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
