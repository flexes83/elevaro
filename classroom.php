<?php
require_once __DIR__ . '/app/includes/classroom.php';

$classId = (int)($_GET['class_id'] ?? 0);
$class = $classId ? classroom_by_id($classId) : null;
if (!$class) { http_response_code(404); echo 'Klassenraum nicht gefunden.'; exit; }
$participant = classroom_current_participant((int)$class['id']);
if (!$participant) {
    header('Location: /join.php?code=' . urlencode((string)$class['invite_code']));
    exit;
}
classroom_touch((int)$participant['id']);
$quizzes = classroom_assigned_quizzes((int)$class['id']);
$online = classroom_online_participants((int)$class['id']);
$activities = classroom_recent_activities((int)$class['id']);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= classroom_h(classroom_label($class)) ?> – Elevaro Klassenraum</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/classroom.css?v=<?= filemtime(__DIR__ . '/assets/css/classroom.css') ?>">
</head>
<body class="classroom-page" data-class-id="<?= (int)$class['id'] ?>">
<nav class="classroom-topbar">
  <a class="brand" href="/">Elevaro</a>
  <div class="class-pill">🏫 <?= classroom_h(classroom_label($class)) ?></div>
  <div class="me-pill"><span><?= classroom_h($participant['avatar_emoji']) ?></span><?= classroom_h($participant['display_name']) ?></div>
</nav>
<main class="classroom-layout">
  <section class="classroom-hero">
    <div>
      <span class="eyebrow">Klassenraum ist geöffnet</span>
      <h1>Hallo <?= classroom_h(explode(' ', trim((string)$participant['display_name']))[0] ?: $participant['display_name']) ?> 👋</h1>
      <p>Hier findest du die Quizzes, Livequizzes und Materialien deiner Lehrkraft. Rechts siehst du, was gerade im Raum passiert.</p>
    </div>
    <div class="room-pulse-card">
      <div class="pulse-dot"></div>
      <strong><?= count($online) ?></strong>
      <span>gerade aktiv</span>
    </div>
  </section>

  <div class="classroom-grid">
    <section class="room-main-card">
      <div class="section-head">
        <div><span class="eyebrow">Bereitgestellt</span><h2>Quizzes & Aufgaben</h2></div>
      </div>
      <div class="quiz-room-list">
        <?php foreach ($quizzes as $quiz): ?>
          <article class="room-quiz-card">
            <div class="quiz-emoji"><?= classroom_h($quiz['theme_emoji'] ?? '🎯') ?></div>
            <div>
              <h3><?= classroom_h($quiz['title']) ?></h3>
              <p><?= classroom_h($quiz['description'] ?? 'Kurzes Quiz zum Üben und Wiederholen.') ?></p>
              <div class="quiz-meta"><span><?= (int)($quiz['grade'] ?? 0) ? classroom_h($quiz['grade'] . '. Klasse') : 'Klassenquiz' ?></span><span>Premium freigeschaltet</span></div>
            </div>
            <a class="btn btn-primary" href="/quiz.php?key=<?= urlencode((string)$quiz['quiz_key']) ?>&class_id=<?= (int)$class['id'] ?>">Starten</a>
          </article>
        <?php endforeach; ?>
        <?php if (!$quizzes): ?>
          <div class="empty-room-state"><strong>Noch keine Quizzes bereitgestellt.</strong><span>Deine Lehrkraft kann im Lehrerbereich Quizzes hinzufügen.</span></div>
        <?php endif; ?>
      </div>
    </section>

    <aside class="room-sidebar">
      <section class="side-card">
        <div class="section-head compact"><h2>Gerade online</h2></div>
        <div id="onlineList" class="online-list">
          <?php foreach ($online as $p): ?>
            <button class="online-person" type="button" data-participant-id="<?= (int)$p['id'] ?>" <?= (int)$p['id']===(int)$participant['id']?'disabled':'' ?>>
              <span class="avatar"><?= classroom_h($p['avatar_emoji']) ?></span><span><?= classroom_h($p['display_name']) ?></span>
              <?php if ((int)$p['id'] !== (int)$participant['id']): ?><small>Duell</small><?php else: ?><small>du</small><?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="side-card">
        <div class="section-head compact"><h2>Was passiert?</h2></div>
        <div id="activityFeed" class="activity-feed">
          <?php foreach ($activities as $a): ?>
            <div class="activity-item"><span><?= classroom_h($a['avatar_emoji'] ?? '✨') ?></span><div><strong><?= classroom_h($a['title']) ?></strong><small><?= classroom_h(date('H:i', strtotime((string)$a['created_at']))) ?></small></div></div>
          <?php endforeach; ?>
        </div>
      </section>
    </aside>
  </div>
</main>
<script>
window.ELEVARO_CLASSROOM = {classId: <?= (int)$class['id'] ?>, participantId: <?= (int)$participant['id'] ?>};
</script>
<script src="/assets/js/classroom.js?v=<?= filemtime(__DIR__ . '/assets/js/classroom.js') ?>"></script>
</body>
</html>
