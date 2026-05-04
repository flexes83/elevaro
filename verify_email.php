<?php
require_once __DIR__ . '/app/includes/auth_tokens.php';

$token = $_GET['token'] ?? '';
$return = $_GET['return'] ?? '/recommendations.php';
$error = null;

try {
    if ($token === '') throw new RuntimeException('Token fehlt.');

    $stmt = elevaro_db()->prepare("
        SELECT * FROM auth_email_verification_tokens
        WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute(['token_hash' => auth_token_hash($token)]);
    $row = $stmt->fetch();

    if (!$row) throw new RuntimeException('Der Bestätigungslink ist ungültig oder abgelaufen.');

    auth_set_user_active((int)$row['user_id']);
    elevaro_db()->prepare("UPDATE auth_email_verification_tokens SET used_at = NOW() WHERE id = :id")->execute(['id' => (int)$row['id']]);
    auth_force_login_user_id((int)$row['user_id']);

    $target = $row['return_url'] ?: $return;
    if (!is_string($target) || $target === '' || str_starts_with($target, 'http')) $target = '/recommendations.php';

    header('Location: ' . $target);
    exit;
} catch (Throwable $e) {
    $error = $e->getMessage();
}
function vh($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Bestätigung fehlgeschlagen – Elevaro</title><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-5"><div class="container" style="max-width:720px;"><div class="alert alert-danger"><?= vh($error) ?></div><a href="/register.php" class="btn btn-primary">Neu registrieren</a></div></body></html>
