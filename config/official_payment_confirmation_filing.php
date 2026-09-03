<?php

if (!function_exists('drms_file_existing_source_as_official_record')) {
    require_once __DIR__ . '/functions.php';
}
if (!function_exists('drms_verify_official_source_file')) {
    require_once __DIR__ . '/official_po_snapshot.php';
}

if (!function_exists('drms_file_client_payment_as_official_record')) {
    function drms_file_client_payment_as_official_record(
        mysqli $conn,
        int $payment_id,
        int $declared_by
    ): array {
        if ($payment_id < 1 || $declared_by < 1) {
            throw new InvalidArgumentException(
                'The client-payment Official Record reference is invalid.'
            );
        }

        $folder = drms_get_official_folder_profile(
            $conn,
            'Client Payment Confirmations'
        );
        if (
            (int) ($folder['is_system_folder'] ?? 0) !== 1 ||
            ($folder['system_folder_key'] ?? '') !== 'payment_confirmation' ||
            ($folder['record_prefix'] ?? '') !== 'PAY'
        ) {
            throw new RuntimeException(
                'The protected Client Payment Confirmations folder is not configured correctly.'
            );
        }

        $payment_stmt = $conn->prepare(
            "SELECT
                payment.*,
                po.po_number,
                po.client_name,
                po.amount AS po_amount,
                po.status AS po_status,
                po.collection_status,
                po.date_created AS po_created_at,
                payment_total.total_paid,
                receipt.delivery_receipt_id,
                receipt.actual_handover_at,
                receipt.record_status AS receipt_record_status,
                po_record.doc_id AS po_record_doc_id,
                po_record.record_number AS po_record_number,
                po_record.file_path AS po_record_file_path,
                po_record.file_hash AS po_record_file_hash,
                po_record.record_phase AS po_record_phase,
                po_record.status AS po_record_status,
                po_record.is_locked AS po_record_is_locked,
                receipt_record.doc_id AS receipt_record_doc_id,
                receipt_record.record_number AS receipt_record_number,
                receipt_record.file_path AS receipt_record_file_path,
                receipt_record.file_hash AS receipt_record_file_hash,
                receipt_record.record_phase AS receipt_record_phase,
                receipt_record.status AS receipt_record_status_document,
                receipt_record.is_locked AS receipt_record_is_locked
             FROM payments payment
             INNER JOIN purchase_orders po
                ON po.po_id = payment.po_id
             INNER JOIN (
                SELECT po_id, SUM(amount_paid) AS total_paid
                FROM payments
                GROUP BY po_id
             ) payment_total
                ON payment_total.po_id = payment.po_id
             LEFT JOIN po_delivery_receipts receipt
                ON receipt.delivery_receipt_id = (
                    SELECT MAX(latest_receipt.delivery_receipt_id)
                    FROM po_delivery_receipts latest_receipt
                    WHERE latest_receipt.po_id = payment.po_id
                      AND latest_receipt.record_status = 'Active'
                )
             LEFT JOIN documents po_record
                ON po_record.source_module = 'Internal Purchase Order'
               AND po_record.source_record_id = payment.po_id
               AND po_record.record_phase = 'Official'
               AND po_record.status <> 'Recycled'
               AND po_record.is_locked = 1
             LEFT JOIN documents receipt_record
                ON receipt_record.source_module = 'Delivery Receipt'
               AND receipt_record.source_record_id =
                    receipt.delivery_receipt_id
               AND receipt_record.record_phase = 'Official'
               AND receipt_record.status <> 'Recycled'
               AND receipt_record.is_locked = 1
             WHERE payment.payment_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        $payment_stmt->bind_param('i', $payment_id);
        $payment_stmt->execute();
        $payment = $payment_stmt->get_result()->fetch_assoc();
        $payment_stmt->close();

        if (!$payment) {
            throw new RuntimeException(
                'The client-payment source record is unavailable.'
            );
        }

        $allowed_po_statuses = [
            'President-Approved',
            'Funded',
            'Delivery Requested',
            'For Pick-up/Delivery',
            'Delivered',
        ];
        $allowed_classifications = [
            'Full Payment',
            'Partial Payment',
            'Advance / Down Payment',
        ];
        $allowed_methods = [
            'Cash',
            'Bank Transfer',
            'GCash',
            'Cheque',
            'Other',
        ];
        if (
            !in_array($payment['po_status'], $allowed_po_statuses, true) ||
            !in_array(
                $payment['payment_classification'],
                $allowed_classifications,
                true
            ) ||
            !in_array($payment['payment_method'], $allowed_methods, true) ||
            (int) ($payment['recorded_by'] ?? 0) !== $declared_by ||
            round((float) ($payment['amount_paid'] ?? 0), 2) <= 0 ||
            trim((string) ($payment['reference_number'] ?? '')) === '' ||
            trim((string) ($payment['po_number'] ?? '')) === '' ||
            trim((string) ($payment['client_name'] ?? '')) === '' ||
            empty($payment['payment_date']) ||
            empty($payment['po_created_at'])
        ) {
            throw new RuntimeException(
                'The client-payment source record is incomplete or is not eligible.'
            );
        }

        $payment_timestamp = strtotime((string) $payment['payment_date']);
        $po_created_timestamp = strtotime((string) $payment['po_created_at']);
        if (
            $payment_timestamp === false ||
            $po_created_timestamp === false ||
            $payment_timestamp < $po_created_timestamp ||
            $payment_timestamp > time() + 60
        ) {
            throw new RuntimeException(
                'The client-payment chronology is invalid.'
            );
        }

        $po_amount = round((float) $payment['po_amount'], 2);
        $total_paid = round((float) $payment['total_paid'], 2);
        if (
            $po_amount <= 0 ||
            $total_paid <= 0 ||
            $total_paid > $po_amount + 0.01
        ) {
            throw new RuntimeException(
                'The client-payment amount does not match the controlled PO balance.'
            );
        }

        $source_po_record = [
            'doc_id' => $payment['po_record_doc_id'],
            'record_number' => $payment['po_record_number'],
            'file_path' => $payment['po_record_file_path'],
            'file_hash' => $payment['po_record_file_hash'],
            'record_phase' => $payment['po_record_phase'],
            'status' => $payment['po_record_status'],
            'is_locked' => $payment['po_record_is_locked'],
        ];
        try {
            drms_verify_official_source_file(
                $source_po_record,
                'The source Internal Purchase Order'
            );
        } catch (RuntimeException $source_error) {
            throw new DomainException(
                'The Internal Purchase Order Official Record is missing or failed integrity verification.'
            );
        }
        if (!preg_match(
            '/^PO-\d{4}-\d{4}$/',
            (string) $payment['po_record_number']
        )) {
            throw new DomainException(
                'The source Internal Purchase Order does not use its controlled folder code.'
            );
        }

        $delivery_timestamp = !empty($payment['actual_handover_at'])
            ? strtotime((string) $payment['actual_handover_at'])
            : false;
        $is_advance =
            $payment['payment_classification'] === 'Advance / Down Payment';
        $is_before_delivery =
            $payment['po_status'] !== 'Delivered' ||
            $delivery_timestamp === false ||
            $payment_timestamp <= $delivery_timestamp;
        if ($is_advance && !$is_before_delivery) {
            throw new RuntimeException(
                'The payment is classified as an advance but was received after delivery.'
            );
        }
        if (!$is_advance && (
            $payment['po_status'] !== 'Delivered' ||
            $delivery_timestamp === false ||
            $payment_timestamp <= $delivery_timestamp ||
            ($payment['receipt_record_status'] ?? '') !== 'Active'
        )) {
            throw new RuntimeException(
                'A partial or full payment requires a completed client delivery record.'
            );
        }

        $source_receipt_number = '';
        if (!$is_advance) {
            $source_receipt_record = [
                'doc_id' => $payment['receipt_record_doc_id'],
                'record_number' => $payment['receipt_record_number'],
                'file_path' => $payment['receipt_record_file_path'],
                'file_hash' => $payment['receipt_record_file_hash'],
                'record_phase' => $payment['receipt_record_phase'],
                'status' => $payment['receipt_record_status_document'],
                'is_locked' => $payment['receipt_record_is_locked'],
            ];
            try {
                drms_verify_official_source_file(
                    $source_receipt_record,
                    'The source Proof of Delivery'
                );
            } catch (RuntimeException $source_error) {
                throw new DomainException(
                    'The Proof of Delivery Official Record is missing or failed integrity verification.'
                );
            }
            $source_receipt_number = (string) $payment['receipt_record_number'];
            if (!preg_match(
                '/^POD-\d{4}-\d{4}$/',
                $source_receipt_number
            )) {
                throw new DomainException(
                    'The source Proof of Delivery does not use its controlled folder code.'
                );
            }
        }

        $stored_file = trim((string) $payment['proof_file_path']);
        $normalized_stored_file = str_replace('\\', '/', $stored_file);
        if (
            $stored_file === '' ||
            basename($normalized_stored_file) !== $normalized_stored_file
        ) {
            throw new RuntimeException(
                'The payment-proof storage reference is invalid.'
            );
        }

        $payment_directory = realpath(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' .
            DIRECTORY_SEPARATOR . 'payments'
        );
        $source_absolute_path = $payment_directory !== false
            ? realpath(
                $payment_directory . DIRECTORY_SEPARATOR . $stored_file
            )
            : false;
        $payment_prefix = $payment_directory !== false
            ? rtrim($payment_directory, DIRECTORY_SEPARATOR) .
                DIRECTORY_SEPARATOR
            : '';
        if (
            $payment_directory === false ||
            $source_absolute_path === false ||
            !is_file($source_absolute_path) ||
            strncasecmp(
                $source_absolute_path,
                $payment_prefix,
                strlen($payment_prefix)
            ) !== 0
        ) {
            throw new RuntimeException(
                'The payment proof is missing from protected source storage.'
            );
        }

        $proof_hash = strtolower(trim((string) $payment['proof_file_hash']));
        if (!preg_match('/^[a-f0-9]{64}$/', $proof_hash)) {
            throw new RuntimeException(
                'The payment proof does not contain a valid integrity hash.'
            );
        }
        $original_name = trim((string) $payment['proof_original_name']);
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
                'The payment proof is not a valid PDF, JPG, or PNG file.'
            );
        }

        $tags = array_filter([
            'client payment confirmation',
            strtolower((string) $payment['payment_classification']),
            'business po ' . trim((string) $payment['po_number']),
            'client ' . trim((string) $payment['client_name']),
            'payment reference ' . trim((string) $payment['reference_number']),
            'payment method ' . trim((string) $payment['payment_method']),
            'source ' . trim((string) $payment['po_record_number']),
            $source_receipt_number !== ''
                ? 'source ' . $source_receipt_number
                : null,
        ]);

        return drms_file_existing_source_as_official_record(
            $conn,
            $source_absolute_path,
            $original_name,
            $proof_hash,
            'Client Payment Confirmations',
            'Client Payment Confirmation',
            $declared_by,
            (string) $payment['payment_date'],
            'Client Payment',
            $payment_id,
            (string) $payment['reference_number'],
            (int) $payment['recorded_by'],
            (int) $payment['po_id'],
            implode(',', $tags)
        );
    }
}

if (!function_exists('drms_consolidate_client_payment_source')) {
    function drms_consolidate_client_payment_source(
        mysqli $conn,
        int $payment_id,
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
            $payment_id < 1 ||
            !preg_match('/^[a-f0-9]{64}$/', $proof_hash) ||
            empty($official_record['created']) ||
            $official_file_name === '' ||
            basename(str_replace('\\', '/', $official_file_name)) !==
                $official_file_name ||
            $official_storage_path === '' ||
            $staging_absolute_path === ''
        ) {
            throw new RuntimeException(
                'The payment-proof consolidation metadata is invalid.'
            );
        }

        $project_root = realpath(dirname(__DIR__));
        $staging_directory = $project_root !== false
            ? realpath(
                $project_root . DIRECTORY_SEPARATOR . 'uploads' .
                DIRECTORY_SEPARATOR . 'payments'
            )
            : false;
        $official_directory = $project_root !== false
            ? realpath(
                $project_root . DIRECTORY_SEPARATOR . 'uploads' .
                DIRECTORY_SEPARATOR . 'official' .
                DIRECTORY_SEPARATOR . 'pay'
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
                'The payment proof is outside its controlled staging or Official Record folder.'
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
                'The payment proof failed final staging-to-official integrity verification.'
            );
        }

        $official_database_path =
            'uploads/official/pay/' . $official_file_name;
        $payment_path_stmt = $conn->prepare(
            "UPDATE payments
             SET proof_file_path = ?
             WHERE payment_id = ?
               AND proof_file_hash = ?"
        );
        $payment_path_stmt->bind_param(
            'sis',
            $official_database_path,
            $payment_id,
            $proof_hash
        );
        $payment_path_stmt->execute();
        $path_updated = $payment_path_stmt->affected_rows === 1;
        $payment_path_stmt->close();
        if (!$path_updated) {
            throw new RuntimeException(
                'The client payment could not be linked to its locked Official Record.'
            );
        }

        if (!unlink($staging_path)) {
            throw new RuntimeException(
                'The verified payment-proof staging copy could not be consolidated.'
            );
        }

        return $official_database_path;
    }
}
