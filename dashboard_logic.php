<?php 
require 'config/db_connect.php'; 
require 'config/functions.php';
require_once 'config/workflow_access.php';

drms_require_login();

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
$po_collection_date = getDateFilter('po.date_created', $period);
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

// ====================================================
// SHARED COLLECTION DECISION-SUPPORT POSITION
// ====================================================
// Clean-start collection metrics use the independent collection_status field
// and verified payment ledger. Operational PO status remains Delivered after
// handover, regardless of whether the client is unpaid, partially paid, or paid.
$collection_dss = [
    'outstanding_amount' => 0.0,
    'open_count' => 0,
    'overdue_amount' => 0.0,
    'overdue_count' => 0,
    'due_soon_amount' => 0.0,
    'due_soon_count' => 0,
    'missing_due_amount' => 0.0,
    'missing_due_count' => 0,
    'collected_value' => 0.0,
    'receivable_value' => 0.0,
    'collection_rate' => 0.0,
];

if ($can_view_financials) {
    try {
        $collection_position_sql = "SELECT
                COALESCE(SUM(
                    CASE
                        WHEN position.collection_status IN ('Unpaid', 'Partially Paid')
                         AND position.balance > 0
                        THEN position.balance
                        ELSE 0
                    END
                ), 0) AS outstanding_amount,
                SUM(
                    CASE
                        WHEN position.collection_status IN ('Unpaid', 'Partially Paid')
                         AND position.balance > 0
                        THEN 1
                        ELSE 0
                    END
                ) AS open_count,
                COALESCE(SUM(
                    CASE
                        WHEN position.collection_status IN ('Unpaid', 'Partially Paid')
                         AND position.balance > 0
                         AND position.due_date < CURDATE()
                        THEN position.balance
                        ELSE 0
                    END
                ), 0) AS overdue_amount,
                SUM(
                    CASE
                        WHEN position.collection_status IN ('Unpaid', 'Partially Paid')
                         AND position.balance > 0
                         AND position.due_date < CURDATE()
                        THEN 1
                        ELSE 0
                    END
                ) AS overdue_count,
                COALESCE(SUM(
                    CASE
                        WHEN position.collection_status IN ('Unpaid', 'Partially Paid')
                         AND position.balance > 0
                         AND position.due_date BETWEEN CURDATE()
                             AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
                        THEN position.balance
                        ELSE 0
                    END
                ), 0) AS due_soon_amount,
                SUM(
                    CASE
                        WHEN position.collection_status IN ('Unpaid', 'Partially Paid')
                         AND position.balance > 0
                         AND position.due_date BETWEEN CURDATE()
                             AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
                        THEN 1
                        ELSE 0
                    END
                ) AS due_soon_count,
                COALESCE(SUM(
                    CASE
                        WHEN position.collection_status IN ('Unpaid', 'Partially Paid')
                         AND position.balance > 0
                         AND position.due_date IS NULL
                        THEN position.balance
                        ELSE 0
                    END
                ), 0) AS missing_due_amount,
                SUM(
                    CASE
                        WHEN position.collection_status IN ('Unpaid', 'Partially Paid')
                         AND position.balance > 0
                         AND position.due_date IS NULL
                        THEN 1
                        ELSE 0
                    END
                ) AS missing_due_count,
                COALESCE(SUM(position.collected_value), 0) AS collected_value,
                COALESCE(SUM(position.amount), 0) AS receivable_value
            FROM (
                SELECT
                    base.po_id,
                    base.status,
                    base.collection_status,
                    base.amount,
                    base.collected_value,
                    base.due_date,
                    GREATEST(base.amount - base.collected_value, 0) AS balance
                FROM (
                    SELECT
                        po.po_id,
                        po.status,
                        po.collection_status,
                        po.amount,
                        LEAST(
                            COALESCE(payment_summary.total_paid, 0),
                            po.amount
                        ) AS collected_value,
                        COALESCE(
                            NULLIF(receipt.collection_due_date, ''),
                            NULLIF(po.expected_collection_date, '')
                        ) AS due_date
                    FROM purchase_orders po
                    LEFT JOIN (
                        SELECT po_id, SUM(amount_paid) AS total_paid
                        FROM payments
                        GROUP BY po_id
                    ) payment_summary
                        ON payment_summary.po_id = po.po_id
                    LEFT JOIN po_delivery_receipts receipt
                        ON receipt.delivery_receipt_id = (
                            SELECT MAX(receipt_candidate.delivery_receipt_id)
                            FROM po_delivery_receipts receipt_candidate
                            WHERE receipt_candidate.po_id = po.po_id
                              AND receipt_candidate.record_status = 'Active'
                        )
                    WHERE po.status = 'Delivered'
                      AND {$po_collection_date['sql']}
                ) base
            ) position";

        $collection_result = fetch_chart_data(
            $conn,
            $collection_position_sql,
            $po_collection_date['types'],
            $po_collection_date['params'],
            true
        );

        foreach ($collection_dss as $key => $default_value) {
            if ($key !== 'collection_rate' && isset($collection_result[$key])) {
                $collection_dss[$key] = is_int($default_value)
                    ? (int) $collection_result[$key]
                    : (float) $collection_result[$key];
            }
        }
        if ($collection_dss['receivable_value'] > 0) {
            $collection_dss['collection_rate'] = min(
                max(
                    ($collection_dss['collected_value'] /
                        $collection_dss['receivable_value']) * 100,
                    0
                ),
                100
            );
        }
    } catch (Throwable $error) {
        error_log(
            'Phase 5F collection DSS calculation failed: ' .
            $error->getMessage()
        );
    }
}

// ====================================================
// SHARED DISPOSAL REPORT (DSS) IMPLEMENTATION
// ====================================================
$q_disp_cat = "SELECT d.category, COUNT(*) as cnt FROM documents d WHERE d.disposition_status = 'Ready for Disposition' AND {$doc_date['sql']} GROUP BY d.category ORDER BY cnt DESC LIMIT 5";
$disp_cat_data = fetch_chart_data($conn, $q_disp_cat, $doc_date['types'], $doc_date['params'], false);

$q_disp_action = "SELECT COALESCE(p.action_after_retention, 'Review Required') as action_type, COUNT(d.doc_id) as cnt FROM documents d LEFT JOIN document_categories dc ON d.category = dc.sub_category LEFT JOIN retention_policies p ON dc.policy_id = p.policy_id WHERE d.disposition_status = 'Ready for Disposition' AND {$doc_date['sql']} GROUP BY p.action_after_retention";
$disp_action_data = fetch_chart_data($conn, $q_disp_action, $doc_date['types'], $doc_date['params'], false);

$q_disp_hist = "SELECT DATE(timestamp) as disp_date, 
                SUM(CASE WHEN action_type = 'ARCHIVE_FILE' THEN 1 ELSE 0 END) as archived_count,
                SUM(CASE WHEN action_type IN ('DELETE', 'DELETE_DOC', 'DELETE_FILE', 'DESTROY_FILE') THEN 1 ELSE 0 END) as destroyed_count
                FROM audit_logs 
                WHERE action_type IN ('ARCHIVE_FILE', 'DELETE', 'DELETE_DOC', 'DELETE_FILE', 'DESTROY_FILE') AND {$audit_date['sql']}
                GROUP BY disp_date ORDER BY disp_date DESC LIMIT 14";
$disp_hist_data = array_reverse(fetch_chart_data($conn, $q_disp_hist, $audit_date['types'], $audit_date['params'], false));

$shared_disposal_dss = [
    'by_category' => $disp_cat_data,
    'by_action' => $disp_action_data,
    'history' => $disp_hist_data
];


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

    $admin_charts['disposal'] = $shared_disposal_dss;

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
$sales_stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'pending_quotations' => 0, 'awaiting_gm_client_po' => 0, 'received_client_po' => 0];
$sales_charts = [];

if ($is_sales_staff) {
    $sales_stats['total'] = get_count($conn, "SELECT COUNT(*) FROM purchase_requests WHERE {$pr_date['sql']}", $pr_date['types'], $pr_date['params']);
    $sales_stats['pending'] = get_count($conn, "SELECT COUNT(*) FROM purchase_requests WHERE status = 'Pending' AND {$pr_date['sql']}", $pr_date['types'], $pr_date['params']);
    $sales_stats['approved'] = get_count($conn, "SELECT COUNT(*) FROM purchase_requests WHERE status IN ('Approved', 'Converted_to_PO') AND {$pr_date['sql']}", $pr_date['types'], $pr_date['params']);
    $sales_stats['rejected'] = get_count($conn, "SELECT COUNT(*) FROM purchase_requests WHERE status = 'Rejected' AND {$pr_date['sql']}", $pr_date['types'], $pr_date['params']);
    
    $sales_stats['pending_quotations'] = get_count($conn, "SELECT COUNT(*) FROM quotations WHERE status = 'Pending Approval' AND {$q_date['sql']}", $q_date['types'], $q_date['params']);
    $sales_stats['awaiting_gm_client_po'] = get_count($conn, "SELECT COUNT(*) FROM quotations WHERE status = 'For GM Acknowledgement' AND {$q_date['sql']}", $q_date['types'], $q_date['params']);
    $sales_stats['received_client_po'] = get_count($conn, "SELECT COUNT(*) FROM quotations WHERE status = 'PO Received' AND {$q_date['sql']}", $q_date['types'], $q_date['params']);

    $q_pr_status = "SELECT status, COUNT(*) as count FROM purchase_requests WHERE {$pr_date['sql']} GROUP BY status";
    $sales_charts['pr_status'] = fetch_chart_data($conn, $q_pr_status, $pr_date['types'], $pr_date['params'], false);

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

    $q_top_clients_sales = "SELECT client_name, COUNT(*) as total_tx FROM purchase_requests WHERE status != 'Rejected' AND {$pr_date['sql']} GROUP BY client_name ORDER BY total_tx DESC LIMIT 5";
    $sales_charts['top_clients'] = fetch_chart_data($conn, $q_top_clients_sales, $pr_date['types'], $pr_date['params'], false);

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
    
    $q_rejected = "SELECT pr_number, client_name FROM purchase_requests WHERE status = 'Rejected' AND {$pr_date['sql']} ORDER BY date_created DESC LIMIT 1";
    $sales_charts['latest_rejected'] = fetch_chart_data($conn, $q_rejected, $pr_date['types'], $pr_date['params'], true);
    
    $q_total_sales_val = "SELECT SUM(amount) as total FROM purchase_requests WHERE status IN ('Approved', 'Converted_to_PO') AND {$pr_date['sql']}";
    $sales_val_res = fetch_chart_data($conn, $q_total_sales_val, $pr_date['types'], $pr_date['params'], true);
    $sales_charts['total_approved_val'] = $sales_val_res['total'] ?? 0;
}

// ==========================================
// PROCUREMENT STATS & CHARTS
// ==========================================
$proc_stats = [
    'total' => 0,
    'ready_prf' => 0,
    'pending' => 0,
    'funded' => 0,
    'delivered' => 0,
];
$proc_charts = [];

if ($role === 'Procurement') {
    $proc_stats['ready_prf'] = get_count(
        $conn,
        "SELECT COUNT(*)
         FROM purchase_requests
         WHERE status = 'Approved'
           AND {$pr_date['sql']}",
        $pr_date['types'],
        $pr_date['params']
    );
    $proc_stats['total'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $proc_stats['pending'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Pending', 'GM-Approved', 'Finance-Approved', 'President-Approved') AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $proc_stats['funded'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Funded' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $proc_stats['delivered'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Delivered' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);

    $q_status = "SELECT status, COUNT(*) as count FROM purchase_orders WHERE {$po_date['sql']} GROUP BY status";
    $proc_charts['status_dist'] = fetch_chart_data($conn, $q_status, $po_date['types'], $po_date['params'], false);

    $q_trend = "SELECT DATE(date_created) as t_date, COUNT(*) as po_count FROM purchase_orders WHERE {$po_date['sql']} GROUP BY t_date ORDER BY t_date DESC LIMIT 14";
    $raw_trend = fetch_chart_data($conn, $q_trend, $po_date['types'], $po_date['params'], false);
    $proc_charts['trend'] = array_reverse($raw_trend);

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
$exec_stats = ['active_docs' => 0, 'archived_docs' => 0, 'pending_pr' => 0, 'pending_po' => 0, 'pending_client_po_ack' => 0];

if (in_array($role, $executives)) {
    $exec_stats['active_docs'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE status = 'Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    $exec_stats['archived_docs'] = get_count($conn, "SELECT COUNT(*) FROM documents WHERE status = 'Archived' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);
    if ($role === 'GM') {
        $pr_queue_condition = "current_approval_stage = 'GM Review'";
    } else {
        $pr_queue_condition = "current_approval_stage = 'Owner Approval'";
    }

    $exec_stats['pending_pr'] = get_count(
        $conn,
        "SELECT COUNT(*)
         FROM purchase_requests
         WHERE status = 'Pending'
           AND {$pr_queue_condition}
           AND {$pr_date['sql']}",
        $pr_date['types'],
        $pr_date['params']
    );
    
    if ($role === 'GM') {
        $exec_stats['pending_client_po_ack'] = get_count(
            $conn,
            "SELECT COUNT(*)
             FROM quotations
             WHERE status = 'For GM Acknowledgement'
               AND {$q_date['sql']}",
            $q_date['types'],
            $q_date['params']
        );
        $exec_stats['pending_po'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Pending' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    } else {
        $exec_stats['pending_po'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Finance-Approved' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    }
}

$sc_stats = ['ready_for_delivery' => 0, 'delivered' => 0, 'awaiting_collection' => 0, 'completed_collections' => 0, 'delivery_proofs' => 0];
$sc_charts = ['status_dist' => [], 'delivery_trend' => [], 'top_clients' => [], 'proof_coverage' => []];

if ($role === 'Supply Chain') {
    $sc_stats['ready_for_delivery'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Delivery Requested', 'For Pick-up/Delivery') AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $sc_stats['delivered'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Delivered' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $sc_stats['awaiting_collection'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Delivered' AND collection_status IN ('Unpaid', 'Partially Paid') AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $sc_stats['completed_collections'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Delivered' AND collection_status = 'Paid' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $sc_stats['delivery_proofs'] = get_count($conn, "SELECT COUNT(DISTINCT po_id) FROM documents WHERE po_id IS NOT NULL AND doc_type = 'Proof of Delivery' AND status = 'Active' AND {$doc_date['sql']}", $doc_date['types'], $doc_date['params']);

    $q_sc_status = "SELECT status, COUNT(*) AS total FROM purchase_orders WHERE status IN ('Delivery Requested', 'For Pick-up/Delivery', 'Delivered') AND {$po_date['sql']} GROUP BY status";
    $sc_charts['status_dist'] = fetch_chart_data($conn, $q_sc_status, $po_date['types'], $po_date['params'], false);

    $q_sc_trend = "SELECT DATE(COALESCE(actual_delivery_date, date_created)) AS delivery_date, COUNT(*) AS total FROM purchase_orders WHERE status = 'Delivered' AND {$po_date['sql']} GROUP BY delivery_date ORDER BY delivery_date DESC LIMIT 14";
    $sc_charts['delivery_trend'] = array_reverse(fetch_chart_data($conn, $q_sc_trend, $po_date['types'], $po_date['params'], false));

    $q_sc_clients = "SELECT client_name, COUNT(*) AS total FROM purchase_orders WHERE status = 'Delivered' AND {$po_date['sql']} GROUP BY client_name ORDER BY total DESC LIMIT 5";
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
        $ws_sql = "SELECT po_id as id, po_number as number, client_name, amount, status, current_location, date_created FROM purchase_orders WHERE status NOT IN ('Delivered', 'Rejected', 'Invalid') AND {$po_date['sql']} ORDER BY date_created DESC LIMIT 10";
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

// ====================================================
// GM-EXCLUSIVE CONFIDENTIAL FOLDER LOGIC (DASHBOARD WIDGETS)
// ====================================================
$is_top_mgmt = in_array($role, ['Admin', 'GM', 'President']);
$user_categories = [];

if ($is_top_mgmt) {
    if ($role === 'GM') {
        $user_categories = $all_cats;
    } else {
        // Automatically hide the Finalized Scans categories from Admin and President
        $gm_cats = [];
        $q_gm_cats = $conn->query("SELECT sub_category FROM document_categories WHERE parent_category = 'Finalized Scans'");
        if ($q_gm_cats) {
            while($r = $q_gm_cats->fetch_assoc()) $gm_cats[] = $r['sub_category'];
        }
        foreach($all_cats as $c) {
            if (!in_array($c, $gm_cats)) {
                $user_categories[] = $c;
            }
        }
    }
} else {
    $user_categories = $rbac_categories[$role] ?? [];
}

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
            UNION ALL SELECT DATE(timestamp), 0, 0, 1, 0, 0 FROM po_history WHERE status_to IN ('Funded', 'Delivery Requested', 'For Pick-up/Delivery', 'Delivered') AND {$po_hist_date['sql']}
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

    $gm_charts['uncollected'] = [
        'total_uncollected' => $collection_dss['outstanding_amount'],
        'count_uncollected' => $collection_dss['open_count'],
        'overdue_amount' => $collection_dss['overdue_amount'],
        'overdue_count' => $collection_dss['overdue_count'],
        'due_soon_amount' => $collection_dss['due_soon_amount'],
        'due_soon_count' => $collection_dss['due_soon_count'],
        'missing_due_amount' => $collection_dss['missing_due_amount'],
        'missing_due_count' => $collection_dss['missing_due_count'],
    ];
    
    $q_aging = "SELECT p.po_number, p.status, p.current_location, TIMESTAMPDIFF(HOUR, COALESCE((SELECT MAX(timestamp) FROM po_history ph WHERE ph.po_id = p.po_id), p.date_created), NOW()) as hours_stagnant FROM purchase_orders p WHERE p.status NOT IN ('Delivered', 'Rejected', 'Invalid') AND {$po_date['sql']} ORDER BY hours_stagnant DESC LIMIT 1";
    $gm_charts['aging_po'] = fetch_chart_data($conn, $q_aging, $po_date['types'], $po_date['params'], true);
    
    $q_quote_conv = "SELECT COUNT(*) as total_quotes, SUM(CASE WHEN status IN ('PO Received', 'Converted to PR') THEN 1 ELSE 0 END) as converted_quotes FROM quotations WHERE {$q_date['sql']}";
    $gm_charts['quote_conversion'] = fetch_chart_data($conn, $q_quote_conv, $q_date['types'], $q_date['params'], true);

    $q_top_client = "SELECT client_name, COUNT(*) as tx_count FROM purchase_orders WHERE {$po_date['sql']} GROUP BY client_name ORDER BY tx_count DESC LIMIT 1";
    $gm_charts['top_client'] = fetch_chart_data($conn, $q_top_client, $po_date['types'], $po_date['params'], true);

    $q_retrieval = "SELECT description, COUNT(*) as freq, action_type
                    FROM audit_logs
                    WHERE action_type IN ('DOWNLOAD_DOC', 'VIEW_RECORD')
                    AND {$audit_date['sql']}
                    GROUP BY description, action_type
                    ORDER BY freq DESC LIMIT 7";
    $retrieval_data = fetch_chart_data($conn, $q_retrieval, $audit_date['types'], $audit_date['params'], false);

    $clean_retrieval = [];
    foreach($retrieval_data as $row) {
        $label = $row['description'];
        if ($row['action_type'] == 'DOWNLOAD_DOC') {
            $label = str_replace('Downloaded document: ', '', $label);
            $label = preg_replace('/^\d+_[a-z0-9]+_/', '', $label);
        } else if ($row['action_type'] == 'VIEW_RECORD') {
            $label = str_replace('Viewed details of ', '', $label);
            $label = str_replace('Viewed record details in ', '', $label);
            $label = preg_replace('/ \| Parameters.*/', '', $label);
        }
        
        if (strlen($label) > 35) $label = substr($label, 0, 32) . '...';

        $found = false;
        foreach($clean_retrieval as &$existing) {
            if($existing['label'] === $label) {
                $existing['count'] += $row['freq'];
                $found = true;
                break;
            }
        }
        if(!$found) {
            $clean_retrieval[] = [
                'label' => $label,
                'count' => $row['freq'],
                'type'  => $row['action_type']
            ];
        }
    }
    usort($clean_retrieval, function($a, $b) { return $b['count'] <=> $a['count']; });
    $gm_charts['retrieval_freq'] = array_slice($clean_retrieval, 0, 7);

    $gm_charts['disposal'] = $shared_disposal_dss;
}

$finance_charts = [];
$finance_stats = [
    'pending_prf' => 0,
    'pending_po' => 0,
    'funded_po' => 0,
    'uncollected_amount' => 0,
    'collected_value' => 0,
    'collection_rate' => 0,
    'open_collection_count' => 0,
    'overdue_amount' => 0,
    'overdue_count' => 0,
    'due_soon_amount' => 0,
    'due_soon_count' => 0,
    'missing_due_amount' => 0,
    'missing_due_count' => 0,
];

if ($role === 'Finance') {
    $finance_stats['pending_prf'] = get_count(
        $conn,
        "SELECT COUNT(*)
         FROM purchase_requests
         WHERE status = 'Pending'
           AND current_approval_stage = 'Finance Review'
           AND {$pr_date['sql']}",
        $pr_date['types'],
        $pr_date['params']
    );
    $finance_stats['pending_po'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'GM-Approved' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    $finance_stats['funded_po'] = get_count($conn, "SELECT COUNT(*) FROM purchase_orders WHERE status = 'Funded' AND {$po_date['sql']}", $po_date['types'], $po_date['params']);
    
    $finance_stats['uncollected_amount'] = $collection_dss['outstanding_amount'];
    $finance_stats['collected_value'] = $collection_dss['collected_value'];
    $finance_stats['collection_rate'] = $collection_dss['collection_rate'];
    $finance_stats['open_collection_count'] = $collection_dss['open_count'];
    $finance_stats['overdue_amount'] = $collection_dss['overdue_amount'];
    $finance_stats['overdue_count'] = $collection_dss['overdue_count'];
    $finance_stats['due_soon_amount'] = $collection_dss['due_soon_amount'];
    $finance_stats['due_soon_count'] = $collection_dss['due_soon_count'];
    $finance_stats['missing_due_amount'] = $collection_dss['missing_due_amount'];
    $finance_stats['missing_due_count'] = $collection_dss['missing_due_count'];

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
    $q_out = "SELECT DATE_FORMAT(released_at, '%Y-%m') as m, SUM(released_amount) as val FROM po_supplier_fund_releases WHERE record_status = 'Active' GROUP BY m";
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

    $q_stacked = "SELECT
            po.client_name,
            SUM(po.amount) AS total_revenue,
            SUM(
                LEAST(
                    COALESCE(payment_summary.total_paid, 0),
                    po.amount
                )
            ) AS collected_amount
        FROM purchase_orders po
        LEFT JOIN (
            SELECT po_id, SUM(amount_paid) AS total_paid
            FROM payments
            GROUP BY po_id
        ) payment_summary
            ON payment_summary.po_id = po.po_id
        WHERE po.status = 'Delivered'
          AND {$po_collection_date['sql']}
        GROUP BY po.client_name
        ORDER BY total_revenue DESC
        LIMIT 5";
    $stacked_data = fetch_chart_data(
        $conn,
        $q_stacked,
        $po_collection_date['types'],
        $po_collection_date['params'],
        false
    );
    
    $tc_labels = []; $tc_col = []; $tc_uncol = [];
    foreach($stacked_data as $t) { $tc_labels[] = $t['client_name']; $tc_col[] = (float)$t['collected_amount']; $tc_uncol[] = max((float)$t['total_revenue'] - (float)$t['collected_amount'], 0); }
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
