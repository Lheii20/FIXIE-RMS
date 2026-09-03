<?php

if (!function_exists('drms_file_existing_source_as_official_record')) {
    require_once __DIR__ . '/functions.php';
}
if (!function_exists('drms_verify_official_source_file')) {
    require_once __DIR__ . '/official_po_snapshot.php';
}

if (!function_exists('drms_file_supplier_fund_release_as_official_record')) {
    function drms_file_supplier_fund_release_as_official_record(
        mysqli $conn,
        int $fund_release_id,
        int $declared_by
    ): array {
        if ($fund_release_id < 1 || $declared_by < 1) {
            throw new InvalidArgumentException(
                'The supplier fund-release Official Record reference is invalid.'
            );
        }

        $folder = drms_get_official_folder_profile(
            $conn,
            'Supplier Fund Release Proofs'
        );
        if (
            (int) ($folder['is_system_folder'] ?? 0) !== 1 ||
            ($folder['system_folder_key'] ?? '') !== 'supplier_fund_release' ||
            ($folder['record_prefix'] ?? '') !== 'FRP'
        ) {
            throw new RuntimeException(
                'The protected Supplier Fund Release Proofs folder is not configured correctly.'
            );
        }

        $release_stmt = $conn->prepare(
            "SELECT
                funding.*,
                po.po_number,
                po.status AS po_status,
                po.requested_fund_amount AS po_requested_fund_amount,
                po.supplier_detail_id AS po_supplier_detail_id,
                supplier.supplier_name,
                supplier.payment_method AS approved_payment_method,
                po_record.doc_id AS po_record_doc_id,
                po_record.record_number AS po_record_number,
                po_record.file_path AS po_record_file_path,
                po_record.file_hash AS po_record_file_hash,
                po_record.record_phase AS po_record_phase,
                po_record.status AS po_record_status,
                po_record.is_locked AS po_record_is_locked
             FROM po_supplier_fund_releases funding
             INNER JOIN purchase_orders po
               ON po.po_id = funding.po_id
             INNER JOIN pr_supplier_details supplier
               ON supplier.supplier_detail_id = funding.supplier_detail_id
              AND supplier.supplier_detail_id = po.supplier_detail_id
              AND supplier.record_status = 'Active'
             LEFT JOIN documents po_record
               ON po_record.source_module = 'Internal Purchase Order'
              AND po_record.source_record_id = po.po_id
             WHERE funding.fund_release_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $release_stmt->bind_param('i', $fund_release_id);
        $release_stmt->execute();
        $release = $release_stmt->get_result()->fetch_assoc();
        $release_stmt->close();

        if (!$release) {
            throw new RuntimeException(
                'The supplier fund-release source record is unavailable.'
            );
        }

        $official_po_statuses = [
            'Funded',
            'Delivery Requested',
            'For Pick-up/Delivery',
            'Delivered',
            'Partially-Collected',
            'Collected',
        ];
        if (
            ($release['record_status'] ?? '') !== 'Active' ||
            !in_array($release['po_status'], $official_po_statuses, true) ||
            (int) ($release['release_cycle'] ?? 0) < 1 ||
            (int) ($release['released_by'] ?? 0) !== $declared_by ||
            empty($release['released_at']) ||
            trim((string) ($release['po_number'] ?? '')) === '' ||
            trim((string) ($release['supplier_name'] ?? '')) === '' ||
            trim((string) ($release['reference_number'] ?? '')) === ''
        ) {
            throw new RuntimeException(
                'The supplier fund-release source record is incomplete or is not active.'
            );
        }

        $approved_amount = round(
            (float) $release['approved_requested_fund_amount'],
            2
        );
        $released_amount = round((float) $release['released_amount'], 2);
        $po_requested_amount = round(
            (float) $release['po_requested_fund_amount'],
            2
        );
        if (
            $approved_amount <= 0 ||
            abs($approved_amount - $released_amount) > 0.01 ||
            abs($approved_amount - $po_requested_amount) > 0.01 ||
            (int) $release['supplier_detail_id'] !==
                (int) $release['po_supplier_detail_id'] ||
            (string) $release['release_method'] !==
                (string) $release['approved_payment_method']
        ) {
            throw new RuntimeException(
                'The fund-release evidence does not match the authorized Internal PO funding details.'
            );
        }

        $source_po_record = [
            'doc_id' => $release['po_record_doc_id'],
            'record_number' => $release['po_record_number'],
            'file_path' => $release['po_record_file_path'],
            'file_hash' => $release['po_record_file_hash'],
            'record_phase' => $release['po_record_phase'],
            'status' => $release['po_record_status'],
            'is_locked' => $release['po_record_is_locked'],
        ];
        drms_verify_official_source_file(
            $source_po_record,
            'The source Internal PO'
        );
        if (!preg_match(
            '/^PO-\d{4}-\d{4}$/',
            (string) $release['po_record_number']
        )) {
            throw new RuntimeException(
                'The source Internal PO does not use its controlled folder code.'
            );
        }

        $stored_file = trim((string) $release['proof_file_path']);
        $normalized_stored_file = str_replace('\\', '/', $stored_file);
        if (
            $stored_file === '' ||
            basename($normalized_stored_file) !== $normalized_stored_file
        ) {
            throw new RuntimeException(
                'The fund-release proof storage reference is invalid.'
            );
        }

        $funding_directory = realpath(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' .
            DIRECTORY_SEPARATOR . 'fund_releases'
        );
        $source_absolute_path = $funding_directory !== false
            ? realpath(
                $funding_directory . DIRECTORY_SEPARATOR . $stored_file
            )
            : false;
        $funding_prefix = $funding_directory !== false
            ? rtrim($funding_directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            : '';
        if (
            $funding_directory === false ||
            $source_absolute_path === false ||
            !is_file($source_absolute_path) ||
            strncasecmp(
                $source_absolute_path,
                $funding_prefix,
                strlen($funding_prefix)
            ) !== 0
        ) {
            throw new RuntimeException(
                'The fund-release proof file is missing from protected source storage.'
            );
        }

        $proof_hash = strtolower(trim((string) $release['proof_file_hash']));
        if (!preg_match('/^[a-f0-9]{64}$/', $proof_hash)) {
            throw new RuntimeException(
                'The fund-release proof does not contain a valid integrity hash.'
            );
        }

        $original_name = trim((string) $release['proof_original_name']);
        if ($original_name === '') {
            $original_name = basename($source_absolute_path);
        }
        $original_extension = strtolower((string) pathinfo(
            basename($original_name),
            PATHINFO_EXTENSION
        ));
        $allowed_proof_types = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
        ];
        $actual_mime = (new finfo(FILEINFO_MIME_TYPE))->file(
            $source_absolute_path
        );
        if (
            !isset($allowed_proof_types[$original_extension]) ||
            $actual_mime !== $allowed_proof_types[$original_extension]
        ) {
            throw new RuntimeException(
                'The fund-release proof is not a valid PDF, JPG, or PNG file.'
            );
        }

        $tags = array_filter([
            'supplier fund release',
            'business po ' . trim((string) $release['po_number']),
            'supplier ' . trim((string) $release['supplier_name']),
            'release cycle ' . (int) $release['release_cycle'],
            'payment reference ' . trim((string) $release['reference_number']),
            'source ' . trim((string) $release['po_record_number']),
        ]);

        return drms_file_existing_source_as_official_record(
            $conn,
            $source_absolute_path,
            $original_name,
            $proof_hash,
            'Supplier Fund Release Proofs',
            'Supplier Fund Release Proof',
            $declared_by,
            (string) $release['released_at'],
            'Supplier Fund Release',
            $fund_release_id,
            (string) $release['reference_number'],
            (int) $release['released_by'],
            (int) $release['po_id'],
            implode(',', $tags)
        );
    }
}
