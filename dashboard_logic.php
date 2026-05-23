<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';

if(!isset($_SESSION['user_id'])) header("Location: index.php");

$role = $_SESSION['role'];
$executives = ['GM', 'President'];

$can_view_retention = in_array($role, $executives);
$can_view_financials = in_array($role, array_merge($executives, ['Finance']));
$can_view_records_kpi = in_array($role, $executives);
$is_sales_staff = ($role === 'Sales Staff');
$active_tab = $can_view_financials ? 'financial' : 'operations';

// ==========================================
// ROLE-SPECIFIC KPI STATS
// ==========================================

// 1. ADMIN STATS
$admin_stats = ['total_users' => 0, 'audit_today' => 0, 'total_files' => 0, 'pending_requests' => 0];
if ($role === 'Admin') {
    $admin_stats['total_users'] = $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0] ?? 0;
    $admin_stats['audit_today'] = $conn->query("SELECT COUNT(*) FROM audit_logs WHERE DATE(timestamp) = CURDATE()")->fetch_row()[0] ?? 0;
    $admin_stats['total_files'] = $conn->query("SELECT COUNT(*) FROM documents")->fetch_row()[0] ?? 0;
    $admin_stats['pending_requests'] = $conn->query("SELECT COUNT(*) FROM user_requests WHERE status = 'Pending'")->fetch_row()[0] ?? 0;
}

// 2. SALES STAFF STATS (UPDATED FOR QUOTATION TRACKER)
$sales_stats = [
    'total' => 0, 
    'pending' => 0, 
    'approved' => 0, 
    'rejected' => 0, 
    'pending_quotations' => 0, 
    'received_client_po' => 0
];
if ($is_sales_staff) {
    // Existing PR Stats
    $sales_stats['total'] = $conn->query("SELECT COUNT(*) FROM purchase_requests")->fetch_row()[0] ?? 0;
    $sales_stats['pending'] = $conn->query("SELECT COUNT(*) FROM purchase_requests WHERE status = 'Pending'")->fetch_row()[0] ?? 0;
    $sales_stats['approved'] = $conn->query("SELECT COUNT(*) FROM purchase_requests WHERE status IN ('Approved', 'Converted_to_PO')")->fetch_row()[0] ?? 0;
    $sales_stats['rejected'] = $conn->query("SELECT COUNT(*) FROM purchase_requests WHERE status = 'Rejected'")->fetch_row()[0] ?? 0;
    
    // New Quotation Tracker Stats
    $sales_stats['pending_quotations'] = $conn->query("SELECT COUNT(*) FROM quotations WHERE status = 'Pending PO'")->fetch_row()[0] ?? 0;
    $sales_stats['received_client_po'] = $conn->query("SELECT COUNT(*) FROM quotations WHERE status = 'PO Received'")->fetch_row()[0] ?? 0;
}

// 3. PROCUREMENT STATS
$proc_stats = ['total' => 0, 'pending' => 0, 'funded' => 0, 'delivered' => 0];
if ($role === 'Procurement') {
    $proc_stats['total'] = $conn->query("SELECT COUNT(*) FROM purchase_orders")->fetch_row()[0] ?? 0;
    $proc_stats['pending'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Pending', 'GM-Approved', 'Finance-Approved', 'President-Approved')")->fetch_row()[0] ?? 0;
    $proc_stats['funded'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Funded'")->fetch_row()[0] ?? 0;
    $proc_stats['delivered'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Collected'")->fetch_row()[0] ?? 0;
}

// 4. GM & PRESIDENT STATS
$exec_stats = ['active_docs' => 0, 'archived_docs' => 0, 'pending_pr' => 0, 'pending_po' => 0];
if (in_array($role, $executives)) {
    $exec_stats['active_docs'] = $conn->query("SELECT COUNT(*) FROM documents WHERE status = 'Active'")->fetch_row()[0] ?? 0;
    $exec_stats['archived_docs'] = $conn->query("SELECT COUNT(*) FROM documents WHERE status = 'Archived'")->fetch_row()[0] ?? 0;
    
    $exec_stats['pending_pr'] = $conn->query("SELECT COUNT(*) FROM purchase_requests WHERE status = 'Pending'")->fetch_row()[0] ?? 0;
    
    if ($role === 'GM') {
        $exec_stats['pending_po'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending'")->fetch_row()[0] ?? 0;
    } else {
        $exec_stats['pending_po'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Finance-Approved'")->fetch_row()[0] ?? 0;
    }
}

// 5. FINANCE STATS
$finance_stats = ['pending_po' => 0, 'funded_po' => 0, 'invoices' => 0, 'receipts' => 0];
if ($role === 'Finance') {
    $finance_stats['pending_po'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'GM-Approved'")->fetch_row()[0] ?? 0;
    $finance_stats['funded_po'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Funded'")->fetch_row()[0] ?? 0;
    $finance_stats['invoices'] = $conn->query("SELECT COUNT(*) FROM documents WHERE category = 'Invoices' AND status='Active'")->fetch_row()[0] ?? 0;
    $finance_stats['receipts'] = $conn->query("SELECT COUNT(*) FROM documents WHERE category = 'Official receipts' AND status='Active'")->fetch_row()[0] ?? 0;
}

// 6. SUPPLY CHAIN STATS
$sc_stats = ['incoming_po' => 0, 'collected_po' => 0, 'dr_count' => 0, 'supplier_docs' => 0];
if ($role === 'Supply Chain') {
    $sc_stats['incoming_po'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status = 'Funded'")->fetch_row()[0] ?? 0;
    $sc_stats['collected_po'] = $conn->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Collected', 'Delivered')")->fetch_row()[0] ?? 0;
    $sc_stats['dr_count'] = $conn->query("SELECT COUNT(*) FROM documents WHERE category = 'Delivery receipts' AND status='Active'")->fetch_row()[0] ?? 0;
    $sc_stats['supplier_docs'] = $conn->query("SELECT COUNT(*) FROM documents WHERE category = 'Supplier transaction records' AND status='Active'")->fetch_row()[0] ?? 0;
}

// 7. TECHNICAL STATS
$tech_stats = ['tickets' => 0, 'diagnostics' => 0, 'job_orders' => 0, 'total' => 0];
if ($role === 'Technical') {
    $tech_stats['tickets'] = $conn->query("SELECT COUNT(*) FROM documents WHERE category = 'Service tickets' AND status='Active'")->fetch_row()[0] ?? 0;
    $tech_stats['diagnostics'] = $conn->query("SELECT COUNT(*) FROM documents WHERE category = 'Diagnostic reports' AND status='Active'")->fetch_row()[0] ?? 0;
    $tech_stats['job_orders'] = $conn->query("SELECT COUNT(*) FROM documents WHERE category = 'Job orders' AND status='Active'")->fetch_row()[0] ?? 0;
    $tech_stats['total'] = $tech_stats['tickets'] + $tech_stats['diagnostics'] + $tech_stats['job_orders'];
}

// 8. ADMINISTRATIVE / STAFF STATS
$admin_staff_stats = ['leaves' => 0, 'notices' => 0, 'memos' => 0, 'policies' => 0];
if (in_array($role, ['Administrative', 'Staff'])) {
    $admin_staff_stats['leaves'] = $conn->query("SELECT COUNT(*) FROM documents WHERE category = 'Leave forms' AND status='Active'")->fetch_row()[0] ?? 0;
    $admin_staff_stats['notices'] = $conn->query("SELECT COUNT(*) FROM documents WHERE category = 'Employee correspondence and notices' AND status='Active'")->fetch_row()[0] ?? 0;
    $admin_staff_stats['memos'] = $conn->query("SELECT COUNT(*) FROM documents WHERE category = 'Internal memorandums' AND status='Active'")->fetch_row()[0] ?? 0;
    $admin_staff_stats['policies'] = $conn->query("SELECT COUNT(*) FROM documents WHERE category = 'Company policies and procedures' AND status='Active'")->fetch_row()[0] ?? 0;
}


// ==========================================
// NOTIFICATIONS & RETENTION ALERTS
// ==========================================
if ($can_view_retention) {
    if (!isset($_SESSION['alerts_generated_today']) || $_SESSION['alerts_generated_today'] !== date('Y-m-d')) {
        $expiring_query_db = $conn->query("SELECT doc_id, file_name FROM documents WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status = 'Active'");
        
        while ($edoc = $expiring_query_db->fetch_assoc()) {
            $fname = $edoc['file_name'];
            $msg = "Retention Alert: Document '" . $fname . "' requires attention (Expiring/Expired).";
            
            $check_stmt = $conn->prepare("SELECT notif_id FROM notifications WHERE target_role = ? AND message = ?");
            $check_stmt->bind_param("ss", $role, $msg);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows == 0) {
                $insert_stmt = $conn->prepare("INSERT INTO notifications (target_role, message, is_read) VALUES (?, ?, 0)");
                $insert_stmt->bind_param("ss", $role, $msg);
                $insert_stmt->execute();
            }
        }
        $_SESSION['alerts_generated_today'] = date('Y-m-d');
    }
}

$notif_query_str = "SELECT * FROM notifications WHERE target_role = '$role' AND is_read = 0";
if ($role !== 'Admin' && !in_array($role, ['GM', 'President'])) {
    $notif_query_str .= " AND message NOT LIKE 'Retention Alert:%' AND message NOT LIKE 'DSS Alert:%'";
}
$notif_query_str .= " ORDER BY created_at DESC LIMIT 5";
$bell_notifications = $conn->query($notif_query_str);
$total_unread = $bell_notifications->num_rows;


// ==========================================
// GLOBAL TURNAROUND & CHART STATS
// ==========================================
if ($role !== 'Admin') {
    $stats = get_dashboard_stats($conn, $role);

    $turnaround_query = $conn->query("
        SELECT AVG(days) as avg_days FROM (
            SELECT DATEDIFF(MAX(h2.timestamp), MIN(h1.timestamp)) as days
            FROM po_history h1
            JOIN po_history h2 ON h1.po_id = h2.po_id
            WHERE h1.status_to IN ('Pending', 'New') AND h2.status_to IN ('Delivered', 'Collected')
            GROUP BY h1.po_id
        ) as subquery
    ");
    $turnaround = round($turnaround_query->fetch_assoc()['avg_days'] ?? 0, 1);

    $expiring_query = $conn->query("SELECT COUNT(*) as count FROM documents WHERE expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status = 'Active'");
    $expiring_docs = $expiring_query->fetch_assoc()['count'] ?? 0;

    $retention_docs = []; 
    $retention_data = []; 
    $total_tracked = 0; $total_expired = 0; $total_expiring_soon = 0; $total_safe = 0;
    
    $retention_search = $_GET['retention_search'] ?? '';
    $retention_filter = $_GET['retention_filter'] ?? 'All';

    if ($can_view_retention) {
        $retention_where = "WHERE d.expiry_date IS NOT NULL AND d.status = 'Active'";
        $params = [];
        $types = "";

        if (!empty($retention_search)) {
            $retention_where .= " AND d.file_name LIKE ?";
            $search_param = "%" . $retention_search . "%";
            $params[] = $search_param;
            $types .= "s";
        }

        $retention_query = "
            SELECT d.*, p.po_number, u.full_name 
            FROM documents d 
            LEFT JOIN purchase_orders p ON d.po_id = p.po_id 
            LEFT JOIN users u ON d.uploaded_by = u.user_id 
            $retention_where
            ORDER BY d.expiry_date ASC";

        $stmt = $conn->prepare($retention_query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $retention_result = $stmt->get_result();

        if($retention_result && $retention_result->num_rows > 0) {
            $current_date_obj = new DateTime(date('Y-m-d')); 
            while($row = $retention_result->fetch_assoc()) {
                $total_tracked++;
                $exp_date_obj = new DateTime(date('Y-m-d', strtotime($row['expiry_date'])));
                $diff = $current_date_obj->diff($exp_date_obj);
                $days_left = (int)$diff->format('%r%a');
                
                $doc_status = "Safe";
                if ($days_left <= 0) { $doc_status = "Expired"; $total_expired++; } 
                elseif ($days_left <= 30) { $doc_status = "Expiring"; $total_expiring_soon++; } 
                else { $total_safe++; }

                if ($retention_filter === 'All' || $retention_filter === $doc_status) {
                    $row['days_left'] = $days_left;
                    $row['retention_status'] = $doc_status;
                    $retention_docs[] = $row;
                }
            }
            $retention_data = $retention_docs; 
        }
    }
}

// ==========================================
// DYNAMIC ROLE-BASED DASHBOARD RECENT FILES 
// ==========================================
$rbac_categories = [];
$all_cats = [];

// Kinukuha mula sa database lahat ng assigned roles sa halip na i-hardcode.
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
        WHERE d.status = 'Active' AND d.category IN ($placeholders) 
        ORDER BY d.uploaded_at DESC LIMIT 5";
    $stmt_rf = $conn->prepare($q_str);
    if ($stmt_rf) {
        $types_rf = str_repeat('s', count($user_categories));
        $stmt_rf->bind_param($types_rf, ...$user_categories);
        $stmt_rf->execute();
        $recent_dashboard_files = $stmt_rf->get_result();
    }
}
?>