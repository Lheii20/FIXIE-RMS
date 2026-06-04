<?php require 'dashboard_logic.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Overview & Analytics - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/dashboard.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    
    <!-- Flatpickr CSS (Core Only, Native header is hidden) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        /* Enterprise Corporate UI Variables */
        :root {
            --bg-body: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-light: #e2e8f0;
            --primary: #2563eb;
            --primary-glow: rgba(37, 99, 235, 0.2);
        }
        body, .main-content {
            background-color: var(--bg-body) !important;
            font-family: 'Inter', 'Segoe UI', Roboto, sans-serif;
            color: var(--text-main);
        }

        /* PREMIUM BUTTON TRIGGER */
        .btn-filter-trigger {
            background: #ffffff; border: 1px solid #cbd5e1; color: #334155; font-weight: 600;
            font-size: 0.85rem; padding: 0.55rem 1.1rem; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .btn-filter-trigger:hover, .btn-filter-trigger[aria-expanded="true"] {
            border-color: var(--primary); color: var(--primary); box-shadow: 0 4px 12px var(--primary-glow);
        }
        
        /* PREMIUM MINIMALIST POPOVER */
        .filter-dropdown-menu {
            width: auto; 
            min-width: 600px; 
            border-radius: 16px; 
            padding: 0;
            margin-top: 12px !important; 
            box-shadow: 0 20px 40px -10px rgba(0,0,0,0.15), 0 0 0 1px rgba(0,0,0,0.05);
            border: none; 
            background: #ffffff;
            transform-origin: top right;
        }
        .filter-dropdown-menu.show {
            animation: smoothPopover 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        @keyframes smoothPopover {
            0% { opacity: 0; transform: translateY(-8px) scale(0.98); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        /* SLEEK QUICK LINKS */
        .quick-filter-title {
            font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; margin-bottom: 12px;
        }
        .quick-filter-btn {
            display: block; width: 100%; text-align: left; 
            padding: 8px 12px; border-radius: 8px; margin-bottom: 4px; cursor: pointer;
            color: #475569; font-weight: 500; font-size: 0.85rem; transition: all 0.2s ease; 
            background: transparent; border: none;
        }
        .quick-filter-btn:hover { background: #f1f5f9; color: #0f172a; }
        .quick-filter-btn.active { background: #eff6ff; color: var(--primary); font-weight: 600; }
        
        /* =========================================
           100% INDEPENDENT CUSTOM CALENDAR HEADER 
           ========================================= */
        .flatpickr-months { display: none !important; } /* TANGGALIN ANG NATIVE BULKY HEADER */
        
        .custom-cal-header { width: 100%; max-width: 320px; margin: 0 auto; user-select: none; }
        .custom-cal-nav {
            height: 34px; width: 34px; display: flex; align-items: center; justify-content: center; 
            border-radius: 8px; transition: 0.2s; background: #f8fafc; border: 1px solid #cbd5e1; cursor: pointer; color: #475569;
        }
        .custom-cal-nav:hover { background: #e2e8f0; border-color: #94a3b8; color: #0f172a; }
        
        .custom-cal-select {
            appearance: none; -webkit-appearance: none; -moz-appearance: none;
            background-color: #f8fafc;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 8px center; background-size: 14px;
            border: 1px solid #cbd5e1; color: #1e293b; font-weight: 700;
            padding: 4px 28px 4px 12px; border-radius: 8px; cursor: pointer;
            transition: all 0.2s ease; outline: none; font-size: 0.9rem; height: 34px;
        }
        .custom-cal-select:hover, .custom-cal-select:focus { background-color: #eff6ff; border-color: #93c5fd; color: var(--primary); }

        /* CALENDAR BODY */
        .calendar-wrapper { width: 320px; margin: 0 auto; display: flex; justify-content: center; }
        .flatpickr-calendar { box-shadow: none !important; border: none !important; width: 100% !important; padding: 0 !important; background: transparent; }
        
        .flatpickr-weekdays { height: 24px; margin-bottom: 5px; }
        .flatpickr-weekday { color: #94a3b8; font-weight: 600; font-size: 0.7rem; text-transform: uppercase; }
        .flatpickr-days { border: none !important; width: 100% !important; }
        .dayContainer { width: 100% !important; min-width: 100% !important; max-width: 100% !important; }
        
        /* Individual Days */
        .flatpickr-day { 
            border-radius: 8px !important; font-weight: 500; color: #334155; transition: 0.2s; border: none !important; 
            height: 36px; line-height: 36px; margin: 2px 0;
        }
        .flatpickr-day:hover { background: #f1f5f9; color: #0f172a; }
        .flatpickr-day.inRange { background: #eff6ff !important; box-shadow: -5px 0 0 #eff6ff, 5px 0 0 #eff6ff !important; border-radius: 0 !important; }
        .flatpickr-day.selected, .flatpickr-day.startRange, .flatpickr-day.endRange, .flatpickr-day.selected:focus, .flatpickr-day.startRange:focus, .flatpickr-day.endRange:focus {
            background: var(--primary) !important; color: #fff !important; font-weight: 600; box-shadow: 0 4px 10px var(--primary-glow) !important; border-radius: 8px !important; z-index: 2;
        }

        /* Action Footer */
        .custom-range-display { font-size: 0.85rem; font-weight: 500; color: #64748b; }
        .custom-range-display strong { color: #0f172a; font-weight: 700; }

        /* EXECUTIVE KPI CARDS */
        .kpi-corp-card {
            background: var(--card-bg); border-radius: 12px; border: 1px solid var(--border-light);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04); padding: 1.5rem; position: relative; overflow: hidden;
            display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s ease; height: 100%;
        }
        .kpi-corp-card:hover { transform: translateY(-2px); box-shadow: 0 8px 16px -4px rgba(0, 0, 0, 0.05); border-color: #cbd5e1; }
        .accent-blue { border-top: 4px solid #3b82f6; } .accent-slate { border-top: 4px solid #64748b; }
        .accent-amber { border-top: 4px solid #f59e0b; } .accent-rose { border-top: 4px solid #f43f5e; }
        .kpi-corp-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.5rem; }
        .kpi-corp-title { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; margin: 0; }
        .kpi-corp-value { font-size: 1.85rem; font-weight: 700; color: var(--text-main); line-height: 1; margin: 0; }
        .kpi-corp-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; }
        .kpi-corp-badge { font-size: 0.7rem; font-weight: 500; color: #475569; display: flex; align-items: center; gap: 4px; }
        
        /* Premium Chart Widgets */
        .corp-widget {
            background: #ffffff; border-radius: 12px; padding: 1.25rem; border: 1px solid var(--border-light);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04); height: 100%; display: flex; flex-direction: column; transition: box-shadow 0.2s ease;
        }
        .corp-widget:hover { box-shadow: 0 8px 16px -4px rgba(0, 0, 0, 0.05); }
        .corp-widget-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; }
        .corp-widget-title { font-size: 0.85rem; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 8px; }
        .chart-box { position: relative; flex-grow: 1; width: 100%; min-height: 260px; max-height: 300px; }

        .table-corp th { font-size: 0.7rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; color: var(--text-muted); background: #f8fafc; border-bottom: 1px solid var(--border-light); padding: 10px 16px; }
        .table-corp td { padding: 12px 16px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <!-- HEADER -->
        <header class="mb-4 pb-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h4 class="fw-bold mb-1" style="color: #0f172a; letter-spacing: -0.5px;">Dashboard & Analytics</h4>
                <p class="text-muted mb-0" style="font-size: 0.85rem;">
                    Welcome, <span class="fw-semibold text-dark"><?php echo htmlspecialchars($_SESSION['fullname']); ?></span>.
                </p>
            </div>
            
            <div class="d-flex align-items-center">
                
                <!-- CUSTOM DROPDOWN FILTER -->
                <div class="dropdown position-relative">
                    <button class="btn-filter-trigger dropdown-toggle" type="button" id="filterDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                        <i class="far fa-calendar-alt text-secondary"></i> 
                        <span id="displayFilterText"><?php echo $active_filter_text; ?></span>
                    </button>
                    
                    <div class="dropdown-menu dropdown-menu-end filter-dropdown-menu p-0" aria-labelledby="filterDropdown">
                        <div class="d-flex flex-column flex-md-row">
                            
                            <!-- Left: Minimalist Quick Links -->
                            <div class="border-end p-4 bg-light" style="min-width: 170px;">
                                <div class="quick-filter-title">Presets</div>
                                <div class="d-flex flex-column">
                                    <button type="button" class="quick-filter-btn <?php echo ($period=='today')?'active':''; ?>" data-val="today">Today</button>
                                    <button type="button" class="quick-filter-btn <?php echo ($period=='this_week')?'active':''; ?>" data-val="this_week">This Week</button>
                                    <button type="button" class="quick-filter-btn <?php echo ($period=='this_month')?'active':''; ?>" data-val="this_month">This Month</button>
                                    <button type="button" class="quick-filter-btn <?php echo ($period=='this_year')?'active':''; ?>" data-val="this_year">This Year</button>
                                    <button type="button" class="quick-filter-btn <?php echo ($period=='all')?'active':''; ?>" data-val="all">All Time</button>
                                </div>
                            </div>
                            
                            <!-- Right: Calendar & Actions -->
                            <div class="flex-grow-1 p-4 bg-white">
                                <div class="quick-filter-title mb-3">Custom Range</div>
                                
                                <!-- 100% WORKING CUSTOM HEADER (Independent from Flatpickr DOM) -->
                                <div class="custom-cal-header d-flex justify-content-between align-items-center mb-3">
                                    <button type="button" id="calPrev" class="custom-cal-nav"><i class="fas fa-chevron-left"></i></button>
                                    <div class="d-flex gap-2">
                                        <select id="calMonth" class="custom-cal-select">
                                            <option value="0">January</option>
                                            <option value="1">February</option>
                                            <option value="2">March</option>
                                            <option value="3">April</option>
                                            <option value="4">May</option>
                                            <option value="5">June</option>
                                            <option value="6">July</option>
                                            <option value="7">August</option>
                                            <option value="8">September</option>
                                            <option value="9">October</option>
                                            <option value="10">November</option>
                                            <option value="11">December</option>
                                        </select>
                                        <select id="calYear" class="custom-cal-select">
                                            <!-- Puno ito ng Javascript mamaya -->
                                        </select>
                                    </div>
                                    <button type="button" id="calNext" class="custom-cal-nav"><i class="fas fa-chevron-right"></i></button>
                                </div>

                                <div class="calendar-wrapper">
                                    <input type="text" id="inlineCalendarContainer" class="d-none">
                                </div>

                                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                                    <div class="custom-range-display" id="customRangeDisplay">
                                        <?php echo ($period == 'custom' && !empty($_GET['start']) && !empty($_GET['end'])) ? "<strong>".date('M d, Y', strtotime($_GET['start']))."</strong> &mdash; <strong>".date('M d, Y', strtotime($_GET['end']))."</strong>" : "<span class='text-muted fw-normal fst-italic'>Select dates...</span>"; ?>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-sm btn-light text-secondary fw-bold px-3 border-0" onclick="closeDropdown()">Cancel</button>
                                        <button type="button" class="btn btn-sm btn-primary fw-bold px-3 shadow-sm" id="applyFilterBtn" style="border-radius: 8px;">Apply</button>
                                    </div>
                                </div>
                            </div>
                            
                        </div>
                    </div>
                </div>

            </div>
        </header>

        <!-- GM AND PRESIDENT EXCLUSIVE "EXECUTIVE" LAYOUT -->
        <?php if (in_array($_SESSION['role'], ['GM', 'President'])): ?>
            
            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-md-6"><a href="documents.php" class="text-decoration-none"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Active Records</p><h3 class="kpi-corp-value mt-1"><?php echo $exec_stats['active_docs']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-folder-open"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-primary" style="font-size: 6px;"></i> Current working files</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="documents.php?view_filter=All" class="text-decoration-none"><div class="kpi-corp-card accent-slate"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Archived Docs</p><h3 class="kpi-corp-value mt-1"><?php echo $exec_stats['archived_docs']; ?></h3></div><div class="kpi-corp-icon bg-secondary bg-opacity-10 text-secondary"><i class="fas fa-archive"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-secondary" style="font-size: 6px;"></i> Safely stored records</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="pr_list.php?filter=Pending" class="text-decoration-none"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending PRs</p><h3 class="kpi-corp-value mt-1"><?php echo $exec_stats['pending_pr']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-file-signature"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-warning" style="font-size: 6px;"></i> Awaiting your approval</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Pending" class="text-decoration-none"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending POs</p><h3 class="kpi-corp-value mt-1"><?php echo $exec_stats['pending_po']; ?></h3></div><div class="kpi-corp-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-stamp"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-danger" style="font-size: 6px;"></i> Action required</div></div></a></div>
            </div>

            <div class="row g-3 mb-4 align-items-stretch">
                <div class="col-lg-8"><div class="corp-widget"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-shield-alt text-primary"></i> System Audit & Activity Trend</h6></div><div class="chart-box"><canvas id="gmAuditChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-recycle text-emerald"></i> Document Lifecycle</h6></div><div class="chart-box"><canvas id="gmLifecycleChart"></canvas></div></div></div>
            </div>

            <div class="row g-3 mb-4 align-items-stretch">
                <div class="col-lg-6"><div class="corp-widget"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-folder text-info"></i> Record Volume Distribution</h6></div><div class="chart-box"><canvas id="gmVolumeChart"></canvas></div></div></div>
                <div class="col-lg-6"><div class="corp-widget"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-project-diagram text-rose"></i> Processing Bottleneck (Avg Hrs)</h6></div><div class="chart-box"><canvas id="gmTurnaroundChart"></canvas></div></div></div>
            </div>

        <?php else: ?>
            
            <!-- STANDARD SECTION FOR ALL OTHER ROLES -->
            <div class="row g-3 mb-4">
                <?php if ($_SESSION['role'] === 'Admin'): ?>
                    <div class="col-xl-3 col-md-6"><a href="admin_users.php" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">System Users</p><h3 class="kpi-corp-value mt-1"><?php echo $admin_stats['total_users']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-users"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="audit_logs.php" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-slate"><div class="kpi-corp-header"><div><p class="kpi-corp-title">System Audits</p><h3 class="kpi-corp-value mt-1"><?php echo $admin_stats['audit_today']; ?></h3></div><div class="kpi-corp-icon bg-secondary bg-opacity-10 text-secondary"><i class="fas fa-history"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="documents.php" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Total Files</p><h3 class="kpi-corp-value mt-1"><?php echo $admin_stats['total_files']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-database"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="admin_requests.php" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Account Requests</p><h3 class="kpi-corp-value mt-1"><?php echo $admin_stats['pending_requests']; ?></h3></div><div class="kpi-corp-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-user-shield"></i></div></div></div></a></div>
                
                <?php elseif ($_SESSION['role'] === 'Finance'): ?>
                    <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=GM-Approved" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">PO For Review</p><h3 class="kpi-corp-value mt-1"><?php echo $finance_stats['pending_po']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-search-dollar"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="documents.php?type=Invoices" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Total Invoices</p><h3 class="kpi-corp-value mt-1"><?php echo $finance_stats['invoices']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-file-invoice-dollar"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="documents.php?type=Official+receipts" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-slate"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Official Receipts</p><h3 class="kpi-corp-value mt-1"><?php echo $finance_stats['receipts']; ?></h3></div><div class="kpi-corp-icon bg-success bg-opacity-10 text-success"><i class="fas fa-receipt"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Funded" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Funded POs</p><h3 class="kpi-corp-value mt-1"><?php echo $finance_stats['funded_po']; ?></h3></div><div class="kpi-corp-icon bg-info bg-opacity-10 text-info"><i class="fas fa-money-bill-wave"></i></div></div></div></a></div>
                
                <?php elseif ($_SESSION['role'] === 'Sales Staff'): ?>
                    <div class="col-xl-3 col-md-6"><a href="pr_list.php" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Total PRs Created</p><h3 class="kpi-corp-value mt-1"><?php echo $sales_stats['total']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-file-invoice"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="pr_list.php?filter=Pending" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending PRs</p><h3 class="kpi-corp-value mt-1"><?php echo $sales_stats['pending']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-clock"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="pr_list.php?filter=Approved" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-slate"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Approved PRs</p><h3 class="kpi-corp-value mt-1"><?php echo $sales_stats['approved']; ?></h3></div><div class="kpi-corp-icon bg-success bg-opacity-10 text-success"><i class="fas fa-check-square"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="pr_list.php?filter=Rejected" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Rejected PRs</p><h3 class="kpi-corp-value mt-1"><?php echo $sales_stats['rejected']; ?></h3></div><div class="kpi-corp-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-times-square"></i></div></div></div></a></div>
                
                <?php elseif ($_SESSION['role'] === 'Procurement'): ?>
                    <div class="col-xl-3 col-md-6"><a href="po_list.php" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Total POs Handled</p><h3 class="kpi-corp-value mt-1"><?php echo $proc_stats['total']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-file-invoice"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Pending" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending Approvals</p><h3 class="kpi-corp-value mt-1"><?php echo $proc_stats['pending']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-hourglass-half"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Funded" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-slate"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Funded POs</p><h3 class="kpi-corp-value mt-1"><?php echo $proc_stats['funded']; ?></h3></div><div class="kpi-corp-icon bg-info bg-opacity-10 text-info"><i class="fas fa-money-bill-wave"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Delivered" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Collected POs</p><h3 class="kpi-corp-value mt-1"><?php echo $proc_stats['delivered']; ?></h3></div><div class="kpi-corp-icon bg-success bg-opacity-10 text-success"><i class="fas fa-check-square"></i></div></div></div></a></div>
                
                <?php elseif ($_SESSION['role'] === 'Supply Chain'): ?>
                    <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Funded" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Incoming Deliveries</p><h3 class="kpi-corp-value mt-1"><?php echo $sc_stats['incoming_po']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-truck"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Collected" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-slate"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Completed Deliveries</p><h3 class="kpi-corp-value mt-1"><?php echo $sc_stats['collected_po']; ?></h3></div><div class="kpi-corp-icon bg-success bg-opacity-10 text-success"><i class="fas fa-box-open"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="documents.php?type=Delivery+receipts" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Delivery Receipts</p><h3 class="kpi-corp-value mt-1"><?php echo $sc_stats['dr_count']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-receipt"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="documents.php?type=Supplier+transaction+records" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Supplier Records</p><h3 class="kpi-corp-value mt-1"><?php echo $sc_stats['supplier_docs']; ?></h3></div><div class="kpi-corp-icon bg-info bg-opacity-10 text-info"><i class="fas fa-address-book"></i></div></div></div></a></div>
                
                <?php elseif ($_SESSION['role'] === 'Technical'): ?>
                    <div class="col-xl-3 col-md-6"><a href="documents.php?type=Service+tickets" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Service Tickets</p><h3 class="kpi-corp-value mt-1"><?php echo $tech_stats['tickets']; ?></h3></div><div class="kpi-corp-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-ticket-alt"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="documents.php?type=Diagnostic+reports" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Diagnostic Reports</p><h3 class="kpi-corp-value mt-1"><?php echo $tech_stats['diagnostics']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-stethoscope"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="documents.php?type=Job+orders" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-slate"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Job Orders</p><h3 class="kpi-corp-value mt-1"><?php echo $tech_stats['job_orders']; ?></h3></div><div class="kpi-corp-icon bg-success bg-opacity-10 text-success"><i class="fas fa-tools"></i></div></div></div></a></div>
                    <div class="col-xl-3 col-md-6"><a href="documents.php" class="text-decoration-none d-block h-100"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Total Tech Records</p><h3 class="kpi-corp-value mt-1"><?php echo $tech_stats['total']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-cogs"></i></div></div></div></a></div>
                <?php endif; ?>
            </div>

            <div class="row g-3 mb-4 align-items-stretch">
                <?php if (isset($is_sales_staff) && $is_sales_staff): ?>
                    <div class="col-lg-6"><div class="corp-widget"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-pie text-primary"></i> PR Status</h6></div><div class="chart-box"><canvas id="salesPrStatusChart"></canvas></div></div></div>
                    <div class="col-lg-6"><div class="corp-widget"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-bar text-success"></i> PR Creation Trend</h6></div><div class="chart-box"><canvas id="salesPerformanceChart"></canvas></div></div></div>
                <?php elseif (in_array($_SESSION['role'], ['Procurement', 'Supply Chain'])): ?>
                    <div class="col-lg-6"><div class="corp-widget"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-pie text-warning"></i> PO Overview</h6></div><div class="chart-box"><canvas id="procPoStatusChart"></canvas></div></div></div>
                    <div class="col-lg-6"><div class="corp-widget"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-folder-open text-info"></i> Document Categories</h6></div><div class="chart-box"><canvas id="docsCategoryChart"></canvas></div></div></div>
                <?php endif; ?>
            </div>
            
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="corp-widget p-0 overflow-hidden">
                        <div class="corp-widget-header px-4 pt-4 pb-2 border-bottom-0"><h6 class="corp-widget-title"><i class="fas fa-tasks text-primary"></i> <?php echo (isset($is_sales_staff) && $is_sales_staff) ? 'Recent PRs' : 'Active POs'; ?></h6></div>
                        <div class="table-responsive" style="max-height: 250px;">
                            <table class="table table-corp align-middle mb-0">
                                <thead class="bg-light sticky-top">
                                    <tr><th class="ps-4">Number</th><th>Client</th><th>Total</th><th>Status</th><?php if(!isset($is_sales_staff) || !$is_sales_staff): ?><th>Location</th><?php endif; ?></tr>
                                </thead>
                                <tbody>
                                    <?php if($my_recent && $my_recent->num_rows > 0): while($doc = $my_recent->fetch_assoc()): ?>
                                        <tr <?php echo (!isset($is_sales_staff) || !$is_sales_staff) ? "style='cursor: pointer;' onclick=\"window.location.href='view_po.php?id={$doc['id']}';\"" : ""; ?>>
                                            <td class="ps-4 fw-bold text-primary">#<?php echo htmlspecialchars($doc['number']); ?></td>
                                            <td class="fw-medium"><?php echo htmlspecialchars($doc['client_name']); ?></td>
                                            <td class="fw-bold">P<?php echo number_format($doc['amount'], 2); ?></td>
                                            <td><span class="badge bg-light text-dark border px-2"><?php echo $doc['status']; ?></span></td>
                                            <?php if(!isset($is_sales_staff) || !$is_sales_staff): ?>
                                                <td><small class="text-muted"><i class="fas fa-map-marker-alt text-danger"></i> <?php echo htmlspecialchars($doc['current_location']); ?></small></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endwhile; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        <?php endif; ?>

        <!-- Recent Department Files Table -->
        <?php if (!empty($user_categories) && !in_array($_SESSION['role'], ['GM', 'President'])): ?>
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="corp-widget p-0 overflow-hidden">
                    <div class="corp-widget-header px-4 pt-4 pb-2 border-bottom-0"><h6 class="corp-widget-title"><i class="fas fa-folder-open text-warning"></i> Recent Department Files</h6></div>
                    <div class="table-responsive" style="max-height: 200px;">
                        <table class="table table-corp align-middle mb-0">
                            <thead class="bg-light sticky-top"><tr><th class="ps-4">Document</th><th>Folder</th><th>Uploader</th><th class="text-end pe-4">Date</th></tr></thead>
                            <tbody>
                                <?php if($recent_dashboard_files && $recent_dashboard_files->num_rows > 0): while($doc = $recent_dashboard_files->fetch_assoc()): ?>
                                        <tr style="cursor: pointer;" onclick="window.location.href='documents.php?search=<?php echo urlencode($doc['file_name']); ?>'">
                                            <td class="ps-4 fw-bold text-dark"><i class="fas fa-file-alt text-primary me-2"></i> <?php echo htmlspecialchars($doc['file_name']); ?></td>
                                            <td><span class="badge bg-light text-dark border px-2"><i class="fas fa-folder me-1 opacity-50"></i><?php echo htmlspecialchars($doc['category']); ?></span></td>
                                            <td class="text-muted"><small><?php echo htmlspecialchars($doc['full_name']); ?></small></td>
                                            <td class="text-end pe-4 text-muted"><small><?php echo date('M d, H:i', strtotime($doc['uploaded_at'])); ?></small></td>
                                        </tr>
                                    <?php endwhile; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Scripts -->
    <?php if (isset($gm_charts) && !empty($gm_charts)): ?>
        <script>const gmData = <?php echo json_encode($gm_charts); ?>;</script>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="assets/js/dashboard.js"></script>
    
    <!-- DASHBOARD DATE FILTER SCRIPT -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let selectedPeriod = "<?php echo $period; ?>";
            let startDateStr = "<?php echo $_GET['start'] ?? ''; ?>";
            let endDateStr = "<?php echo $_GET['end'] ?? ''; ?>";

            // Initialize Custom DOM Elements for Month & Year
            const calMonth = document.getElementById('calMonth');
            const calYear = document.getElementById('calYear');
            const calPrev = document.getElementById('calPrev');
            const calNext = document.getElementById('calNext');

            // Populate Year Dropdown (15 years past and future)
            const currentY = new Date().getFullYear();
            for(let i = currentY - 15; i <= currentY + 15; i++) {
                let opt = document.createElement('option');
                opt.value = i; opt.text = i;
                calYear.appendChild(opt);
            }

            // Initialize Flatpickr 
            const fp = flatpickr("#inlineCalendarContainer", {
                mode: "range",
                inline: true,
                showMonths: 1, 
                defaultDate: (startDateStr && endDateStr) ? [startDateStr, endDateStr] : null,
                
                // Hooks to sync Flatpickr state to our custom dropdowns
                onReady: function(selectedDates, dateStr, instance) {
                    calMonth.value = instance.currentMonth;
                    calYear.value = instance.currentYear;
                },
                onMonthChange: function(selectedDates, dateStr, instance) {
                    calMonth.value = instance.currentMonth;
                    calYear.value = instance.currentYear;
                },
                onYearChange: function(selectedDates, dateStr, instance) {
                    calMonth.value = instance.currentMonth;
                    calYear.value = instance.currentYear;
                },
                
                onChange: function(selectedDates, dateStr, instance) {
                    document.querySelectorAll('.quick-filter-btn').forEach(b => b.classList.remove('active'));
                    selectedPeriod = 'custom';
                    
                    if (selectedDates.length === 2) {
                        startDateStr = instance.formatDate(selectedDates[0], "Y-m-d");
                        endDateStr = instance.formatDate(selectedDates[1], "Y-m-d");
                        let s_disp = instance.formatDate(selectedDates[0], "M d, Y");
                        let e_disp = instance.formatDate(selectedDates[1], "M d, Y");
                        document.getElementById('customRangeDisplay').innerHTML = `<strong>${s_disp}</strong> &mdash; <strong>${e_disp}</strong>`;
                    } else if (selectedDates.length === 1) {
                        startDateStr = instance.formatDate(selectedDates[0], "Y-m-d");
                        endDateStr = startDateStr; 
                        let s_disp = instance.formatDate(selectedDates[0], "M d, Y");
                        document.getElementById('customRangeDisplay').innerHTML = `<strong>${s_disp}</strong> &mdash; <span class="text-muted fw-normal fst-italic">Select end date...</span>`;
                    }
                }
            });

            // Action Listeners for Custom Header UI
            function updateFlatpickrView() {
                let m = parseInt(calMonth.value);
                let y = parseInt(calYear.value);
                fp.jumpToDate(new Date(y, m, 1)); // Forces Flatpickr to go to exactly this month/year
            }
            
            calMonth.addEventListener('change', updateFlatpickrView);
            calYear.addEventListener('change', updateFlatpickrView);
            
            calPrev.addEventListener('click', function(e){ e.preventDefault(); fp.changeMonth(-1); });
            calNext.addEventListener('click', function(e){ e.preventDefault(); fp.changeMonth(1); });

            // Ensure rendering when opening modal
            const filterDropdown = document.getElementById('filterDropdown');
            if(filterDropdown) {
                filterDropdown.addEventListener('show.bs.dropdown', function () {
                    fp.redraw();
                });
            }

            // Quick Filter Buttons Logic
            const quickBtns = document.querySelectorAll('.quick-filter-btn');
            quickBtns.forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault(); 
                    e.stopPropagation(); 
                    
                    selectedPeriod = this.getAttribute('data-val');
                    let currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.set('period', selectedPeriod);
                    currentUrl.searchParams.delete('start');
                    currentUrl.searchParams.delete('end');
                    
                    window.location.href = currentUrl.toString(); 
                });
            });

            // Apply Custom Range
            document.getElementById('applyFilterBtn').addEventListener('click', function() {
                if (selectedPeriod === 'custom' && (!startDateStr || !endDateStr)) {
                    alert('Mangyaring pumili ng kumpletong Start at End Date sa kalendaryo.');
                    return;
                }
                
                let currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('period', selectedPeriod);
                
                if (selectedPeriod === 'custom') {
                    currentUrl.searchParams.set('start', startDateStr);
                    currentUrl.searchParams.set('end', endDateStr);
                }
                
                window.location.href = currentUrl.toString(); 
            });
        });
        
        function closeDropdown() {
            var dropdownElement = document.getElementById('filterDropdown');
            var dropdownInstance = bootstrap.Dropdown.getInstance(dropdownElement);
            if (dropdownInstance) dropdownInstance.hide();
        }
    </script>
    
    <!-- EXECUTIVE CHARTS JAVASCRIPT -->
    <?php if (in_array($_SESSION['role'], ['President', 'GM'])): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            Chart.defaults.font.family = "'Inter', 'Segoe UI', sans-serif";
            Chart.defaults.color = '#64748b'; 
            Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.95)';
            Chart.defaults.plugins.tooltip.padding = 10;
            Chart.defaults.plugins.tooltip.cornerRadius = 8;
            Chart.defaults.plugins.tooltip.titleFont = { size: 12, weight: '600' };
            Chart.defaults.plugins.tooltip.bodyFont = { size: 12 };

            if(document.getElementById('gmAuditChart')) {
                const ctx = document.getElementById('gmAuditChart').getContext('2d');
                let gradient = ctx.createLinearGradient(0, 0, 0, 300);
                gradient.addColorStop(0, 'rgba(37, 99, 235, 0.2)'); 
                gradient.addColorStop(1, 'rgba(37, 99, 235, 0)');   

                const aLabels = gmData.audit.map(a => a.log_date);
                const aData = gmData.audit.map(a => a.action_count);
                
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: aLabels,
                        datasets: [{
                            label: 'System Actions',
                            data: aData,
                            borderColor: '#2563eb',
                            backgroundColor: gradient,
                            borderWidth: 3,
                            pointRadius: 0, 
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: '#2563eb',
                            pointHoverBorderColor: '#fff',
                            pointHoverBorderWidth: 2,
                            fill: true,
                            tension: 0.4
                        }]
                    },
                    options: {
                        maintainAspectRatio: false,
                        interaction: { intersect: false, mode: 'index' },
                        plugins: { legend: { display: false } },
                        scales: { 
                            y: { beginAtZero: true, grid: { borderDash: [2, 2], color: '#f1f5f9' }, border: { display: false }, ticks: { font: {size:10} } }, 
                            x: { grid: { display: false }, border: { display: false }, ticks: { font: {size:10} } } 
                        }
                    }
                });
            }

            if(document.getElementById('gmLifecycleChart')) {
                new Chart(document.getElementById('gmLifecycleChart'), {
                    type: 'doughnut',
                    data: {
                        labels: ['Active', 'Archived', 'Disposition'],
                        datasets: [{
                            data: [ gmData.lifecycle.active_docs, gmData.lifecycle.archived_docs, gmData.lifecycle.ready_disp ],
                            backgroundColor: ['#10b981', '#94a3b8', '#ef4444'],
                            borderWidth: 2,
                            borderColor: '#ffffff',
                            hoverOffset: 4
                        }]
                    },
                    options: {
                        cutout: '70%',
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 6, font: {size: 11, weight: '500'} } } }
                    }
                });
            }

            if(document.getElementById('gmVolumeChart')) {
                const vLabels = gmData.volume.map(v => v.category || 'Uncategorized');
                const vData = gmData.volume.map(v => v.count);
                new Chart(document.getElementById('gmVolumeChart'), {
                    type: 'bar',
                    data: { labels: vLabels, datasets: [{ label: 'Total Files', data: vData, backgroundColor: '#3b82f6', borderRadius: 4, barThickness: 12 }] },
                    options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, grid: { borderDash: [2, 2], color: '#f1f5f9' }, border: { display: false }, ticks: { precision: 0 } }, y: { grid: { display: false }, border: { display: false }, ticks: { font: {size: 11} } } } }
                });
            }

            if(document.getElementById('gmTurnaroundChart')) {
                const tLabels = gmData.turnaround.map(t => t.stage.replace('-Approved', ''));
                const tData = gmData.turnaround.map(t => t.avg_hours);
                new Chart(document.getElementById('gmTurnaroundChart'), {
                    type: 'bar',
                    data: { labels: tLabels, datasets: [{ label: 'Avg Hours Spent', data: tData, backgroundColor: '#f43f5e', borderRadius: 4, barThickness: 30 }] },
                    options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { borderDash: [2, 2], color: '#f1f5f9' }, border: { display: false } }, x: { grid: { display: false }, border: { display: false } } } }
                });
            }
        });
    </script>
    <?php endif; ?>

</body>
</html>