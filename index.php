<?php
// Elevaro homepage
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Elevaro – Spielerisch zu guten Noten</title>
  <meta name="description" content="Elevaro findet passende Schulquizze nach Bundesland, Schulart, Klasse und Fach. Spielerisch lernen, besser verstehen, gute Noten vorbereiten.">
  <link rel="preconnect" href="https://cdn.jsdelivr.net">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/home.css">
</head>
<body>

<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/">Elevaro</a>

    <div class="ms-auto d-flex align-items-center gap-2">
      <a href="#beispiele" class="btn btn-sm btn-outline-primary d-none d-md-inline-flex">Quiz testen</a>
      <a href="onboarding.php" class="btn btn-sm btn-primary">Los geht’s</a>
    </div>
  </div>
</nav>

<header class="home-hero">
  <div class="container">
    <div id="returningBox" class="returning-box d-none mb-4">
      <div>
        <span class="small-label">Willkommen zurück</span>
        <strong id="returningTitle">Weiterlernen?</strong>
        <p id="returningText" class="mb-0 text-muted">Wir haben deine letzte Auswahl gespeichert.</p>
      </div>
      <a href="recommendations.php" class="btn btn-primary">Weiterlernen</a>
    </div>

    <div class="row align-items-center g-5">
      <div class="col-lg-7">
        <span class="hero-badge">Schulstoff · Quiz · Motivation</span>
        <h1 class="display-3 fw-black mt-3 mb-3">Spielerisch zu guten Noten.</h1>
        <p class="lead mb-4">
          Elevaro findet Quizze, die zu deinem Bundesland, deiner Schulart und deiner Klasse passen.
          So übst du nicht irgendwas – sondern genau den Stoff, der gerade wichtig ist.
        </p>

        <div class="d-flex flex-wrap gap-3 mb-4">
          <a href="onboarding.php" class="btn btn-primary btn-lg">
            Los geht’s
          </a>
          <a href="#so-funktionierts" class="btn btn-light btn-lg">
            Wie funktioniert das?
          </a>
        </div>

        <div class="trust-row">
          <span>Ohne Anmeldung starten</span>
          <span>Passend zum Schulstoff</span>
          <span>Direktes Feedback</span>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="hero-card">
          <div class="hero-panda">🐼</div>
          <h2 class="h4 fw-bold mb-2">Ich suche dir passende Quizze raus.</h2>
          <p class="text-muted mb-4">
            Beantworte ein paar kurze Fragen und starte direkt mit einem Quiz, das zu deinem Lernstand passt.
          </p>
          <div class="mini-flow">
            <div><strong>1</strong><span>Auswählen</span></div>
            <div><strong>2</strong><span>Quizzen</span></div>
            <div><strong>3</strong><span>Besser werden</span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</header>

<main>

  <section id="so-funktionierts" class="section-padding">
    <div class="container">
      <div class="section-head text-center mb-5">
        <span class="small-label">So funktioniert’s</span>
        <h2 class="fw-bold">Dein Lernweg in drei Schritten.</h2>
        <p class="text-muted mb-0">
          Statt langer Suche führt dich Elevaro direkt zu passenden Themen und Quizzen.
        </p>
      </div>

      <div class="row g-4">
        <div class="col-md-4">
          <article class="step-card h-100">
            <div class="step-icon">🗺️</div>
            <h3 class="h5 fw-bold">1. Kurz einordnen</h3>
            <p>
              Bundesland, Schulart, Klasse und Fach – daraus entsteht dein persönlicher Einstieg.
            </p>
          </article>
        </div>

        <div class="col-md-4">
          <article class="step-card h-100">
            <div class="step-icon">🎯</div>
            <h3 class="h5 fw-bold">2. Passendes Thema finden</h3>
            <p>
              Elevaro zeigt dir Themen, die wirklich zu deinem Schulstoff passen.
            </p>
          </article>
        </div>

        <div class="col-md-4">
          <article class="step-card h-100">
            <div class="step-icon">⚡</div>
            <h3 class="h5 fw-bold">3. Direkt üben</h3>
            <p>
              Quiz spielen, Feedback bekommen und Wackelkandidaten gezielt wiederholen.
            </p>
          </article>
        </div>
      </div>
    </div>
  </section>

  <section id="beispiele" class="section-padding bg-soft">
    <div class="container">
      <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
        <div>
          <span class="small-label">Direkt ausprobieren</span>
          <h2 class="fw-bold mb-1">Beliebte Einstiegs-Quizze</h2>
          <p class="text-muted mb-0">
            Noch kein Profil? Dann teste Elevaro mit einem Beispiel aus dem Schulstoff.
          </p>
        </div>
        <a href="onboarding.php" class="btn btn-outline-primary">Passende Quizze finden</a>
      </div>

      <div class="quiz-grid">
        <article class="quiz-card featured">
          <span class="quiz-badge">Mathe · Klasse 5</span>
          <h3>Bruchrechnen – Grundlagen</h3>
          <p>
            Übe einfache Brüche, Anteile, Zähler und Nenner – mit direktem Feedback nach jeder Frage.
          </p>
          <div class="quiz-actions">
            <a href="quiz.php?quiz=mathe/klasse-5/bruchrechnen" class="btn btn-primary">Quiz starten</a>
            <span class="quiz-meta">5 Fragen · ca. 3 Minuten</span>
          </div>
        </article>

        <article class="quiz-card disabled-card">
          <span class="quiz-badge">Deutsch · Klasse 5</span>
          <h3>Wortarten erkennen</h3>
          <p>
            Nomen, Verben und Adjektive sicher unterscheiden. Dieses Quiz wird gerade vorbereitet.
          </p>
          <div class="quiz-actions">
            <a href="onboarding.php" class="btn btn-light">Vormerken</a>
            <span class="quiz-meta">kommt bald</span>
          </div>
        </article>

        <article class="quiz-card disabled-card">
          <span class="quiz-badge">Englisch · Basics</span>
          <h3>Simple Present</h3>
          <p>
            Grundlagen festigen und typische Fehler vermeiden. Dieses Quiz folgt als nächstes.
          </p>
          <div class="quiz-actions">
            <a href="onboarding.php" class="btn btn-light">Vormerken</a>
            <span class="quiz-meta">kommt bald</span>
          </div>
        </article>
      </div>
    </div>
  </section>

  <section class="section-padding">
    <div class="container">
      <div class="teacher-card">
        <div>
          <span class="small-label">Für Schule gedacht</span>
          <h2 class="fw-bold">Nicht nur Quiz. Sondern Lernstoff mit System.</h2>
          <p class="text-muted mb-0">
            Elevaro wird so aufgebaut, dass Inhalte später nach Lehrplan, Bundesland und Klasse zugeordnet werden können.
            So entstehen Quizze, die Lehrer empfehlen können – und Schüler gerne spielen.
          </p>
        </div>
        <a href="onboarding.php" class="btn btn-primary btn-lg">Jetzt passenden Einstieg finden</a>
      </div>
    </div>
  </section>

</main>

<footer class="py-4 border-top bg-white">
  <div class="container d-flex justify-content-between flex-wrap gap-2 text-muted small">
    <span>© <?= date('Y') ?> Elevaro</span>
    <span>Spielerisch zu guten Noten.</span>
  </div>
</footer>

<script src="assets/js/home.js"></script>
</body>
</html>
