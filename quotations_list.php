<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';
if(!isset($_SESSION['user_id'])) header("Location: index.php");

$search = $_GET['search'] ?? '';
$valid_filters = ['all', 'Pending PO', 'PO Received', 'Converted to PR'];
$filter = (isset($_GET['filter']) && in_array($_GET['filter'], $valid_filters)) ? $_GET['filter'] : 'all';

$sql = "SELECT * FROM quotations WHERE 1=1";
$params = [];
$types = "";

// Search Logic
if (!empty($search)) {
    $sql .= " AND (quotation_number LIKE ? OR client_name LIKE ? OR client_po_number LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "sss";
}

// Filter Logic
if ($filter != 'all') {
    $sql .= " AND status = ?";
    $params[] = $filter;
    $types .= "s";
}

$sql .= " ORDER BY created_at DESC";
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
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <!-- Import premium font for sleek typography -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body, .main-content {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7fb;
            color: #334155;
        }

        /* Top Header Design */
        .page-header {
            background: #ffffff;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        /* Controls */
        .sleek-filter-bar {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .sleek-search-group {
            position: relative;
            min-width: 280px;
        }
        .sleek-search-group i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }
        .sleek-search-input {
            width: 100%;
            padding: 0.65rem 1rem 0.65rem 2.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
            font-size: 0.85rem;
            transition: 0.2s;
        }
        .sleek-search-input:focus {
            background: #ffffff;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            outline: none;
        }

        .sleek-select {
            padding: 0.65rem 2rem 0.65rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 0.75rem center/12px;
            font-size: 0.85rem;
            color: #334155;
            appearance: none;
            cursor: pointer;
            transition: 0.2s;
        }
        .sleek-select:focus { border-color: #3b82f6; outline: none; }

        .btn-gradient-primary {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 0.65rem 1.5rem;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.2px;
            transition: 0.3s ease;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
        }
        .btn-gradient-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(37, 99, 235, 0.3);
            color: #ffffff;
        }

        /* Main Data Grid Card */
        .grid-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            border: 1px solid #f1f5f9;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .table-responsive-custom {
            width: 100%;
            max-height: calc(100vh - 270px);
            overflow-y: auto;
            overflow-x: auto;
        }

        .premium-table {
            width: 100% !important;
            margin: 0 !important;
            border-collapse: separate !important;
            border-spacing: 0;
        }

        .premium-table thead th {
            position: sticky;
            top: 0;
            background: #f8fafc !important;
            color: #64748b !important;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 1rem 1.5rem !important;
            border-bottom: 1px solid #e2e8f0 !important;
            z-index: 10;
        }

        .premium-table tbody td {
            padding: 1rem 1.5rem !important;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9 !important;
            color: #334155;
            transition: background 0.2s;
        }

        .premium-table tbody tr:hover td {
            background: #fcfcfd !important;
        }

        .premium-table tbody tr:last-child td {
            border-bottom: none !important;
        }

        /* Order Info Block */
        .order-info-block {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .doc-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            color: #f59e0b; /* Distinct subtle amber for Quotations */
            font-size: 1.15rem;
            flex-shrink: 0;
            border: 1px solid #e2e8f0;
        }
        .doc-details {
            display: flex;
            flex-direction: column;
        }
        .doc-title {
            font-weight: 700;
            color: #0f172a;
            font-size: 0.95rem;
            margin-bottom: 2px;
        }

        .data-label { color: #64748b; font-size: 0.75rem; }
        .data-value { font-weight: 600; color: #1e293b; font-size: 0.9rem; }
        .currency-data { font-family: 'Inter', monospace; font-weight: 600; color: #0f172a; font-size: 0.95rem; }
        
        /* Modern Soft Badges */
        .badge-soft {
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .bg-soft-warning { background: #fffbeb; color: #d97706; }
        .bg-soft-primary { background: #eff6ff; color: #2563eb; }
        .bg-soft-success { background: #ecfdf5; color: #059669; }
        .bg-soft-danger { background: #fef2f2; color: #dc2626; }

        /* Action Buttons */
        .action-flex {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
        }
        .btn-view-icon {
            width: 34px; height: 34px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 8px;
            background: #f1f5f9;
            color: #64748b;
            text-decoration: none;
            transition: 0.2s;
        }
        .btn-view-icon:hover { background: #e2e8f0; color: #0f172a; }
        
        .btn-quick-act {
            padding: 0 1rem;
            height: 34px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            border: none;
            transition: 0.2s;
            text-decoration: none;
        }
        .btn-quick-approve { background: #10b981; color: #fff; box-shadow: 0 2px 4px rgba(16,185,129,0.2); }
        .btn-quick-approve:hover { background: #059669; transform: translateY(-1px); color: #fff; }
        
        .btn-quick-outline { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
        .btn-quick-outline:hover { background: #dbeafe; transform: translateY(-1px); color: #1d4ed8; }

        /* Skeleton Loader */
        .skeleton-wrapper { padding: 1.5rem; }
        .skeleton-row { display: flex; gap: 1.5rem; margin-bottom: 1.5rem; align-items: center; }
        .skeleton-cell { height: 14px; background: linear-gradient(90deg, #f1f5f9 25%, #e2e8f0 50%, #f1f5f9 75%); background-size: 200% 100%; animation: loading 1.5s infinite; border-radius: 6px; }
        .skeleton-box { width: 42px; height: 42px; border-radius: 10px; flex-shrink: 0; }
        @keyframes loading { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

        /* Datatables Footer Adjustments */
        .dataTables_wrapper .dataTables_paginate { padding: 1rem 1.5rem; display: flex; justify-content: flex-end; }
        .dataTables_wrapper .dataTables_info { padding: 1.2rem 1.5rem; font-size: 0.8rem; color: #64748b !important; }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border: none !important; background: transparent !important; padding: 0.3rem 0.8rem; margin: 0 0.15rem;
            border-radius: 8px; color: #64748b !important; font-size: 0.85rem; font-weight: 500; cursor: pointer;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current { background: #f1f5f9 !important; color: #0f172a !important; font-weight: 600; }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover:not(.current) { background: #e2e8f0 !important; color: #0f172a !important; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <!-- Premium Header Area -->
        <div class="page-header">
            <div>
                <h3 class="fw-bold mb-1" style="color: #0f172a; letter-spacing: -0.5px;">Client Quotations</h3>
                <span class="text-muted" style="font-size: 0.85rem;">Track outgoing offers and Client Purchase Orders</span>
            </div>

            <form method="GET" action="quotations_list.php" class="sleek-filter-bar m-0">
                <div class="sleek-search-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" class="sleek-search-input" placeholder="Search QTN, Client, or PO..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <select name="filter" class="sleek-select" onchange="this.form.submit()">
                    <option value="all" <?php echo ($filter == 'all') ? 'selected' : ''; ?>>All Records</option>
                    <option value="Pending PO" <?php echo ($filter == 'Pending PO') ? 'selected' : ''; ?>>Pending Client PO</option>
                    <option value="PO Received" <?php echo ($filter == 'PO Received') ? 'selected' : ''; ?>>PO Received</option>
                    <option value="Converted to PR" <?php echo ($filter == 'Converted to PR') ? 'selected' : ''; ?>>Converted to PR</option>
                </select>

                <?php if(!empty($search) || $filter != 'all'): ?>
                    <a href="quotations_list.php" class="btn btn-light border d-flex align-items-center justify-content-center" style="border-radius: 10px; width: 42px;" title="Reset Filters"><i class="fas fa-redo-alt text-muted"></i></a>
                <?php endif; ?>

                <?php if($_SESSION['role'] == 'Sales Staff'): ?>
                    <a href="create_quotation.php" class="btn-gradient-primary text-decoration-none d-flex align-items-center">
                        <i class="fas fa-plus me-2"></i> Draft Quotation
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Data Grid Layout -->
        <div class="grid-card">
            
            <!-- Sleek Skeleton -->
            <div id="grid-skeleton" class="skeleton-wrapper">
                <?php for($i=0; $i<6; $i++): ?>
                <div class="skeleton-row border-bottom border-light pb-3">
                    <div class="skeleton-cell skeleton-box"></div>
                    <div style="flex: 1;">
                        <div class="skeleton-cell w-50 mb-2"></div>
                        <div class="skeleton-cell w-25" style="height: 10px;"></div>
                    </div>
                    <div class="skeleton-cell" style="width: 15%;"></div>
                    <div class="skeleton-cell" style="width: 15%;"></div>
                    <div class="skeleton-cell" style="width: 15%;"></div>
                    <div class="skeleton-cell ms-auto" style="width: 12%;"></div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Table Container -->
            <div id="grid-content" style="display: none;">
                <div class="table-responsive-custom">
                    <table id="dataTable" class="table premium-table">
                        <thead>
                            <tr>
                                <th style="width: 25%;">Quotation Details</th>
                                <th style="width: 15%;">Quoted Value</th>
                                <th style="width: 15%;">Client PO Ref</th>
                                <th style="width: 15%;">Status</th>
                                <th style="width: 15%;">Date Created</th>
                                <th style="width: 15%;" class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()): 
                                    // Quotation Badge logic
                                    $s = $row['status'];
                                    $badge = 'bg-soft-warning';
                                    $icon = 'fa-clock';
                                    
                                    if($s == 'PO Received') { $badge = 'bg-soft-success'; $icon = 'fa-check-double'; }
                                    elseif($s == 'Converted to PR') { $badge = 'bg-soft-primary'; $icon = 'fa-exchange-alt'; }
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="order-info-block">
                                            <div class="doc-icon-box">
                                                <i class="fas fa-file-contract"></i>
                                            </div>
                                            <div class="doc-details">
                                                <span class="doc-title"><?php echo htmlspecialchars($row['quotation_number']); ?></span>
                                                <span class="data-label"><?php echo htmlspecialchars($row['client_name']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="currency-data">
                                        ₱<?php echo number_format($row['amount'], 2); ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['client_po_number'])): ?>
                                            <span class="data-value text-success"><i class="fas fa-file-invoice me-1"></i> <?php echo htmlspecialchars($row['client_po_number']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted fst-italic" style="font-size: 0.8rem;">Pending Approval</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="badge-soft <?php echo $badge; ?>">
                                            <i class="fas <?php echo $icon; ?>"></i> <?php echo htmlspecialchars($row['status']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="data-value d-block fw-normal"><?php echo date('M d, Y', strtotime($row['created_at'])); ?></span>
                                        <span class="data-label"><?php echo date('h:i A', strtotime($row['created_at'])); ?></span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="action-flex">
                                            <?php if ($_SESSION['role'] === 'Sales Staff'): ?>
                                                
                                                <?php if ($row['status'] === 'Pending PO'): ?>
                                                    <!-- Receive Client Approval Action -->
                                                    <button type="button" class="btn-quick-act btn-quick-outline" onclick="openReceivePoModal('<?php echo $row['quotation_id']; ?>', '<?php echo htmlspecialchars($row['quotation_number']); ?>')">
                                                        <i class="fas fa-paperclip me-1"></i> Log Approval
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($row['status'] === 'PO Received'): ?>
                                                    <!-- Proceed to PR creation -->
                                                    <a href="create_pr.php?quotation_id=<?php echo $row['quotation_id']; ?>" class="btn-quick-act btn-quick-approve">
                                                        <i class="fas fa-arrow-right me-1"></i> Create PR
                                                    </a>
                                                <?php endif; ?>

                                            <?php endif; ?>
                                            
                                            <!-- Universal View Details -->
                                            <a href="view_quotation.php?id=<?php echo $row['quotation_id']; ?>" class="btn-view-icon" title="View Document">
                                                <i class="fas fa-eye"></i>
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

    <!-- Premium Confirmation Modal for Uploading/Receiving Client PO -->
    <div class="modal fade" id="receivePoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-1 border-0" style="border-radius: 24px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);">
                <form action="actions/quotation_handler.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-header border-0 pb-0 pt-4 px-4 justify-content-center position-relative">
                        <button type="button" class="btn-close position-absolute end-0 me-4" data-bs-dismiss="modal" style="font-size: 0.75rem;"></button>
                        <div class="text-center w-100">
                            <div class="mb-3 mx-auto d-flex align-items-center justify-content-center bg-soft-success text-success" style="width: 56px; height: 56px; border-radius: 16px;">
                                <i class="fas fa-file-signature fs-4"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-1" style="letter-spacing: -0.5px;">Log Client Approval</h5>
                            <p class="text-muted mb-0" style="font-size: 0.85rem;">Attach proof of approval for <strong id="modalQuoteNumber" class="text-primary"></strong>.</p>
                        </div>
                    </div>
                    
                    <div class="modal-body px-4 py-4">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="receive_po">
                        <input type="hidden" name="quotation_id" id="modalQuotationId" value="">
                        
                        <!-- Mode of Approval Select -->
                        <div class="mb-4 text-start">
                            <label class="form-label fw-bold" style="font-size: 0.75rem; text-transform: uppercase; color: #475569; letter-spacing: 0.5px;">Mode of Approval <span class="text-danger">*</span></label>
                            <select name="approval_mode" class="form-select form-select-lg sleek-select w-100" required style="border-radius: 12px; font-size: 0.95rem; background-color: #f8fafc; border: 1px solid #cbd5e1;">
                                <option value="" disabled selected>Select how the client approved...</option>
                                <option value="Email Confirmation">Email Confirmation</option>
                                <option value="Chat/Viber Agreement">Chat / Viber Agreement</option>
                                <option value="Signed Physical Document">Signed Physical Document</option>
                                <option value="Official Client PO">Official Client PO Document</option>
                            </select>
                        </div>

                        <!-- Stylized File Upload Area -->
                        <div class="mb-4 text-start">
                            <label class="form-label fw-bold" style="font-size: 0.75rem; text-transform: uppercase; color: #475569; letter-spacing: 0.5px;">Proof of Approval <span class="text-danger">*</span></label>
                            <div class="position-relative">
                                <input type="file" name="client_po_file" class="form-control form-control-lg" accept=".pdf,.png,.jpg,.jpeg" required style="border-radius: 12px; font-size: 0.9rem; border: 2px dashed #cbd5e1; background: #f8fafc; padding: 1rem 1rem 1rem 3rem;">
                                <i class="fas fa-cloud-upload-alt position-absolute" style="left: 1.2rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 1.2rem;"></i>
                            </div>
                            <div class="form-text mt-2" style="font-size: 0.75rem; color: #64748b;">
                                <i class="fas fa-info-circle text-primary me-1"></i> Acceptable formats: PDF, JPG, PNG (Max: 10MB)
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2 pt-2">
                            <button type="button" class="btn btn-light w-50 py-2" data-bs-dismiss="modal" style="border-radius: 12px; font-weight: 600; color: #475569; border: 1px solid #e2e8f0; font-size: 0.9rem;">Cancel</button>
                            <button type="submit" class="btn btn-success w-50 text-white py-2" style="border-radius: 12px; font-weight: 600; background: #10b981; border: none; box-shadow: 0 4px 12px rgba(16,185,129,0.2); font-size: 0.9rem;">Confirm & Save</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            var table = $('#dataTable').DataTable({
                "order": [], 
                "bStateSave": false, 
                "pageLength": 15,
                "language": {
                    "info": "Showing _START_ to _END_ of _TOTAL_ entries",
                    "infoEmpty": "No entries found",
                    "paginate": {
                        "previous": "<i class='fas fa-angle-left'></i>",
                        "next": "<i class='fas fa-angle-right'></i>"
                    }
                },
                "dom": 't<"d-flex justify-content-between align-items-center border-top"ip>',
                "initComplete": function() {
                    setTimeout(() => {
                        $('#grid-skeleton').hide();
                        $('#grid-content').fadeIn(300);
                    }, 200); 
                }
            });
        });

        function openReceivePoModal(quotationId, quotationNumber) {
            document.getElementById('modalQuotationId').value = quotationId;
            document.getElementById('modalQuoteNumber').innerText = quotationNumber;
            
            var myModal = new bootstrap.Modal(document.getElementById('receivePoModal'));
            myModal.show();
        }
    </script>
</body>
</html>