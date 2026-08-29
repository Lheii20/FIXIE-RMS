<?php

date_default_timezone_set('Asia/Manila');

function phase5c_collection_money(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function phase5c_create_user_notification(
    mysqli $conn,
    int $recipient_user_id,
    string $target_role,
    string $message,
    string $notification_key,
    string $target_url
): bool {
    $user_stmt = $conn->prepare(
        "SELECT user_id
         FROM users
         WHERE user_id = ?
           AND role = ?
           AND status = 'Active'
         LIMIT 1"
    );
    $user_stmt->bind_param('is', $recipient_user_id, $target_role);
    $user_stmt->execute();
    $recipient_exists = (bool) $user_stmt->get_result()->fetch_assoc();
    $user_stmt->close();

    if (!$recipient_exists) {
        return false;
    }

    $insert_stmt = $conn->prepare(
        "INSERT INTO notifications (
            target_role,
            recipient_user_id,
            message,
            notification_key,
            target_url
         ) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            notif_id = LAST_INSERT_ID(notif_id)"
    );
    $insert_stmt->bind_param(
        'sisss',
        $target_role,
        $recipient_user_id,
        $message,
        $notification_key,
        $target_url
    );
    $insert_stmt->execute();
    $was_created = $insert_stmt->affected_rows === 1;
    $notification_id = (int) $conn->insert_id;
    $insert_stmt->close();

    if ($notification_id < 1) {
        throw new RuntimeException(
            'The personal collection notification could not be resolved.'
        );
    }

    $state_stmt = $conn->prepare(
        "INSERT IGNORE INTO notification_user_states (
            notif_id,
            user_id,
            is_read,
            is_pinned,
            is_deleted
         ) VALUES (?, ?, 0, 0, 0)"
    );
    $state_stmt->bind_param(
        'ii',
        $notification_id,
        $recipient_user_id
    );
    $state_stmt->execute();
    $state_stmt->close();

    return $was_created;
}

function phase5c_create_role_notification_once(
    mysqli $conn,
    string $target_role,
    string $message,
    string $notification_key,
    string $target_url
): bool {
    $insert_stmt = $conn->prepare(
        "INSERT INTO notifications (
            target_role,
            recipient_user_id,
            message,
            notification_key,
            target_url
         ) VALUES (?, NULL, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            notif_id = LAST_INSERT_ID(notif_id)"
    );
    $insert_stmt->bind_param(
        'ssss',
        $target_role,
        $message,
        $notification_key,
        $target_url
    );
    $insert_stmt->execute();
    $was_created = $insert_stmt->affected_rows === 1;
    $notification_id = (int) $conn->insert_id;
    $insert_stmt->close();

    if ($notification_id < 1) {
        throw new RuntimeException(
            'The shared collection notification could not be resolved.'
        );
    }

    $state_stmt = $conn->prepare(
        "INSERT IGNORE INTO notification_user_states (
            notif_id,
            user_id,
            is_read,
            is_pinned,
            is_deleted
         )
         SELECT ?, user_id, 0, 0, 0
         FROM users
         WHERE role = ?
           AND status = 'Active'"
    );
    $state_stmt->bind_param('is', $notification_id, $target_role);
    $state_stmt->execute();
    $state_stmt->close();

    return $was_created;
}

function phase5c_sync_collection_reminders(
    mysqli $conn,
    int $user_id
): array {
    ensure_collaboration_tables_exist($conn);

    $migration_check = $conn->query(
        "SHOW COLUMNS FROM notifications LIKE 'notification_key'"
    );
    if (!$migration_check || $migration_check->num_rows === 0) {
        throw new RuntimeException(
            'Phase 5C notification migration is not installed.'
        );
    }

    $collection_status_check = $conn->query(
        "SHOW COLUMNS FROM purchase_orders LIKE 'collection_status'"
    );
    if (!$collection_status_check || $collection_status_check->num_rows === 0) {
        throw new RuntimeException(
            'Phase 6B3A collection-status migration is not installed.'
        );
    }

    $finance_stmt = $conn->prepare(
        "SELECT user_id
         FROM users
         WHERE user_id = ?
           AND role = 'Finance'
           AND status = 'Active'
         LIMIT 1"
    );
    $finance_stmt->bind_param('i', $user_id);
    $finance_stmt->execute();
    $is_active_finance = (bool) $finance_stmt
        ->get_result()
        ->fetch_assoc();
    $finance_stmt->close();

    if (!$is_active_finance) {
        return ['created' => 0, 'evaluated' => 0];
    }

    $reminder_stmt = $conn->prepare(
        "SELECT
            po.po_id,
            po.po_number,
            po.client_name,
            po.amount,
            po.collection_status,
            po.expected_collection_date,
            COALESCE(payment_summary.total_paid, 0) AS total_paid,
            receipt.collection_due_date AS receipt_due_date,
            latest_followup.followup_id,
            latest_followup.next_followup_date
         FROM purchase_order_task_assignments assignment
         INNER JOIN purchase_orders po
            ON po.po_id = assignment.po_id
         LEFT JOIN po_delivery_receipts receipt
            ON receipt.delivery_receipt_id = (
                SELECT MAX(receipt_candidate.delivery_receipt_id)
                FROM po_delivery_receipts receipt_candidate
                WHERE receipt_candidate.po_id = po.po_id
                  AND receipt_candidate.record_status = 'Active'
            )
         LEFT JOIN (
            SELECT po_id, SUM(amount_paid) AS total_paid
            FROM payments
            GROUP BY po_id
         ) payment_summary
            ON payment_summary.po_id = po.po_id
         LEFT JOIN po_collection_followups latest_followup
            ON latest_followup.followup_id = (
                SELECT MAX(followup_candidate.followup_id)
                FROM po_collection_followups followup_candidate
                WHERE followup_candidate.po_id = po.po_id
                  AND followup_candidate.record_status = 'Active'
            )
         WHERE assignment.assignment_id = (
                SELECT MAX(assignment_candidate.assignment_id)
                FROM purchase_order_task_assignments assignment_candidate
                WHERE assignment_candidate.po_id = po.po_id
                  AND assignment_candidate.assignment_status = 'Active'
            )
           AND assignment.assigned_to = ?
           AND assignment.assigned_role = 'Finance'
           AND po.status = 'Delivered'
           AND po.collection_status IN ('Unpaid', 'Partially Paid')
         ORDER BY po.po_id"
    );
    $reminder_stmt->bind_param('i', $user_id);
    $reminder_stmt->execute();
    $result = $reminder_stmt->get_result();

    $timezone = new DateTimeZone('Asia/Manila');
    $today = new DateTimeImmutable('today', $timezone);
    $today_key = $today->format('Y-m-d');
    $created_count = 0;
    $evaluated_count = 0;

    while ($row = $result->fetch_assoc()) {
        $amount = round((float) $row['amount'], 2);
        $paid = round((float) $row['total_paid'], 2);
        $balance = max(round($amount - $paid, 2), 0);
        if ($balance <= 0.01) {
            continue;
        }

        $evaluated_count++;
        $po_id = (int) $row['po_id'];
        $po_number = (string) $row['po_number'];
        $client_name = (string) $row['client_name'];
        $target_url = 'collection_followup.php?po_id=' . $po_id;
        $latest_followup_id = (int) ($row['followup_id'] ?? 0);
        $next_followup_value = (string) (
            $row['next_followup_date'] ?? ''
        );

        if (
            $latest_followup_id > 0 &&
            $next_followup_value !== '' &&
            strtotime($next_followup_value) !== false
        ) {
            $next_followup = new DateTimeImmutable(
                $next_followup_value,
                $timezone
            );
            $days_to_followup = (int) $today
                ->diff($next_followup)
                ->format('%r%a');

            if ($days_to_followup <= 0) {
                if ($days_to_followup < 0) {
                    $days_overdue = abs($days_to_followup);
                    $message = 'Collection follow-up overdue: ' .
                        $po_number . ' for ' . $client_name . ' has ' .
                        phase5c_collection_money($balance) .
                        ' outstanding. The scheduled follow-up is ' .
                        $days_overdue .
                        ($days_overdue === 1
                            ? ' day overdue.'
                            : ' days overdue.');
                } else {
                    $message = 'Collection follow-up due today: ' .
                        $po_number . ' for ' . $client_name . ' has ' .
                        phase5c_collection_money($balance) .
                        ' outstanding. Record today’s client contact result.';
                }

                $notification_key = 'collection:po:' . $po_id .
                    ':followup:' . $latest_followup_id . ':' . $today_key;
                if (phase5c_create_user_notification(
                    $conn,
                    $user_id,
                    'Finance',
                    $message,
                    $notification_key,
                    $target_url
                )) {
                    $created_count++;
                }
            }

            continue;
        }

        $due_date_value = (string) (
            $row['receipt_due_date'] ?:
            $row['expected_collection_date'] ?:
            ''
        );

        if (
            $due_date_value === '' ||
            strtotime($due_date_value) === false
        ) {
            $message = 'Collection setup alert: ' . $po_number . ' for ' .
                $client_name . ' has ' .
                phase5c_collection_money($balance) .
                ' outstanding but no contractual collection due date.';
            $notification_key = 'collection:po:' . $po_id .
                ':missing-due:' . $today_key;

            if (phase5c_create_user_notification(
                $conn,
                $user_id,
                'Finance',
                $message,
                $notification_key,
                $target_url
            )) {
                $created_count++;
            }
            continue;
        }

        $due_date = new DateTimeImmutable($due_date_value, $timezone);
        $days_to_due = (int) $today->diff($due_date)->format('%r%a');

        if ($days_to_due > 3) {
            continue;
        }

        if ($days_to_due > 0) {
            $message = 'Collection due soon: ' . $po_number . ' for ' .
                $client_name . ' has ' .
                phase5c_collection_money($balance) . ' outstanding and is due in ' .
                $days_to_due . ($days_to_due === 1 ? ' day.' : ' days.');
            $notification_key = 'collection:po:' . $po_id .
                ':contract:due-soon:' . $due_date->format('Y-m-d');
        } elseif ($days_to_due === 0) {
            $message = 'Collection due today: ' . $po_number . ' for ' .
                $client_name . ' has ' .
                phase5c_collection_money($balance) .
                ' outstanding. Contact the client today.';
            $notification_key = 'collection:po:' . $po_id .
                ':contract:due-today:' . $today_key;
        } else {
            $days_overdue = abs($days_to_due);
            $message = 'Collection overdue alert: ' . $po_number . ' for ' .
                $client_name . ' has ' .
                phase5c_collection_money($balance) . ' outstanding and is ' .
                $days_overdue .
                ($days_overdue === 1
                    ? ' day overdue.'
                    : ' days overdue.');
            $notification_key = 'collection:po:' . $po_id .
                ':contract:overdue:' . $today_key;
        }

        if (phase5c_create_user_notification(
            $conn,
            $user_id,
            'Finance',
            $message,
            $notification_key,
            $target_url
        )) {
            $created_count++;
        }
    }
    $reminder_stmt->close();

    $unassigned_result = $conn->query(
        "SELECT
            COUNT(*) AS receivable_count,
            COALESCE(SUM(
                GREATEST(
                    po.amount - COALESCE(payment_summary.total_paid, 0),
                    0
                )
            ), 0) AS outstanding_amount
         FROM purchase_orders po
         LEFT JOIN (
            SELECT po_id, SUM(amount_paid) AS total_paid
            FROM payments
            GROUP BY po_id
         ) payment_summary
            ON payment_summary.po_id = po.po_id
         WHERE po.status = 'Delivered'
           AND po.collection_status IN ('Unpaid', 'Partially Paid')
           AND po.amount - COALESCE(payment_summary.total_paid, 0) > 0.01
           AND NOT EXISTS (
                SELECT 1
                FROM purchase_order_task_assignments assignment
                WHERE assignment.po_id = po.po_id
                  AND assignment.assignment_status = 'Active'
           )"
    );
    $unassigned = $unassigned_result->fetch_assoc();
    $unassigned_count = (int) ($unassigned['receivable_count'] ?? 0);
    $unassigned_amount = round(
        (float) ($unassigned['outstanding_amount'] ?? 0),
        2
    );

    if ($unassigned_count > 0) {
        $message = 'Collection assignment alert: ' . $unassigned_count .
            ($unassigned_count === 1
                ? ' open receivable totaling '
                : ' open receivables totaling ') .
            phase5c_collection_money($unassigned_amount) .
            ' have no Finance owner. Assign or claim these records today.';

        if (phase5c_create_role_notification_once(
            $conn,
            'Finance',
            $message,
            'collection:unassigned:' . $today_key,
            'collection_monitoring.php?filter=unassigned'
        )) {
            $created_count++;
        }
    }

    return [
        'created' => $created_count,
        'evaluated' => $evaluated_count,
    ];
}
