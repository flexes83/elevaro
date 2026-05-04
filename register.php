<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/frontend_header.php';

auth_start_session();

if (auth_is_logged_in()) {
    header('Location: /recommendations.php');
    exit;
}

$return = $_GET['return'] ?? '/recommendations.php';
$error = null;

$values = [
    'display_name' => '',
    'email' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['display_name'] = trim($_POST['display_name'] ?? '');
    $values['email'] = trim($_POST['email'] ?? '');

    try {
        if (empty($_POST['accept_terms']) || empty($_POST['accept_privacy'])) {
            throw new RuntimeException('Bitte AGB und Datenschutz akzeptieren.');
        }

        if (($_POST['password'] ?? '') !== ($_POST['password_confirm'] ?? '')) {
            throw new RuntimeException('Die Passwörter stimmen nicht überein.');
        }

        auth_create_user([
            'email' => $values['email'],
            'password' => (string)($_POST['password'] ?? ''),
            'display_name' => $values['display_name'],
            'role' => 'schueler',
        ]);

        header('Location: ' . $return);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function reg_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Dein Lernzugang – Elevaro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Speichere deinen Fortschritt und übe passende Aufgaben.">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/frontend-header.css">
  <link rel="stylesheet" href="/assets/css/register.css">
</head>
<body>
<?php elevaro_frontend_header('light', []); ?>

<main class="register-page">
  <section class="register-shell">
    <aside class="register-copy">
      <div class="register-visual">🚀</div>
      <span class="register-kicker">Fast geschafft</span>
      <h1>Dein Lernzugang</h1>
      <p>Speichere deinen Fortschritt, übe passende Aufgaben und bleib dran – Schritt für Schritt.</p>

      <div class="register-price-card">
        <div>
          <strong>Premium</strong>
          <span>4,99 € / Monat</span>
        </div>
        <small>monatlich kündbar</small>
      </div>

      <div class="register-usp">
        <div>✓ Aufgaben üben, die noch nicht sitzen</div>
        <div>✓ Fortschritt, Statistiken und Serien speichern</div>
        <div>✓ Premium-Inhalte wie Listenings und Comprehension</div>
      </div>
    </aside>

    <section class="register-card">
      <div class="register-card-head">
        <h2>Account erstellen</h2>
        <p>Danach kannst du direkt mit deinen passenden Quizzen starten.</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= reg_h($error) ?></div>
      <?php endif; ?>

      <form method="post" class="register-form">
        <label>Dein Name</label>
        <input name="display_name" value="<?= reg_h($values['display_name']) ?>" placeholder="z. B. Felix" required>

        <label>E-Mail-Adresse</label>
        <input type="email" name="email" value="<?= reg_h($values['email']) ?>" placeholder="du@example.de" required>

        <label>Passwort</label>
        <input type="password" name="password" minlength="8" placeholder="Mindestens 8 Zeichen" required>

        <label>Passwort wiederholen</label>
        <input type="password" name="password_confirm" minlength="8" required>

        <label class="check-row">
          <input type="checkbox" name="accept_terms" required>
          <span>Ich akzeptiere die AGB.</span>
        </label>

        <label class="check-row">
          <input type="checkbox" name="accept_privacy" required>
          <span>Ich habe die Datenschutzhinweise gelesen.</span>
        </label>

        <button class="btn btn-primary btn-lg w-100 mt-3">Jetzt loslegen</button>
      </form>
    </section>
  </section>
</main>
</body>
</html>
