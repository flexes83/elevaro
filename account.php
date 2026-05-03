<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/frontend_header.php';

auth_require_login();

$user = auth_user();
$role = auth_effective_role();
$name = trim((string)($user['display_name'] ?: $user['username'] ?: $user['email']));
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Mein Konto – Elevaro</title>
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
      <span class="badge text-bg-light text-dark mb-3">Mein Konto</span>
      <h1><?= auth_h($name) ?></h1>
      <p>Die Kontoverwaltung ist vorbereitet. Profilfelder, Passwortänderung und Einstellungen ergänzen wir im nächsten Schritt.</p>
    </section>

    <section class="dashboard-card">
      <h2>Kontodaten</h2>
      <div class="table-responsive mt-3">
        <table class="table align-middle">
          <tr>
            <th>E-Mail</th>
            <td><?= auth_h($user['email'] ?? '') ?></td>
          </tr>
          <tr>
            <th>Benutzername</th>
            <td><?= auth_h($user['username'] ?? '') ?></td>
          </tr>
          <tr>
            <th>Rolle</th>
            <td><?= auth_h(auth_role_label((string)$role)) ?></td>
          </tr>
        </table>
      </div>
      <a class="btn btn-outline-danger" href="/logout.php">Logout</a>
    </section>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
