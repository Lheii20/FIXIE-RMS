<?php require 'dashboard_logic.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Overview & Analytics - Fixie DRMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link href="assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/assets/css/dashboard.css'); ?>" rel="stylesheet">
    <link href="assets/css/client-po-acknowledgement.css?v=<?php echo filemtime(__DIR__ . '/assets/css/client-po-acknowledgement.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="dashboard-page">
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <header class="dashboard-header mb-3 d-flex justify-content-between align-items-center gap-3">
            <div class="dashboard-heading flex-grow-1">
                <h5 class="fw-bold mb-1 tracking-tight text-main">Dashboard & Analytics</h5>
                <p class="text-muted mb-0 fs-sm">Welcome, <span class="fw-semibold text-dark"><?php echo htmlspecialchars($_SESSION['fullname']); ?></span>.</p>
            </div>
            
            <div class="dashboard-filter-wrap d-flex align-items-center">
                <div class="dropdown position-relative">
                    <button class="btn-filter-trigger dropdown-toggle justify-content-between" type="button" id="filterDropdown" data-bs-toggle="dropdown" data-bs-display="static" data-bs-auto-close="outside" aria-expanded="false" aria-label="Select dashboard date range">
                        <span><i class="far fa-calendar-alt text-secondary me-2"></i> <span id="displayFilterText"><?php echo $active_filter_text; ?></span></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end filter-dropdown-menu p-0" aria-labelledby="filterDropdown">
                        <div class="d-flex flex-column flex-md-row">
                            <div class="border-end p-3 bg-light" style="min-width: 150px;">
                                <div class="quick-filter-title">Presets</div>
                                <div class="d-flex flex-column">
                                    <button type="button" class="quick-filter-btn <?php echo ($period=='today')?'active':''; ?>" data-val="today">Today</button>
                                    <button type="button" class="quick-filter-btn <?php echo ($period=='this_week')?'active':''; ?>" data-val="this_week">This Week</button>
                                    <button type="button" class="quick-filter-btn <?php echo ($period=='this_month')?'active':''; ?>" data-val="this_month">This Month</button>
                                    <button type="button" class="quick-filter-btn <?php echo ($period=='this_year')?'active':''; ?>" data-val="this_year">This Year</button>
                                    <button type="button" class="quick-filter-btn <?php echo ($period=='all')?'active':''; ?>" data-val="all">All Time</button>
                                </div>
                            </div>
                            <div class="flex-grow-1 p-3 bg-white">
                                <div class="quick-filter-title mb-2">Custom Range</div>
                                <div class="custom-cal-header d-flex justify-content-between align-items-center mb-2">
                                    <button type="button" id="calPrev" class="custom-cal-nav" aria-label="Previous month" title="Previous month"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
                                    <div class="d-flex gap-2">
                                        <select id="calMonth" class="custom-cal-select">
                                            <option value="0">January</option> <option value="1">February</option> <option value="2">March</option>
                                            <option value="3">April</option> <option value="4">May</option> <option value="5">June</option>
                                            <option value="6">July</option> <option value="7">August</option> <option value="8">September</option>
                                            <option value="9">October</option> <option value="10">November</option> <option value="11">December</option>
                                        </select>
                                        <select id="calYear" class="custom-cal-select"></select>
                                    </div>
                                    <button type="button" id="calNext" class="custom-cal-nav" aria-label="Next month" title="Next month"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
                                </div>
                                <div class="calendar-wrapper"><input type="text" id="inlineCalendarContainer" class="d-none"></div>
                                <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 pt-2 border-top gap-2">
                                    <div class="custom-range-display" id="customRangeDisplay">
                                        <?php echo ($period == 'custom' && !empty($_GET['start']) && !empty($_GET['end'])) ? "<strong>".date('M d, Y', strtotime($_GET['start']))."</strong> &mdash; <strong>".date('M d, Y', strtotime($_GET['end']))."</strong>" : "<span class='text-muted fw-normal fst-italic'>Select dates...</span>"; ?>
                                    </div>
                                    <div class="d-flex gap-2 ms-auto">
                                        <button type="button" class="btn btn-sm btn-light text-secondary fw-bold px-3 border-0" onclick="closeDropdown()">Cancel</button>
                                        <button type="button" class="btn btn-sm btn-primary fw-bold px-3 shadow-sm rounded-custom" id="applyFilterBtn">Apply</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- ============================================== -->
        <!-- ADMIN DASHBOARD SECTION -->
        <!-- ============================================== -->
        <?php if ($_SESSION['role'] === 'Admin'): ?>
            <div class="row g-3 mb-3 dashboard-kpi-grid">
                <div class="col-xl-3 col-md-6"><a href="admin_users.php" class="text-decoration-none"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Active Users</p><h3 class="kpi-corp-value mt-1"><?php echo $admin_stats['total_users']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-users"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-primary box-12" style="font-size: 5px;"></i> System credentials</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="documents.php" class="text-decoration-none"><div class="kpi-corp-card accent-purple"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Managed Files</p><h3 class="kpi-corp-value mt-1"><?php echo $admin_stats['total_files']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10" style="color: #8b5cf6;"><i class="fas fa-folder-open"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle box-12" style="color: #8b5cf6; font-size: 5px;"></i> Uploaded records</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="admin_requests.php" class="text-decoration-none"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending Requests</p><h3 class="kpi-corp-value mt-1"><?php echo $admin_stats['pending_requests']; ?></h3></div><div class="kpi-corp-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-shield-alt"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-danger box-12" style="font-size: 5px;"></i> Needs your approval</div></div></a></div>
                
                <?php 
                    $pct = $admin_insights_data['storage_pct']; 
                    $p_color = ($pct > 85) ? 'bg-danger' : (($pct > 60) ? 'bg-warning' : 'bg-success');
                    $t_color = ($pct > 85) ? 'text-danger' : (($pct > 60) ? 'text-warning' : 'text-success');
                    $accent = ($pct > 85) ? 'accent-rose' : (($pct > 60) ? 'accent-amber' : 'accent-emerald');
                ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-corp-card <?php echo $accent; ?>">
                        <div class="kpi-corp-header">
                            <div><p class="kpi-corp-title">Storage Health</p><h3 class="kpi-corp-value mt-1"><?php echo $admin_insights_data['storage_formatted']; ?></h3></div>
                            <div class="kpi-corp-icon <?php echo $p_color; ?> bg-opacity-10 <?php echo $t_color; ?>"><i class="fas fa-server"></i></div>
                        </div>
                        <div class="mt-auto pt-2">
                            <div class="d-flex justify-content-between align-items-center mb-1 fs-xs fw-bold">
                                <span class="<?php echo $t_color; ?>"><?php echo $pct; ?>% Used</span><span class="text-muted">50 GB Max</span>
                            </div>
                            <div class="progress shadow-sm" style="height: 5px; border-radius: 3px; background-color: #f1f5f9;">
                                <div class="progress-bar <?php echo $p_color; ?>" role="progressbar" style="width: <?php echo $pct; ?>%;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php 
                $admin_insights = []; 
                $p_req = $admin_insights_data['pending_req_all'] ?? 0;
                
                $admin_insights[] = [
                    'status' => ($p_req > 0) ? 'danger' : 'success', 
                    'icon' => ($p_req > 0) ? 'fa-shield-alt' : 'fa-check-circle', 
                    'title' => ($p_req > 0) ? 'Action Required: Security Requests' : 'Support Queue Cleared', 
                    'desc' => ($p_req > 0) ? "There are currently <strong>{$p_req}</strong> overall pending user request(s) awaiting your administrative approval." : "No pending security requests from users across all records."
                ];
                
                $t_today = $admin_insights_data['today_traffic'] ?? 0; 
                $t_days = $admin_insights_data['total_days'] ?? 1; 
                $t_logs = $admin_insights_data['total_logs'] ?? 0; 
                $avg_daily = ($t_days > 0) ? ($t_logs / $t_days) : 0; 
                $spike = ($t_today > ($avg_daily * 1.5));
                
                $admin_insights[] = [
                    'status' => $spike ? 'warning' : 'info', 
                    'icon' => $spike ? 'fa-exclamation-triangle' : 'fa-server', 
                    'title' => $spike ? 'System Traffic Spike Detected' : 'Stable System Usage', 
                    'desc' => $spike ? "Today's activity reached <strong>{$t_today} actions</strong>, notably higher than the historical daily average of " . round($avg_daily) . ". Monitor for unusual events." : "Overall system interaction remains stable and within normal historical thresholds."
                ];
                
                $top_user = $admin_insights_data['top_user'] ?? null;
                if ($top_user) { 
                    $admin_insights[] = [
                        'status' => 'primary', 
                        'icon' => 'fa-user-check', 
                        'title' => 'Top System Contributor', 
                        'desc' => "<strong>" . htmlspecialchars($top_user['full_name']) . "</strong> is the all-time most active user with <strong>" . number_format($top_user['c']) . "</strong> total interactions."
                    ]; 
                }
                
                $t_files = $admin_insights_data['total_files_all'] ?? 0;
                $admin_insights[] = [
                    'status' => 'success', 
                    'icon' => 'fa-database', 
                    'title' => 'Total Repository Volume', 
                    'desc' => "The system currently safeguards a total of <strong>" . number_format($t_files) . "</strong> documents (including archives) across all departments."
                ];
            ?>
            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-8">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-server text-primary"></i> System Traffic & Activity Trend</h6></div>
                        <div class="chart-box"><canvas id="adminTrafficChart"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header mb-3"><h6 class="corp-widget-title text-dark"><i class="fas fa-shield-alt text-warning"></i>Security & System Insights</h6></div>
                        <div class="dss-insights">
                            <?php foreach($admin_insights as $i): ?>
                                <div class="insight-card p-2 mb-2 rounded border-start border-4 border-<?php echo $i['status']; ?> bg-<?php echo $i['status']; ?> bg-opacity-10">
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="text-<?php echo $i['status']; ?> mt-1 text-center" style="width: 20px;"><i class="fas <?php echo $i['icon']; ?>"></i></div>
                                        <div>
                                            <span class="d-block fw-bold text-dark fs-xs"><?php echo $i['title']; ?></span>
                                            <span class="text-muted fs-xs" style="line-height: 1.35; display: block; margin-top: 2px;"><?php echo $i['desc']; ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-pie text-emerald"></i> User Role Distribution</h6></div><div class="chart-box"><canvas id="adminRolesChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-users text-primary"></i> Most Active Users</h6></div><div class="chart-box"><canvas id="adminActiveUsersChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-headset text-rose"></i> Support Requests Workload</h6></div><div class="chart-box"><canvas id="adminRequestsChart"></canvas></div></div></div>
            </div>

            <!-- DISPOSAL REPORT (DSS) FOR ADMIN (SWAPPED) -->
            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-4">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header">
                            <h6 class="corp-widget-title"><i class="fas fa-tasks text-warning"></i> Pending Disposition</h6>
                        </div>
                        <div class="chart-box"><canvas id="adminDisposalActionChart"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header">
                            <h6 class="corp-widget-title"><i class="fas fa-history text-danger"></i> Disposal Report (DSS): Historical Actions</h6>
                        </div>
                        <div class="chart-box"><canvas id="adminDisposalHistoryChart"></canvas></div>
                    </div>
                </div>
            </div>

        <!-- ============================================== -->
        <!-- EXECUTIVE DASHBOARD SECTION -->
        <!-- ============================================== -->
        <?php elseif (in_array($_SESSION['role'], ['GM', 'President'])): ?>
            <?php if ($_SESSION['role'] === 'GM'): ?>
                <a href="quotations_list.php?filter=For%20GM%20Acknowledgement" class="po-ack-dashboard-queue">
                    <span class="po-ack-dashboard-copy">
                        <span class="po-ack-dashboard-icon"><i class="fas fa-file-signature"></i></span>
                        <span>
                            <strong>Official Client PO review queue</strong>
                            <small>Client POs waiting for your authenticated acknowledgment before PRF preparation</small>
                        </span>
                    </span>
                    <span class="po-ack-dashboard-count">
                        <span><?php echo (int) $exec_stats['pending_client_po_ack']; ?></span>
                        Review queue <i class="fas fa-arrow-right"></i>
                    </span>
                </a>
            <?php endif; ?>

            <div class="row g-3 mb-3 dashboard-kpi-grid">
                <div class="col-xl-3 col-md-6"><a href="documents.php" class="text-decoration-none"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Active Records</p><h3 class="kpi-corp-value mt-1"><?php echo $exec_stats['active_docs']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-folder-open"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-primary box-12" style="font-size: 5px;"></i> Current working files</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="documents.php?view_filter=All" class="text-decoration-none"><div class="kpi-corp-card accent-slate"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Archived Docs</p><h3 class="kpi-corp-value mt-1"><?php echo $exec_stats['archived_docs']; ?></h3></div><div class="kpi-corp-icon bg-secondary bg-opacity-10 text-secondary"><i class="fas fa-archive"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-secondary box-12" style="font-size: 5px;"></i> Safely stored records</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="pr_list.php?queue=mine" class="text-decoration-none"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">My PRF Queue</p><h3 class="kpi-corp-value mt-1"><?php echo $exec_stats['pending_pr']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-file-signature"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-warning box-12" style="font-size: 5px;"></i> Assigned to your stage</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=<?php echo $_SESSION['role'] === 'GM' ? 'Pending' : 'Finance-Approved'; ?>" class="text-decoration-none"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending POs</p><h3 class="kpi-corp-value mt-1"><?php echo $exec_stats['pending_po']; ?></h3></div><div class="kpi-corp-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-stamp"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-danger box-12" style="font-size: 5px;"></i> Action required</div></div></a></div>
            </div>

            <?php 
                $insights = []; 
                $pending_client_po_ack = $_SESSION['role'] === 'GM'
                    ? (int) $exec_stats['pending_client_po_ack']
                    : 0;
                $total_pending = $exec_stats['pending_pr'] +
                    $exec_stats['pending_po'] +
                    $pending_client_po_ack;
                
                $insights[] = [
                    'status' => ($total_pending > 0) ? 'danger' : 'success', 
                    'icon' => ($total_pending > 0) ? 'fa-signature' : 'fa-check-circle', 
                    'title' => ($total_pending > 0) ? 'Action Required: Pending Approvals' : 'Approval Queue Clear', 
                    'desc' => ($total_pending > 0)
                        ? ($_SESSION['role'] === 'GM'
                            ? "You have <strong>{$pending_client_po_ack}</strong> Client PO(s), <strong>{$exec_stats['pending_pr']}</strong> PR(s), and <strong>{$exec_stats['pending_po']}</strong> supplier PO(s) awaiting your sign-off."
                            : "You have <strong>{$exec_stats['pending_pr']}</strong> PR(s) and <strong>{$exec_stats['pending_po']}</strong> PO(s) awaiting your executive sign-off.")
                        : "Your approval queue is currently empty. Excellent turnaround!"
                ];

                $uncoll_amt = $gm_charts['uncollected']['total_uncollected'] ?? 0; 
                $uncoll_cnt = $gm_charts['uncollected']['count_uncollected'] ?? 0;
                $overdue_amt = $gm_charts['uncollected']['overdue_amount'] ?? 0;
                $overdue_cnt = $gm_charts['uncollected']['overdue_count'] ?? 0;
                $due_soon_amt = $gm_charts['uncollected']['due_soon_amount'] ?? 0;
                $due_soon_cnt = $gm_charts['uncollected']['due_soon_count'] ?? 0;
                $missing_due_amt = $gm_charts['uncollected']['missing_due_amount'] ?? 0;
                $missing_due_cnt = $gm_charts['uncollected']['missing_due_count'] ?? 0;

                if ($overdue_amt > 0) {
                    $insights[] = [
                        'status' => 'danger',
                        'icon' => 'fa-triangle-exclamation',
                        'title' => 'Overdue Collections Alert',
                        'desc' => "<strong>₱ " . number_format($overdue_amt, 2) . "</strong> across <strong>{$overdue_cnt}</strong> PO(s) is already overdue. Total open collection exposure is <strong>₱ " . number_format($uncoll_amt, 2) . "</strong>."
                    ];
                } elseif ($missing_due_cnt > 0) {
                    $insights[] = [
                        'status' => 'warning',
                        'icon' => 'fa-calendar-xmark',
                        'title' => 'Collection Due-Date Gap',
                        'desc' => "<strong>{$missing_due_cnt}</strong> open PO(s), totaling <strong>₱ " . number_format($missing_due_amt, 2) . "</strong>, have no reliable collection due date. Finance should review the legacy delivery record."
                    ];
                } elseif ($due_soon_amt > 0) {
                    $insights[] = [
                        'status' => 'warning',
                        'icon' => 'fa-clock',
                        'title' => 'Collections Due Within 3 Days',
                        'desc' => "<strong>₱ " . number_format($due_soon_amt, 2) . "</strong> across <strong>{$due_soon_cnt}</strong> PO(s) is approaching its client payment deadline. Proactive Finance follow-up is advised."
                    ];
                } else {
                    $insights[] = [
                        'status' => ($uncoll_amt > 0) ? 'warning' : 'success',
                        'icon' => ($uncoll_amt > 0) ? 'fa-file-invoice-dollar' : 'fa-check-double',
                        'title' => ($uncoll_amt > 0) ? 'Pending Collection Exposure' : 'Collections Up-to-date',
                        'desc' => ($uncoll_amt > 0)
                            ? "<strong>₱ " . number_format($uncoll_amt, 2) . "</strong> across <strong>{$uncoll_cnt}</strong> delivered PO(s) remains collectible, with no currently overdue balance."
                            : "All delivered purchase orders within this period have been fully collected."
                    ];
                }

                $aging_po = $gm_charts['aging_po'] ?? null; 
                $hrs_stag = isset($aging_po['hours_stagnant']) ? (int)$aging_po['hours_stagnant'] : 0;
                
                $insights[] = [
                    'status' => ($hrs_stag >= 48) ? 'danger' : 'info', 
                    'icon' => ($hrs_stag >= 48) ? 'fa-hourglass-half' : 'fa-clock', 
                    'title' => ($hrs_stag >= 48) ? 'Stagnant Workflow Alert' : 'Healthy Workflow Pace', 
                    'desc' => ($hrs_stag >= 48) ? "PO <strong>" . htmlspecialchars($aging_po['po_number']) . "</strong> has been stuck at <strong>{$aging_po['status']}</strong> (Location: {$aging_po['current_location']}) for <strong>{$hrs_stag} hours</strong>. Please review to prevent SLA breaches." : "No active purchase orders have been stagnant for more than 48 hours."
                ];
                
                $highest_stage = 'None'; 
                $highest_hours = 0; 
                $total_avg_hours = 0; 
                $stage_count = 0;
                $stage_names = ['GM-Approved' => 'GM Approval', 'Finance-Approved' => 'Finance Validation', 'President-Approved' => 'President Approval', 'Funded' => 'Funding', 'Delivered' => 'Delivery'];
                
                if(!empty($gm_charts['turnaround'])) { 
                    foreach($gm_charts['turnaround'] as $t) { 
                        $total_avg_hours += $t['avg_hours']; 
                        $stage_count++; 
                        if($t['avg_hours'] > $highest_hours) { 
                            $highest_hours = $t['avg_hours']; 
                            $highest_stage = $stage_names[$t['stage']] ?? str_replace('-Approved', '', $t['stage']); 
                        } 
                    } 
                }
                
                $overall_avg = ($stage_count > 0) ? ($total_avg_hours / $stage_count) : 0; 
                $is_bottleneck = ($highest_hours > 12 && $highest_hours > ($overall_avg * 1.5));
                
                $insights[] = [
                    'status' => $is_bottleneck ? 'danger' : 'success', 
                    'icon' => $is_bottleneck ? 'fa-project-diagram' : 'fa-tachometer-alt', 
                    'title' => $is_bottleneck ? 'Workflow Bottleneck Detected' : 'Optimal Workflow Processing', 
                    'desc' => $is_bottleneck ? "The <strong>{$highest_stage}</strong> phase averages <strong>{$highest_hours} hrs</strong>, significantly slower than the overall standard (" . round($overall_avg,1) . " hrs). Investigate this stage." : "Document processing stages are balanced with an overall average of <strong>" . round($overall_avg,1) . " hrs</strong>."
                ];
                
                $tot_q = $gm_charts['quote_conversion']['total_quotes'] ?? 0; 
                $conv_q = $gm_charts['quote_conversion']['converted_quotes'] ?? 0; 
                $conv_rate = ($tot_q > 0) ? round(($conv_q / $tot_q) * 100) : 0;
                
                $insights[] = [
                    'status' => ($conv_rate >= 50) ? 'primary' : (($tot_q > 0) ? 'warning' : 'secondary'), 
                    'icon' => 'fa-handshake', 
                    'title' => 'Sales Conversion Rate', 
                    'desc' => ($tot_q > 0) ? "<strong>{$conv_rate}%</strong> of sent quotations ({$conv_q} out of {$tot_q}) converted to Client POs. " . ($conv_rate < 50 ? "Consider reviewing pricing or sending follow-ups." : "Excellent conversion momentum.") : "No client quotations were drafted within the selected period."
                ];

                $top_client = $gm_charts['top_client'] ?? null;
                $insights[] = [
                    'status' => 'primary', 
                    'icon' => 'fa-building', 
                    'title' => 'Top Client Activity', 
                    'desc' => (!empty($top_client)) ? "<strong>" . htmlspecialchars($top_client['client_name']) . "</strong> generated the highest volume with <strong>{$top_client['tx_count']}</strong> transaction(s). Ensure high SLA standards for this key account." : "No purchase order transactions recorded for client volume analysis."
                ];

                $disp_count = $gm_charts['lifecycle']['ready_disp'] ?? 0;
                $insights[] = [
                    'status' => ($disp_count > 0) ? 'danger' : 'success', 
                    'icon' => ($disp_count > 0) ? 'fa-archive' : 'fa-shield-alt', 
                    'title' => ($disp_count > 0) ? 'Retention Compliance Alert' : 'Fully Compliant Records', 
                    'desc' => ($disp_count > 0) ? "<strong>{$disp_count}</strong> historical records have reached maturity and are ready for disposition. Immediate action is advised." : "All active and archived records are well within their legal retention limits."
                ];

            ?>
            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-8">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-line text-primary"></i>Daily Transaction Volume</h6></div>
                        <div class="chart-box"><canvas id="gmActivityTrendChart"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header mb-3"><h6 class="corp-widget-title text-dark"><i class="fas fa-lightbulb text-warning"></i>Insights & Recommendations</h6></div>
                        <div class="dss-insights">
                            <?php foreach($insights as $i): ?>
                                <div class="insight-card p-2 mb-2 rounded border-start border-4 border-<?php echo $i['status']; ?> bg-<?php echo $i['status']; ?> bg-opacity-10">
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="text-<?php echo $i['status']; ?> mt-1 text-center" style="width: 20px;"><i class="fas <?php echo $i['icon']; ?>"></i></div>
                                        <div>
                                            <span class="d-block fw-bold text-dark fs-xs"><?php echo $i['title']; ?></span>
                                            <span class="text-muted fs-xs" style="line-height: 1.35; display: block; margin-top: 2px;"><?php echo $i['desc']; ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-folder-open text-info"></i> Record Volume Distribution</h6></div><div class="chart-box"><canvas id="gmVolumeChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-project-diagram text-rose"></i> Processing Bottleneck (Avg Hrs)</h6></div><div class="chart-box"><canvas id="gmTurnaroundChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-pie text-emerald"></i> Document Lifecycle</h6></div><div class="chart-box"><canvas id="gmLifecycleChart"></canvas></div></div></div>
            </div>

            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-8">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header">
                            <h6 class="corp-widget-title"><i class="fas fa-search-location text-warning"></i> Retrieval Frequency Report (DSS)</h6>
                        </div>
                        <div class="chart-box"><canvas id="gmRetrievalChart"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header mb-3">
                            <h6 class="corp-widget-title text-dark"><i class="fas fa-info-circle text-info"></i> Frequency Analysis</h6>
                        </div>
                        <div class="p-3 bg-light rounded-custom border border-light text-muted fs-sm" style="line-height: 1.6;">
                            This Decision Support System (DSS) report identifies the most frequently accessed and downloaded records across the organization. High retrieval rates on specific documents may indicate operational priority, frequent audits, or critical active transactions that require management attention.
                        </div>
                    </div>
                </div>
            </div>

            <!-- DISPOSAL REPORT (DSS) FOR EXECUTIVES (SWAPPED) -->
            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-4">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header">
                            <h6 class="corp-widget-title"><i class="fas fa-tasks text-warning"></i> Pending Disposition</h6>
                        </div>
                        <div class="chart-box"><canvas id="gmDisposalActionChart"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header">
                            <h6 class="corp-widget-title"><i class="fas fa-history text-danger"></i> Disposal Report (DSS): Historical Actions</h6>
                        </div>
                        <div class="chart-box"><canvas id="gmDisposalHistoryChart"></canvas></div>
                    </div>
                </div>
            </div>

        <!-- ============================================== -->
        <!-- FINANCE DASHBOARD SECTION -->
        <!-- ============================================== -->
        <?php elseif ($_SESSION['role'] === 'Finance'): ?>
            <div class="row g-3 mb-3 dashboard-kpi-grid">
                <div class="col-xl-3 col-md-6"><a href="pr_list.php?queue=mine" class="text-decoration-none"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">PRFs to Review</p><h3 class="kpi-corp-value mt-1"><?php echo $finance_stats['pending_prf']; ?></h3></div><div class="kpi-corp-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-calculator"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-danger box-12" style="font-size: 5px;"></i> COGS and fund validation</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=GM-Approved" class="text-decoration-none"><div class="kpi-corp-card accent-emerald"><div class="kpi-corp-header"><div><p class="kpi-corp-title">POs to Validate</p><h3 class="kpi-corp-value mt-1"><?php echo $finance_stats['pending_po']; ?></h3></div><div class="kpi-corp-icon bg-success bg-opacity-10 text-success"><i class="fas fa-file-signature"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-success box-12" style="font-size: 5px;"></i> Purchase order validation</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="collection_monitoring.php" class="text-decoration-none"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Outstanding Balance</p><h3 class="kpi-corp-value mt-1 text-md">₱ <?php echo number_format($finance_stats['uncollected_amount'], 2); ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-cash-register"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-warning box-12" style="font-size: 5px;"></i> <?php echo number_format($finance_stats['open_collection_count']); ?> open receivable<?php echo $finance_stats['open_collection_count'] === 1 ? '' : 's'; ?></div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="collection_ledger.php" class="text-decoration-none"><div class="kpi-corp-card accent-purple"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Collected Value</p><h3 class="kpi-corp-value mt-1 text-md">₱ <?php echo number_format($finance_stats['collected_value'], 2); ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10" style="color: #8b5cf6;"><i class="fas fa-chart-line"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle box-12" style="color: #8b5cf6; font-size: 5px;"></i> <?php echo number_format($finance_stats['collection_rate'], 1); ?>% collection realization</div></div></a></div>
            </div>

            <?php 
                $fin_insights = []; 
                $future_est = $finance_charts['future_sum'] ?? 0;
                
                $fin_insights[] = [
                    'status' => ($future_est > 0) ? 'primary' : 'secondary', 
                    'icon' => 'fa-chart-line', 
                    'title' => 'Revenue Prediction Forecast', 
                    'desc' => ($future_est > 0) ? "Predictive analytics forecast a potential revenue of <strong>₱ " . number_format($future_est, 2) . "</strong> over the next 3 months based on established linear trends." : "Insufficient historical data to generate an accurate 3-month forecast."
                ];
                
                $cf_in_arr = $finance_charts['cf_in'] ?? [0];
                $cf_out_arr = $finance_charts['cf_out'] ?? [0];
                $cf_in = end($cf_in_arr); 
                $cf_out = end($cf_out_arr); 
                $is_positive = ($cf_in >= $cf_out);
                
                $fin_insights[] = [
                    'status' => $is_positive ? 'success' : 'danger', 
                    'icon' => $is_positive ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down', 
                    'title' => $is_positive ? 'Positive Cash Flow Trend' : 'Negative Cash Flow Alert', 
                    'desc' => "For the latest recorded month, total cash inflows (₱ ".number_format((float)$cf_in, 2).") " . ($is_positive ? "exceeded" : "fell short of") . " total outflows (₱ ".number_format((float)$cf_out, 2).")."
                ];
                
                $outstanding_amount = (float) $finance_stats['uncollected_amount'];
                $overdue_amount = (float) $finance_stats['overdue_amount'];
                $overdue_count = (int) $finance_stats['overdue_count'];
                $due_soon_amount = (float) $finance_stats['due_soon_amount'];
                $due_soon_count = (int) $finance_stats['due_soon_count'];
                $missing_due_amount = (float) $finance_stats['missing_due_amount'];
                $missing_due_count = (int) $finance_stats['missing_due_count'];

                if ($overdue_amount > 0) {
                    $fin_insights[] = [
                        'status' => 'danger',
                        'icon' => 'fa-triangle-exclamation',
                        'title' => 'Immediate Collection Required',
                        'desc' => "<strong>₱ " . number_format($overdue_amount, 2) . "</strong> across <strong>{$overdue_count}</strong> receivable(s) is overdue. Prioritize these accounts in Collection Monitoring."
                    ];
                } elseif ($missing_due_count > 0) {
                    $fin_insights[] = [
                        'status' => 'warning',
                        'icon' => 'fa-calendar-xmark',
                        'title' => 'Due-Date Review Required',
                        'desc' => "<strong>{$missing_due_count}</strong> open receivable(s), totaling <strong>₱ " . number_format($missing_due_amount, 2) . "</strong>, have no reliable due date. Review the delivery record before aging analysis."
                    ];
                } elseif ($due_soon_amount > 0) {
                    $fin_insights[] = [
                        'status' => 'warning',
                        'icon' => 'fa-clock',
                        'title' => 'Collections Due Within 3 Days',
                        'desc' => "<strong>₱ " . number_format($due_soon_amount, 2) . "</strong> across <strong>{$due_soon_count}</strong> receivable(s) needs proactive client follow-up."
                    ];
                } else {
                    $fin_insights[] = [
                        'status' => ($outstanding_amount > 0) ? 'primary' : 'success',
                        'icon' => ($outstanding_amount > 0) ? 'fa-file-invoice-dollar' : 'fa-check-double',
                        'title' => ($outstanding_amount > 0) ? 'Receivables Within Term' : 'No Open Receivables',
                        'desc' => ($outstanding_amount > 0)
                            ? "The remaining <strong>₱ " . number_format($outstanding_amount, 2) . "</strong> is still within the recorded client payment term."
                            : "All delivered receivables in the selected period are fully collected."
                    ];
                }

                $collection_rate = (float) $finance_stats['collection_rate'];
                $collection_position_value =
                    (float) $finance_stats['collected_value'] +
                    $outstanding_amount;
                $fin_insights[] = [
                    'status' => $collection_position_value <= 0
                        ? 'secondary'
                        : ($collection_rate >= 90
                            ? 'success'
                            : ($collection_rate >= 75 ? 'primary' : 'warning')),
                    'icon' => 'fa-percent',
                    'title' => 'Collection Realization Rate',
                    'desc' => $collection_position_value > 0
                        ? "<strong>" . number_format($collection_rate, 1) . "%</strong> of delivered receivable value is already collected, leaving <strong>₱ " . number_format($outstanding_amount, 2) . "</strong> outstanding."
                        : "No delivered receivable value is available for collection-rate analysis in the selected period."
                ];
                
                $p_prf = $finance_stats['pending_prf'];
                $p_po = $finance_stats['pending_po'];
                $finance_action_count = $p_prf + $p_po;
                $fin_insights[] = [
                    'status' => ($finance_action_count > 0) ? 'warning' : 'success', 
                    'icon' => 'fa-stamp', 
                    'title' => ($finance_action_count > 0) ? 'Finance Queue Requires Action' : 'Finance Queue Cleared', 
                    'desc' => ($finance_action_count > 0)
                        ? "You have <strong>{$p_prf}</strong> PRF(s) awaiting COGS review and <strong>{$p_po}</strong> PO(s) awaiting financial validation."
                        : "No PRFs or purchase orders are waiting for your approval."
                ];
            ?>
            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-8">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-area text-primary"></i> Monthly Sales & Revenue Forecast</h6></div>
                        <div class="chart-box"><canvas id="finRevenueChart"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header mb-3"><h6 class="corp-widget-title text-dark"><i class="fas fa-lightbulb text-warning"></i>Finance & Sales Insights</h6></div>
                        <div class="dss-insights">
                            <?php foreach($fin_insights as $i): ?>
                                <div class="insight-card p-2 mb-2 rounded border-start border-4 border-<?php echo $i['status']; ?> bg-<?php echo $i['status']; ?> bg-opacity-10">
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="text-<?php echo $i['status']; ?> mt-1 text-center" style="width: 20px;"><i class="fas <?php echo $i['icon']; ?>"></i></div>
                                        <div>
                                            <span class="d-block fw-bold text-dark fs-xs"><?php echo $i['title']; ?></span>
                                            <span class="text-muted fs-xs" style="line-height: 1.35; display: block; margin-top: 2px;"><?php echo $i['desc']; ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-exchange-alt text-success"></i> Cash Flow Trend</h6></div><div class="chart-box"><canvas id="finCashflowChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-level-up-alt text-primary"></i> MoM Revenue Growth</h6></div><div class="chart-box"><canvas id="finMomChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-building text-info"></i> Client Balances & Revenue</h6></div><div class="chart-box"><canvas id="finTopClientsRadarChart"></canvas></div></div></div>
            </div>
            
        <!-- ============================================== -->
        <!-- PROCUREMENT DASHBOARD SECTION -->
        <!-- ============================================== -->
        <?php elseif ($_SESSION['role'] === 'Procurement'): ?>
            <div class="row g-3 mb-3 dashboard-kpi-grid">
                <div class="col-xl-3 col-md-6"><a href="pr_list.php?queue=mine" class="text-decoration-none"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">PRFs Ready for PO</p><h3 class="kpi-corp-value mt-1"><?php echo $proc_stats['ready_prf']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-clipboard-check"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-primary box-12" style="font-size: 5px;"></i> Officially approved handoff</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=In_Progress" class="text-decoration-none"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending Approvals</p><h3 class="kpi-corp-value mt-1"><?php echo $proc_stats['pending']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-hourglass-half"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-warning box-12" style="font-size: 5px;"></i> Waiting for sign-off</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Funded" class="text-decoration-none"><div class="kpi-corp-card accent-emerald"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Funded Orders</p><h3 class="kpi-corp-value mt-1"><?php echo $proc_stats['funded']; ?></h3></div><div class="kpi-corp-icon bg-success bg-opacity-10 text-success"><i class="fas fa-money-bill-wave"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-success box-12" style="font-size: 5px;"></i> Ready for purchasing</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Completed" class="text-decoration-none"><div class="kpi-corp-card accent-purple"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Delivered Orders</p><h3 class="kpi-corp-value mt-1"><?php echo $proc_stats['delivered']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10" style="color: #8b5cf6;"><i class="fas fa-truck-loading"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle box-12" style="color: #8b5cf6; font-size: 5px;"></i> Client handoff completed</div></div></a></div>
            </div>
            
            <?php 
                $proc_insights = []; 
                $tot_spent = $proc_charts['total_spent'] ?? 0;
                
                $proc_insights[] = [
                    'status' => 'primary', 
                    'icon' => 'fa-chart-pie', 
                    'title' => 'Total Procurement Spend', 
                    'desc' => "Total value of active and completed purchase orders is <strong>₱ " . number_format($tot_spent, 2) . "</strong> for the selected period."
                ];
                
                $stagnant = $proc_charts['stagnant'] ?? null;
                if ($stagnant && $stagnant['hours_wait'] > 24) { 
                    $proc_insights[] = [
                        'status' => 'danger', 
                        'icon' => 'fa-exclamation-circle', 
                        'title' => 'Approval Bottleneck', 
                        'desc' => "PO <strong>" . htmlspecialchars($stagnant['po_number']) . "</strong> has been stuck at <strong>{$stagnant['status']}</strong> for over <strong>{$stagnant['hours_wait']} hours</strong>. Follow up with " . htmlspecialchars($stagnant['current_location']) . "."
                    ]; 
                } else { 
                    $proc_insights[] = [
                        'status' => 'success', 
                        'icon' => 'fa-check-circle', 
                        'title' => 'Smooth Processing', 
                        'desc' => "No major approval bottlenecks detected. Purchase orders are moving through the workflow efficiently."
                    ]; 
                }
                
                $top_cat = !empty($proc_charts['top_cats']) ? $proc_charts['top_cats'][0] : null;
                if ($top_cat) { 
                    $proc_insights[] = [
                        'status' => 'info', 
                        'icon' => 'fa-boxes', 
                        'title' => 'Highest Spending Category', 
                        'desc' => "<strong>" . htmlspecialchars($top_cat['cat_name']) . "</strong> leads procurement spending with <strong>₱ " . number_format($top_cat['spent'], 2) . "</strong>."
                    ]; 
                }
                
                $ready_prf = $proc_stats['ready_prf'];
                $p_po = $proc_stats['pending'];
                $proc_insights[] = [
                    'status' => ($ready_prf > 0) ? 'primary' : (($p_po > 0) ? 'warning' : 'success'), 
                    'icon' => 'fa-clipboard-list', 
                    'title' => ($ready_prf > 0) ? 'New Official PRF Handoff' : 'Procurement Queue', 
                    'desc' => ($ready_prf > 0)
                        ? "There are <strong>{$ready_prf}</strong> officially approved PRF(s) ready for supplier PO preparation. You also have <strong>{$p_po}</strong> encoded PO(s) moving through approval."
                        : (($p_po > 0)
                            ? "There are <strong>{$p_po}</strong> encoded purchase orders waiting for executive or finance approval."
                            : "No approved PRFs are awaiting conversion and all encoded purchase orders are processed.")
                ];
            ?>
            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-8">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-line text-primary"></i> Daily PO Generation Trend</h6></div>
                        <div class="chart-box"><canvas id="procTrendChart"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header mb-3"><h6 class="corp-widget-title text-dark"><i class="fas fa-lightbulb text-warning"></i> Procurement Insights</h6></div>
                        <div class="dss-insights">
                            <?php foreach($proc_insights as $i): ?>
                                <div class="insight-card p-2 mb-2 rounded border-start border-4 border-<?php echo $i['status']; ?> bg-<?php echo $i['status']; ?> bg-opacity-10">
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="text-<?php echo $i['status']; ?> mt-1 text-center" style="width: 20px;"><i class="fas <?php echo $i['icon']; ?>"></i></div>
                                        <div>
                                            <span class="d-block fw-bold text-dark fs-xs"><?php echo $i['title']; ?></span>
                                            <span class="text-muted fs-xs" style="line-height: 1.35; display: block; margin-top: 2px;"><?php echo $i['desc']; ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-pie text-emerald"></i> PO Status Overview</h6></div><div class="chart-box"><canvas id="procStatusChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-tags text-primary"></i> Top Categories (Spend)</h6></div><div class="chart-box"><canvas id="procCategoryChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-building text-info"></i> Top Brands Purchased</h6></div><div class="chart-box"><canvas id="procBrandChart"></canvas></div></div></div>
            </div>

        <!-- ============================================== -->
        <!-- SUPPLY CHAIN DASHBOARD SECTION -->
        <!-- ============================================== -->
        <?php elseif ($_SESSION['role'] === 'Supply Chain'): ?>
            <div class="row g-3 mb-3 dashboard-kpi-grid">
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Delivery_Queue" class="text-decoration-none"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Delivery Queue</p><h3 class="kpi-corp-value mt-1"><?php echo $sc_stats['ready_for_delivery']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-truck-loading"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-warning box-12" style="font-size: 5px;"></i> Requests awaiting logistics completion</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Completed" class="text-decoration-none"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Delivered Orders</p><h3 class="kpi-corp-value mt-1"><?php echo $sc_stats['delivered']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-truck"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-primary box-12" style="font-size: 5px;"></i> Delivery handoffs completed</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Awaiting_Collection" class="text-decoration-none"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Awaiting Collection</p><h3 class="kpi-corp-value mt-1"><?php echo $sc_stats['awaiting_collection']; ?></h3></div><div class="kpi-corp-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-hand-holding-usd"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-danger box-12" style="font-size: 5px;"></i> Delivered with an open balance</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php" class="text-decoration-none"><div class="kpi-corp-card accent-emerald"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Delivery Proofs Filed</p><h3 class="kpi-corp-value mt-1"><?php echo $sc_stats['delivery_proofs']; ?></h3></div><div class="kpi-corp-icon bg-success bg-opacity-10 text-success"><i class="fas fa-clipboard-check"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-success box-12" style="font-size: 5px;"></i> Proof-of-delivery records</div></div></a></div>
            </div>

            <?php 
                $sc_insights = []; 
                $ready = $sc_stats['ready_for_delivery'];
                
                $sc_insights[] = [
                    'status' => $ready > 0 ? 'warning' : 'success', 
                    'icon' => $ready > 0 ? 'fa-truck-loading' : 'fa-check-circle', 
                    'title' => $ready > 0 ? 'Delivery Action Required' : 'Delivery Queue Clear', 
                    'desc' => $ready > 0 ? "There are <strong>{$ready}</strong> approved delivery request(s) awaiting logistics review, scheduling, or client handoff." : 'There are no approved delivery requests waiting for Supply Chain action.'
                ];
                
                $handoff = $sc_stats['awaiting_collection'];
                $sc_insights[] = [
                    'status' => $handoff > 0 ? 'info' : 'success', 
                    'icon' => 'fa-share-square', 
                    'title' => $handoff > 0 ? 'Finance Collection Handoff' : 'Collection Handoffs Complete', 
                    'desc' => $handoff > 0 ? "<strong>{$handoff}</strong> delivered order(s) are now awaiting Finance collection. Delivery responsibility has been completed for these records." : 'No delivered orders are currently awaiting collection handoff.'
                ];
                
                $proof_gap = max(0, $sc_stats['delivered'] - $sc_stats['delivery_proofs']);
                $sc_insights[] = [
                    'status' => $proof_gap > 0 ? 'danger' : 'success', 
                    'icon' => $proof_gap > 0 ? 'fa-file-circle-exclamation' : 'fa-file-circle-check', 
                    'title' => $proof_gap > 0 ? 'Review Delivery Documentation' : 'Delivery Proof Coverage', 
                    'desc' => $proof_gap > 0 ? "Up to <strong>{$proof_gap}</strong> delivery record(s) may still need a proof-of-delivery file. Attach the signed receipt or acknowledgement before closing the handoff." : 'Every delivery in the selected period has a matching proof-of-delivery record.'
                ];
                
                $completed = $sc_stats['completed_collections'];
                $sc_insights[] = [
                    'status' => 'primary', 
                    'icon' => 'fa-flag-checkered', 
                    'title' => 'Completed Fulfilment Cycle', 
                    'desc' => "<strong>{$completed}</strong> delivered order(s) in the selected period are fully paid."
                ];
            ?>
            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-8">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-line text-primary"></i> Delivery Completion Trend</h6></div>
                        <div class="chart-box"><canvas id="scDeliveryTrendChart"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header mb-3"><h6 class="corp-widget-title text-dark"><i class="fas fa-lightbulb text-warning"></i> Supply Chain Insights</h6></div>
                        <div class="dss-insights">
                            <?php foreach($sc_insights as $i): ?>
                                <div class="insight-card p-2 mb-2 rounded border-start border-4 border-<?php echo $i['status']; ?> bg-<?php echo $i['status']; ?> bg-opacity-10">
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="text-<?php echo $i['status']; ?> mt-1 text-center" style="width: 20px;"><i class="fas <?php echo $i['icon']; ?>"></i></div>
                                        <div>
                                            <span class="d-block fw-bold text-dark fs-xs"><?php echo $i['title']; ?></span>
                                            <span class="text-muted fs-xs" style="line-height: 1.35; display: block; margin-top: 2px;"><?php echo $i['desc']; ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-pie text-emerald"></i> Fulfilment Status</h6></div><div class="chart-box"><canvas id="scStatusChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-building text-info"></i> Delivery Volume by Client</h6></div><div class="chart-box"><canvas id="scClientChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-file-signature text-success"></i> Proof Coverage</h6></div><div class="chart-box"><canvas id="scProofChart"></canvas></div></div></div>
            </div>

        <!-- ============================================== -->
        <!-- SALES STAFF DASHBOARD SECTION -->
        <!-- ============================================== -->
        <?php elseif ($_SESSION['role'] === 'Sales Staff'): ?>
            <div class="row g-3 mb-3 dashboard-kpi-grid">
                <div class="col-xl-3 col-md-6"><a href="pr_list.php" class="text-decoration-none"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Total PR Generated</p><h3 class="kpi-corp-value mt-1"><?php echo $sales_stats['total']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-file-invoice"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-primary box-12" style="font-size: 5px;"></i> All requests created</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="pr_list.php?filter=Pending" class="text-decoration-none"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending PRs</p><h3 class="kpi-corp-value mt-1"><?php echo $sales_stats['pending']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-clock"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-warning box-12" style="font-size: 5px;"></i> Waiting for management</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="quotations_list.php?filter=Pending PO" class="text-decoration-none"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending Quotes</p><h3 class="kpi-corp-value mt-1"><?php echo $sales_stats['pending_quotations']; ?></h3></div><div class="kpi-corp-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-file-contract"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-danger box-12" style="font-size: 5px;"></i> Waiting for Client PO</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="pr_list.php?filter=Approved" class="text-decoration-none"><div class="kpi-corp-card accent-emerald"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Approved PRs</p><h3 class="kpi-corp-value mt-1"><?php echo $sales_stats['approved']; ?></h3></div><div class="kpi-corp-icon bg-success bg-opacity-10 text-success"><i class="fas fa-check-double"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-success box-12" style="font-size: 5px;"></i> Ready for processing</div></div></a></div>
            </div>

            <?php 
                $sales_insights = []; 
                $app_count = $sales_stats['approved'] ?? 0;
                
                $sales_insights[] = [
                    'status' => 'primary', 
                    'icon' => 'fa-rocket', 
                    'title' => 'Approved Pipeline', 
                    'desc' => "You have <strong>{$app_count}</strong> approved Purchase Requests successfully handed over to procurement."
                ];
                
                $pend_q = $sales_stats['pending_quotations'];
                $gm_po_wait = $sales_stats['awaiting_gm_client_po'] ?? 0;
                $sales_insights[] = [
                    'status' => ($pend_q > 0 || $gm_po_wait > 0) ? 'warning' : 'success', 
                    'icon' => 'fa-envelope-open-text', 
                    'title' => ($pend_q > 0) ? 'Quotation Follow-ups' : 'Client PO Status', 
                    'desc' => ($pend_q > 0)
                        ? "You have <strong>{$pend_q}</strong> pending quotation(s). Please follow up with your clients to secure their Purchase Orders."
                        : (($gm_po_wait > 0)
                            ? "<strong>{$gm_po_wait}</strong> official Client PO(s) are waiting for General Manager acknowledgment."
                            : "All received Client POs have completed General Manager acknowledgment.")
                ];
                
                $pend_pr = $sales_stats['pending'];
                $sales_insights[] = [
                    'status' => ($pend_pr > 0) ? 'info' : 'success', 
                    'icon' => 'fa-user-clock', 
                    'title' => 'Internal Approvals', 
                    'desc' => ($pend_pr > 0) ? "<strong>{$pend_pr}</strong> of your Purchase Requests are currently pending review from General Management." : "No pending PRs awaiting management approval."
                ];
                
                $last_rej = $sales_charts['latest_rejected'] ?? null;
                if ($last_rej) { 
                    $sales_insights[] = [
                        'status' => 'danger', 
                        'icon' => 'fa-times-circle', 
                        'title' => 'Recent PR Rejection', 
                        'desc' => "PR <strong>" . htmlspecialchars($last_rej['pr_number']) . "</strong> for <strong>" . htmlspecialchars($last_rej['client_name']) . "</strong> was rejected. Please review the details."
                    ]; 
                }
            ?>
            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-8">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-area text-primary"></i> Daily PR Submission Pipeline</h6></div>
                        <div class="chart-box"><canvas id="salesTrendChart"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="corp-widget h-100">
                        <div class="corp-widget-header mb-3"><h6 class="corp-widget-title text-dark"><i class="fas fa-lightbulb text-warning"></i> Sales Insights</h6></div>
                        <div class="dss-insights">
                            <?php foreach($sales_insights as $i): ?>
                                <div class="insight-card p-2 mb-2 rounded border-start border-4 border-<?php echo $i['status']; ?> bg-<?php echo $i['status']; ?> bg-opacity-10">
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="text-<?php echo $i['status']; ?> mt-1 text-center" style="width: 20px;"><i class="fas <?php echo $i['icon']; ?>"></i></div>
                                        <div>
                                            <span class="d-block fw-bold text-dark fs-xs"><?php echo $i['title']; ?></span>
                                            <span class="text-muted fs-xs" style="line-height: 1.35; display: block; margin-top: 2px;"><?php echo $i['desc']; ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-pie text-emerald"></i> PR Status Distribution</h6></div><div class="chart-box"><canvas id="salesPrStatusChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-boxes text-info"></i> Top Requested Categories (Qty)</h6></div><div class="chart-box"><canvas id="salesTopCatChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget h-100"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-building text-primary"></i> Top Clients (Transaction Count)</h6></div><div class="chart-box"><canvas id="salesTopClientsChart"></canvas></div></div></div>
            </div>

        <!-- ============================================== -->
        <!-- FALLBACK DASHBOARD (OTHER ROLES) -->
        <!-- ============================================== -->
        <?php else: ?>
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="corp-widget p-0 overflow-hidden">
                        <div class="corp-widget-header px-4 pt-3 pb-2 border-bottom-0"><h6 class="corp-widget-title"><i class="fas fa-tasks text-primary"></i> <?php echo (isset($is_sales_staff) && $is_sales_staff) ? 'Recent PRs' : 'Active POs'; ?></h6></div>
                        <div class="table-responsive" style="max-height: 220px;">
                            <table class="table table-corp align-middle mb-0">
                                <thead class="bg-light sticky-top">
                                    <tr><th class="ps-4">Number</th><th>Client</th><th>Total</th><th>Status</th><?php if(!isset($is_sales_staff) || !$is_sales_staff): ?><th>Location</th><?php endif; ?></tr>
                                </thead>
                                <tbody>
                                    <?php if($my_recent && $my_recent->num_rows > 0): while($doc = $my_recent->fetch_assoc()): ?>
                                        <tr <?php echo (!isset($is_sales_staff) || !$is_sales_staff) ? "style='cursor: pointer;' onclick=\"window.location.href='view_po.php?id={$doc['id']}';\"" : ""; ?>>
                                            <td class="ps-4 fw-bold text-primary">#<?php echo htmlspecialchars($doc['number']); ?></td>
                                            <td class="fw-medium"><?php echo htmlspecialchars($doc['client_name']); ?></td>
                                            <td class="fw-bold">₱ <?php echo number_format($doc['amount'], 2); ?></td>
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
            
            <?php if (!empty($user_categories)): ?>
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="corp-widget p-0 overflow-hidden">
                        <div class="corp-widget-header px-4 pt-3 pb-2 border-bottom-0"><h6 class="corp-widget-title"><i class="fas fa-folder-open text-warning"></i> Recent Department Files</h6></div>
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
        <?php endif; ?>

    </div>

    <!-- ============================================== -->
    <!-- CHART DATA INJECTION -->
    <!-- ============================================== -->
    <?php if (isset($admin_charts) && !empty($admin_charts)): ?><script>const adminData = <?php echo json_encode($admin_charts); ?>;</script><?php endif; ?>
    <?php if (isset($gm_charts) && !empty($gm_charts)): ?><script>const gmData = <?php echo json_encode($gm_charts); ?>;</script><?php endif; ?>
    <?php if (isset($finance_charts) && !empty($finance_charts)): ?><script>const finData = <?php echo json_encode($finance_charts); ?>;</script><?php endif; ?>
    <?php if (isset($proc_charts) && !empty($proc_charts)): ?><script>const procData = <?php echo json_encode($proc_charts); ?>;</script><?php endif; ?>
    <?php if (isset($sc_charts) && !empty($sc_charts)): ?><script>const scData = <?php echo json_encode($sc_charts); ?>;</script><?php endif; ?>
    <?php if (isset($sales_charts) && !empty($sales_charts)): ?><script>const salesData = <?php echo json_encode($sales_charts); ?>;</script><?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof scData === 'undefined' || typeof Chart === 'undefined') return;

            const renderChart = function (id, config, hasData) {
                const canvas = document.getElementById(id); if (!canvas) return; const container = canvas.parentElement;
                if (!hasData) { 
                    canvas.style.display = 'none'; 
                    if (!container.querySelector('.no-data-message')) { 
                        const message = document.createElement('div'); 
                        message.className = 'no-data-message'; 
                        message.innerHTML = '<i class="fas fa-inbox"></i><span>No records found for this period.</span>'; 
                        container.appendChild(message); 
                    } 
                    return; 
                }
                new Chart(canvas.getContext('2d'), config);
            };

            const trend = scData.delivery_trend || [];
            renderChart('scDeliveryTrendChart', { type: 'line', data: { labels: trend.map(row => row.delivery_date), datasets: [{ label: 'Delivered Orders', data: trend.map(row => Number(row.total)), borderColor: '#2563eb', backgroundColor: 'rgba(37, 99, 235, 0.12)', borderWidth: 3, fill: true, tension: 0.4, pointBackgroundColor: '#fff', pointBorderColor: '#2563eb', pointBorderWidth: 2, pointRadius: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { borderDash: [4, 4], color: '#f1f5f9' } } } } }, trend.length > 0);
            
            const statuses = scData.status_dist || [];
            renderChart('scStatusChart', { type: 'doughnut', data: { labels: statuses.map(row => row.status), datasets: [{ data: statuses.map(row => Number(row.total)), backgroundColor: ['#f59e0b', '#2563eb', '#8b5cf6', '#10b981'], borderWidth: 0 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '72%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } } } }, statuses.length > 0);
            
            const clients = scData.top_clients || [];
            renderChart('scClientChart', { type: 'bar', data: { labels: clients.map(row => row.client_name), datasets: [{ label: 'Delivered Orders', data: clients.map(row => Number(row.total)), backgroundColor: '#0ea5e9', borderRadius: 6, barPercentage: 0.65 }] }, options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { borderDash: [4, 4], color: '#f1f5f9' } }, y: { grid: { display: false } } } } }, clients.length > 0);
            
            const proofs = scData.proof_coverage || [];
            renderChart('scProofChart', { type: 'doughnut', data: { labels: proofs.map(row => row.label), datasets: [{ data: proofs.map(row => Number(row.total)), backgroundColor: ['#10b981', '#e2e8f0'], borderWidth: 0 }] }, options: { responsive: true, maintainAspectRatio: false, cutout: '72%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14 } } } } }, proofs.some(row => Number(row.total) > 0));
        });
    </script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let selectedPeriod = "<?php echo $period; ?>"; 
            let startDateStr = "<?php echo $_GET['start'] ?? ''; ?>"; 
            let endDateStr = "<?php echo $_GET['end'] ?? ''; ?>";
            
            const calMonth = document.getElementById('calMonth'); 
            const calYear = document.getElementById('calYear'); 
            const calPrev = document.getElementById('calPrev'); 
            const calNext = document.getElementById('calNext'); 
            const currentY = new Date().getFullYear();
            
            for(let i = currentY - 15; i <= currentY + 15; i++) { 
                let opt = document.createElement('option'); 
                opt.value = i; 
                opt.text = i; 
                calYear.appendChild(opt); 
            }

            const fp = flatpickr("#inlineCalendarContainer", {
                mode: "range", inline: true, showMonths: 1, defaultDate: (startDateStr && endDateStr) ? [startDateStr, endDateStr] : null,
                onReady: function(selectedDates, dateStr, instance) { calMonth.value = instance.currentMonth; calYear.value = instance.currentYear; },
                onMonthChange: function(selectedDates, dateStr, instance) { calMonth.value = instance.currentMonth; calYear.value = instance.currentYear; },
                onYearChange: function(selectedDates, dateStr, instance) { calMonth.value = instance.currentMonth; calYear.value = instance.currentYear; },
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

            function updateFlatpickrView() { fp.jumpToDate(new Date(parseInt(calYear.value), parseInt(calMonth.value), 1)); }
            calMonth.addEventListener('change', updateFlatpickrView); 
            calYear.addEventListener('change', updateFlatpickrView);
            calPrev.addEventListener('click', function(e){ e.preventDefault(); fp.changeMonth(-1); }); 
            calNext.addEventListener('click', function(e){ e.preventDefault(); fp.changeMonth(1); });

            const filterDropdown = document.getElementById('filterDropdown');
            if(filterDropdown) { filterDropdown.addEventListener('show.bs.dropdown', function () { fp.redraw(); }); }

            document.querySelectorAll('.quick-filter-btn').forEach(btn => { 
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

            document.getElementById('applyFilterBtn').addEventListener('click', function() { 
                if (selectedPeriod === 'custom' && (!startDateStr || !endDateStr)) { 
                    alert('Please select a complete Start and End Date from the calendar.'); 
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
            var dd = bootstrap.Dropdown.getInstance(document.getElementById('filterDropdown')); 
            if (dd) dd.hide(); 
        }
    </script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Chart.defaults.font.family = "'Inter', 'Segoe UI', sans-serif"; 
            Chart.defaults.color = '#64748b'; 
            Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15, 23, 42, 0.95)'; 
            Chart.defaults.plugins.tooltip.titleColor = '#ffffff'; 
            Chart.defaults.plugins.tooltip.bodyColor = '#f8fafc'; 
            Chart.defaults.plugins.tooltip.padding = 12; 
            Chart.defaults.plugins.tooltip.cornerRadius = 8; 
            Chart.defaults.plugins.tooltip.titleFont = { size: 13, weight: '600' }; 
            Chart.defaults.plugins.tooltip.bodyFont = { size: 12 }; 
            Chart.defaults.plugins.tooltip.usePointStyle = true; 

            Chart.register({
                id: 'noDataPlugin',
                afterDraw: function(chart) {
                    let hasData = false;
                    for (let i = 0; i < chart.data.datasets.length; i++) { 
                        let dataset = chart.data.datasets[i]; 
                        if(dataset.data && dataset.data.length > 0) { 
                            for (let j = 0; j < dataset.data.length; j++) { 
                                if (Number(dataset.data[j]) > 0) { hasData = true; break; } 
                            } 
                        } 
                        if (hasData) break; 
                    }
                    if (!hasData) { 
                        let ctx = chart.ctx; let width = chart.width; let height = chart.height; 
                        chart.clear(); ctx.save(); 
                        ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; 
                        ctx.font = '600 13px Inter, sans-serif'; ctx.fillStyle = '#94a3b8'; 
                        ctx.fillText('No records found for this period', width / 2, height / 2); 
                        ctx.restore(); 
                    }
                }
            });

            // ==============================================
            // ADMIN CHARTS
            // ==============================================
            if(document.getElementById('adminTrafficChart') && typeof adminData !== 'undefined') {
                const ctxTr = document.getElementById('adminTrafficChart').getContext('2d');
                const trLabels = adminData.traffic.map(t => { let d = new Date(t.log_date); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); }); 
                const trData = adminData.traffic.map(t => t.action_count);
                
                let gradTr = ctxTr.createLinearGradient(0, 0, 0, 300); 
                gradTr.addColorStop(0, 'rgba(59, 130, 246, 0.4)'); 
                gradTr.addColorStop(1, 'rgba(59, 130, 246, 0.0)');
                
                new Chart(ctxTr, { type: 'line', data: { labels: trLabels.length ? trLabels : ['No Date'], datasets: [{ label: 'System Actions', data: trData, borderColor: '#3b82f6', backgroundColor: gradTr, borderWidth: 3, fill: true, tension: 0.4, pointBackgroundColor: '#ffffff', pointBorderColor: '#3b82f6', pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6 }] }, options: { maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: { display: false } }, x: { grid: { display: false }, border: { display: false } } } } });
            }

            if(document.getElementById('adminRolesChart') && typeof adminData !== 'undefined') {
                const rLabels = adminData.roles.map(r => r.role); 
                const rData = adminData.roles.map(r => r.user_count);
                new Chart(document.getElementById('adminRolesChart'), { type: 'doughnut', data: { labels: rLabels.length ? rLabels : ['No Roles'], datasets: [{ data: rData, backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ec4899', '#0ea5e9', '#64748b'], hoverOffset: 6 }] }, options: { cutout: '65%', maintainAspectRatio: false, layout: { padding: 10 }, plugins: { legend: { position: 'right', labels: { usePointStyle: true, padding: 15, font: {size: 11} } } } } });
            }

            if(document.getElementById('adminActiveUsersChart') && typeof adminData !== 'undefined') {
                const ctxUsers = document.getElementById('adminActiveUsersChart').getContext('2d'); 
                let horizGrad = ctxUsers.createLinearGradient(0, 0, 300, 0); 
                horizGrad.addColorStop(0, 'rgba(16, 185, 129, 0.8)'); 
                horizGrad.addColorStop(1, 'rgba(59, 130, 246, 0.8)');
                const uLabels = adminData.active_users.map(u => u.full_name); 
                const uData = adminData.active_users.map(u => u.activity_count);
                new Chart(ctxUsers, { type: 'bar', data: { labels: uLabels.length ? uLabels : ['No Users'], datasets: [{ label: 'Total Actions', data: uData, backgroundColor: horizGrad, borderRadius: 6, barPercentage: 0.65 }] }, options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' } }, y: { grid: { display: false }, ticks: {font: {size: 10}} } } } });
            }

            if(document.getElementById('adminRequestsChart') && typeof adminData !== 'undefined') {
                const reqLabels = adminData.requests.map(r => r.status); 
                const reqData = adminData.requests.map(r => r.req_count);
                const reqColors = reqLabels.map(l => l === 'Pending' ? 'rgba(245, 158, 11, 0.7)' : (l === 'Approved' ? 'rgba(16, 185, 129, 0.7)' : 'rgba(239, 68, 68, 0.7)')); 
                const reqBorders = reqLabels.map(l => l === 'Pending' ? '#f59e0b' : (l === 'Approved' ? '#10b981' : '#ef4444'));
                new Chart(document.getElementById('adminRequestsChart'), { type: 'polarArea', data: { labels: reqLabels.length ? reqLabels : ['No Requests'], datasets: [{ data: reqData, backgroundColor: reqColors, borderColor: reqBorders, borderWidth: 2 }] }, options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, font: {size: 10} } } }, scales: { r: { ticks: { display: false }, grid: { color: '#e2e8f0' } } } } });
            }

            // --- ADMIN DISPOSAL REPORT (DSS) CHARTS ---
            if(typeof adminData !== 'undefined' && adminData.disposal) {
                if(document.getElementById('adminDisposalHistoryChart')) {
                    const ctxHist = document.getElementById('adminDisposalHistoryChart').getContext('2d');
                    const dHist = adminData.disposal.history;
                    const hLabels = dHist.map(h => { let d = new Date(h.disp_date); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); });
                    const archData = dHist.map(h => h.archived_count);
                    const destData = dHist.map(h => h.destroyed_count);

                    new Chart(ctxHist, {
                        type: 'bar',
                        data: {
                            labels: hLabels.length ? hLabels : ['No Record'],
                            datasets: [
                                { label: 'Archived Documents', data: archData, backgroundColor: 'rgba(99, 102, 241, 0.8)', borderRadius: 4 },
                                { label: 'Destroyed / Deleted', data: destData, backgroundColor: 'rgba(239, 68, 68, 0.8)', borderRadius: 4 }
                            ]
                        },
                        options: {
                            maintainAspectRatio: false,
                            interaction: { intersect: false, mode: 'index' },
                            plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: {size: 11} } } },
                            scales: {
                                y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: {display: false}, ticks: {stepSize: 1} },
                                x: { grid: { display: false }, border: {display: false} }
                            }
                        }
                    });
                }
                if(document.getElementById('adminDisposalActionChart')) {
                    const ctxAction = document.getElementById('adminDisposalActionChart').getContext('2d');
                    const dAction = adminData.disposal.by_action;
                    const aLabels = dAction.map(a => a.action_type);
                    const aData = dAction.map(a => a.cnt);

                    const colorMap = { 'Archive': 'rgba(99, 102, 241, 0.8)', 'Destroy': 'rgba(239, 68, 68, 0.8)', 'Review Required': 'rgba(245, 158, 11, 0.8)' };
                    const bgColors = aLabels.map(l => colorMap[l] || 'rgba(100, 116, 139, 0.8)');

                    new Chart(ctxAction, {
                        type: 'doughnut',
                        data: {
                            labels: aLabels.length ? aLabels : ['No Record'],
                            datasets: [{ data: aData, backgroundColor: bgColors, hoverOffset: 6, borderWidth: 2 }]
                        },
                        options: {
                            cutout: '70%',
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15, font: {size: 11} } } }
                        }
                    });
                }
            }

            // ==============================================
            // EXECUTIVE CHARTS (GM / PRESIDENT)
            // ==============================================
            if(document.getElementById('gmActivityTrendChart') && typeof gmData !== 'undefined') {
                const ctxAct = document.getElementById('gmActivityTrendChart').getContext('2d');
                const fLabels = gmData.activity_trend.map(f => { let d = new Date(f.a_date); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); });
                const reqQuoteData = gmData.activity_trend.map(f => f.req_quote_count); 
                const poData = gmData.activity_trend.map(f => f.po_count); 
                const finFulfillData = gmData.activity_trend.map(f => f.fin_fulfill_count); 
                const docData = gmData.activity_trend.map(f => f.doc_count); 
                const approvalData = gmData.activity_trend.map(f => f.approval_count);
                
                let gradPO = ctxAct.createLinearGradient(0, 0, 0, 300); 
                gradPO.addColorStop(0, 'rgba(59, 130, 246, 0.3)'); 
                gradPO.addColorStop(1, 'rgba(59, 130, 246, 0.0)');
                
                let gradDoc = ctxAct.createLinearGradient(0, 0, 0, 300); 
                gradDoc.addColorStop(0, 'rgba(244, 63, 94, 0.3)'); 
                gradDoc.addColorStop(1, 'rgba(244, 63, 94, 0.0)');
                
                new Chart(ctxAct, { type: 'line', data: { labels: fLabels.length ? fLabels : ['No Date'], datasets: [ { label: 'Purchase Orders', data: poData, borderColor: '#3b82f6', backgroundColor: gradPO, borderWidth: 2, fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 6 }, { label: 'Files & Records Uploaded', data: docData, borderColor: '#f43f5e', backgroundColor: gradDoc, borderWidth: 2, fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 6 }, { label: 'Payments & Fulfillment', data: finFulfillData, borderColor: '#10b981', backgroundColor: 'transparent', borderWidth: 2, fill: false, tension: 0.4, pointRadius: 0, pointHoverRadius: 6 }, { label: 'Workflow Approvals', data: approvalData, borderColor: '#8b5cf6', backgroundColor: 'transparent', borderWidth: 2, fill: false, tension: 0.4, pointRadius: 0, pointHoverRadius: 6 }, { label: 'PRs & Quotations', data: reqQuoteData, borderColor: '#f59e0b', backgroundColor: 'transparent', borderWidth: 2, borderDash: [5, 5], fill: false, tension: 0.4, pointRadius: 0, pointHoverRadius: 6 } ] }, options: { maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { display: true, position: 'top', labels: { usePointStyle: true, boxWidth: 8, font: {size: 11}, padding: 25 } }, tooltip: { callbacks: { title: function(context) { return context[0].label; } } } }, scales: { y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9', drawBorder: false }, border: { display: false }, ticks: { font: {size:11}, padding: 10, stepSize: 1 } }, x: { grid: { display: false }, border: { display: false }, ticks: { font: {size:11}, maxRotation: 45, autoSkip: true, maxTicksLimit: 10, padding: 10 } } } } });
            }

            if(document.getElementById('gmLifecycleChart') && typeof gmData !== 'undefined') {
                new Chart(document.getElementById('gmLifecycleChart'), { type: 'pie', data: { labels: ['Active', 'Archived', 'Disposition'], datasets: [{ data: [ gmData.lifecycle.active_docs, gmData.lifecycle.archived_docs, gmData.lifecycle.ready_disp ], backgroundColor: ['#10b981', '#6366f1', '#f43f5e'], hoverBackgroundColor: ['#059669', '#4f46e5', '#e11d48'], borderWidth: 2, borderColor: '#ffffff', hoverOffset: 6 }] }, options: { maintainAspectRatio: false, layout: { padding: 10 }, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20, font: {size: 12} } } } } });
            }

            if(document.getElementById('gmVolumeChart') && typeof gmData !== 'undefined') {
                const vLabels = gmData.volume.map(v => v.category || 'Uncategorized'); const vData = gmData.volume.map(v => v.count);
                new Chart(document.getElementById('gmVolumeChart'), { type: 'doughnut', data: { labels: vLabels.length ? vLabels : ['Uncategorized'], datasets: [{ data: vData, backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#0ea5e9', '#64748b'], hoverBackgroundColor: ['#2563eb', '#059669', '#d97706', '#7c3aed', '#db2777', '#0284c7', '#475569'], borderWidth: 3, borderColor: '#ffffff', hoverOffset: 6 }] }, options: { cutout: '65%', maintainAspectRatio: false, layout: { padding: 10 }, plugins: { legend: { position: 'right', labels: { usePointStyle: true, padding: 15, font: {size: 11} } } } } });
            }

            if(document.getElementById('gmTurnaroundChart') && typeof gmData !== 'undefined') {
                const ctxTurn = document.getElementById('gmTurnaroundChart').getContext('2d'); 
                let horizGradient = ctxTurn.createLinearGradient(0, 0, 300, 0); 
                horizGradient.addColorStop(0, 'rgba(244, 63, 94, 0.8)'); 
                horizGradient.addColorStop(1, 'rgba(249, 115, 22, 0.8)');
                
                const stageNameMap = { 'GM-Approved': 'GM Approval', 'Finance-Approved': 'Finance Validation', 'President-Approved': 'Pres Approval', 'Funded': 'Funding', 'Delivered': 'Delivery' }; 
                const tLabels = gmData.turnaround.map(t => stageNameMap[t.stage] || t.stage); 
                const tData = gmData.turnaround.map(t => t.avg_hours);
                new Chart(ctxTurn, { type: 'bar', data: { labels: tLabels.length ? tLabels : ['No Record'], datasets: [{ label: 'Avg Hours Spent', data: tData, backgroundColor: horizGradient, borderRadius: 6, barPercentage: 0.65 }] }, options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: { display: false }, title: { display: true, text: 'Average Hours', font: {size: 11, weight: '600'}, color: '#94a3b8' } }, y: { grid: { display: false }, border: { display: false }, ticks: { font: {size: 11, weight: '600'} } } } } });
            }

            // Initialization for Retrieval Frequency Chart (DSS)
            if(document.getElementById('gmRetrievalChart') && typeof gmData !== 'undefined' && gmData.retrieval_freq) {
                const ctxRet = document.getElementById('gmRetrievalChart').getContext('2d');
                const retLabels = gmData.retrieval_freq.map(r => r.label);
                const retData = gmData.retrieval_freq.map(r => r.count);
                const retBgColors = gmData.retrieval_freq.map(r => r.type === 'DOWNLOAD_DOC' ? 'rgba(16, 185, 129, 0.8)' : 'rgba(59, 130, 246, 0.8)');

                new Chart(ctxRet, {
                    type: 'bar',
                    data: {
                        labels: retLabels.length ? retLabels : ['No Record'],
                        datasets: [{
                            label: 'Access Frequency',
                            data: retData,
                            backgroundColor: retBgColors,
                            borderRadius: 4,
                            barPercentage: 0.6
                        }]
                    },
                    options: {
                        indexAxis: 'y', 
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(ctx) { return ctx.raw + ' Accesses'; }
                                }
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                grid: { borderDash: [4, 4], color: '#f1f5f9' },
                                border: { display: false },
                                ticks: { precision: 0 }
                            },
                            y: {
                                grid: { display: false },
                                border: { display: false },
                                ticks: { font: {size: 11}, color: '#64748b' }
                            }
                        }
                    }
                });
            }

            // --- EXECUTIVE DISPOSAL REPORT (DSS) CHARTS ---
            if(typeof gmData !== 'undefined' && gmData.disposal) {
                if(document.getElementById('gmDisposalHistoryChart')) {
                    const ctxHist = document.getElementById('gmDisposalHistoryChart').getContext('2d');
                    const dHist = gmData.disposal.history;
                    const hLabels = dHist.map(h => { let d = new Date(h.disp_date); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); });
                    const archData = dHist.map(h => h.archived_count);
                    const destData = dHist.map(h => h.destroyed_count);

                    new Chart(ctxHist, {
                        type: 'bar',
                        data: {
                            labels: hLabels.length ? hLabels : ['No Record'],
                            datasets: [
                                { label: 'Archived Documents', data: archData, backgroundColor: 'rgba(99, 102, 241, 0.8)', borderRadius: 4 },
                                { label: 'Destroyed / Deleted', data: destData, backgroundColor: 'rgba(239, 68, 68, 0.8)', borderRadius: 4 }
                            ]
                        },
                        options: {
                            maintainAspectRatio: false,
                            interaction: { intersect: false, mode: 'index' },
                            plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: {size: 11} } } },
                            scales: {
                                y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: {display: false}, ticks: {stepSize: 1} },
                                x: { grid: { display: false }, border: {display: false} }
                            }
                        }
                    });
                }
                if(document.getElementById('gmDisposalActionChart')) {
                    const ctxAction = document.getElementById('gmDisposalActionChart').getContext('2d');
                    const dAction = gmData.disposal.by_action;
                    const aLabels = dAction.map(a => a.action_type);
                    const aData = dAction.map(a => a.cnt);

                    const colorMap = { 'Archive': 'rgba(99, 102, 241, 0.8)', 'Destroy': 'rgba(239, 68, 68, 0.8)', 'Review Required': 'rgba(245, 158, 11, 0.8)' };
                    const bgColors = aLabels.map(l => colorMap[l] || 'rgba(100, 116, 139, 0.8)');

                    new Chart(ctxAction, {
                        type: 'doughnut',
                        data: {
                            labels: aLabels.length ? aLabels : ['No Record'],
                            datasets: [{ data: aData, backgroundColor: bgColors, hoverOffset: 6, borderWidth: 2 }]
                        },
                        options: {
                            cutout: '70%',
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15, font: {size: 11} } } }
                        }
                    });
                }
            }

            // ==============================================
            // FINANCE CHARTS 
            // ==============================================
            if(document.getElementById('finRevenueChart') && typeof finData !== 'undefined') {
                const ctxFinRev = document.getElementById('finRevenueChart').getContext('2d');
                let gradActual = ctxFinRev.createLinearGradient(0, 0, 0, 400); gradActual.addColorStop(0, 'rgba(59, 130, 246, 0.5)'); gradActual.addColorStop(1, 'rgba(59, 130, 246, 0.0)');
                let gradPred = ctxFinRev.createLinearGradient(0, 0, 0, 400); gradPred.addColorStop(0, 'rgba(139, 92, 246, 0.4)'); gradPred.addColorStop(1, 'rgba(139, 92, 246, 0.0)');
                new Chart(ctxFinRev, { type: 'line', data: { labels: finData.revenue_labels, datasets: [ { label: 'Actual Revenue', data: finData.revenue_actuals, borderColor: '#3b82f6', backgroundColor: gradActual, borderWidth: 3, fill: true, tension: 0.4, pointBackgroundColor: '#ffffff', pointBorderColor: '#3b82f6', pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6 }, { label: 'Predicted Trend', data: finData.revenue_predicteds, borderColor: '#8b5cf6', backgroundColor: gradPred, borderWidth: 3, borderDash: [5, 5], fill: true, tension: 0.4, pointBackgroundColor: '#ffffff', pointBorderColor: '#8b5cf6', pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6 } ] }, options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: {size: 12, family: 'Inter'} } }, tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.95)', titleFont: { size: 13, family: 'Inter' }, bodyFont: { size: 12, family: 'Inter' }, callbacks: { label: function(ctx) { return ctx.dataset.label + ': ₱ ' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2}); } } } }, scales: { y: { grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: {display: false}, ticks: { callback: function(val) { return '₱ ' + val.toLocaleString(); } } }, x: { grid: { display: false }, border: {display: false} } } } });
            }

            if(document.getElementById('finCashflowChart') && typeof finData !== 'undefined') {
                const ctxCF = document.getElementById('finCashflowChart').getContext('2d');
                new Chart(ctxCF, { type: 'line', data: { labels: finData.cf_labels, datasets: [ { label: 'Inflow', data: finData.cf_in, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', fill: true, tension: 0.4, borderWidth: 2, pointRadius: 3 }, { label: 'Outflow', data: finData.cf_out, borderColor: '#ef4444', backgroundColor: 'rgba(239, 68, 68, 0.1)', fill: true, tension: 0.4, borderWidth: 2, pointRadius: 3 } ] }, options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, font: {size: 10} } }, tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': ₱ ' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2}); } } } }, scales: { y: { grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: {display: false}, ticks: { font: {size: 10}, callback: function(val) { if(val >= 1000) return '₱ ' + (val/1000) + 'k'; return '₱ ' + val; } } }, x: { grid: { display: false }, ticks: { font: {size: 10} } } } } });
            }

            if(document.getElementById('finMomChart') && typeof finData !== 'undefined') {
                const ctxMom = document.getElementById('finMomChart').getContext('2d'); 
                const momColors = finData.mom_pct.map(val => val >= 0 ? 'rgba(16, 185, 129, 0.8)' : 'rgba(239, 68, 68, 0.8)');
                new Chart(ctxMom, { type: 'bar', data: { labels: finData.mom_labels, datasets: [{ label: 'Growth (%)', data: finData.mom_pct, backgroundColor: momColors, borderRadius: 4, barPercentage: 0.6 }] }, options: { maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return (ctx.parsed.y > 0 ? '+' : '') + ctx.parsed.y + '% MoM'; } } } }, scales: { y: { grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: {display: false}, ticks: { font: {size: 10}, callback: function(val) { return val + '%'; } } }, x: { grid: { display: false }, ticks: { font: {size: 10} } } } } });
            }

            if(document.getElementById('finTopClientsRadarChart') && typeof finData !== 'undefined') {
                const ctxTopRadar = document.getElementById('finTopClientsRadarChart').getContext('2d');
                new Chart(ctxTopRadar, { type: 'radar', data: { labels: finData.tc_labels.length ? finData.tc_labels : ['No Clients'], datasets: [ { label: 'Collected', data: finData.tc_col, backgroundColor: 'rgba(59, 130, 246, 0.3)', borderColor: '#3b82f6', pointBackgroundColor: '#3b82f6', borderWidth: 2 }, { label: 'Outstanding', data: finData.tc_uncol, backgroundColor: 'rgba(245, 158, 11, 0.3)', borderColor: '#f59e0b', pointBackgroundColor: '#f59e0b', borderWidth: 2 } ] }, options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, font: {size: 10} } }, tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': ₱ ' + ctx.parsed.r.toLocaleString(undefined, {minimumFractionDigits: 2}); } } } }, scales: { r: { ticks: { display: false }, grid: { color: '#e2e8f0' }, pointLabels: { font: { size: 9, family: 'Inter' }, color: '#64748b' } } } } });
            }

            // ==============================================
            // PROCUREMENT CHARTS 
            // ==============================================
            if(document.getElementById('procTrendChart') && typeof procData !== 'undefined') {
                const ctxProcTrend = document.getElementById('procTrendChart').getContext('2d');
                const ptLabels = procData.trend.map(t => { let d = new Date(t.t_date); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); }); 
                const ptData = procData.trend.map(t => t.po_count);
                
                let gradPt = ctxProcTrend.createLinearGradient(0, 0, 0, 300); 
                gradPt.addColorStop(0, 'rgba(59, 130, 246, 0.4)'); 
                gradPt.addColorStop(1, 'rgba(59, 130, 246, 0.0)');
                
                new Chart(ctxProcTrend, { type: 'line', data: { labels: ptLabels.length ? ptLabels : ['No Date'], datasets: [{ label: 'POs Created', data: ptData, borderColor: '#3b82f6', backgroundColor: gradPt, borderWidth: 3, fill: true, tension: 0.4, pointBackgroundColor: '#ffffff', pointBorderColor: '#3b82f6', pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6 }] }, options: { maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: { display: false }, ticks: {stepSize: 1} }, x: { grid: { display: false }, border: { display: false } } } } });
            }

            if(document.getElementById('procStatusChart') && typeof procData !== 'undefined') {
                const psLabels = procData.status_dist.map(s => s.status); 
                const psData = procData.status_dist.map(s => s.count);
                new Chart(document.getElementById('procStatusChart'), { type: 'doughnut', data: { labels: psLabels.length ? psLabels : ['No Data'], datasets: [{ data: psData, backgroundColor: ['#f59e0b', '#3b82f6', '#10b981', '#8b5cf6', '#64748b', '#ef4444'], hoverOffset: 6 }] }, options: { cutout: '65%', maintainAspectRatio: false, layout: { padding: 10 }, plugins: { legend: { position: 'right', labels: { usePointStyle: true, padding: 15, font: {size: 11} } } } } });
            }

            if(document.getElementById('procCategoryChart') && typeof procData !== 'undefined') {
                const ctxCat = document.getElementById('procCategoryChart').getContext('2d'); 
                let gradCat = ctxCat.createLinearGradient(0, 0, 300, 0); 
                gradCat.addColorStop(0, 'rgba(16, 185, 129, 0.8)'); 
                gradCat.addColorStop(1, 'rgba(59, 130, 246, 0.8)');
                
                const pcLabels = procData.top_cats.map(c => c.cat_name); 
                const pcData = procData.top_cats.map(c => c.spent);
                
                new Chart(ctxCat, { type: 'bar', data: { labels: pcLabels.length ? pcLabels : ['No Record'], datasets: [{ label: 'Total Spent (₱)', data: pcData, backgroundColor: gradCat, borderRadius: 6, barPercentage: 0.65 }] }, options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return '₱ ' + ctx.parsed.x.toLocaleString(undefined, {minimumFractionDigits: 2}); } } } }, scales: { x: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: { display: false }, ticks: { callback: function(val) { if(val >= 1000) return '₱ ' + (val/1000) + 'k'; return '₱ ' + val; } } }, y: { grid: { display: false }, border: { display: false }, ticks: { font: {size: 10} } } } } });
            }

            if(document.getElementById('procBrandChart') && typeof procData !== 'undefined') {
                const pbLabels = procData.top_brands.map(b => b.brand); 
                const pbData = procData.top_brands.map(b => b.spent);
                new Chart(document.getElementById('procBrandChart'), { type: 'polarArea', data: { labels: pbLabels.length ? pbLabels : ['No Brands'], datasets: [{ data: pbData, backgroundColor: ['rgba(59, 130, 246, 0.7)', 'rgba(16, 185, 129, 0.7)', 'rgba(245, 158, 11, 0.7)', 'rgba(139, 92, 246, 0.7)', 'rgba(236, 72, 153, 0.7)'], borderWidth: 2 }] }, options: { maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8, font: {size: 10} } }, tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': ₱ ' + ctx.raw.toLocaleString(undefined, {minimumFractionDigits: 2}); } } } }, scales: { r: { ticks: { display: false }, grid: { color: '#e2e8f0' } } } } });
            }

            // ==============================================
            // SALES STAFF CHARTS 
            // ==============================================
            if(document.getElementById('salesTrendChart') && typeof salesData !== 'undefined') {
                const ctxSalesTrend = document.getElementById('salesTrendChart').getContext('2d');
                const stLabels = salesData.trend.map(t => { let d = new Date(t.t_date); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); }); 
                const submittedData = salesData.trend.map(t => t.submitted_prs); 
                const approvedData = salesData.trend.map(t => t.approved_prs);
                
                let gradSub = ctxSalesTrend.createLinearGradient(0, 0, 0, 300); 
                gradSub.addColorStop(0, 'rgba(59, 130, 246, 0.4)'); 
                gradSub.addColorStop(1, 'rgba(59, 130, 246, 0.0)');
                
                let gradApp = ctxSalesTrend.createLinearGradient(0, 0, 0, 300); 
                gradApp.addColorStop(0, 'rgba(16, 185, 129, 0.4)'); 
                gradApp.addColorStop(1, 'rgba(16, 185, 129, 0.0)');
                
                new Chart(ctxSalesTrend, { type: 'line', data: { labels: stLabels.length ? stLabels : ['No Date'], datasets: [ { label: 'Submitted PRs', data: submittedData, borderColor: '#3b82f6', backgroundColor: gradSub, borderWidth: 3, fill: true, tension: 0.4, pointRadius: 3, pointHoverRadius: 6 }, { label: 'Approved PRs', data: approvedData, borderColor: '#10b981', backgroundColor: gradApp, borderWidth: 3, fill: true, tension: 0.4, pointRadius: 3, pointHoverRadius: 6 } ] }, options: { maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8, font: {size: 11}, padding: 20 } } }, scales: { y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: { display: false }, ticks: { stepSize: 1 } }, x: { grid: { display: false }, border: { display: false } } } } });
            }

            if(document.getElementById('salesPrStatusChart') && typeof salesData !== 'undefined') {
                const spLabels = salesData.pr_status.map(s => s.status); 
                const spData = salesData.pr_status.map(s => s.count);
                
                const colorMap = { 'Pending': 'rgba(245, 158, 11, 0.7)', 'Approved': 'rgba(16, 185, 129, 0.7)', 'Converted_to_PO': 'rgba(59, 130, 246, 0.7)', 'Rejected': 'rgba(239, 68, 68, 0.7)' }; 
                const borderMap = { 'Pending': '#f59e0b', 'Approved': '#10b981', 'Converted_to_PO': '#3b82f6', 'Rejected': '#ef4444' };
                
                const spBgColors = spLabels.map(status => colorMap[status] || 'rgba(100, 116, 139, 0.7)'); 
                const spBorderColors = spLabels.map(status => borderMap[status] || '#64748b');
                
                new Chart(document.getElementById('salesPrStatusChart'), { type: 'polarArea', data: { labels: spLabels.length ? spLabels.map(s => s.replace(/_/g, ' ')) : ['No Data'], datasets: [{ data: spData, backgroundColor: spBgColors, borderColor: spBorderColors, borderWidth: 2 }] }, options: { maintainAspectRatio: false, layout: { padding: 10 }, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15, font: {size: 11} } } }, scales: { r: { ticks: { display: false }, grid: { color: '#e2e8f0' } } } } });
            }

            if(document.getElementById('salesTopCatChart') && typeof salesData !== 'undefined') {
                const scLabels = salesData.top_cats.map(c => c.cat_name); 
                const scData = salesData.top_cats.map(c => c.total_qty);
                new Chart(document.getElementById('salesTopCatChart'), { type: 'pie', data: { labels: scLabels, datasets: [{ label: 'Quantity Requested', data: scData, backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#0ea5e9', '#f43f5e'], hoverOffset: 6, borderWidth: 2, borderColor: '#ffffff' }] }, options: { maintainAspectRatio: false, layout: { padding: 10 }, plugins: { legend: { position: 'right', labels: { usePointStyle: true, padding: 15, font: {size: 11} } }, tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': ' + ctx.parsed + ' items'; } } } } } });
            }

            if(document.getElementById('salesTopClientsChart') && typeof salesData !== 'undefined') {
                const tcLabels = salesData.top_clients.map(c => c.client_name); 
                const tcData = salesData.top_clients.map(c => c.total_tx);
                new Chart(document.getElementById('salesTopClientsChart'), { type: 'radar', data: { labels: tcLabels.length ? tcLabels : ['No Record'], datasets: [{ label: 'Transactions', data: tcData, backgroundColor: 'rgba(59, 130, 246, 0.3)', borderColor: '#3b82f6', pointBackgroundColor: '#3b82f6', borderWidth: 2 }] }, options: { maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return ctx.raw + ' Transactions'; } } } }, scales: { r: { ticks: { display: false, stepSize: 1 }, grid: { color: '#e2e8f0' }, pointLabels: { font: { size: 10, family: 'Inter' }, color: '#64748b' } } } } });
            }
        });
    </script>
</body>
</html>
