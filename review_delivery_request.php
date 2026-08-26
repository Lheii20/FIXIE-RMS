<?php
require 'config/db_connect.php';
require 'config/functions.php';

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Supply Chain') {
    header('Location: dashboard.php');
    exit();
}

function phase4c_datetime_value(?string $value): string
{
    if (!$value || strtotime($value) === false) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($value));
}

function phase4c_datetime_label(
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
$eligibility_error = '';

if ($po_id > 0) {
    try {
        $record_stmt = $conn->prepare(
            "SELECT
                po.po_id,
                po.po_number,
                po.client_name,
                po.status AS po_status,
                po.current_location,
                po.source_pr_workflow_version,
                pr.pr_number,
                delivery_request.*,
                plan.delivery_plan_id,
                plan.logistics_status,
                preparer.full_name AS prepared_by_name,
                funding.reference_number AS funding_reference,
                funding.released_amount,
                funding.released_at
             FROM purchase_orders po
             INNER JOIN purchase_requests pr
                ON pr.pr_id = po.pr_id
             INNER JOIN po_delivery_requests delivery_request
                ON delivery_request.po_id = po.po_id
               AND delivery_request.record_status = 'Active'
             INNER JOIN po_delivery_plans plan
                ON plan.delivery_request_id =
                    delivery_request.delivery_request_id
               AND plan.record_status = 'Active'
             LEFT JOIN users preparer
                ON preparer.user_id = delivery_request.prepared_by
             LEFT JOIN po_supplier_fund_releases funding
                ON funding.fund_release_id =
                    delivery_request.fund_release_id
               AND funding.record_status = 'Active'
             WHERE po.po_id = ?
               AND po.source_pr_workflow_version = 2
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

            if ($record['po_status'] !== 'Delivery Requested') {
                $eligibility_error =
                    'This PO is no longer waiting for logistics review.';
            } elseif ($record['request_status'] !== 'Submitted') {
                $eligibility_error =
                    'This delivery request is currently ' .
                    $record['request_status'] . '.';
            } elseif ($record['logistics_status'] !== 'Pending Review') {
                $eligibility_error =
                    'This logistics plan is currently ' .
                    $record['logistics_status'] . '.';
            } elseif (!$active_assignment) {
                $eligibility_error =
                    'This logistics review has no active Supply Chain assignment.';
            } elseif ($active_assignment['assigned_role'] !== 'Supply Chain') {
                $eligibility_error =
                    'This task is assigned to ' .
                    $active_assignment['assigned_role'] . '.';
            } elseif ((int) $active_assignment['assigned_to'] !==
                $current_user_id) {
                $eligibility_error =
                    'This logistics review is assigned to ' .
                    $active_assignment['assignee_name'] . '.';
            }
        }
    } catch (mysqli_sql_exception $error) {
        error_log(
            'Phase 4C logistics review page failed: ' .
            $error->getMessage()
        );
        $record = null;
        $eligibility_error =
            'The delivery logistics foundation is unavailable. Verify Phase 4A and Phase 4B.';
    }
}

$can_review = $record && $eligibility_error === '';
$current_datetime = date('Y-m-d\TH:i');
$planned_pickup_value = $record
    ? phase4c_datetime_value($record['preferred_pickup_at'])
    : '';
$planned_delivery_value = $record
    ? phase4c_datetime_value($record['preferred_delivery_at'])
    : '';
$default_provider_type = 'Company Fleet';
$default_provider_name = 'Fixie Computer Ventures';
if ($record && $record['request_type'] === 'Delivery Only') {
    $default_provider_type = 'Supplier Delivery';
    $default_provider_name = $record['supplier_name_snapshot'];
} elseif ($record && $record['request_type'] === 'Client Pick-up') {
    $default_provider_type = 'Client Pick-up';
    $default_provider_name = $record['client_name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logistics Review - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/prf-form.css?v=<?php echo filemtime(__DIR__ . '/assets/css/prf-form.css'); ?>" rel="stylesheet">
    <link href="assets/css/logistics-review.css?v=<?php echo filemtime(__DIR__ . '/assets/css/logistics-review.css'); ?>" rel="stylesheet">
</head>
<body class="prf-page logistics-review-page">
    <?php include 'sidebar.php'; ?>

    <main class="main-content fade-in">
        <div class="container-fluid prf-shell logistics-shell">
            <header class="prf-page-header">
                <a
                    href="<?php echo $po_id > 0 ? 'view_po.php?id=' . $po_id : 'po_list.php?filter=my_tasks'; ?>"
                    class="prf-back-button"
                    aria-label="Back to Purchase Order"
                >
                    <i class="fas fa-arrow-left"></i>
                </a>

                <div class="prf-page-heading">
                    <div class="prf-eyebrow">Supply Chain logistics control</div>
                    <h2>Review and plot delivery</h2>
                    <p>Validate the Procurement request, select the actual provider, and set the final executable schedule.</p>
                </div>

                <?php if ($can_review): ?>
                    <span class="prf-workflow-chip logistics-assigned-chip">
                        <i class="fas fa-route"></i>
                        Supply Chain assigned
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
                id="logisticsValidationMessage"
                class="prf-alert prf-alert-danger d-none"
                role="alert"
                aria-live="polite"
            ></div>

            <?php if (!$record): ?>
                <section class="prf-empty-state">
                    <div class="prf-empty-icon"><i class="fas fa-route"></i></div>
                    <h3>No delivery request selected</h3>
                    <p>Select a submitted delivery request assigned to Supply Chain.</p>
                    <a href="po_list.php?filter=my_tasks" class="btn btn-primary">
                        View my Supply Chain tasks
                    </a>
                </section>

            <?php else: ?>
                <section class="prf-source-card logistics-source-card">
                    <div class="prf-source-grid logistics-source-grid">
                        <div class="prf-source-item prf-source-item-primary">
                            <span>Delivery Request</span>
                            <strong><?php echo htmlspecialchars($record['request_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Purchase Order</span>
                            <strong><?php echo htmlspecialchars($record['po_number']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Client</span>
                            <strong><?php echo htmlspecialchars($record['client_name']); ?></strong>
                        </div>
                        <div class="prf-source-item">
                            <span>Request type</span>
                            <strong><?php echo htmlspecialchars($record['request_type']); ?></strong>
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

                <section class="prf-route-card logistics-route-card" aria-label="Delivery workflow route">
                    <div class="prf-route-label">Current route</div>
                    <div class="prf-route-steps">
                        <div class="prf-route-step logistics-route-complete">
                            <span><i class="fas fa-check"></i></span>
                            <div><strong>Supplier funded</strong><small>Finance completed</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step logistics-route-complete">
                            <span><i class="fas fa-check"></i></span>
                            <div><strong>Delivery request</strong><small>Procurement submitted</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step is-current">
                            <span>3</span>
                            <div><strong>Logistics plotting</strong><small>Current · Supply Chain</small></div>
                        </div>
                        <i class="fas fa-chevron-right prf-route-arrow"></i>
                        <div class="prf-route-step">
                            <span>4</span>
                            <div><strong>Delivery execution</strong><small>Next</small></div>
                        </div>
                    </div>
                </section>

                <?php if ($eligibility_error !== ''): ?>
                    <section class="prf-alert prf-alert-danger" role="alert">
                        <i class="fas fa-lock"></i>
                        <div>
                            <strong>Logistics review is blocked</strong>
                            <span><?php echo htmlspecialchars($eligibility_error); ?></span>
                        </div>
                    </section>
                <?php else: ?>
                    <form
                        action="actions/delivery_request_handler.php"
                        method="POST"
                        id="logisticsReviewForm"
                        novalidate
                    >
                        <input
                            type="hidden"
                            name="action"
                            id="logisticsAction"
                            value="approve_delivery_schedule"
                        >
                        <input type="hidden" name="po_id" value="<?php echo $po_id; ?>">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"
                        >

                        <div class="logistics-layout">
                            <div class="logistics-main-column">
                                <section class="prf-card logistics-request-card">
                                    <div class="prf-card-header">
                                        <div>
                                            <span class="prf-section-kicker">Procurement submission</span>
                                            <h3>Requested movement details</h3>
                                        </div>
                                        <span class="logistics-readonly-chip">
                                            <i class="fas fa-lock"></i>
                                            Read-only request
                                        </span>
                                    </div>

                                    <div class="logistics-request-grid">
                                        <div class="logistics-request-item logistics-request-primary">
                                            <span>Supplier</span>
                                            <strong><?php echo htmlspecialchars($record['supplier_name_snapshot']); ?></strong>
                                        </div>
                                        <div class="logistics-request-item">
                                            <span>Supplier ready</span>
                                            <strong><?php echo htmlspecialchars(phase4c_datetime_label($record['supplier_ready_confirmed_at'])); ?></strong>
                                        </div>
                                        <div class="logistics-request-item">
                                            <span>Package / unit count</span>
                                            <strong><?php echo number_format((int) $record['package_count']); ?></strong>
                                        </div>
                                        <div class="logistics-request-item">
                                            <span>Prepared by</span>
                                            <strong><?php echo htmlspecialchars((string) ($record['prepared_by_name'] ?? 'Procurement')); ?></strong>
                                        </div>
                                    </div>

                                    <div class="logistics-address-grid">
                                        <?php if (!empty($record['pickup_address'])): ?>
                                            <div>
                                                <span>Requested pick-up location</span>
                                                <strong><?php echo nl2br(htmlspecialchars($record['pickup_address'])); ?></strong>
                                                <small><?php echo htmlspecialchars(phase4c_datetime_label($record['preferred_pickup_at'], 'No preferred pick-up schedule')); ?></small>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($record['delivery_address'])): ?>
                                            <div>
                                                <span>Requested delivery address</span>
                                                <strong><?php echo nl2br(htmlspecialchars($record['delivery_address'])); ?></strong>
                                                <small><?php echo htmlspecialchars(phase4c_datetime_label($record['preferred_delivery_at'], 'No preferred delivery schedule')); ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($record['handling_instructions']) || !empty($record['procurement_remarks'])): ?>
                                        <div class="logistics-request-notes">
                                            <?php if (!empty($record['handling_instructions'])): ?>
                                                <div><span>Handling</span><p><?php echo nl2br(htmlspecialchars($record['handling_instructions'])); ?></p></div>
                                            <?php endif; ?>
                                            <?php if (!empty($record['procurement_remarks'])): ?>
                                                <div><span>Procurement note</span><p><?php echo nl2br(htmlspecialchars($record['procurement_remarks'])); ?></p></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </section>

                                <section class="prf-card">
                                    <div class="prf-card-header">
                                        <div>
                                            <span class="prf-section-kicker">Approved logistics plan</span>
                                            <h3>Provider and final schedule</h3>
                                        </div>
                                        <span class="prf-required-note"><span>*</span> Required fields</span>
                                    </div>

                                    <div class="prf-form-grid logistics-form-grid">
                                        <div class="prf-field">
                                            <label for="providerType">Provider type <span>*</span></label>
                                            <select
                                                name="provider_type"
                                                id="providerType"
                                                class="form-select"
                                                required
                                            >
                                                <?php foreach (['Company Fleet', 'Third-Party Logistics', 'Supplier Delivery', 'Client Pick-up'] as $provider_option): ?>
                                                    <option value="<?php echo htmlspecialchars($provider_option); ?>" <?php echo $default_provider_type === $provider_option ? 'selected' : ''; ?>><?php echo htmlspecialchars($provider_option); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="prf-field">
                                            <label for="providerName">Provider / responsible party <span data-provider-required>*</span></label>
                                            <input
                                                type="text"
                                                name="provider_name"
                                                id="providerName"
                                                class="form-control"
                                                maxlength="150"
                                                value="<?php echo htmlspecialchars($default_provider_name); ?>"
                                                placeholder="Company, courier, supplier, or client"
                                            >
                                        </div>

                                        <div class="prf-field" data-logistics-pickup>
                                            <label for="plannedPickupAt">Final pick-up schedule <span>*</span></label>
                                            <input
                                                type="datetime-local"
                                                name="planned_pickup_at"
                                                id="plannedPickupAt"
                                                class="form-control"
                                                value="<?php echo htmlspecialchars($planned_pickup_value); ?>"
                                                min="<?php echo htmlspecialchars($current_datetime); ?>"
                                            >
                                        </div>

                                        <div class="prf-field" data-logistics-delivery>
                                            <label for="plannedDeliveryAt">Final delivery schedule <span>*</span></label>
                                            <input
                                                type="datetime-local"
                                                name="planned_delivery_at"
                                                id="plannedDeliveryAt"
                                                class="form-control"
                                                value="<?php echo htmlspecialchars($planned_delivery_value); ?>"
                                                min="<?php echo htmlspecialchars($current_datetime); ?>"
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="driverName">Driver / rider <small>Optional</small></label>
                                            <input
                                                type="text"
                                                name="driver_name"
                                                id="driverName"
                                                class="form-control"
                                                maxlength="150"
                                                placeholder="Assigned driver or rider"
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="driverContactNumber">Driver contact <small>Optional</small></label>
                                            <input
                                                type="text"
                                                name="driver_contact_number"
                                                id="driverContactNumber"
                                                class="form-control"
                                                maxlength="50"
                                                placeholder="Contact number"
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="vehicleType">Vehicle / service type <small>Optional</small></label>
                                            <input
                                                type="text"
                                                name="vehicle_type"
                                                id="vehicleType"
                                                class="form-control"
                                                maxlength="100"
                                                placeholder="Van, motorcycle, truck, courier service"
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="vehiclePlateNumber">Plate number <small>Optional</small></label>
                                            <input
                                                type="text"
                                                name="vehicle_plate_number"
                                                id="vehiclePlateNumber"
                                                class="form-control"
                                                maxlength="50"
                                                placeholder="Vehicle plate or unit number"
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="trackingReference">Tracking / booking reference <small>Optional</small></label>
                                            <input
                                                type="text"
                                                name="tracking_reference"
                                                id="trackingReference"
                                                class="form-control"
                                                maxlength="100"
                                                placeholder="Courier booking or trip reference"
                                            >
                                        </div>

                                        <div class="prf-field">
                                            <label for="routeOrPlotNotes">Route / plotting note <small>Optional</small></label>
                                            <textarea
                                                name="route_or_plot_notes"
                                                id="routeOrPlotNotes"
                                                class="form-control"
                                                rows="2"
                                                maxlength="2000"
                                                placeholder="Loading window, route sequence, access instructions, or coordination note"
                                            ></textarea>
                                        </div>
                                    </div>
                                </section>
                            </div>

                            <aside class="prf-summary-card logistics-summary-card" aria-label="Logistics approval summary">
                                <div class="prf-summary-heading">
                                    <span>Review summary</span>
                                    <small>Pending approval</small>
                                </div>

                                <div class="prf-summary-row">
                                    <span>Request cycle</span>
                                    <strong><?php echo (int) $record['request_cycle']; ?></strong>
                                </div>
                                <div class="prf-summary-row">
                                    <span>Funded amount</span>
                                    <strong>₱<?php echo number_format((float) $record['released_amount'], 2); ?></strong>
                                </div>
                                <div class="prf-summary-row prf-summary-request">
                                    <span>Logistics status</span>
                                    <strong><?php echo htmlspecialchars($record['logistics_status']); ?></strong>
                                </div>

                                <div class="logistics-control-note">
                                    <i class="fas fa-shield-alt"></i>
                                    <div>
                                        <strong>Independent logistics check</strong>
                                        <span>The final provider and schedule are controlled by the assigned Supply Chain reviewer.</span>
                                    </div>
                                </div>

                                <label class="logistics-confirmation" for="logisticsConfirmation">
                                    <input
                                        type="checkbox"
                                        name="logistics_confirmation"
                                        value="1"
                                        id="logisticsConfirmation"
                                        data-logistics-confirm
                                        required
                                    >
                                    <span>I confirm that the provider and final schedule are workable and ready for execution.</span>
                                </label>

                                <button type="submit" class="prf-submit-button logistics-approve-button" data-logistics-submit>
                                    <span>Approve &amp; Schedule</span>
                                    <i class="fas fa-calendar-check"></i>
                                </button>
                                <button type="button" class="logistics-return-button" data-return-open>
                                    <i class="fas fa-undo-alt"></i>
                                    Return for Correction
                                </button>
                            </aside>
                        </div>

                        <div class="logistics-return-overlay" data-return-overlay hidden>
                            <div class="logistics-return-dialog" role="dialog" aria-modal="true" aria-labelledby="returnDialogTitle">
                                <button type="button" class="logistics-return-close" data-return-close aria-label="Close return dialog">
                                    <i class="fas fa-times"></i>
                                </button>
                                <div class="logistics-return-icon"><i class="fas fa-undo-alt"></i></div>
                                <h3 id="returnDialogTitle">Return to Procurement</h3>
                                <p>State exactly what must be corrected. The same DRF number will be reopened for Procurement.</p>
                                <label for="returnReason">Return reason <span>*</span></label>
                                <textarea
                                    name="return_reason"
                                    id="returnReason"
                                    class="form-control"
                                    rows="4"
                                    minlength="10"
                                    maxlength="500"
                                    placeholder="Explain the incorrect or incomplete address, schedule, contact, or movement detail"
                                ></textarea>
                                <small><span data-return-count>0</span>/500 characters · minimum 10</small>
                                <div class="logistics-return-actions">
                                    <button type="button" data-return-close>Cancel</button>
                                    <button type="button" data-return-confirm>
                                        Return Request
                                        <i class="fas fa-arrow-left"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <?php if ($record): ?>
        <script>
            window.fixieLogisticsRequest = <?php echo json_encode([
                'requestType' => $record['request_type'],
                'supplierName' => $record['supplier_name_snapshot'],
                'clientName' => $record['client_name'],
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        </script>
    <?php endif; ?>
    <script src="assets/js/logistics-review.js?v=<?php echo filemtime(__DIR__ . '/assets/js/logistics-review.js'); ?>"></script>
</body>
</html>
