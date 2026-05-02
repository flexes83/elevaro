<?php
require_once __DIR__ . '/../app/includes/db.php';

function admin_db(): PDO { return elevaro_db(); }
function admin_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function admin_active(string $file): string { return basename($_SERVER['SCRIPT_NAME']) === $file ? 'active' : ''; }
function admin_count(PDO $pdo, string $table): int {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
?>