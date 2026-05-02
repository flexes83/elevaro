<?php
require_once __DIR__ . '/app/includes/quiz_repository.php';

$quizKey = $_GET['key'] ?? 'mathe_klasse5_bruchrechnen';
$quiz = elevaro_get_quiz_payload($quizKey);

if (!$quiz) {
    http_response_code(404);
    echo 'Quiz nicht gefunden.';
    exit;
}

if (empty($quiz['questions'])) {
    http_response_code(404);
    echo 'Dieses Quiz enthält noch keine veröffentlichten Fragen.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($quiz['title']) ?> – Elevaro</title>
  <meta name="description" content="<?= htmlspecialchars($quiz['description'] ?? '') ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/quiz.css">

<link rel="stylesheet" href="assets/css/design-system.css">
</head>
<body class="quiz-page">

<nav class="navbar navbar-expand-lg bg-white border-bottom">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/">Elevaro</a>
    <div class="d-flex gap-2">
      <a href="recommendations.php" class="btn btn-sm btn-outline-primary">Empfehlungen</a>
      <a href="/" class="btn btn-sm btn-light">Startseite</a>
    </div>
  </div>
</nav>

<main class="quiz-wrap">
  <div class="container">
    <div class="quiz-shell mx-auto">

      <div class="quiz-header mb-4">
        <span class="quiz-eyebrow">Quiz</span>
        <h1 class="fw-bold mb-2"><?= htmlspecialchars($quiz['title']) ?></h1>
        <p class="text-muted mb-0"><?= htmlspecialchars($quiz['description'] ?? '') ?></p>
      </div>

      <div id="introCard" class="quiz-card">
        <div class="row align-items-center g-4">
          <div class="col-md-4 text-center">
            <div class="quiz-panda">🐼</div>
          </div>
          <div class="col-md-8">
            <h2 class="h3 fw-bold">Bereit?</h2>
            <p class="text-muted">
              Du startest mit den leichteren Fragen. Je besser Elevaro deine Antworten kennenlernt,
              desto smarter können später passende Fragen vorgeschlagen werden.
            </p>
            <button id="startBtn" class="btn btn-primary btn-lg">Quiz starten</button>
          </div>
        </div>
      </div>

      <div id="quizCard" class="quiz-card d-none">
        <div class="d-flex justify-content-between align-items-center mb-4">
          <div class="progress flex-grow-1 me-3" style="height: 10px;">
            <div id="progressBar" class="progress-bar" style="width: 0%"></div>
          </div>
          <span id="counter" class="text-muted small"></span>
        </div>

        <h2 id="question" class="h3 fw-bold mb-4"></h2>
        <div id="answers" class="answer-grid"></div>
        <div id="feedback" class="feedback-box d-none mt-4"></div>
        <button id="nextBtn" class="btn btn-primary mt-4 d-none">Weiter</button>
      </div>

      <div id="resultCard" class="quiz-card d-none text-center">
        <div id="resultPanda" class="quiz-panda small-panda mb-3">🐼</div>
        <span class="quiz-eyebrow">Ergebnis</span>
        <h2 id="resultHeadline" class="fw-bold mt-2">Geschafft!</h2>
        <p id="resultText" class="lead text-muted"></p>

        <div class="result-stats my-4">
          <div>
            <span id="statCorrect">0</span>
            <small>richtig</small>
          </div>
          <div>
            <span id="statTotal">0</span>
            <small>Fragen</small>
          </div>
          <div>
            <span id="statPercent">0%</span>
            <small>Score</small>
          </div>
        </div>

        <div id="weakBox" class="weak-box d-none text-start">
          <h3 class="h5 fw-bold">Diese Fragen wackeln noch:</h3>
          <ul id="weakList" class="mb-0"></ul>
        </div>

        <div class="d-flex justify-content-center gap-3 flex-wrap mt-4">
          <button id="restartBtn" class="btn btn-primary">Nochmal spielen</button>
          <button id="weakBtn" class="btn btn-outline-primary d-none">Wackelkandidaten üben</button>
          <a href="recommendations.php" class="btn btn-light">Weitere Quizze</a>
        </div>
      </div>

    </div>
  </div>
</main>

<script>
window.ELEVARO_QUIZ = {
  dbId: <?= (int)$quiz['id'] ?>,
  id: <?= json_encode($quiz['quiz_key'], JSON_UNESCAPED_UNICODE) ?>,
  title: <?= json_encode($quiz['title'], JSON_UNESCAPED_UNICODE) ?>,
  questions: <?= json_encode($quiz['questions'], JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="assets/js/quiz.js"></script>
</body>
</html>
