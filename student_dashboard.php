<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/frontend_header.php';

auth_require_login();

$user = auth_user();
$role = auth_effective_role();

if ($role === 'admin') {
    header('Location: /admin/index.php');
    exit;
}

$name = trim((string)($user['display_name'] ?: $user['username'] ?: $user['email']));
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Schüler-Dashboard – Elevaro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://cdn.jsdelivr.net">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/css/frontend-header.css" rel="stylesheet">
  <link href="/assets/css/dashboard.css" rel="stylesheet">
</head>
<body>
<?php elevaro_frontend_header('light'); ?>

<main class="dashboard-wrap">
  <div class="container">
    <section class="dashboard-hero">
      <span class="badge text-bg-light text-dark mb-3">Schüler</span>
      <h1>Hallo <?= auth_h($name) ?>, bereit zum Üben?</h1>
      <p>Hier entsteht dein persönlicher Lernbereich mit Empfehlungen, Fortschritt und Wiederholungen.</p>
      <a class="btn btn-primary btn-lg" href="/recommendations.php">Quizze finden</a>
    </section>

    <div class="dashboard-grid">
      <section class="dashboard-card">
        <h2>Dein Überblick</h2>
        <div class="stat-grid mt-3">
          <div class="stat-tile">
            <strong>0</strong>
            <span>gespielte Quizze</span>
          </div>
          <div class="stat-tile">
            <strong>0</strong>
            <span>Punkte</span>
          </div>
          <div class="stat-tile">
            <strong>0</strong>
            <span>Serientage</span>
          </div>
        </div>
      </section>

      <aside class="dashboard-card">
        <h2>Schnellzugriff</h2>
        <div class="quick-actions mt-3">
          <a class="btn btn-primary" href="/recommendations.php">🎯 Empfohlene Quizze</a>
          <a class="btn btn-light" href="/onboarding.php?edit=1">⚙️ Auswahl ändern</a>
          <a class="btn btn-light" href="/account.php">👤 Mein Konto</a>
        </div>
      </aside>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
