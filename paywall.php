<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/access.php';
require_once __DIR__ . '/app/includes/frontend_header.php';

$user = auth_user();
$isPremium = elevaro_user_is_premium($user);
$return = $_GET['return'] ?? '/recommendations.php';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Quizz dich zu besseren Noten – Elevaro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Lerne mit kurzen Quizzen, wiederhole deine Fehler und werde Schritt für Schritt besser.">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/paywall.css">
  <link rel="stylesheet" href="/assets/css/frontend-header.css">
</head>
<body>
<?php elevaro_frontend_header('light', []); ?>

<main class="paywall-page">
  <section class="paywall-hero">
    <div class="paywall-card">
      <span class="paywall-kicker">Elevaro Premium</span>
      <h1>Quizz dich zu besseren Noten</h1>
      <p class="paywall-subline">Lerne mit kurzen Quizzen, wiederhole deine Fehler und werde Schritt für Schritt besser.</p>

      <div class="paywall-result-preview">
        <div>
          <strong>🔥 Dein nächster Schritt</strong>
          <span>Falsche Fragen gezielt wiederholen, Fortschritt speichern und Serien aufbauen.</span>
        </div>
      </div>

      <ul class="paywall-benefits">
        <li><strong>Unbegrenzte Quizze</strong><span>Keine Tageslimits, kein Ausbremsen.</span></li>
        <li><strong>Fehlertraining</strong><span>Übe genau die Fragen, die noch wackeln.</span></li>
        <li><strong>Fortschritt & Serien</strong><span>Sieh, was besser wird und bleib motiviert.</span></li>
        <li><strong>Listening & Spezialquizze</strong><span>Nutze abwechslungsreiche Lernformate.</span></li>
      </ul>

      <div class="paywall-pricebox">
        <div>
          <span class="paywall-price">4,99 €</span>
          <span class="paywall-period">/ Monat</span>
          <small>monatlich kündbar</small>
        </div>
        <?php if ($isPremium): ?>
          <a class="btn btn-success btn-lg" href="<?= htmlspecialchars($return, ENT_QUOTES, 'UTF-8') ?>">Du hast Premium</a>
        <?php elseif ($user): ?>
          <a class="btn btn-primary btn-lg" href="/api/create_checkout_session.php">Premium freischalten</a>
        <?php else: ?>
          <a class="btn btn-primary btn-lg" href="/login.php?return=<?= urlencode('/paywall.php?return=' . $return) ?>">Premium freischalten</a>
        <?php endif; ?>
      </div>
      <div class="paywall-button-note">4,99 € / Monat · monatlich kündbar · Zahlung über Stripe</div>

      <p class="paywall-trust">Öffentliche Quizze bleiben spielbar. Premium ist für gezieltes Weiterlernen, Fortschritt und unbegrenztes Training.</p>
    </div>

    <aside class="paywall-side">
      <div class="mini-stat">
        <span>8/15</span>
        <small>richtig</small>
      </div>
      <div class="mini-stat">
        <span>3</span>
        <small>Wackelkandidaten</small>
      </div>
      <div class="mini-stat">
        <span>🔥 5</span>
        <small>Serie</small>
      </div>
    </aside>
  </section>

  <section class="paywall-code-card">
    <h2>Du hast einen Code?</h2>
    <p>Klassencode oder Freischaltcode eingeben und direkt weiterlernen.</p>
    <?php if ($user): ?>
      <form method="post" action="/redeem_code.php" class="paywall-code-form">
        <input name="code" placeholder="Code eingeben" required>
        <button class="btn btn-outline-primary">Code einlösen</button>
      </form>
    <?php else: ?>
      <a class="btn btn-outline-primary" href="/register.php?return=<?= urlencode('/redeem_code.php') ?>">Code einlösen</a>
      <p class="code-login-note">Login oder Registrierung erfolgt im nächsten Schritt.</p>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
