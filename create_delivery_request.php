<?php
require 'config/db_connect.php';
require 'config/functions.php';

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Procurement') {
    header('Location: dashboard.php');
    exit();
}

function phase4b_display_datetime(
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
$po = null;
$active_assignment = null;
$existing_request = null;
$eligibility_error = '';
$item_summary = ['line_count' => 0, 'total_quantity' => 0];

if ($po_id > 0) {
    try {
        $po_stmt = $conn->prepare(
            "SELECT
                po.*,
                pr.pr_number,
                supplier.supplier_name,
                supplier.supplier_reference,
                supplier.payment_method,
                supplier.payment_terms,
                funding.fund_release_id,
                funding.release_method,
                funding.reference_number AS funding_reference,
                funding.released_amount,
                funding.released_at,
                funding.proof_file_path AS funding_proof_path,
                finance_user.full_name AS released_by_name
             FROM purchase_orders po
             INNER JOIN purchase_requests pr
                ON pr.pr_id = po.pr_id
             INNER JOIN pr_supplier_details supplier
                ON supplier.supplier_detail_id = po.supplier_detail_id
               AND supplier.pr_id = po.pr_id
               AND supplier.record_status = 'Active'
             INNER JOIN po_supplier_fund_releases funding
                ON funding.po_id = po.po_id
               AND funding.record_status = 'Active'
             LEFT JOIN users finance_user
                ON finance_user.user_id = funding.released_by
             WHERE po.po_id = ?
               AND po.source_pr_workflow_version = 2
             ORDER BY funding.release_cycle DESC
             LIMIT 1"
        );
        $po_stmt->bind_param('i', $po_id);
        $po_stmt->execute();
        $po = $po_stmt->get_result()->fetch_assoc();

        if ($po) {
            $active_assignment = get_active_po_task_assignment(
                $conn,
                $po_id
            );

            $existing_stmt = $conn->prepare(
                "SELECT
                    delivery_request.*,
                    plan.logistics_status,
                    plan.return_reason
                 FROM po_delivery_requests delivery_request
                 LEFT JOIN po_delivery_plans plan
                    ON plan.delivery_request_id =
                        delivery_request.delivery_request_id
                   AND plan.record_status = 'Active'
                 WHERE delivery_request.po_id = ?
                   AND delivery_request.record_status = 'Active'
                 ORDER BY delivery_request.request_cycle DESC
                 LIMIT 1"
            );
            $existing_stmt->bind_param('i', $po_id);
            $existing_stmt->execute();
            $existing_request =
                $existing_stmt->get_result()->fetch_assoc();

            $item_stmt = $conn->prepare(
                "SELECT
                    COUNT(*) AS line_count,
                    COALESCE(SUM(quantity), 0) AS total_quantity
                 FROM po_items
                 WHERE po_id = ?"
            );
            $item_stmt->bind_param('i', $po_id);
            $item_stmt->execute();
            $item_summary = $item_stmt->get_result()->fetch_assoc();

            if ($po['status'] !== 'Funded') {
                $eligibility_error =
                    'This PO is no longer waiting for a Procurement delivery request.';
            } elseif ($existing_request && !(
                $existing_request['request_status'] === 'Returned' &&
                $existing_request['logistics_status'] === 'Returned'
            )) {
                $eligibility_error =
                    'Delivery request ' . $existing_request['request_number'] .
                    ' already exists with status ' .
                    $existing_request['request_status'] . '.';
            } elseif (!$active_assignment) {
                $eligibility_error =
                    'This PO does not have an active Procurement assignment. Run the Phase 4B handoff migration first.';
            } elseif ($active_assignment['assigned_role'] !== 'Procurement') {
                $eligibility_error =
                    'This task is still assigned to ' .
                    $active_assignment['assigned_role'] .
                    '. Run the Phase 4B handoff migration first.';
            } elseif ((int) $active_assignment['assigned_to'] !==
                $current_user_id) {
                $eligibility_error =
                    'This delivery-request task is assigned to ' .
                    $active_assignment['assignee_name'] . '.';
            }
        }
    } catch (mysqli_sql_exception $error) {
        error_log(
            'Phase 4B delivery request page failed: ' .
            $error->getMessage()
        );
        $po = null;
        $eligibility_error =
            'Phase 4A is not completely installed. Run its database migration before opening this form.';
    }
}

$can_submit = $po && $eligibility_error === '';
$is_correction = $can_submit && $existing_request &&
    $existing_request['request_status'] === 'Returned' &&
    $existing_request['logistics_status'] === 'Returned';
$current_datetime = date('Y-m-d\TH:i');
$tomorrow_pickup = date('Y-m-d\T09:00', strtotime('+1 day'));
$tomorrow_delivery = date('Y-m-d\T14:00', strtotime('+1 day'));
$form_request_type = $is_correction
    ? $existing_request['request_type']
    : 'Pick-up and Delivery';
$form_supplier_ready = $is_correction &&
    !empty($existing_request['supplier_ready_confirmed_at'])
        ? date(
            'Y-m-d\TH:i',
            strtotime($existing_request['supplier_ready_confirmed_at'])
        )
        : $current_datetime;
$form_preferred_pickup = $is_correction &&
    !empty($existing_request['preferred_pickup_at'])
        ? date(
            'Y-m-d\TH:i',
            strtotime($existing_request['preferred_pickup_at'])
        )
        : $tomorrow_pickup;
$form_preferred_delivery = $is_correction &&
    !empty($existing_request['preferred_delivery_at'])
        ? date(
            'Y-m-d\TH:i',
            strtotime($existing_request['preferred_delivery_at'])
        )
        : $tomorrow_delivery;
$funding_proof_url = $po && !empty($po['funding_proof_path'])
    ? 'download.php?type=fund_release&record_id=' .
        (int) $po['fund_release_id']
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prepare Delivery Request - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/prf-form.css?v=<?php echo filemtime(__DIR__ . '/assets/css/prf-form.css'); ?>" rel="stylesheet">
    <link href="assets/css/delivery-request.css?v=<?php echo filemtime(__DIR__ . '/assets/css/delivery-request.css'); ?>" rel="stylesheet">
</head>
<body class="prf-page delivery-request-page">
    <?php include 'sidebar.php'; ?>

    <main class="main-content fade-in">
        <div class="container-fluid prf-shell">
            <header class="prf-page-header">
                <a
                    href="<?php echo $po_id > 0 ? 'view_po.php?id=' . $po_id : 'po_list.php?filter=my_tasks'; ?>"
                    class="prf-back-button"
                    aria-label="Back to Purchase Order"
                >
                    <i class="fas fa-arrow-left"></i>
                </a>

                <div class="prf-page-heading">
                    <div class="prf-eyebrow">Procurement delivery coordination</div>
                    <h2><?php echo $is_correction ? 'Correct delivery request' : 'Prepare delivery request'; ?></h2>
                    <p><?php echo $is_correction ? 'Review the Supply Chain return reason, correct the movement details, and resubmit the same request.' : 'Confirm supplier readiness and send complete movement details to Supply Chain for review and plotting.'; ?></p>
                </div>

                <?php if ($can_submit): ?>
                    <span class="prf-workflow-chip delivery-ready-chip">
                        <i class="fas fa-clipboard-check"></i>
                        Procurement assigned
                    </span>
                <?php endif; ?>
            </header>

            <?php if (isset($_GET['error']) && trim((string) $_GET['error']) !== ''): ?>
                <div class="prf-alert prf-alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars((string) $_GET['error']); ?></span>
                </div>
            <?php endif; ?>

            <div
                id="deliveryRequestValidationMessage"
                class="prf-alert prf-alert-danger d-none"
                role="alert"
                aria-live="polite"
            ></div>

            <?php if (!$po): ?>
                <section class="prf-empty-state">
                    <div class="prf-empty-icon"><i class="fas fa-truck-loading"></i></div>
                    <h3>No eligible funded PO selected</h3>
                    <p>Select a Version 2 funded PO assigned to Procurement.</p>
                    <a href="po_list.php?filter=my_tasks" class="btn btn-primary">
                        View my Procurement tasks
                    </a>
                </section>

            <?php else: ?>
                <section class="prf-source-card delivery-source-card">
                    <div class="prf-source-grid delivery-source-grid">
                        <div class="prf-source-item prf-source-item-primary">
                            <span>Purchase Order</span>
                            <strong><?php echo htmlspecialchars($po['po_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Approved PRF</span>
                            <strong><?php echo htmlspecialchars($po['pr_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Client</span>
                            <strong><?php echo htmlspecialchars($po['client_name']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Supplier</span>
                            <strong><?php echo htmlspecialchars($po['supplier_name']); ?></strong>
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

                <section class="prf-route-card delivery-route-card" aria-label="Delivery workflow route">
                    <div class="prf-route-label">Current route</div>
                    <div class="prf-route-steps">
                        <div class="prf-route-step delivery-route-complete">
                            <span><i class="fas fa-check"></i></span>
                            <div><strong>Supplier funded</strong><small>Finance completed</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step is-current">
                            <span>2</span>
                            <div><strong>Delivery request</strong><small>Current · Procurement</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step">
                            <span>3</span>
                            <div><strong>Logistics plotting</strong><small>Next · Supply Chain</small></div>
                        </div>
                    </div>
                </section>

                <?php if ($is_correction): ?>
                    <section class="delivery-return-notice" role="alert">
                        <i class="fas fa-undo-alt"></i>
                        <div>
                            <strong><?php echo htmlspecialchars($existing_request['request_number']); ?> was returned for correction</strong>
                            <span><?php echo htmlspecialchars((string) $existing_request['return_reason']); ?></span>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($eligibility_error !== ''): ?>
                    <section class="prf-alert prf-alert-danger" role="alert">
                        <i class="fas fa-lock"></i>
                        <div>
                            <strong>Delivery request is blocked</strong>
                            <span><?php echo htmlspecialchars($eligibility_error); ?></span>
                        </div>
                    </section>
                <?php else: ?>
                    <form
                        action="actions/delivery_request_handler.php"
                        method="POST"
                        id="deliveryRequestForm"
                        novalidate
                    >
                        <input type="hidden" name="action" value="submit_delivery_request">
                        <input type="hidden" name="po_id" value="<?php echo $po_id; ?>">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"
                        >

                        <div class="delivery-form-layout">
                            <div class="delivery-main-column">
                                <section class="prf-card">
                                    <div class="prf-card-header">
                                        <div>
                                            <span class="prf-section-kicker">Supplier readiness</span>
                                            <h3>Confirmation and contact</h3>
                                        </div>
                                        <span class="prf-required-note"><span>*</span> Required fields</span>
                                    </div>

                                    <div class="delivery-locked-grid">
                                        <div class="delivery-locked-detail delivery-locked-primary">
                                            <span>Approved supplier</span>
                                            <strong><?php echo htmlspecialchars($po['supplier_name']); ?></strong>
                                        </div>
                                        <div class="delivery-locked-detail">
                                            <span>Supplier reference</span>
                                            <strong><?php echo htmlspecialchars(trim((string) $po['supplier_reference']) !== '' ? $po['supplier_reference'] : 'Not provided'); ?></strong>
                                        </div>
                                        <div class="delivery-locked-detail">
                                            <span>Payment terms</span>
                                            <strong><?php echo htmlspecialchars(trim((string) $po['payment_terms']) !== '' ? $po['payment_terms'] : 'Not provided'); ?></strong>
                                        </div>
                                    </div>

                                    <div class="prf-form-grid delivery-form-grid">
                                        <div class="prf-field">
                                            <label for="supplierReadyAt">Supplier ready confirmation <span>*</span></label>
                                            <input
                                                type="datetime-local"
                                                name="supplier_ready_confirmed_at"
                                                id="supplierReadyAt"
                                                class="form-control"
                                                value="<?php echo htmlspecialchars($form_supplier_ready); ?>"
                                                max="<?php echo htmlspecialchars($current_datetime); ?>"
                                                required
                                            >
                                            <small class="prf-help-text">Actual date and time the supplier confirmed that the items are ready.</small>
                                        </div>

                                        <div class="prf-field">
                                            <label for="supplierConfirmationReference">Confirmation reference <small>Optional</small></label>
                                            <input
                                                type="text"
                                                name="supplier_confirmation_reference"
                                                id="supplierConfirmationReference"
                                                class="form-control"
                                                maxlength="100"
                                                placeholder="Email subject, chat reference, or call note"
                                                value="<?php echo htmlspecialchars((string) ($existing_request['supplier_confirmation_reference'] ?? '')); ?>"
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="supplierContactName">Supplier contact person <small>Optional</small></label>
                                            <input
                                                type="text"
                                                name="supplier_contact_name"
                                                id="supplierContactName"
                                                class="form-control"
                                                maxlength="150"
                                                placeholder="Contact person's full name"
                                                value="<?php echo htmlspecialchars((string) ($existing_request['supplier_contact_name'] ?? '')); ?>"
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="supplierContactNumber">Contact number <small>Optional</small></label>
                                            <input
                                                type="text"
                                                name="supplier_contact_number"
                                                id="supplierContactNumber"
                                                class="form-control"
                                                maxlength="50"
                                                placeholder="e.g. 0917 123 4567"
                                                value="<?php echo htmlspecialchars((string) ($existing_request['supplier_contact_number'] ?? '')); ?>"
                                            >
                                        </div>

                                        <div class="prf-field prf-span-2">
                                            <label for="supplierContactEmail">Contact email <small>Optional</small></label>
                                            <input
                                                type="email"
                                                name="supplier_contact_email"
                                                id="supplierContactEmail"
                                                class="form-control"
                                                maxlength="150"
                                                placeholder="supplier@example.com"
                                                value="<?php echo htmlspecialchars((string) ($existing_request['supplier_contact_email'] ?? '')); ?>"
                                            >
                                        </div>
                                    </div>
                                </section>

                                <section class="prf-card">
                                    <div class="prf-card-header">
                                        <div>
                                            <span class="prf-section-kicker">Movement request</span>
                                            <h3>Pick-up and delivery details</h3>
                                        </div>
                                        <span class="delivery-section-note">
                                            <i class="fas fa-route"></i>
                                            Supply Chain confirms the final schedule
                                        </span>
                                    </div>

                                    <div class="prf-form-grid delivery-form-grid">
                                        <div class="prf-field prf-span-2">
                                            <label for="deliveryRequestType">Request type <span>*</span></label>
                                            <select
                                                name="request_type"
                                                id="deliveryRequestType"
                                                class="form-select"
                                                required
                                            >
                                                <option value="Pick-up and Delivery" <?php echo $form_request_type === 'Pick-up and Delivery' ? 'selected' : ''; ?>>Pick-up from supplier and deliver to client</option>
                                                <option value="Delivery Only" <?php echo $form_request_type === 'Delivery Only' ? 'selected' : ''; ?>>Supplier or courier delivery to client</option>
                                                <option value="Client Pick-up" <?php echo $form_request_type === 'Client Pick-up' ? 'selected' : ''; ?>>Client will pick up the items</option>
                                            </select>
                                        </div>

                                        <div class="prf-field prf-span-2" data-pickup-field>
                                            <label for="pickupAddress">Pick-up location <span>*</span></label>
                                            <textarea
                                                name="pickup_address"
                                                id="pickupAddress"
                                                class="form-control"
                                                rows="2"
                                                maxlength="2000"
                                                placeholder="Complete supplier or client pick-up location"
                                                required
                                            ><?php echo htmlspecialchars((string) ($existing_request['pickup_address'] ?? '')); ?></textarea>
                                        </div>

                                        <div class="prf-field prf-span-2" data-delivery-field>
                                            <label for="deliveryAddress">Client delivery address <span>*</span></label>
                                            <textarea
                                                name="delivery_address"
                                                id="deliveryAddress"
                                                class="form-control"
                                                rows="2"
                                                maxlength="2000"
                                                placeholder="Complete delivery address, building, floor, and landmark"
                                                required
                                            ><?php echo htmlspecialchars((string) ($existing_request['delivery_address'] ?? '')); ?></textarea>
                                        </div>

                                        <div class="prf-field" data-pickup-field>
                                            <label for="preferredPickupAt">Preferred pick-up <span>*</span></label>
                                            <input
                                                type="datetime-local"
                                                name="preferred_pickup_at"
                                                id="preferredPickupAt"
                                                class="form-control"
                                                value="<?php echo htmlspecialchars($form_preferred_pickup); ?>"
                                                min="<?php echo htmlspecialchars($current_datetime); ?>"
                                                required
                                            >
                                        </div>

                                        <div class="prf-field" data-delivery-field>
                                            <label for="preferredDeliveryAt">Preferred delivery <span>*</span></label>
                                            <input
                                                type="datetime-local"
                                                name="preferred_delivery_at"
                                                id="preferredDeliveryAt"
                                                class="form-control"
                                                value="<?php echo htmlspecialchars($form_preferred_delivery); ?>"
                                                min="<?php echo htmlspecialchars($current_datetime); ?>"
                                                required
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="packageCount">Package or unit count <span>*</span></label>
                                            <input
                                                type="number"
                                                name="package_count"
                                                id="packageCount"
                                                class="form-control"
                                                min="1"
                                                max="100000"
                                                step="1"
                                                value="<?php echo $is_correction ? max(1, (int) $existing_request['package_count']) : max(1, (int) $item_summary['total_quantity']); ?>"
                                                required
                                            >
                                            <small class="prf-help-text">Adjust this if several ordered units are packed together.</small>
                                        </div>

                                        <div class="prf-field">
                                            <label for="handlingInstructions">Handling instructions <small>Optional</small></label>
                                            <textarea
                                                name="handling_instructions"
                                                id="handlingInstructions"
                                                class="form-control"
                                                rows="2"
                                                maxlength="2000"
                                                placeholder="Fragile items, loading access, required equipment, or site restrictions"
                                            ><?php echo htmlspecialchars((string) ($existing_request['handling_instructions'] ?? '')); ?></textarea>
                                        </div>

                                        <div class="prf-field prf-span-2">
                                            <label for="procurementRemarks">Procurement remarks <small>Optional</small></label>
                                            <textarea
                                                name="procurement_remarks"
                                                id="procurementRemarks"
                                                class="form-control"
                                                rows="2"
                                                maxlength="2000"
                                                placeholder="Concise coordination note for Supply Chain"
                                            ><?php echo htmlspecialchars((string) ($existing_request['procurement_remarks'] ?? '')); ?></textarea>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <aside class="prf-summary-card delivery-summary-card" aria-label="Delivery request summary">
                                <div class="prf-summary-heading">
                                    <span>Request summary</span>
                                    <small>Verified source</small>
                                </div>

                                <div class="prf-summary-row">
                                    <span>PO line items</span>
                                    <strong><?php echo number_format((int) $item_summary['line_count']); ?></strong>
                                </div>
                                <div class="prf-summary-row">
                                    <span>Ordered quantity</span>
                                    <strong><?php echo number_format((int) $item_summary['total_quantity']); ?></strong>
                                </div>
                                <div class="prf-summary-row prf-summary-request">
                                    <span>Supplier funding</span>
                                    <strong>₱<?php echo number_format((float) $po['released_amount'], 2); ?></strong>
                                </div>

                                <div class="delivery-funding-panel">
                                    <div>
                                        <span>Funding reference</span>
                                        <strong><?php echo htmlspecialchars($po['funding_reference']); ?></strong>
                                    </div>
                                    <small>
                                        <?php echo htmlspecialchars($po['release_method']); ?> ·
                                        <?php echo htmlspecialchars(phase4b_display_datetime($po['released_at'])); ?>
                                    </small>
                                    <?php if ($funding_proof_url !== ''): ?>
                                        <a href="<?php echo htmlspecialchars($funding_proof_url); ?>" target="_blank" rel="noopener">
                                            <i class="fas fa-paperclip"></i>
                                            View payment proof
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <div class="delivery-next-step">
                                    <i class="fas fa-calendar-check"></i>
                                    <div>
                                        <strong>Next: logistics review</strong>
                                        <span>Supply Chain checks availability, provider, route, and final schedule.</span>
                                    </div>
                                </div>

                                <label class="delivery-confirmation" for="deliveryConfirmation">
                                    <input
                                        type="checkbox"
                                        name="delivery_confirmation"
                                        value="1"
                                        id="deliveryConfirmation"
                                        data-delivery-confirm
                                        required
                                    >
                                    <span>I confirm that the supplier is ready and the submitted addresses and preferred schedules are accurate.</span>
                                </label>

                                <button type="submit" class="prf-submit-button" data-delivery-submit>
                                    <span><?php echo $is_correction ? 'Resubmit to Supply Chain' : 'Submit to Supply Chain'; ?></span>
                                    <i class="fas fa-arrow-right"></i>
                                </button>
                            </aside>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <script src="assets/js/delivery-request.js?v=<?php echo filemtime(__DIR__ . '/assets/js/delivery-request.js'); ?>"></script>
</body>
</html>
