<?php

/**
 * Read-only Signature Phase 1 verifier.
 *
 * Run from the project root:
 * C:\xampp\php\php.exe scripts\verify_signature_phase_1.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
$databaseFile = $projectRoot . DIRECTORY_SEPARATOR . 'config' .
    DIRECTORY_SEPARATOR . 'db_connect.php';
$signatureFile = $projectRoot . DIRECTORY_SEPARATOR . 'config' .
    DIRECTORY_SEPARATOR . 'signature_workflow.php';

if (!is_file($databaseFile)) {
    fwrite(STDERR, "Missing config/db_connect.php.\n");
    exit(1);
}

if (!is_file($signatureFile)) {
    fwrite(STDERR, "Missing config/signature_workflow.php.\n");
    exit(1);
}

require $databaseFile;
require $signatureFile;

$requiredColumns = [
    'official_declaration_requests' => [
        'request_id',
        'request_reference',
        'document_id',
        'source_file_hash',
        'source_version',
        'declaration_basis',
        'requested_signer_role',
        'request_status',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'official_document_id',
    ],
    'user_signature_profiles' => [
        'signature_profile_id',
        'user_id',
        'display_name',
        'signatory_title',
        'signature_image_path',
        'signature_image_hash',
        'profile_status',
    ],
    'document_signature_events' => [
        'signature_id',
        'verification_code',
        'record_module',
        'record_id',
        'signature_stage',
        'signature_type',
        'signer_user_id',
        'signer_name',
        'signer_role',
        'signature_method',
        'signed_file_hash',
        'signed_version',
        'consent_text',
        'signed_at',
        'signature_status',
    ],
];

try {
    if (!drms_signature_tables_ready($conn)) {
        throw new RuntimeException(
            'One or more Signature Phase 1 tables are missing. Import the SQL migration first.'
        );
    }

    foreach ($requiredColumns as $tableName => $columns) {
        $result = $conn->query('SHOW COLUMNS FROM `' . $tableName . '`');
        $actualColumns = [];
        while ($column = $result->fetch_assoc()) {
            $actualColumns[] = (string) $column['Field'];
        }

        $missingColumns = array_values(array_diff($columns, $actualColumns));
        if ($missingColumns !== []) {
            throw new RuntimeException(
                $tableName . ' is missing: ' . implode(', ', $missingColumns)
            );
        }

        echo '[OK] ' . $tableName . ' (' . count($actualColumns) . " columns)\n";
    }

    $policyChecks = [
        ['GM', 'Purchase Requisition Form', 'GM Review', true],
        ['Finance', 'Purchase Requisition Form', 'Finance Review', true],
        ['President', 'Purchase Requisition Form', 'Owner Approval', true],
        ['GM', 'Client PO Approval', 'GM Acknowledgement', true],
        ['Admin', 'General Document', 'Official Declaration', false],
        ['GM', 'General Document', 'Official Declaration', true],
        ['President', 'General Document', 'Official Declaration', true],
    ];

    foreach ($policyChecks as $check) {
        [$role, $module, $stage, $expected] = $check;
        $actual = drms_signature_role_can_sign($role, $module, $stage);
        if ($actual !== $expected) {
            throw new RuntimeException(
                "Unexpected authority rule for $role / $module / $stage."
            );
        }
    }

    echo "[OK] Signatory authority matrix\n";
    echo "[OK] Admin is not an organizational signatory\n";
    echo "\nSignature Phase 1 verification passed.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAILED] ' . $exception->getMessage() . "\n");
    exit(1);
}

