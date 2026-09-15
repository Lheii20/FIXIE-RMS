<?php

declare(strict_types=1);

require_once __DIR__ . '/e_signature.php';
require_once __DIR__ . '/storage_security.php';

if (!function_exists('drms_delivery_receipt_signature_version')) {
    function drms_delivery_receipt_signature_version(
        int $deliveryReceiptId,
        int $poId,
        int $receiptCycle
    ): string {
        if ($deliveryReceiptId < 1 || $poId < 1 || $receiptCycle < 1) {
            throw new InvalidArgumentException(
                'The delivery-completion signature version cannot be generated from incomplete references.'
            );
        }

        return 'receipt-' . substr(
            hash(
                'sha256',
                $deliveryReceiptId . '|' . $poId . '|' . $receiptCycle
            ),
            0,
            12
        );
    }
}

if (!function_exists('drms_delivery_receipt_signature_fingerprint')) {
    /**
     * Builds the immutable fingerprint for the Supply Chain delivery-
     * completion certification. Mutable collection and PO workflow statuses
     * are deliberately excluded so later payment activity cannot invalidate
     * the completed handover signature.
     */
    function drms_delivery_receipt_signature_fingerprint(
        mysqli $conn,
        int $deliveryReceiptId,
        int $recordedBy
    ): string {
        $statement = $conn->prepare(
            "SELECT
                receipt.delivery_receipt_id,
                receipt.po_id,
                receipt.receipt_cycle,
                receipt.delivery_request_id,
                receipt.delivery_plan_id,
                receipt.client_receipt_reference,
                receipt.actual_handover_at,
                receipt.acknowledgement_type,
                receipt.recipient_name,
                receipt.recipient_position,
                receipt.recipient_contact,
                receipt.expected_item_quantity,
                receipt.delivered_item_quantity,
                receipt.delivery_condition,
                receipt.discrepancy_notes,
                receipt.proof_original_name,
                receipt.proof_file_hash,
                receipt.collection_term_days,
                receipt.collection_due_date,
                receipt.recorded_by,
                receipt.created_at,
                po.po_number,
                po.client_name,
                request.request_number,
                plan.reviewed_by AS logistics_reviewed_by,
                plan.reviewed_at AS logistics_reviewed_at
             FROM po_delivery_receipts receipt
             INNER JOIN purchase_orders po
                ON po.po_id = receipt.po_id
             INNER JOIN po_delivery_requests request
                ON request.delivery_request_id = receipt.delivery_request_id
               AND request.record_status = 'Active'
             INNER JOIN po_delivery_plans plan
                ON plan.delivery_plan_id = receipt.delivery_plan_id
               AND plan.delivery_request_id = request.delivery_request_id
               AND plan.record_status = 'Active'
             WHERE receipt.delivery_receipt_id = ?
               AND receipt.recorded_by = ?
               AND receipt.record_status = 'Active'
             LIMIT 1"
        );
        $statement->bind_param('ii', $deliveryReceiptId, $recordedBy);
        $statement->execute();
        $snapshot = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (
            !$snapshot ||
            empty($snapshot['proof_file_hash']) ||
            !preg_match(
                '/^[a-f0-9]{64}$/',
                strtolower((string) $snapshot['proof_file_hash'])
            ) ||
            empty($snapshot['created_at'])
        ) {
            throw new RuntimeException(
                'The client delivery receipt could not be fingerprinted for electronic-signature verification.'
            );
        }

        $encoded = json_encode(
            [
                'module' => 'Delivery Receipt',
                'stage' => 'Delivery Completion',
                'record' => $snapshot,
            ],
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($encoded)) {
            throw new RuntimeException(
                'The client delivery receipt could not be encoded for electronic-signature verification.'
            );
        }

        return hash('sha256', $encoded);
    }
}

if (!function_exists('drms_load_delivery_receipt_signature_event')) {
    /**
     * Returns null only for a legacy receipt created before Phase 9C. A found
     * event must pass signer, fingerprint, chronology, and visual-image checks.
     */
    function drms_load_delivery_receipt_signature_event(
        mysqli $conn,
        int $deliveryReceiptId,
        int $poId,
        int $receiptCycle,
        int $recordedBy,
        ?string $createdAt = null,
        bool $forUpdate = false
    ): ?array {
        $signatureVersion = drms_delivery_receipt_signature_version(
            $deliveryReceiptId,
            $poId,
            $receiptCycle
        );
        $sql =
            "SELECT
                signature_id,
                verification_code,
                signer_user_id,
                signer_name,
                signer_role,
                signature_method,
                signed_file_hash,
                signed_version,
                consent_text,
                signed_at,
                signature_image_path,
                signature_image_hash,
                remarks
             FROM document_signature_events
             WHERE record_module = 'Delivery Receipt'
               AND record_id = ?
               AND signature_stage = 'Delivery Completion'
               AND signed_version = ?
               AND signer_user_id = ?
               AND signature_type = 'Electronic Approval'
               AND signature_status = 'Valid'
             ORDER BY signature_id DESC
             LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $statement = $conn->prepare($sql);
        $statement->bind_param(
            'isi',
            $deliveryReceiptId,
            $signatureVersion,
            $recordedBy
        );
        $statement->execute();
        $signature = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (!$signature) {
            return null;
        }
        if ((string) ($signature['signer_role'] ?? '') !== 'Supply Chain') {
            throw new RuntimeException(
                'The stored delivery-completion signature does not belong to the Supply Chain role.'
            );
        }

        $expectedFingerprint = drms_delivery_receipt_signature_fingerprint(
            $conn,
            $deliveryReceiptId,
            $recordedBy
        );
        $storedFingerprint = strtolower(
            trim((string) ($signature['signed_file_hash'] ?? ''))
        );
        if (
            !preg_match('/^[a-f0-9]{64}$/', $storedFingerprint) ||
            !hash_equals($expectedFingerprint, $storedFingerprint)
        ) {
            throw new RuntimeException(
                'The Supply Chain signature no longer matches the recorded client handover and acknowledgement proof.'
            );
        }

        if ($createdAt && strtotime($createdAt) !== false) {
            $createdTimestamp = strtotime($createdAt);
            $signedTimestamp = strtotime(
                (string) ($signature['signed_at'] ?? '')
            );
            if (
                $signedTimestamp === false ||
                $signedTimestamp < $createdTimestamp ||
                $signedTimestamp > $createdTimestamp + 120
            ) {
                throw new RuntimeException(
                    'The Supply Chain signature timestamp does not match the delivery receipt creation.'
                );
            }
        }

        $signature['verified_signature_image_path'] = null;
        $storedPath = trim(
            (string) ($signature['signature_image_path'] ?? '')
        );
        $storedHash = strtolower(
            trim((string) ($signature['signature_image_hash'] ?? ''))
        );
        if ($storedPath !== '' && preg_match('/^[a-f0-9]{64}$/', $storedHash)) {
            try {
                $absolutePath = drms_storage_resolve_existing_file($storedPath);
                $actualHash = hash_file('sha256', $absolutePath);
                $imageInfo = @getimagesize($absolutePath);
                if (
                    $actualHash !== false &&
                    hash_equals($storedHash, strtolower($actualHash)) &&
                    is_array($imageInfo) &&
                    ($imageInfo['mime'] ?? '') === 'image/png'
                ) {
                    $signature['verified_signature_image_path'] = $absolutePath;
                }
            } catch (Throwable $error) {
                error_log(
                    'Delivery-completion signature image verification skipped: ' .
                    $error->getMessage()
                );
            }
        }

        return $signature;
    }
}

