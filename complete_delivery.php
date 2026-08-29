<?php
require 'config/db_connect.php';
require 'config/functions.php';
require_once 'config/workflow_feedback.php';

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Supply Chain') {
    header('Location: dashboard.php');
    exit();
}

function phase4d_datetime_label(
    ?string $value,
    string $fallback = 'Not provided'
): string {
    if (!$value || strtotime($value) === false) {
        return $fallback;
    }

    return date('M d, Y · g:i A', strtotime($value));
}

$po_id = isset($_GET['po_id']) ? (int) $_GET['po_id'] : 0;
$current_user_id = (int) $_SESSION['user_id'];
$record = null;
$active_assignment = null;
$existing_receipt = null;
$eligibility_error = '';
$request_error = drms_public_feedback_message(
    $_GET['error'] ?? '',
    'The delivery-completion action could not be completed. No changes were saved.'
);

if ($po_id > 0) {
    try {
        $record_stmt = $conn->prepare(
            "SELECT
                po.po_id,
                po.po_number,
                po.client_name,
                po.amount,
                po.status AS po_status,
                po.current_location,
                delivery_request.delivery_request_id,
                delivery_request.request_number,
                delivery_request.request_type,
                delivery_request.request_status,
                delivery_request.delivery_address,
                delivery_request.pickup_address,
                delivery_request.package_count,
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
                reviewer.full_name AS reviewed_by_name,
                item_total.expected_item_quantity
             FROM purchase_orders po
             INNER JOIN po_delivery_requests delivery_request
                ON delivery_request.po_id = po.po_id
               AND delivery_request.record_status = 'Active'
             INNER JOIN po_delivery_plans plan
                ON plan.delivery_request_id =
                    delivery_request.delivery_request_id
               AND plan.record_status = 'Active'
             LEFT JOIN users reviewer
                ON reviewer.user_id = plan.reviewed_by
             INNER JOIN (
                SELECT
                    po_id,
                    COALESCE(SUM(quantity), 0) AS expected_item_quantity
                FROM po_items
                GROUP BY po_id
             ) item_total
                ON item_total.po_id = po.po_id
             WHERE po.po_id = ?
             ORDER BY delivery_request.request_cycle DESC
             LIMIT 1"
        );
        $record_stmt->bind_param('i', $po_id);
        $record_stmt->execute();
        $record = $record_stmt->get_result()->fetch_assoc();

        if ($record) {
            $active_assignment = get_active_po_task_assignment(
                $conn,
                $po_id
            );

            $receipt_stmt = $conn->prepare(
                "SELECT delivery_receipt_id
                 FROM po_delivery_receipts
                 WHERE po_id = ?
                   AND record_status = 'Active'
                 LIMIT 1"
            );
            $receipt_stmt->bind_param('i', $po_id);
            $receipt_stmt->execute();
            $existing_receipt =
                $receipt_stmt->get_result()->fetch_assoc();

            if ($record['po_status'] !== 'For Pick-up/Delivery') {
                $eligibility_error =
                    'This PO is no longer waiting for delivery completion.';
            } elseif ($record['request_status'] !== 'Scheduled' ||
                $record['logistics_status'] !== 'Scheduled') {
                $eligibility_error =
                    'The delivery request does not have an active approved schedule.';
            } elseif ($existing_receipt) {
                $eligibility_error =
                    'An active client delivery receipt already exists.';
            } elseif (!$active_assignment) {
                $eligibility_error =
                    'This delivery task has no active Supply Chain assignment.';
            } elseif ($active_assignment['assigned_role'] !== 'Supply Chain') {
                $eligibility_error =
                    'This task is assigned to ' .
                    $active_assignment['assigned_role'] . '.';
            } elseif ((int) $active_assignment['assigned_to'] !==
                $current_user_id) {
                $eligibility_error =
                    'This delivery task is assigned to ' .
                    $active_assignment['assignee_name'] . '.';
            }
        }
    } catch (Throwable $error) {
        drms_log_workflow_failure('Delivery completion page load', $error);
        $record = null;
        $eligibility_error =
            'The delivery details could not be loaded. Return to Purchase Orders and try again.';
    }
}

$can_complete = $record && $eligibility_error === '';
$current_datetime = date('Y-m-d\TH:i');
$collection_term_days = 15;
$initial_due_date = (new DateTimeImmutable('now'))
    ->modify('+' . $collection_term_days . ' days')
    ->format('M d, Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complete Client Delivery - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/prf-form.css?v=<?php echo filemtime(__DIR__ . '/assets/css/prf-form.css'); ?>" rel="stylesheet">
    <link href="assets/css/delivery-completion.css?v=<?php echo filemtime(__DIR__ . '/assets/css/delivery-completion.css'); ?>" rel="stylesheet">
    <link href="assets/css/workflow-ui.css?v=<?php echo filemtime(__DIR__ . '/assets/css/workflow-ui.css'); ?>" rel="stylesheet">
</head>
<body class="prf-page delivery-completion-page workflow-ui">
    <?php include 'sidebar.php'; ?>

    <main class="main-content fade-in">
        <div class="container-fluid prf-shell delivery-completion-shell">
            <header class="prf-page-header">
                <a
                    href="<?php echo $po_id > 0 ? 'view_po.php?id=' . $po_id : 'po_list.php?filter=my_tasks'; ?>"
                    class="prf-back-button"
                    aria-label="Back to Purchase Order"
                >
                    <i class="fas fa-arrow-left"></i>
                </a>

                <div class="prf-page-heading">
                    <div class="prf-eyebrow">Supply Chain client handover</div>
                    <h2>Complete client delivery</h2>
                    <p>Record the receiving person and attach acknowledgement evidence before starting the 15-calendar-day Finance collection period.</p>
                </div>

                <?php if ($can_complete): ?>
                    <span class="prf-workflow-chip delivery-completion-chip">
                        <i class="fas fa-box-open"></i>
                        Delivery assigned
                    </span>
                <?php endif; ?>
            </header>

            <?php if ($request_error !== ''): ?>
                <div class="prf-alert prf-alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($request_error); ?></span>
                </div>
            <?php endif; ?>

            <div
                id="deliveryCompletionValidationMessage"
                class="prf-alert prf-alert-danger d-none"
                role="alert"
                aria-live="polite"
            ></div>

            <?php if (!$record): ?>
                <?php if ($eligibility_error !== ''): ?>
                    <section class="prf-alert prf-alert-danger" role="alert">
                        <i class="fas fa-triangle-exclamation"></i>
                        <div>
                            <strong>Delivery completion is unavailable</strong>
                            <span><?php echo htmlspecialchars($eligibility_error); ?></span>
                        </div>
                    </section>
                <?php endif; ?>
                <section class="prf-empty-state">
                    <div class="prf-empty-icon"><i class="fas fa-box-open"></i></div>
                    <h3>No scheduled delivery selected</h3>
                    <p>Select a scheduled PO assigned to your Supply Chain account.</p>
                    <a href="po_list.php?filter=my_tasks" class="btn btn-primary">
                        View my delivery tasks
                    </a>
                </section>

            <?php else: ?>
                <section class="prf-source-card delivery-completion-source">
                    <div class="prf-source-grid delivery-completion-source-grid">
                        <div class="prf-source-item prf-source-item-primary">
                            <span>Purchase Order</span>
                            <strong><?php echo htmlspecialchars($record['po_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Delivery Request</span>
                            <strong><?php echo htmlspecialchars($record['request_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Client</span>
                            <strong><?php echo htmlspecialchars($record['client_name']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Provider</span>
                            <strong><?php echo htmlspecialchars(trim((string) $record['provider_type'] . ' · ' . (string) $record['provider_name'], ' ·')); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Task owner</span>
                            <strong><?php echo htmlspecialchars((string) ($active_assignment['assignee_name'] ?? 'Unassigned')); ?></strong>
                        </div>
                        <div class="prf-source-action">
                            <a href="view_po.php?id=<?php echo $po_id; ?>" class="prf-document-link">
                                <i class="fas fa-eye"></i>
                                View PO
                            </a>
                        </div>
                    </div>
                </section>

                <section class="prf-route-card delivery-completion-route" aria-label="Delivery completion route">
                    <div class="prf-route-label">Current route</div>
                    <div class="prf-route-steps">
                        <div class="prf-route-step delivery-complete-step">
                            <span><i class="fas fa-check"></i></span>
                            <div><strong>Delivery request</strong><small>Procurement completed</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step delivery-complete-step">
                            <span><i class="fas fa-check"></i></span>
                            <div><strong>Logistics schedule</strong><small>Supply Chain approved</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step is-current">
                            <span>3</span>
                            <div><strong>Client receipt</strong><small>Current · Supply Chain</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step">
                            <span>4</span>
                            <div><strong>Collection monitoring</strong><small>Next · Finance</small></div>
                        </div>
                    </div>
                </section>

                <?php if ($eligibility_error !== ''): ?>
                    <section class="prf-alert prf-alert-danger" role="alert">
                        <i class="fas fa-lock"></i>
                        <div>
                            <strong>Delivery completion is blocked</strong>
                            <span><?php echo htmlspecialchars($eligibility_error); ?></span>
                        </div>
                    </section>
                <?php else: ?>
                    <form
                        action="actions/delivery_completion_handler.php"
                        method="POST"
                        enctype="multipart/form-data"
                        id="deliveryCompletionForm"
                        data-collection-term-days="<?php echo $collection_term_days; ?>"
                        novalidate
                    >
                        <input type="hidden" name="action" value="complete_client_delivery">
                        <input type="hidden" name="po_id" value="<?php echo $po_id; ?>">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"
                        >

                        <div class="delivery-completion-layout">
                            <div class="delivery-completion-main">
                                <section class="prf-card delivery-plan-card">
                                    <div class="prf-card-header">
                                        <div>
                                            <span class="prf-section-kicker">Approved logistics plan</span>
                                            <h3>Scheduled movement</h3>
                                        </div>
                                        <span class="delivery-readonly-chip">
                                            <i class="fas fa-lock"></i>
                                            Read-only schedule
                                        </span>
                                    </div>

                                    <div class="delivery-plan-grid">
                                        <div class="delivery-plan-item delivery-plan-primary">
                                            <span>Provider</span>
                                            <strong><?php echo htmlspecialchars((string) $record['provider_name']); ?></strong>
                                            <small><?php echo htmlspecialchars((string) $record['provider_type']); ?></small>
                                        </div>
                                        <div class="delivery-plan-item">
                                            <span>Planned pick-up</span>
                                            <strong><?php echo htmlspecialchars(phase4d_datetime_label($record['planned_pickup_at'], 'Not required')); ?></strong>
                                        </div>
                                        <div class="delivery-plan-item">
                                            <span>Planned delivery</span>
                                            <strong><?php echo htmlspecialchars(phase4d_datetime_label($record['planned_delivery_at'], 'Not required')); ?></strong>
                                        </div>
                                        <div class="delivery-plan-item">
                                            <span>Approved by</span>
                                            <strong><?php echo htmlspecialchars((string) ($record['reviewed_by_name'] ?? 'Supply Chain')); ?></strong>
                                        </div>
                                    </div>

                                    <div class="delivery-destination">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <div>
                                            <span><?php echo $record['request_type'] === 'Client Pick-up' ? 'Client pick-up location' : 'Client delivery address'; ?></span>
                                            <strong><?php echo nl2br(htmlspecialchars((string) ($record['request_type'] === 'Client Pick-up' ? $record['pickup_address'] : $record['delivery_address']))); ?></strong>
                                        </div>
                                    </div>

                                    <?php if (!empty($record['driver_name']) || !empty($record['tracking_reference'])): ?>
                                        <div class="delivery-transport-strip">
                                            <?php if (!empty($record['driver_name'])): ?>
                                                <span><i class="fas fa-user"></i><?php echo htmlspecialchars($record['driver_name']); ?><?php echo !empty($record['driver_contact_number']) ? ' · ' . htmlspecialchars($record['driver_contact_number']) : ''; ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($record['vehicle_type'])): ?>
                                                <span><i class="fas fa-truck"></i><?php echo htmlspecialchars($record['vehicle_type']); ?><?php echo !empty($record['vehicle_plate_number']) ? ' · ' . htmlspecialchars($record['vehicle_plate_number']) : ''; ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($record['tracking_reference'])): ?>
                                                <span><i class="fas fa-barcode"></i><?php echo htmlspecialchars($record['tracking_reference']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </section>

                                <section class="prf-card">
                                    <div class="prf-card-header">
                                        <div>
                                            <span class="prf-section-kicker">Client acknowledgement</span>
                                            <h3>Receiving details and evidence</h3>
                                        </div>
                                        <span class="prf-required-note"><span>*</span> Required fields</span>
                                    </div>

                                    <div class="prf-form-grid delivery-completion-form-grid">
                                        <div class="prf-field">
                                            <label for="actualHandoverAt">Actual handover date and time <span>*</span></label>
                                            <input
                                                type="datetime-local"
                                                name="actual_handover_at"
                                                id="actualHandoverAt"
                                                class="form-control"
                                                value="<?php echo htmlspecialchars($current_datetime); ?>"
                                                max="<?php echo htmlspecialchars($current_datetime); ?>"
                                                required
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="acknowledgementType">Acknowledgement type <span>*</span></label>
                                            <select
                                                name="acknowledgement_type"
                                                id="acknowledgementType"
                                                class="form-select"
                                                required
                                            >
                                                <option value="Signed Delivery Receipt">Signed delivery receipt</option>
                                                <option value="Client Email Confirmation">Client email confirmation</option>
                                                <option value="Electronic Acknowledgement">Electronic acknowledgement</option>
                                                <option value="Other">Other verified acknowledgement</option>
                                            </select>
                                        </div>

                                        <div class="prf-field">
                                            <label for="recipientName">Received by <span>*</span></label>
                                            <input
                                                type="text"
                                                name="recipient_name"
                                                id="recipientName"
                                                class="form-control"
                                                maxlength="150"
                                                placeholder="Client representative's full name"
                                                required
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="recipientPosition">Position / department <small>Optional</small></label>
                                            <input
                                                type="text"
                                                name="recipient_position"
                                                id="recipientPosition"
                                                class="form-control"
                                                maxlength="100"
                                                placeholder="e.g. Procurement Officer"
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="recipientContact">Recipient contact <small>Optional</small></label>
                                            <input
                                                type="text"
                                                name="recipient_contact"
                                                id="recipientContact"
                                                class="form-control"
                                                maxlength="100"
                                                placeholder="Email or contact number"
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="clientReceiptReference">Client receipt reference <small>Optional</small></label>
                                            <input
                                                type="text"
                                                name="client_receipt_reference"
                                                id="clientReceiptReference"
                                                class="form-control"
                                                maxlength="100"
                                                placeholder="Signed DR, email subject, or acknowledgement number"
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="deliveredItemQuantity">Delivered quantity <span>*</span></label>
                                            <input
                                                type="number"
                                                name="delivered_item_quantity"
                                                id="deliveredItemQuantity"
                                                class="form-control"
                                                min="1"
                                                max="1000000"
                                                step="1"
                                                value="<?php echo (int) $record['expected_item_quantity']; ?>"
                                                data-expected-quantity="<?php echo (int) $record['expected_item_quantity']; ?>"
                                                required
                                            >
                                            <small class="prf-help-text">Must equal the complete PO quantity. Partial deliveries cannot close the PO.</small>
                                        </div>

                                        <div class="prf-field">
                                            <label for="deliveryCondition">Delivery condition <span>*</span></label>
                                            <select
                                                name="delivery_condition"
                                                id="deliveryCondition"
                                                class="form-select"
                                                required
                                            >
                                                <option value="Complete and Accepted">Complete and accepted</option>
                                                <option value="Accepted with Noted Issue">Accepted with noted issue</option>
                                            </select>
                                        </div>

                                        <div class="prf-field prf-span-2 is-hidden" data-discrepancy-field>
                                            <label for="discrepancyNotes">Noted issue <span>*</span></label>
                                            <textarea
                                                name="discrepancy_notes"
                                                id="discrepancyNotes"
                                                class="form-control"
                                                rows="2"
                                                maxlength="2000"
                                                placeholder="Describe the accepted issue, shortage concern, packaging damage, or client observation"
                                            ></textarea>
                                        </div>

                                        <div class="prf-field prf-span-2">
                                            <label for="deliveryReceiptProof">Client acknowledgement proof <span>*</span></label>
                                            <label class="prf-file-control delivery-proof-control" for="deliveryReceiptProof">
                                                <i class="fas fa-paperclip"></i>
                                                <span data-delivery-proof-name>Select signed receipt, email, or acknowledgement</span>
                                                <strong>Browse</strong>
                                            </label>
                                            <input
                                                type="file"
                                                name="delivery_receipt_proof"
                                                id="deliveryReceiptProof"
                                                class="visually-hidden"
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                data-delivery-proof
                                                required
                                            >
                                            <small class="prf-help-text">PDF, JPG, or PNG · maximum 10 MB</small>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <aside class="prf-summary-card delivery-completion-summary" aria-label="Client delivery completion summary">
                                <div class="prf-summary-heading">
                                    <span>Completion summary</span>
                                    <small>Final handover</small>
                                </div>

                                <div class="prf-summary-row">
                                    <span>Client receivable</span>
                                    <strong>₱<?php echo number_format((float) $record['amount'], 2); ?></strong>
                                </div>
                                <div class="prf-summary-row">
                                    <span>Expected quantity</span>
                                    <strong><?php echo number_format((int) $record['expected_item_quantity']); ?></strong>
                                </div>
                                <div class="prf-summary-row prf-summary-request">
                                    <span>Collection term</span>
                                    <strong><?php echo $collection_term_days; ?> calendar days</strong>
                                </div>

                                <div class="delivery-due-panel">
                                    <span>Expected collection due</span>
                                    <strong data-collection-due><?php echo htmlspecialchars($initial_due_date); ?></strong>
                                    <small>Automatically calculated from the actual client handover date.</small>
                                </div>

                                <div class="delivery-finance-next">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                    <div>
                                        <strong>Next: Finance collection</strong>
                                        <span>The delivered PO and due date will be assigned to Finance after verified client receipt.</span>
                                    </div>
                                </div>

                                <label class="delivery-completion-confirmation" for="deliveryCompletionConfirmation">
                                    <input
                                        type="checkbox"
                                        name="delivery_completion_confirmation"
                                        value="1"
                                        id="deliveryCompletionConfirmation"
                                        data-delivery-completion-confirm
                                        required
                                    >
                                    <span>I confirm that the complete PO quantity was received by the named client representative and the attached evidence is authentic.</span>
                                </label>

                                <button type="submit" class="prf-submit-button delivery-completion-submit" data-delivery-completion-submit>
                                    <span>Record Client Receipt</span>
                                    <i class="fas fa-check-circle"></i>
                                </button>
                            </aside>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <script src="assets/js/delivery-completion.js?v=<?php echo filemtime(__DIR__ . '/assets/js/delivery-completion.js'); ?>"></script>
</body>
</html>
