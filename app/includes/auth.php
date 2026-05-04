<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const ELEVARO_AUTH_SESSION_KEY = 'elevaro_auth_user_id';
const ELEVARO_EFFECTIVE_ROLE_KEY = 'elevaro_effective_role';

function auth_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function auth_roles(): array
{
    return ['admin', 'lehrer', 'schueler'];
}

function auth_role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Admin',
        'lehrer' => 'Lehrer',
        'schueler' => 'Schüler',
        default => $role,
    };
}

function auth_user(): ?array
{
    auth_start_session();

    $userId = $_SESSION[ELEVARO_AUTH_SESSION_KEY] ?? null;

    if (!$userId) {
        return null;
    }

    try {
        $pdo = elevaro_db();
        $stmt = $pdo->prepare("
            SELECT *
            FROM auth_users
            WHERE id = :id
              AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute(['id' => (int)$userId]);
        $user = $stmt->fetch();

        return $user ?: null;
    } catch (Throwable $e) {
        return null;
    }
}


function auth_find_user_by_login(string $login): ?array
{
    $pdo = elevaro_db();
    $login = trim($login);

    $stmt = $pdo->prepare("
        SELECT *
        FROM auth_users
        WHERE email = :email_login OR username = :username_login
        LIMIT 1
    ");
    $stmt->execute([
        'email_login' => $login,
        'username_login' => $login,
    ]);

    return $stmt->fetch() ?: null;
}

function auth_user_column_exists(string $column): bool
{
    try {
        $stmt = elevaro_db()->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'auth_users'
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute(['column_name' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function auth_create_user(array $data, bool $autoLogin = true): int
{
    auth_start_session();

    $email = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');
    $displayName = trim((string)($data['display_name'] ?? ''));
    $role = (string)($data['role'] ?? 'schueler');
    $status = (string)($data['status'] ?? 'active');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Bitte eine gültige E-Mail-Adresse eingeben.');
    }

    if (mb_strlen($password, 'UTF-8') < 8) {
        throw new RuntimeException('Das Passwort muss mindestens 8 Zeichen lang sein.');
    }

    if (!in_array($role, auth_roles(), true) || $role === 'admin') {
        $role = 'schueler';
    }

    if (!in_array($status, ['active', 'pending'], true)) {
        $status = 'active';
    }

    if (auth_find_user_by_login($email)) {
        throw new RuntimeException('Für diese E-Mail-Adresse existiert bereits ein Account.');
    }

    $usernameBase = explode('@', $email)[0] ?: 'user';
    $username = preg_replace('/[^a-z0-9._-]+/i', '.', $usernameBase);
    $username = trim((string)$username, '.-_') ?: 'user';

    $pdo = elevaro_db();
    $candidate = $username;
    $i = 2;
    while (auth_find_user_by_login($candidate)) {
        $candidate = $username . $i;
        $i++;
    }
    $username = $candidate;

    $fields = [
        'email' => $email,
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'display_name' => $displayName !== '' ? $displayName : $email,
        'role' => $role,
        'status' => $status,
    ];

    if (function_exists('auth_user_column_exists') && auth_user_column_exists('has_active_access')) {
        $fields['has_active_access'] = 1;
    }
    if (function_exists('auth_user_column_exists') && auth_user_column_exists('plan')) {
        $fields['plan'] = 'free';
    }
    if (function_exists('auth_user_column_exists') && auth_user_column_exists('accepted_terms_at')) {
        $fields['accepted_terms_at'] = date('Y-m-d H:i:s');
    }
    if (function_exists('auth_user_column_exists') && auth_user_column_exists('accepted_privacy_at')) {
        $fields['accepted_privacy_at'] = date('Y-m-d H:i:s');
    }
    if (function_exists('auth_user_column_exists') && auth_user_column_exists('marketing_consent_at')) {
        $fields['marketing_consent_at'] = !empty($data['marketing_consent']) ? date('Y-m-d H:i:s') : null;
    }
    if (function_exists('auth_user_column_exists') && auth_user_column_exists('billing_name')) {
        $fields['billing_name'] = trim((string)($data['billing_name'] ?? '')) ?: ($displayName ?: $email);
    }
    if (function_exists('auth_user_column_exists') && auth_user_column_exists('billing_email')) {
        $fields['billing_email'] = trim((string)($data['billing_email'] ?? '')) ?: $email;
    }
    if (function_exists('auth_user_column_exists') && auth_user_column_exists('billing_country')) {
        $fields['billing_country'] = strtoupper(substr(trim((string)($data['billing_country'] ?? 'DE')), 0, 2)) ?: 'DE';
    }

    $columns = array_keys($fields);
    $placeholders = array_map(static fn($column) => ':' . $column, $columns);

    $stmt = $pdo->prepare("
        INSERT INTO auth_users (" . implode(', ', $columns) . ")
        VALUES (" . implode(', ', $placeholders) . ")
    ");
    $stmt->execute($fields);

    $userId = (int)$pdo->lastInsertId();

    if ($autoLogin && $status === 'active') {
        $_SESSION[ELEVARO_AUTH_SESSION_KEY] = $userId;
    }

    return $userId;
}

function auth_set_user_active(int $userId): void
{
    elevaro_db()->prepare("UPDATE auth_users SET status = 'active' WHERE id = :id")->execute(['id' => $userId]);
}

function auth_get_user_by_id_any_status(int $userId): ?array
{
    $stmt = elevaro_db()->prepare("SELECT * FROM auth_users WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $userId]);
    return $stmt->fetch() ?: null;
}

function auth_force_login_user_id(int $userId): void
{
    auth_start_session();
    session_regenerate_id(true);
    $_SESSION[ELEVARO_AUTH_SESSION_KEY] = $userId;
    unset($_SESSION[ELEVARO_EFFECTIVE_ROLE_KEY]);
}

function auth_login(string $login, string $password): bool
{
    auth_start_session();

    $login = trim($login);

    if ($login === '' || $password === '') {
        return false;
    }

    $pdo = elevaro_db();

    $stmt = $pdo->prepare("
        SELECT *
        FROM auth_users
        WHERE (email = :email_login OR username = :username_login)
          AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([
        'email_login' => $login,
        'username_login' => $login,
    ]);

    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);

    $_SESSION[ELEVARO_AUTH_SESSION_KEY] = (int)$user['id'];
    unset($_SESSION[ELEVARO_EFFECTIVE_ROLE_KEY]);

    $stmt = $pdo->prepare("UPDATE auth_users SET last_login_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => (int)$user['id']]);

    return true;
}

function auth_logout(): void
{
    auth_start_session();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }

    session_destroy();
}

function auth_is_logged_in(): bool
{
    return auth_user() !== null;
}

function auth_real_role(): ?string
{
    $user = auth_user();

    return $user['role'] ?? null;
}

function auth_effective_role(): ?string
{
    auth_start_session();

    $user = auth_user();

    if (!$user) {
        return null;
    }

    if ($user['role'] === 'admin') {
        $effective = $_SESSION[ELEVARO_EFFECTIVE_ROLE_KEY] ?? null;

        if ($effective && in_array($effective, auth_roles(), true)) {
            return $effective;
        }
    }

    return $user['role'];
}

function auth_is_admin(): bool
{
    $user = auth_user();

    return $user && ($user['role'] ?? '') === 'admin';
}

function auth_require_login(): void
{
    if (!auth_is_logged_in()) {
        $return = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: /login.php?return=' . urlencode($return));
        exit;
    }
}

function auth_require_admin(): void
{
    auth_require_login();

    if (!auth_is_admin()) {
        http_response_code(403);
        echo 'Kein Zugriff.';
        exit;
    }
}

function auth_set_effective_role(string $role): void
{
    auth_start_session();

    if (!auth_is_admin()) {
        throw new RuntimeException('Nur Admins dürfen Rollen simulieren.');
    }

    if (!in_array($role, auth_roles(), true)) {
        throw new InvalidArgumentException('Ungültige Rolle.');
    }

    $_SESSION[ELEVARO_EFFECTIVE_ROLE_KEY] = $role;
}

function auth_reset_effective_role(): void
{
    auth_start_session();
    unset($_SESSION[ELEVARO_EFFECTIVE_ROLE_KEY]);
}

function auth_redirect_after_login(): void
{
    $return = $_GET['return'] ?? '';

    if ($return && str_starts_with($return, '/')) {
        header('Location: ' . $return);
        exit;
    }

    if (auth_is_admin()) {
        header('Location: /admin/index.php');
        exit;
    }

    header('Location: /recommendations.php');
    exit;
}

function auth_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
