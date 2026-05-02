<?php
$quizPath = __DIR__ . '/data/quizzes/mathe/klasse-5/bruchrechnen.json';
$quiz = json_decode(file_get_contents($quizPath), true);
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Elevaro – Spielerisch zu guten Noten</title>
  <meta name="description" content="Elevaro hilft Schülern, Schulstoff spielerisch zu üben und besser zu verstehen.">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/">Elevaro</a>
    <div class="ms-auto d-flex gap-2">
      <a href="#quizze" class="btn btn-sm btn-outline-primary">Quizze entdecken</a>
      <a href="quiz.php?quiz=mathe/klasse-5/bruchrechnen" class="btn btn-sm btn-primary">Jetzt starten</a>
    </div>
  </div>
</nav>

<header class="hero-section">
  <div class="container">
    <div class="row align-items-center g-5">
      <div class="col-lg-7">
        <div class="badge rounded-pill text-bg-light mb-3">Schulstoff • Quiz • Motivation</div>
        <h1 class="display-4 fw-bold mb-3">Spielerisch zu guten Noten.</h1>
        <p class="lead mb-4">
          Elevaro macht aus trockenem Schulstoff kurze, motivierende Quizrunden.
          Übe gezielt nach Fach, Klasse und Thema – mit direktem Feedback und Fragen, die hängen bleiben.
        </p>
        <div class="d-flex flex-wrap gap-3">
          <a href="quiz.php?quiz=mathe/klasse-5/bruchrechnen" class="btn btn-primary btn-lg">Erstes Quiz spielen</a>
          <a href="#so-funktionierts" class="btn btn-light btn-lg">So funktioniert’s</a>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="panda-card">
          <div class="panda-face">🐼</div>
          <h2 class="h4 fw-bold">Bereit?</h2>
          <p>Starte mit Bruchrechnen und finde heraus, welche Fragen du schon sicher kannst – und welche du nochmal üben solltest.</p>
        </div>
      </div>
    </div>
  </div>
</header>

<main>
  <section id="so-funktionierts" class="py-5">
    <div class="container">
      <div class="text-center mb-5">
        <h2 class="fw-bold">Lernen, das sich nach Fortschritt anfühlt.</h2>
        <p class="text-muted">Kurze Einheiten, direktes Feedback und Wiederholung genau dort, wo sie hilft.</p>
      </div>
      <div class="row g-4">
        <div class="col-md-4">
          <div class="feature-card h-100">
            <div class="feature-icon">🎯</div>
            <h3 class="h5">Nah am Schulstoff</h3>
            <p>Quizze sind nach Fach, Klasse und Thema aufgebaut – damit du genau das übst, was gerade wichtig ist.</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="feature-card h-100">
            <div class="feature-icon">⚡</div>
            <h3 class="h5">Sofort verstehen</h3>
            <p>Nach jeder Antwort bekommst du direkt Rückmeldung. So merkst du sofort, was sitzt und was noch wackelt.</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="feature-card h-100">
            <div class="feature-icon">🔁</div>
            <h3 class="h5">Gezielt wiederholen</h3>
            <p>Falsche Antworten werden zu Wackelkandidaten – perfekt, um genau diese Fragen später erneut zu trainieren.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="quizze" class="py-5 bg-light">
    <div class="container">
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
          <h2 class="fw-bold mb-1">Starte dein erstes Quiz</h2>
          <p class="text-muted mb-0">Weitere Fächer und Klassen folgen Schritt für Schritt.</p>
        </div>
      </div>

      <div class="quiz-tile">
        <div>
          <span class="badge text-bg-primary mb-2">Mathe • Klasse 5</span>
          <h3 class="h4 fw-bold mb-2"><?= htmlspecialchars($quiz['title']) ?></h3>
          <p class="mb-0 text-muted"><?= htmlspecialchars($quiz['description']) ?></p>
        </div>
        <a href="quiz.php?quiz=mathe/klasse-5/bruchrechnen" class="btn btn-primary">Quiz starten</a>
      </div>
    </div>
  </section>

  <section class="py-5">
    <div class="container text-center">
      <h2 class="fw-bold">Für Schüler gemacht. Für Schule gedacht.</h2>
      <p class="lead text-muted mx-auto" style="max-width: 760px;">
        Elevaro verbindet spielerisches Üben mit echtem Lernnutzen.
        Ziel ist nicht Rätselraten, sondern besseres Verstehen – Thema für Thema.
      </p>
    </div>
  </section>
</main>

<footer class="py-4 border-top bg-white">
  <div class="container d-flex flex-wrap justify-content-between gap-2 text-muted small">
    <span>© <?= date('Y') ?> Elevaro</span>
    <span>Spielerisch zu guten Noten.</span>
  </div>
</footer>

</body>
</html>
