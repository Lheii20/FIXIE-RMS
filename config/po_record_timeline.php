<?php

/**
 * Build one read-only timeline for the complete business record connected to a
 * purchase order. This helper never creates, updates, or deletes records.
 */

if (!function_exists('drms_po_timeline_text')) {
    function drms_po_timeline_text($value, $limit = 260)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $limit, '…', 'UTF-8');
        }

        return strlen($text) > $limit
            ? substr($text, 0, max(0, $limit - 3)) . '...'
            : $text;
    }
}

if (!function_exists('drms_po_timeline_detail')) {
    function drms_po_timeline_detail(array $parts)
    {
        $clean = [];
        foreach ($parts as $part) {
            $part = drms_po_timeline_text($part);
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        return implode(' · ', $clean);
    }
}

if (!function_exists('drms_po_timeline_money')) {
    function drms_po_timeline_money($amount)
    {
        return '₱' . number_format((float) $amount, 2);
    }
}

if (!function_exists('drms_po_timeline_add')) {
    function drms_po_timeline_add(array &$events, array $event)
    {
        $occurred_at = trim((string) ($event['occurred_at'] ?? ''));
        if ($occurred_at === '' || strtotime($occurred_at) === false) {
            return;
        }

        $allowed_tones = [
            'primary',
            'success',
            'warning',
            'danger',
            'info',
            'secondary',
        ];
        $tone = (string) ($event['tone'] ?? 'secondary');
        if (!in_array($tone, $allowed_tones, true)) {
            $tone = 'secondary';
        }

        $events[] = [
            'event_key' => (string) ($event['event_key'] ?? uniqid('event_', true)),
            'occurred_at' => $occurred_at,
            'category' => drms_po_timeline_text($event['category'] ?? 'Record'),
            'title' => drms_po_timeline_text($event['title'] ?? 'Record updated'),
            'detail' => drms_po_timeline_text($event['detail'] ?? ''),
            'actor' => drms_po_timeline_text($event['actor'] ?? 'System'),
            'tone' => $tone,
            'icon' => preg_replace(
                '/[^a-z0-9-]/i',
                '',
                (string) ($event['icon'] ?? 'fa-circle')
            ),
            'source' => (string) ($event['source'] ?? 'record'),
            'status_to' => (string) ($event['status_to'] ?? ''),
            'milestone_status' => (string) ($event['milestone_status'] ?? ''),
        ];
    }
}

if (!function_exists('get_po_record_timeline')) {
    function get_po_record_timeline(mysqli $conn, $po_id)
    {
        $po_id = (int) $po_id;
        if ($po_id < 1) {
            return [];
        }

        $events = [];

        $context_stmt = $conn->prepare(
            "SELECT
                po.po_id,
                po.po_number,
                po.client_name,
                po.date_created AS po_created_at,
                po.created_by AS po_created_by,
                po_creator.full_name AS po_creator_name,
                request.pr_id,
                request.pr_number,
                request.date_created AS pr_created_at,
                request.created_by AS pr_created_by,
                pr_creator.full_name AS pr_creator_name,
                request.quotation_id,
                request.client_approval_record_id,
                quotation.quotation_number,
                quotation.amount AS quotation_amount,
                quotation.created_at AS quotation_created_at,
                quotation.created_by AS quotation_created_by,
                quotation_creator.full_name AS quotation_creator_name
             FROM purchase_orders po
             LEFT JOIN users po_creator
                ON po_creator.user_id = po.created_by
             LEFT JOIN purchase_requests request
                ON request.pr_id = po.pr_id
             LEFT JOIN users pr_creator
                ON pr_creator.user_id = request.created_by
             LEFT JOIN quotations quotation
                ON quotation.quotation_id = request.quotation_id
             LEFT JOIN users quotation_creator
                ON quotation_creator.user_id = quotation.created_by
             WHERE po.po_id = ?
             LIMIT 1"
        );
        $context_stmt->bind_param('i', $po_id);
        $context_stmt->execute();
        $context = $context_stmt->get_result()->fetch_assoc();
        $context_stmt->close();

        if (!$context) {
            return [];
        }

        if (!empty($context['quotation_id'])) {
            drms_po_timeline_add($events, [
                'event_key' => 'quotation:' . (int) $context['quotation_id'],
                'occurred_at' => $context['quotation_created_at'],
                'category' => 'Quotation',
                'title' => 'Quotation prepared',
                'detail' => drms_po_timeline_detail([
                    $context['quotation_number'],
                    drms_po_timeline_money($context['quotation_amount']),
                    $context['client_name'],
                ]),
                'actor' => $context['quotation_creator_name'] ?: 'System',
                'tone' => 'primary',
                'icon' => 'fa-file-invoice',
                'source' => 'quotation',
            ]);
        }

        if (!empty($context['pr_id'])) {
            drms_po_timeline_add($events, [
                'event_key' => 'pr:' . (int) $context['pr_id'],
                'occurred_at' => $context['pr_created_at'],
                'category' => 'Purchase Request',
                'title' => 'Purchase request prepared',
                'detail' => $context['pr_number'],
                'actor' => $context['pr_creator_name'] ?: 'System',
                'tone' => 'primary',
                'icon' => 'fa-clipboard-list',
                'source' => 'purchase_request',
            ]);
        }

        drms_po_timeline_add($events, [
            'event_key' => 'po:' . $po_id,
            'occurred_at' => $context['po_created_at'],
            'category' => 'Purchase Order',
            'title' => 'Purchase order created',
            'detail' => drms_po_timeline_detail([
                $context['po_number'],
                $context['client_name'],
            ]),
            'actor' => $context['po_creator_name'] ?: 'System',
            'tone' => 'primary',
            'icon' => 'fa-file-contract',
            'source' => 'purchase_order',
            'milestone_status' => 'Pending',
        ]);

        $quotation_id = (int) ($context['quotation_id'] ?? 0);
        $approval_record_id = (int) (
            $context['client_approval_record_id'] ?? 0
        );
        $pr_id = (int) ($context['pr_id'] ?? 0);

        if ($quotation_id > 0 || $approval_record_id > 0) {
            $client_stmt = $conn->prepare(
                "SELECT
                    approval.*,
                    recorder.full_name AS actor_name
                 FROM client_approval_records approval
                 LEFT JOIN users recorder
                    ON recorder.user_id = approval.recorded_by
                 WHERE approval.approval_record_id = ?
                    OR approval.quotation_id = ?
                 ORDER BY approval.recorded_at ASC,
                          approval.approval_record_id ASC"
            );
            $client_stmt->bind_param(
                'ii',
                $approval_record_id,
                $quotation_id
            );
            $client_stmt->execute();
            $client_rows = $client_stmt->get_result();
            while ($row = $client_rows->fetch_assoc()) {
                $is_official =
                    $row['record_type'] === 'Official Client PO';
                drms_po_timeline_add($events, [
                    'event_key' =>
                        'client_approval:' . (int) $row['approval_record_id'],
                    'occurred_at' => $row['recorded_at'],
                    'category' => 'Client Approval',
                    'title' => $is_official
                        ? 'Official client PO received'
                        : 'Client confirmation recorded',
                    'detail' => drms_po_timeline_detail([
                        $row['approval_mode'],
                        !empty($row['actual_client_po_number'])
                            ? 'Client PO ' . $row['actual_client_po_number']
                            : '',
                        $row['record_status'] !== 'Active'
                            ? $row['record_status']
                            : '',
                    ]),
                    'actor' => $row['actor_name'] ?: 'System',
                    'tone' => $row['record_status'] === 'Active'
                        ? 'success'
                        : 'secondary',
                    'icon' => $is_official
                        ? 'fa-file-signature'
                        : 'fa-circle-check',
                    'source' => 'client_approval',
                ]);
            }
            $client_stmt->close();

            $ack_stmt = $conn->prepare(
                "SELECT
                    acknowledgement.*,
                    actor.full_name AS actor_name
                 FROM client_po_internal_acknowledgements acknowledgement
                 LEFT JOIN users actor
                    ON actor.user_id = acknowledgement.acted_by
                 WHERE acknowledgement.approval_record_id = ?
                    OR acknowledgement.quotation_id = ?
                 ORDER BY acknowledgement.acted_at ASC,
                          acknowledgement.acknowledgement_id ASC"
            );
            $ack_stmt->bind_param(
                'ii',
                $approval_record_id,
                $quotation_id
            );
            $ack_stmt->execute();
            $ack_rows = $ack_stmt->get_result();
            while ($row = $ack_rows->fetch_assoc()) {
                $acknowledged = $row['decision'] === 'Acknowledged';
                drms_po_timeline_add($events, [
                    'event_key' =>
                        'client_po_ack:' . (int) $row['acknowledgement_id'],
                    'occurred_at' => $row['acted_at'],
                    'category' => 'GM Acknowledgement',
                    'title' => $acknowledged
                        ? 'Client PO acknowledged by GM'
                        : 'Client PO record returned',
                    'detail' => drms_po_timeline_detail([
                        $row['acknowledgement_method'],
                        trim(
                            (string) $row['signatory_name'] . ' ' .
                            (string) $row['signatory_role']
                        ),
                        $row['remarks'],
                        $row['record_status'] !== 'Active'
                            ? $row['record_status']
                            : '',
                    ]),
                    'actor' => $row['actor_name'] ?: 'System',
                    'tone' => $acknowledged ? 'success' : 'warning',
                    'icon' => $acknowledged
                        ? 'fa-user-check'
                        : 'fa-rotate-left',
                    'source' => 'client_po_acknowledgement',
                ]);
            }
            $ack_stmt->close();
        }

        if ($pr_id > 0) {
            $pr_approval_stmt = $conn->prepare(
                "SELECT
                    approval.*,
                    actor.full_name AS actor_name
                 FROM pr_approval_records approval
                 LEFT JOIN users actor
                    ON actor.user_id = approval.acted_by
                 WHERE approval.pr_id = ?
                   AND approval.acted_at IS NOT NULL
                   AND approval.decision <> 'Pending'
                 ORDER BY approval.acted_at ASC,
                          approval.pr_approval_record_id ASC"
            );
            $pr_approval_stmt->bind_param('i', $pr_id);
            $pr_approval_stmt->execute();
            $pr_approval_rows = $pr_approval_stmt->get_result();
            while ($row = $pr_approval_rows->fetch_assoc()) {
                $approved = $row['decision'] === 'Approved';
                $rejected = $row['decision'] === 'Rejected';
                drms_po_timeline_add($events, [
                    'event_key' =>
                        'pr_approval:' .
                        (int) $row['pr_approval_record_id'],
                    'occurred_at' => $row['acted_at'],
                    'category' => 'PR Approval',
                    'title' =>
                        $row['approval_stage'] . ' — ' . $row['decision'],
                    'detail' => drms_po_timeline_detail([
                        $row['required_role'],
                        $row['decision_remarks'],
                    ]),
                    'actor' => $row['actor_name'] ?: 'System',
                    'tone' => $approved
                        ? 'success'
                        : ($rejected ? 'danger' : 'warning'),
                    'icon' => $approved
                        ? 'fa-check'
                        : ($rejected ? 'fa-xmark' : 'fa-rotate-left'),
                    'source' => 'pr_approval',
                ]);
            }
            $pr_approval_stmt->close();
        }

        $history_stmt = $conn->prepare(
            "SELECT
                history.*,
                actor.full_name AS actor_name
             FROM po_history history
             LEFT JOIN users actor
                ON actor.user_id = history.changed_by
             WHERE history.po_id = ?
             ORDER BY history.timestamp ASC,
                      history.history_id ASC"
        );
        $history_stmt->bind_param('i', $po_id);
        $history_stmt->execute();
        $history_rows = $history_stmt->get_result();
        while ($row = $history_rows->fetch_assoc()) {
            $status_to = (string) $row['status_to'];
            $same_status = (string) $row['status_from'] === $status_to;
            $is_rejected = stripos($status_to, 'Rejected') !== false ||
                $status_to === 'Invalid';
            drms_po_timeline_add($events, [
                'event_key' => 'po_history:' . (int) $row['history_id'],
                'occurred_at' => $row['timestamp'],
                'category' => 'PO Workflow',
                'title' => $same_status
                    ? 'PO activity recorded at ' . $status_to
                    : $row['status_from'] . ' → ' . $status_to,
                'detail' => $row['remarks'],
                'actor' => $row['actor_name'] ?: 'System',
                'tone' => $is_rejected
                    ? 'danger'
                    : ($same_status ? 'info' : 'success'),
                'icon' => $is_rejected
                    ? 'fa-ban'
                    : ($same_status ? 'fa-note-sticky' : 'fa-arrow-right'),
                'source' => 'po_history',
                'status_to' => $status_to,
            ]);
        }
        $history_stmt->close();

        $fund_stmt = $conn->prepare(
            "SELECT
                funding.*,
                releaser.full_name AS released_by_name,
                voider.full_name AS voided_by_name
             FROM po_supplier_fund_releases funding
             LEFT JOIN users releaser
                ON releaser.user_id = funding.released_by
             LEFT JOIN users voider
                ON voider.user_id = funding.voided_by
             WHERE funding.po_id = ?
             ORDER BY funding.release_cycle ASC,
                      funding.fund_release_id ASC"
        );
        $fund_stmt->bind_param('i', $po_id);
        $fund_stmt->execute();
        $fund_rows = $fund_stmt->get_result();
        while ($row = $fund_rows->fetch_assoc()) {
            drms_po_timeline_add($events, [
                'event_key' =>
                    'fund_release:' . (int) $row['fund_release_id'],
                'occurred_at' => $row['released_at'],
                'category' => 'Supplier Funding',
                'title' => 'Supplier funding released',
                'detail' => drms_po_timeline_detail([
                    'Cycle ' . (int) $row['release_cycle'],
                    drms_po_timeline_money($row['released_amount']),
                    $row['release_method'],
                    'Ref ' . $row['reference_number'],
                    $row['remarks'],
                ]),
                'actor' => $row['released_by_name'] ?: 'Finance',
                'tone' => 'success',
                'icon' => 'fa-coins',
                'source' => 'fund_release',
                'milestone_status' => 'Funded',
            ]);

            if (
                $row['record_status'] === 'Voided' &&
                !empty($row['voided_at'])
            ) {
                drms_po_timeline_add($events, [
                    'event_key' =>
                        'fund_release_void:' .
                        (int) $row['fund_release_id'],
                    'occurred_at' => $row['voided_at'],
                    'category' => 'Supplier Funding',
                    'title' => 'Supplier funding record voided',
                    'detail' => $row['void_reason'],
                    'actor' => $row['voided_by_name'] ?: 'System',
                    'tone' => 'danger',
                    'icon' => 'fa-ban',
                    'source' => 'fund_release_void',
                ]);
            }
        }
        $fund_stmt->close();

        $request_stmt = $conn->prepare(
            "SELECT
                request.*,
                preparer.full_name AS prepared_by_name,
                canceller.full_name AS cancelled_by_name,
                voider.full_name AS voided_by_name
             FROM po_delivery_requests request
             LEFT JOIN users preparer
                ON preparer.user_id = request.prepared_by
             LEFT JOIN users canceller
                ON canceller.user_id = request.cancelled_by
             LEFT JOIN users voider
                ON voider.user_id = request.voided_by
             WHERE request.po_id = ?
             ORDER BY request.request_cycle ASC,
                      request.delivery_request_id ASC"
        );
        $request_stmt->bind_param('i', $po_id);
        $request_stmt->execute();
        $request_rows = $request_stmt->get_result();
        while ($row = $request_rows->fetch_assoc()) {
            drms_po_timeline_add($events, [
                'event_key' =>
                    'delivery_request:' .
                    (int) $row['delivery_request_id'],
                'occurred_at' => $row['submitted_at'] ?: $row['created_at'],
                'category' => 'Delivery',
                'title' => 'Delivery request submitted',
                'detail' => drms_po_timeline_detail([
                    $row['request_number'],
                    $row['request_type'],
                    'Cycle ' . (int) $row['request_cycle'],
                    $row['supplier_name_snapshot'],
                ]),
                'actor' => $row['prepared_by_name'] ?: 'Procurement',
                'tone' => 'info',
                'icon' => 'fa-truck-ramp-box',
                'source' => 'delivery_request',
                'milestone_status' => 'Delivery Requested',
            ]);

            if (!empty($row['cancelled_at'])) {
                drms_po_timeline_add($events, [
                    'event_key' =>
                        'delivery_request_cancel:' .
                        (int) $row['delivery_request_id'],
                    'occurred_at' => $row['cancelled_at'],
                    'category' => 'Delivery',
                    'title' => 'Delivery request cancelled',
                    'detail' => $row['cancellation_reason'],
                    'actor' => $row['cancelled_by_name'] ?: 'System',
                    'tone' => 'danger',
                    'icon' => 'fa-ban',
                    'source' => 'delivery_request_cancel',
                ]);
            }

            if (
                $row['record_status'] === 'Voided' &&
                !empty($row['voided_at'])
            ) {
                drms_po_timeline_add($events, [
                    'event_key' =>
                        'delivery_request_void:' .
                        (int) $row['delivery_request_id'],
                    'occurred_at' => $row['voided_at'],
                    'category' => 'Delivery',
                    'title' => 'Delivery request voided',
                    'detail' => $row['void_reason'],
                    'actor' => $row['voided_by_name'] ?: 'System',
                    'tone' => 'danger',
                    'icon' => 'fa-ban',
                    'source' => 'delivery_request_void',
                ]);
            }
        }
        $request_stmt->close();

        $plan_stmt = $conn->prepare(
            "SELECT
                plan.*,
                request.request_number,
                reviewer.full_name AS reviewed_by_name,
                returner.full_name AS returned_by_name,
                dispatcher.full_name AS dispatched_by_name,
                voider.full_name AS voided_by_name
             FROM po_delivery_plans plan
             INNER JOIN po_delivery_requests request
                ON request.delivery_request_id = plan.delivery_request_id
             LEFT JOIN users reviewer
                ON reviewer.user_id = plan.reviewed_by
             LEFT JOIN users returner
                ON returner.user_id = plan.returned_by
             LEFT JOIN users dispatcher
                ON dispatcher.user_id = plan.dispatched_by
             LEFT JOIN users voider
                ON voider.user_id = plan.voided_by
             WHERE request.po_id = ?
             ORDER BY plan.delivery_plan_id ASC"
        );
        $plan_stmt->bind_param('i', $po_id);
        $plan_stmt->execute();
        $plan_rows = $plan_stmt->get_result();
        while ($row = $plan_rows->fetch_assoc()) {
            if (!empty($row['reviewed_at'])) {
                drms_po_timeline_add($events, [
                    'event_key' =>
                        'delivery_plan_review:' .
                        (int) $row['delivery_plan_id'],
                    'occurred_at' => $row['reviewed_at'],
                    'category' => 'Logistics',
                    'title' => 'Delivery schedule approved',
                    'detail' => drms_po_timeline_detail([
                        $row['request_number'],
                        $row['provider_type'],
                        $row['provider_name'],
                        !empty($row['planned_delivery_at'])
                            ? 'Planned ' . date(
                                'M d, Y h:i A',
                                strtotime($row['planned_delivery_at'])
                            )
                            : '',
                    ]),
                    'actor' => $row['reviewed_by_name'] ?: 'Supply Chain',
                    'tone' => 'info',
                    'icon' => 'fa-route',
                    'source' => 'delivery_plan_review',
                    'milestone_status' => 'For Pick-up/Delivery',
                ]);
            }

            if (!empty($row['returned_at'])) {
                drms_po_timeline_add($events, [
                    'event_key' =>
                        'delivery_plan_return:' .
                        (int) $row['delivery_plan_id'],
                    'occurred_at' => $row['returned_at'],
                    'category' => 'Logistics',
                    'title' => 'Delivery request returned for correction',
                    'detail' => drms_po_timeline_detail([
                        $row['request_number'],
                        $row['return_reason'],
                    ]),
                    'actor' => $row['returned_by_name'] ?: 'Supply Chain',
                    'tone' => 'warning',
                    'icon' => 'fa-rotate-left',
                    'source' => 'delivery_plan_return',
                ]);
            }

            if (!empty($row['dispatched_at'])) {
                drms_po_timeline_add($events, [
                    'event_key' =>
                        'delivery_dispatch:' .
                        (int) $row['delivery_plan_id'],
                    'occurred_at' => $row['dispatched_at'],
                    'category' => 'Logistics',
                    'title' => 'Delivery dispatched',
                    'detail' => drms_po_timeline_detail([
                        $row['provider_name'],
                        $row['driver_name'],
                        !empty($row['tracking_reference'])
                            ? 'Tracking ' . $row['tracking_reference']
                            : '',
                    ]),
                    'actor' => $row['dispatched_by_name'] ?: 'Supply Chain',
                    'tone' => 'info',
                    'icon' => 'fa-truck-fast',
                    'source' => 'delivery_dispatch',
                ]);
            }

            if (
                $row['record_status'] === 'Voided' &&
                !empty($row['voided_at'])
            ) {
                drms_po_timeline_add($events, [
                    'event_key' =>
                        'delivery_plan_void:' .
                        (int) $row['delivery_plan_id'],
                    'occurred_at' => $row['voided_at'],
                    'category' => 'Logistics',
                    'title' => 'Delivery plan voided',
                    'detail' => $row['void_reason'],
                    'actor' => $row['voided_by_name'] ?: 'System',
                    'tone' => 'danger',
                    'icon' => 'fa-ban',
                    'source' => 'delivery_plan_void',
                ]);
            }
        }
        $plan_stmt->close();

        $receipt_stmt = $conn->prepare(
            "SELECT
                receipt.*,
                recorder.full_name AS recorded_by_name,
                voider.full_name AS voided_by_name
             FROM po_delivery_receipts receipt
             LEFT JOIN users recorder
                ON recorder.user_id = receipt.recorded_by
             LEFT JOIN users voider
                ON voider.user_id = receipt.voided_by
             WHERE receipt.po_id = ?
             ORDER BY receipt.receipt_cycle ASC,
                      receipt.delivery_receipt_id ASC"
        );
        $receipt_stmt->bind_param('i', $po_id);
        $receipt_stmt->execute();
        $receipt_rows = $receipt_stmt->get_result();
        while ($row = $receipt_rows->fetch_assoc()) {
            drms_po_timeline_add($events, [
                'event_key' =>
                    'delivery_receipt:' .
                    (int) $row['delivery_receipt_id'],
                'occurred_at' => $row['actual_handover_at'],
                'category' => 'Client Delivery',
                'title' => 'Client delivery completed',
                'detail' => drms_po_timeline_detail([
                    $row['client_receipt_reference'],
                    $row['recipient_name'],
                    (int) $row['delivered_item_quantity'] . '/' .
                        (int) $row['expected_item_quantity'] . ' items',
                    $row['delivery_condition'],
                    !empty($row['collection_due_date'])
                        ? 'Collection due ' . date(
                            'M d, Y',
                            strtotime($row['collection_due_date'])
                        )
                        : '',
                ]),
                'actor' => $row['recorded_by_name'] ?: 'Supply Chain',
                'tone' => 'success',
                'icon' => 'fa-box-check',
                'source' => 'delivery_receipt',
                'milestone_status' => 'Delivered',
            ]);

            if (
                $row['record_status'] === 'Voided' &&
                !empty($row['voided_at'])
            ) {
                drms_po_timeline_add($events, [
                    'event_key' =>
                        'delivery_receipt_void:' .
                        (int) $row['delivery_receipt_id'],
                    'occurred_at' => $row['voided_at'],
                    'category' => 'Client Delivery',
                    'title' => 'Delivery receipt voided',
                    'detail' => $row['void_reason'],
                    'actor' => $row['voided_by_name'] ?: 'System',
                    'tone' => 'danger',
                    'icon' => 'fa-ban',
                    'source' => 'delivery_receipt_void',
                ]);
            }
        }
        $receipt_stmt->close();

        $payment_stmt = $conn->prepare(
            "SELECT
                payment.*,
                recorder.full_name AS recorded_by_name,
                status_history.collection_status_from,
                status_history.collection_status_to,
                status_history.balance_before,
                status_history.balance_after
             FROM payments payment
             LEFT JOIN users recorder
                ON recorder.user_id = payment.recorded_by
             LEFT JOIN po_collection_status_history status_history
                ON status_history.collection_history_id = (
                    SELECT MAX(latest_history.collection_history_id)
                    FROM po_collection_status_history latest_history
                    WHERE latest_history.payment_id = payment.payment_id
                )
             WHERE payment.po_id = ?
             ORDER BY payment.payment_date ASC,
                      payment.payment_id ASC"
        );
        $payment_stmt->bind_param('i', $po_id);
        $payment_stmt->execute();
        $payment_rows = $payment_stmt->get_result();
        while ($row = $payment_rows->fetch_assoc()) {
            $classification = $row['payment_classification'] ?: 'Payment';
            drms_po_timeline_add($events, [
                'event_key' => 'payment:' . (int) $row['payment_id'],
                'occurred_at' => $row['payment_date'],
                'category' => 'Collection',
                'title' => $classification . ' recorded',
                'detail' => drms_po_timeline_detail([
                    drms_po_timeline_money($row['amount_paid']),
                    $row['payment_method'],
                    !empty($row['reference_number'])
                        ? 'Ref ' . $row['reference_number']
                        : '',
                    !empty($row['collection_status_to'])
                        ? $row['collection_status_from'] . ' → ' .
                            $row['collection_status_to']
                        : '',
                ]),
                'actor' => $row['recorded_by_name'] ?: 'Finance',
                'tone' => $classification === 'Full Payment'
                    ? 'success'
                    : 'primary',
                'icon' => 'fa-hand-holding-dollar',
                'source' => 'payment',
            ]);
        }
        $payment_stmt->close();

        $collection_stmt = $conn->prepare(
            "SELECT
                history.*,
                actor.full_name AS actor_name
             FROM po_collection_status_history history
             LEFT JOIN users actor
                ON actor.user_id = history.changed_by
             WHERE history.po_id = ?
               AND history.payment_id IS NULL
             ORDER BY history.changed_at ASC,
                      history.collection_history_id ASC"
        );
        $collection_stmt->bind_param('i', $po_id);
        $collection_stmt->execute();
        $collection_rows = $collection_stmt->get_result();
        while ($row = $collection_rows->fetch_assoc()) {
            drms_po_timeline_add($events, [
                'event_key' =>
                    'collection_status:' .
                    (int) $row['collection_history_id'],
                'occurred_at' => $row['changed_at'],
                'category' => 'Collection',
                'title' => 'Collection status updated',
                'detail' => drms_po_timeline_detail([
                    $row['collection_status_from'] . ' → ' .
                        $row['collection_status_to'],
                    drms_po_timeline_money($row['balance_before']) . ' → ' .
                        drms_po_timeline_money($row['balance_after']),
                    $row['remarks'],
                ]),
                'actor' => $row['actor_name'] ?: 'Finance',
                'tone' => $row['collection_status_to'] === 'Paid'
                    ? 'success'
                    : 'primary',
                'icon' => 'fa-chart-line',
                'source' => 'collection_status',
            ]);
        }
        $collection_stmt->close();

        $followup_stmt = $conn->prepare(
            "SELECT
                followup.*,
                recorder.full_name AS recorded_by_name,
                voider.full_name AS voided_by_name
             FROM po_collection_followups followup
             LEFT JOIN users recorder
                ON recorder.user_id = followup.recorded_by
             LEFT JOIN users voider
                ON voider.user_id = followup.voided_by
             WHERE followup.po_id = ?
             ORDER BY followup.followup_cycle ASC,
                      followup.followup_id ASC"
        );
        $followup_stmt->bind_param('i', $po_id);
        $followup_stmt->execute();
        $followup_rows = $followup_stmt->get_result();
        while ($row = $followup_rows->fetch_assoc()) {
            drms_po_timeline_add($events, [
                'event_key' => 'followup:' . (int) $row['followup_id'],
                'occurred_at' => $row['contact_attempted_at'],
                'category' => 'Collection Follow-up',
                'title' => $row['followup_outcome'],
                'detail' => drms_po_timeline_detail([
                    $row['contact_channel'],
                    $row['contact_person'],
                    !empty($row['commitment_amount'])
                        ? 'Commitment ' .
                            drms_po_timeline_money($row['commitment_amount'])
                        : '',
                    !empty($row['promised_payment_date'])
                        ? 'Promised ' . date(
                            'M d, Y',
                            strtotime($row['promised_payment_date'])
                        )
                        : '',
                    !empty($row['next_followup_date'])
                        ? 'Next follow-up ' . date(
                            'M d, Y',
                            strtotime($row['next_followup_date'])
                        )
                        : '',
                    $row['followup_notes'],
                ]),
                'actor' => $row['recorded_by_name'] ?: 'Finance',
                'tone' => in_array(
                    $row['followup_outcome'],
                    ['No Response', 'Unable to Reach', 'Dispute or Concern'],
                    true
                ) ? 'warning' : 'info',
                'icon' => 'fa-comments-dollar',
                'source' => 'collection_followup',
            ]);

            if (
                $row['record_status'] === 'Voided' &&
                !empty($row['voided_at'])
            ) {
                drms_po_timeline_add($events, [
                    'event_key' =>
                        'followup_void:' . (int) $row['followup_id'],
                    'occurred_at' => $row['voided_at'],
                    'category' => 'Collection Follow-up',
                    'title' => 'Follow-up record voided',
                    'detail' => $row['void_reason'],
                    'actor' => $row['voided_by_name'] ?: 'System',
                    'tone' => 'danger',
                    'icon' => 'fa-ban',
                    'source' => 'collection_followup_void',
                ]);
            }
        }
        $followup_stmt->close();

        // If a documentary milestone and its PO status history were saved at
        // practically the same moment, keep the richer documentary event only.
        $milestone_times = [];
        foreach ($events as $event) {
            if ($event['milestone_status'] === '') {
                continue;
            }
            $milestone_times[$event['milestone_status']][] =
                strtotime($event['occurred_at']);
        }

        $events = array_values(array_filter(
            $events,
            function ($event) use ($milestone_times) {
                if (
                    $event['source'] !== 'po_history' ||
                    $event['status_to'] === '' ||
                    empty($milestone_times[$event['status_to']])
                ) {
                    return true;
                }

                $history_time = strtotime($event['occurred_at']);
                foreach ($milestone_times[$event['status_to']] as $time) {
                    if (abs($history_time - $time) <= 15) {
                        return false;
                    }
                }
                return true;
            }
        ));

        usort($events, function ($left, $right) {
            $time_compare = strcmp(
                $right['occurred_at'],
                $left['occurred_at']
            );
            if ($time_compare !== 0) {
                return $time_compare;
            }
            return strcmp($right['event_key'], $left['event_key']);
        });

        return $events;
    }
}
