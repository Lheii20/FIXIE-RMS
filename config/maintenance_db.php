<?php

declare(strict_types=1);

require_once __DIR__ . '/runtime.php';

/**
 * Side-effect-free database bootstrap for CLI maintenance.
 * Unlike the normal web bootstrap, this file does not create tables, alter
 * columns, clean login rows, start sessions, or enforce browser sessions.
 */

$database_config = drms_runtime_section('database');
$host = (string) $database_config['host'];
$port = (int) $database_config['port'];
$user = (string) $database_config['user'];
$pass = (string) $database_config['password'];
$database = (string) $database_config['name'];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $pass, $database, $port);
    $conn->set_charset('utf8mb4');
    drms_runtime_database_timezone($conn);
    unset($database_config, $host, $port, $user, $pass, $database);
} catch (mysqli_sql_exception $error) {
    error_log('Maintenance database connection failed: ' . $error->getMessage());
    throw new RuntimeException('The maintenance runner could not connect to the database.', 0, $error);
}

