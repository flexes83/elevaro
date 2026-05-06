<?php
require_once __DIR__ . '/app/includes/classroom.php';

$code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
$class = $code !== '' ? classroom_by_code($code) : null;
$error = null;
$mode = (string)($_POST['join_mode'] ?? 'name');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$class) throw new RuntimeException('Dieser Klassenraum wurde nicht gefunden.');

        if ($mode === 'pin') {
            $participant = classroom_login_guest_pin($class, (string)($_POST['guest_pin'] ?? ''));
        } else {
            if (isset($class['allow_guest_join']) && (int)$class['allow_guest_join'] !== 1 && !auth_is_logged_in()) {
                throw new RuntimeException('Für diesen Klassenraum brauchst du ein Schülerkonto.');
            }
            $participant = classroom_join_guest($class, (string)($_POST['display_name'] ?? ''));
        }

        header('Location: /classroom.php?class_id=' . (int)$class['id']);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$prefill = '';
if ($user = auth_user()) $prefill = (string)($user['display_name'] ?: $user['username'] ?: '');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Klassenraum beitreten – Elevaro</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/classroom.css?v=<?= filemtime(__DIR__ . '/assets/css/classroom.css') ?>">
</head>
<body class="classroom-join-page">
<main class="classroom-join-shell">
  <section class="classroom-join-card">
    <div class="join-badge">🏫 Klassenraum</div>
    <?php if ($class): ?>
      <h1>Willkommen in<br><?= classroom_h(classroom_label($class)) ?></h1>
      <p>Du kannst neu beitreten oder deinen 4-stelligen Klassen-PIN nutzen, wenn du schon einmal dabei warst.</p>
      <?php if ($error): ?><div class="alert alert-danger"><?= classroom_h($error) ?></div><?php endif; ?>

      <div class="join-methods">
        <form method="post" class="join-form join-form-card">
          <input type="hidden" name="code" value="<?= classroom_h($code) ?>">
          <input type="hidden" name="join_mode" value="name">
          <div class="join-form-icon">👋</div>
          <h2>Neu beitreten</h2>
          <p>Gib deinen Namen ein. Danach bekommst du deinen persönlichen Klassen-PIN.</p>
          <label class="form-label">Wie sollen wir dich nennen?</label>
          <input class="form-control form-control-lg" name="display_name" value="<?= classroom_h($prefill) ?>" placeholder="z. B. Lena Müller" autocomplete="name" <?= $mode !== 'pin' ? 'autofocus' : '' ?> required>
          <button class="btn btn-primary btn-lg w-100">Beitreten</button>
        </form>

        <form method="post" class="join-form join-form-card join-pin-card">
          <input type="hidden" name="code" value="<?= classroom_h($code) ?>">
          <input type="hidden" name="join_mode" value="pin">
          <div class="join-form-icon">🔐</div>
          <h2>Ich habe schon einen PIN</h2>
          <p>Wenn du das Gerät gewechselt hast, kommst du damit direkt zurück.</p>
          <label class="form-label">4-stelliger Klassen-PIN</label>
          <input class="form-control form-control-lg join-pin-input" name="guest_pin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="4827" autocomplete="one-time-code" <?= $mode === 'pin' ? 'autofocus' : '' ?>>
          <button class="btn btn-outline-primary btn-lg w-100">Mit PIN weiter</button>
        </form>
      </div>

      <div class="join-login-hint">
        Schon ein Schülerkonto? <a href="/login.php?return=<?= urlencode('/join.php?code=' . $code) ?>">Einloggen</a>
      </div>
    <?php else: ?>
      <h1>Klassenraum öffnen</h1>
      <p>Gib den Code ein, den du von deiner Lehrkraft bekommen hast.</p>
      <?php if ($error): ?><div class="alert alert-danger"><?= classroom_h($error) ?></div><?php endif; ?>
      <form method="get" class="join-form">
        <label class="form-label">Klassencode</label>
        <input class="form-control form-control-lg text-uppercase" name="code" placeholder="ABCD1234" required autofocus>
        <button class="btn btn-primary btn-lg w-100">Klassenraum suchen</button>
      </form>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
