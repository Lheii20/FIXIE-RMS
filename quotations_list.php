<?php
require 'config/db_connect.php';
require 'config/functions.php';
require_once 'config/workflow_access.php';

drms_require_workflow_roles(['Sales Staff']);

$search = trim((string) ($_GET['search'] ?? ''));
$valid_filters = ['all', 'Pending Approval', 'PO Received', 'Converted to PR'];
$filter = $_GET['filter'] ?? 'all';

if ($filter === 'Pending PO') {
    $filter = 'Pending Approval';
}

if (!in_array($filter, $valid_filters, true)) {
    $filter = 'all';
}

$sql = "
    SELECT
        q.*,
        (
            SELECT car.record_type
            FROM client_approval_records car
            WHERE car.quotation_id = q.quotation_id
              AND car.record_status = 'Active'
            ORDER BY car.recorded_at DESC, car.approval_record_id DESC
            LIMIT 1
        ) AS latest_approval_record_type,
        (
            SELECT car.internal_reference
            FROM client_approval_records car
            WHERE car.quotation_id = q.quotation_id
              AND car.record_status = 'Active'
            ORDER BY car.recorded_at DESC, car.approval_record_id DESC
            LIMIT 1
        ) AS latest_approval_reference
    FROM quotations q
    WHERE 1 = 1
";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= "
        AND (
            q.quotation_number LIKE ?
            OR q.client_name LIKE ?
            OR q.client_po_number LIKE ?
            OR EXISTS (
                SELECT 1
                FROM client_approval_records search_car
                WHERE search_car.quotation_id = q.quotation_id
                  AND (
                    search_car.actual_client_po_number LIKE ?
                    OR search_car.internal_reference LIKE ?
                  )
            )
        )
    ";

    $search_parameter = '%' . $search . '%';

    for ($index = 0; $index < 5; $index++) {
        $params[] = $search_parameter;
    }

    $types .= 'sssss';
}

if ($filter !== 'all') {
    $sql .= " AND q.status = ?";
    $params[] = $filter;
    $types .= 's';
}

$sql .= " ORDER BY q.created_at DESC";
$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Client Quotations - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/compact-mobile-lists.css" rel="stylesheet">
    <link href="assets/css/mobile-drive-lists.css?v=<?php echo filemtime(__DIR__ . '/assets/css/mobile-drive-lists.css'); ?>" rel="stylesheet">
    <link href="assets/css/client-approval.css?v=<?php echo filemtime(__DIR__ . '/assets/css/client-approval.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="page-quotation-list">
    <?php include 'sidebar.php'; ?>

    <div class="main-content fade-in">
        <div class="page-header">
            <div class="list-title-row d-flex align-items-center justify-content-between gap-2">
                <div class="list-title-copy">
                    <h3 class="fw-bold mb-0 text-slate-900 tracking-tight">Client Quotations</h3>
                    <span class="list-title-subtitle text-muted fs-sm d-none d-md-block mt-1">
                        Track quotations, supporting confirmations, and official Client POs
                    </span>
                </div>

                <?php if ($_SESSION['role'] === 'Sales Staff'): ?>
                    <a
                        href="create_quotation.php"
                        class="mobile-list-create-action d-inline-flex d-md-none align-items-center justify-content-center"
                        title="Create Quotation"
                        aria-label="Create Quotation"
                    >
                        <svg
                            class="mobile-list-create-icon"
                            width="22"
                            height="22"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.35"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <path d="M12 5v14M5 12h14"></path>
                        </svg>
                        <span class="visually-hidden">Create Quotation</span>
                    </a>
                <?php endif; ?>
            </div>

            <form method="GET" action="quotations_list.php" class="sleek-filter-bar m-0">
                <div class="sleek-search-group">
                    <button
                        type="submit"
                        class="sleek-search-submit"
                        title="Search quotations"
                        aria-label="Search quotations"
                    >
                        <i class="fas fa-search" aria-hidden="true"></i>
                    </button>
                    <input
                        type="text"
                        name="search"
                        class="sleek-search-input"
                        placeholder="Search QTN, client, PO, or reference"
                        value="<?php echo htmlspecialchars($search); ?>"
                    >
                </div>

                <select name="filter" class="sleek-select" onchange="this.form.submit()">
                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Records</option>
                    <option value="Pending Approval" <?php echo $filter === 'Pending Approval' ? 'selected' : ''; ?>>Waiting for Official PO</option>
                    <option value="PO Received" <?php echo $filter === 'PO Received' ? 'selected' : ''; ?>>Official Client PO Received</option>
                    <option value="Converted to PR" <?php echo $filter === 'Converted to PR' ? 'selected' : ''; ?>>Converted to PR</option>
                </select>

                <?php if ($search !== '' || $filter !== 'all'): ?>
                    <a
                        href="quotations_list.php"
                        class="btn btn-light border d-flex align-items-center justify-content-center btn-reset-filter"
                        title="Reset Filters"
                    >
                        <i class="fas fa-redo-alt text-muted"></i>
                    </a>
                <?php endif; ?>

                <?php if ($_SESSION['role'] === 'Sales Staff'): ?>
                    <a
                        href="create_quotation.php"
                        class="btn-gradient-primary text-decoration-none d-flex align-items-center"
                        title="Create Quotation"
                    >
                        <i class="fas fa-plus me-2"></i> Draft Quotation
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="grid-card">
            <div id="grid-skeleton" class="skeleton-wrapper">
                <?php for ($index = 0; $index < 6; $index++): ?>
                    <div class="skeleton-row border-bottom border-light pb-3">
                        <div class="skeleton-cell skeleton-box"></div>
                        <div class="flex-1">
                            <div class="skeleton-cell w-50 mb-2"></div>
                            <div class="skeleton-cell w-25 h-10px"></div>
                        </div>
                        <div class="skeleton-cell w-15-pct"></div>
                        <div class="skeleton-cell w-15-pct"></div>
                        <div class="skeleton-cell w-15-pct"></div>
                        <div class="skeleton-cell ms-auto w-12-pct"></div>
                    </div>
                <?php endfor; ?>
            </div>

            <div id="grid-content" class="init-hidden">
                <div class="table-responsive-custom">
                    <table id="dataTable" class="table premium-table">
                        <thead>
                            <tr>
                                <th class="w-25-pct">Quotation Details</th>
                                <th class="w-15-pct">Quoted Value</th>
                                <th class="w-15-pct">Client PO / Reference</th>
                                <th class="w-15-pct">Status</th>
                                <th class="w-15-pct">Date Created</th>
                                <th class="w-15-pct text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <?php
                                    $status = $row['status'];
                                    $badge = 'bg-soft-warning';
                                    $icon = 'fa-clock';
                                    $status_label = 'Waiting for Client Approval';

                                    if (
                                        $status === 'Pending Approval' &&
                                        ($row['latest_approval_record_type'] ?? '') === 'Supporting Confirmation'
                                    ) {
                                        $badge = 'bg-soft-primary';
                                        $icon = 'fa-comments';
                                        $status_label = 'Confirmation Recorded';
                                    } elseif ($status === 'PO Received') {
                                        $badge = 'bg-soft-success';
                                        $icon = 'fa-check-double';
                                        $status_label = 'Official Client PO Received';
                                    } elseif ($status === 'Converted to PR') {
                                        $badge = 'bg-soft-primary';
                                        $icon = 'fa-exchange-alt';
                                        $status_label = 'Converted to PR';
                                    }

                                    $quotation_id = (int) $row['quotation_id'];
                                    $quotation_number = (string) $row['quotation_number'];
                                    $client_name = (string) $row['client_name'];
                                    $amount = number_format((float) $row['amount'], 2);
                                    $client_po_number = trim((string) ($row['client_po_number'] ?? ''));
                                    $latest_reference = trim((string) ($row['latest_approval_reference'] ?? ''));
                                    $date_created = !empty($row['created_at'])
                                        ? date('M d, Y', strtotime($row['created_at']))
                                        : '--';
                                    $time_created = !empty($row['created_at'])
                                        ? date('h:i A', strtotime($row['created_at']))
                                        : '--';
                                    ?>
                                    <tr>
                                        <td class="ps-4" data-label="Quotation Details">
                                            <div class="order-info-block">
                                                <div class="doc-icon-box"><i class="fas fa-file-contract"></i></div>
                                                <div class="doc-details">
                                                    <span class="doc-title"><?php echo htmlspecialchars($quotation_number); ?></span>
                                                    <span class="mobile-list-subline">
                                                        <span class="data-label"><?php echo htmlspecialchars($client_name); ?></span>
                                                        <span class="mobile-list-status <?php echo htmlspecialchars($badge); ?>">
                                                            <?php echo htmlspecialchars($status_label); ?>
                                                        </span>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="currency-data" data-label="Quoted Value">₱<?php echo $amount; ?></td>

                                        <td data-label="Client PO / Reference">
                                            <?php if ($client_po_number !== ''): ?>
                                                <span class="data-value text-success">
                                                    <i class="fas fa-file-invoice me-1"></i>
                                                    <?php echo htmlspecialchars($client_po_number); ?>
                                                </span>
                                            <?php elseif ($latest_reference !== ''): ?>
                                                <span class="data-value text-primary">
                                                    <i class="fas fa-link me-1"></i>
                                                    <?php echo htmlspecialchars($latest_reference); ?>
                                                </span>
                                                <span class="data-label d-block">Supporting record</span>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic fs-08rem">Waiting...</span>
                                            <?php endif; ?>
                                        </td>

                                        <td data-label="Status">
                                            <div class="badge-soft <?php echo htmlspecialchars($badge); ?>">
                                                <i class="fas <?php echo htmlspecialchars($icon); ?>"></i>
                                                <?php echo htmlspecialchars($status_label); ?>
                                            </div>
                                        </td>

                                        <td data-label="Date Created">
                                            <span class="data-value d-block fw-normal"><?php echo htmlspecialchars($date_created); ?></span>
                                            <span class="data-label"><?php echo htmlspecialchars($time_created); ?></span>
                                        </td>

                                        <td class="text-end pe-4" data-label="Actions">
                                            <div class="action-flex">
                                                <?php if ($_SESSION['role'] === 'Sales Staff'): ?>
                                                    <?php if (in_array($status, ['Pending Approval', 'Pending PO'], true)): ?>
                                                        <button
                                                            type="button"
                                                            class="btn-quick-act btn-quick-outline client-approval-trigger d-none d-md-inline-flex"
                                                            data-client-approval-trigger
                                                            data-client-approval-modal-id="clientApprovalModal"
                                                            data-quotation-id="<?php echo $quotation_id; ?>"
                                                            data-quotation-number="<?php echo htmlspecialchars($quotation_number, ENT_QUOTES, 'UTF-8'); ?>"
                                                        >
                                                            <i class="fas fa-file-signature me-1"></i> Record Response
                                                        </button>
                                                    <?php endif; ?>

                                                    <?php if ($status === 'PO Received'): ?>
                                                        <a
                                                            href="create_pr.php?quotation_id=<?php echo $quotation_id; ?>"
                                                            class="btn-quick-act btn-quick-approve text-decoration-none"
                                                        >
                                                            <i class="fas fa-arrow-right me-1"></i> Create PR
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <a
                                                    href="view_quotation.php?id=<?php echo $quotation_id; ?>"
                                                    class="btn-view-icon"
                                                    title="View Document"
                                                    aria-label="View quotation"
                                                >
                                                    <i class="fas fa-chevron-right"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php
    $client_approval_modal_id = 'clientApprovalModal';
    $client_approval_quotation_id = '';
    $client_approval_quotation_number = '';
    require __DIR__ . '/includes/client_approval_modal.php';
    ?>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="assets/js/client-approval-form.js?v=<?php echo filemtime(__DIR__ . '/assets/js/client-approval-form.js'); ?>"></script>

    <script>
        $(document).ready(function () {
            try {
                $('#dataTable').DataTable({
                    order: [],
                    bStateSave: false,
                    pageLength: 15,
                    language: {
                        info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                        infoEmpty: 'No entries found',
                        paginate: {
                            previous: "<i class='fas fa-angle-left'></i>",
                            next: "<i class='fas fa-angle-right'></i>"
                        }
                    },
                    dom: 't<"d-flex justify-content-between align-items-center border-top"ip>',
                    initComplete: function () {
                        setTimeout(function () {
                            $('#grid-skeleton').hide();
                            $('#grid-content').fadeIn(300);
                        }, 200);
                    }
                });
            } catch (error) {
                console.error('DataTables Error:', error);
                $('#grid-skeleton').hide();
                $('#grid-content').fadeIn(300);
            }

            setTimeout(function () {
                if ($('#grid-skeleton').is(':visible')) {
                    $('#grid-skeleton').hide();
                    $('#grid-content').fadeIn(300);
                }
            }, 1000);
        });

        const Toast = Swal.mixin({
            toast: true,
            position: 'bottom-end',
            showConfirmButton: false,
            timer: 4000,
            timerProgressBar: true,
            customClass: { popup: 'shadow-lg rounded-3' }
        });

        const successMessage = <?php echo json_encode($_GET['success'] ?? null); ?>;
        const errorMessage = <?php echo json_encode($_GET['error'] ?? null); ?>;

        if (successMessage) Toast.fire({ icon: 'success', title: successMessage });
        if (errorMessage) Toast.fire({ icon: 'error', title: errorMessage });

        if (successMessage || errorMessage) {
            const cleanUrl = new URL(window.location.href);
            cleanUrl.searchParams.delete('success');
            cleanUrl.searchParams.delete('error');
            window.history.replaceState({}, '', cleanUrl);
        }
    </script>
</body>
</html>
