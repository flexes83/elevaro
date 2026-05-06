<?php
require_once __DIR__ . '/app/includes/classroom.php';

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
$classId = (int)($_GET['class_id'] ?? 0);
$class = $code !== '' ? classroom_by_code($code) : ($classId ? classroom_by_id($classId) : null);
if (!$class) { http_response_code(404); echo 'Klassenraum nicht gefunden.'; exit; }
$participant = classroom_current_participant((int)$class['id']);
if (!$participant) {
    // QR-Code und geteilter Link dürfen direkt auf classroom.php zeigen.
    // Wer noch keine Klassenraum-Session hat, landet im niedrigschwelligen Namens-Join.
    header('Location: /join.php?code=' . urlencode((string)$class['invite_code']));
    exit;
}
classroom_touch((int)$participant['id']);
$quizzes = classroom_assigned_quizzes((int)$class['id']);
$online = classroom_online_participants((int)$class['id']);
$activities = classroom_recent_activities((int)$class['id']);
$duels = classroom_duels_for_participant((int)$class['id'], (int)$participant['id']);
$avatarOptions = classroom_avatar_options();
$gradientOptions = classroom_gradient_options();
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
  <button class="me-pill avatar-settings-toggle" type="button" aria-haspopup="dialog"><span class="avatar-bubble <?= classroom_h($participant['avatar_type'] ?? 'emoji') ?> <?= classroom_h($participant['avatar_gradient'] ?? 'grad-1') ?>"><?= classroom_h($participant['avatar_emoji']) ?></span><?= classroom_h($participant['display_name']) ?><small>ändern</small></button>
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
      <strong id="onlineCount"><?= count($online) ?></strong>
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
              <span class="avatar avatar-bubble <?= classroom_h($p['avatar_type'] ?? 'emoji') ?> <?= classroom_h($p['avatar_gradient'] ?? 'grad-1') ?>"><?= classroom_h($p['avatar_emoji']) ?></span><span><?= classroom_h($p['display_name']) ?></span>
              <?php if ((int)$p['id'] !== (int)$participant['id']): ?><small>Duell</small><?php else: ?><small>du</small><?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>
      </section>
      
      <section class="side-card" id="duelCard">
        <div class="section-head compact"><h2>Quizduelle</h2></div>
        <div id="duelList" class="duel-list">
          <?php foreach ($duels as $duel): ?>
            <?php $isChallenged = (int)$duel['challenged_participant_id'] === (int)$participant['id']; $url = classroom_duel_url($duel); ?>
            <div class="duel-item">
              <strong><?= classroom_h($isChallenged ? ($duel['challenger_name'] . ' fordert dich heraus') : ('Duell mit ' . $duel['challenged_name'])) ?></strong>
              <small><?= classroom_h($duel['quiz_title'] ?: 'Erstes Klassenquiz') ?></small>
              <?php if ($duel['status'] === 'pending' && $isChallenged): ?>
                <div class="duel-actions"><button class="btn btn-sm btn-primary" data-duel-action="accept" data-duel-id="<?= (int)$duel['id'] ?>">Annehmen</button><button class="btn btn-sm btn-light" data-duel-action="decline" data-duel-id="<?= (int)$duel['id'] ?>">Ablehnen</button></div>
              <?php elseif ($duel['status'] === 'accepted' && $url): ?>
                <a class="btn btn-sm btn-primary" href="<?= classroom_h($url) ?>">Duell starten</a>
              <?php else: ?>
                <span class="duel-waiting">wartet …</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="side-card">
        <div class="section-head compact"><h2>Was passiert?</h2></div>
        <div id="activityFeed" class="activity-feed">
          <?php foreach ($activities as $a): ?>
            <div class="activity-item"><span class="avatar-bubble <?= classroom_h($a['avatar_type'] ?? 'emoji') ?> <?= classroom_h($a['avatar_gradient'] ?? 'grad-1') ?>"><?= classroom_h($a['avatar_emoji'] ?? '✨') ?></span><div><strong><?= classroom_h($a['title']) ?></strong><small><?= classroom_h(date('H:i', strtotime((string)$a['created_at']))) ?></small></div></div>
          <?php endforeach; ?>
        </div>
      </section>
    </aside>
  </div>
</main>

<div class="avatar-modal-backdrop" id="avatarModal" hidden>
  <div class="avatar-modal" role="dialog" aria-modal="true" aria-labelledby="avatarModalTitle">
    <button class="avatar-modal-close" type="button" data-avatar-close aria-label="Schließen">×</button>
    <span class="eyebrow">Dein Zeichen</span>
    <h2 id="avatarModalTitle">Avatar wählen</h2>
    <p>Wähle ein eindeutiges Emoji oder nutze deine Initialen. Bereits vergebene Zeichen können nicht doppelt gewählt werden.</p>
    <div class="avatar-tabs" role="tablist">
      <button class="active" type="button" data-avatar-tab="emoji">Emoji</button>
      <button type="button" data-avatar-tab="initials">Initialen</button>
    </div>
    <div class="avatar-panel active" data-avatar-panel="emoji">
      <div class="avatar-choice-grid">
        <?php foreach ($avatarOptions as $emoji): ?>
          <button type="button" class="avatar-choice" data-avatar-type="emoji" data-avatar-value="<?= classroom_h($emoji) ?>"><?= classroom_h($emoji) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="avatar-panel" data-avatar-panel="initials">
      <label class="form-label">Initialen</label>
      <input class="form-control form-control-lg text-uppercase" id="initialsInput" maxlength="3" value="<?= classroom_h(classroom_initials((string)$participant['display_name'])) ?>">
      <div class="gradient-grid">
        <?php foreach ($gradientOptions as $gradient): ?>
          <button type="button" class="gradient-choice <?= classroom_h($gradient) ?>" data-gradient="<?= classroom_h($gradient) ?>"><span><?= classroom_h(classroom_initials((string)$participant['display_name'])) ?></span></button>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary w-100 mt-3" type="button" id="saveInitialsAvatar">Initialen übernehmen</button>
    </div>
    <div class="avatar-error" id="avatarError" hidden></div>
  </div>
</div>

<script>
window.ELEVARO_CLASSROOM = {classId: <?= (int)$class['id'] ?>, participantId: <?= (int)$participant['id'] ?>};
</script>
<script src="/assets/js/classroom.js?v=<?= filemtime(__DIR__ . '/assets/js/classroom.js') ?>"></script>
</body>
</html>
