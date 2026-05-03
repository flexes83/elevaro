<?php
require_once __DIR__ . '/app/includes/frontend_header.php'; ?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Deine Empfehlungen – Elevaro</title>
  <meta name="description" content="Passende Quiz-Empfehlungen auf Basis deiner Klasse, Schulart und deines Bundeslands.">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/recommendations.css">
  <link rel="stylesheet" href="/assets/css/frontend-header.css">
</head>
<body>

<?php elevaro_frontend_header('light', ['show_change_selection' => true]); ?>

<main class="recommendations-wrap">
  <div class="container">
    <div class="recommendations-shell mx-auto">

      <section class="hero-card mb-4">
        <div class="row g-4 align-items-center">
          <div class="col-lg-8">
            <span class="eyebrow">Dein Lernweg</span>
            <h1 class="fw-bold mb-2">Das passt zu dir.</h1>
            <p class="lead text-muted mb-0">
              Wir haben deine Auswahl gespeichert und schlagen dir Quizze vor,
              die zu Klasse, Schulart und Bundesland passen.
            </p>
          </div>
          <div class="col-lg-4 text-center">
            <div class="panda-success">🐼</div>
          </div>
        </div>
      </section>

      <section class="profile-summary mb-4">
        <div class="summary-item">
          <span>Bundesland</span>
          <strong id="summaryState">–</strong>
        </div>
        <div class="summary-item">
          <span>Schulart</span>
          <strong id="summarySchoolType">–</strong>
        </div>
        <div class="summary-item">
          <span>Klasse</span>
          <strong id="summaryGrade">–</strong>
        </div>
        <div class="summary-item">
          <span>Fach</span>
          <strong id="summarySubject">–</strong>
        </div>
      </section>

      <section class="mb-4">
        <div class="d-flex justify-content-between align-items-end gap-3 flex-wrap mb-3">
          <div>
            <h2 class="h3 fw-bold mb-1">Empfohlene Quizze</h2>
            <p class="text-muted mb-0">Starte mit einem Thema, das wirklich zu deinem Lernstand passt.</p>
          </div>
          <a href="onboarding.php" class="btn btn-light">Neu auswählen</a>
        </div>

        <div id="recommendationsLoading" class="loading-card">
          Empfehlungen werden geladen …
        </div>

        <div id="recommendationsEmpty" class="empty-card d-none">
          <div class="empty-icon">🧭</div>
          <h3 class="h5 fw-bold">Noch keine exakte Empfehlung gefunden.</h3>
          <p class="text-muted mb-3">
            Für diese Kombination bauen wir gerade passende Inhalte auf.
            Starte solange mit unserem ersten Mathe-Quiz.
          </p>
          <a href="quiz.php?quiz=mathe/klasse-5/bruchrechnen" class="btn btn-primary">Beispielquiz starten</a>
        </div>

        <div id="recommendationsList" class="recommendation-grid"></div>
      </section>

    </div>
  </div>
</main>

<script src="assets/js/recommendations.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
