<?php
require '../config/db_connect.php';

header('Content-Type: application/json');
error_reporting(0); 

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized Access. Please login.']);
    exit();
}

$action = $_GET['action'] ?? '';
$period = $_GET['period'] ?? 'all'; 
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

function getDateFilter($column, $period) {
    $start = $_GET['start'] ?? '';
    $end = $_GET['end'] ?? '';
    
    switch ($period) {
        case 'today': return "DATE($column) = CURDATE()";
        case 'this_week': return "YEARWEEK($column, 1) = YEARWEEK(CURDATE(), 1)";
        case 'this_month': return "MONTH($column) = MONTH(CURDATE()) AND YEAR($column) = YEAR(CURDATE())";
        case 'this_year': return "YEAR($column) = YEAR(CURDATE())";
        case 'custom':
            if(!empty($start) && !empty($end)) {
                $s = date('Y-m-d', strtotime($start));
                $e = date('Y-m-d', strtotime($end));
                return "DATE($column) BETWEEN '$s' AND '$e'";
            }
            return "1=1";
        default: return "1=1"; 
    }
}

// ==========================================
// FINANCE & PRESIDENT (SALES / REVENUE)
// ==========================================
if ($action === 'revenue_forecast') {
    $date_filter = getDateFilter('date_created', $period);
    $query = "SELECT DATE_FORMAT(date_created, '%Y-%m') as month, SUM(amount) as total FROM purchase_orders WHERE status NOT IN ('Rejected', 'Invalid') AND $date_filter GROUP BY month ORDER BY month ASC";
    $result = $conn->query($query);
    
    $historical_labels = []; $historical_data = [];
    if ($result) {
        while($row = $result->fetch_assoc()){
            $historical_labels[] = $row['month'];
            $historical_data[] = (float)$row['total'];
        }
    }
    
    $n = count($historical_data);
    $forecast_labels = []; $forecast_data = [];
    if($n > 1) { 
        $sumX = 0; $sumY = 0; $sumXY = 0; $sumX2 = 0;
        for($i = 0; $i < $n; $i++) {
            $x = $i + 1; $y = $historical_data[$i];
            $sumX += $x; $sumY += $y; $sumXY += ($x * $y); $sumX2 += ($x * $x);
        }
        $denominator = (($n * $sumX2) - ($sumX * $sumX));
        if ($denominator != 0) {
            $m = (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
            $b = ($sumY - ($m * $sumX)) / $n;
            $last_month = end($historical_labels);
            $date = new DateTime($last_month . '-01');
            for($i = 1; $i <= 3; $i++) {
                $date->modify('+1 month');
                $forecast_labels[] = $date->format('M Y'); 
                $forecast_data[] = max(0, round(($m * ($n + $i)) + $b, 2)); 
            }
        }
    }
    echo json_encode(['status' => 'success', 'historical' => ['labels' => $historical_labels, 'data' => $historical_data], 'forecast' => ['labels' => $forecast_labels, 'data' => $forecast_data]]);
    exit();
}
elseif ($action === 'top_clients') {
    $date_filter = getDateFilter('date_created', $period);
    $query = "SELECT client_name, SUM(amount) as total_revenue FROM purchase_orders WHERE status NOT IN ('Rejected', 'Invalid') AND $date_filter GROUP BY client_name ORDER BY total_revenue DESC LIMIT 5";
    $result = $conn->query($query);
    $labels = []; $data = [];
    if ($result) { while($row = $result->fetch_assoc()){ $labels[] = $row['client_name']; $data[] = (float)$row['total_revenue']; } }
    echo json_encode(['status' => 'success', 'labels' => $labels, 'data' => $data]);
    exit();
}
elseif ($action === 'top_categories') {
    $date_filter = getDateFilter('o.date_created', $period);
    $query = "SELECT p.category, SUM(p.total_price) as revenue FROM po_items p JOIN purchase_orders o ON p.po_id = o.po_id WHERE o.status NOT IN ('Rejected', 'Invalid') AND $date_filter GROUP BY p.category ORDER BY revenue DESC";
    $category_map = ['01' => 'Hardware', '02' => 'CCTV', '03' => 'Peripherals', '04' => 'Office Supplies', '05' => 'WIFI / LAN', '06' => 'Printers'];
    $result = $conn->query($query);
    $labels = []; $data = [];
    if ($result) { while($row = $result->fetch_assoc()){ $cat_code = $row['category']; $labels[] = isset($category_map[$cat_code]) ? $category_map[$cat_code] : ($cat_code ?: 'Uncategorized'); $data[] = (float)$row['revenue']; } }
    echo json_encode(['status' => 'success', 'labels' => $labels, 'data' => $data]);
    exit();
}

// ==========================================
// GM, PRESIDENT, & PROCUREMENT (RECORDS & WORKFLOW)
// ==========================================
elseif ($action === 'bottleneck_analysis') {
    $date_filter = getDateFilter('timestamp', $period);
    $query = "SELECT po_id, status_to, timestamp FROM po_history WHERE $date_filter ORDER BY po_id ASC, timestamp ASC";
    $result = $conn->query($query);
    $po_data = [];
    if ($result) { while($row = $result->fetch_assoc()) { $po_data[$row['po_id']][] = ['status' => $row['status_to'], 'time' => strtotime($row['timestamp'])]; } }
    
    $department_times = ['GM' => [], 'Finance' => [], 'President' => [], 'Funding' => []];
    foreach ($po_data as $po_id => $history) {
        for($i = 0; $i < count($history) - 1; $i++) {
            $current = $history[$i]; $next = $history[$i+1];
            $diff_hours = ($next['time'] - $current['time']) / 3600; 
            if ($current['status'] == 'Pending') $department_times['GM'][] = $diff_hours;
            elseif ($current['status'] == 'GM-Approved') $department_times['Finance'][] = $diff_hours;
            elseif ($current['status'] == 'Finance-Approved') $department_times['President'][] = $diff_hours;
            elseif ($current['status'] == 'President-Approved') $department_times['Funding'][] = $diff_hours;
        }
    }
    
    $labels = []; $data = [];
    foreach ($department_times as $dept => $times) {
        $labels[] = $dept;
        $data[] = (count($times) > 0) ? round(array_sum($times) / count($times), 2) : 0;
    }
    echo json_encode(['status' => 'success', 'labels' => $labels, 'data' => $data]);
    exit();
}
elseif ($action === 'workload_distribution') {
    $date_filter = getDateFilter('date_created', $period);
    $query = "SELECT current_location, COUNT(po_id) as pending_count FROM purchase_orders WHERE status NOT IN ('Collected', 'Rejected', 'Invalid') AND current_location NOT IN ('Closed', '', 'Voided') AND $date_filter GROUP BY current_location ORDER BY pending_count DESC";
    $result = $conn->query($query);
    $labels = []; $data = [];
    if ($result) { while($row = $result->fetch_assoc()){ $labels[] = $row['current_location']; $data[] = (int)$row['pending_count']; } }
    echo json_encode(['status' => 'success', 'labels' => $labels, 'data' => $data]);
    exit();
}
elseif ($action === 'rejection_rate') {
    $date_filter = getDateFilter('date_created', $period);
    $query = "SELECT SUM(CASE WHEN status IN ('Rejected', 'Invalid') THEN 1 ELSE 0 END) as rejected_count, SUM(CASE WHEN status NOT IN ('Rejected', 'Invalid') THEN 1 ELSE 0 END) as passed_count FROM purchase_orders WHERE $date_filter";
    $result = $conn->query($query);
    $labels = ['Approved / Active', 'Rejected / Rework']; $data = [0, 0];
    if ($result && $row = $result->fetch_assoc()) { $data = [(int)$row['passed_count'], (int)$row['rejected_count']]; }
    echo json_encode(['status' => 'success', 'labels' => $labels, 'data' => $data]);
    exit();
}
elseif ($action === 'doc_retention_status') {
    $date_filter = getDateFilter('uploaded_at', $period);
    $query = "SELECT 
                SUM(CASE WHEN status = 'Active' AND disposition_status != 'Ready for Disposition' THEN 1 ELSE 0 END) as active_docs,
                SUM(CASE WHEN status = 'Archived' AND disposition_status != 'Ready for Disposition' THEN 1 ELSE 0 END) as archived_docs,
                SUM(CASE WHEN disposition_status = 'Ready for Disposition' THEN 1 ELSE 0 END) as disposition_docs
              FROM documents WHERE $date_filter";
    $result = $conn->query($query);
    $labels = ['Active Records', 'Archived', 'For Disposition'];
    $data = [0, 0, 0];
    if ($result && $row = $result->fetch_assoc()) {
        $data = [(int)$row['active_docs'], (int)$row['archived_docs'], (int)$row['disposition_docs']];
    }
    echo json_encode(['status' => 'success', 'labels' => $labels, 'data' => $data]);
    exit();
}

// ==========================================
// ADMIN ANALYTICS
// ==========================================
elseif ($action === 'admin_user_roles') {
    $query = "SELECT role, COUNT(*) as count FROM users GROUP BY role";
    $result = $conn->query($query);
    $labels = []; $data = [];
    if ($result) { while($row = $result->fetch_assoc()) { $labels[] = $row['role']; $data[] = (int)$row['count']; } }
    echo json_encode(['status' => 'success', 'labels' => $labels, 'data' => $data]);
    exit();
}
elseif ($action === 'admin_audit_activity') {
    $date_filter = getDateFilter('timestamp', $period);
    $query = "SELECT DATE_FORMAT(timestamp, '%b %d') as date, COUNT(*) as count FROM audit_logs WHERE $date_filter GROUP BY DATE(timestamp) ORDER BY DATE(timestamp) DESC LIMIT 10";
    $result = $conn->query($query);
    $labels = []; $data = [];
    if ($result) { while($row = $result->fetch_assoc()) { array_unshift($labels, $row['date']); array_unshift($data, (int)$row['count']); } }
    echo json_encode(['status' => 'success', 'labels' => $labels, 'data' => $data]);
    exit();
}

// ==========================================
// SALES STAFF ANALYTICS
// ==========================================
elseif ($action === 'sales_pr_status') {
    $date_filter = getDateFilter('date_created', $period);
    $query = "SELECT status, COUNT(*) as count FROM purchase_requests WHERE created_by = $user_id AND $date_filter GROUP BY status";
    $result = $conn->query($query);
    $labels = []; $data = [];
    if ($result) { while($row = $result->fetch_assoc()) { $labels[] = $row['status']; $data[] = (int)$row['count']; } }
    echo json_encode(['status' => 'success', 'labels' => $labels, 'data' => $data]);
    exit();
}
elseif ($action === 'sales_performance') {
    $date_filter = getDateFilter('date_created', $period);
    $query = "SELECT DATE_FORMAT(date_created, '%b %Y') as month, COUNT(*) as count FROM purchase_requests WHERE created_by = $user_id AND $date_filter GROUP BY month ORDER BY month ASC LIMIT 6";
    $result = $conn->query($query);
    $labels = []; $data = [];
    if ($result) { while($row = $result->fetch_assoc()) { $labels[] = $row['month']; $data[] = (int)$row['count']; } }
    echo json_encode(['status' => 'success', 'labels' => $labels, 'data' => $data]);
    exit();
}

// ==========================================
// PROCUREMENT / SUPPLY CHAIN ANALYTICS
// ==========================================
elseif ($action === 'proc_po_status') {
    $date_filter = getDateFilter('date_created', $period);
    $query = "SELECT status, COUNT(*) as count FROM purchase_orders WHERE $date_filter GROUP BY status";
    $result = $conn->query($query);
    $labels = []; $data = [];
    if ($result) { while($row = $result->fetch_assoc()) { $labels[] = $row['status']; $data[] = (int)$row['count']; } }
    echo json_encode(['status' => 'success', 'labels' => $labels, 'data' => $data]);
    exit();
}

// ==========================================
// DOCUMENT HANDLERS ANALYTICS (TECH/ADMIN STAFF)
// ==========================================
elseif ($action === 'docs_category') {
    $date_filter = getDateFilter('uploaded_at', $period);
    $query = "SELECT category, COUNT(*) as count FROM documents WHERE status='Active' AND $date_filter GROUP BY category ORDER BY count DESC LIMIT 6";
    $result = $conn->query($query);
    $labels = []; $data = [];
    if ($result) { while($row = $result->fetch_assoc()) { $labels[] = $row['category']; $data[] = (int)$row['count']; } }
    echo json_encode(['status' => 'success', 'labels' => $labels, 'data' => $data]);
    exit();
}

echo json_encode(['error' => 'Invalid Action parameter provided.']);
exit();
?>