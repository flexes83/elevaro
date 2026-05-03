<?php
require_once __DIR__ . '/app/includes/auth.php';

auth_require_login();

$role = auth_effective_role();

if ($role === 'admin') {
    header('Location: /admin/index.php');
    exit;
}

if ($role === 'lehrer') {
    header('Location: /teacher_dashboard.php');
    exit;
}

header('Location: /student_dashboard.php');
exit;
