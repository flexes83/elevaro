<?php
require_once __DIR__ . '/app/includes/frontend_header.php';
$email = $_GET['email'] ?? '';
function vh($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><title>E-Mail bestätigen – Elevaro</title><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="/assets/css/frontend-header.css"><link rel="stylesheet" href="/assets/css/register.css"></head><body>
<?php elevaro_frontend_header('light', []); ?>
<main class="register-page"><section class="register-shell" style="grid-template-columns:1fr;max-width:760px;"><section class="register-card"><div class="register-card-head"><h2>Bestätige deine E-Mail</h2><p>Wir haben dir einen Bestätigungslink an <strong><?= vh($email) ?></strong> geschickt.</p></div><p class="text-muted">Nach der Bestätigung aktivieren wir deinen Account und leiten dich automatisch weiter.</p><a class="btn btn-light" href="/login.php">Zum Login</a></section></section></main></body></html>
