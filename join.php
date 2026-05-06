<?php
require_once __DIR__ . '/app/includes/classroom.php';

$code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
$class = $code !== '' ? classroom_by_code($code) : null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$class) throw new RuntimeException('Dieser Klassenraum wurde nicht gefunden.');
        if (isset($class['allow_guest_join']) && (int)$class['allow_guest_join'] !== 1 && !auth_is_logged_in()) {
            throw new RuntimeException('Für diesen Klassenraum brauchst du ein Schülerkonto.');
        }
        $participant = classroom_join_guest($class, (string)($_POST['display_name'] ?? ''));
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
      <p>Gib deinen Namen ein, damit deine Lehrkraft dich im Klassenraum zuordnen kann. Du brauchst keine E-Mail-Adresse.</p>
      <?php if ($error): ?><div class="alert alert-danger"><?= classroom_h($error) ?></div><?php endif; ?>
      <form method="post" class="join-form">
        <input type="hidden" name="code" value="<?= classroom_h($code) ?>">
        <label class="form-label">Wie sollen wir dich nennen?</label>
        <input class="form-control form-control-lg" name="display_name" value="<?= classroom_h($prefill) ?>" placeholder="z. B. Lena Müller" autocomplete="name" required autofocus>
        <button class="btn btn-primary btn-lg w-100">Beitreten</button>
      </form>
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
