<?php
// Elevaro refreshed homepage
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Elevaro – Spielerisch zu guten Noten</title>
  <meta name="description" content="Elevaro findet passende Lernquizze nach Bundesland, Schulart, Klasse und Fach. Spielerisch üben, besser verstehen und sicherer werden.">
  <link rel="preconnect" href="https://cdn.jsdelivr.net">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/home.css">
</head>
<body>

<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/">Elevaro</a>

    <div class="ms-auto d-flex align-items-center gap-2">
      <a href="#quizbeispiele" class="btn btn-sm btn-outline-primary d-none d-md-inline-flex">Quiz testen</a>
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
        <span class="hero-badge">Lernquizze nach Klasse, Fach und Schulstoff</span>
        <h1 class="display-3 fw-black mt-3 mb-3">Spielerisch üben. Sicherer werden.</h1>
        <p class="lead mb-4">
          Elevaro schlägt dir Quizze vor, die zu deiner Klasse, Schulart und deinem Bundesland passen.
          So startest du nicht irgendwo, sondern dort, wo dein Lernstoff gerade wirklich relevant ist.
        </p>

        <div class="d-flex flex-wrap gap-3 mb-4">
          <a href="onboarding.php" class="btn btn-primary btn-lg">
            Passende Quizze finden
          </a>
          <a href="#so-funktionierts" class="btn btn-light btn-lg">
            Wie funktioniert das?
          </a>
        </div>

        <div class="trust-row">
          <span>Ohne Anmeldung starten</span>
          <span>Kurze Quiz-Sessions</span>
          <span>Inhalte werden schrittweise geprüft</span>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="hero-card">
          <div class="hero-orbit">
            <div class="hero-panda">🐼</div>
            <span class="orbit-pill pill-one">Mathe ➗</span>
            <span class="orbit-pill pill-two">Geo 🌍</span>
            <span class="orbit-pill pill-three">Englisch 🇬🇧</span>
          </div>

          <h2 class="h4 fw-bold mb-2">Dein Lernweg startet mit ein paar Fragen.</h2>
          <p class="text-muted mb-4">
            Wähle Bundesland, Schulart, Klasse und Fach – Elevaro zeigt dir anschließend passende Themen und Quizze.
          </p>

          <div class="mini-flow">
            <div><strong>1</strong><span>Einordnen</span></div>
            <div><strong>2</strong><span>Quiz finden</span></div>
            <div><strong>3</strong><span>Üben</span></div>
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
        <h2 class="fw-bold">Vom Schulstoff zum passenden Quiz.</h2>
        <p class="text-muted mb-0">
          Elevaro verbindet kurze, motivierende Quizze mit einer klaren Einordnung nach Klasse, Fach und Lernziel.
        </p>
      </div>

      <div class="row g-4">
        <div class="col-md-4">
          <article class="step-card h-100">
            <div class="step-icon">🎒</div>
            <h3 class="h5 fw-bold">1. Kurz einordnen</h3>
            <p>
              Du wählst Bundesland, Schulart, Klasse und Fach. Daraus entsteht dein persönlicher Einstieg.
            </p>
          </article>
        </div>

        <div class="col-md-4">
          <article class="step-card h-100">
            <div class="step-icon">🎯</div>
            <h3 class="h5 fw-bold">2. Thema auswählen</h3>
            <p>
              Statt endloser Suche bekommst du Themen vorgeschlagen, die zu deinem aktuellen Lernstand passen.
            </p>
          </article>
        </div>

        <div class="col-md-4">
          <article class="step-card h-100">
            <div class="step-icon">⚡</div>
            <h3 class="h5 fw-bold">3. Direkt üben</h3>
            <p>
              Du beantwortest kurze Fragen, bekommst direkt Feedback und kannst Wackelkandidaten gezielt wiederholen.
            </p>
          </article>
        </div>
      </div>
    </div>
  </section>

  <section id="quizbeispiele" class="section-padding bg-soft">
    <div class="container">
      <div class="d-flex justify-content-between align-items-end flex-wrap gap-3 mb-4">
        <div>
          <span class="small-label">Direkt ausprobieren</span>
          <h2 class="fw-bold mb-1">Quiz-Beispiele</h2>
          <p class="text-muted mb-0">
            Noch kein Profil? Starte mit einem Beispiel – oder lass dir direkt passende Quizze vorschlagen.
          </p>
        </div>
        <a href="onboarding.php" class="btn btn-outline-primary">Quizze für mich finden</a>
      </div>

      <div class="quiz-grid">
        <article class="quiz-card" style="--card-c1:#6c5ce7;--card-c2:#a29bfe;">
          <div class="quiz-visual"><span>➗</span></div>
          <div class="quiz-content">
            <span class="quiz-badge">Mathe · Klasse 5</span>
            <h3>Brüche verstehen</h3>
            <p>Übe Zähler, Nenner und einfache Anteile – Schritt für Schritt und mit direktem Feedback.</p>
            <div class="quiz-actions">
              <a href="quiz.php?key=mathe_klasse5_bruchrechnen" class="btn btn-primary">Quiz starten</a>
              <span class="quiz-meta">kurz & motivierend</span>
            </div>
          </div>
        </article>

        <article class="quiz-card" style="--card-c1:#0984e3;--card-c2:#74b9ff;">
          <div class="quiz-visual"><span>🇬🇧</span></div>
          <div class="quiz-content">
            <span class="quiz-badge">Englisch · Grammatik</span>
            <h3>this, that, these & those</h3>
            <p>Trainiere typische Stolperstellen in kurzen Beispielsätzen und erkenne Muster sicherer.</p>
            <div class="quiz-actions">
              <a href="onboarding.php" class="btn btn-light">Passend einordnen</a>
              <span class="quiz-meta">ideal zum Wiederholen</span>
            </div>
          </div>
        </article>

        <article class="quiz-card" style="--card-c1:#00b894;--card-c2:#55efc4;">
          <div class="quiz-visual"><span>🌍</span></div>
          <div class="quiz-content">
            <span class="quiz-badge">Geo · Orientierung</span>
            <h3>Karten lesen</h3>
            <p>Erkenne Symbole, Himmelsrichtungen und einfache Zusammenhänge auf Karten.</p>
            <div class="quiz-actions">
              <a href="onboarding.php" class="btn btn-light">Passendes Thema finden</a>
              <span class="quiz-meta">mit Bildfragen möglich</span>
            </div>
          </div>
        </article>
      </div>
    </div>
  </section>

  <section class="section-padding">
    <div class="container">
      <div class="quality-card">
        <div class="quality-icon">✅</div>
        <div>
          <span class="small-label">Qualität im Blick</span>
          <h2 class="fw-bold">Inhalte, die nicht einfach nur zufällig entstehen.</h2>
          <p class="text-muted mb-0">
            Elevaro ordnet Quizze nach Lernkontexten wie Bundesland, Schulart, Klasse und Fach.
            Zusätzlich können Inhalte redaktionell geprüft und perspektivisch mit Lehrkräften weiter geschärft werden.
          </p>
        </div>
      </div>
    </div>
  </section>

  <section class="section-padding pt-0">
    <div class="container">
      <div class="cta-card">
        <div>
          <span class="small-label">Bereit?</span>
          <h2 class="fw-bold">Finde dein nächstes Quiz.</h2>
          <p class="text-muted mb-0">
            Starte mit dem kurzen Onboarding und erhalte Vorschläge, die besser zu deinem Lernstoff passen.
          </p>
        </div>
        <a href="onboarding.php" class="btn btn-primary btn-lg">Los geht’s</a>
      </div>
    </div>
  </section>

</main>

<footer class="py-4 border-top bg-white">
  <div class="container d-flex justify-content-between flex-wrap gap-2 text-muted small">
    <span>© <?= date('Y') ?> Elevaro</span>
    <span>Spielerisch üben. Sicherer werden.</span>
  </div>
</footer>

<script src="assets/js/home.js"></script>
</body>
</html>
