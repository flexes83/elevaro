<?php
require_once __DIR__ . '/app/includes/auth.php';
require_once __DIR__ . '/app/includes/frontend_header.php';

auth_start_session();

if (auth_is_logged_in()) {
    header('Location: /teacher/dashboard.php');
    exit;
}

$return = '/teacher/dashboard.php';
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
<body class="register-page">

<div class="register-layout">
    <div class="register-card">
        <div class="register-brand">
            <span class="register-badge">Für Lehrkräfte</span>
            <h1>Erstelle deinen Lehrerzugang</h1>
            <p>
                Klassenräume erstellen, KI-Quizze generieren und Lernstände verfolgen –
                zunächst kostenlos ohne Bezahlmodell.
            </p>
        </div>

        <?php if ($error): ?>
            <div class="register-error">
                <?= reg_h($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="register-form">
            <div class="form-group">
                <label for="display_name">Name</label>
                <input type="text" id="display_name" name="display_name" required value="<?= reg_h($values['display_name']) ?>">
            </div>

            <div class="form-group">
                <label for="school_name">Schule (optional)</label>
                <input type="text" id="school_name" name="school_name" value="<?= reg_h($values['school_name']) ?>">
            </div>

            <div class="form-group">
                <label for="email">E-Mail</label>
                <input type="email" id="email" name="email" required value="<?= reg_h($values['email']) ?>">
            </div>

            <div class="form-group">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" required minlength="8">
            </div>

            <div class="form-group">
                <label for="password_confirm">Passwort wiederholen</label>
                <input type="password" id="password_confirm" name="password_confirm" required minlength="8">
            </div>

            <label class="checkbox-row">
                <input type="checkbox" name="accept_terms" value="1" required>
                <span>Ich akzeptiere die AGB.</span>
            </label>

            <label class="checkbox-row">
                <input type="checkbox" name="accept_privacy" value="1" required>
                <span>Ich akzeptiere die Datenschutzerklärung.</span>
            </label>

            <button type="submit" class="register-submit">
                Lehrerzugang erstellen
            </button>

            <div class="register-footer">
                Bereits registriert?
                <a href="/login.php?return=/teacher/dashboard.php">Zum Login</a>
            </div>
        </form>
    </div>
</div>

</body>
</html>
