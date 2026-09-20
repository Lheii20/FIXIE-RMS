<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/config/db_connect.php';

try {
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM `user_sessions`');
    while ($row = $result->fetch_assoc()) $columns[] = (string) $row['Field'];
    $required = [
        'session_id', 'user_id', 'device_key_hash', 'auth_token_hash',
        'ip_address', 'user_agent', 'signed_in_at', 'last_seen_at',
        'ended_at', 'ended_reason'
    ];
    $missing = array_diff($required, $columns);
    if ($missing) throw new RuntimeException('Missing columns: ' . implode(', ', $missing));

    $indexes = [];
    $result = $conn->query('SHOW INDEX FROM `user_sessions`');
    while ($row = $result->fetch_assoc()) $indexes[(string) $row['Key_name']] = true;
    foreach (['PRIMARY', 'uq_user_sessions_device_key', 'idx_user_sessions_user_state'] as $name) {
        if (!isset($indexes[$name])) throw new RuntimeException('Missing index: ' . $name);
    }
    echo "Security Center database verification passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Security Center database verification failed: ' . $error->getMessage() . "\n");
    exit(1);
}
