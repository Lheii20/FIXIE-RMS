<?php
session_start();

require '../config/db_connect.php';
require '../config/functions.php';
require_once '../config/workflow_feedback.php';

date_default_timezone_set('Asia/Manila');

function phase4b_redirect(int $po_id, string $type, string $message): void
{
    $destination = $po_id > 0
        ? '../create_delivery_request.php?po_id=' . $po_id
        : '../po_list.php?filter=my_tasks';

    $public_message = $type === 'error'
        ? drms_public_feedback_message(
            $message,
            'The delivery request could not be completed. No workflow changes were saved.'
        )
        : drms_feedback_clean_text($message);
    drms_redirect_with_feedback($destination, $type, $public_message);
}

function phase4b_parse_datetime(string $value): ?DateTime
{
    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTime::getLastErrors();
    $has_errors = is_array($errors) &&
        ($errors['warning_count'] > 0 || $errors['error_count'] > 0);

    if (
        !$date ||
        $has_errors ||
        $date->format('Y-m-d\TH:i') !== $value
    ) {
        return null;
    }

    return $date;
}

function phase4b_length_is_valid(
    ?string $value,
    int $maximum,
    bool $required = false
): bool {
    $text = trim((string) $value);
    if ($required && $text === '') {
        return false;
    }

    return strlen($text) <= $maximum;
}

function phase4b_auto_assign_supply_chain(
    mysqli $conn,
    int $po_id,
    int $assigned_by
): int {
    return phase4c_auto_assign_role(
        $conn,
        $po_id,
        'Supply Chain',
        $assigned_by
    );
}

function phase4c_auto_assign_role(
    mysqli $conn,
    int $po_id,
    string $role,
    int $assigned_by
): int {
    $candidate_stmt = $conn->prepare(
        "SELECT
            user_account.user_id,
            COUNT(active_task.assignment_id) AS active_tasks
         FROM users user_account
         LEFT JOIN purchase_order_task_assignments active_task
            ON active_task.assigned_to = user_account.user_id
           AND active_task.assignment_status = 'Active'
         WHERE user_account.role = ?
           AND user_account.status = 'Active'
         GROUP BY user_account.user_id
         ORDER BY active_tasks ASC, user_account.user_id ASC
         LIMIT 1"
    );
    $candidate_stmt->bind_param('s', $role);
    $candidate_stmt->execute();
    $candidate = $candidate_stmt->get_result()->fetch_assoc();

    if (!$candidate) {
        throw new DomainException(
            'No active ' . $role . ' user is available for this workflow task.'
        );
    }

    $assigned_to = (int) $candidate['user_id'];
    $assigned_role = $role;
    $assignment_stmt = $conn->prepare(
        "INSERT INTO purchase_order_task_assignments (
            po_id,
            assigned_to,
            assigned_by,
            assigned_role,
            assignment_status,
            assigned_at
         ) VALUES (?, ?, ?, ?, 'Active', NOW())"
    );
    $assignment_stmt->bind_param(
        'iiis',
        $po_id,
        $assigned_to,
        $assigned_by,
        $assigned_role
    );
    $assignment_stmt->execute();

    return $assigned_to;
}

function phase4c_redirect_review(
    int $po_id,
    string $type,
    string $message
): void {
    $destination = $po_id > 0
        ? '../review_delivery_request.php?po_id=' . $po_id
        : '../po_list.php?filter=my_tasks';

    $public_message = $type === 'error'
        ? drms_public_feedback_message(
            $message,
            'The logistics decision could not be completed. No workflow changes were saved.'
        )
        : drms_feedback_clean_text($message);
    drms_redirect_with_feedback($destination, $type, $public_message);
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../po_list.php?filter=my_tasks');
    exit();
}

$po_id = (int) ($_POST['po_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
$session_token = (string) ($_SESSION['csrf_token'] ?? '');
$posted_token = (string) ($_POST['csrf_token'] ?? '');

if (
    $session_token === '' ||
    $posted_token === '' ||
    !hash_equals($session_token, $posted_token)
) {
    $security_message =
        'Security token validation failed. Refresh the form and try again.';
    if (in_array(
        $action,
        ['approve_delivery_schedule', 'return_delivery_request'],
        true
    )) {
        phase4c_redirect_review($po_id, 'error', $security_message);
    }
    phase4b_redirect($po_id, 'error', $security_message);
}

if (in_array(
    $action,
    ['approve_delivery_schedule', 'return_delivery_request'],
    true
)) {
    if ($_SESSION['role'] !== 'Supply Chain') {
        phase4c_redirect_review(
            $po_id,
            'error',
            'Only Supply Chain can review and schedule a delivery request.'
        );
    }

    if ($po_id < 1) {
        phase4c_redirect_review(
            0,
            'error',
            'Select a valid delivery request.'
        );
    }

    $user_id = (int) $_SESSION['user_id'];
    $is_approval = $action === 'approve_delivery_schedule';
    $return_reason = trim((string) ($_POST['return_reason'] ?? ''));

    $provider_type = trim((string) ($_POST['provider_type'] ?? ''));
    $provider_name = trim((string) ($_POST['provider_name'] ?? ''));
    $planned_pickup_input = trim(
        (string) ($_POST['planned_pickup_at'] ?? '')
    );
    $planned_delivery_input = trim(
        (string) ($_POST['planned_delivery_at'] ?? '')
    );
    $driver_name = trim((string) ($_POST['driver_name'] ?? ''));
    $driver_contact_number = trim(
        (string) ($_POST['driver_contact_number'] ?? '')
    );
    $vehicle_type = trim((string) ($_POST['vehicle_type'] ?? ''));
    $vehicle_plate_number = trim(
        (string) ($_POST['vehicle_plate_number'] ?? '')
    );
    $tracking_reference = trim(
        (string) ($_POST['tracking_reference'] ?? '')
    );
    $route_or_plot_notes = trim(
        (string) ($_POST['route_or_plot_notes'] ?? '')
    );
    $logistics_confirmation =
        isset($_POST['logistics_confirmation']) &&
        $_POST['logistics_confirmation'] === '1';

    $allowed_provider_types = [
        'Company Fleet',
        'Third-Party Logistics',
        'Supplier Delivery',
        'Client Pick-up',
    ];

    if ($is_approval) {
        if (!in_array($provider_type, $allowed_provider_types, true)) {
            phase4c_redirect_review(
                $po_id,
                'error',
                'Select a valid logistics provider type.'
            );
        }

        if (
            !phase4b_length_is_valid($provider_name, 150) ||
            !phase4b_length_is_valid($driver_name, 150) ||
            !phase4b_length_is_valid($driver_contact_number, 50) ||
            !phase4b_length_is_valid($vehicle_type, 100) ||
            !phase4b_length_is_valid($vehicle_plate_number, 50) ||
            !phase4b_length_is_valid($tracking_reference, 100) ||
            !phase4b_length_is_valid($route_or_plot_notes, 2000)
        ) {
            phase4c_redirect_review(
                $po_id,
                'error',
                'One or more logistics fields exceed the allowed length.'
            );
        }

        if (!$logistics_confirmation) {
            phase4c_redirect_review(
                $po_id,
                'error',
                'Confirm the final provider and schedule before approval.'
            );
        }
    } elseif (
        strlen($return_reason) < 10 ||
        strlen($return_reason) > 500
    ) {
        phase4c_redirect_review(
            $po_id,
            'error',
            'Provide a clear return reason from 10 to 500 characters.'
        );
    }

    try {
        $conn->begin_transaction();

        $review_stmt = $conn->prepare(
            "SELECT
                po.po_number,
                po.client_name,
                po.status,
                delivery_request.delivery_request_id,
                delivery_request.request_number,
                delivery_request.request_type,
                delivery_request.supplier_name_snapshot,
                delivery_request.request_status,
                plan.delivery_plan_id,
                plan.logistics_status
             FROM purchase_orders po
             INNER JOIN po_delivery_requests delivery_request
                ON delivery_request.po_id = po.po_id
               AND delivery_request.record_status = 'Active'
             INNER JOIN po_delivery_plans plan
                ON plan.delivery_request_id =
                    delivery_request.delivery_request_id
               AND plan.record_status = 'Active'
             WHERE po.po_id = ?
             ORDER BY delivery_request.request_cycle DESC
             LIMIT 1
             FOR UPDATE"
        );
        $review_stmt->bind_param('i', $po_id);
        $review_stmt->execute();
        $review = $review_stmt->get_result()->fetch_assoc();

        if (
            !$review ||
            $review['status'] !== 'Delivery Requested' ||
            $review['request_status'] !== 'Submitted' ||
            $review['logistics_status'] !== 'Pending Review'
        ) {
            throw new DomainException(
                'This delivery request is no longer waiting for logistics review.'
            );
        }

        enforce_po_task_ownership(
            $conn,
            $po_id,
            $user_id,
            'Supply Chain'
        );

        $rule_stmt = $conn->prepare(
            "SELECT next_status, next_location, notify_target
             FROM workflow_rules
             WHERE current_status = 'Delivery Requested'
               AND action_key = ?
               AND required_role = 'Supply Chain'
             LIMIT 1"
        );
        $rule_stmt->bind_param('s', $action);
        $rule_stmt->execute();
        $rule = $rule_stmt->get_result()->fetch_assoc();

        if (!$rule) {
            throw new DomainException(
                'The selected logistics workflow action is unavailable.'
            );
        }

        $delivery_request_id = (int) $review['delivery_request_id'];
        $delivery_plan_id = (int) $review['delivery_plan_id'];
        $new_status = $rule['next_status'];
        $new_location = trim((string) $rule['next_location']) !== ''
            ? $rule['next_location']
            : ($is_approval
                ? 'Supply Chain Dept.'
                : 'Procurement Dept.');

        if ($is_approval) {
            $planned_pickup_at = phase4b_parse_datetime(
                $planned_pickup_input
            );
            $planned_delivery_at = phase4b_parse_datetime(
                $planned_delivery_input
            );
            $schedule_floor = (new DateTime('now'))->modify('-5 minutes');

            if ($review['request_type'] === 'Pick-up and Delivery') {
                if (!$planned_pickup_at || !$planned_delivery_at) {
                    throw new DomainException(
                        'Final pick-up and delivery schedules are required.'
                    );
                }
            } elseif ($review['request_type'] === 'Delivery Only') {
                $planned_pickup_at = null;
                if (!$planned_delivery_at) {
                    throw new DomainException(
                        'A final delivery schedule is required.'
                    );
                }
            } else {
                $planned_delivery_at = null;
                if (!$planned_pickup_at) {
                    throw new DomainException(
                        'A final client pick-up schedule is required.'
                    );
                }
            }

            if (
                ($planned_pickup_at &&
                    $planned_pickup_at < $schedule_floor) ||
                ($planned_delivery_at &&
                    $planned_delivery_at < $schedule_floor)
            ) {
                throw new DomainException(
                    'Final logistics schedules cannot be in the past.'
                );
            }

            if (
                $planned_pickup_at &&
                $planned_delivery_at &&
                $planned_delivery_at < $planned_pickup_at
            ) {
                throw new DomainException(
                    'Final delivery cannot be earlier than final pick-up.'
                );
            }

            if ($provider_type === 'Third-Party Logistics' &&
                $provider_name === '') {
                throw new DomainException(
                    'Enter the approved third-party logistics provider.'
                );
            }

            if ($provider_type === 'Company Fleet' &&
                $provider_name === '') {
                $provider_name = 'Fixie Computer Ventures';
            } elseif ($provider_type === 'Supplier Delivery' &&
                $provider_name === '') {
                $provider_name = $review['supplier_name_snapshot'];
            } elseif ($provider_type === 'Client Pick-up' &&
                $provider_name === '') {
                $provider_name = $review['client_name'];
            }

            $planned_pickup_sql = $planned_pickup_at
                ? $planned_pickup_at->format('Y-m-d H:i:s')
                : null;
            $planned_delivery_sql = $planned_delivery_at
                ? $planned_delivery_at->format('Y-m-d H:i:s')
                : null;
            $driver_name = $driver_name !== '' ? $driver_name : null;
            $driver_contact_number = $driver_contact_number !== ''
                ? $driver_contact_number
                : null;
            $vehicle_type = $vehicle_type !== '' ? $vehicle_type : null;
            $vehicle_plate_number = $vehicle_plate_number !== ''
                ? $vehicle_plate_number
                : null;
            $tracking_reference = $tracking_reference !== ''
                ? $tracking_reference
                : null;
            $route_or_plot_notes = $route_or_plot_notes !== ''
                ? $route_or_plot_notes
                : null;

            $plan_update_stmt = $conn->prepare(
                "UPDATE po_delivery_plans
                 SET logistics_status = 'Scheduled',
                     provider_type = ?,
                     provider_name = ?,
                     planned_pickup_at = ?,
                     planned_delivery_at = ?,
                     driver_name = ?,
                     driver_contact_number = ?,
                     vehicle_type = ?,
                     vehicle_plate_number = ?,
                     tracking_reference = ?,
                     route_or_plot_notes = ?,
                     reviewed_by = ?,
                     reviewed_at = NOW(),
                     returned_by = NULL,
                     returned_at = NULL,
                     return_reason = NULL
                 WHERE delivery_plan_id = ?
                   AND logistics_status = 'Pending Review'"
            );
            $plan_update_stmt->bind_param(
                'ssssssssssii',
                $provider_type,
                $provider_name,
                $planned_pickup_sql,
                $planned_delivery_sql,
                $driver_name,
                $driver_contact_number,
                $vehicle_type,
                $vehicle_plate_number,
                $tracking_reference,
                $route_or_plot_notes,
                $user_id,
                $delivery_plan_id
            );
            $plan_update_stmt->execute();
            if ($plan_update_stmt->affected_rows !== 1) {
                throw new DomainException(
                    'The logistics plan changed before it could be approved.'
                );
            }

            $request_update_stmt = $conn->prepare(
                "UPDATE po_delivery_requests
                 SET request_status = 'Scheduled'
                 WHERE delivery_request_id = ?
                   AND request_status = 'Submitted'"
            );
            $request_update_stmt->bind_param('i', $delivery_request_id);
            $request_update_stmt->execute();

            $history_remarks = 'Delivery request ' .
                $review['request_number'] . ' approved and scheduled through ' .
                $provider_type . '.';
            $success_message = 'Delivery request approved and scheduled for execution.';
            $audit_action = 'APPROVE_DELIVERY_SCHEDULE';
            $next_role = 'Supply Chain';
            $assignment_reason = 'Logistics review completed';
        } else {
            $plan_update_stmt = $conn->prepare(
                "UPDATE po_delivery_plans
                 SET logistics_status = 'Returned',
                     returned_by = ?,
                     returned_at = NOW(),
                     return_reason = ?
                 WHERE delivery_plan_id = ?
                   AND logistics_status = 'Pending Review'"
            );
            $plan_update_stmt->bind_param(
                'isi',
                $user_id,
                $return_reason,
                $delivery_plan_id
            );
            $plan_update_stmt->execute();
            if ($plan_update_stmt->affected_rows !== 1) {
                throw new DomainException(
                    'The logistics plan changed before it could be returned.'
                );
            }

            $request_update_stmt = $conn->prepare(
                "UPDATE po_delivery_requests
                 SET request_status = 'Returned'
                 WHERE delivery_request_id = ?
                   AND request_status = 'Submitted'"
            );
            $request_update_stmt->bind_param('i', $delivery_request_id);
            $request_update_stmt->execute();

            $history_remarks = 'Delivery request ' .
                $review['request_number'] .
                ' returned to Procurement. Reason: ' . $return_reason;
            $success_message = 'Delivery request returned to Procurement for correction.';
            $audit_action = 'RETURN_DELIVERY_REQUEST';
            $next_role = 'Procurement';
            $assignment_reason = 'Delivery request returned for correction';
        }

        $po_update_stmt = $conn->prepare(
            "UPDATE purchase_orders
             SET status = ?,
                 current_location = ?,
                 is_viewed = 0
             WHERE po_id = ?
               AND status = 'Delivery Requested'"
        );
        $po_update_stmt->bind_param(
            'ssi',
            $new_status,
            $new_location,
            $po_id
        );
        $po_update_stmt->execute();
        if ($po_update_stmt->affected_rows !== 1) {
            throw new DomainException(
                'The PO status changed before the logistics decision was saved.'
            );
        }

        $history_stmt = $conn->prepare(
            "INSERT INTO po_history (
                po_id,
                changed_by,
                status_from,
                status_to,
                remarks
             ) VALUES (?, ?, 'Delivery Requested', ?, ?)"
        );
        $history_stmt->bind_param(
            'iiss',
            $po_id,
            $user_id,
            $new_status,
            $history_remarks
        );
        $history_stmt->execute();

        complete_po_task_assignment(
            $conn,
            $po_id,
            $user_id,
            $assignment_reason
        );
        $assigned_user = phase4c_auto_assign_role(
            $conn,
            $po_id,
            $next_role,
            $user_id
        );

        $notify_target = trim((string) $rule['notify_target']) !== ''
            ? $rule['notify_target']
            : $next_role;
        create_role_notification(
            $conn,
            $notify_target,
            'Delivery request ' . $review['request_number'] .
                ' for PO ' . $review['po_number'] . ' is now ' .
                $new_status . '.'
        );

        log_audit_action(
            $conn,
            $user_id,
            $audit_action,
            $history_remarks,
            [
                'status' => 'Delivery Requested',
                'logistics_status' => 'Pending Review',
            ],
            [
                'status' => $new_status,
                'logistics_status' => $is_approval
                    ? 'Scheduled'
                    : 'Returned',
                'delivery_request_id' => $delivery_request_id,
                'delivery_plan_id' => $delivery_plan_id,
                'assigned_user' => $assigned_user,
                'provider_type' => $is_approval
                    ? $provider_type
                    : null,
                'return_reason' => $is_approval
                    ? null
                    : $return_reason,
            ]
        );

        $conn->commit();
        header(
            'Location: ../view_po.php?id=' . $po_id . '&success=' .
            rawurlencode($success_message)
        );
        exit();
    } catch (Throwable $error) {
        $conn->rollback();
        drms_log_workflow_failure(
            'Logistics review for PO ' . $po_id,
            $error
        );

        $public_error = $error instanceof DomainException
            ? $error->getMessage()
            : 'The logistics decision could not be completed. No workflow changes were saved. Please try again.';
        phase4c_redirect_review($po_id, 'error', $public_error);
    }
}

if ($action !== 'submit_delivery_request') {
    phase4b_redirect($po_id, 'error', 'Invalid delivery-request action.');
}

if ($_SESSION['role'] !== 'Procurement') {
    phase4b_redirect($po_id, 'error', 'Only Procurement can prepare and submit a delivery request.');
}

if ($po_id < 1) {
    phase4b_redirect(0, 'error', 'Select a valid funded Purchase Order.');
}

$request_type = trim((string) ($_POST['request_type'] ?? ''));
$supplier_ready_input = trim(
    (string) ($_POST['supplier_ready_confirmed_at'] ?? '')
);
$supplier_confirmation_reference = trim(
    (string) ($_POST['supplier_confirmation_reference'] ?? '')
);
$supplier_contact_name = trim(
    (string) ($_POST['supplier_contact_name'] ?? '')
);
$supplier_contact_number = trim(
    (string) ($_POST['supplier_contact_number'] ?? '')
);
$supplier_contact_email = trim(
    (string) ($_POST['supplier_contact_email'] ?? '')
);
$pickup_address = trim((string) ($_POST['pickup_address'] ?? ''));
$delivery_address = trim((string) ($_POST['delivery_address'] ?? ''));
$preferred_pickup_input = trim(
    (string) ($_POST['preferred_pickup_at'] ?? '')
);
$preferred_delivery_input = trim(
    (string) ($_POST['preferred_delivery_at'] ?? '')
);
$package_count_input = trim((string) ($_POST['package_count'] ?? ''));
$handling_instructions = trim(
    (string) ($_POST['handling_instructions'] ?? '')
);
$procurement_remarks = trim(
    (string) ($_POST['procurement_remarks'] ?? '')
);
$confirmed = isset($_POST['delivery_confirmation']) &&
    $_POST['delivery_confirmation'] === '1';

$allowed_request_types = [
    'Pick-up and Delivery',
    'Delivery Only',
    'Client Pick-up',
];

if (!in_array($request_type, $allowed_request_types, true)) {
    phase4b_redirect($po_id, 'error', 'Select a valid delivery request type.');
}

$supplier_ready_at = phase4b_parse_datetime($supplier_ready_input);
$preferred_pickup_at = phase4b_parse_datetime($preferred_pickup_input);
$preferred_delivery_at = phase4b_parse_datetime($preferred_delivery_input);
$now = new DateTime('now');
$schedule_floor = (clone $now)->modify('-5 minutes');

if (!$supplier_ready_at || $supplier_ready_at > $now) {
    phase4b_redirect(
        $po_id,
        'error',
        'Enter a valid non-future supplier readiness confirmation date and time.'
    );
}

if (
    !phase4b_length_is_valid($supplier_confirmation_reference, 100) ||
    !phase4b_length_is_valid($supplier_contact_name, 150) ||
    !phase4b_length_is_valid($supplier_contact_number, 50) ||
    !phase4b_length_is_valid($supplier_contact_email, 150) ||
    !phase4b_length_is_valid($pickup_address, 2000) ||
    !phase4b_length_is_valid($delivery_address, 2000) ||
    !phase4b_length_is_valid($handling_instructions, 2000) ||
    !phase4b_length_is_valid($procurement_remarks, 2000)
) {
    phase4b_redirect($po_id, 'error', 'One or more delivery-request fields exceed the allowed length.');
}

if (
    $supplier_contact_email !== '' &&
    !filter_var($supplier_contact_email, FILTER_VALIDATE_EMAIL)
) {
    phase4b_redirect($po_id, 'error', 'Enter a valid supplier contact email address.');
}

$package_count = filter_var(
    $package_count_input,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 100000]]
);
if ($package_count === false) {
    phase4b_redirect($po_id, 'error', 'Package count must be a whole number from 1 to 100,000.');
}

if ($request_type === 'Pick-up and Delivery') {
    if (
        $pickup_address === '' ||
        $delivery_address === '' ||
        !$preferred_pickup_at ||
        !$preferred_delivery_at
    ) {
        phase4b_redirect(
            $po_id,
            'error',
            'Pick-up address, delivery address, and both preferred schedules are required.'
        );
    }
} elseif ($request_type === 'Delivery Only') {
    $pickup_address = '';
    $preferred_pickup_at = null;
    if ($delivery_address === '' || !$preferred_delivery_at) {
        phase4b_redirect(
            $po_id,
            'error',
            'Delivery address and preferred delivery schedule are required.'
        );
    }
} else {
    $delivery_address = '';
    $preferred_delivery_at = null;
    if ($pickup_address === '' || !$preferred_pickup_at) {
        phase4b_redirect(
            $po_id,
            'error',
            'Client pick-up location and preferred pick-up schedule are required.'
        );
    }
}

if (
    ($preferred_pickup_at && $preferred_pickup_at < $schedule_floor) ||
    ($preferred_delivery_at && $preferred_delivery_at < $schedule_floor)
) {
    phase4b_redirect($po_id, 'error', 'Preferred logistics schedules cannot be in the past.');
}

if (
    $preferred_pickup_at &&
    $preferred_delivery_at &&
    $preferred_delivery_at < $preferred_pickup_at
) {
    phase4b_redirect($po_id, 'error', 'Preferred delivery must not be earlier than preferred pick-up.');
}

if (!$confirmed) {
    phase4b_redirect($po_id, 'error', 'Confirm the supplier readiness and delivery-request details before submitting.');
}

$supplier_ready_sql = $supplier_ready_at->format('Y-m-d H:i:s');
$preferred_pickup_sql = $preferred_pickup_at
    ? $preferred_pickup_at->format('Y-m-d H:i:s')
    : null;
$preferred_delivery_sql = $preferred_delivery_at
    ? $preferred_delivery_at->format('Y-m-d H:i:s')
    : null;
$supplier_confirmation_reference =
    $supplier_confirmation_reference !== ''
        ? $supplier_confirmation_reference
        : null;
$supplier_contact_name =
    $supplier_contact_name !== '' ? $supplier_contact_name : null;
$supplier_contact_number =
    $supplier_contact_number !== '' ? $supplier_contact_number : null;
$supplier_contact_email =
    $supplier_contact_email !== '' ? $supplier_contact_email : null;
$pickup_address = $pickup_address !== '' ? $pickup_address : null;
$delivery_address = $delivery_address !== '' ? $delivery_address : null;
$handling_instructions =
    $handling_instructions !== '' ? $handling_instructions : null;
$procurement_remarks =
    $procurement_remarks !== '' ? $procurement_remarks : null;
$user_id = (int) $_SESSION['user_id'];

try {
    $conn->begin_transaction();

    $po_stmt = $conn->prepare(
        "SELECT
            po.po_number,
            po.status,
            po.supplier_detail_id,
            supplier.supplier_name,
            funding.fund_release_id
         FROM purchase_orders po
         INNER JOIN pr_supplier_details supplier
            ON supplier.supplier_detail_id = po.supplier_detail_id
           AND supplier.pr_id = po.pr_id
           AND supplier.record_status = 'Active'
         INNER JOIN po_supplier_fund_releases funding
            ON funding.po_id = po.po_id
           AND funding.record_status = 'Active'
         WHERE po.po_id = ?
         ORDER BY funding.release_cycle DESC
         LIMIT 1
         FOR UPDATE"
    );
    $po_stmt->bind_param('i', $po_id);
    $po_stmt->execute();
    $po = $po_stmt->get_result()->fetch_assoc();

    if (
        !$po ||
        $po['status'] !== 'Funded'
    ) {
        throw new DomainException(
            'This PO is no longer eligible for delivery-request preparation.'
        );
    }

    enforce_po_task_ownership($conn, $po_id, $user_id, 'Procurement');

    $rule_stmt = $conn->prepare(
        "SELECT next_status, next_location, notify_target
         FROM workflow_rules
         WHERE current_status = 'Funded'
           AND action_key = 'create_delivery_request'
           AND required_role = 'Procurement'
         LIMIT 1"
    );
    $rule_stmt->execute();
    $rule = $rule_stmt->get_result()->fetch_assoc();

    if (!$rule || $rule['next_status'] !== 'Delivery Requested') {
        throw new DomainException(
            'The delivery-request workflow is currently unavailable. Please ask an administrator to review the workflow configuration.'
        );
    }

    $existing_stmt = $conn->prepare(
        "SELECT
            delivery_request.delivery_request_id,
            delivery_request.request_number,
            delivery_request.request_cycle,
            delivery_request.request_status,
            plan.delivery_plan_id,
            plan.logistics_status
         FROM po_delivery_requests delivery_request
         LEFT JOIN po_delivery_plans plan
            ON plan.delivery_request_id =
                delivery_request.delivery_request_id
           AND plan.record_status = 'Active'
         WHERE delivery_request.po_id = ?
           AND delivery_request.record_status = 'Active'
         ORDER BY delivery_request.request_cycle DESC
         LIMIT 1
         FOR UPDATE"
    );
    $existing_stmt->bind_param('i', $po_id);
    $existing_stmt->execute();
    $existing_request =
        $existing_stmt->get_result()->fetch_assoc();
    $is_resubmission = $existing_request &&
        $existing_request['request_status'] === 'Returned' &&
        $existing_request['logistics_status'] === 'Returned';

    if ($existing_request && !$is_resubmission) {
        throw new DomainException(
            'An active delivery request already exists for this PO.'
        );
    }

    $fund_release_id = (int) $po['fund_release_id'];
    $supplier_detail_id = (int) $po['supplier_detail_id'];
    $supplier_name_snapshot = (string) $po['supplier_name'];

    if ($is_resubmission) {
        $delivery_request_id =
            (int) $existing_request['delivery_request_id'];
        $request_number = $existing_request['request_number'];
        $request_cycle = (int) $existing_request['request_cycle'];
        $delivery_plan_id = (int) $existing_request['delivery_plan_id'];

        $request_update_stmt = $conn->prepare(
            "UPDATE po_delivery_requests
             SET request_type = ?,
                 supplier_name_snapshot = ?,
                 supplier_ready_confirmed_at = ?,
                 supplier_confirmation_reference = ?,
                 supplier_contact_name = ?,
                 supplier_contact_number = ?,
                 supplier_contact_email = ?,
                 pickup_address = ?,
                 delivery_address = ?,
                 preferred_pickup_at = ?,
                 preferred_delivery_at = ?,
                 package_count = ?,
                 handling_instructions = ?,
                 procurement_remarks = ?,
                 request_status = 'Submitted',
                 prepared_by = ?,
                 submitted_at = NOW()
             WHERE delivery_request_id = ?
               AND request_status = 'Returned'"
        );
        $request_update_stmt->bind_param(
            'sssssssssssissii',
            $request_type,
            $supplier_name_snapshot,
            $supplier_ready_sql,
            $supplier_confirmation_reference,
            $supplier_contact_name,
            $supplier_contact_number,
            $supplier_contact_email,
            $pickup_address,
            $delivery_address,
            $preferred_pickup_sql,
            $preferred_delivery_sql,
            $package_count,
            $handling_instructions,
            $procurement_remarks,
            $user_id,
            $delivery_request_id
        );
        $request_update_stmt->execute();
        if ($request_update_stmt->affected_rows !== 1) {
            throw new DomainException(
                'The returned delivery request changed before it could be resubmitted.'
            );
        }

        $plan_reset_stmt = $conn->prepare(
            "UPDATE po_delivery_plans
             SET logistics_status = 'Pending Review',
                 provider_type = NULL,
                 provider_name = NULL,
                 planned_pickup_at = NULL,
                 planned_delivery_at = NULL,
                 driver_name = NULL,
                 driver_contact_number = NULL,
                 vehicle_type = NULL,
                 vehicle_plate_number = NULL,
                 tracking_reference = NULL,
                 route_or_plot_notes = NULL,
                 reviewed_by = NULL,
                 reviewed_at = NULL,
                 returned_by = NULL,
                 returned_at = NULL,
                 return_reason = NULL
             WHERE delivery_plan_id = ?
               AND logistics_status = 'Returned'"
        );
        $plan_reset_stmt->bind_param('i', $delivery_plan_id);
        $plan_reset_stmt->execute();
        if ($plan_reset_stmt->affected_rows !== 1) {
            throw new DomainException(
                'The returned logistics plan changed before resubmission.'
            );
        }
    } else {
        $cycle_stmt = $conn->prepare(
            "SELECT COALESCE(MAX(request_cycle), 0) + 1 AS next_cycle
             FROM po_delivery_requests
             WHERE po_id = ?
             FOR UPDATE"
        );
        $cycle_stmt->bind_param('i', $po_id);
        $cycle_stmt->execute();
        $request_cycle = (int) (
            $cycle_stmt->get_result()->fetch_assoc()['next_cycle'] ?? 1
        );
        $request_number = 'DRF-' . date('Ymd') . '-' .
            str_pad((string) $po_id, 4, '0', STR_PAD_LEFT) . '-' .
            str_pad((string) $request_cycle, 2, '0', STR_PAD_LEFT);

        $insert_stmt = $conn->prepare(
            "INSERT INTO po_delivery_requests (
                request_number,
                po_id,
                request_cycle,
                fund_release_id,
                supplier_detail_id,
                request_type,
                supplier_name_snapshot,
                supplier_ready_confirmed_at,
                supplier_confirmation_reference,
                supplier_contact_name,
                supplier_contact_number,
                supplier_contact_email,
                pickup_address,
                delivery_address,
                preferred_pickup_at,
                preferred_delivery_at,
                package_count,
                handling_instructions,
                procurement_remarks,
                request_status,
                prepared_by,
                submitted_at
             ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                'Submitted', ?, NOW()
             )"
        );
        $insert_stmt->bind_param(
            'siiiisssssssssssissi',
            $request_number,
            $po_id,
            $request_cycle,
            $fund_release_id,
            $supplier_detail_id,
            $request_type,
            $supplier_name_snapshot,
            $supplier_ready_sql,
            $supplier_confirmation_reference,
            $supplier_contact_name,
            $supplier_contact_number,
            $supplier_contact_email,
            $pickup_address,
            $delivery_address,
            $preferred_pickup_sql,
            $preferred_delivery_sql,
            $package_count,
            $handling_instructions,
            $procurement_remarks,
            $user_id
        );
        $insert_stmt->execute();
        $delivery_request_id = (int) $conn->insert_id;

        if ($delivery_request_id < 1) {
            throw new RuntimeException(
                'The delivery request could not be saved.'
            );
        }

        $plan_stmt = $conn->prepare(
            "INSERT INTO po_delivery_plans (
                delivery_request_id,
                logistics_status
             ) VALUES (?, 'Pending Review')"
        );
        $plan_stmt->bind_param('i', $delivery_request_id);
        $plan_stmt->execute();
    }

    $new_status = $rule['next_status'];
    $new_location = trim((string) $rule['next_location']) !== ''
        ? $rule['next_location']
        : 'Supply Chain Dept.';
    $update_stmt = $conn->prepare(
        "UPDATE purchase_orders
         SET status = ?,
             current_location = ?,
             is_viewed = 0
         WHERE po_id = ?
           AND status = 'Funded'"
    );
    $update_stmt->bind_param(
        'ssi',
        $new_status,
        $new_location,
        $po_id
    );
    $update_stmt->execute();
    if ($update_stmt->affected_rows !== 1) {
        throw new DomainException(
            'The PO status changed before the delivery request could be submitted.'
        );
    }

    $history_remarks = 'Delivery request ' . $request_number .
        ($is_resubmission
            ? ' corrected and resubmitted to Supply Chain.'
            : ' submitted after supplier readiness confirmation.');
    $history_stmt = $conn->prepare(
        "INSERT INTO po_history (
            po_id,
            changed_by,
            status_from,
            status_to,
            remarks
         ) VALUES (?, ?, 'Funded', 'Delivery Requested', ?)"
    );
    $history_stmt->bind_param(
        'iis',
        $po_id,
        $user_id,
        $history_remarks
    );
    $history_stmt->execute();

    complete_po_task_assignment(
        $conn,
        $po_id,
        $user_id,
        $is_resubmission
            ? 'Corrected delivery request resubmitted'
            : 'Delivery request submitted to Supply Chain'
    );
    $assigned_supply_chain_user = phase4b_auto_assign_supply_chain(
        $conn,
        $po_id,
        $user_id
    );

    $notify_target = trim((string) $rule['notify_target']) !== ''
        ? $rule['notify_target']
        : 'Supply Chain';
    create_role_notification(
        $conn,
        $notify_target,
        ($is_resubmission ? 'Corrected delivery request ' : 'Delivery request ') .
            $request_number . ' for PO ' .
            $po['po_number'] .
            ' is ready for logistics review and schedule plotting.'
    );

    log_audit_action(
        $conn,
        $user_id,
        $is_resubmission
            ? 'RESUBMIT_DELIVERY_REQUEST'
            : 'SUBMIT_DELIVERY_REQUEST',
        ($is_resubmission ? 'Resubmitted ' : 'Submitted ') .
            $request_number . ' for PO ' . $po['po_number'] . '.',
        ['status' => 'Funded'],
        [
            'status' => 'Delivery Requested',
            'delivery_request_id' => $delivery_request_id,
            'request_number' => $request_number,
            'request_type' => $request_type,
            'supplier_ready_confirmed_at' => $supplier_ready_sql,
            'assigned_supply_chain_user' => $assigned_supply_chain_user,
        ]
    );

    $conn->commit();

    header(
        'Location: ../view_po.php?id=' . $po_id . '&success=' .
        rawurlencode(
            'Delivery request ' . $request_number .
            ($is_resubmission ? ' resubmitted' : ' submitted') .
            ' to Supply Chain for logistics review.'
        )
    );
    exit();
} catch (Throwable $error) {
    $conn->rollback();

    drms_log_workflow_failure(
        'Delivery request submission for PO ' . $po_id,
        $error
    );

    $public_error = $error instanceof DomainException
        ? $error->getMessage()
        : 'The delivery request could not be submitted. No workflow changes were saved. Please try again.';

    phase4b_redirect($po_id, 'error', $public_error);
}

