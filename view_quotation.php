<?php
require 'config/db_connect.php';
require 'config/functions.php';
require_once 'config/workflow_access.php';

date_default_timezone_set('Asia/Manila');

drms_require_workflow_roles([
    'Sales Staff',
    'Procurement',
    'GM',
    'Finance',
    'President',
]);

$quotation_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $conn->prepare(
    "SELECT q.*, u.full_name AS creator_name, u.role
     FROM quotations q
     LEFT JOIN users u ON q.created_by = u.user_id
     WHERE q.quotation_id = ?"
);
$stmt->bind_param("i", $quotation_id);
$stmt->execute();
$quotation_result = $stmt->get_result();

if ($quotation_result->num_rows === 0) {
    header("Location: quotations_list.php?error=" . rawurlencode("Quotation Not Found"));
    exit();
}

$quote = $quotation_result->fetch_assoc();
$items_data = [];

$item_statement = $conn->prepare(
    "SELECT * FROM quotation_items WHERE quotation_id = ?"
);
$item_statement->bind_param("i", $quotation_id);
$item_statement->execute();
$item_result = $item_statement->get_result();

while ($item = $item_result->fetch_assoc()) {
    $items_data[] = $item;
}

$linked_pr_statement = $conn->prepare(
    "SELECT pr_id, pr_number, status
     FROM purchase_requests
     WHERE quotation_id = ?
     ORDER BY pr_id DESC
     LIMIT 1"
);
$linked_pr_statement->bind_param("i", $quotation_id);
$linked_pr_statement->execute();
$linked_pr = $linked_pr_statement->get_result()->fetch_assoc();

$approval_records = [];
$approval_statement = $conn->prepare(
    "SELECT car.*, u.full_name AS recorded_by_name
     FROM client_approval_records car
     LEFT JOIN users u ON car.recorded_by = u.user_id
     WHERE car.quotation_id = ?
     ORDER BY car.recorded_at DESC, car.approval_record_id DESC"
);
$approval_statement->bind_param("i", $quotation_id);
$approval_statement->execute();
$approval_result = $approval_statement->get_result();

while ($approval_record = $approval_result->fetch_assoc()) {
    $approval_records[] = $approval_record;
}

$official_approval_record = null;
$latest_supporting_record = null;

foreach ($approval_records as $approval_record) {
    if (
        $approval_record['record_status'] === 'Active' &&
        $approval_record['record_type'] === 'Official Client PO' &&
        $official_approval_record === null
    ) {
        $official_approval_record = $approval_record;
    }

    if (
        $approval_record['record_status'] === 'Active' &&
        $approval_record['record_type'] === 'Supporting Confirmation' &&
        $latest_supporting_record === null
    ) {
        $latest_supporting_record = $approval_record;
    }
}

$role = $_SESSION['role'];
$is_sales_staff = $role === 'Sales Staff';
$can_create_pr = $is_sales_staff && $quote['status'] === 'PO Received';
$can_submit_approval = $is_sales_staff && in_array(
    $quote['status'],
    ['Pending Approval', 'Pending PO'],
    true
);

$has_structured_official_po = $official_approval_record !== null;
$has_legacy_approval = !$has_structured_official_po && !empty($quote['client_po_number']);

$status = $quote['status'];
$badge = 'bg-soft-warning';
$icon = 'fa-clock';
$status_label = 'Waiting for Client Approval';

if ($status === 'Pending Approval' && $latest_supporting_record !== null) {
    $badge = 'bg-soft-primary';
    $icon = 'fa-comments';
    $status_label = 'Confirmation Recorded';
} elseif ($status === 'PO Received') {
    $badge = 'bg-soft-success';
    $icon = 'fa-check-double';
    $status_label = $has_structured_official_po
        ? 'Official Client PO Received'
        : 'Legacy Client Approval';
} elseif ($status === 'Converted to PR') {
    $badge = 'bg-soft-primary';
    $icon = 'fa-exchange-alt';
    $status_label = 'Converted to PR';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>View Quotation <?php echo htmlspecialchars($quote['quotation_number']); ?> - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link href="assets/css/client-approval.css?v=<?php echo filemtime(__DIR__ . '/assets/css/client-approval.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="page-view-quotation">
    <?php include 'sidebar.php'; ?>

    <div class="main-content fade-in">
        <div class="container-fluid view-doc-shell" style="max-width: 1200px;">
            <div
                class="view-doc-toolbar view-quotation-toolbar d-flex justify-content-between align-items-center mb-4 no-print bg-white p-3 rounded shadow-sm border"
                style="border-radius: 12px !important;"
            >
                <div class="view-doc-toolbar-main d-flex align-items-center gap-2">
                    <a
                        href="quotations_list.php"
                        class="view-doc-back btn btn-sm btn-light border shadow-sm px-3"
                        style="font-weight: 600; border-radius: 8px;"
                    >
                        <i class="fas fa-arrow-left me-2"></i><span>Back</span>
                    </a>
                    <button
                        type="button"
                        class="view-doc-print btn btn-sm btn-primary shadow-sm px-3 fw-bold"
                        style="border-radius: 8px;"
                        onclick="window.print()"
                    >
                        <i class="fas fa-print me-1"></i><span>Print</span>
                    </button>
                </div>

                <div class="view-doc-toolbar-actions d-flex align-items-center gap-2 text-end">
                    <?php if ($can_submit_approval): ?>
                        <button
                            type="button"
                            class="btn btn-sm btn-outline-primary fw-bold px-3 client-approval-trigger"
                            data-client-approval-trigger
                            data-client-approval-modal-id="clientApprovalModal"
                            data-quotation-id="<?php echo $quotation_id; ?>"
                            data-quotation-number="<?php echo htmlspecialchars($quote['quotation_number'], ENT_QUOTES, 'UTF-8'); ?>"
                        >
                            <i class="fas fa-file-signature me-1"></i> Record Response
                        </button>
                    <?php endif; ?>

                    <?php if ($can_create_pr): ?>
                        <a
                            href="create_pr.php?quotation_id=<?php echo $quotation_id; ?>"
                            class="view-doc-primary-action btn btn-sm btn-success shadow-sm fw-bold px-3"
                            style="border-radius: 8px;"
                        >
                            <i class="fas fa-arrow-right me-1"></i><span>Create PR</span>
                        </a>
                    <?php endif; ?>

                    <span class="view-doc-status badge <?php echo htmlspecialchars($badge); ?> px-3 py-1 shadow-sm">
                        <i class="fas <?php echo htmlspecialchars($icon); ?>"></i>
                        <?php echo htmlspecialchars($status_label); ?>
                    </span>
                </div>
            </div>

            <div class="row g-4 screen-only-cards view-summary-grid">
                <div class="col-md-8">
                    <div class="card shadow-sm border-0 h-100 view-info-card" style="border-radius: 16px;">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center mb-3 pb-2 border-bottom border-light">
                                <div
                                    class="bg-light text-primary rounded-circle d-flex align-items-center justify-content-center me-3"
                                    style="width: 40px; height: 40px;"
                                >
                                    <i class="fas fa-file-contract fs-5"></i>
                                </div>
                                <h6 class="text-uppercase text-dark fw-bold m-0">Quotation Details</h6>
                            </div>

                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <small class="text-muted d-block mb-1">Quotation No.</small>
                                    <span class="fw-bold text-primary fs-5">
                                        #<?php echo htmlspecialchars($quote['quotation_number']); ?>
                                    </span>
                                </div>
                                <div class="col-sm-6">
                                    <small class="text-muted d-block mb-1">Client Name</small>
                                    <span class="fw-bold text-dark fs-5">
                                        <?php echo htmlspecialchars($quote['client_name']); ?>
                                    </span>
                                </div>
                                <div class="col-sm-6">
                                    <small class="text-muted d-block mb-1">Prepared By</small>
                                    <span class="fw-medium text-dark">
                                        <i class="fas fa-user-circle text-muted me-1"></i>
                                        <?php echo htmlspecialchars($quote['creator_name'] ?? 'Unknown'); ?>
                                    </span>
                                </div>
                                <div class="col-sm-6">
                                    <small class="text-muted d-block mb-1">Date Issued</small>
                                    <span class="fw-medium text-dark">
                                        <i class="far fa-calendar-alt text-muted me-1"></i>
                                        <?php echo date('F d, Y h:i A', strtotime($quote['created_at'])); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div
                        class="card shadow-sm border-0 mb-4 bg-primary bg-opacity-10 border-primary view-total-card"
                        style="border-radius: 16px;"
                    >
                        <div class="card-body p-4 text-center">
                            <h6 class="text-uppercase text-primary fw-bold small mb-2">Grand Total Estimate</h6>
                            <h2 class="fw-bold text-primary mb-0">
                                ₱ <?php echo number_format((float) $quote['amount'], 2); ?>
                            </h2>
                        </div>
                    </div>

                    <?php if ($has_structured_official_po): ?>
                        <?php
                        $official_file_link = 'download.php?type=client_approval&record_id=' .
                            (int) $official_approval_record['approval_record_id'];
                        $official_file_extension = strtolower(pathinfo(
                            $official_approval_record['proof_file_path'],
                            PATHINFO_EXTENSION
                        ));
                        $official_file_type = in_array(
                            $official_file_extension,
                            ['jpg', 'jpeg', 'png'],
                            true
                        ) ? 'image' : 'pdf';
                        ?>
                        <div
                            class="card shadow-sm border-0 border-start border-4 border-success quote-approval-card"
                            style="border-radius: 16px;"
                        >
                            <div class="card-body p-4">
                                <h6 class="text-uppercase text-success fw-bold small mb-3">
                                    <i class="fas fa-check-circle me-1"></i> Official Client PO
                                </h6>
                                <small class="text-muted d-block mb-1">Actual PO Number</small>
                                <div class="fw-bold text-dark mb-3">
                                    <?php echo htmlspecialchars($official_approval_record['actual_client_po_number']); ?>
                                </div>

                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <small class="text-muted d-block">PO Date</small>
                                        <span class="small fw-semibold">
                                            <?php echo date('M d, Y', strtotime($official_approval_record['client_po_date'])); ?>
                                        </span>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted d-block">Final Approval</small>
                                        <span class="small fw-semibold">
                                            <?php echo date('M d, Y', strtotime($official_approval_record['final_approval_date'])); ?>
                                        </span>
                                    </div>
                                </div>

                                <small class="text-muted d-block mb-1">Internal Reference</small>
                                <div class="small fw-semibold text-primary mb-3">
                                    <?php echo htmlspecialchars($official_approval_record['internal_reference']); ?>
                                </div>

                                <div class="d-flex gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary flex-fill"
                                        onclick='viewFile(
                                            <?php echo json_encode($official_file_link); ?>,
                                            <?php echo json_encode($official_file_type); ?>
                                        )'
                                    >
                                        <i class="fas fa-eye me-1"></i> View
                                    </button>
                                    <a
                                        href="<?php echo htmlspecialchars($official_file_link); ?>"
                                        download
                                        class="btn btn-sm btn-light border"
                                        title="Download official Client PO"
                                    >
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($has_legacy_approval): ?>
                        <div
                            class="card shadow-sm border-0 border-start border-4 border-secondary quote-approval-card"
                            style="border-radius: 16px;"
                        >
                            <div class="card-body p-4">
                                <h6 class="text-uppercase text-secondary fw-bold small mb-3">
                                    <i class="fas fa-history me-1"></i> Legacy Approval Record
                                </h6>
                                <small class="text-muted d-block mb-1">Existing Reference</small>
                                <div class="fw-bold text-dark mb-2">
                                    <?php echo htmlspecialchars($quote['client_po_number']); ?>
                                </div>
                                <small class="text-muted">
                                    Created before the structured official Client PO workflow.
                                </small>
                            </div>
                        </div>
                    <?php elseif ($latest_supporting_record): ?>
                        <div
                            class="card shadow-sm border-0 border-start border-4 border-primary quote-approval-card"
                            style="border-radius: 16px;"
                        >
                            <div class="card-body p-4">
                                <h6 class="text-uppercase text-primary fw-bold small mb-2">
                                    <i class="fas fa-comments me-1"></i> Confirmation Recorded
                                </h6>
                                <div class="small fw-semibold text-dark">
                                    <?php echo htmlspecialchars($latest_supporting_record['approval_mode']); ?>
                                </div>
                                <div class="small text-primary mt-1">
                                    <?php echo htmlspecialchars($latest_supporting_record['internal_reference']); ?>
                                </div>
                                <p class="text-muted small mt-3 mb-0">
                                    Still waiting for the official signed Client PO.
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div
                            class="card shadow-sm border-0 border-start border-4 border-warning quote-approval-card"
                            style="border-radius: 16px;"
                        >
                            <div class="card-body p-4">
                                <h6 class="text-uppercase text-warning fw-bold small mb-2">
                                    <i class="fas fa-clock me-1"></i> Client Approval
                                </h6>
                                <p class="text-muted small m-0">
                                    Waiting for client confirmation or an official signed Client PO.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($approval_records)): ?>
                <div class="card shadow-sm border-0 mt-4 mb-4 client-approval-timeline no-print">
                    <div class="card-header bg-white border-0 d-flex align-items-center justify-content-between">
                        <h6 class="m-0 fw-bold text-dark">
                            <i class="fas fa-stream me-2 text-primary"></i> Client Approval Records
                        </h6>
                        <span class="badge bg-light text-secondary border"><?php echo count($approval_records); ?></span>
                    </div>

                    <div class="card-body p-0">
                        <?php foreach ($approval_records as $record): ?>
                            <?php
                            $record_is_official = $record['record_type'] === 'Official Client PO';
                            $record_file_link = 'download.php?type=client_approval&record_id=' .
                                (int) $record['approval_record_id'];
                            $record_extension = strtolower(pathinfo(
                                $record['proof_file_path'],
                                PATHINFO_EXTENSION
                            ));
                            $record_file_type = in_array(
                                $record_extension,
                                ['jpg', 'jpeg', 'png'],
                                true
                            ) ? 'image' : 'pdf';
                            ?>
                            <div class="client-approval-timeline-row">
                                <div>
                                    <div class="client-approval-record-title">
                                        <?php if ($record_is_official): ?>
                                            <span class="text-success">Official Client PO</span>
                                        <?php else: ?>
                                            Supporting Confirmation
                                        <?php endif; ?>
                                    </div>
                                    <div class="client-approval-record-sub">
                                        <?php echo htmlspecialchars($record['approval_mode']); ?>
                                        <?php if (!empty($record['recorded_by_name'])): ?>
                                            · <?php echo htmlspecialchars($record['recorded_by_name']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="client-approval-record-reference">
                                    <?php echo htmlspecialchars($record['internal_reference']); ?>
                                    <?php if (!empty($record['actual_client_po_number'])): ?>
                                        <div class="client-approval-record-sub">
                                            PO: <?php echo htmlspecialchars($record['actual_client_po_number']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="client-approval-record-date">
                                    <?php echo date('M d, Y h:i A', strtotime($record['recorded_at'])); ?>
                                </div>

                                <button
                                    type="button"
                                    class="btn btn-sm btn-light border"
                                    onclick='viewFile(
                                        <?php echo json_encode($record_file_link); ?>,
                                        <?php echo json_encode($record_file_type); ?>
                                    )'
                                    title="View proof"
                                >
                                    <i class="fas fa-eye text-primary"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($linked_pr): ?>
                <div
                    class="alert alert-primary border-0 shadow-sm d-flex align-items-center justify-content-between mt-4 mb-4 quote-linked-pr"
                    style="border-radius: 14px;"
                >
                    <div class="d-flex align-items-center gap-3">
                        <div
                            class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center shadow-sm"
                            style="width: 38px; height: 38px;"
                        >
                            <i class="fas fa-link"></i>
                        </div>
                        <div>
                            <div class="fw-bold">
                                Linked Purchase Request: <?php echo htmlspecialchars($linked_pr['pr_number']); ?>
                            </div>
                            <small class="text-muted">Status: <?php echo htmlspecialchars($linked_pr['status']); ?></small>
                        </div>
                    </div>
                    <a
                        href="view_pr.php?id=<?php echo (int) $linked_pr['pr_id']; ?>"
                        class="btn btn-sm btn-outline-primary fw-bold"
                    >
                        View PR
                    </a>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 mb-4 view-items-card" style="border-radius: 16px; overflow: hidden;">
                <div class="card-header bg-white py-3 border-bottom border-light">
                    <h6 class="m-0 fw-bold text-dark">
                        <i class="fas fa-list-ul me-2 text-primary"></i> Quoted Items
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 view-items-table">
                            <thead class="bg-light text-secondary">
                                <tr>
                                    <th class="ps-4 py-3 border-bottom-0">Item & Specifications</th>
                                    <th class="py-3 border-bottom-0">Category & Brand</th>
                                    <th class="text-center py-3 border-bottom-0">Qty</th>
                                    <th class="text-end py-3 border-bottom-0">Unit Price</th>
                                    <th class="text-end pe-4 py-3 border-bottom-0">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items_data as $item): ?>
                                    <tr>
                                        <td class="ps-4 py-3">
                                            <div class="fw-bold text-dark">
                                                <?php echo htmlspecialchars($item['item_name']); ?>
                                            </div>
                                            <?php if (!empty($item['specifications'])): ?>
                                                <div class="text-muted fst-italic mt-1 small">
                                                    <?php echo nl2br(htmlspecialchars($item['specifications'])); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border me-1 px-2 py-1">
                                                <?php echo htmlspecialchars($item['category']); ?>
                                            </span>
                                            <?php if (!empty($item['brand'])): ?>
                                                <span class="badge bg-light text-secondary border px-2 py-1">
                                                    <?php echo htmlspecialchars($item['brand']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center fw-bold text-dark"><?php echo (int) $item['quantity']; ?></td>
                                        <td class="text-end text-muted fw-medium">
                                            ₱<?php echo number_format((float) $item['unit_price'], 2); ?>
                                        </td>
                                        <td class="text-end pe-4 fw-bold text-primary">
                                            ₱<?php echo number_format((float) $item['total_price'], 2); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="view-mobile-grand-total d-md-none" aria-label="Grand Total Estimate">
                <span>Grand Total Estimate</span>
                <strong>₱ <?php echo number_format((float) $quote['amount'], 2); ?></strong>
            </div>
        </div>

        <div class="print-only-quote">
            <div
                class="d-flex justify-content-between align-items-start"
                style="border-bottom: 3px solid #0d6efd; padding-bottom: 20px; margin-bottom: 30px;"
            >
                <div>
                    <h1 class="print-header-brand">Fixie Computer Ventures</h1>
                    <div class="print-header-sub">
                        <strong>Driven by Innovation, Defined by Service.</strong><br>
                        123 Technology Avenue, Tech Hub City, Philippines 1000<br>
                        Phone: (02) 8123-4567 | Email: sales@fixie.com
                    </div>
                </div>
                <div class="text-end">
                    <div class="print-title-doc">QUOTATION</div>
                    <div style="font-size: 13pt; margin-top: 8px; font-weight: 500;">
                        Quote No:
                        <strong style="color: #0d6efd !important;">
                            #<?php echo htmlspecialchars($quote['quotation_number']); ?>
                        </strong>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-7">
                    <div class="info-box h-100">
                        <div class="info-label">Prepared For:</div>
                        <h4 class="fw-bold m-0 text-dark">
                            <?php echo htmlspecialchars($quote['client_name']); ?>
                        </h4>
                    </div>
                </div>
                <div class="col-5">
                    <div class="info-box h-100">
                        <table style="width: 100%; font-size: 9.5pt;">
                            <tr>
                                <td class="info-label">Date Issued:</td>
                                <td style="text-align: right; font-weight: bold;">
                                    <?php echo date('F d, Y', strtotime($quote['created_at'])); ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="info-label">Prepared By:</td>
                                <td style="text-align: right; font-weight: bold;">
                                    <?php echo htmlspecialchars($quote['creator_name'] ?? 'Unknown'); ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <table class="print-table">
                <thead>
                    <tr>
                        <th style="text-align: center; width: 5%;">#</th>
                        <th style="text-align: left; width: 50%;">ITEM DESCRIPTION & SPECIFICATIONS</th>
                        <th style="text-align: center; width: 10%;">QTY</th>
                        <th style="text-align: right; width: 15%;">UNIT PRICE</th>
                        <th style="text-align: right; width: 20%;">TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $counter = 1; ?>
                    <?php foreach ($items_data as $item): ?>
                        <tr>
                            <td style="text-align: center;"><?php echo $counter++; ?></td>
                            <td>
                                <div style="font-weight: bold;"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                <div style="margin-top: 4px;">
                                    <?php echo nl2br(htmlspecialchars($item['specifications'] ?? '')); ?>
                                </div>
                            </td>
                            <td style="text-align: center;"><?php echo (int) $item['quantity']; ?></td>
                            <td style="text-align: right; white-space: nowrap;">
                                ₱ <?php echo number_format((float) $item['unit_price'], 2); ?>
                            </td>
                            <td style="text-align: right; font-weight: bold; white-space: nowrap;">
                                ₱ <?php echo number_format((float) $item['total_price'], 2); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" style="text-align: right; font-weight: bold;">GRAND TOTAL ESTIMATE</td>
                        <td style="text-align: right; font-weight: 900;">
                            ₱ <?php echo number_format((float) $quote['amount'], 2); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <p style="text-align: center; margin-top: 30px; font-size: 9pt; color: #6c757d; font-style: italic;">
                This quotation is subject to terms and conditions. Valid for 30 days from the date of issue.
            </p>
        </div>
    </div>

    <?php
    $client_approval_modal_id = 'clientApprovalModal';
    $client_approval_quotation_id = $quotation_id;
    $client_approval_quotation_number = $quote['quotation_number'];
    require __DIR__ . '/includes/client_approval_modal.php';
    ?>

    <div class="modal fade view-file-preview-modal" id="previewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; overflow: hidden; border: none;">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark">File Preview</h5>
                    <button
                        type="button"
                        class="btn-close shadow-none"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>
                <div
                    class="modal-body text-center p-0 bg-light mt-3"
                    id="previewBody"
                    style="min-height: 400px; display: flex; align-items: center; justify-content: center;"
                ></div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/client-approval-form.js?v=<?php echo filemtime(__DIR__ . '/assets/js/client-approval-form.js'); ?>"></script>

    <script>
        function viewFile(path, type) {
            const modalBody = document.getElementById('previewBody');
            const previewModal = bootstrap.Modal.getOrCreateInstance(
                document.getElementById('previewModal')
            );

            modalBody.innerHTML = '<div class="spinner-border text-primary" role="status"></div>';

            if (type === 'image') {
                const image = document.createElement('img');
                image.src = path;
                image.className = 'img-fluid';
                image.style.maxHeight = '80vh';
                modalBody.replaceChildren(image);
            } else {
                const frame = document.createElement('iframe');
                frame.src = path;
                frame.width = '100%';
                frame.height = '600';
                frame.style.border = 'none';
                modalBody.replaceChildren(frame);
            }

            previewModal.show();
        }

        const Toast = Swal.mixin({
            toast: true,
            position: 'bottom-end',
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true
        });

        const successMessage = <?php echo json_encode($_GET['success'] ?? null); ?>;
        const errorMessage = <?php echo json_encode($_GET['error'] ?? null); ?>;

        if (successMessage) Toast.fire({ icon: 'success', title: successMessage });
        if (errorMessage) Toast.fire({ icon: 'error', title: errorMessage });
    </script>
</body>
</html>
