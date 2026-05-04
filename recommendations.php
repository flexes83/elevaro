<?php
require_once __DIR__ . '/app/includes/frontend_header.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Deine Quiz-Empfehlungen – Elevaro</title>
  <meta name="description" content="Quiz-Empfehlungen passend zu deiner Auswahl.">
  <link rel="preconnect" href="https://cdn.jsdelivr.net">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/frontend-header.css">
  <link rel="stylesheet" href="/assets/css/recommendations.css">
  <link rel="stylesheet" href="/assets/css/quiz-card.css">
</head>
<body>

<?php elevaro_frontend_header('light', ['show_change_selection' => true]); ?>

<main class="recommendations-wrap">
  <div class="container">

    <section class="recommendations-hero">
      <div>
        <span class="eyebrow">Dein Lernweg</span>
        <h1>Das passt zu dir.</h1>
        <p id="heroText">
          Wir schlagen dir Quizze vor, die zu deiner Auswahl passen.
        </p>
      </div>

      <div class="hero-orb">
        <span id="heroIcon">🎯</span>
      </div>
    </section>

    <section class="profile-strip" aria-label="Deine Auswahl">
      <div>
        <span>Bundesland</span>
        <strong id="stateLabel">–</strong>
      </div>
      <div>
        <span>Schulart</span>
        <strong id="schoolTypeLabel">–</strong>
      </div>
      <div>
        <span>Klasse/Stufe</span>
        <strong id="gradeLabel">–</strong>
      </div>
      <div>
        <span>Fach</span>
        <strong id="subjectLabel">–</strong>
      </div>
    </section>

    <section class="recommendations-section">
      <div class="section-title-row">
        <div>
          <span id="topicKicker" class="eyebrow d-none">Lernbereich</span>
          <h2>Empfohlene Quizze</h2>
          <p id="sectionIntro">Starte mit einem Thema, das wirklich zu deinem Lernstand passt.</p>
        </div>

        <a href="/onboarding.php?edit=1" class="change-link">Neu auswählen</a>
      </div>

      <div id="loadingState" class="state-card">
        <div class="state-icon">✨</div>
        <h3>Wir suchen passende Quizze …</h3>
        <p>Das dauert nur einen Moment.</p>
      </div>

      <div id="recommendationsGrid" class="recommendations-grid elevaro-quiz-grid d-none"></div>

      <div id="emptyState" class="state-card d-none">
        <div class="state-icon">🧭</div>
        <h3>Noch keine exakte Empfehlung gefunden.</h3>
        <p>Für diese Kombination bauen wir gerade passende Inhalte auf. Starte solange mit unserem ersten Beispielquiz.</p>
        <a href="/quiz.php?key=mathe_klasse5_bruchrechnen" class="btn btn-primary">Beispielquiz starten</a>
      </div>
    </section>

  </div>
</main>

<script src="/assets/js/quiz-card.js"></script>
<script src="/assets/js/recommendations.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
