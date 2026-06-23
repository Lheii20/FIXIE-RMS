<?php
// =========================================================================
// FIXIE DRMS - SCHEDULED MAINTENANCE SCRIPT
// Execute this file via Linux Cron Job or Windows Task Scheduler daily.
// Example Cron Command (runs at midnight): 
// 0 0 * * * /usr/bin/php /path/to/fixie_drms/cron_maintenance.php
// =========================================================================

// Optional: Prevent browser execution. Require CLI for security.
// if (php_sapi_name() !== 'cli') {
//     die("Access Denied: This script can only be run from the command line.");
// }

require_once __DIR__ . '/config/db_connect.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting background maintenance tasks...\n";

try {
    // =========================================================================
    // 1. AUTO-ARCHIVE RULE
    // =========================================================================
    $conn->query("
        UPDATE documents 
        SET status = 'Archived' 
        WHERE status = 'Active' 
        AND expiry_date IS NOT NULL 
        AND expiry_date <= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
    ");
    
    $archived_count = $conn->affected_rows;
    echo "[" . date('Y-m-d H:i:s') . "] Auto-Archive Rule: {$archived_count} documents archived.\n";

    // =========================================================================
    // 2. RETENTION & DISPOSITION RULE (Uses corrected schema)
    // =========================================================================
    $conn->query("
        UPDATE documents d
        JOIN retention_policies p ON d.policy_id = p.policy_id
        SET d.disposition_status = 'Ready for Disposition'
        WHERE d.disposition_status = 'Pending' 
        AND DATE_ADD(d.uploaded_at, INTERVAL (p.retention_years * 12) MONTH) <= CURDATE()
    ");
    
    $disposition_count = $conn->affected_rows;
    echo "[" . date('Y-m-d H:i:s') . "] Retention Rule: {$disposition_count} documents flagged for disposition.\n";

    echo "[" . date('Y-m-d H:i:s') . "] Background maintenance completed successfully.\n";

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR during maintenance: " . $e->getMessage() . "\n";
    error_log("Cron Maintenance Error: " . $e->getMessage());
}
?>