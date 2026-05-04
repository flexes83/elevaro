<?php
require_once __DIR__ . '/app/includes/auth.php';

auth_start_session();

$error = null;

if (auth_is_logged_in()) {
    auth_redirect_after_login();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (auth_login($login, $password)) {
        auth_redirect_after_login();
    }

    $error = 'Login fehlgeschlagen. Bitte Daten prüfen.';
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Login – Elevaro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      --primary: #5a4ff3;
      --ink: #172033;
      --muted: #6c7482;
      --font-body: "Inter", system-ui, sans-serif;
      --font-display: "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
    }

    body {
      min-height: 100vh;
      display: grid;
      place-items: center;
      margin: 0;
      color: var(--ink);
      font-family: var(--font-body);
      font-weight: 650;
      letter-spacing: -0.015em;
      background:
        radial-gradient(circle at 15% 18%, rgba(255,255,255,.52), transparent 20rem),
        radial-gradient(circle at 82% 12%, rgba(0,206,201,.34), transparent 28rem),
        radial-gradient(circle at 78% 78%, rgba(253,203,110,.2), transparent 30rem),
        radial-gradient(circle at 20% 82%, rgba(253,121,168,.18), transparent 24rem),
        linear-gradient(120deg, #fff 0%, #f2efff 24%, #e3fbff 64%, #fff7fb 100%);
    }

    .login-card {
      width: min(94vw, 460px);
      border: 1px solid rgba(255,255,255,.75);
      border-radius: 2rem;
      padding: 2rem;
      background: rgba(255,255,255,.88);
      box-shadow: 0 1.8rem 5rem rgba(23,32,51,.13);
      backdrop-filter: blur(22px);
    }

    h1 {
      font-family: var(--font-display);
      font-size: 2.7rem;
      line-height: .95;
      letter-spacing: -.06em;
      font-weight: 800;
    }

    .brand {
      color: var(--primary);
      font-family: var(--font-display);
      font-weight: 800;
      letter-spacing: -.04em;
      text-decoration: none;
    }

    .btn {
      border-radius: 999px;
      font-weight: 850;
      letter-spacing: -.02em;
    }

    .btn-primary {
      background: var(--primary);
      border-color: var(--primary);
    }

    .form-control {
      border-radius: 1rem;
      padding: .8rem 1rem;
      font-weight: 750;
    }
  </style>
</head>
<body>
  <main class="login-card">
    <a class="brand" href="/">Elevaro</a>
    <h1 class="mt-4 mb-2">Einloggen</h1>
    <p class="text-muted mb-4">Melde dich an, um Elevaro mit deiner Rolle zu nutzen.</p>

    <?php if ($error): ?>
      <div class="alert alert-danger"><?= auth_h($error) ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="mb-3">
        <label class="form-label fw-bold">E-Mail oder Benutzername</label>
        <input class="form-control" name="login" autocomplete="username" required>
      </div>

      <div class="mb-4">
        <label class="form-label fw-bold">Passwort</label>
        <input class="form-control" name="password" type="password" autocomplete="current-password" required>
      </div>

      <button class="btn btn-primary btn-lg w-100">Einloggen</button>
    </form>
    <p class="text-center mt-3 mb-0"><a href="/forgot_password.php">Passwort vergessen?</a></p>
<p class="text-center mt-3">Noch keinen Account? <a href="/register.php?return=<?= urlencode($_GET['return'] ?? '/recommendations.php') ?>">Kostenlos registrieren</a></p>
  </main>
</body>
</html>
