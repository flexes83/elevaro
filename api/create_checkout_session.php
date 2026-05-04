<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/includes/auth.php';
require_once __DIR__ . '/../app/includes/access.php';
require_once __DIR__ . '/../app/includes/stripe_client.php';

if (!auth_is_logged_in()) {
    header('Location: /register.php?mode=premium&return=' . urlencode('/api/create_checkout_session.php'));
    exit;
}

$user = auth_user();

if (elevaro_user_is_premium($user)) {
    header('Location: /account.php?premium=1');
    exit;
}

try {
    $url = elevaro_create_student_checkout_session($user);
    header('Location: ' . $url);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Checkout konnte nicht gestartet werden: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
