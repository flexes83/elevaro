<?php
require_once __DIR__ . '/app/includes/frontend_header.php';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Premium aktiviert – Elevaro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/paywall.css">
  <link rel="stylesheet" href="/assets/css/frontend-header.css">
</head>
<body>
<?php elevaro_frontend_header('light', []); ?>
<main class="paywall-page">
  <section class="paywall-code-card is-standalone">
    <h1>Premium ist fast bereit 🎉</h1>
    <p>Deine Zahlung wurde verarbeitet. Falls dein Zugang nicht sofort aktiv ist, dauert der Stripe-Webhook einen Moment.</p>
    <a class="btn btn-primary" href="/recommendations.php">Weiterlernen</a>
  </section>
</main>
</body>
</html>
