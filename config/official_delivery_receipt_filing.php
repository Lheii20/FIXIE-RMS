<?php

if (!function_exists('drms_file_existing_source_as_official_record')) {
    require_once __DIR__ . '/functions.php';
}
if (!function_exists('drms_verify_official_source_file')) {
    require_once __DIR__ . '/official_po_snapshot.php';
}

if (!function_exists('drms_file_client_delivery_receipt_as_official_record')) {
    function drms_file_client_delivery_receipt_as_official_record(
        mysqli $conn,
        int $delivery_receipt_id,
        int $declared_by
    ): array {
        if ($delivery_receipt_id < 1 || $declared_by < 1) {
            throw new InvalidArgumentException(
                'The client delivery-receipt Official Record reference is invalid.'
            );
        }

        $folder = drms_get_official_folder_profile(
            $conn,
            'Client Delivery Receipts'
        );
        if (
            (int) ($folder['is_system_folder'] ?? 0) !== 1 ||
            ($folder['system_folder_key'] ?? '') !== 'delivery_receipt' ||
            ($folder['record_prefix'] ?? '') !== 'POD'
        ) {
            throw new RuntimeException(
                'The protected Client Delivery Receipts folder is not configured correctly.'
            );
        }

        $receipt_stmt = $conn->prepare(
            "SELECT
                receipt.*,
                po.po_number,
                po.client_name,
                po.status AS po_status,
                request.request_number,
                request.request_status,
                request.record_status AS request_record_status,
                plan.logistics_status,
                plan.provider_type,
                plan.provider_name,
                plan.tracking_reference,
                plan.reviewed_by,
                plan.reviewed_at,
                plan.record_status AS plan_record_status,
                plan_record.doc_id AS plan_record_doc_id,
                plan_record.record_number AS plan_record_number,
                plan_record.file_path AS plan_record_file_path,
                plan_record.file_hash AS plan_record_file_hash,
                plan_record.record_phase AS plan_record_phase,
                plan_record.status AS plan_record_status_document,
                plan_record.is_locked AS plan_record_is_locked
             FROM po_delivery_receipts receipt
             INNER JOIN purchase_orders po
                ON po.po_id = receipt.po_id
             INNER JOIN po_delivery_requests request
                ON request.delivery_request_id = receipt.delivery_request_id
               AND request.po_id = receipt.po_id
             INNER JOIN po_delivery_plans plan
                ON plan.delivery_plan_id = receipt.delivery_plan_id
               AND plan.delivery_request_id = receipt.delivery_request_id
             LEFT JOIN documents plan_record
                ON plan_record.source_module = 'Logistics Plan'
               AND plan_record.source_record_id = plan.delivery_plan_id
             WHERE receipt.delivery_receipt_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $receipt_stmt->bind_param('i', $delivery_receipt_id);
        $receipt_stmt->execute();
        $receipt = $receipt_stmt->get_result()->fetch_assoc();
        $receipt_stmt->close();

        if (!$receipt) {
            throw new RuntimeException(
                'The client delivery-receipt source record is unavailable.'
            );
        }

        $allowed_po_statuses = ['For Pick-up/Delivery', 'Delivered'];
        $allowed_request_statuses = ['Scheduled', 'Completed'];
        $allowed_plan_statuses = ['Scheduled', 'Completed'];
        $allowed_acknowledgements = [
            'Signed Delivery Receipt',
            'Client Email Confirmation',
            'Electronic Acknowledgement',
            'Other',
        ];
        $allowed_conditions = [
            'Complete and Accepted',
            'Accepted with Noted Issue',
        ];
        if (
            ($receipt['record_status'] ?? '') !== 'Active' ||
            ($receipt['request_record_status'] ?? '') !== 'Active' ||
            ($receipt['plan_record_status'] ?? '') !== 'Active' ||
            !in_array($receipt['po_status'], $allowed_po_statuses, true) ||
            !in_array(
                $receipt['request_status'],
                $allowed_request_statuses,
                true
            ) ||
            !in_array(
                $receipt['logistics_status'],
                $allowed_plan_statuses,
                true
            ) ||
            !in_array(
                $receipt['acknowledgement_type'],
                $allowed_acknowledgements,
                true
            ) ||
            !in_array(
                $receipt['delivery_condition'],
                $allowed_conditions,
                true
            ) ||
            (int) ($receipt['receipt_cycle'] ?? 0) < 1 ||
            (int) ($receipt['recorded_by'] ?? 0) !== $declared_by ||
            (int) ($receipt['reviewed_by'] ?? 0) < 1 ||
            trim((string) ($receipt['po_number'] ?? '')) === '' ||
            trim((string) ($receipt['client_name'] ?? '')) === '' ||
            trim((string) ($receipt['request_number'] ?? '')) === '' ||
            trim((string) ($receipt['recipient_name'] ?? '')) === '' ||
            empty($receipt['actual_handover_at']) ||
            empty($receipt['reviewed_at'])
        ) {
            throw new RuntimeException(
                'The client delivery-receipt source record is incomplete or is not active.'
            );
        }

        $expected_quantity = (int) $receipt['expected_item_quantity'];
        $delivered_quantity = (int) $receipt['delivered_item_quantity'];
        if (
            $expected_quantity < 1 ||
            $delivered_quantity !== $expected_quantity
        ) {
            throw new RuntimeException(
                'The delivery receipt does not confirm the complete PO quantity.'
            );
        }
        if (
            $receipt['delivery_condition'] === 'Accepted with Noted Issue' &&
            trim((string) ($receipt['discrepancy_notes'] ?? '')) === ''
        ) {
            throw new RuntimeException(
                'The accepted delivery issue is missing from the receipt.'
            );
        }

        $handover_timestamp = strtotime(
            (string) $receipt['actual_handover_at']
        );
        $reviewed_timestamp = strtotime((string) $receipt['reviewed_at']);
        if (
            $handover_timestamp === false ||
            $reviewed_timestamp === false ||
            $handover_timestamp < $reviewed_timestamp ||
            $handover_timestamp > time() + 60
        ) {
            throw new RuntimeException(
                'The client delivery-receipt chronology is invalid.'
            );
        }

        $collection_term_days = (int) $receipt['collection_term_days'];
        $expected_due_date = (new DateTimeImmutable(
            (string) $receipt['actual_handover_at']
        ))->modify('+' . $collection_term_days . ' days')->format('Y-m-d');
        if (
            $collection_term_days !== 15 ||
            $expected_due_date !== (string) $receipt['collection_due_date']
        ) {
            throw new RuntimeException(
                'The delivery receipt does not contain the controlled 15-calendar-day collection term.'
            );
        }

        $source_plan_record = [
            'doc_id' => $receipt['plan_record_doc_id'],
            'record_number' => $receipt['plan_record_number'],
            'file_path' => $receipt['plan_record_file_path'],
            'file_hash' => $receipt['plan_record_file_hash'],
            'record_phase' => $receipt['plan_record_phase'],
            'status' => $receipt['plan_record_status_document'],
            'is_locked' => $receipt['plan_record_is_locked'],
        ];
        try {
            drms_verify_official_source_file(
                $source_plan_record,
                'The source Approved Logistics Plan'
            );
        } catch (RuntimeException $source_error) {
            throw new DomainException(
                'The Approved Logistics Plan Official Record is missing or failed integrity verification.'
            );
        }
        if (!preg_match(
            '/^LGP-\d{4}-\d{4}$/',
            (string) $receipt['plan_record_number']
        )) {
            throw new DomainException(
                'The source Approved Logistics Plan does not use its controlled folder code.'
            );
        }

        $stored_file = trim((string) $receipt['proof_file_path']);
        $normalized_stored_file = str_replace('\\', '/', $stored_file);
        if (
            $stored_file === '' ||
            basename($normalized_stored_file) !== $normalized_stored_file
        ) {
            throw new RuntimeException(
                'The client acknowledgement storage reference is invalid.'
            );
        }

        $receipt_directory = realpath(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' .
            DIRECTORY_SEPARATOR . 'delivery_receipts'
        );
        $source_absolute_path = $receipt_directory !== false
            ? realpath(
                $receipt_directory . DIRECTORY_SEPARATOR . $stored_file
            )
            : false;
        $receipt_prefix = $receipt_directory !== false
            ? rtrim($receipt_directory, DIRECTORY_SEPARATOR) .
                DIRECTORY_SEPARATOR
            : '';
        if (
            $receipt_directory === false ||
            $source_absolute_path === false ||
            !is_file($source_absolute_path) ||
            strncasecmp(
                $source_absolute_path,
                $receipt_prefix,
                strlen($receipt_prefix)
            ) !== 0
        ) {
            throw new RuntimeException(
                'The client acknowledgement file is missing from protected source storage.'
            );
        }

        $proof_hash = strtolower(trim((string) $receipt['proof_file_hash']));
        if (!preg_match('/^[a-f0-9]{64}$/', $proof_hash)) {
            throw new RuntimeException(
                'The client acknowledgement does not contain a valid integrity hash.'
            );
        }

        $original_name = trim((string) $receipt['proof_original_name']);
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
                'The client acknowledgement is not a valid PDF, JPG, or PNG file.'
            );
        }

        $business_reference = 'POD-' .
            str_pad(
                (string) (int) $receipt['po_id'],
                4,
                '0',
                STR_PAD_LEFT
            ) . '-' .
            str_pad(
                (string) (int) $receipt['receipt_cycle'],
                2,
                '0',
                STR_PAD_LEFT
            );
        $tags = array_filter([
            'client delivery receipt',
            'proof of delivery',
            'business pod ' . $business_reference,
            'business po ' . trim((string) $receipt['po_number']),
            'client ' . trim((string) $receipt['client_name']),
            'recipient ' . trim((string) $receipt['recipient_name']),
            'acknowledgement ' . trim((string) $receipt['acknowledgement_type']),
            'source ' . trim((string) $receipt['plan_record_number']),
            trim((string) ($receipt['client_receipt_reference'] ?? '')) !== ''
                ? 'client reference ' . trim(
                    (string) $receipt['client_receipt_reference']
                )
                : null,
            trim((string) ($receipt['tracking_reference'] ?? '')) !== ''
                ? 'tracking ' . trim((string) $receipt['tracking_reference'])
                : null,
        ]);

        return drms_file_existing_source_as_official_record(
            $conn,
            $source_absolute_path,
            $original_name,
            $proof_hash,
            'Client Delivery Receipts',
            'Proof of Delivery',
            $declared_by,
            (string) $receipt['actual_handover_at'],
            'Delivery Receipt',
            $delivery_receipt_id,
            $business_reference,
            (int) $receipt['recorded_by'],
            (int) $receipt['po_id'],
            implode(',', $tags)
        );
    }
}

if (!function_exists('drms_consolidate_client_delivery_receipt_source')) {
    function drms_consolidate_client_delivery_receipt_source(
        mysqli $conn,
        int $delivery_receipt_id,
        string $proof_hash,
        array $official_record,
        string $staging_absolute_path
    ): string {
        $proof_hash = strtolower(trim($proof_hash));
        $official_file_name = (string) ($official_record['file_name'] ?? '');
        $official_storage_path = (string) (
            $official_record['storage_absolute_path'] ?? ''
        );
        if (
            $delivery_receipt_id < 1 ||
            !preg_match('/^[a-f0-9]{64}$/', $proof_hash) ||
            empty($official_record['created']) ||
            $official_file_name === '' ||
            basename(str_replace('\\', '/', $official_file_name)) !==
                $official_file_name ||
            $official_storage_path === '' ||
            $staging_absolute_path === ''
        ) {
            throw new RuntimeException(
                'The delivery-proof consolidation metadata is invalid.'
            );
        }

        $project_root = realpath(dirname(__DIR__));
        $staging_directory = $project_root !== false
            ? realpath(
                $project_root . DIRECTORY_SEPARATOR . 'uploads' .
                DIRECTORY_SEPARATOR . 'delivery_receipts'
            )
            : false;
        $official_directory = $project_root !== false
            ? realpath(
                $project_root . DIRECTORY_SEPARATOR . 'uploads' .
                DIRECTORY_SEPARATOR . 'official' .
                DIRECTORY_SEPARATOR . 'pod'
            )
            : false;
        $staging_path = realpath($staging_absolute_path);
        $official_path = realpath($official_storage_path);
        $staging_prefix = $staging_directory !== false
            ? rtrim($staging_directory, DIRECTORY_SEPARATOR) .
                DIRECTORY_SEPARATOR
            : '';
        $official_prefix = $official_directory !== false
            ? rtrim($official_directory, DIRECTORY_SEPARATOR) .
                DIRECTORY_SEPARATOR
            : '';
        if (
            $project_root === false ||
            $staging_directory === false ||
            $official_directory === false ||
            $staging_path === false ||
            $official_path === false ||
            !is_file($staging_path) ||
            !is_file($official_path) ||
            strncasecmp(
                $staging_path,
                $staging_prefix,
                strlen($staging_prefix)
            ) !== 0 ||
            strncasecmp(
                $official_path,
                $official_prefix,
                strlen($official_prefix)
            ) !== 0 ||
            basename($official_path) !== $official_file_name
        ) {
            throw new RuntimeException(
                'The delivery proof is outside its controlled staging or Official Record folder.'
            );
        }

        $staging_hash = strtolower((string) hash_file(
            'sha256',
            $staging_path
        ));
        $official_hash = strtolower((string) hash_file(
            'sha256',
            $official_path
        ));
        if (
            !hash_equals($proof_hash, $staging_hash) ||
            !hash_equals($proof_hash, $official_hash)
        ) {
            throw new RuntimeException(
                'The delivery proof failed final staging-to-official integrity verification.'
            );
        }

        $official_database_path =
            'uploads/official/pod/' . $official_file_name;
        $receipt_path_stmt = $conn->prepare(
            "UPDATE po_delivery_receipts
             SET proof_file_path = ?
             WHERE delivery_receipt_id = ?
               AND proof_file_hash = ?"
        );
        $receipt_path_stmt->bind_param(
            'sis',
            $official_database_path,
            $delivery_receipt_id,
            $proof_hash
        );
        $receipt_path_stmt->execute();
        $path_updated = $receipt_path_stmt->affected_rows === 1;
        $receipt_path_stmt->close();
        if (!$path_updated) {
            throw new RuntimeException(
                'The delivery receipt could not be linked to its locked Official Record.'
            );
        }

        if (!unlink($staging_path)) {
            throw new RuntimeException(
                'The verified delivery-proof staging copy could not be consolidated.'
            );
        }

        return $official_database_path;
    }
}
