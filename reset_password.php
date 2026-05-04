<?php
require_once __DIR__ . '/app/includes/auth_tokens.php';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = null; $success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($token === '') throw new RuntimeException('Token fehlt.');
        if (($_POST['password'] ?? '') !== ($_POST['password_confirm'] ?? '')) throw new RuntimeException('Die Passwörter stimmen nicht überein.');
        $password = (string)($_POST['password'] ?? '');
        if (mb_strlen($password, 'UTF-8') < 8) throw new RuntimeException('Das Passwort muss mindestens 8 Zeichen lang sein.');
        $stmt = elevaro_db()->prepare("SELECT * FROM auth_password_reset_tokens WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW() LIMIT 1");
        $stmt->execute(['token_hash' => auth_token_hash($token)]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Der Link ist ungültig oder abgelaufen.');
        elevaro_db()->prepare("UPDATE auth_users SET password_hash = :hash WHERE id = :id")->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int)$row['user_id']]);
        elevaro_db()->prepare("UPDATE auth_password_reset_tokens SET used_at = NOW() WHERE id = :id")->execute(['id' => (int)$row['id']]);
        $success = 'Dein Passwort wurde geändert. Du kannst dich jetzt einloggen.';
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
function rh($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Passwort zurücksetzen – Elevaro</title><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="/assets/css/register.css"></head><body><main class="register-page"><section class="register-shell" style="grid-template-columns:1fr;max-width:640px;"><section class="register-card"><div class="register-card-head"><h2>Neues Passwort setzen</h2><p>Wähle ein neues Passwort für deinen Account.</p></div><?php if ($success): ?><div class="alert alert-success"><?= rh($success) ?></div><a class="btn btn-primary" href="/login.php">Zum Login</a><?php else: ?><?php if ($error): ?><div class="alert alert-danger"><?= rh($error) ?></div><?php endif; ?><form method="post" class="register-form"><input type="hidden" name="token" value="<?= rh($token) ?>"><label>Neues Passwort</label><input type="password" name="password" minlength="8" required><label>Passwort wiederholen</label><input type="password" name="password_confirm" minlength="8" required><button class="btn btn-primary btn-lg w-100 mt-3">Passwort speichern</button></form><?php endif; ?></section></section></main></body></html>
