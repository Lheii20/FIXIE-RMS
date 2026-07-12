<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

if(!isset($_SESSION['user_id'])) header("Location: index.php");

$role = $_SESSION['role'];
$executives = ['GM', 'President'];
$can_view_financials = in_array($role, array_merge($executives, ['Finance']));
$is_sales_staff = ($role === 'Sales Staff');

// ==========================================
// SYSTEM STORAGE HELPER FUNCTIONS
// ==========================================
if (!function_exists('getDirSize')) {
    function getDirSize($dir) {
        $size = 0;
        if (is_dir($dir)) {
            try {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $size += $file->getSize();
                    }
                }
            } catch (Exception $e) {
                // Ignore permissions/read errors safely
            }
        }
        return $size;
    }
}

if (!function_exists('formatBytes')) {
    function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

// ==========================================
// PERIOD FILTER LOGIC (PREPARED STATEMENT SAFE)
// ==========================================
$period = $_GET['period'] ?? 'all';

function getDateFilter($column, $period) {
    $start = $_GET['start'] ?? '';
    $end = $_GET['end'] ?? '';
    
    switch ($period) {
        case 'today': 
            return ['sql' => "DATE($column) = CURDATE()", 'types' => '', 'params' => []];
        case 'this_week': 
            return ['sql' => "YEARWEEK($column, 1) = YEARWEEK(CURDATE(), 1)", 'types' => '', 'params' => []];
        case 'this_month': 
            return ['sql' => "MONTH($column) = MONTH(CURDATE()) AND YEAR($column) = YEAR(CURDATE())", 'types' => '', 'params' => []];
        case 'this_year': 
            return ['sql' => "YEAR($column) = YEAR(CURDATE())", 'types' => '', 'params' => []];
        case 'custom':
            if(!empty($start) && !empty($end)) {
                $s = date('Y-m-d', strtotime($start));
                $e = date('Y-m-d', strtotime($end));
                return ['sql' => "DATE($column) BETWEEN ? AND ?", 'types' => 'ss', 'params' => [$s, $e]];
            }
            return ['sql' => "1=1", 'types' => '', 'params' => []];
        default: 
            return ['sql' => "1=1", 'types' => '', 'params' => []]; // All Time (default)
    }
}

$audit_date   = getDateFilter('timestamp', $period);
$user_date    = getDateFilter('created_at', $period);
$doc_date     = getDateFilter('uploaded_at', $period);
$req_date     = getDateFilter('requested_at', $period);
$pr_date      = getDateFilter('date_created', $period);
$po_date      = getDateFilter('date_created', $period);
$q_date       = getDateFilter('created_at', $period);
$po_hist_date = getDateFilter('timestamp', $period); 
$payment_date = getDateFilter('created_at', $period);
$doc_ver_date = getDateFilter('uploaded_at', $period);

function get_count($conn, $sql, $types, $params) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_row()) {
            return $row[0];
        }
    }
    return 0;
}

function fetch_chart_data($conn, $sql, $types, $params, $single = false) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($single) {
            return $res->fetch_assoc() ?: [];
        } else {
            $arr = [];
            while ($row = $res->fetch_assoc()) $arr[] = $row;
            return $arr;
        }
    }
    return $single ? [] : [];
}

// ==========================================
// ROLE-SPECIFIC KPI STATS & ADMIN ANALYTICS
// ==========================================
$admin_stats = ['total_users' => 0, 'audit_today' => 0, 'total_files' => 0, 'pending_requests' => 0];
$admin_charts = [];
$admin_insights_data = [];

if ($role === 'Admin') {
    $admin_stats['total_users'] = get_count($conn, "SELECT COUNT(*) FROM users WHERE status = 'Active' AND {$user_date['sql']}", $user_date['types'], $user_date['params']); 
    $admin_stats['audit_today'] = get_count($conn, "SELECT COUNT(*) FROM audit_logs WHERE {$audit_date['sql']}", $audit_date['types'], $audit_date['params']);
    $admin_stats['total_files'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $admin_stats['pending_requests'] = get_count($conn, "SELECT COUNT(*) FROM user_requests WHERE status = 'Pending' AND {$req_date['sql']}", $req_date['types'], $req_date['params']);

    $q_traffic = "SELECT DATE(timestamp) as log_date, COUNT(*) as action_count FROM audit_logs WHERE {$audit_date['sql']} GROUP BY log_date ORDER BY log_date DESC LIMIT 14";
    $raw_traffic = fetch_chart_data($conn, $q_traffic, $audit_date['types'], $audit_date['params'], false);
    $admin_charts['traffic'] = array_reverse($raw_traffic);

    $q_roles = "SELECT role, COUNT(*) as user_count FROM users WHERE status = 'Active' GROUP BY role";
    $admin_charts['roles'] = fetch_chart_data($conn, $q_roles, '', [], false);

    $q_active_users = "SELECT u.full_name, COUNT(a.log_id) as activity_count FROM audit_logs a JOIN users u ON a.user_id = u.user_id WHERE {$audit_date['sql']} GROUP BY a.user_id ORDER BY activity_count DESC LIMIT 5";
    $admin_charts['active_users'] = fetch_chart_data($conn, $q_active_users, $audit_date['types'], $audit_date['params'], false);

    $q_requests = "SELECT status, COUNT(*) as req_count FROM user_requests WHERE {$req_date['sql']} GROUP BY status";
    $admin_charts['requests'] = fetch_chart_data($conn, $q_requests, $req_date['types'], $req_date['params'], false);

    $admin_insights_data['pending_req_all'] = get_count($conn, "SELECT COUNT(*) FROM user_requests WHERE status = 'Pending'", '', []);
    $admin_insights_data['today_traffic'] = get_count($conn, "SELECT COUNT(*) FROM audit_logs WHERE DATE(timestamp) = CURDATE()", '', []);
    
    $days_res = $conn->query("SELECT COUNT(DISTINCT DATE(timestamp)) as days FROM audit_logs");
    $logs_res = $conn->query("SELECT COUNT(*) as logs FROM audit_logs");
    $admin_insights_data['total_days'] = $days_res ? ($days_res->fetch_assoc()['days'] ?: 1) : 1;
    $admin_insights_data['total_logs'] = $logs_res ? ($logs_res->fetch_assoc()['logs'] ?: 0) : 0;
    
    $q_top_user = "SELECT u.full_name, COUNT(a.log_id) as c FROM audit_logs a JOIN users u ON a.user_id = u.user_id GROUP BY a.user_id ORDER BY c DESC LIMIT 1";
    $admin_insights_data['top_user'] = fetch_chart_data($conn, $q_top_user, '', [], true);
    
    $admin_insights_data['total_files_all'] = get_count($conn, "SELECT COUNT(*) FROM documents", '', []);

    // ==========================================
    // SYSTEM STORAGE CALCULATOR
    // ==========================================
    $uploads_dir = __DIR__ . '/uploads'; 
    $storage_used = getDirSize($uploads_dir);
    $storage_limit = 50 * 1024 * 1024 * 1024; // 50GB Limit
    $storage_pct = ($storage_limit > 0) ? round(($storage_used / $storage_limit) * 100, 1) : 0;
    
    $admin_insights_data['storage_used'] = $storage_used;
    $admin_insights_data['storage_limit'] = $storage_limit;
    $admin_insights_data['storage_pct'] = $storage_pct;
    $admin_insights_data['storage_formatted'] = formatBytes($storage_used);
    $admin_insights_data['limit_formatted'] = formatBytes($storage_limit);
}

// ==========================================
// SALES STAFF STATS & CHARTS
// ==========================================
$sales_stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'pending_quotations' => 0, 'received_client_po' => 0];
$sales_charts = [];

if ($is_sales_staff) {
    $sales_stats['total'] = get_count($conn, "SELECT COUNT(*) FROM purchase_requests WHERE {$pr_date['sql']}", $pr_date['types'], $pr_date['params']);
    $sales_stats['pending'] = get_count($conn, "SELECT COUNT(*) FROM purchase_requests WHERE status = 'Pending' AND {$pr_date['sql']}", $pr_date['types'], $pr_date['params']);
    $sales_stats['approved'] = get_count($conn, "SELECT COUNT(*) FROM purchase_requests WHERE status IN ('Approved', 'Converted_to_PO') AND {$pr_date['sql']}", $pr_date['types'], $pr_date['params']);
    $sales_stats['rejected'] = get_count($conn, "SELECT COUNT(*) FROM purchase_requests WHERE status = 'Rejected' AND {$pr_date['sql']}", $pr_date['types'], $pr_date['params']);
    
    // UPDATED: Now queries for "Pending Approval" instead of "Pending PO"
    $sales_stats['pending_quotations'] = get_count($conn, "SELECT COUNT(*) FROM quotations WHERE status = 'Pending Approval' AND {$q_date['sql']}", $q_date['types'], $q_date['params']);
    $sales_stats['received_client_po'] = get_count($conn, "SELECT COUNT(*) FROM quotations WHERE status = 'PO Received' AND {$q_date['sql']}", $q_date['types'], $q_date['params']);

    // 1. PR Status Distribution (For Polar Area)
    $q_pr_status = "SELECT status, COUNT(*) as count FROM purchase_requests WHERE {$pr_date['sql']} GROUP BY status";
    $sales_charts['pr_status'] = fetch_chart_data($conn, $q_pr_status, $pr_date['types'], $pr_date['params'], false);

    // 2. Daily Pipeline Volume (Submitted vs Approved PRs)
    $q_sales_trend = "
        SELECT DATE(date_created) as t_date, 
               COUNT(*) as submitted_prs,
               SUM(CASE WHEN status IN ('Approved', 'Converted_to_PO') THEN 1 ELSE 0 END) as approved_prs
        FROM purchase_requests 
        WHERE {$pr_date['sql']} 
        GROUP BY t_date 
        ORDER BY t_date DESC LIMIT 14
    ";
    $raw_sales_trend = fetch_chart_data($conn, $q_sales_trend, $pr_date['types'], $pr_date['params'], false);
    $sales_charts['trend'] = array_reverse($raw_sales_trend);

    // 3. Top Clients by Transaction Count (Radar Chart)
    $q_top_clients_sales = "SELECT client_name, COUNT(*) as total_tx FROM purchase_requests WHERE status != 'Rejected' AND {$pr_date['sql']} GROUP BY client_name ORDER BY total_tx DESC LIMIT 5";
    $sales_charts['top_clients'] = fetch_chart_data($conn, $q_top_clients_sales, $pr_date['types'], $pr_date['params'], false);

    // 4. Top Requested Categories
    $standard_cats = [
        '01' => ['name' => 'Hardware', 'qty' => 0],
        '02' => ['name' => 'CCTVs', 'qty' => 0],
        '03' => ['name' => 'Peripherals', 'qty' => 0],
        '04' => ['name' => 'Office Supplies', 'qty' => 0],
        '05' => ['name' => 'WIFI / LAN', 'qty' => 0],
        '06' => ['name' => 'Printers', 'qty' => 0]
    ];
    
    $q_top_cats_sales = "SELECT pi.category as cat_code, SUM(pi.quantity) as total_qty FROM pr_items pi JOIN purchase_requests pr ON pi.pr_id = pr.pr_id WHERE pr.status != 'Rejected' AND {$pr_date['sql']} GROUP BY pi.category";
    $cat_res = fetch_chart_data($conn, $q_top_cats_sales, $pr_date['types'], $pr_date['params'], false);
    
    foreach($cat_res as $r) {
        $code = str_pad($r['cat_code'], 2, '0', STR_PAD_LEFT);
        $qty = (int)$r['total_qty'];
        if (isset($standard_cats[$code])) {
            $standard_cats[$code]['qty'] += $qty;
        } else {
            // Fallback mapper if old strings exist in DB instead of code
            $mapped = false;
            foreach($standard_cats as $k => $v) {
                if (stripos($code, $v['name']) !== false) {
                    $standard_cats[$k]['qty'] += $qty; $mapped = true; break;
                }
            }
            if (!$mapped) {
                 if(stripos($code, 'cctv') !== false) $standard_cats['02']['qty'] += $qty;
                 elseif(stripos($code, 'peripheral') !== false) $standard_cats['03']['qty'] += $qty;
                 elseif(stripos($code, 'office') !== false) $standard_cats['04']['qty'] += $qty;
                 elseif(stripos($code, 'wifi') !== false || stripos($code, 'lan') !== false) $standard_cats['05']['qty'] += $qty;
                 elseif(stripos($code, 'print') !== false) $standard_cats['06']['qty'] += $qty;
                 else $standard_cats['01']['qty'] += $qty; // Default fallback to Hardware
            }
        }
    }
    
    $final_top_cats = [];
    foreach($standard_cats as $data) {
        $final_top_cats[] = [
            'cat_name' => $data['name'], 
            'total_qty' => $data['qty']
        ];
    }
    $sales_charts['top_cats'] = $final_top_cats;
    
    // Insights Data
    $q_rejected = "SELECT pr_number, client_name FROM purchase_requests WHERE status = 'Rejected' AND {$pr_date['sql']} ORDER BY date_created DESC LIMIT 1";
    $sales_charts['latest_rejected'] = fetch_chart_data($conn, $q_rejected, $pr_date['types'], $pr_date['params'], true);
    
    $q_total_sales_val = "SELECT SUM(amount) as total FROM purchase_requests WHERE status IN ('Approved', 'Converted_to_PO') AND {$pr_date['sql']}";
    $sales_val_res = fetch_chart_data($conn, $q_total_sales_val, $pr_date['types'], $pr_date['params'], true);
    $sales_charts['total_approved_val'] = $sales_val_res['total'] ?? 0;
}

// ==========================================
// PROCUREMENT STATS & CHARTS
// ==========================================
$proc_stats = ['total' => 0, 'pending' => 0, 'funded' => 0, 'delivered' => 0];
$proc_charts = [];

if ($role === 'Procurement') {
    $proc_stats['total'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $proc_stats['pending'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Pending', 'GM-Approved', 'Finance-Approved', 'President-Approved') AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $proc_stats['funded'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Funded' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $proc_stats['delivered'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Collected', 'Delivered', 'Partially-Collected') AND {$po_date['sql']}", $po_date['types'], $po_date['params']);

    $q_status = "SELECT status, COUNT(*) as count FROM purchase_orders WHERE {$po_date['sql']} GROUP BY status";
    $proc_charts['status_dist'] = fetch_chart_data($conn, $q_status, $po_date['types'], $po_date['params'], false);

    $q_trend = "SELECT DATE(date_created) as t_date, COUNT(*) as po_count FROM purchase_orders WHERE {$po_date['sql']} GROUP BY t_date ORDER BY t_date DESC LIMIT 14";
    $raw_trend = fetch_chart_data($conn, $q_trend, $po_date['types'], $po_date['params'], false);
    $proc_charts['trend'] = array_reverse($raw_trend);

    // Categories mapping for Procurement
    $standard_cats_proc = [
        '01' => ['name' => 'Hardware', 'spent' => 0],
        '02' => ['name' => 'CCTVs', 'spent' => 0],
        '03' => ['name' => 'Peripherals', 'spent' => 0],
        '04' => ['name' => 'Office Supplies', 'spent' => 0],
        '05' => ['name' => 'WIFI / LAN', 'spent' => 0],
        '06' => ['name' => 'Printers', 'spent' => 0]
    ];
    $q_cats = "SELECT pi.category as cat_code, SUM(pi.total_price) as spent FROM po_items pi JOIN purchase_orders p ON pi.po_id = p.po_id WHERE p.status NOT IN ('Rejected', 'Invalid') AND {$po_date['sql']} GROUP BY pi.category";
    $cat_res_proc = fetch_chart_data($conn, $q_cats, $po_date['types'], $po_date['params'], false);
    foreach($cat_res_proc as $r) {
        $code = str_pad($r['cat_code'], 2, '0', STR_PAD_LEFT);
        $spent = (float)$r['spent'];
        if (isset($standard_cats_proc[$code])) {
            $standard_cats_proc[$code]['spent'] += $spent;
        } else {
            $mapped = false;
            foreach($standard_cats_proc as $k => $v) {
                if (stripos($code, $v['name']) !== false) {
                    $standard_cats_proc[$k]['spent'] += $spent; $mapped = true; break;
                }
            }
            if (!$mapped) $standard_cats_proc['01']['spent'] += $spent;
        }
    }
    $final_proc_cats = [];
    foreach($standard_cats_proc as $data) { $final_proc_cats[] = ['cat_name' => $data['name'], 'spent' => $data['spent']]; }
    $proc_charts['top_cats'] = $final_proc_cats;

    $q_brands = "SELECT brand, SUM(total_price) as spent FROM po_items pi JOIN purchase_orders p ON pi.po_id = p.po_id WHERE brand != 'Generic/Other' AND p.status NOT IN ('Rejected', 'Invalid') AND {$po_date['sql']} GROUP BY brand ORDER BY spent DESC LIMIT 5";
    $proc_charts['top_brands'] = fetch_chart_data($conn, $q_brands, $po_date['types'], $po_date['params'], false);

    $q_total_spent = "SELECT SUM(amount) as total FROM purchase_orders WHERE status NOT IN ('Rejected', 'Invalid') AND {$po_date['sql']}";
    $total_spent_res = fetch_chart_data($conn, $q_total_spent, $po_date['types'], $po_date['params'], true);
    $proc_charts['total_spent'] = $total_spent_res['total'] ?? 0;
    
    $q_stagnant = "SELECT po_number, status, current_location, TIMESTAMPDIFF(HOUR, date_created, NOW()) as hours_wait FROM purchase_orders WHERE status IN ('Pending', 'GM-Approved', 'Finance-Approved', 'President-Approved') ORDER BY hours_wait DESC LIMIT 1";
    $proc_charts['stagnant'] = fetch_chart_data($conn, $q_stagnant, '', [], true);
}

// ==========================================
// EXECUTIVE (GM/PRES) CHART ANALYTICS
// ==========================================
$exec_stats = ['active_docs' => 0, 'archived_docs' => 0, 'pending_pr' => 0, 'pending_po' => 0];

if (in_array($role, $executives)) {
    $exec_stats['active_docs'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE status = 'Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $exec_stats['archived_docs'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE status = 'Archived' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $exec_stats['pending_pr'] = get_count($conn, "SELECT COUNT(*) FROM purchase_requests WHERE status = 'Pending' AND {$pr_date['sql']}", $pr_date['types'], $pr_date['params']);
    
    if ($role === 'GM') {
        $exec_stats['pending_po'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    } else {
        $exec_stats['pending_po'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Finance-Approved' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    }
}

$sc_stats = ['ready_for_delivery' => 0, 'delivered' => 0, 'awaiting_collection' => 0, 'completed_collections' => 0, 'delivery_proofs' => 0];
$sc_charts = ['status_dist' => [], 'delivery_trend' => [], 'top_clients' => [], 'proof_coverage' => []];
if ($role === 'Supply Chain') {
    // Supply Chain sees only operational fulfilment information, not finance figures.
    $sc_stats['ready_for_delivery'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Funded' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $sc_stats['delivered'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Delivered', 'Partially-Collected', 'Collected') AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $sc_stats['awaiting_collection'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Delivered' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $sc_stats['completed_collections'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Collected' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $sc_stats['delivery_proofs'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE doc_type = 'Proof of Delivery' AND status = 'Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);

    $q_sc_status = "SELECT status, COUNT(*) AS total FROM purchase_orders WHERE status IN ('Funded', 'Delivered', 'Partially-Collected', 'Collected') AND {$po_date['sql']} GROUP BY status";
    $sc_charts['status_dist'] = fetch_chart_data($conn, $q_sc_status, $po_date['types'], $po_date['params'], false);

    $q_sc_trend = "SELECT DATE(COALESCE(actual_delivery_date, date_created)) AS delivery_date, COUNT(*) AS total FROM purchase_orders WHERE status IN ('Delivered', 'Partially-Collected', 'Collected') AND {$po_date['sql']} GROUP BY delivery_date ORDER BY delivery_date DESC LIMIT 14";
    $sc_charts['delivery_trend'] = array_reverse(fetch_chart_data($conn, $q_sc_trend, $po_date['types'], $po_date['params'], false));

    $q_sc_clients = "SELECT client_name, COUNT(*) AS total FROM purchase_orders WHERE status IN ('Delivered', 'Partially-Collected', 'Collected') AND {$po_date['sql']} GROUP BY client_name ORDER BY total DESC LIMIT 5";
    $sc_charts['top_clients'] = fetch_chart_data($conn, $q_sc_clients, $po_date['types'], $po_date['params'], false);

    $sc_charts['proof_coverage'] = [
        ['label' => 'Proofs Filed', 'total' => $sc_stats['delivery_proofs']],
        ['label' => 'Delivered Orders', 'total' => max(0, $sc_stats['delivered'] - $sc_stats['delivery_proofs'])]
    ];
}

$tech_stats = ['tickets' => 0, 'diagnostics' => 0, 'job_orders' => 0, 'total' => 0];
if ($role === 'Technical') {
    $tech_stats['tickets'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Service tickets' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $tech_stats['diagnostics'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Diagnostic reports' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $tech_stats['job_orders'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Job orders' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $tech_stats['total'] = $tech_stats['tickets'] + $tech_stats['diagnostics'] + $tech_stats['job_orders'];
}

$my_recent = null;
if (!in_array($role, ['Admin', 'Finance']) && !in_array($role, $executives)) {
    if ($is_sales_staff) {
        $ws_sql = "SELECT pr_id as id, pr_number as number, client_name, amount, status, date_created FROM purchase_requests WHERE {$pr_date['sql']} ORDER BY date_created DESC LIMIT 10";
        $stmt_ws = $conn->prepare($ws_sql);
        if(!empty($pr_date['params'])) $stmt_ws->bind_param($pr_date['types'], ...$pr_date['params']);
    } else if ($role == 'Procurement') {
        $ws_sql = "SELECT po_id as id, po_number as number, client_name, amount, status, current_location, date_created FROM purchase_orders WHERE {$po_date['sql']} ORDER BY date_created DESC LIMIT 10";
        $stmt_ws = $conn->prepare($ws_sql);
        if(!empty($po_date['params'])) $stmt_ws->bind_param($po_date['types'], ...$po_date['params']);
    } else {
        $ws_sql = "SELECT po_id as id, po_number as number, client_name, amount, status, current_location, date_created FROM purchase_orders WHERE status NOT IN ('Collected', 'Invalid') AND {$po_date['sql']} ORDER BY date_created DESC LIMIT 10";
        $stmt_ws = $conn->prepare($ws_sql);
        if(!empty($po_date['params'])) $stmt_ws->bind_param($po_date['types'], ...$po_date['params']);
    }
    $stmt_ws->execute();
    $my_recent = $stmt_ws->get_result();
}

$rbac_categories = [];
$all_cats = [];
$cat_query = $conn->query("SELECT sub_category, assigned_to_role FROM document_categories");

if ($cat_query) {
    while ($row = $cat_query->fetch_assoc()) {
        $all_cats[] = $row['sub_category'];
        if (!empty($row['assigned_to_role'])) {
            $roles = explode(',', $row['assigned_to_role']);
            foreach ($roles as $r) {
                $r = trim($r);
                $rbac_categories[$r][] = $row['sub_category'];
            }
        }
    }
}

$is_top_mgmt = in_array($role, ['Admin', 'GM', 'President']);
$user_categories = $is_top_mgmt ? $all_cats : ($rbac_categories[$role] ?? []);
$recent_dashboard_files = null;

if (!empty($user_categories) && !in_array($role, ['Finance', 'Admin'])) {
    $placeholders = implode(',', array_fill(0, count($user_categories), '?'));
    $q_str = "
        SELECT d.*, u.full_name 
        FROM documents d 
        LEFT JOIN users u ON d.uploaded_by = u.user_id 
        WHERE d.status = 'Active' AND d.category IN ($placeholders) AND {$doc_date['sql']}
        ORDER BY d.uploaded_at DESC LIMIT 5";
        
    $stmt_rf = $conn->prepare($q_str);
    if ($stmt_rf) {
        $types_rf = str_repeat('s', count($user_categories)) . $doc_date['types'];
        $params_rf = array_merge($user_categories, $doc_date['params']);
        if(!empty($params_rf)) {
            $stmt_rf->bind_param($types_rf, ...$params_rf);
        }
        $stmt_rf->execute();
        $recent_dashboard_files = $stmt_rf->get_result();
    }
}

$gm_charts = [];
if (in_array($role, $executives)) {
    $q_life = "SELECT SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_docs, SUM(CASE WHEN status = 'Archived' THEN 1 ELSE 0 END) as archived_docs, SUM(CASE WHEN disposition_status = 'Ready for Disposition' THEN 1 ELSE 0 END) as ready_disp FROM documents WHERE {$doc_date['sql']}";
    $gm_charts['lifecycle'] = fetch_chart_data($conn, $q_life, $doc_date['types'], $doc_date['params'], true);

    $q_vol = "SELECT dc.parent_category as category, COUNT(d.doc_id) as count FROM document_categories dc LEFT JOIN documents d ON LOWER(d.category) = LOWER(dc.sub_category) AND d.status = 'Active' AND {$doc_date['sql']} GROUP BY dc.parent_category ORDER BY count DESC";
    $gm_charts['volume'] = fetch_chart_data($conn, $q_vol, $doc_date['types'], $doc_date['params'], false);

    $q_activity_trend = "
        SELECT a_date, SUM(is_req_quote) as req_quote_count, SUM(is_po) as po_count, SUM(is_fin_fulfill) as fin_fulfill_count, SUM(is_doc) as doc_count, SUM(is_approval) as approval_count
        FROM (
            SELECT DATE(date_created) as a_date, 1 as is_req_quote, 0 as is_po, 0 as is_fin_fulfill, 0 as is_doc, 0 as is_approval FROM purchase_requests WHERE {$pr_date['sql']}
            UNION ALL SELECT DATE(created_at), 1, 0, 0, 0, 0 FROM quotations WHERE {$q_date['sql']}
            UNION ALL SELECT DATE(date_created), 0, 1, 0, 0, 0 FROM purchase_orders WHERE {$po_date['sql']}
            UNION ALL SELECT DATE(created_at), 0, 0, 1, 0, 0 FROM payments WHERE {$payment_date['sql']}
            UNION ALL SELECT DATE(timestamp), 0, 0, 1, 0, 0 FROM po_history WHERE status_to IN ('Funded', 'Delivered', 'Collected', 'Partially-Collected') AND {$po_hist_date['sql']}
            UNION ALL SELECT DATE(uploaded_at), 0, 0, 0, 1, 0 FROM documents WHERE {$doc_date['sql']}
            UNION ALL SELECT DATE(timestamp), 0, 0, 0, 0, 1 FROM po_history WHERE status_to LIKE '%Approved%' AND {$po_hist_date['sql']}
        ) as combined GROUP BY a_date ORDER BY a_date DESC LIMIT 15
    ";
    $act_types = $pr_date['types'] . $q_date['types'] . $po_date['types'] . $payment_date['types'] . $po_hist_date['types'] . $doc_date['types'] . $po_hist_date['types'];
    $act_params = array_merge($pr_date['params'], $q_date['params'], $po_date['params'], $payment_date['params'], $po_hist_date['params'], $doc_date['params'], $po_hist_date['params']);
    $raw_activity = fetch_chart_data($conn, $q_activity_trend, $act_types, $act_params, false);
    $gm_charts['activity_trend'] = array_reverse($raw_activity);

    $q_turn = "SELECT status_to as stage, ROUND(AVG(TIMESTAMPDIFF(HOUR, (SELECT MIN(timestamp) FROM po_history h2 WHERE h2.po_id = po_history.po_id), timestamp)), 1) as avg_hours FROM po_history WHERE status_to IN ('GM-Approved', 'Finance-Approved', 'President-Approved', 'Funded', 'Delivered') AND {$po_hist_date['sql']} GROUP BY status_to";
    $gm_charts['turnaround'] = fetch_chart_data($conn, $q_turn, $po_hist_date['types'], $po_hist_date['params'], false);

    $q_uncollected = "SELECT SUM(amount) as total_uncollected, COUNT(*) as count_uncollected FROM purchase_orders WHERE status IN ('Delivered', 'Partially-Collected') AND {$po_date['sql']}";
    $gm_charts['uncollected'] = fetch_chart_data($conn, $q_uncollected, $po_date['types'], $po_date['params'], true);

    $q_aging = "SELECT p.po_number, p.status, p.current_location, TIMESTAMPDIFF(HOUR, COALESCE((SELECT MAX(timestamp) FROM po_history ph WHERE ph.po_id = p.po_id), p.date_created), NOW()) as hours_stagnant FROM purchase_orders p WHERE p.status NOT IN ('Collected', 'Rejected', 'Invalid') AND {$po_date['sql']} ORDER BY hours_stagnant DESC LIMIT 1";
    $gm_charts['aging_po'] = fetch_chart_data($conn, $q_aging, $po_date['types'], $po_date['params'], true);

    $q_quote_conv = "SELECT COUNT(*) as total_quotes, SUM(CASE WHEN status IN ('PO Received', 'Converted to PR') THEN 1 ELSE 0 END) as converted_quotes FROM quotations WHERE {$q_date['sql']}";
    $gm_charts['quote_conversion'] = fetch_chart_data($conn, $q_quote_conv, $q_date['types'], $q_date['params'], true);

    $q_top_client = "SELECT client_name, COUNT(*) as tx_count FROM purchase_orders WHERE {$po_date['sql']} GROUP BY client_name ORDER BY tx_count DESC LIMIT 1";
    $gm_charts['top_client'] = fetch_chart_data($conn, $q_top_client, $po_date['types'], $po_date['params'], true);
}

$finance_charts = [];
$finance_stats = ['pending_po' => 0, 'funded_po' => 0, 'uncollected_amount' => 0, 'total_revenue' => 0];

if ($role === 'Finance') {
    $finance_stats['pending_po'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'GM-Approved' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $finance_stats['funded_po'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Funded' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    
    $q_fin_kpi = "SELECT SUM(CASE WHEN status IN ('Delivered', 'Partially-Collected') THEN amount ELSE 0 END) as uncollected, SUM(CASE WHEN status IN ('Collected', 'Partially-Collected') THEN amount ELSE 0 END) as total_rev FROM purchase_orders WHERE {$po_date['sql']}";
    $fin_kpi_res = fetch_chart_data($conn, $q_fin_kpi, $po_date['types'], $po_date['params'], true);
    $finance_stats['uncollected_amount'] = $fin_kpi_res['uncollected'] ?? 0;
    $finance_stats['total_revenue'] = $fin_kpi_res['total_rev'] ?? 0;

    $stmt_monthly = $conn->query("SELECT DATE_FORMAT(date_created, '%Y-%m') as month_str, SUM(amount) as total_sales FROM purchase_orders WHERE status NOT IN ('Rejected', 'Invalid') GROUP BY month_str ORDER BY month_str ASC LIMIT 12");
    $historical = []; if($stmt_monthly) { while($r = $stmt_monthly->fetch_assoc()) { $historical[] = $r; } }
    
    $n = count($historical); $sum_x = 0; $sum_y = 0; $sum_xy = 0; $sum_xx = 0; $x = 1; $last_month_str = date('Y-m');
    $labels = []; $actuals = []; $predicteds = [];
    foreach($historical as $row) { $y = (float)$row['total_sales']; $sum_x += $x; $sum_y += $y; $sum_xy += ($x * $y); $sum_xx += ($x * $x); $last_month_str = $row['month_str'].'-01'; $x++; }
    
    $m = 0; $b = 0;
    if($n > 1) {
        $denominator = (($n * $sum_xx) - ($sum_x * $sum_x));
        if($denominator != 0) { $m = (($n * $sum_xy) - ($sum_x * $sum_y)) / $denominator; $b = ($sum_y - ($m * $sum_x)) / $n; }
    } else if ($n == 1) { $b = $historical[0]['total_sales']; }
    
    $current_x = 1;
    foreach($historical as $idx => $row) {
        $labels[] = date('M Y', strtotime($row['month_str'].'-01')); $actuals[] = (float)$row['total_sales'];
        if ($idx === count($historical) - 1) { $predicteds[] = (float)$row['total_sales']; } else { $predicteds[] = null; }
        $current_x++;
    }
    
    $future_sum = 0; $base_time = strtotime($last_month_str);
    for($i=1; $i<=3; $i++) {
        $pred_y = ($m * $current_x) + $b; if($pred_y < 0) $pred_y = 0; 
        $next_month = strtotime("+$i month", $base_time);
        $labels[] = date('M Y', $next_month) . ' (Est)'; $actuals[] = null; $predicteds[] = round($pred_y, 2); $future_sum += round($pred_y, 2); $current_x++;
    }
    $finance_charts['revenue_labels'] = $labels; $finance_charts['revenue_actuals'] = $actuals;
    $finance_charts['revenue_predicteds'] = $predicteds; $finance_charts['future_sum'] = $future_sum;

    $q_in = "SELECT DATE_FORMAT(payment_date, '%Y-%m') as m, SUM(amount_paid) as val FROM payments GROUP BY m";
    $in_data = fetch_chart_data($conn, $q_in, '', []);
    $q_out = "SELECT DATE_FORMAT(date_created, '%Y-%m') as m, SUM(amount) as val FROM purchase_requests WHERE status IN ('Approved', 'Converted_to_PO') GROUP BY m";
    $out_data = fetch_chart_data($conn, $q_out, '', []);

    $cf_months = [];
    foreach($in_data as $row) { $cf_months[$row['m']] = ['inflow' => $row['val'], 'outflow' => 0]; }
    foreach($out_data as $row) { if(!isset($cf_months[$row['m']])) { $cf_months[$row['m']] = ['inflow'=>0, 'outflow'=>0]; } $cf_months[$row['m']]['outflow'] = $row['val']; }
    ksort($cf_months); $cf_sliced = array_slice($cf_months, -6, 6, true);
    
    $cf_labels = []; $cf_in = []; $cf_out = [];
    foreach($cf_sliced as $m => $v) { $cf_labels[] = date('M Y', strtotime($m.'-01')); $cf_in[] = $v['inflow']; $cf_out[] = $v['outflow']; }
    $finance_charts['cf_labels'] = $cf_labels; $finance_charts['cf_in'] = $cf_in; $finance_charts['cf_out'] = $cf_out;

    $mom_labels = []; $mom_rev = []; $mom_pct = []; $prev_sales = null;
    foreach($historical as $row) {
        $curr = (float)$row['total_sales']; $growth = 0;
        if($prev_sales !== null && $prev_sales > 0) { $growth = (($curr - $prev_sales) / $prev_sales) * 100; }
        $mom_labels[] = date('M Y', strtotime($row['month_str'].'-01')); $mom_rev[] = $curr; $mom_pct[] = round($growth, 1); $prev_sales = $curr;
    }
    $finance_charts['mom_labels'] = array_slice($mom_labels, -6); $finance_charts['mom_rev'] = array_slice($mom_rev, -6); $finance_charts['mom_pct'] = array_slice($mom_pct, -6);

    $q_stacked = "SELECT client_name, SUM(amount) as total_revenue, SUM(CASE WHEN status = 'Collected' THEN amount WHEN status = 'Partially-Collected' THEN COALESCE((SELECT SUM(amount_paid) FROM payments WHERE po_id = purchase_orders.po_id), 0) ELSE 0 END) as collected_amount FROM purchase_orders WHERE status NOT IN ('Rejected', 'Invalid') AND {$po_date['sql']} GROUP BY client_name ORDER BY total_revenue DESC LIMIT 5";
    $stacked_data = fetch_chart_data($conn, $q_stacked, $po_date['types'], $po_date['params'], false);
    
    $tc_labels = []; $tc_col = []; $tc_uncol = [];
    foreach($stacked_data as $t) { $tc_labels[] = $t['client_name']; $tc_col[] = (float)$t['collected_amount']; $tc_uncol[] = (float)$t['total_revenue'] - (float)$t['collected_amount']; }
    $finance_charts['tc_labels'] = $tc_labels; $finance_charts['tc_col'] = $tc_col; $finance_charts['tc_uncol'] = $tc_uncol;
}

$active_filter_text = "All Time";
if ($period == 'today') $active_filter_text = "Today";
if ($period == 'this_week') $active_filter_text = "This Week";
if ($period == 'this_month') $active_filter_text = "This Month";
if ($period == 'this_year') $active_filter_text = "This Year";
if ($period == 'custom' && !empty($_GET['start']) && !empty($_GET['end'])) {
    $s_display = date('M d, Y', strtotime($_GET['start']));
    $e_display = date('M d, Y', strtotime($_GET['end']));
    $active_filter_text = ($s_display == $e_display) ? $s_display : "$s_display to $e_display";
}
?>
