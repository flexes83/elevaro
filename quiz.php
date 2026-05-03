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

function qh($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$tags = array_filter(array_map('trim', explode(',', (string)($quiz['tag_names'] ?? ''))));

$imagePath = trim((string)($quiz['image_path'] ?? ''));
$imageStatus = strtolower((string)($quiz['image_status'] ?? ''));
$visibleStatuses = ['', 'draft', 'approved', 'generated', 'selected', 'active'];
$hasImage = $imagePath !== '' && in_array($imageStatus, $visibleStatuses, true);

$progress = $quiz['progress'] ?? [
    'total' => count($quiz['questions']),
    'passed' => 0,
    'failed' => 0,
    'unanswered' => count($quiz['questions']),
    'attempted' => 0,
    'played' => false,
];

$total = max((int)($progress['total'] ?? count($quiz['questions'])), 0);
$passed = (int)($progress['passed'] ?? 0);
$failed = (int)($progress['failed'] ?? 0);
$greenDeg = $total > 0 ? ($passed / $total) * 360 : 0;
$redDeg = $total > 0 ? ($failed / $total) * 360 : 0;
$played = !empty($progress['played']);
$percent = $total > 0 ? round(($passed / $total) * 100) : 0;

$subjectLabel = $quiz['subject_name'] ?? '';
$schoolLabel = $quiz['school_type_name'] ?? '';
$gradeLabel = !empty($quiz['grade']) ? ((int)$quiz['grade'] . '. Klasse') : '';
$stateLabel = $quiz['state_name'] ?? '';
$learningGoal = $quiz['learning_goal'] ?: ($quiz['topic_description'] ?? '');
$introEmoji = $quiz['theme_emoji'] ?? $quiz['subject_icon'] ?? '🎯';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= qh($quiz['title']) ?> – Elevaro</title>
  <meta name="description" content="<?= qh($quiz['description'] ?? '') ?>">
  <link rel="preconnect" href="https://cdn.jsdelivr.net">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/quiz.css?v=<?= filemtime(__DIR__ . '/assets/css/quiz.css') ?>">
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
        <h1 class="fw-bold mb-2"><?= qh($quiz['title']) ?></h1>
        <p class="text-muted mb-0"><?= qh($quiz['description'] ?? '') ?></p>
      </div>

      <div id="introCard" class="quiz-intro-card">
        <div class="quiz-intro-media <?= $hasImage ? 'has-image' : '' ?>">
          <?php if ($hasImage): ?>
            <img src="<?= qh($imagePath) ?>" alt="">
          <?php else: ?>
            <span><?= qh($introEmoji) ?></span>
          <?php endif; ?>

          <div class="quiz-intro-donut <?= $played ? '' : 'is-empty' ?>"
               style="--quiz-green: <?= qh((string)$greenDeg) ?>deg; --quiz-red: <?= qh((string)$redDeg) ?>deg;"
               title="<?= $played ? qh($passed . ' bestanden, ' . $failed . ' nachzuarbeiten') : 'Noch nicht gespielt' ?>">
            <span><?= $played ? qh($percent . '%') : '' ?></span>
          </div>
        </div>

        <div class="quiz-intro-content">
          <div class="quiz-meta-row">
            <?php if ($subjectLabel): ?><span><?= qh($subjectLabel) ?></span><?php endif; ?>
            <?php if ($gradeLabel): ?><span><?= qh($gradeLabel) ?></span><?php endif; ?>
            <?php if ($schoolLabel): ?><span><?= qh($schoolLabel) ?></span><?php endif; ?>
            <?php if ($stateLabel): ?><span><?= qh($stateLabel) ?></span><?php endif; ?>
          </div>

          <h2>Bereit?</h2>

          <?php if ($learningGoal): ?>
            <p class="quiz-learning-goal"><strong>Lernziel:</strong> <?= qh($learningGoal) ?></p>
          <?php endif; ?>

          <p>
            Du startest mit leichteren Fragen. Je besser Elevaro deine Antworten kennenlernt,
            desto smarter können später passende Fragen vorgeschlagen werden.
          </p>

          <?php if ($tags): ?>
            <div class="quiz-tags">
              <?php foreach (array_slice($tags, 0, 6) as $tag): ?>
                <span><?= qh($tag) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="quiz-intro-actions">
            <button id="startBtn" class="btn btn-primary btn-lg">Quiz starten</button>
            <span class="quiz-progress-text">
              <?= $played ? qh($passed . ' von ' . $total . ' bestanden') : qh($total . ' Fragen') ?>
            </span>
          </div>
        </div>
      </div>

      <div id="introExtras" class="quiz-highscore-placeholder">
        <div>
          <strong>Highscores</strong>
          <span>Kommt später: Klassenranglisten, Bestzeiten und Serien.</span>
        </div>
      </div>

      <div id="quizCard" class="quiz-card d-none">
        <div id="quizPlayMedia" class="quiz-play-media <?= $hasImage ? 'has-image' : '' ?>">
          <?php if ($hasImage): ?>
            <img src="<?= qh($imagePath) ?>" alt="">
          <?php else: ?>
            <span><?= qh($introEmoji) ?></span>
          <?php endif; ?>
        </div>

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
        <div id="resultPanda" class="quiz-panda small-panda mb-3">🏆</div>
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
  questions: <?= json_encode($quiz['questions'], JSON_UNESCAPED_UNICODE) ?>,
  imagePath: <?= json_encode($imagePath, JSON_UNESCAPED_UNICODE) ?>,
  hasImage: <?= $hasImage ? 'true' : 'false' ?>,
  emoji: <?= json_encode($introEmoji, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="assets/js/quiz.js?v=<?= filemtime(__DIR__ . '/assets/js/quiz.js') ?>"></script>
</body>
</html>
