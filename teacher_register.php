<?php
require_once __DIR__ . '/app/includes/auth.php';

auth_start_session();

if (auth_is_logged_in()) {
    header('Location: /teacher/dashboard.php');
    exit;
}

$error = null;

$values = [
    'display_name' => '',
    'email' => '',
    'school_name' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['display_name'] = trim($_POST['display_name'] ?? '');
    $values['email'] = trim($_POST['email'] ?? '');
    $values['school_name'] = trim($_POST['school_name'] ?? '');

    try {
        if (empty($_POST['accept_terms']) || empty($_POST['accept_privacy'])) {
            throw new RuntimeException('Bitte AGB und Datenschutz akzeptieren.');
        }

        if (($_POST['password'] ?? '') !== ($_POST['password_confirm'] ?? '')) {
            throw new RuntimeException('Die Passwörter stimmen nicht überein.');
        }

        $userId = auth_create_user([
            'email' => $values['email'],
            'password' => (string)($_POST['password'] ?? ''),
            'display_name' => $values['display_name'],
            'role' => 'lehrer',
            'status' => 'active',
            'school_name' => $values['school_name'],
            'has_active_access' => 1,
        ], true);

        auth_login_by_user_id($userId);

        header('Location: /teacher/dashboard.php?welcome=1');
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
    <title>Lehrerzugang erstellen – Elevaro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Erstelle deinen kostenlosen Lehrerzugang für Klassenräume und KI-Quizze.">
    <link rel="stylesheet" href="/assets/css/register.css">
</head>
<body class="register-page teacher-register-page">

<div class="register-bg-orb register-bg-orb-1"></div>
<div class="register-bg-orb register-bg-orb-2"></div>

<main class="register-shell">
    <section class="register-card register-card-teacher">
        <aside class="register-hero-panel">
            <a href="/" class="register-logo register-logo-clean" aria-label="Elevaro Startseite">
                <span>Elevaro</span>
            </a>

            <div class="register-hero-content">
                <span class="register-badge">Kostenloser Beta-Zugang</span>
                <h1>Unterrichtsmaterialien werden zu interaktiven Quizzes.</h1>
                <p>
                    Erstelle aus Arbeitsblättern, Buchseiten oder PDFs in wenigen Minuten interaktive Quizzes für deinen Unterricht. Teile Klassenräume per QR-Code und teste Elevaro aktuell kostenlos im kleinen Lehrkräfte-Kreis.
                </p>

                <div class="register-benefits">
                    <div class="register-benefit">
                        <span>⚡</span>
                        <strong>Kostenlose Beta</strong>
                        <small>Ohne Payment, ohne Laufzeit, direkt ausprobieren.</small>
                    </div>
                    <div class="register-benefit">
                        <span>🧠</span>
                        <strong>KI-Quiz-Wizard</strong>
                        <small>Arbeitsblatt hochladen → fertiges Quiz erhalten.</small>
                    </div>
                    <div class="register-benefit">
                        <span>🎮</span>
                        <strong>Klassenräume & Duelle</strong>
                        <small>Quizzes gemeinsam spielen, Fortschritte sehen und gegeneinander antreten.</small>
                    </div>
                
                <div class="register-beta-note">
                    Kostenloser Zugang während der Beta • Kein Payment erforderlich
                </div>
</div>
            </div>
        </aside>

        <div class="register-form-panel">
            <div class="register-form-head">
                <span class="register-kicker">Geschlossene Beta</span>
                <h2>Lehrerzugang erstellen</h2>
                <p>Der Zugang ist während der Beta kostenlos. Eine E-Mail-Verifikation ist aktuell noch nicht erforderlich.</p>
            </div>

            <?php if ($error): ?>
                <div class="register-error">
                    <?= reg_h($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="register-form">
                <div class="form-group">
                    <label for="display_name">Name</label>
                    <input type="text" id="display_name" name="display_name" required value="<?= reg_h($values['display_name']) ?>" placeholder="z. B. Felix Küster">
                </div>

                <div class="form-group">
                    <label for="school_name">Schule <span>optional</span></label>
                    <input type="text" id="school_name" name="school_name" value="<?= reg_h($values['school_name']) ?>" placeholder="z. B. Gymnasium am See">
                </div>

                <div class="form-group">
                    <label for="email">E-Mail</label>
                    <input type="email" id="email" name="email" required value="<?= reg_h($values['email']) ?>" placeholder="name@schule.de">
                </div>

                <div class="register-grid-2">
                    <div class="form-group">
                        <label for="password">Passwort</label>
                        <input type="password" id="password" name="password" required minlength="8" placeholder="Mind. 8 Zeichen">
                    </div>

                    <div class="form-group">
                        <label for="password_confirm">Wiederholen</label>
                        <input type="password" id="password_confirm" name="password_confirm" required minlength="8" placeholder="Noch einmal">
                    </div>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="accept_terms" value="1" required>
                    <span>Ich akzeptiere die <a href="/agb.php" target="_blank">AGB</a>.</span>
                </label>

                <label class="checkbox-row">
                    <input type="checkbox" name="accept_privacy" value="1" required>
                    <span>Ich akzeptiere die <a href="/datenschutz.php" target="_blank">Datenschutzerklärung</a>.</span>
                </label>

                <button type="submit" class="register-submit">
                    Kostenlosen Beta-Zugang erstellen
                    <span>→</span>
                </button>

                <div class="register-footer">
                    Bereits registriert?
                    <a href="/login.php?return=/teacher/dashboard.php">Zum Login</a>
                </div>
            </form>
        </div>
    </section>
</main>

</body>
</html>
