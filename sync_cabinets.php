<?php
declare(strict_types=1);

// DRMS_VC1_LEGACY_SYNC_DISABLED
// Storage locations are independent of digital record categories.
// This retired entry point never opens a database connection.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

fwrite(STDERR, "Legacy cabinet synchronization is disabled. No storage or records were changed.\n");
fwrite(STDERR, "Use the Virtual Cabinet location-management workflow when VC2 is installed.\n");
exit(2);
