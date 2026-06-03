<?php require 'dashboard_logic.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Overview - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/dashboard.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        .badge-subtle-success { background-color: rgba(25, 135, 84, 0.1); color: #198754; border: 1px solid rgba(25, 135, 84, 0.2); }
        .badge-subtle-warning { background-color: rgba(255, 193, 7, 0.1); color: #D97706; border: 1px solid rgba(255, 193, 7, 0.2); }
        .badge-subtle-danger { background-color: rgba(220, 53, 69, 0.1); color: #dc3545; border: 1px solid rgba(220, 53, 69, 0.2); }
        .table-professional th { font-size: 0.7rem; letter-spacing: 0.5px; font-weight: 600; }
        .table-professional td { font-size: 0.8rem; vertical-align: middle; }
        .hover-dark:hover { color: #1e293b !important; }
        
        .sleek-filter-group {
            border-radius: 50px;
            border: 1px solid #e2e8f0;
            background-color: #ffffff;
            transition: all 0.2s ease-in-out;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            overflow: hidden; 
            flex-wrap: nowrap;
        }
        .sleek-filter-group:hover {
            border-color: #94a3b8;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .sleek-filter-group .input-group-text {
            background-color: transparent !important;
            border: none !important;
            padding-left: 1rem;
            color: #64748b;
        }
        .sleek-filter-group select, 
        .sleek-filter-group input {
            background-color: transparent !important;
            border: none !important;
            font-size: 0.85rem !important;
            font-weight: 600;
            color: #334155;
            box-shadow: none !important;
            padding-right: 1rem;
        }
        .sleek-filter-group select:focus, 
        .sleek-filter-group input:focus {
            box-shadow: none !important;
            outline: none !important;
        }

        .sleek-filter-group select {
            width: auto !important;
            min-width: 95px !important;
        }
        #customDateWrapper .sleek-filter-group input.form-control {
            min-width: 260px !important; 
            text-align: center;
        }
        
        .flatpickr-calendar {
            font-family: 'Inter', 'Segoe UI', Roboto, sans-serif !important;
            width: 290px !important; 
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 10px 25px rgba(0,0,0,0.08) !important;
            border-radius: 12px !important;
            padding: 10px !important;
        }
        .flatpickr-months { margin-bottom: 8px !important; }
        .flatpickr-current-month { font-size: 1.05rem !important; font-weight: 700 !important; color: #1e293b !important; }
        .flatpickr-day {
            font-size: 0.85rem !important;
            border-radius: 6px !important;
            max-width: 34px !important;
            height: 34px !important;
            line-height: 34px !important;
            color: #475569 !important;
            margin-top: 2px !important;
        }
        .flatpickr-day.inRange, .flatpickr-day.prevMonthDay.inRange, .flatpickr-day.nextMonthDay.inRange, .flatpickr-day.today.inRange {
            background: rgba(13, 110, 253, 0.1) !important;
            border-color: transparent !important;
            box-shadow: -5px 0 0 rgba(13, 110, 253, 0.1), 5px 0 0 rgba(13, 110, 253, 0.1) !important;
        }
        .flatpickr-day.selected, .flatpickr-day.startRange, .flatpickr-day.endRange, .flatpickr-day.selected:focus, .flatpickr-day.selected:hover {
            background: #0d6efd !important;
            border-color: #0d6efd !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            border-radius: 6px !important;
        }
        .flatpickr-weekdays { height: 24px !important; }
        span.flatpickr-weekday { font-size: 0.75rem !important; font-weight: 600 !important; color: #94a3b8 !important; }

        .chart-container {
            position: relative;
            height: 320px; 
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .no-data-message {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(248, 249, 250, 0.6);
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            color: #64748b;
            z-index: 10;
        }
        .no-data-message i { font-size: 2.5rem; margin-bottom: 10px; color: #94a3b8; }
        .no-data-message span { font-size: 0.85rem; font-weight: 600; letter-spacing: 0.3px; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <header class="mb-3 pb-2 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h4 class="fw-bold mb-1 text-dark text-uppercase" style="letter-spacing: 0.5px;">System Overview</h4>
                <p class="text-muted mb-0" style="font-size: 0.8rem;">
                    Welcome back, <span class="fw-semibold text-dark"><?php echo htmlspecialchars($_SESSION['fullname']); ?></span>.
                </p>
            </div>
            
            <div class="d-flex align-items-center">
                <form method="GET" class="d-flex align-items-center m-0 gap-2" id="periodFilterForm">
                    <?php 
                    foreach($_GET as $k => $v) {
                        if($k !== 'period' && $k !== 'start' && $k !== 'end' && is_string($v)) {
                            echo "<input type='hidden' name='".htmlspecialchars($k)."' value='".htmlspecialchars($v)."'>";
                        }
                    } 
                    ?>
                    
                    <div class="input-group input-group-sm sleek-filter-group" title="Select Data Range">
                        <span class="input-group-text text-primary pe-1">
                            <i class="fas fa-calendar-day"></i>
                        </span>
                        <select name="period" id="period" class="form-select ps-1" onchange="handlePeriodChange()">
                            <option value="all" <?php echo $period == 'all' ? 'selected' : ''; ?>>All Time</option>
                            <option value="today" <?php echo $period == 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="this_week" <?php echo $period == 'this_week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="this_month" <?php echo $period == 'this_month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="this_year" <?php echo $period == 'this_year' ? 'selected' : ''; ?>>This Year</option>
                            <option value="custom" <?php echo $period == 'custom' ? 'selected' : ''; ?>>Custom</option>
                        </select>
                    </div>
                    
                    <div id="customDateWrapper" style="display: <?php echo $period == 'custom' ? 'block' : 'none'; ?>;">
                        <div class="input-group input-group-sm sleek-filter-group" title="Click to select start and end dates">
                            <span class="input-group-text text-primary pe-0">
                                <i class="fas fa-calendar-alt"></i>
                            </span>
                            <input type="text" id="customDateRange" class="form-control flatpickr-input px-2" placeholder="Select 'From' and 'To'..." readonly>
                            <input type="hidden" name="start" id="startDate" value="<?php echo htmlspecialchars($_GET['start'] ?? ''); ?>">
                            <input type="hidden" name="end" id="endDate" value="<?php echo htmlspecialchars($_GET['end'] ?? ''); ?>">
                        </div>
                    </div>
                </form>
            </div>
        </header>

        <div class="row g-2 mb-3 mt-1">
            <?php if ($_SESSION['role'] === 'Admin'): ?>
                <div class="col-xl-3 col-md-6">
                    <a href="admin_users.php" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Total System Users</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                                <i class="fas fa-users"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $admin_stats['total_users']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Accounts</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="audit_logs.php" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">System Audits</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(13, 202, 240, 0.1); color: #0dcaf0;">
                                <i class="fas fa-history"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $admin_stats['audit_today']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Logs</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Total System Files</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(108, 117, 125, 0.1); color: #6c757d;">
                                <i class="fas fa-database"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $admin_stats['total_files']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Documents</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="admin_requests.php" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Account Requests</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(255, 193, 7, 0.1); color: #D97706;">
                                <i class="fas fa-user-shield"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $admin_stats['pending_requests']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Pending</span></div>
                    </a>
                </div>
            <?php elseif (in_array($_SESSION['role'], ['GM', 'President'])): ?>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Active Records</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                                <i class="fas fa-folder-open"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $exec_stats['active_docs']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Files</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php?view_filter=All" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Archived Docs</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(108, 117, 125, 0.1); color: #6c757d;">
                                <i class="fas fa-archive"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $exec_stats['archived_docs']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Saved</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="pr_list.php?filter=Pending" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Pending PR Approval</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(255, 193, 7, 0.1); color: #D97706;">
                                <i class="fas fa-file-signature"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $exec_stats['pending_pr']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Requests</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="po_list.php?filter=Pending" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Pending PO Approval</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(220, 53, 69, 0.1); color: #dc3545;">
                                <i class="fas fa-stamp"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $exec_stats['pending_po']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Orders</span></div>
                    </a>
                </div>
            <?php elseif ($_SESSION['role'] === 'Finance'): ?>
                <div class="col-xl-3 col-md-6">
                    <a href="po_list.php?filter=GM-Approved" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Pending PO Review</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(255, 193, 7, 0.1); color: #ffc107;">
                                <i class="fas fa-search-dollar"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $finance_stats['pending_po']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">For Approval</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php?type=Invoices" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Total Invoices</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                                <i class="fas fa-file-invoice-dollar"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $finance_stats['invoices']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Files</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php?type=Official+receipts" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Official Receipts</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(25, 135, 84, 0.1); color: #198754;">
                                <i class="fas fa-receipt"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $finance_stats['receipts']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Files</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="po_list.php?filter=Funded" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Funded POs</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(13, 202, 240, 0.1); color: #0dcaf0;">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $finance_stats['funded_po']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Ready</span></div>
                    </a>
                </div>
            <?php elseif ($_SESSION['role'] === 'Sales Staff'): ?>
                <div class="col-xl-3 col-md-6">
                    <a href="pr_list.php" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Total PRs Created</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                                <i class="fas fa-file-invoice"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $sales_stats['total']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Requests</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="pr_list.php?filter=Pending" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Pending PRs</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(255, 193, 7, 0.1); color: #ffc107;">
                                <i class="fas fa-clock"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $sales_stats['pending']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Waiting</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="pr_list.php?filter=Approved" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Approved PRs</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(25, 135, 84, 0.1); color: #198754;">
                                <i class="fas fa-check-square"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $sales_stats['approved']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Approved</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="pr_list.php?filter=Rejected" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Rejected PRs</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(220, 53, 69, 0.1); color: #dc3545;">
                                <i class="fas fa-times-square"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $sales_stats['rejected']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Declined</span></div>
                    </a>
                </div>
            <?php elseif ($_SESSION['role'] === 'Procurement'): ?>
                <div class="col-xl-3 col-md-6">
                    <a href="po_list.php" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Total POs Handled</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                                <i class="fas fa-file-invoice"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $proc_stats['total']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Records</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="po_list.php?filter=Pending" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Pending Approvals</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(255, 193, 7, 0.1); color: #ffc107;">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $proc_stats['pending']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">POs</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="po_list.php?filter=Funded" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Funded POs</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(13, 202, 240, 0.1); color: #0dcaf0;">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $proc_stats['funded']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">For Delivery</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="po_list.php?filter=Delivered" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Collected POs</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(25, 135, 84, 0.1); color: #198754;">
                                <i class="fas fa-check-square"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $proc_stats['delivered']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">POs</span></div>
                    </a>
                </div>
            <?php elseif ($_SESSION['role'] === 'Supply Chain'): ?>
                <div class="col-xl-3 col-md-6">
                    <a href="po_list.php?filter=Funded" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Incoming Deliveries</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(255, 193, 7, 0.1); color: #ffc107;">
                                <i class="fas fa-truck"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $sc_stats['incoming_po']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Pending</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="po_list.php?filter=Collected" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Completed Deliveries</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(25, 135, 84, 0.1); color: #198754;">
                                <i class="fas fa-box-open"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $sc_stats['collected_po']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Items</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php?type=Delivery+receipts" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Delivery Receipts</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                                <i class="fas fa-receipt"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $sc_stats['dr_count']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Files</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php?type=Supplier+transaction+records" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Supplier Records</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(13, 202, 240, 0.1); color: #0dcaf0;">
                                <i class="fas fa-address-book"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $sc_stats['supplier_docs']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Files</span></div>
                    </a>
                </div>
            <?php elseif ($_SESSION['role'] === 'Technical'): ?>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php?type=Service+tickets" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Service Tickets</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(220, 53, 69, 0.1); color: #dc3545;">
                                <i class="fas fa-ticket-alt"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $tech_stats['tickets']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Files</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php?type=Diagnostic+reports" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Diagnostic Reports</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(255, 193, 7, 0.1); color: #ffc107;">
                                <i class="fas fa-stethoscope"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $tech_stats['diagnostics']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Files</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php?type=Job+orders" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Job Orders</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(25, 135, 84, 0.1); color: #198754;">
                                <i class="fas fa-tools"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $tech_stats['job_orders']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Files</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Total Tech Records</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                                <i class="fas fa-cogs"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $tech_stats['total']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Total</span></div>
                    </a>
                </div>
            <?php elseif (in_array($_SESSION['role'], ['Administrative', 'Staff'])): ?>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php?type=Leave+forms" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Leave Forms</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(13, 110, 253, 0.1); color: #0d6efd;">
                                <i class="fas fa-calendar-minus"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $admin_staff_stats['leaves']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Files</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php?type=Employee+correspondence+and+notices" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Employee Notices</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(13, 202, 240, 0.1); color: #0dcaf0;">
                                <i class="fas fa-id-card"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $admin_staff_stats['notices']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Files</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php?type=Internal+memorandums" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Internal Memos</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(255, 193, 7, 0.1); color: #ffc107;">
                                <i class="fas fa-bullhorn"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $admin_staff_stats['memos']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Files</span></div>
                    </a>
                </div>
                <div class="col-xl-3 col-md-6">
                    <a href="documents.php?type=Company+policies+and+procedures" class="kpi-card text-decoration-none d-block h-100">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="kpi-title text-muted fw-bold text-uppercase">Company Policies</span>
                            <div class="icon-box rounded-1 d-flex align-items-center justify-content-center" style="background: rgba(25, 135, 84, 0.1); color: #198754;">
                                <i class="fas fa-book"></i>
                            </div>
                        </div>
                        <div class="kpi-value fw-bold text-dark"><?php echo $admin_staff_stats['policies']; ?> <span class="text-muted fw-normal" style="font-size: 0.75rem;">Files</span></div>
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <ul class="nav nav-pills mb-3 border-bottom pb-2" id="dashboardTabs" role="tablist">
            <?php if ($_SESSION['role'] === 'Admin'): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="syshealth-tab" data-bs-toggle="pill" data-bs-target="#syshealth" type="button" role="tab" aria-selected="true">
                        <i class="fas fa-shield-alt me-2"></i> System Health & Audit
                    </button>
                </li>
            <?php elseif ($can_view_financials): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="financial-tab" data-bs-toggle="pill" data-bs-target="#financial" type="button" role="tab" aria-selected="true">
                        <i class="fas fa-chart-line me-2"></i> Financial & Analytics Overview
                    </button>
                </li>
                
                <li class="nav-item" role="presentation">
                    <button class="nav-link ms-1" id="operations-tab" data-bs-toggle="pill" data-bs-target="#operations" type="button" role="tab" aria-selected="false">
                        <i class="fas fa-cogs me-2"></i> Department Performance
                    </button>
                </li>
            <?php else: ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="workspace-tab" data-bs-toggle="pill" data-bs-target="#workspace" type="button" role="tab" aria-selected="true">
                        <i class="fas fa-briefcase me-2"></i>Workspace
                    </button>
                </li>
            <?php endif; ?>
        </ul>

        <div class="tab-content" id="dashboardTabsContent">
            
            <?php if ($_SESSION['role'] === 'Admin'): ?>
                <div class="tab-pane fade show active" id="syshealth" role="tabpanel" tabindex="0">
                    <div class="card shadow-sm border-0 h-100">
                        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
                            <h6 class="fw-bold mb-0 text-dark">
                                <i class="fas fa-history me-2 text-primary"></i> Recent System Activity
                            </h6>
                            <a href="audit_logs.php" class="btn btn-sm btn-outline-primary shadow-sm"><i class="fas fa-list me-1"></i> View Full Logs</a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 border-0">
                                    <thead class="bg-light text-muted small text-uppercase">
                                        <tr>
                                            <th class="ps-3 py-2">User</th>
                                            <th class="py-2">Action Details</th>
                                            <th class="py-2">IP Address</th>
                                            <th class="text-end pe-3 py-2">Date & Time</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if($recent_audit && $recent_audit->num_rows > 0):
                                            while($log = $recent_audit->fetch_assoc()): ?>
                                                <tr>
                                                    <td class="ps-3 py-2 fw-bold text-dark">
                                                        <?php echo htmlspecialchars($log['full_name'] ?? 'System / Guest'); ?><br>
                                                        <small class="text-muted fw-normal" style="font-size: 0.7rem;"><?php echo htmlspecialchars($log['role'] ?? 'N/A'); ?></small>
                                                    </td>
                                                    <td class="py-2">
                                                        <span class="badge bg-light text-dark border border-secondary border-opacity-25 px-2 py-1 mb-1 rounded-1"><?php echo htmlspecialchars($log['action_type']); ?></span><br>
                                                        <small class="text-muted" style="font-size: 0.75rem;"><?php echo htmlspecialchars($log['description']); ?></small>
                                                    </td>
                                                    <td class="py-2 text-muted small"><i class="fas fa-network-wired me-1"></i> <?php echo htmlspecialchars($log['ip_address']); ?></td>
                                                    <td class="text-end pe-3 py-2 text-muted small"><i class="far fa-clock me-1"></i><?php echo date('M d, Y h:i A', strtotime($log['timestamp'])); ?></td>
                                                </tr>
                                            <?php endwhile; 
                                        else: ?>
                                            <tr><td colspan="4" class="text-center py-4 text-muted"><i class="fas fa-history fa-2x mb-2 d-block opacity-50"></i>No recent activity found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($can_view_financials): ?>
                <div class="tab-pane fade show active" id="financial" role="tabpanel" tabindex="0">
                    <div class="row g-3 mb-3">
                        <div class="col-lg-8">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
                                    <h6 class="m-0 fw-bold text-primary"><i class="fas fa-chart-line me-2"></i> Sales Forecast</h6>
                                    <span class="badge bg-light text-muted border rounded-1">Predictive Analysis</span>
                                </div>
                                <div class="card-body p-2">
                                    <div class="chart-container">
                                        <canvas id="revenueForecastChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
                                    <h6 class="fw-bold mb-0 text-dark"><i class="fas fa-history me-2"></i> Recent Records</h6>
                                    <a href="po_list.php" class="text-decoration-none small text-primary fw-medium">View All</a>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0 border-0">
                                            <tbody>
                                                <?php
                                                if($recent_pos && $recent_pos->num_rows > 0):
                                                    while($po = $recent_pos->fetch_assoc()): ?>
                                                        <tr style="cursor: pointer;" onclick="window.location.href='view_po.php?id=<?php echo $po['po_id']; ?>'">
                                                            <td class="ps-3 py-2">
                                                                <div class="fw-bold text-dark">#<?php echo htmlspecialchars($po['po_number']); ?></div>
                                                                <div class="text-muted text-truncate" style="max-width: 140px; font-size: 0.75rem;"><?php echo htmlspecialchars($po['client_name']); ?></div>
                                                            </td>
                                                            <td class="py-2">
                                                                <span class="badge-status status-<?php echo str_replace([' ', '/'], '_', $po['status']); ?>">
                                                                    <?php echo $po['status']; ?>
                                                                </span>
                                                            </td>
                                                            <td class="text-end pe-3 py-2 text-muted" style="font-size: 0.7rem;">
                                                                <?php echo date('M d', strtotime($po['date_created'])); ?>
                                                            </td>
                                                        </tr>
                                                    <?php endwhile; 
                                                else: ?>
                                                    <tr><td colspan="3" class="text-center py-4 text-muted"><i class="fas fa-folder-open fa-2x mb-2 d-block opacity-25"></i><br>No recent records.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-header bg-white border-bottom py-2">
                                    <h6 class="m-0 fw-bold text-dark"><i class="fas fa-crown text-warning me-2"></i> Top 5 Clients </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="chart-container">
                                        <canvas id="topClientsChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-header bg-white border-bottom py-2">
                                    <h6 class="m-0 fw-bold text-dark"><i class="fas fa-boxes text-primary me-2"></i> Sales by Category</h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="chart-container">
                                        <canvas id="topCategoriesChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="operations" role="tabpanel" tabindex="0">
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-header bg-white border-bottom py-2">
                                    <h6 class="m-0 fw-bold text-dark"><i class="fas fa-history text-muted me-2"></i> Average Processing Time </h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="chart-container">
                                        <canvas id="bottleneckChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-header bg-white border-bottom py-2">
                                    <h6 class="m-0 fw-bold text-info"><i class="fas fa-users-cog me-2"></i> Department Workload</h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="chart-container">
                                        <canvas id="workloadChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-header bg-white border-bottom py-2">
                                    <h6 class="m-0 fw-bold text-success"><i class="fas fa-check-circle me-2"></i>PO Success Rate</h6>
                                </div>
                                <div class="card-body p-2">
                                    <div class="chart-container">
                                        <canvas id="rejectionChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="tab-pane fade show active" id="workspace" role="tabpanel" tabindex="0">
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <div class="card shadow-sm border-0 h-100">
                                <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
                                    <h6 class="fw-bold mb-0 text-dark">
                                        <i class="fas fa-tasks me-2 text-primary"></i> 
                                        <?php 
                                        if (isset($is_sales_staff) && $is_sales_staff) {
                                            echo 'Recent Purchase Requests';
                                        } else {
                                            echo ($_SESSION['role'] == 'Procurement') ? 'Recent Handled POs' : 'Active System Purchase Orders'; 
                                        }
                                        ?>
                                    </h6>
                                    <?php if(isset($is_sales_staff) && $is_sales_staff): ?>
                                        <a href="create_pr.php" class="btn btn-sm btn-primary shadow-sm"><i class="fas fa-plus me-1"></i> New PR</a>
                                    <?php elseif(in_array($_SESSION['role'], ['Supply Chain', 'Procurement', 'Finance'])): ?>
                                        <a href="po_list.php" class="btn btn-sm btn-outline-primary shadow-sm"><i class="fas fa-list me-1"></i> View Full Directory</a>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0 border-0">
                                            <thead class="bg-light text-muted small text-uppercase">
                                                <tr>
                                                    <th class="ps-3 py-2"><?php echo (isset($is_sales_staff) && $is_sales_staff) ? 'PR Number' : 'PO Number'; ?></th>
                                                    <th class="py-2">Client Name</th>
                                                    <th class="py-2">Grand Total</th>
                                                    <th class="py-2">Status</th>
                                                    <?php if(!isset($is_sales_staff) || !$is_sales_staff): ?>
                                                    <th class="py-2">Current Desk</th>
                                                    <?php endif; ?>
                                                    <th class="text-end pe-3 py-2">Date Created</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                if($my_recent && $my_recent->num_rows > 0):
                                                    while($doc = $my_recent->fetch_assoc()): ?>
                                                        <tr <?php echo (!isset($is_sales_staff) || !$is_sales_staff) ? "style='cursor: pointer;' onclick=\"window.location.href='view_po.php?id={$doc['id']}';\"" : ""; ?>>
                                                            <td class="ps-3 py-2 fw-bold text-primary">#<?php echo htmlspecialchars($doc['number']); ?></td>
                                                            <td class="py-2 text-dark fw-medium"><?php echo htmlspecialchars($doc['client_name']); ?></td>
                                                            <td class="py-2 fw-medium text-dark">₱ <?php echo number_format($doc['amount'], 2); ?></td>
                                                            <td class="py-2">
                                                                <span class="badge-status status-<?php echo str_replace([' ', '/'], '_', $doc['status']); ?>">
                                                                    <?php echo $doc['status']; ?>
                                                                </span>
                                                            </td>
                                                            <?php if(!isset($is_sales_staff) || !$is_sales_staff): ?>
                                                            <td class="py-2">
                                                                <span class="badge bg-light text-secondary border border-secondary border-opacity-25 px-2 py-1 rounded-1"><i class="fas fa-map-marker-alt me-1 text-danger"></i> <?php echo htmlspecialchars($doc['current_location']); ?></span>
                                                            </td>
                                                            <?php endif; ?>
                                                            <td class="text-end pe-3 py-2 text-muted small"><i class="far fa-calendar-alt me-1"></i><?php echo date('M d, Y', strtotime($doc['date_created'])); ?></td>
                                                        </tr>
                                                    <?php endwhile; 
                                                else: ?>
                                                    <tr><td colspan="<?php echo (isset($is_sales_staff) && $is_sales_staff) ? '5' : '6'; ?>" class="text-center py-5 text-muted"><i class="fas fa-folder-open fa-3x mb-3 d-block opacity-25"></i>No records found to display.</td></tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($user_categories)): ?>
        <div class="row mt-3 mb-3">
            <div class="col-12">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="fas fa-folder-open me-2 text-warning"></i> Recent Department Files
                        </h6>
                        <div>
                            <a href="documents.php" class="btn btn-sm btn-outline-secondary shadow-sm"><i class="fas fa-folder me-1"></i> View All Folders</a>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 border-0">
                                <thead class="bg-light text-muted small text-uppercase">
                                    <tr>
                                        <th class="ps-3 py-2">Document Name</th>
                                        <th class="py-2">Folder</th>
                                        <th class="py-2">Uploaded By</th>
                                        <th class="text-end pe-3 py-2">Date Uploaded</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($recent_dashboard_files && $recent_dashboard_files->num_rows > 0): ?>
                                        <?php while($doc = $recent_dashboard_files->fetch_assoc()): 
                                            $ext = strtolower(pathinfo($doc['file_name'], PATHINFO_EXTENSION));
                                            $icon = in_array($ext, ['pdf']) ? 'fa-file-pdf text-danger' : (in_array($ext, ['jpg','png','jpeg']) ? 'fa-file-image text-success' : 'fa-file-alt text-primary');
                                        ?>
                                            <tr style="cursor: pointer;" onclick="window.location.href='documents.php?search=<?php echo urlencode($doc['file_name']); ?>'">
                                                <td class="ps-3 py-2 fw-bold text-dark">
                                                    <i class="fas <?php echo $icon; ?> me-2"></i> <?php echo htmlspecialchars($doc['file_name']); ?>
                                                </td>
                                                <td class="py-2"><span class="badge bg-warning text-dark fw-medium rounded-1"><i class="fas fa-folder me-1"></i><?php echo htmlspecialchars($doc['category']); ?></span></td>
                                                <td class="py-2 text-muted small"><?php echo htmlspecialchars($doc['full_name']); ?></td>
                                                <td class="text-end pe-3 py-2 text-muted small"><?php echo date('M d, Y h:i A', strtotime($doc['uploaded_at'])); ?></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">No recent files found for your department.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="documentViewerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl-custom modal-dialog-centered" style="margin: 1rem auto;">
            <div class="modal-content shadow-lg border-0">
                <div class="modal-header bg-primary text-white">
                    <h6 class="modal-title fw-bold" id="documentViewerTitle"><i class="fas fa-file-alt me-2"></i> Document Viewer</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0 text-center position-relative" style="height: 75vh; background-color: #f1f5f9;">
                    <iframe id="documentViewerFrame" src="" style="width: 100%; height: 100%; border: none; display: block;"></iframe>
                </div>
                <div class="modal-footer bg-light border-top-0 py-2">
                    <button type="button" class="btn btn-secondary px-3 fw-medium shadow-sm" data-bs-dismiss="modal">
                        <i class="fas fa-arrow-left me-2"></i> Back
                    </button>
                    <a href="#" id="documentDownloadBtn" class="btn btn-primary px-3 fw-medium shadow-sm" download>
                        <i class="fas fa-download me-2"></i> Download
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="previewModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-primary text-white py-2">
                    <h6 class="modal-title fw-bold"><i class="fas fa-eye me-2"></i> File Preview</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-0 bg-light" id="previewBody" style="min-height: 400px; display: flex; align-items: center; justify-content: center;"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="assets/js/dashboard.js"></script>
    
    <script>
        function handlePeriodChange() {
            var period = document.getElementById('period').value;
            if (period === 'custom') {
                document.getElementById('customDateWrapper').style.display = 'block';
                const fp = document.querySelector("#customDateRange")._flatpickr;
                if(fp) {
                    setTimeout(() => fp.open(), 50); 
                }
            } else {
                document.getElementById('customDateWrapper').style.display = 'none';
                document.getElementById('startDate').value = '';
                document.getElementById('endDate').value = '';
                document.getElementById('periodFilterForm').submit();
            }
        }

        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
          return new bootstrap.Tooltip(tooltipTriggerEl)
        })

        function viewFile(path, type) {
            const modalBody = document.getElementById('previewBody');
            const myModal = new bootstrap.Modal(document.getElementById('previewModal'));
            modalBody.innerHTML = '<div class="spinner-border text-primary" role="status"></div>';
            
            if (type === 'image') {
                modalBody.innerHTML = `<img src="${path}" class="img-fluid" style="max-height: 80vh;">`;
            } else if (type === 'pdf') {
                modalBody.innerHTML = `<iframe src="${path}" width="100%" height="600px" style="border:none;"></iframe>`;
            } else {
                modalBody.innerHTML = `<div class="p-4"><i class="fas fa-file-download fa-2x text-muted mb-2"></i><p style="font-size:0.85rem;">This file type cannot be previewed.</p><a href="${path}" download class="btn btn-primary shadow-sm"><i class="fas fa-download me-2"></i> Download File</a></div>`;
            }
            myModal.show();
        }
    </script>
</body>
</html>