<?php require 'dashboard_logic.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Overview & Analytics - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/dashboard.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            --bg-body: #f8fafc; --card-bg: #ffffff; --text-main: #0f172a; --text-muted: #64748b;
            --border-light: #e2e8f0; --primary: #2563eb; --primary-glow: rgba(37, 99, 235, 0.2);
        }
        body, .main-content { background-color: var(--bg-body) !important; font-family: 'Inter', 'Segoe UI', Roboto, sans-serif; color: var(--text-main); }

        .btn-filter-trigger {
            background: #ffffff; border: 1px solid #cbd5e1; color: #334155; font-weight: 600; font-size: 0.85rem; padding: 0.45rem 1rem; border-radius: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); height: 38px;
        }
        .btn-filter-trigger:hover, .btn-filter-trigger[aria-expanded="true"] { border-color: var(--primary); color: var(--primary); box-shadow: 0 4px 12px var(--primary-glow); }

        .filter-dropdown-menu { width: auto; min-width: 600px; border-radius: 12px; padding: 0; margin-top: 8px !important; box-shadow: 0 20px 40px -10px rgba(0,0,0,0.15), 0 0 0 1px rgba(0,0,0,0.05); border: none; background: #ffffff; transform-origin: top right; }
        .filter-dropdown-menu.show { animation: smoothPopover 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        @keyframes smoothPopover { 0% { opacity: 0; transform: translateY(-8px) scale(0.98); } 100% { opacity: 1; transform: translateY(0) scale(1); } }
        
        .quick-filter-title { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; margin-bottom: 12px; }
        .quick-filter-btn { display: block; width: 100%; text-align: left; padding: 6px 10px; border-radius: 6px; margin-bottom: 4px; cursor: pointer; color: #475569; font-weight: 500; font-size: 0.8rem; transition: all 0.2s ease; background: transparent; border: none; }
        .quick-filter-btn:hover { background: #f1f5f9; color: #0f172a; }
        .quick-filter-btn.active { background: #eff6ff; color: var(--primary); font-weight: 600; }

        .flatpickr-months { display: none !important; }
        .custom-cal-header { width: 100%; max-width: 320px; margin: 0 auto; user-select: none; }
        .custom-cal-nav { height: 30px; width: 30px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: 0.2s; background: #f8fafc; border: 1px solid #cbd5e1; cursor: pointer; color: #475569; }
        .custom-cal-nav:hover { background: #e2e8f0; border-color: #94a3b8; color: #0f172a; }
        .custom-cal-select {
            appearance: none; -webkit-appearance: none; -moz-appearance: none; background-color: #f8fafc;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23475569' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 6px center; background-size: 12px;
            border: 1px solid #cbd5e1; color: #1e293b; font-weight: 600; padding: 2px 24px 2px 10px; border-radius: 6px; cursor: pointer; transition: all 0.2s ease; outline: none; font-size: 0.85rem; height: 30px;
        }
        .custom-cal-select:hover, .custom-cal-select:focus { background-color: #eff6ff; border-color: #93c5fd; color: var(--primary); }
        
        .calendar-wrapper { width: 320px; margin: 0 auto; display: flex; justify-content: center; }
        .flatpickr-calendar { box-shadow: none !important; border: none !important; width: 100% !important; padding: 0 !important; background: transparent; }
        .flatpickr-weekdays { height: 20px; margin-bottom: 5px; }
        .flatpickr-weekday { color: #94a3b8; font-weight: 600; font-size: 0.65rem; text-transform: uppercase; }
        .flatpickr-days { border: none !important; width: 100% !important; }
        .dayContainer { width: 100% !important; min-width: 100% !important; max-width: 100% !important; }
        .flatpickr-day { border-radius: 6px !important; font-weight: 500; color: #334155; transition: 0.2s; border: none !important; height: 32px; line-height: 32px; margin: 1px 0; font-size: 0.85rem; }
        .flatpickr-day:hover { background: #f1f5f9; color: #0f172a; }
        .flatpickr-day.inRange { background: #eff6ff !important; box-shadow: -5px 0 0 #eff6ff, 5px 0 0 #eff6ff !important; border-radius: 0 !important; }
        .flatpickr-day.selected, .flatpickr-day.startRange, .flatpickr-day.endRange, .flatpickr-day.selected:focus, .flatpickr-day.startRange:focus, .flatpickr-day.endRange:focus { background: var(--primary) !important; color: #fff !important; font-weight: 600; box-shadow: 0 2px 8px var(--primary-glow) !important; border-radius: 6px !important; z-index: 2; }
        
        .custom-range-display { font-size: 0.8rem; font-weight: 500; color: #64748b; }
        .custom-range-display strong { color: #0f172a; font-weight: 700; }
        
        .kpi-corp-card { background: var(--card-bg); border-radius: 8px; border: 1px solid var(--border-light); box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03); padding: 1rem 1.15rem; position: relative; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; transition: all 0.2s ease; height: 100%; }
        .kpi-corp-card:hover { transform: translateY(-2px); box-shadow: 0 6px 12px -4px rgba(0, 0, 0, 0.05); border-color: #cbd5e1; }
        .accent-blue { border-top: 3px solid #3b82f6; } .accent-slate { border-top: 3px solid #64748b; } .accent-amber { border-top: 3px solid #f59e0b; } .accent-rose { border-top: 3px solid #f43f5e; } .accent-emerald { border-top: 3px solid #10b981; } .accent-purple { border-top: 3px solid #8b5cf6; }
        .kpi-corp-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.25rem; }
        .kpi-corp-title { font-size: 0.7rem; font-weight: 600; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; margin: 0; }
        .kpi-corp-value { font-size: 1.4rem; font-weight: 700; color: var(--text-main); line-height: 1; margin: 0; }
        .kpi-corp-icon { width: 34px; height: 34px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 1rem; }
        .kpi-corp-badge { font-size: 0.65rem; font-weight: 500; color: #475569; display: flex; align-items: center; gap: 4px; margin-top: 6px;}
        
        .corp-widget { background: #ffffff; border-radius: 8px; padding: 1rem 1.15rem; border: 1px solid var(--border-light); box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03); display: flex; flex-direction: column; transition: box-shadow 0.2s ease; }
        .corp-widget:hover { box-shadow: 0 6px 12px -4px rgba(0, 0, 0, 0.05); }
        .corp-widget-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.85rem; }
        .corp-widget-title { font-size: 0.8rem; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px; margin: 0; display: flex; align-items: center; gap: 6px; }
        .chart-box { position: relative; flex-grow: 1; width: 100%; min-height: 200px; max-height: 240px; }
        
        .table-corp th { font-size: 0.65rem; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; color: var(--text-muted); background: #f8fafc; border-bottom: 1px solid var(--border-light); padding: 8px 12px; }
        .table-corp td { padding: 10px 12px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; font-size: 0.8rem; }
        
        .dss-insights { max-height: 240px; overflow-y: auto; padding-right: 6px; }
        .dss-insights::-webkit-scrollbar { width: 5px; } .dss-insights::-webkit-scrollbar-track { background: transparent; } .dss-insights::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .insight-card { transition: all 0.2s ease; } .insight-card:hover { transform: translateX(4px); background-color: rgba(0,0,0,0.02) !important; border-color: inherit !important; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div class="main-content fade-in">
        
        <header class="mb-3 pb-2 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="flex-grow-1">
                <h5 class="fw-bold mb-1" style="color: #0f172a; letter-spacing: -0.5px;">Dashboard & Analytics</h5>
                <p class="text-muted mb-0" style="font-size: 0.8rem;">
                    Welcome, <span class="fw-semibold text-dark"><?php echo htmlspecialchars($_SESSION['fullname']); ?></span>.
                </p>
            </div>
            
            <div class="d-flex flex-wrap align-items-center gap-3">
                <div class="dropdown position-relative">
                    <button class="btn-filter-trigger dropdown-toggle" type="button" id="filterDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                        <i class="far fa-calendar-alt text-secondary"></i> 
                        <span id="displayFilterText"><?php echo $active_filter_text; ?></span>
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
                                    <button type="button" id="calPrev" class="custom-cal-nav"><i class="fas fa-chevron-left"></i></button>
                                    <div class="d-flex gap-2">
                                        <select id="calMonth" class="custom-cal-select">
                                            <option value="0">January</option> <option value="1">February</option> <option value="2">March</option>
                                            <option value="3">April</option> <option value="4">May</option> <option value="5">June</option>
                                            <option value="6">July</option> <option value="7">August</option> <option value="8">September</option>
                                            <option value="9">October</option> <option value="10">November</option> <option value="11">December</option>
                                        </select>
                                        <select id="calYear" class="custom-cal-select"></select>
                                    </div>
                                    <button type="button" id="calNext" class="custom-cal-nav"><i class="fas fa-chevron-right"></i></button>
                                </div>
                                <div class="calendar-wrapper"><input type="text" id="inlineCalendarContainer" class="d-none"></div>
                                <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                                    <div class="custom-range-display" id="customRangeDisplay">
                                        <?php echo ($period == 'custom' && !empty($_GET['start']) && !empty($_GET['end'])) ? "<strong>".date('M d, Y', strtotime($_GET['start']))."</strong> &mdash; <strong>".date('M d, Y', strtotime($_GET['end']))."</strong>" : "<span class='text-muted fw-normal fst-italic'>Select dates...</span>"; ?>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-sm btn-light text-secondary fw-bold px-3 border-0" onclick="closeDropdown()">Cancel</button>
                                        <button type="button" class="btn btn-sm btn-primary fw-bold px-3 shadow-sm" id="applyFilterBtn" style="border-radius: 6px;">Apply</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <?php if ($_SESSION['role'] === 'Admin'): ?>
            <div class="row g-3 mb-3">
                <div class="col-xl-3 col-md-6"><a href="admin_users.php" class="text-decoration-none"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Active Users</p><h3 class="kpi-corp-value mt-1"><?php echo $admin_stats['total_users']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-users"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-primary" style="font-size: 5px;"></i> System credentials</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="documents.php" class="text-decoration-none"><div class="kpi-corp-card accent-purple"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Managed Files</p><h3 class="kpi-corp-value mt-1"><?php echo $admin_stats['total_files']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10" style="color: #8b5cf6;"><i class="fas fa-folder-open"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle" style="color: #8b5cf6; font-size: 5px;"></i> Uploaded records</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="admin_requests.php" class="text-decoration-none"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending Requests</p><h3 class="kpi-corp-value mt-1"><?php echo $admin_stats['pending_requests']; ?></h3></div><div class="kpi-corp-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-shield-alt"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-danger" style="font-size: 5px;"></i> Needs your approval</div></div></a></div>
                
                <?php 
                    $pct = $admin_insights_data['storage_pct']; 
                    $p_color = ($pct > 85) ? 'bg-danger' : (($pct > 60) ? 'bg-warning' : 'bg-success');
                    $t_color = ($pct > 85) ? 'text-danger' : (($pct > 60) ? 'text-warning' : 'text-success');
                    $accent = ($pct > 85) ? 'accent-rose' : (($pct > 60) ? 'accent-amber' : 'accent-emerald');
                ?>
                <div class="col-xl-3 col-md-6">
                    <div class="kpi-corp-card <?php echo $accent; ?>">
                        <div class="kpi-corp-header">
                            <div>
                                <p class="kpi-corp-title">Storage Health</p>
                                <h3 class="kpi-corp-value mt-1"><?php echo $admin_insights_data['storage_formatted']; ?></h3>
                            </div>
                            <div class="kpi-corp-icon <?php echo $p_color; ?> bg-opacity-10 <?php echo $t_color; ?>">
                                <i class="fas fa-server"></i>
                            </div>
                        </div>
                        <div class="mt-auto pt-2">
                            <div class="d-flex justify-content-between align-items-center mb-1" style="font-size: 0.65rem; font-weight: 600;">
                                <span class="<?php echo $t_color; ?>"><?php echo $pct; ?>% Used</span>
                                <span class="text-muted">50 GB Max</span>
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
                $p_req = $admin_insights_data['pending_req_all'];
                $admin_insights[] = [
                    'status' => ($p_req > 0) ? 'danger' : 'success', 'icon' => ($p_req > 0) ? 'fa-shield-alt' : 'fa-check-circle',
                    'title' => ($p_req > 0) ? 'Action Required: Security Requests' : 'Support Queue Cleared',
                    'desc' => ($p_req > 0) ? "There are currently <strong>{$p_req}</strong> overall pending user request(s) awaiting your administrative approval." : "No pending security requests from users across all records."
                ];
                $t_today = $admin_insights_data['today_traffic'];
                $t_days = $admin_insights_data['total_days'];
                $t_logs = $admin_insights_data['total_logs'];
                $avg_daily = ($t_days > 0) ? ($t_logs / $t_days) : 0;
                $spike = ($t_today > ($avg_daily * 1.5));
                $admin_insights[] = [
                    'status' => $spike ? 'warning' : 'info', 'icon' => $spike ? 'fa-exclamation-triangle' : 'fa-server',
                    'title' => $spike ? 'System Traffic Spike Detected' : 'Stable System Usage',
                    'desc' => $spike ? "Today's activity reached <strong>{$t_today} actions</strong>, notably higher than the historical daily average of " . round($avg_daily) . ". Monitor for unusual events." : "Overall system interaction remains stable and within normal historical thresholds."
                ];
                $top_user = $admin_insights_data['top_user'];
                if ($top_user) {
                    $admin_insights[] = [
                        'status' => 'primary', 'icon' => 'fa-user-check',
                        'title' => 'Top System Contributor',
                        'desc' => "<strong>" . htmlspecialchars($top_user['full_name']) . "</strong> is the all-time most active user with <strong>" . number_format($top_user['c']) . "</strong> total interactions."
                    ];
                }
                $t_files = $admin_insights_data['total_files_all'];
                $admin_insights[] = [
                    'status' => 'success', 'icon' => 'fa-database',
                    'title' => 'Total Repository Volume',
                    'desc' => "The system currently safeguards a total of <strong>" . number_format($t_files) . "</strong> documents (including archives) across all departments."
                ];
            ?>
            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-8">
                    <div class="corp-widget" style="height: 100%;">
                        <div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-server text-primary"></i> System Traffic & Activity Trend</h6></div>
                        <div class="chart-box"><canvas id="adminTrafficChart"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="corp-widget" style="height: 100%;">
                        <div class="corp-widget-header mb-3"><h6 class="corp-widget-title text-dark"><i class="fas fa-shield-alt text-warning"></i>Security & System Insights</h6></div>
                        <div class="dss-insights">
                            <?php foreach($admin_insights as $i): ?>
                                <div class="insight-card p-2 mb-2 rounded border-start border-4 border-<?php echo $i['status']; ?> bg-<?php echo $i['status']; ?> bg-opacity-10">
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="text-<?php echo $i['status']; ?> mt-1" style="width: 20px; text-align: center;"><i class="fas <?php echo $i['icon']; ?>"></i></div>
                                        <div><span class="d-block fw-bold text-dark" style="font-size: 0.75rem;"><?php echo $i['title']; ?></span><span class="text-muted" style="font-size: 0.7rem; line-height: 1.35; display: block; margin-top: 2px;"><?php echo $i['desc']; ?></span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-pie text-emerald"></i> User Role Distribution</h6></div><div class="chart-box"><canvas id="adminRolesChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-users text-primary"></i> Most Active Users</h6></div><div class="chart-box"><canvas id="adminActiveUsersChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-headset text-rose"></i> Support Requests Workload</h6></div><div class="chart-box"><canvas id="adminRequestsChart"></canvas></div></div></div>
            </div>

        <?php elseif (in_array($_SESSION['role'], ['GM', 'President'])): ?>
            <div class="row g-3 mb-3">
                <div class="col-xl-3 col-md-6"><a href="documents.php" class="text-decoration-none"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Active Records</p><h3 class="kpi-corp-value mt-1"><?php echo $exec_stats['active_docs']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-folder-open"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-primary" style="font-size: 5px;"></i> Current working files</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="documents.php?view_filter=All" class="text-decoration-none"><div class="kpi-corp-card accent-slate"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Archived Docs</p><h3 class="kpi-corp-value mt-1"><?php echo $exec_stats['archived_docs']; ?></h3></div><div class="kpi-corp-icon bg-secondary bg-opacity-10 text-secondary"><i class="fas fa-archive"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-secondary" style="font-size: 5px;"></i> Safely stored records</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="pr_list.php?filter=Pending" class="text-decoration-none"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending PRs</p><h3 class="kpi-corp-value mt-1"><?php echo $exec_stats['pending_pr']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-file-signature"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-warning" style="font-size: 5px;"></i> Awaiting your approval</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Pending" class="text-decoration-none"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending POs</p><h3 class="kpi-corp-value mt-1"><?php echo $exec_stats['pending_po']; ?></h3></div><div class="kpi-corp-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-stamp"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-danger" style="font-size: 5px;"></i> Action required</div></div></a></div>
            </div>

            <?php 
                $insights = [];
                $total_pending = $exec_stats['pending_pr'] + $exec_stats['pending_po'];
                $insights[] = [
                    'status' => ($total_pending > 0) ? 'danger' : 'success', 'icon' => ($total_pending > 0) ? 'fa-signature' : 'fa-check-circle',
                    'title' => ($total_pending > 0) ? 'Action Required: Pending Approvals' : 'Approval Queue Clear',
                    'desc' => ($total_pending > 0) ? "You have <strong>{$exec_stats['pending_pr']}</strong> PR(s) and <strong>{$exec_stats['pending_po']}</strong> PO(s) awaiting your executive sign-off." : "Your approval queue is currently empty. Excellent turnaround!"
                ];
                $uncoll_amt = $gm_charts['uncollected']['total_uncollected'] ?? 0;
                $uncoll_cnt = $gm_charts['uncollected']['count_uncollected'] ?? 0;
                $insights[] = [
                    'status' => ($uncoll_amt > 0) ? 'warning' : 'success', 'icon' => ($uncoll_amt > 0) ? 'fa-file-invoice-dollar' : 'fa-check-double',
                    'title' => ($uncoll_amt > 0) ? 'Pending Collections Alert' : 'Collections Up-to-date',
                    'desc' => ($uncoll_amt > 0) ? "<strong>₱" . number_format($uncoll_amt, 2) . "</strong> across <strong>{$uncoll_cnt}</strong> delivered PO(s) are awaiting full collection. Advise Finance to follow up." : "All delivered purchase orders within this period have been fully collected."
                ];
                $aging_po = $gm_charts['aging_po'] ?? null;
                $hrs_stag = isset($aging_po['hours_stagnant']) ? (int)$aging_po['hours_stagnant'] : 0;
                $insights[] = [
                    'status' => ($hrs_stag >= 48) ? 'danger' : 'info', 'icon' => ($hrs_stag >= 48) ? 'fa-hourglass-half' : 'fa-clock',
                    'title' => ($hrs_stag >= 48) ? 'Stagnant Workflow Alert' : 'Healthy Workflow Pace',
                    'desc' => ($hrs_stag >= 48) ? "PO <strong>" . htmlspecialchars($aging_po['po_number']) . "</strong> has been stuck at <strong>{$aging_po['status']}</strong> (Location: {$aging_po['current_location']}) for <strong>{$hrs_stag} hours</strong>. Please review to prevent SLA breaches." : "No active purchase orders have been stagnant for more than 48 hours."
                ];
                $highest_stage = 'None'; $highest_hours = 0; $total_avg_hours = 0; $stage_count = 0;
                $stage_names = ['GM-Approved' => 'GM Approval', 'Finance-Approved' => 'Finance Validation', 'President-Approved' => 'President Approval', 'Funded' => 'Funding', 'Delivered' => 'Delivery'];
                if(!empty($gm_charts['turnaround'])) {
                    foreach($gm_charts['turnaround'] as $t) {
                        $total_avg_hours += $t['avg_hours']; $stage_count++;
                        if($t['avg_hours'] > $highest_hours) {
                            $highest_hours = $t['avg_hours']; $highest_stage = $stage_names[$t['stage']] ?? str_replace('-Approved', '', $t['stage']);
                        }
                    }
                }
                $overall_avg = ($stage_count > 0) ? ($total_avg_hours / $stage_count) : 0;
                $is_bottleneck = ($highest_hours > 12 && $highest_hours > ($overall_avg * 1.5));
                $insights[] = [
                    'status' => $is_bottleneck ? 'danger' : 'success', 'icon' => $is_bottleneck ? 'fa-project-diagram' : 'fa-tachometer-alt',
                    'title' => $is_bottleneck ? 'Workflow Bottleneck Detected' : 'Optimal Workflow Processing',
                    'desc' => $is_bottleneck ? "The <strong>{$highest_stage}</strong> phase averages <strong>{$highest_hours} hrs</strong>, significantly slower than the overall standard (" . round($overall_avg,1) . " hrs). Investigate this stage." : "Document processing stages are balanced with an overall average of <strong>" . round($overall_avg,1) . " hrs</strong>."
                ];
                $tot_q = $gm_charts['quote_conversion']['total_quotes'] ?? 0;
                $conv_q = $gm_charts['quote_conversion']['converted_quotes'] ?? 0;
                $conv_rate = ($tot_q > 0) ? round(($conv_q / $tot_q) * 100) : 0;
                $insights[] = [
                    'status' => ($conv_rate >= 50) ? 'primary' : (($tot_q > 0) ? 'warning' : 'secondary'), 'icon' => 'fa-handshake',
                    'title' => 'Sales Conversion Rate',
                    'desc' => ($tot_q > 0) ? "<strong>{$conv_rate}%</strong> of sent quotations ({$conv_q} out of {$tot_q}) converted to Client POs. " . ($conv_rate < 50 ? "Consider reviewing pricing or sending follow-ups." : "Excellent conversion momentum.") : "No client quotations were drafted within the selected period."
                ];
                $top_client = $gm_charts['top_client'] ?? null;
                $insights[] = [
                    'status' => 'primary', 'icon' => 'fa-building', 'title' => 'Top Client Activity',
                    'desc' => (!empty($top_client)) ? "<strong>" . htmlspecialchars($top_client['client_name']) . "</strong> generated the highest volume with <strong>{$top_client['tx_count']}</strong> transaction(s). Ensure high SLA standards for this key account." : "No purchase order transactions recorded for client volume analysis."
                ];
                $disp_count = $gm_charts['lifecycle']['ready_disp'] ?? 0;
                $insights[] = [
                    'status' => ($disp_count > 0) ? 'danger' : 'success', 'icon' => ($disp_count > 0) ? 'fa-archive' : 'fa-shield-check',
                    'title' => ($disp_count > 0) ? 'Retention Compliance Alert' : 'Fully Compliant Records',
                    'desc' => ($disp_count > 0) ? "<strong>{$disp_count}</strong> historical records have reached maturity and are ready for disposition. Immediate action is advised." : "All active and archived records are well within their legal retention limits."
                ];
            ?>
            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-8"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-line text-primary"></i>Daily Transaction Volume</h6></div><div class="chart-box"><canvas id="gmActivityTrendChart"></canvas></div></div></div>
                <div class="col-lg-4">
                    <div class="corp-widget" style="height: 100%;">
                        <div class="corp-widget-header mb-3"><h6 class="corp-widget-title text-dark"><i class="fas fa-lightbulb text-warning"></i>Insights & Recommendations</h6></div>
                        <div class="dss-insights">
                            <?php foreach($insights as $i): ?>
                                <div class="insight-card p-2 mb-2 rounded border-start border-4 border-<?php echo $i['status']; ?> bg-<?php echo $i['status']; ?> bg-opacity-10">
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="text-<?php echo $i['status']; ?> mt-1" style="width: 20px; text-align: center;"><i class="fas <?php echo $i['icon']; ?>"></i></div>
                                        <div><span class="d-block fw-bold text-dark" style="font-size: 0.75rem;"><?php echo $i['title']; ?></span><span class="text-muted" style="font-size: 0.7rem; line-height: 1.35; display: block; margin-top: 2px;"><?php echo $i['desc']; ?></span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-folder-open text-info"></i> Record Volume Distribution</h6></div><div class="chart-box"><canvas id="gmVolumeChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-project-diagram text-rose"></i> Processing Bottleneck (Avg Hrs)</h6></div><div class="chart-box"><canvas id="gmTurnaroundChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-pie text-emerald"></i> Document Lifecycle</h6></div><div class="chart-box"><canvas id="gmLifecycleChart"></canvas></div></div></div>
            </div>

        <?php elseif ($_SESSION['role'] === 'Finance'): ?>
            <div class="row g-3 mb-3">
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=GM-Approved" class="text-decoration-none"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending Approvals</p><h3 class="kpi-corp-value mt-1"><?php echo $finance_stats['pending_po']; ?></h3></div><div class="kpi-corp-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-file-signature"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-danger" style="font-size: 5px;"></i> Needs your validation</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Funded" class="text-decoration-none"><div class="kpi-corp-card accent-emerald"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Funded Orders</p><h3 class="kpi-corp-value mt-1"><?php echo $finance_stats['funded_po']; ?></h3></div><div class="kpi-corp-icon bg-success bg-opacity-10 text-success"><i class="fas fa-money-bill-wave"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-success" style="font-size: 5px;"></i> Approved budget</div></div></a></div>
                <div class="col-xl-3 col-md-6"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Uncollected Sales</p><h3 class="kpi-corp-value mt-1" style="font-size:1.1rem;">₱<?php echo number_format($finance_stats['uncollected_amount'], 2); ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-cash-register"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-warning" style="font-size: 5px;"></i> Pending receivables</div></div></div>
                <div class="col-xl-3 col-md-6"><div class="kpi-corp-card accent-purple"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Total Revenue</p><h3 class="kpi-corp-value mt-1" style="font-size:1.1rem;">₱<?php echo number_format($finance_stats['total_revenue'], 2); ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10" style="color: #8b5cf6;"><i class="fas fa-chart-line"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle" style="color: #8b5cf6; font-size: 5px;"></i> Generated sales</div></div></div>
            </div>

            <?php 
                $fin_insights = [];
                $future_est = $finance_charts['future_sum'] ?? 0;
                $fin_insights[] = [
                    'status' => ($future_est > 0) ? 'primary' : 'secondary', 'icon' => 'fa-chart-line',
                    'title' => 'Revenue Prediction Forecast',
                    'desc' => ($future_est > 0) ? "Predictive analytics forecast a potential revenue of <strong>₱" . number_format($future_est, 2) . "</strong> over the next 3 months based on established linear trends." : "Insufficient historical data to generate an accurate 3-month forecast."
                ];
                $cf_in = end($finance_charts['cf_in']); $cf_out = end($finance_charts['cf_out']); $is_positive = ($cf_in >= $cf_out);
                $fin_insights[] = [
                    'status' => $is_positive ? 'success' : 'danger', 'icon' => $is_positive ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down',
                    'title' => $is_positive ? 'Positive Cash Flow Trend' : 'Negative Cash Flow Alert',
                    'desc' => "For the latest recorded month, total cash inflows (₱".number_format((float)$cf_in, 2).") " . ($is_positive ? "exceeded" : "fell short of") . " total outflows (₱".number_format((float)$cf_out, 2).")."
                ];
                $uncol_total = array_sum($finance_charts['tc_uncol'] ?? [0]);
                $fin_insights[] = [
                    'status' => ($uncol_total > 0) ? 'warning' : 'success', 'icon' => ($uncol_total > 0) ? 'fa-exclamation-triangle' : 'fa-check-double',
                    'title' => ($uncol_total > 0) ? 'Outstanding Receivables Detected' : 'Healthy Receivables',
                    'desc' => ($uncol_total > 0) ? "There is <strong>₱" . number_format($uncol_total, 2) . "</strong> in unpaid balances from your top clients. Immediate collection efforts are strongly advised." : "No critical overdue receivables detected from your major clients."
                ];
                $p_po = $finance_stats['pending_po'];
                $fin_insights[] = [
                    'status' => ($p_po > 0) ? 'warning' : 'success', 'icon' => 'fa-stamp',
                    'title' => ($p_po > 0) ? 'Action Required' : 'Queue Cleared',
                    'desc' => ($p_po > 0) ? "You currently have <strong>{$p_po}</strong> purchase orders flagged as GM-Approved waiting for your financial validation." : "No pending purchase orders waiting for finance approval."
                ];
            ?>

            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-8"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-area text-primary"></i> Monthly Sales & Revenue Forecast</h6></div><div class="chart-box"><canvas id="finRevenueChart"></canvas></div></div></div>
                <div class="col-lg-4">
                    <div class="corp-widget" style="height: 100%;">
                        <div class="corp-widget-header mb-3"><h6 class="corp-widget-title text-dark"><i class="fas fa-lightbulb text-warning"></i>Finance & Sales Insights</h6></div>
                        <div class="dss-insights">
                            <?php foreach($fin_insights as $i): ?>
                                <div class="insight-card p-2 mb-2 rounded border-start border-4 border-<?php echo $i['status']; ?> bg-<?php echo $i['status']; ?> bg-opacity-10">
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="text-<?php echo $i['status']; ?> mt-1" style="width: 20px; text-align: center;"><i class="fas <?php echo $i['icon']; ?>"></i></div>
                                        <div><span class="d-block fw-bold text-dark" style="font-size: 0.75rem;"><?php echo $i['title']; ?></span><span class="text-muted" style="font-size: 0.7rem; line-height: 1.35; display: block; margin-top: 2px;"><?php echo $i['desc']; ?></span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-exchange-alt text-success"></i> Cash Flow Trend</h6></div><div class="chart-box"><canvas id="finCashflowChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-level-up-alt text-primary"></i> MoM Revenue Growth</h6></div><div class="chart-box"><canvas id="finMomChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-building text-info"></i> Client Balances & Revenue</h6></div><div class="chart-box"><canvas id="finTopClientsRadarChart"></canvas></div></div></div>
            </div>
            
        <?php elseif ($_SESSION['role'] === 'Procurement'): ?>
            <div class="row g-3 mb-3">
                <div class="col-xl-3 col-md-6"><a href="po_list.php" class="text-decoration-none"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Total Orders</p><h3 class="kpi-corp-value mt-1"><?php echo $proc_stats['total']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-shopping-cart"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-primary" style="font-size: 5px;"></i> All generated POs</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=In_Progress" class="text-decoration-none"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending Approvals</p><h3 class="kpi-corp-value mt-1"><?php echo $proc_stats['pending']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-hourglass-half"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-warning" style="font-size: 5px;"></i> Waiting for sign-off</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Funded" class="text-decoration-none"><div class="kpi-corp-card accent-emerald"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Funded Orders</p><h3 class="kpi-corp-value mt-1"><?php echo $proc_stats['funded']; ?></h3></div><div class="kpi-corp-icon bg-success bg-opacity-10 text-success"><i class="fas fa-money-bill-wave"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-success" style="font-size: 5px;"></i> Ready for purchasing</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="po_list.php?filter=Completed" class="text-decoration-none"><div class="kpi-corp-card accent-purple"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Delivered / Collected</p><h3 class="kpi-corp-value mt-1"><?php echo $proc_stats['delivered']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10" style="color: #8b5cf6;"><i class="fas fa-truck-loading"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle" style="color: #8b5cf6; font-size: 5px;"></i> Successfully completed</div></div></a></div>
            </div>
            
            <?php
                $proc_insights = [];
                $tot_spent = $proc_charts['total_spent'] ?? 0;
                $proc_insights[] = [
                    'status' => 'primary', 'icon' => 'fa-chart-pie',
                    'title' => 'Total Procurement Spend',
                    'desc' => "Total value of active and completed purchase orders is <strong>₱" . number_format($tot_spent, 2) . "</strong> for the selected period."
                ];
                $stagnant = $proc_charts['stagnant'] ?? null;
                if ($stagnant && $stagnant['hours_wait'] > 24) {
                    $proc_insights[] = [
                        'status' => 'danger', 'icon' => 'fa-exclamation-circle',
                        'title' => 'Approval Bottleneck',
                        'desc' => "PO <strong>" . htmlspecialchars($stagnant['po_number']) . "</strong> has been stuck at <strong>{$stagnant['status']}</strong> for over <strong>{$stagnant['hours_wait']} hours</strong>. Follow up with " . htmlspecialchars($stagnant['current_location']) . "."
                    ];
                } else {
                    $proc_insights[] = [
                        'status' => 'success', 'icon' => 'fa-check-circle',
                        'title' => 'Smooth Processing',
                        'desc' => "No major approval bottlenecks detected. Purchase orders are moving through the workflow efficiently."
                    ];
                }
                $top_cat = !empty($proc_charts['top_cats']) ? $proc_charts['top_cats'][0] : null;
                if ($top_cat) {
                    $proc_insights[] = [
                        'status' => 'info', 'icon' => 'fa-boxes',
                        'title' => 'Highest Spending Category',
                        'desc' => "<strong>" . htmlspecialchars($top_cat['cat_name']) . "</strong> leads procurement spending with <strong>₱" . number_format($top_cat['spent'], 2) . "</strong>."
                    ];
                }
                $p_po = $proc_stats['pending'];
                $proc_insights[] = [
                    'status' => ($p_po > 0) ? 'warning' : 'success', 'icon' => 'fa-clipboard-list',
                    'title' => 'Pending Tracker',
                    'desc' => ($p_po > 0) ? "There are <strong>{$p_po}</strong> encoded purchase orders waiting for executive or finance approval." : "All your encoded purchase orders have been fully processed and approved."
                ];
            ?>
            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-8"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-line text-primary"></i> Daily PO Generation Trend</h6></div><div class="chart-box"><canvas id="procTrendChart"></canvas></div></div></div>
                <div class="col-lg-4">
                    <div class="corp-widget" style="height: 100%;">
                        <div class="corp-widget-header mb-3"><h6 class="corp-widget-title text-dark"><i class="fas fa-lightbulb text-warning"></i> Procurement Insights</h6></div>
                        <div class="dss-insights">
                            <?php foreach($proc_insights as $i): ?>
                                <div class="insight-card p-2 mb-2 rounded border-start border-4 border-<?php echo $i['status']; ?> bg-<?php echo $i['status']; ?> bg-opacity-10">
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="text-<?php echo $i['status']; ?> mt-1" style="width: 20px; text-align: center;"><i class="fas <?php echo $i['icon']; ?>"></i></div>
                                        <div><span class="d-block fw-bold text-dark" style="font-size: 0.75rem;"><?php echo $i['title']; ?></span><span class="text-muted" style="font-size: 0.7rem; line-height: 1.35; display: block; margin-top: 2px;"><?php echo $i['desc']; ?></span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-pie text-emerald"></i> PO Status Overview</h6></div><div class="chart-box"><canvas id="procStatusChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-tags text-primary"></i> Top Categories (Spend)</h6></div><div class="chart-box"><canvas id="procCategoryChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-building text-info"></i> Top Brands Purchased</h6></div><div class="chart-box"><canvas id="procBrandChart"></canvas></div></div></div>
            </div>

        <?php elseif ($_SESSION['role'] === 'Sales Staff'): ?>
            <div class="row g-3 mb-3">
                <div class="col-xl-3 col-md-6"><a href="pr_list.php" class="text-decoration-none"><div class="kpi-corp-card accent-blue"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Total PR Generated</p><h3 class="kpi-corp-value mt-1"><?php echo $sales_stats['total']; ?></h3></div><div class="kpi-corp-icon bg-primary bg-opacity-10 text-primary"><i class="fas fa-file-invoice"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-primary" style="font-size: 5px;"></i> All requests created</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="pr_list.php?filter=Pending" class="text-decoration-none"><div class="kpi-corp-card accent-amber"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending PRs</p><h3 class="kpi-corp-value mt-1"><?php echo $sales_stats['pending']; ?></h3></div><div class="kpi-corp-icon bg-warning bg-opacity-10 text-warning"><i class="fas fa-clock"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-warning" style="font-size: 5px;"></i> Waiting for management</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="quotations_list.php?filter=Pending PO" class="text-decoration-none"><div class="kpi-corp-card accent-rose"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Pending Quotes</p><h3 class="kpi-corp-value mt-1"><?php echo $sales_stats['pending_quotations']; ?></h3></div><div class="kpi-corp-icon bg-danger bg-opacity-10 text-danger"><i class="fas fa-file-contract"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-danger" style="font-size: 5px;"></i> Waiting for Client PO</div></div></a></div>
                <div class="col-xl-3 col-md-6"><a href="pr_list.php?filter=Approved" class="text-decoration-none"><div class="kpi-corp-card accent-emerald"><div class="kpi-corp-header"><div><p class="kpi-corp-title">Approved PRs</p><h3 class="kpi-corp-value mt-1"><?php echo $sales_stats['approved']; ?></h3></div><div class="kpi-corp-icon bg-success bg-opacity-10 text-success"><i class="fas fa-check-double"></i></div></div><div class="kpi-corp-badge"><i class="fas fa-circle text-success" style="font-size: 5px;"></i> Ready for processing</div></div></a></div>
            </div>

            <?php
                $sales_insights = [];
                $app_count = $sales_stats['approved'] ?? 0;
                $sales_insights[] = [
                    'status' => 'primary', 'icon' => 'fa-rocket',
                    'title' => 'Approved Pipeline',
                    'desc' => "You have <strong>{$app_count}</strong> approved Purchase Requests successfully handed over to procurement."
                ];
                $pend_q = $sales_stats['pending_quotations'];
                $sales_insights[] = [
                    'status' => ($pend_q > 0) ? 'warning' : 'success', 'icon' => 'fa-envelope-open-text',
                    'title' => 'Quotation Follow-ups',
                    'desc' => ($pend_q > 0) ? "You have <strong>{$pend_q}</strong> pending quotation(s). Please follow up with your clients to secure their Purchase Orders." : "All generated quotations have received client POs."
                ];
                $pend_pr = $sales_stats['pending'];
                $sales_insights[] = [
                    'status' => ($pend_pr > 0) ? 'info' : 'success', 'icon' => 'fa-user-clock',
                    'title' => 'Internal Approvals',
                    'desc' => ($pend_pr > 0) ? "<strong>{$pend_pr}</strong> of your Purchase Requests are currently pending review from General Management." : "No pending PRs awaiting management approval."
                ];
                $last_rej = $sales_charts['latest_rejected'] ?? null;
                if ($last_rej) {
                    $sales_insights[] = [
                        'status' => 'danger', 'icon' => 'fa-times-circle',
                        'title' => 'Recent PR Rejection',
                        'desc' => "PR <strong>" . htmlspecialchars($last_rej['pr_number']) . "</strong> for <strong>" . htmlspecialchars($last_rej['client_name']) . "</strong> was rejected. Please review the details."
                    ];
                }
            ?>

            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-8"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-area text-primary"></i> Daily PR Submission Pipeline</h6></div><div class="chart-box"><canvas id="salesTrendChart"></canvas></div></div></div>
                <div class="col-lg-4">
                    <div class="corp-widget" style="height: 100%;">
                        <div class="corp-widget-header mb-3"><h6 class="corp-widget-title text-dark"><i class="fas fa-lightbulb text-warning"></i> Sales Insights</h6></div>
                        <div class="dss-insights">
                            <?php foreach($sales_insights as $i): ?>
                                <div class="insight-card p-2 mb-2 rounded border-start border-4 border-<?php echo $i['status']; ?> bg-<?php echo $i['status']; ?> bg-opacity-10">
                                    <div class="d-flex align-items-start gap-2">
                                        <div class="text-<?php echo $i['status']; ?> mt-1" style="width: 20px; text-align: center;"><i class="fas <?php echo $i['icon']; ?>"></i></div>
                                        <div><span class="d-block fw-bold text-dark" style="font-size: 0.75rem;"><?php echo $i['title']; ?></span><span class="text-muted" style="font-size: 0.7rem; line-height: 1.35; display: block; margin-top: 2px;"><?php echo $i['desc']; ?></span></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3 align-items-stretch">
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-chart-pie text-emerald"></i> PR Status Distribution</h6></div><div class="chart-box"><canvas id="salesPrStatusChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-boxes text-info"></i> Top Requested Categories (Qty)</h6></div><div class="chart-box"><canvas id="salesTopCatChart"></canvas></div></div></div>
                <div class="col-lg-4"><div class="corp-widget" style="height: 100%;"><div class="corp-widget-header"><h6 class="corp-widget-title"><i class="fas fa-building text-primary"></i> Top Clients (Transaction Count)</h6></div><div class="chart-box"><canvas id="salesTopClientsChart"></canvas></div></div></div>
            </div>

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

    <?php if (isset($admin_charts) && !empty($admin_charts)): ?>
        <script>const adminData = <?php echo json_encode($admin_charts); ?>;</script>
    <?php endif; ?>
    <?php if (isset($gm_charts) && !empty($gm_charts)): ?>
        <script>const gmData = <?php echo json_encode($gm_charts); ?>;</script>
    <?php endif; ?>
    <?php if (isset($finance_charts) && !empty($finance_charts)): ?>
        <script>const finData = <?php echo json_encode($finance_charts); ?>;</script>
    <?php endif; ?>
    <?php if (isset($proc_charts) && !empty($proc_charts)): ?>
        <script>const procData = <?php echo json_encode($proc_charts); ?>;</script>
    <?php endif; ?>
    <?php if (isset($sales_charts) && !empty($sales_charts)): ?>
        <script>const salesData = <?php echo json_encode($sales_charts); ?>;</script>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="assets/js/dashboard.js"></script>
    
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
                opt.value = i; opt.text = i;
                calYear.appendChild(opt);
            }

            const fp = flatpickr("#inlineCalendarContainer", {
                mode: "range", inline: true, showMonths: 1, 
                defaultDate: (startDateStr && endDateStr) ? [startDateStr, endDateStr] : null,
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
                    e.preventDefault(); e.stopPropagation(); 
                    selectedPeriod = this.getAttribute('data-val');
                    let currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.set('period', selectedPeriod);
                    currentUrl.searchParams.delete('start'); currentUrl.searchParams.delete('end');
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

            // =====================================
            // ADMIN CHARTS
            // =====================================
            if(document.getElementById('adminTrafficChart') && typeof adminData !== 'undefined') {
                const ctxTr = document.getElementById('adminTrafficChart').getContext('2d');
                const trLabels = adminData.traffic.map(t => { let d = new Date(t.log_date); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); });
                const trData = adminData.traffic.map(t => t.action_count);
                let gradTr = ctxTr.createLinearGradient(0, 0, 0, 300); 
                gradTr.addColorStop(0, 'rgba(59, 130, 246, 0.4)'); 
                gradTr.addColorStop(1, 'rgba(59, 130, 246, 0.0)');
                new Chart(ctxTr, {
                    type: 'line',
                    data: {
                        labels: trLabels.length ? trLabels : ['No Date'],
                        datasets: [{ label: 'System Actions', data: trData, borderColor: '#3b82f6', backgroundColor: gradTr, borderWidth: 3, fill: true, tension: 0.4, pointBackgroundColor: '#ffffff', pointBorderColor: '#3b82f6', pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6 }]
                    },
                    options: { maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: { display: false } }, x: { grid: { display: false }, border: { display: false } } } }
                });
            }

            if(document.getElementById('adminRolesChart') && typeof adminData !== 'undefined') {
                const rLabels = adminData.roles.map(r => r.role);
                const rData = adminData.roles.map(r => r.user_count);
                new Chart(document.getElementById('adminRolesChart'), {
                    type: 'doughnut', data: { labels: rLabels.length ? rLabels : ['No Roles'], datasets: [{ data: rData, backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#8b5cf6', '#ec4899', '#0ea5e9', '#64748b'], hoverOffset: 6 }] },
                    options: { cutout: '65%', maintainAspectRatio: false, layout: { padding: 10 }, plugins: { legend: { position: 'right', labels: { usePointStyle: true, padding: 15, font: {size: 11} } } } }
                });
            }

            if(document.getElementById('adminActiveUsersChart') && typeof adminData !== 'undefined') {
                const ctxUsers = document.getElementById('adminActiveUsersChart').getContext('2d');
                let horizGrad = ctxUsers.createLinearGradient(0, 0, 300, 0); 
                horizGrad.addColorStop(0, 'rgba(16, 185, 129, 0.8)'); 
                horizGrad.addColorStop(1, 'rgba(59, 130, 246, 0.8)');
                
                const uLabels = adminData.active_users.map(u => u.full_name);
                const uData = adminData.active_users.map(u => u.activity_count);
                
                new Chart(ctxUsers, {
                    type: 'bar', 
                    data: { 
                        labels: uLabels.length ? uLabels : ['No Users'], 
                        datasets: [{ label: 'Total Actions', data: uData, backgroundColor: horizGrad, borderRadius: 6, barPercentage: 0.65 }] 
                    },
                    options: { 
                        indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, 
                        scales: { x: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' } }, y: { grid: { display: false }, ticks: {font: {size: 10}} } } 
                    }
                });
            }

            if(document.getElementById('adminRequestsChart') && typeof adminData !== 'undefined') {
                const reqLabels = adminData.requests.map(r => r.status);
                const reqData = adminData.requests.map(r => r.req_count);
                
                const reqColors = reqLabels.map(l => l === 'Pending' ? 'rgba(245, 158, 11, 0.7)' : (l === 'Approved' ? 'rgba(16, 185, 129, 0.7)' : 'rgba(239, 68, 68, 0.7)'));
                const reqBorders = reqLabels.map(l => l === 'Pending' ? '#f59e0b' : (l === 'Approved' ? '#10b981' : '#ef4444'));

                new Chart(document.getElementById('adminRequestsChart'), {
                    type: 'polarArea', 
                    data: { 
                        labels: reqLabels.length ? reqLabels : ['No Requests'], 
                        datasets: [{ 
                            data: reqData, 
                            backgroundColor: reqColors, 
                            borderColor: reqBorders,
                            borderWidth: 2
                        }] 
                    },
                    options: { 
                        maintainAspectRatio: false, 
                        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, font: {size: 10} } } },
                        scales: { r: { ticks: { display: false }, grid: { color: '#e2e8f0' } } }
                    }
                });
            }

            // =====================================
            // EXECUTIVE CHARTS (GM / PRESIDENT)
            // =====================================
            if(document.getElementById('gmActivityTrendChart') && typeof gmData !== 'undefined') {
                const ctxAct = document.getElementById('gmActivityTrendChart').getContext('2d');
                const fLabels = gmData.activity_trend.map(f => { let d = new Date(f.a_date); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); });
                const reqQuoteData = gmData.activity_trend.map(f => f.req_quote_count);
                const poData = gmData.activity_trend.map(f => f.po_count);
                const finFulfillData = gmData.activity_trend.map(f => f.fin_fulfill_count);
                const docData = gmData.activity_trend.map(f => f.doc_count);
                const approvalData = gmData.activity_trend.map(f => f.approval_count);

                let gradPO = ctxAct.createLinearGradient(0, 0, 0, 300); gradPO.addColorStop(0, 'rgba(59, 130, 246, 0.3)'); gradPO.addColorStop(1, 'rgba(59, 130, 246, 0.0)');
                let gradDoc = ctxAct.createLinearGradient(0, 0, 0, 300); gradDoc.addColorStop(0, 'rgba(244, 63, 94, 0.3)'); gradDoc.addColorStop(1, 'rgba(244, 63, 94, 0.0)');

                new Chart(ctxAct, {
                    type: 'line',
                    data: {
                        labels: fLabels.length ? fLabels : ['No Date'],
                        datasets: [
                            { label: 'Purchase Orders', data: poData, borderColor: '#3b82f6', backgroundColor: gradPO, borderWidth: 2, fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 6 },
                            { label: 'Files & Records Uploaded', data: docData, borderColor: '#f43f5e', backgroundColor: gradDoc, borderWidth: 2, fill: true, tension: 0.4, pointRadius: 0, pointHoverRadius: 6 },
                            { label: 'Payments & Fulfillment', data: finFulfillData, borderColor: '#10b981', backgroundColor: 'transparent', borderWidth: 2, fill: false, tension: 0.4, pointRadius: 0, pointHoverRadius: 6 },
                            { label: 'Workflow Approvals', data: approvalData, borderColor: '#8b5cf6', backgroundColor: 'transparent', borderWidth: 2, fill: false, tension: 0.4, pointRadius: 0, pointHoverRadius: 6 },
                            { label: 'PRs & Quotations', data: reqQuoteData, borderColor: '#f59e0b', backgroundColor: 'transparent', borderWidth: 2, borderDash: [5, 5], fill: false, tension: 0.4, pointRadius: 0, pointHoverRadius: 6 }
                        ]
                    },
                    options: { maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { display: true, position: 'top', labels: { usePointStyle: true, boxWidth: 8, font: {size: 11}, padding: 25 } }, tooltip: { callbacks: { title: function(context) { return context[0].label; } } } }, scales: { y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9', drawBorder: false }, border: { display: false }, ticks: { font: {size:11}, padding: 10, stepSize: 1 } }, x: { grid: { display: false }, border: { display: false }, ticks: { font: {size:11}, maxRotation: 45, autoSkip: true, maxTicksLimit: 10, padding: 10 } } } }
                });
            }

            if(document.getElementById('gmLifecycleChart') && typeof gmData !== 'undefined') {
                new Chart(document.getElementById('gmLifecycleChart'), {
                    type: 'pie', data: { labels: ['Active', 'Archived', 'Disposition'], datasets: [{ data: [ gmData.lifecycle.active_docs, gmData.lifecycle.archived_docs, gmData.lifecycle.ready_disp ], backgroundColor: ['#10b981', '#6366f1', '#f43f5e'], hoverBackgroundColor: ['#059669', '#4f46e5', '#e11d48'], borderWidth: 2, borderColor: '#ffffff', hoverOffset: 6 }] },
                    options: { maintainAspectRatio: false, layout: { padding: 10 }, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20, font: {size: 12} } } } }
                });
            }

            if(document.getElementById('gmVolumeChart') && typeof gmData !== 'undefined') {
                const vLabels = gmData.volume.map(v => v.category || 'Uncategorized');
                const vData = gmData.volume.map(v => v.count);
                new Chart(document.getElementById('gmVolumeChart'), {
                    type: 'doughnut', data: { labels: vLabels.length ? vLabels : ['Uncategorized'], datasets: [{ data: vData, backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#0ea5e9', '#64748b'], hoverBackgroundColor: ['#2563eb', '#059669', '#d97706', '#7c3aed', '#db2777', '#0284c7', '#475569'], borderWidth: 3, borderColor: '#ffffff', hoverOffset: 6 }] },
                    options: { cutout: '65%', maintainAspectRatio: false, layout: { padding: 10 }, plugins: { legend: { position: 'right', labels: { usePointStyle: true, padding: 15, font: {size: 11} } } } }
                });
            }

            if(document.getElementById('gmTurnaroundChart') && typeof gmData !== 'undefined') {
                const ctxTurn = document.getElementById('gmTurnaroundChart').getContext('2d');
                let horizGradient = ctxTurn.createLinearGradient(0, 0, 300, 0); horizGradient.addColorStop(0, 'rgba(244, 63, 94, 0.8)'); horizGradient.addColorStop(1, 'rgba(249, 115, 22, 0.8)');
                const stageNameMap = { 'GM-Approved': 'GM Approval', 'Finance-Approved': 'Finance Validation', 'President-Approved': 'Pres Approval', 'Funded': 'Funding', 'Delivered': 'Delivery' };
                const tLabels = gmData.turnaround.map(t => stageNameMap[t.stage] || t.stage);
                const tData = gmData.turnaround.map(t => t.avg_hours);
                new Chart(ctxTurn, {
                    type: 'bar', data: { labels: tLabels.length ? tLabels : ['No Record'], datasets: [{ label: 'Avg Hours Spent', data: tData, backgroundColor: horizGradient, borderRadius: 6, barPercentage: 0.65 }] },
                    options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: { display: false }, title: { display: true, text: 'Average Hours', font: {size: 11, weight: '600'}, color: '#94a3b8' } }, y: { grid: { display: false }, border: { display: false }, ticks: { font: {size: 11, weight: '600'} } } } }
                });
            }

            // =====================================
            // FINANCE CHARTS 
            // =====================================
            if(document.getElementById('finRevenueChart') && typeof finData !== 'undefined') {
                const ctxFinRev = document.getElementById('finRevenueChart').getContext('2d');
                let gradActual = ctxFinRev.createLinearGradient(0, 0, 0, 400); gradActual.addColorStop(0, 'rgba(59, 130, 246, 0.5)'); gradActual.addColorStop(1, 'rgba(59, 130, 246, 0.0)');
                let gradPred = ctxFinRev.createLinearGradient(0, 0, 0, 400); gradPred.addColorStop(0, 'rgba(139, 92, 246, 0.4)'); gradPred.addColorStop(1, 'rgba(139, 92, 246, 0.0)');
                new Chart(ctxFinRev, {
                    type: 'line',
                    data: {
                        labels: finData.revenue_labels,
                        datasets: [
                            { label: 'Actual Revenue', data: finData.revenue_actuals, borderColor: '#3b82f6', backgroundColor: gradActual, borderWidth: 3, fill: true, tension: 0.4, pointBackgroundColor: '#ffffff', pointBorderColor: '#3b82f6', pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6 },
                            { label: 'Predicted Trend', data: finData.revenue_predicteds, borderColor: '#8b5cf6', backgroundColor: gradPred, borderWidth: 3, borderDash: [5, 5], fill: true, tension: 0.4, pointBackgroundColor: '#ffffff', pointBorderColor: '#8b5cf6', pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6 }
                        ]
                    },
                    options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top', labels: { usePointStyle: true, font: {size: 12, family: 'Inter'} } }, tooltip: { backgroundColor: 'rgba(15, 23, 42, 0.95)', titleFont: { size: 13, family: 'Inter' }, bodyFont: { size: 12, family: 'Inter' }, callbacks: { label: function(ctx) { return ctx.dataset.label + ': ₱' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2}); } } } }, scales: { y: { grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: {display: false}, ticks: { callback: function(val) { return '₱' + val.toLocaleString(); } } }, x: { grid: { display: false }, border: {display: false} } } }
                });
            }

            if(document.getElementById('finCashflowChart') && typeof finData !== 'undefined') {
                const ctxCF = document.getElementById('finCashflowChart').getContext('2d');
                new Chart(ctxCF, {
                    type: 'line',
                    data: {
                        labels: finData.cf_labels,
                        datasets: [
                            { label: 'Inflow', data: finData.cf_in, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', fill: true, tension: 0.4, borderWidth: 2, pointRadius: 3 },
                            { label: 'Outflow', data: finData.cf_out, borderColor: '#ef4444', backgroundColor: 'rgba(239, 68, 68, 0.1)', fill: true, tension: 0.4, borderWidth: 2, pointRadius: 3 }
                        ]
                    },
                    options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, font: {size: 10} } }, tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': ₱' + ctx.parsed.y.toLocaleString(undefined, {minimumFractionDigits: 2}); } } } }, scales: { y: { grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: {display: false}, ticks: { font: {size: 10}, callback: function(val) { if(val >= 1000) return '₱' + (val/1000) + 'k'; return '₱' + val; } } }, x: { grid: { display: false }, ticks: { font: {size: 10} } } } }
                });
            }

            if(document.getElementById('finMomChart') && typeof finData !== 'undefined') {
                const ctxMom = document.getElementById('finMomChart').getContext('2d');
                const momColors = finData.mom_pct.map(val => val >= 0 ? 'rgba(16, 185, 129, 0.8)' : 'rgba(239, 68, 68, 0.8)');
                new Chart(ctxMom, {
                    type: 'bar',
                    data: { labels: finData.mom_labels, datasets: [{ label: 'Growth (%)', data: finData.mom_pct, backgroundColor: momColors, borderRadius: 4, barPercentage: 0.6 }] },
                    options: { maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return (ctx.parsed.y > 0 ? '+' : '') + ctx.parsed.y + '% MoM'; } } } }, scales: { y: { grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: {display: false}, ticks: { font: {size: 10}, callback: function(val) { return val + '%'; } } }, x: { grid: { display: false }, ticks: { font: {size: 10} } } } }
                });
            }

            if(document.getElementById('finTopClientsRadarChart') && typeof finData !== 'undefined') {
                const ctxTopRadar = document.getElementById('finTopClientsRadarChart').getContext('2d');
                new Chart(ctxTopRadar, {
                    type: 'radar',
                    data: {
                        labels: finData.tc_labels.length ? finData.tc_labels : ['No Clients'],
                        datasets: [
                            { label: 'Collected', data: finData.tc_col, backgroundColor: 'rgba(59, 130, 246, 0.3)', borderColor: '#3b82f6', pointBackgroundColor: '#3b82f6', borderWidth: 2 },
                            { label: 'Outstanding', data: finData.tc_uncol, backgroundColor: 'rgba(245, 158, 11, 0.3)', borderColor: '#f59e0b', pointBackgroundColor: '#f59e0b', borderWidth: 2 }
                        ]
                    },
                    options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, font: {size: 10} } }, tooltip: { callbacks: { label: function(ctx) { return ctx.dataset.label + ': ₱' + ctx.parsed.r.toLocaleString(undefined, {minimumFractionDigits: 2}); } } } }, scales: { r: { ticks: { display: false }, grid: { color: '#e2e8f0' }, pointLabels: { font: { size: 9, family: 'Inter' }, color: '#64748b' } } } }
                });
            }

            // =====================================
            // PROCUREMENT CHARTS 
            // =====================================
            if(document.getElementById('procTrendChart') && typeof procData !== 'undefined') {
                const ctxProcTrend = document.getElementById('procTrendChart').getContext('2d');
                const ptLabels = procData.trend.map(t => { let d = new Date(t.t_date); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }); });
                const ptData = procData.trend.map(t => t.po_count);
                let gradPt = ctxProcTrend.createLinearGradient(0, 0, 0, 300);
                gradPt.addColorStop(0, 'rgba(59, 130, 246, 0.4)');
                gradPt.addColorStop(1, 'rgba(59, 130, 246, 0.0)');
                new Chart(ctxProcTrend, {
                    type: 'line',
                    data: {
                        labels: ptLabels.length ? ptLabels : ['No Date'],
                        datasets: [{ label: 'POs Created', data: ptData, borderColor: '#3b82f6', backgroundColor: gradPt, borderWidth: 3, fill: true, tension: 0.4, pointBackgroundColor: '#ffffff', pointBorderColor: '#3b82f6', pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6 }]
                    },
                    options: { maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: { display: false }, ticks: {stepSize: 1} }, x: { grid: { display: false }, border: { display: false } } } }
                });
            }
            
            if(document.getElementById('procStatusChart') && typeof procData !== 'undefined') {
                const psLabels = procData.status_dist.map(s => s.status);
                const psData = procData.status_dist.map(s => s.count);
                new Chart(document.getElementById('procStatusChart'), {
                    type: 'doughnut', data: { labels: psLabels.length ? psLabels : ['No Data'], datasets: [{ data: psData, backgroundColor: ['#f59e0b', '#3b82f6', '#10b981', '#8b5cf6', '#64748b', '#ef4444'], hoverOffset: 6 }] },
                    options: { cutout: '65%', maintainAspectRatio: false, layout: { padding: 10 }, plugins: { legend: { position: 'right', labels: { usePointStyle: true, padding: 15, font: {size: 11} } } } }
                });
            }
            
            if(document.getElementById('procCategoryChart') && typeof procData !== 'undefined') {
                const ctxCat = document.getElementById('procCategoryChart').getContext('2d');
                let gradCat = ctxCat.createLinearGradient(0, 0, 300, 0); 
                gradCat.addColorStop(0, 'rgba(16, 185, 129, 0.8)'); 
                gradCat.addColorStop(1, 'rgba(59, 130, 246, 0.8)');
                const pcLabels = procData.top_cats.map(c => c.cat_name);
                const pcData = procData.top_cats.map(c => c.spent);
                new Chart(ctxCat, {
                    type: 'bar', data: { labels: pcLabels.length ? pcLabels : ['No Record'], datasets: [{ label: 'Total Spent (₱)', data: pcData, backgroundColor: gradCat, borderRadius: 6, barPercentage: 0.65 }] },
                    options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return '₱' + ctx.parsed.x.toLocaleString(undefined, {minimumFractionDigits: 2}); } } } }, scales: { x: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: { display: false }, ticks: { callback: function(val) { if(val >= 1000) return '₱' + (val/1000) + 'k'; return '₱' + val; } } }, y: { grid: { display: false }, border: { display: false }, ticks: { font: {size: 10} } } } }
                });
            }
            
            if(document.getElementById('procBrandChart') && typeof procData !== 'undefined') {
                const pbLabels = procData.top_brands.map(b => b.brand);
                const pbData = procData.top_brands.map(b => b.spent);
                new Chart(document.getElementById('procBrandChart'), {
                    type: 'polarArea', 
                    data: { labels: pbLabels.length ? pbLabels : ['No Brands'], datasets: [{ data: pbData, backgroundColor: ['rgba(59, 130, 246, 0.7)', 'rgba(16, 185, 129, 0.7)', 'rgba(245, 158, 11, 0.7)', 'rgba(139, 92, 246, 0.7)', 'rgba(236, 72, 153, 0.7)'], borderWidth: 2 }] },
                    options: { maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8, font: {size: 10} } }, tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': ₱' + ctx.raw.toLocaleString(undefined, {minimumFractionDigits: 2}); } } } }, scales: { r: { ticks: { display: false }, grid: { color: '#e2e8f0' } } } }
                });
            }

            // =====================================
            // SALES STAFF CHARTS 
            // =====================================
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

                new Chart(ctxSalesTrend, {
                    type: 'line',
                    data: {
                        labels: stLabels.length ? stLabels : ['No Date'],
                        datasets: [
                            { label: 'Submitted PRs', data: submittedData, borderColor: '#3b82f6', backgroundColor: gradSub, borderWidth: 3, fill: true, tension: 0.4, pointRadius: 3, pointHoverRadius: 6 },
                            { label: 'Approved PRs', data: approvedData, borderColor: '#10b981', backgroundColor: gradApp, borderWidth: 3, fill: true, tension: 0.4, pointRadius: 3, pointHoverRadius: 6 }
                        ]
                    },
                    options: { maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8, font: {size: 11}, padding: 20 } } }, scales: { y: { beginAtZero: true, grid: { borderDash: [4, 4], color: '#f1f5f9' }, border: { display: false }, ticks: { stepSize: 1 } }, x: { grid: { display: false }, border: { display: false } } } }
                });
            }

            if(document.getElementById('salesPrStatusChart') && typeof salesData !== 'undefined') {
                const spLabels = salesData.pr_status.map(s => s.status);
                const spData = salesData.pr_status.map(s => s.count);
                
                const colorMap = {
                    'Pending': 'rgba(245, 158, 11, 0.7)',
                    'Approved': 'rgba(16, 185, 129, 0.7)',
                    'Converted_to_PO': 'rgba(59, 130, 246, 0.7)',
                    'Rejected': 'rgba(239, 68, 68, 0.7)',
                };
                
                const borderMap = {
                    'Pending': '#f59e0b',
                    'Approved': '#10b981',
                    'Converted_to_PO': '#3b82f6',
                    'Rejected': '#ef4444',
                };
                
                const spBgColors = spLabels.map(status => colorMap[status] || 'rgba(100, 116, 139, 0.7)');
                const spBorderColors = spLabels.map(status => borderMap[status] || '#64748b');

                new Chart(document.getElementById('salesPrStatusChart'), {
                    type: 'polarArea', 
                    data: { 
                        labels: spLabels.length ? spLabels.map(s => s.replace(/_/g, ' ')) : ['No Data'], 
                        datasets: [{ 
                            data: spData, 
                            backgroundColor: spBgColors, 
                            borderColor: spBorderColors, 
                            borderWidth: 2 
                        }] 
                    },
                    options: { maintainAspectRatio: false, layout: { padding: 10 }, plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15, font: {size: 11} } } }, scales: { r: { ticks: { display: false }, grid: { color: '#e2e8f0' } } } }
                });
            }

            if(document.getElementById('salesTopCatChart') && typeof salesData !== 'undefined') {
                const scLabels = salesData.top_cats.map(c => c.cat_name);
                const scData = salesData.top_cats.map(c => c.total_qty);
                new Chart(document.getElementById('salesTopCatChart'), {
                    type: 'pie', 
                    data: { 
                        labels: scLabels, 
                        datasets: [{ 
                            label: 'Quantity Requested', 
                            data: scData, 
                            backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#0ea5e9', '#f43f5e'], 
                            hoverOffset: 6,
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }] 
                    },
                    options: { 
                        maintainAspectRatio: false, 
                        layout: { padding: 10 }, 
                        plugins: { 
                            legend: { position: 'right', labels: { usePointStyle: true, padding: 15, font: {size: 11} } }, 
                            tooltip: { callbacks: { label: function(ctx) { return ctx.label + ': ' + ctx.parsed + ' items'; } } } 
                        } 
                    }
                });
            }

            if(document.getElementById('salesTopClientsChart') && typeof salesData !== 'undefined') {
                const tcLabels = salesData.top_clients.map(c => c.client_name);
                const tcData = salesData.top_clients.map(c => c.total_tx);
                new Chart(document.getElementById('salesTopClientsChart'), {
                    type: 'radar', 
                    data: { 
                        labels: tcLabels.length ? tcLabels : ['No Record'], 
                        datasets: [{ 
                            label: 'Transactions', data: tcData, 
                            backgroundColor: 'rgba(59, 130, 246, 0.3)', borderColor: '#3b82f6', pointBackgroundColor: '#3b82f6', borderWidth: 2 
                        }] 
                    },
                    options: { maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(ctx) { return ctx.raw + ' Transactions'; } } } }, scales: { r: { ticks: { display: false, stepSize: 1 }, grid: { color: '#e2e8f0' }, pointLabels: { font: { size: 10, family: 'Inter' }, color: '#64748b' } } } }
                });
            }
        });
    </script>
</body>
</html>