<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

require_once __DIR__ . '/config/maintenance_db.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/maintenance_engine.php';

try {
    $options = drms_maintenance_parse_arguments($argv ?? []);
    if (!empty($options['help'])) {
        echo drms_maintenance_help_text();
        exit(0);
    }

    $report = drms_maintenance_run($conn, __DIR__, $options);
    if (($report['status'] ?? '') === 'busy') {
        drms_maintenance_emit((string) $report['message'], false, true);
    }
    exit((int) ($report['exit_code'] ?? 1));
} catch (Throwable $error) {
    drms_maintenance_emit('Maintenance runner could not start: ' . $error->getMessage(), false, true);
    error_log('Fixie DRMS maintenance startup failure: ' . $error->getMessage());
    exit(1);
}
