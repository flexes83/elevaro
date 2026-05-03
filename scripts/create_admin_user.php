<?php
/**
 * Einmaliges Setup für den ersten Admin.
 *
 * Aufruf per Browser:
 * /scripts/create_admin_user.php?token=CHANGE_ME&email=mail@example.de&password=SehrSicheresPasswort&name=Felix
 *
 * Wichtig:
 * - Token unten ändern.
 * - Script nach erfolgreicher Ausführung löschen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

$setupToken = 'Kel1!sch00l';

if (($_GET['token'] ?? '') !== $setupToken) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$email = trim($_GET['email'] ?? '');
$username = trim($_GET['username'] ?? 'admin');
$password = (string)($_GET['password'] ?? '');
$name = trim($_GET['name'] ?? 'Admin');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
    http_response_code(400);
    echo "Bitte gültige email und password mit mindestens 10 Zeichen übergeben.\n";
    exit;
}

$pdo = elevaro_db();

$stmt = $pdo->prepare("
    INSERT INTO auth_users (email, username, password_hash, display_name, role, status)
    VALUES (:email, :username, :password_hash, :display_name, 'admin', 'active')
    ON DUPLICATE KEY UPDATE
      password_hash = VALUES(password_hash),
      display_name = VALUES(display_name),
      role = 'admin',
      status = 'active'
");

$stmt->execute([
    'email' => $email,
    'username' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'display_name' => $name,
]);

echo "OK: Admin wurde angelegt/aktualisiert.\n";
echo "Bitte dieses Script jetzt löschen.\n";
