<?php ?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Los geht’s – Elevaro</title>
  <meta name="description" content="Finde Quizze, die zu deinem Bundesland, deiner Schulart und deiner Klasse passen.">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/onboarding.css">

<link rel="stylesheet" href="assets/css/design-system.css">
</head>
<body>

<nav class="navbar bg-white border-bottom">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/">Elevaro</a>
    <span class="text-muted small">Spielerisch zu guten Noten.</span>
  </div>
</nav>

<main class="onboarding-wrap">
  <div class="container">
    <div class="onboarding-shell mx-auto">

      <div class="progress mb-4" style="height: 10px;">
        <div id="stepProgress" class="progress-bar" style="width: 0%"></div>
      </div>

      <div class="onboarding-card">
        <div class="row g-4 align-items-center">
          <div class="col-lg-4 text-center">
            <div id="stepIllustration" class="step-illustration">🐼</div>
            <div id="pandaHint" class="panda-hint">Ich suche dir passende Quizze raus.</div>
          </div>

          <div class="col-lg-8">
            <div id="stepBadge" class="step-badge mb-2">Schritt 1</div>
            <h1 id="stepTitle" class="fw-bold mb-2">Wie dürfen wir dich nennen?</h1>
            <p id="stepText" class="text-muted mb-4">
              Dein Vorname reicht völlig. Du kannst den Schritt auch überspringen.
            </p>

            <div id="choices" class="choice-grid"></div>

            <div id="emptyState" class="alert alert-light border d-none mt-3">
              Für diese Auswahl haben wir noch keine passenden Inhalte. Du kannst trotzdem weitergehen – wir erweitern Elevaro Schritt für Schritt.
            </div>

            <div class="d-flex justify-content-between gap-3 mt-4">
              <button id="backBtn" class="btn btn-light" disabled>Zurück</button>
              <button id="skipBtn" class="btn btn-outline-secondary">Überspringen</button>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</main>

<script src="assets/js/onboarding.js"></script>
</body>
</html>
