<?php

require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/access.php';

auth_require_login();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $result = elevaro_apply_access_code((int)auth_user()['id'], $_POST['code'] ?? '');
        if ($result['type'] === 'premium_code') {
            $success = 'Dein Zugang wurde für ' . (int)$result['months'] . ' Monate freigeschaltet.';
        } else {
            $success = 'Du wurdest erfolgreich deiner Klasse zugeordnet.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Code einlösen – Elevaro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/paywall.css">
</head>
<body>
<main class="paywall-page">
  <section class="paywall-code-card is-standalone">
    <h1>Code einlösen</h1>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <form method="post" class="paywall-code-form">
      <input name="code" placeholder="Code eingeben" required>
      <button class="btn btn-primary">Einlösen</button>
    </form>
    <a class="btn btn-light mt-3" href="/recommendations.php">Zurück zu deinen Quizzen</a>
  </section>
</main>
</body>
</html>
