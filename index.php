<?php
// Elevaro refined hero homepage
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Elevaro – Lernquizze passend zu deinem Schulstoff</title>
  <meta name="description" content="Elevaro schlägt dir Lernquizze vor, die zu Bundesland, Schulart, Klasse und Fach passen. Kurze Quiz-Sessions mit direktem Feedback.">
  <link rel="preconnect" href="https://cdn.jsdelivr.net">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/home.css">
</head>
<body>

<nav class="navbar navbar-expand-lg nav-glass fixed-top">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/">Elevaro</a>

    <div class="ms-auto d-flex align-items-center gap-2">
      <a href="#showcase" class="btn btn-sm btn-ghost d-none d-md-inline-flex">Beispiele</a>
      <a href="onboarding.php" class="btn btn-sm btn-light">Quizze finden</a>
    </div>
  </div>
</nav>

<header class="hero">
  <div class="hero-gradient"></div>
  <div class="hero-noise"></div>
  <div class="hero-blob blob-one"></div>
  <div class="hero-blob blob-two"></div>
  <div class="hero-blob blob-three"></div>

  <div class="container hero-inner">
    <div id="returningBox" class="returning-box d-none mb-4">
      <div>
        <span class="eyebrow">Willkommen zurück</span>
        <strong id="returningTitle">Weiterlernen?</strong>
        <p id="returningText" class="mb-0">Wir haben deine letzte Auswahl gespeichert.</p>
      </div>
      <a href="recommendations.php" class="btn btn-light">Weiterlernen</a>
    </div>

    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <span class="eyebrow">Lernquizze für die Schule</span>
        <h1 class="hero-title">Quizze, die zu deinem Schulstoff passen.</h1>
        <p class="hero-subline">
          Wähle Bundesland, Schulart, Klasse und Fach – Elevaro findet passende Themen und macht daraus kurze Lernquizze mit direktem Feedback.
        </p>

        <div class="hero-actions">
          <a href="onboarding.php" class="btn btn-light btn-lg">Quizze für mich finden</a>
          <a href="#showcase" class="btn btn-outline-light btn-lg">Beispiele ansehen</a>
        </div>

        <div class="hero-tags">
          <span>ohne Anmeldung starten</span>
          <span>kurze Sessions</span>
          <span>direktes Feedback</span>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="demo-shell">
          <div class="demo-top">
            <div>
              <span id="demoContext">Englisch · Klasse 5</span>
              <strong id="demoTitle">this, that, these & those</strong>
            </div>

            <div class="demo-stats">
              <span><b id="demoStreak">3</b>er Serie</span>
              <span><b id="demoPoints">120</b> Punkte</span>
            </div>
          </div>

          <div class="demo-progress">
            <span id="demoProgress"></span>
          </div>

          <div class="demo-card">
            <div id="demoMedia" class="demo-media d-none"></div>
            <div class="demo-content">
              <h2 id="demoQuestion">Welche Antwort ist richtig?</h2>
              <div id="demoAnswers" class="demo-answers"></div>
              <div id="demoFeedback" class="demo-feedback d-none"></div>
            </div>
          </div>

          <div id="fakeCursor" class="fake-cursor">
            <span></span>
          </div>
        </div>
      </div>
    </div>

    <div class="metric-strip">
      <div>
        <strong class="count-up" data-target="100">0</strong>
        <span>Themen im Aufbau</span>
      </div>
      <div>
        <strong class="count-up" data-target="500">0</strong>
        <span>Quizfragen geplant</span>
      </div>
      <div>
        <strong class="count-up" data-target="16">0</strong>
        <span>Bundesländer abbildbar</span>
      </div>
    </div>
  </div>
</header>

<main>
  <section class="flow-section">
    <div class="container">
      <div class="flow-card">
        <div>
          <span>1</span>
          <strong>Einordnen</strong>
          <p>Bundesland, Schulart, Klasse und Fach wählen.</p>
        </div>
        <div>
          <span>2</span>
          <strong>Vorschläge bekommen</strong>
          <p>Elevaro zeigt passende Themen und Quizze.</p>
        </div>
        <div>
          <span>3</span>
          <strong>Gezielt üben</strong>
          <p>Antworten, Feedback bekommen, Wackelkandidaten wiederholen.</p>
        </div>
      </div>
    </div>
  </section>

  <section id="showcase" class="showcase-section">
    <div class="container">
      <div class="section-head">
        <span class="eyebrow dark">Vorzeigequizze</span>
        <h2>So soll sich Lernen anfühlen.</h2>
        <p>
          Die Beispiele zeigen, wohin Elevaro geht: visuell, abwechslungsreich und näher am tatsächlichen Lernkontext.
        </p>
      </div>

      <div class="showcase-grid">
        <article class="showcase-card large-card">
          <div class="showcase-visual bird-visual">
            <div class="bird-photo-mini"></div>
            <div class="answer-chips">
              <span>Elster</span>
              <span>Amsel</span>
              <span>Star</span>
            </div>
          </div>
          <div class="showcase-body">
            <span class="card-label">Biologie · Arten erkennen</span>
            <h3>Vogelarten bestimmen</h3>
            <p>Ideal für Bildfragen: sehen, unterscheiden, benennen – nicht nur auswendig lernen.</p>
          </div>
        </article>

        <article class="showcase-card">
          <div class="showcase-visual english-visual">
            <span class="huge">🇬🇧</span>
            <div class="floating-words">
              <span>this</span><span>that</span><span>these</span><span>those</span>
            </div>
          </div>
          <div class="showcase-body">
            <span class="card-label">Englisch · Grammatik</span>
            <h3>this, that, these & those</h3>
            <p>Kurze Beispielsätze, klare Erklärung und typische Fehler als Antwortoptionen.</p>
          </div>
        </article>

        <article class="showcase-card">
          <div class="showcase-visual geo-visual">
            <span class="huge">🗺️</span>
          </div>
          <div class="showcase-body">
            <span class="card-label">Geographie · Orientierung</span>
            <h3>Karten lesen</h3>
            <p>Symbole erkennen, Richtungen verstehen und Zusammenhänge auf Karten einordnen.</p>
          </div>
        </article>
      </div>
    </div>
  </section>

  <section class="teacher-note-section">
    <div class="container">
      <div class="teacher-note">
        <div>
          <span class="eyebrow dark">Qualität im Blick</span>
          <h2>KI hilft beim Erstellen. Menschen behalten den Überblick.</h2>
          <p>
            Quizze können nach Lehrplan-Kontext erstellt, redaktionell geprüft und perspektivisch mit Lehrkräften weiter geschärft werden.
          </p>
        </div>
        <a href="onboarding.php" class="btn btn-primary btn-lg">Quizze finden</a>
      </div>
    </div>
  </section>
</main>

<footer class="footer">
  <div class="container">
    <span>© <?= date('Y') ?> Elevaro</span>
    <span>Lernquizze passend zu deinem Schulstoff.</span>
  </div>
</footer>

<script src="assets/js/home.js"></script>
</body>
</html>
