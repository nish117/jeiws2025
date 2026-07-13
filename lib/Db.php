<?php
// Shared MySQL connection helper — used by admin/functions.php
// (project sync) and the site/ portal (attendance & materials stock).

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    defined('JEIWS_CONFIG') or define('JEIWS_CONFIG', 1);
    $cfg = require __DIR__ . '/../config/db.php';

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['host'], $cfg['port'], $cfg['dbname']
    );

    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}
