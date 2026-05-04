<?php
require_once __DIR__ . '/app/includes/auth_tokens.php';
$notice = null; $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    try {
        $user = auth_find_user_by_login($login);
        if ($user && ($user['status'] ?? '') === 'active') {
            $token = auth_create_password_reset_token((int)$user['id']);
            auth_send_password_reset_mail($user, $token);
        }
        $notice = 'Falls ein aktiver Account existiert, haben wir dir einen Link zum Zurücksetzen geschickt.';
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
function fh($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Passwort vergessen – Elevaro</title><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="/assets/css/register.css"></head><body><main class="register-page"><section class="register-shell" style="grid-template-columns:1fr;max-width:640px;"><section class="register-card"><div class="register-card-head"><h2>Passwort vergessen?</h2><p>Gib deine E-Mail-Adresse ein. Wir senden dir einen Link zum Zurücksetzen.</p></div><?php if ($notice): ?><div class="alert alert-success"><?= fh($notice) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= fh($error) ?></div><?php endif; ?><form method="post" class="register-form"><label>E-Mail-Adresse</label><input type="email" name="login" required><button class="btn btn-primary btn-lg w-100 mt-3">Link senden</button></form><p class="mt-3"><a href="/login.php">Zurück zum Login</a></p></section></section></main></body></html>
