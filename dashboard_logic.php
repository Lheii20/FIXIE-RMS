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

$audit_date = getDateFilter('timestamp', $period);
$user_date  = getDateFilter('created_at', $period);
$doc_date   = getDateFilter('uploaded_at', $period);
$req_date   = getDateFilter('created_at', $period);
$pr_date    = getDateFilter('date_created', $period);
$po_date    = getDateFilter('date_created', $period);
$q_date     = getDateFilter('created_at', $period);

// HELPER FUNCTION: Mabilisang Prepared Statement execution para sa mga COUNT() at AVG()
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
// GLOBAL TURNAROUND STATS (PREPARED STATEMENT)
// ==========================================
if ($role !== 'Admin') {
    $stats = get_dashboard_stats($conn, $role);

    $turn_filter = getDateFilter('h1.timestamp', $period);
    $turnaround_sql = "
        SELECT AVG(days) as avg_days FROM (
            SELECT DATEDIFF(MAX(h2.timestamp), MIN(h1.timestamp)) as days
            FROM po_history h1
            JOIN po_history h2 ON h1.po_id = h2.po_id
            WHERE h1.status_to IN ('Pending', 'New') AND h2.status_to IN ('Delivered', 'Collected')
            AND {$turn_filter['sql']}
            GROUP BY h1.po_id
        ) as subquery
    ";
    $stmt_turn = $conn->prepare($turnaround_sql);
    if(!empty($turn_filter['params'])) {
        $stmt_turn->bind_param($turn_filter['types'], ...$turn_filter['params']);
    }
    $stmt_turn->execute();
    $turnaround = round($stmt_turn->get_result()->fetch_assoc()['avg_days'] ?? 0, 1);
}

// ==========================================
// PREPARING QUERIES FOR DASHBOARD TABLES
// (Inilipat dito mula sa dashboard.php)
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

$recent_audit = null;
if ($role === 'Admin') {
    $ra_sql = "SELECT a.*, u.full_name, u.role FROM audit_logs a LEFT JOIN users u ON a.user_id = u.user_id WHERE {$audit_date['sql']} ORDER BY a.timestamp DESC LIMIT 10";
    $stmt_ra = $conn->prepare($ra_sql);
    if(!empty($audit_date['params'])) {
        $stmt_ra->bind_param($audit_date['types'], ...$audit_date['params']);
    }
    $stmt_ra->execute();
    $recent_audit = $stmt_ra->get_result();
}

$recent_pos = null;
if ($can_view_financials) {
    $rp_sql = "SELECT po_id, po_number, client_name, status, date_created FROM purchase_orders WHERE {$po_date['sql']} ORDER BY date_created DESC LIMIT 6";
    $stmt_rp = $conn->prepare($rp_sql);
    if(!empty($po_date['params'])) {
        $stmt_rp->bind_param($po_date['types'], ...$po_date['params']);
    }
    $stmt_rp->execute();
    $recent_pos = $stmt_rp->get_result();
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
if ($is_top_mgmt) {
    $user_categories = $all_cats;
} else {
    $user_categories = $rbac_categories[$role] ?? [];
}

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
?>