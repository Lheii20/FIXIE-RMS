<?php

declare(strict_types=1);

require_once __DIR__ . '/e_signature.php';
require_once __DIR__ . '/storage_security.php';

if (!function_exists('drms_payment_signature_version')) {
    function drms_payment_signature_version(
        int $paymentId,
        int $poId
    ): string {
        if ($paymentId < 1 || $poId < 1) {
            throw new InvalidArgumentException(
                'The payment signature version cannot be generated from incomplete references.'
            );
        }

        return 'payment-' . substr(
            hash('sha256', $paymentId . '|' . $poId),
            0,
            12
        );
    }
}

if (!function_exists('drms_payment_signature_hash_snapshot')) {
    function drms_payment_signature_hash_snapshot(array $snapshot): string
    {
        $proofHash = strtolower(
            trim((string) ($snapshot['proof_file_hash'] ?? ''))
        );
        if (
            (int) ($snapshot['payment_id'] ?? 0) < 1 ||
            (int) ($snapshot['po_id'] ?? 0) < 1 ||
            (int) ($snapshot['recorded_by'] ?? 0) < 1 ||
            !preg_match('/^[a-f0-9]{64}$/', $proofHash) ||
            empty($snapshot['created_at'])
        ) {
            throw new RuntimeException(
                'The client payment could not be fingerprinted for electronic-signature verification.'
            );
        }

        // Preserve the exact key order used by Signature Phase 9D1.
        $record = [];
        foreach ([
            'payment_id',
            'po_id',
            'amount_paid',
            'payment_date',
            'notes',
            'payment_classification',
            'recorded_by',
            'created_at',
            'payment_method',
            'reference_number',
            'proof_original_name',
            'proof_file_hash',
            'po_number',
            'client_name',
            'po_amount',
        ] as $key) {
            $record[$key] = $key === 'proof_file_hash'
                ? $proofHash
                : ($snapshot[$key] ?? null);
        }

        $encoded = json_encode(
            [
                'module' => 'Client Payment Confirmation',
                'stage' => 'Finance Verification',
                'record' => $record,
            ],
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($encoded)) {
            throw new RuntimeException(
                'The client payment could not be encoded for electronic-signature verification.'
            );
        }

        return hash('sha256', $encoded);
    }
}

if (!function_exists('drms_payment_signature_fingerprint')) {
    /**
     * Creates the Finance signature fingerprint from immutable payment data
     * and the exact proof hash. The storage path is excluded because the
     * verified source is consolidated into protected Official Record storage
     * after filing.
     */
    function drms_payment_signature_fingerprint(
        mysqli $conn,
        int $paymentId,
        int $recordedBy
    ): string {
        $statement = $conn->prepare(
            "SELECT
                payment.payment_id,
                payment.po_id,
                payment.amount_paid,
                payment.payment_date,
                payment.notes,
                payment.payment_classification,
                payment.recorded_by,
                payment.created_at,
                payment.payment_method,
                payment.reference_number,
                payment.proof_original_name,
                payment.proof_file_hash,
                po.po_number,
                po.client_name,
                po.amount AS po_amount
             FROM payments payment
             INNER JOIN purchase_orders po
                ON po.po_id = payment.po_id
             WHERE payment.payment_id = ?
               AND payment.recorded_by = ?
             LIMIT 1"
        );
        $statement->bind_param('ii', $paymentId, $recordedBy);
        $statement->execute();
        $snapshot = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (!$snapshot) {
            throw new RuntimeException(
                'The client payment could not be fingerprinted for electronic-signature verification.'
            );
        }

        return drms_payment_signature_hash_snapshot($snapshot);
    }
}

if (!function_exists('drms_payment_signature_evidence_map')) {
    /**
     * Loads and verifies the latest Finance signature for one or more payment
     * rows in two database queries at most. Missing events are legacy records;
     * mismatched events are returned as invalid without hiding other rows.
     */
    function drms_payment_signature_evidence_map(
        mysqli $conn,
        array $paymentIds
    ): array {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $paymentIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $evidence = [];
        foreach ($ids as $id) {
            $evidence[$id] = [
                'state' => 'legacy',
                'event' => null,
            ];
        }

        $idList = implode(',', $ids);
        $result = $conn->query(
            "SELECT
                event.signature_id,
                event.verification_code,
                event.record_id,
                event.official_document_id,
                event.signer_user_id,
                event.signer_name,
                event.signer_role,
                event.signature_type,
                event.signature_status,
                event.signature_method,
                event.signed_file_hash,
                event.signed_version,
                event.consent_text,
                event.signed_at,
                event.signature_image_path,
                event.signature_image_hash,
                event.remarks,
                payment.payment_id,
                payment.po_id,
                payment.amount_paid,
                payment.payment_date,
                payment.notes,
                payment.payment_classification,
                payment.recorded_by,
                payment.created_at,
                payment.payment_method,
                payment.reference_number,
                payment.proof_original_name,
                payment.proof_file_hash,
                po.po_number,
                po.client_name,
                po.amount AS po_amount
             FROM document_signature_events event
             INNER JOIN payments payment
                ON payment.payment_id = event.record_id
             INNER JOIN purchase_orders po
                ON po.po_id = payment.po_id
             WHERE event.record_module = 'Client Payment Confirmation'
               AND event.signature_stage = 'Finance Verification'
               AND event.record_id IN ({$idList})
             ORDER BY event.signature_id DESC"
        );

        while ($row = $result->fetch_assoc()) {
            $paymentId = (int) $row['payment_id'];
            if (
                !isset($evidence[$paymentId]) ||
                $evidence[$paymentId]['event'] !== null
            ) {
                continue;
            }

            try {
                $expectedVersion = drms_payment_signature_version(
                    $paymentId,
                    (int) $row['po_id']
                );
                if (
                    (string) $row['signature_type'] !== 'Electronic Approval' ||
                    (string) $row['signature_status'] !== 'Valid' ||
                    (string) $row['signer_role'] !== 'Finance' ||
                    (int) $row['signer_user_id'] !==
                        (int) $row['recorded_by'] ||
                    !hash_equals(
                        $expectedVersion,
                        (string) $row['signed_version']
                    )
                ) {
                    throw new RuntimeException(
                        'The payment signature authority or version does not match the payment record.'
                    );
                }

                $expectedHash = drms_payment_signature_hash_snapshot($row);
                $storedHash = strtolower(
                    trim((string) $row['signed_file_hash'])
                );
                if (
                    !preg_match('/^[a-f0-9]{64}$/', $storedHash) ||
                    !hash_equals($expectedHash, $storedHash)
                ) {
                    throw new RuntimeException(
                        'The Finance signature no longer matches the recorded payment and proof.'
                    );
                }

                $createdTimestamp = strtotime((string) $row['created_at']);
                $signedTimestamp = strtotime((string) $row['signed_at']);
                if (
                    $createdTimestamp === false ||
                    $signedTimestamp === false ||
                    $signedTimestamp < $createdTimestamp ||
                    $signedTimestamp > $createdTimestamp + 120
                ) {
                    throw new RuntimeException(
                        'The Finance signature timestamp does not match the payment creation.'
                    );
                }

                $row['verified_signature_image_path'] = null;
                $storedPath = trim(
                    (string) ($row['signature_image_path'] ?? '')
                );
                $imageHash = strtolower(
                    trim((string) ($row['signature_image_hash'] ?? ''))
                );
                if (
                    $storedPath !== '' &&
                    preg_match('/^[a-f0-9]{64}$/', $imageHash)
                ) {
                    try {
                        $absolutePath =
                            drms_storage_resolve_existing_file($storedPath);
                        $actualHash = hash_file('sha256', $absolutePath);
                        $imageInfo = @getimagesize($absolutePath);
                        if (
                            $actualHash !== false &&
                            hash_equals($imageHash, strtolower($actualHash)) &&
                            is_array($imageInfo) &&
                            ($imageInfo['mime'] ?? '') === 'image/png'
                        ) {
                            $row['verified_signature_image_path'] =
                                $absolutePath;
                        }
                    } catch (Throwable $imageError) {
                        error_log(
                            'Payment signature image verification skipped: ' .
                            $imageError->getMessage()
                        );
                    }
                }

                $evidence[$paymentId] = [
                    'state' => 'verified',
                    'event' => $row,
                ];
            } catch (Throwable $verificationError) {
                error_log(
                    'Payment signature verification failed for payment ' .
                    $paymentId . ': ' . $verificationError->getMessage()
                );
                $evidence[$paymentId] = [
                    'state' => 'invalid',
                    'event' => [
                        'signature_id' => (int) $row['signature_id'],
                    ],
                ];
            }
        }

        return $evidence;
    }
}

if (!function_exists('drms_payment_signature_evidence')) {
    function drms_payment_signature_evidence(
        mysqli $conn,
        int $paymentId
    ): array {
        $map = drms_payment_signature_evidence_map($conn, [$paymentId]);

        return $map[$paymentId] ?? [
            'state' => 'legacy',
            'event' => null,
        ];
    }
}

