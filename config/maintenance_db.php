<?php

declare(strict_types=1);

/**
 * Side-effect-free database bootstrap for CLI maintenance.
 * Unlike the normal web bootstrap, this file does not create tables, alter
 * columns, clean login rows, start sessions, or enforce browser sessions.
 */

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$database = getenv('DB_NAME') ?: 'fixie_drms';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass, $database);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $error) {
    error_log('Maintenance database connection failed: ' . $error->getMessage());
    throw new RuntimeException('The maintenance runner could not connect to the database.', 0, $error);
}

