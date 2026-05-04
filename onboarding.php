<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/frontend_header.php';

auth_start_session();

$step = $_GET['step'] ?? 'role';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 'role') {
        $_SESSION['elevaro_onboarding_role'] = $_POST['role'] ?? 'schueler';
        header('Location: /onboarding.php?step=basics');
        exit;
    }

    if ($step === 'basics') {
        $_SESSION['elevaro_onboarding_grade'] = trim($_POST['grade'] ?? '');
        $_SESSION['elevaro_onboarding_school_type'] = trim($_POST['school_type'] ?? '');
        $_SESSION['elevaro_onboarding_subject'] = trim($_POST['subject'] ?? '');
        header('Location: /register.php?return=' . urlencode('/recommendations.php'));
        exit;
    }
}

function onb_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$role = $_SESSION['elevaro_onboarding_role'] ?? 'schueler';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Loslegen – Elevaro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Finde Quizze passend zu Klasse, Fach und Schulart.">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/frontend-header.css">
  <link rel="stylesheet" href="/assets/css/onboarding-register.css">
</head>
<body>
<?php elevaro_frontend_header('light', []); ?>

<main class="elevaro-flow-page">
  <section class="flow-shell">
    <div class="flow-card">

      <?php if ($step === 'role'): ?>
        <span class="flow-kicker">Schritt 1 von 2</span>
        <h1>Für wen suchst du passende Quizze?</h1>
        <p>Wir passen den Einstieg daran an, ob du selbst lernst, dein Kind unterstützt oder mit einer Klasse arbeitest.</p>

        <form method="post" class="role-grid">
          <button class="role-card" name="role" value="schueler">
            <span>🎒</span>
            <strong>Für Schüler</strong>
            <small>Ich möchte selbst üben und besser werden.</small>
          </button>

          <button class="role-card" name="role" value="eltern">
            <span>👨‍👩‍👧</span>
            <strong>Für Eltern</strong>
            <small>Ich suche passende Übungen für mein Kind.</small>
          </button>

          <button class="role-card" name="role" value="lehrer">
            <span>🏫</span>
            <strong>Für Lehrer</strong>
            <small>Ich möchte Quizze für meine Klasse nutzen.</small>
          </button>
        </form>

      <?php else: ?>
        <span class="flow-kicker">Schritt 2 von 2</span>
        <h1>Was passt zu dir?</h1>
        <p>Damit wir dir direkt sinnvollere Quizze zeigen können, brauchen wir nur ein paar grobe Angaben.</p>

        <form method="post" class="flow-form">
          <div class="row g-3">
            <div class="col-md-4">
              <label>Klasse</label>
              <input name="grade" value="<?= onb_h($_SESSION['elevaro_onboarding_grade'] ?? '') ?>" placeholder="z. B. 7">
            </div>

            <div class="col-md-4">
              <label>Schulart</label>
              <select name="school_type">
                <option value="">Bitte wählen</option>
                <option value="grundschule">Grundschule</option>
                <option value="hauptschule">Hauptschule</option>
                <option value="realschule">Realschule</option>
                <option value="gymnasium">Gymnasium</option>
                <option value="gemeinschaftsschule">Gemeinschaftsschule</option>
              </select>
            </div>

            <div class="col-md-4">
              <label>Fach / Thema</label>
              <input name="subject" value="<?= onb_h($_SESSION['elevaro_onboarding_subject'] ?? '') ?>" placeholder="z. B. Englisch">
            </div>
          </div>

          <button class="btn btn-primary btn-lg mt-4">Weiter</button>
        </form>
      <?php endif; ?>

    </div>
  </section>
</main>
</body>
</html>
