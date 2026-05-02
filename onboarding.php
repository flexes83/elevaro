<?php ?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Los geht’s – Elevaro</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/onboarding.css">
</head>
<body>

<div class="container py-5">
  <div class="onboarding-shell mx-auto">

    <div class="progress mb-4" style="height:10px;">
      <div id="stepProgress" class="progress-bar" style="width:10%"></div>
    </div>

    <div class="onboarding-card p-4">
      <div id="stepBadge" class="mb-2"></div>
      <h1 id="stepTitle" class="h3 fw-bold mb-2"></h1>
      <p id="stepText" class="text-muted mb-4"></p>

      <div id="choices"></div>

      <div class="d-flex justify-content-between mt-4">
        <button id="backBtn" class="btn btn-light">Zurück</button>
        <button id="skipBtn" class="btn btn-outline-secondary">Überspringen</button>
      </div>
    </div>

  </div>
</div>

<script src="assets/js/onboarding.js"></script>
</body>
</html>
