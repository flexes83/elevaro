<?php
$quizSlug = $_GET['quiz'] ?? 'mathe/klasse-5/bruchrechnen';
$configPath = __DIR__ . '/data/quizzes/' . $quizSlug . '.json';

if (!file_exists($configPath)) {
    http_response_code(404);
    echo 'Quiz nicht gefunden.';
    exit;
}

$quiz = json_decode(file_get_contents($configPath), true);
$questionsPath = dirname($configPath) . '/' . $quiz['questions_file'];
$questions = json_decode(file_get_contents($questionsPath), true);
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($quiz['title']) ?> – Elevaro</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="quiz-page">

<nav class="navbar navbar-expand-lg bg-white border-bottom">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/">Elevaro</a>
    <a href="/" class="btn btn-sm btn-outline-secondary">Zur Übersicht</a>
  </div>
</nav>

<main class="py-5">
  <div class="container">
    <div class="quiz-shell mx-auto">
      <div class="mb-4">
        <span class="badge text-bg-primary mb-2"><?= htmlspecialchars($quiz['subject']) ?> • Klasse <?= htmlspecialchars($quiz['grade']) ?></span>
        <h1 class="h2 fw-bold mb-2"><?= htmlspecialchars($quiz['title']) ?></h1>
        <p class="text-muted mb-0"><?= htmlspecialchars($quiz['description']) ?></p>
      </div>

      <div id="introCard" class="card border-0 shadow-sm">
        <div class="card-body p-4 p-md-5">
          <div class="panda-mini mb-3">🐼</div>
          <h2 class="h4 fw-bold">Los geht’s!</h2>
          <p class="text-muted">
            Beantworte die Fragen in Ruhe. Nach jeder Antwort bekommst du direkt Feedback.
            Am Ende siehst du dein Ergebnis und kannst deine Wackelkandidaten gezielt wiederholen.
          </p>
          <button id="startBtn" class="btn btn-primary btn-lg">Quiz starten</button>
        </div>
      </div>

      <div id="quizCard" class="card border-0 shadow-sm d-none">
        <div class="card-body p-4 p-md-5">
          <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="progress flex-grow-1 me-3" style="height: 10px;">
              <div id="progressBar" class="progress-bar" style="width: 0%"></div>
            </div>
            <span id="counter" class="text-muted small"></span>
          </div>

          <h2 id="question" class="h3 fw-bold mb-4"></h2>
          <div id="answers" class="d-grid gap-3"></div>
          <div id="feedback" class="feedback-box d-none mt-4"></div>
          <button id="nextBtn" class="btn btn-primary mt-4 d-none">Weiter</button>
        </div>
      </div>

      <div id="resultCard" class="card border-0 shadow-sm d-none">
        <div class="card-body p-4 p-md-5 text-center">
          <div class="panda-mini mb-3">🐼</div>
          <h2 class="fw-bold">Geschafft!</h2>
          <p id="resultText" class="lead text-muted"></p>
          <div class="d-flex justify-content-center gap-3 flex-wrap mt-4">
            <button id="restartBtn" class="btn btn-primary">Nochmal spielen</button>
            <button id="weakBtn" class="btn btn-outline-primary d-none">Wackelkandidaten üben</button>
            <a href="/" class="btn btn-light">Zur Übersicht</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
window.ELEVARO_QUIZ = {
  id: <?= json_encode($quiz['id']) ?>,
  questions: <?= json_encode($questions, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="assets/js/quiz.js"></script>
</body>
</html>
