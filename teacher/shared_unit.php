<?php
$token = trim((string)($_GET['token'] ?? ''));
header('Location: /shared_unit.php' . ($token !== '' ? '?token=' . urlencode($token) : ''));
exit;
