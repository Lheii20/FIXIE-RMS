<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/e_signature.php';

$root = dirname(__DIR__);
$required = [
    'actions/pr_handler.php',
    'view_pr.php',
    'assets/js/prf-review.js',
    'config/official_prf_snapshot.php',
    'config/e_signature.php',
    'includes/e_signature_modal.php',
];
foreach ($required as $file) {
    if (!is_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file))) {
        throw new RuntimeException('Missing Signature Phase 4 PRF file: ' . $file);
    }
    echo "[OK] $file\n";
}

if (!drms_signature_tables_ready($conn)) {
    throw new RuntimeException('Signature Phase 1 database tables are not available.');
}
echo "[OK] Signature foundation tables\n";

$checks = [
    'actions/pr_handler.php' => [
        'phase4_prf_prepare_e_signature',
        'drms_esign_record_event',
        'phase4_prf_signature_fingerprint',
    ],
    'view_pr.php' => [
        'includes/e_signature_modal.php',
        'E-signed',
        'signature_events_by_stage',
    ],
    'assets/js/prf-review.js' => [
        'window.DRMSESignature.open',
        'electronicallySigned',
    ],
    'config/official_prf_snapshot.php' => [
        'document_signature_events',
        'E-SIGN',
    ],
];
foreach ($checks as $file => $markers) {
    $content = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));
    foreach ($markers as $marker) {
        if ($content === false || strpos($content, $marker) === false) {
            throw new RuntimeException('Missing PRF e-signature integration marker: ' . $file . ' / ' . $marker);
        }
    }
    echo "[OK] $file integration\n";
}

echo "Signature Phase 4 PRF installation verification passed.\n";
