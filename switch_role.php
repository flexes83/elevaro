<?php
require_once __DIR__ . '/app/includes/auth.php';

auth_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}

$role = (string)($_POST['role'] ?? '');
$return = (string)($_POST['return'] ?? '/admin/index.php');

try {
    if ($role === 'reset') {
        auth_reset_effective_role();
    } else {
        auth_set_effective_role($role);
    }
} catch (Throwable $e) {
    // Debug-Simulation darf nie die App abschießen.
}

if (!$return || !str_starts_with($return, '/')) {
    $return = '/admin/index.php';
}

header('Location: ' . $return);
exit;
