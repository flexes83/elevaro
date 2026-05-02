<?php

require_once __DIR__ . '/config.php';

function elevaro_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = elevaro_config('db');

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['database'],
        $config['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
