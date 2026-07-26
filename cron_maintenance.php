<?php
// =========================================================================
// FIXIE DRMS - ENTERPRISE SCHEDULED MAINTENANCE SCRIPT
// Execute this file via Linux Cron Job or Windows Task Scheduler daily.
// Example Cron Command (runs at midnight): 
// 0 0 * * * /usr/bin/php /path/to/fixie_drms/cron_maintenance.php
// =========================================================================

require_once __DIR__ . '/config/db_connect.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting Enterprise background maintenance tasks...\n";

try {
    $managers = [];
    $mg_res = $conn->query("SELECT user_id FROM users WHERE role IN ('Admin', 'GM', 'President') AND status = 'Active'");
    if ($mg_res) {
        while($mg = $mg_res->fetch_assoc()) $managers[] = $mg['user_id'];
    }
    $has_notif_table = ($conn->query("SHOW TABLES LIKE 'notifications'")->num_rows > 0);

    // =========================================================================
    // 1. ACTIVE TO ARCHIVED RULE
    // Upload Date + Active Years & Months <= NOW
    // =========================================================================
    $conn->query("
        UPDATE documents d
        JOIN document_categories dc ON d.category = dc.sub_category
        JOIN retention_policies p ON dc.policy_id = p.policy_id
        SET d.status = 'Archived'
        WHERE d.status = 'Active' AND d.is_legal_hold = 0
        AND DATE_ADD(DATE_ADD(d.uploaded_at, INTERVAL COALESCE(p.active_years, 0) YEAR), INTERVAL COALESCE(p.active_months, 0) MONTH) <= NOW()
    ");
    
    echo "[" . date('Y-m-d H:i:s') . "] Auto-Archive Rule: " . $conn->affected_rows . " documents transitioned to Archive Phase.\n";

    // =========================================================================
    // 2. REVIEW FOR PERMANENT DELETION RULE (Ready for Disposition)
    // Upload Date + Active Y&M + Archive Y&M <= NOW
    // =========================================================================
    $conn->query("
        UPDATE documents d
        JOIN document_categories dc ON d.category = dc.sub_category
        JOIN retention_policies p ON dc.policy_id = p.policy_id
        SET d.disposition_status = 'Ready for Disposition'
        WHERE d.status = 'Archived' AND d.disposition_status = 'Pending' AND d.is_legal_hold = 0
        AND p.action_after_retention = 'Review for permanent deletion'
        AND DATE_ADD(DATE_ADD(d.uploaded_at, INTERVAL (COALESCE(p.active_years, 0) + COALESCE(p.archive_years, 0)) YEAR), INTERVAL (COALESCE(p.active_months, 0) + COALESCE(p.archive_months, 0)) MONTH) <= NOW()
    ");
    
    echo "[" . date('Y-m-d H:i:s') . "] Ready for Disposition Rule: " . $conn->affected_rows . " documents flagged for manual review.\n";

    // =========================================================================
    // 3. PRE-DISPOSITION ALERTS (30-day & 15-day Warnings)
    // =========================================================================
    $alert_query = $conn->query("
        SELECT d.doc_id, d.file_name, p.active_years, p.active_months, p.archive_years, p.archive_months, d.uploaded_at
        FROM documents d
        JOIN document_categories dc ON d.category = dc.sub_category
        JOIN retention_policies p ON dc.policy_id = p.policy_id
        WHERE d.disposition_status = 'Pending' AND d.is_legal_hold = 0
    ");

    if ($alert_query && $has_notif_table) {
        $alert_30 = 0;
        $alert_15 = 0;
        $stmt_alert = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
        
        while($doc = $alert_query->fetch_assoc()) {
            $base_date = $doc['uploaded_at'];
            $total_years = (int)$doc['active_years'] + (int)$doc['archive_years'];
            $total_months = (int)$doc['active_months'] + (int)$doc['archive_months'];
            
            // Compute disposition date based on new variables
            $disposition_date = date('Y-m-d', strtotime("$base_date +$total_years years +$total_months months"));
            $diff_days = (strtotime($disposition_date) - time()) / (60 * 60 * 24);
            
            if (round($diff_days) == 30 || round($diff_days) == 15) {
                $msg = "Retention Alert: The document '{$doc['file_name']}' is scheduled for disposition review in " . round($diff_days) . " days.";
                if ($stmt_alert) {
                    foreach($managers as $uid) {
                        $stmt_alert->bind_param("is", $uid, $msg);
                        $stmt_alert->execute();
                    }
                }
                if (round($diff_days) == 30) $alert_30++; else $alert_15++;
            }
        }
        echo "[" . date('Y-m-d H:i:s') . "] Alerts Generated: {$alert_30} (30-day) and {$alert_15} (15-day) warnings sent.\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] Background maintenance completed successfully.\n";

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR during maintenance: " . $e->getMessage() . "\n";
    error_log("Cron Maintenance Error: " . $e->getMessage());
}
?>