<?php
require_once __DIR__ . '/app/includes/auth.php';

auth_logout();

header('Location: /login.php');
exit;
