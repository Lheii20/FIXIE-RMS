<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

if(!isset($_SESSION['user_id'])) header("Location: index.php");

$role = $_SESSION['role'];
$executives = ['GM', 'President'];
$can_view_financials = in_array($role, array_merge($executives, ['Finance']));
$is_sales_staff = ($role === 'Sales Staff');

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
$req_date     = getDateFilter('created_at', $period);
$pr_date      = getDateFilter('date_created', $period);
$po_date      = getDateFilter('date_created', $period);
$q_date       = getDateFilter('created_at', $period);
$po_hist_date = getDateFilter('timestamp', $period); 

// HELPER FUNCTION: Mabilisang Prepared Statement execution
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

// ==========================================
// ROLE-SPECIFIC KPI STATS
// ==========================================
$admin_stats = ['total_users' => 0, 'audit_today' => 0, 'total_files' => 0, 'pending_requests' => 0];
if ($role === 'Admin') {
    $admin_stats['total_users'] = get_count($conn, "SELECT COUNT(*) FROM users WHERE {$user_date['sql']}", $user_date['types'], $user_date['params']);
    $admin_stats['audit_today'] = get_count($conn, "SELECT COUNT(*) FROM audit_logs WHERE {$audit_date['sql']}", $audit_date['types'], $audit_date['params']);
    $admin_stats['total_files'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $admin_stats['pending_requests'] = get_count($conn, "SELECT COUNT(*) FROM user_requests WHERE status = 'Pending' AND {$req_date['sql']}", $req_date['types'], $req_date['params']);
}

$sales_stats = [
    'total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'pending_quotations' => 0, 'received_client_po' => 0
];
if ($is_sales_staff) {
    $sales_stats['total'] = get_count($conn, "SELECT COUNT(*) FROM purchase_requests WHERE {$pr_date['sql']}", $pr_date['types'], $pr_date['params']);
    $sales_stats['pending'] = get_count($conn, "SELECT COUNT(*) FROM purchase_requests WHERE status = 'Pending' AND {$pr_date['sql']}", $pr_date['types'], $pr_date['params']);
    $sales_stats['approved'] = get_count($conn, "SELECT COUNT(*) FROM purchase_requests WHERE status IN ('Approved', 'Converted_to_PO') AND {$pr_date['sql']}", $pr_date['types'], $pr_date['params']);
    $sales_stats['rejected'] = get_count($conn, "SELECT COUNT(*) FROM purchase_requests WHERE status = 'Rejected' AND {$pr_date['sql']}", $pr_date['types'], $pr_date['params']);
    $sales_stats['pending_quotations'] = get_count($conn, "SELECT COUNT(*) FROM quotations WHERE status = 'Pending PO' AND {$q_date['sql']}", $q_date['types'], $q_date['params']);
    $sales_stats['received_client_po'] = get_count($conn, "SELECT COUNT(*) FROM quotations WHERE status = 'PO Received' AND {$q_date['sql']}", $q_date['types'], $q_date['params']);
}

$proc_stats = ['total' => 0, 'pending' => 0, 'funded' => 0, 'delivered' => 0];
if ($role === 'Procurement') {
    $proc_stats['total'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $proc_stats['pending'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Pending', 'GM-Approved', 'Finance-Approved', 'President-Approved') AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $proc_stats['funded'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Funded' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $proc_stats['delivered'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Collected' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
}

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

$finance_stats = ['pending_po' => 0, 'funded_po' => 0, 'invoices' => 0, 'receipts' => 0];
if ($role === 'Finance') {
    $finance_stats['pending_po'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'GM-Approved' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $finance_stats['funded_po'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Funded' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $finance_stats['invoices'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Invoices' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $finance_stats['receipts'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Official receipts' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
}

$sc_stats = ['incoming_po' => 0, 'collected_po' => 0, 'dr_count' => 0, 'supplier_docs' => 0];
if ($role === 'Supply Chain') {
    $sc_stats['incoming_po'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Funded' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $sc_stats['collected_po'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Collected', 'Delivered') AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $sc_stats['dr_count'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Delivery receipts' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $sc_stats['supplier_docs'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Supplier transaction records' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
}

$tech_stats = ['tickets' => 0, 'diagnostics' => 0, 'job_orders' => 0, 'total' => 0];
if ($role === 'Technical') {
    $tech_stats['tickets'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Service tickets' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $tech_stats['diagnostics'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Diagnostic reports' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $tech_stats['job_orders'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Job orders' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $tech_stats['total'] = $tech_stats['tickets'] + $tech_stats['diagnostics'] + $tech_stats['job_orders'];
}

$admin_staff_stats = ['leaves' => 0, 'notices' => 0, 'memos' => 0, 'policies' => 0];
if (in_array($role, ['Administrative', 'Staff'])) {
    $admin_staff_stats['leaves'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Leave forms' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $admin_staff_stats['notices'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Employee correspondence and notices' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $admin_staff_stats['memos'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Internal memorandums' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $admin_staff_stats['policies'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE category = 'Company policies and procedures' AND status='Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
}

// ==========================================
// PREPARING QUERIES FOR DASHBOARD TABLES
// ==========================================
$my_recent = null;
if ($role !== 'Admin') {
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

// ==========================================
// RBAC CATEGORY & RECENT FILES
// ==========================================
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
if (!empty($user_categories)) {
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

// ==========================================
// GM / PRESIDENT NEW CHART ANALYTICS
// ==========================================
$gm_charts = [];
if (in_array($role, $executives)) {
    
    function fetch_gm_data($conn, $sql, $types, $params, $single = false) {
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

    $q_life = "
        SELECT 
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_docs,
            SUM(CASE WHEN status = 'Archived' THEN 1 ELSE 0 END) as archived_docs,
            SUM(CASE WHEN disposition_status = 'Ready for Disposition' THEN 1 ELSE 0 END) as ready_disp
        FROM documents
        WHERE {$doc_date['sql']}
    ";
    $gm_charts['lifecycle'] = fetch_gm_data($conn, $q_life, $doc_date['types'], $doc_date['params'], true);
    if(empty($gm_charts['lifecycle']['active_docs'])) $gm_charts['lifecycle'] = ['active_docs'=>0, 'archived_docs'=>0, 'ready_disp'=>0];

    $q_vol = "
        SELECT dc.parent_category as category, COUNT(d.doc_id) as count 
        FROM document_categories dc
        LEFT JOIN documents d ON LOWER(d.category) = LOWER(dc.sub_category) AND d.status = 'Active' AND {$doc_date['sql']}
        GROUP BY dc.parent_category
        ORDER BY count DESC
    ";
    $gm_charts['volume'] = fetch_gm_data($conn, $q_vol, $doc_date['types'], $doc_date['params'], false);

    $q_aud = "
        SELECT DATE(timestamp) as log_date, COUNT(*) as action_count 
        FROM audit_logs 
        WHERE {$audit_date['sql']}
        GROUP BY DATE(timestamp)
        ORDER BY log_date ASC
        LIMIT 30
    ";
    $gm_charts['audit'] = fetch_gm_data($conn, $q_aud, $audit_date['types'], $audit_date['params'], false);
    
    $q_turn = "
        SELECT status_to as stage, ROUND(AVG(TIMESTAMPDIFF(HOUR, 
            (SELECT MIN(timestamp) FROM po_history h2 WHERE h2.po_id = po_history.po_id), 
            timestamp)), 1) as avg_hours
        FROM po_history
        WHERE status_to IN ('GM-Approved', 'Finance-Approved', 'President-Approved', 'Funded', 'Delivered')
        AND {$po_hist_date['sql']}
        GROUP BY status_to
    ";
    $gm_charts['turnaround'] = fetch_gm_data($conn, $q_turn, $po_hist_date['types'], $po_hist_date['params'], false);
    
    if(empty($gm_charts['turnaround'])) {
        $gm_charts['turnaround'] = [
            ['stage' => 'GM-Approved', 'avg_hours' => 12],
            ['stage' => 'Finance-Approved', 'avg_hours' => 24],
            ['stage' => 'Pres-Approved', 'avg_hours' => 48],
            ['stage' => 'Funded', 'avg_hours' => 72],
            ['stage' => 'Delivered', 'avg_hours' => 96]
        ];
    }
}

// FORMAT PARA SA DISPLAY TEXT SA BUTTON
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