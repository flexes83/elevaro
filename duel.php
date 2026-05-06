<?php
require_once __DIR__ . '/app/includes/classroom.php';

$classId = (int)($_GET['class_id'] ?? 0);
$duelId = (int)($_GET['duel_id'] ?? 0);
$class = $classId ? classroom_by_id($classId) : null;
$participant = $class ? classroom_current_participant($classId) : null;

if (!$class) {
    http_response_code(404);
    echo 'Klassenraum nicht gefunden.';
    exit;
}
if (!$participant) {
    header('Location: /join.php?code=' . urlencode((string)$class['invite_code']));
    exit;
}

$duel = $duelId ? classroom_duel_by_id($classId, $duelId) : null;
if (!$duel || $duel['status'] !== 'accepted' || !classroom_participant_in_duel($duel, (int)$participant['id'])) {
    header('Location: /classroom.php?class_id=' . $classId);
    exit;
}

$questions = classroom_duel_questions_payload($duel);
if (!$questions) {
    header('Location: /classroom.php?class_id=' . $classId);
    exit;
}

$isChallenger = (int)$duel['challenger_participant_id'] === (int)$participant['id'];
$opponent = [
    'id' => $isChallenger ? (int)$duel['challenged_participant_id'] : (int)$duel['challenger_participant_id'],
    'name' => $isChallenger ? (string)$duel['challenged_name'] : (string)$duel['challenger_name'],
    'avatar' => $isChallenger ? (string)$duel['challenged_avatar'] : (string)$duel['challenger_avatar'],
    'avatar_type' => $isChallenger ? (string)$duel['challenged_avatar_type'] : (string)$duel['challenger_avatar_type'],
    'avatar_gradient' => $isChallenger ? (string)$duel['challenged_avatar_gradient'] : (string)$duel['challenger_avatar_gradient'],
];

function dh($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$classroomUrl = '/classroom.php?class_id=' . (int)$class['id'];
$startedAt = $duel['started_at'] ?: date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Quizduell – Elevaro</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/design-system.css">
  <link rel="stylesheet" href="assets/css/classroom.css?v=<?= filemtime(__DIR__ . '/assets/css/classroom.css') ?>">
  <link rel="stylesheet" href="assets/css/duel.css?v=<?= filemtime(__DIR__ . '/assets/css/duel.css') ?>">
</head>
<body class="duel-page quiz-classroom-mode">
<nav class="classroom-topbar classroom-quiz-topbar">
  <a class="brand" href="<?= dh($classroomUrl) ?>" aria-label="Zurück in den Klassenraum">Elevaro</a>
  <a class="class-pill" href="<?= dh($classroomUrl) ?>">🏫 <?= dh(classroom_label($class)) ?></a>
  <a class="classroom-back" href="<?= dh($classroomUrl) ?>">← Klassenraum</a>
  <div class="me-pill"><span class="avatar-bubble <?= dh($participant['avatar_type'] ?? 'emoji') ?> <?= dh($participant['avatar_gradient'] ?? 'grad-1') ?>"><?= dh($participant['avatar_emoji'] ?? '🙂') ?></span><?= dh($participant['display_name']) ?></div>
</nav>

<main class="duel-wrap">
  <section id="duelIntro" class="duel-card duel-intro">
    <span class="duel-eyebrow">Quizduell</span>
    <h1>Ihr tretet gegeneinander an</h1>
    <div class="duel-versus">
      <div><span class="avatar-bubble <?= dh($participant['avatar_type'] ?? 'emoji') ?> <?= dh($participant['avatar_gradient'] ?? 'grad-1') ?> big"><?= dh($participant['avatar_emoji'] ?? '🙂') ?></span><strong>Du</strong></div>
      <b>VS</b>
      <div><span class="avatar-bubble <?= dh($opponent['avatar_type']) ?> <?= dh($opponent['avatar_gradient']) ?> big"><?= dh($opponent['avatar']) ?></span><strong><?= dh($opponent['name']) ?></strong></div>
    </div>
    <p>Es kommen <?= count($questions) ?> kurze Fragen. Pro Frage habt ihr 10 Sekunden. Wenn beide geantwortet haben, geht es direkt weiter.</p>
    <button id="duelStartBtn" class="btn btn-primary btn-lg">Bereit</button>
  </section>

  <section id="duelGame" class="duel-card d-none">
    <div class="duel-hud">
      <div><strong>Du</strong><span id="myScore">0</span></div>
      <div class="duel-timer"><span id="duelTimer">10</span></div>
      <div><strong><?= dh($opponent['name']) ?></strong><span id="otherScore">0</span></div>
    </div>
    <div class="duel-progress"><span id="duelProgressBar"></span></div>
    <small id="duelCounter" class="duel-counter"></small>
    <h2 id="duelQuestion"></h2>
    <div id="duelAnswers" class="duel-answers"></div>
    <div id="duelFeedback" class="duel-feedback d-none"></div>
  </section>

  <section id="duelResult" class="duel-card duel-result d-none">
    <div id="duelResultIcon" class="duel-result-icon">🏆</div>
    <h1 id="duelResultHeadline">Duell beendet</h1>
    <p id="duelResultText"></p>
    <div class="duel-final-score"><span>Du: <b id="finalMyScore">0</b></span><span><?= dh($opponent['name']) ?>: <b id="finalOtherScore">0</b></span></div>
    <div class="duel-result-actions">
      <button id="rematchBtn" class="btn btn-primary">Revanche fordern</button>
      <a class="btn btn-light" href="<?= dh($classroomUrl) ?>">Zurück in den Klassenraum</a>
    </div>
  </section>
</main>

<script>
window.ELEVARO_DUEL = {
  classId: <?= (int)$class['id'] ?>,
  duelId: <?= (int)$duel['id'] ?>,
  meId: <?= (int)$participant['id'] ?>,
  opponentId: <?= (int)$opponent['id'] ?>,
  opponentName: <?= json_encode($opponent['name'], JSON_UNESCAPED_UNICODE) ?>,
  opponentAvatar: <?= json_encode(['type'=>$opponent['avatar_type'], 'value'=>$opponent['avatar'], 'gradient'=>$opponent['avatar_gradient']], JSON_UNESCAPED_UNICODE) ?>,
  startedAt: <?= json_encode($startedAt, JSON_UNESCAPED_UNICODE) ?>,
  questions: <?= json_encode($questions, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="assets/js/duel.js?v=<?= filemtime(__DIR__ . '/assets/js/duel.js') ?>"></script>
</body>
</html>
