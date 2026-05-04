<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/email.php';

function auth_random_token(): string { return bin2hex(random_bytes(32)); }
function auth_token_hash(string $token): string { return hash('sha256', $token); }

function auth_create_email_verification_token(int $userId, string $returnUrl = '/recommendations.php'): string
{
    $token = auth_random_token();
    elevaro_db()->prepare("
        INSERT INTO auth_email_verification_tokens (user_id, token_hash, return_url, expires_at)
        VALUES (:user_id, :token_hash, :return_url, DATE_ADD(NOW(), INTERVAL 24 HOUR))
    ")->execute([
        'user_id' => $userId,
        'token_hash' => auth_token_hash($token),
        'return_url' => $returnUrl,
    ]);
    return $token;
}

function auth_send_verification_mail(array $user, string $token, string $returnUrl = '/recommendations.php'): bool
{
    $url = elevaro_base_url() . '/verify_email.php?token=' . urlencode($token) . '&return=' . urlencode($returnUrl);
    $name = trim((string)($user['display_name'] ?? $user['email'] ?? ''));
    $body = '<p>Hallo ' . htmlspecialchars($name ?: 'du', ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>bitte bestätige kurz deine E-Mail-Adresse, damit dein Elevaro-Account aktiviert wird.</p>'
        . '<p>Danach kommst du automatisch zurück zu deinem Lernweg.</p>';
    return elevaro_send_mail((string)$user['email'], 'Bitte bestätige deine E-Mail-Adresse', elevaro_mail_layout('E-Mail bestätigen', $body, 'E-Mail bestätigen', $url));
}

function auth_create_password_reset_token(int $userId): string
{
    $token = auth_random_token();
    elevaro_db()->prepare("
        INSERT INTO auth_password_reset_tokens (user_id, token_hash, expires_at)
        VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))
    ")->execute([
        'user_id' => $userId,
        'token_hash' => auth_token_hash($token),
    ]);
    return $token;
}

function auth_send_password_reset_mail(array $user, string $token): bool
{
    $url = elevaro_base_url() . '/reset_password.php?token=' . urlencode($token);
    $body = '<p>Du hast angefordert, dein Passwort zurückzusetzen.</p>'
        . '<p>Der Link ist eine Stunde gültig. Falls du das nicht warst, kannst du diese E-Mail ignorieren.</p>';
    return elevaro_send_mail((string)$user['email'], 'Passwort zurücksetzen', elevaro_mail_layout('Passwort zurücksetzen', $body, 'Neues Passwort setzen', $url));
}
