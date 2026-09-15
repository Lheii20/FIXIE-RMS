<?php

declare(strict_types=1);

require_once __DIR__ . '/e_signature.php';
require_once __DIR__ . '/storage_security.php';

if (!function_exists('drms_delivery_signature_version')) {
    function drms_delivery_signature_version(
        int $deliveryRequestId,
        int $deliveryPlanId,
        string $requestNumber
    ): string {
        if ($deliveryRequestId < 1 || $deliveryPlanId < 1 || trim($requestNumber) === '') {
            throw new InvalidArgumentException(
                'The delivery approval version cannot be generated from incomplete references.'
            );
        }

        return 'delivery-' . substr(
            hash(
                'sha256',
                $deliveryRequestId . '|' . $deliveryPlanId . '|' . $requestNumber
            ),
            0,
            11
        );
    }
}

if (!function_exists('drms_delivery_signature_fingerprint')) {
    /**
     * Returns the canonical fingerprint used by the Supply Chain approval.
     * Completed or dispatched records are normalized back to their signed
     * Scheduled state because later execution must not invalidate the approval.
     */
    function drms_delivery_signature_fingerprint(
        mysqli $conn,
        int $deliveryRequestId,
        int $deliveryPlanId,
        int $reviewerId
    ): string {
        $statement = $conn->prepare(
            "SELECT
                request.delivery_request_id,
                request.request_number,
                request.request_cycle,
                request.request_type,
                request.supplier_name_snapshot,
                request.supplier_ready_confirmed_at,
                request.supplier_confirmation_reference,
                request.supplier_contact_name,
                request.supplier_contact_number,
                request.supplier_contact_email,
                request.pickup_address,
                request.delivery_address,
                request.preferred_pickup_at,
                request.preferred_delivery_at,
                request.package_count,
                request.handling_instructions,
                request.procurement_remarks,
                request.request_status,
                request.prepared_by,
                request.submitted_at,
                plan.delivery_plan_id,
                plan.logistics_status,
                plan.provider_type,
                plan.provider_name,
                plan.planned_pickup_at,
                plan.planned_delivery_at,
                plan.driver_name,
                plan.driver_contact_number,
                plan.vehicle_type,
                plan.vehicle_plate_number,
                plan.tracking_reference,
                plan.route_or_plot_notes,
                plan.reviewed_by,
                plan.reviewed_at,
                po.po_id,
                po.po_number,
                po.client_name
             FROM po_delivery_requests request
             INNER JOIN po_delivery_plans plan
                ON plan.delivery_request_id = request.delivery_request_id
             INNER JOIN purchase_orders po
                ON po.po_id = request.po_id
             WHERE request.delivery_request_id = ?
               AND plan.delivery_plan_id = ?
               AND plan.reviewed_by = ?
               AND request.record_status = 'Active'
               AND plan.record_status = 'Active'
             LIMIT 1"
        );
        $statement->bind_param(
            'iii',
            $deliveryRequestId,
            $deliveryPlanId,
            $reviewerId
        );
        $statement->execute();
        $snapshot = $statement->get_result()->fetch_assoc();
        $statement->close();

        $allowedRequestStatuses = ['Scheduled', 'Completed'];
        $allowedPlanStatuses = ['Scheduled', 'Dispatched', 'Completed'];
        if (
            !$snapshot ||
            !in_array(
                (string) ($snapshot['request_status'] ?? ''),
                $allowedRequestStatuses,
                true
            ) ||
            !in_array(
                (string) ($snapshot['logistics_status'] ?? ''),
                $allowedPlanStatuses,
                true
            ) ||
            empty($snapshot['reviewed_at'])
        ) {
            throw new RuntimeException(
                'The approved delivery and logistics data could not be fingerprinted for electronic-signature verification.'
            );
        }

        // These two fields were both Scheduled when the approval was signed.
        // Subsequent execution changes only their operational state.
        $snapshot['request_status'] = 'Scheduled';
        $snapshot['logistics_status'] = 'Scheduled';

        $encoded = json_encode(
            [
                'module' => 'Delivery Request',
                'stage' => 'Supply Chain Approval',
                'record' => $snapshot,
            ],
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($encoded)) {
            throw new RuntimeException(
                'The approved delivery and logistics data could not be encoded for electronic-signature verification.'
            );
        }

        return hash('sha256', $encoded);
    }
}

if (!function_exists('drms_load_delivery_signature_event')) {
    /**
     * Loads the latest valid Supply Chain signature and verifies that it still
     * matches the signed approval data. A missing event represents a legacy
     * pre-electronic-signature record and returns null.
     */
    function drms_load_delivery_signature_event(
        mysqli $conn,
        int $deliveryRequestId,
        int $deliveryPlanId,
        int $reviewerId,
        string $requestNumber,
        ?string $reviewedAt = null,
        bool $forUpdate = false
    ): ?array {
        $signatureVersion = drms_delivery_signature_version(
            $deliveryRequestId,
            $deliveryPlanId,
            $requestNumber
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
             WHERE record_module = 'Delivery Request'
               AND record_id = ?
               AND signature_stage = 'Supply Chain Approval'
               AND signed_version = ?
               AND signer_user_id = ?
               AND signature_type = 'Electronic Approval'
               AND signature_status = 'Valid'
             ORDER BY signature_id DESC
             LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '');
        $statement = $conn->prepare($sql);
        $statement->bind_param(
            'isi',
            $deliveryRequestId,
            $signatureVersion,
            $reviewerId
        );
        $statement->execute();
        $signature = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (!$signature) {
            return null;
        }
        if ((string) ($signature['signer_role'] ?? '') !== 'Supply Chain') {
            throw new RuntimeException(
                'The stored delivery approval does not belong to the Supply Chain role.'
            );
        }

        $expectedFingerprint = drms_delivery_signature_fingerprint(
            $conn,
            $deliveryRequestId,
            $deliveryPlanId,
            $reviewerId
        );
        $storedFingerprint = strtolower(trim((string) $signature['signed_file_hash']));
        if (
            !preg_match('/^[a-f0-9]{64}$/', $storedFingerprint) ||
            !hash_equals($expectedFingerprint, $storedFingerprint)
        ) {
            throw new RuntimeException(
                'The Supply Chain signature no longer matches the approved Delivery Request and Logistics Plan.'
            );
        }

        if ($reviewedAt && strtotime($reviewedAt) !== false) {
            $reviewedTimestamp = strtotime($reviewedAt);
            $signedTimestamp = strtotime((string) ($signature['signed_at'] ?? ''));
            if (
                $signedTimestamp === false ||
                $signedTimestamp < $reviewedTimestamp ||
                $signedTimestamp > $reviewedTimestamp + 120
            ) {
                throw new RuntimeException(
                    'The Supply Chain signature timestamp does not match the logistics approval.'
                );
            }
        }

        $signature['verified_signature_image_path'] = null;
        $storedPath = trim((string) ($signature['signature_image_path'] ?? ''));
        $storedHash = strtolower(trim((string) ($signature['signature_image_hash'] ?? '')));
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
                    'Delivery approval signature image verification skipped: ' .
                    $error->getMessage()
                );
            }
        }

        return $signature;
    }
}

