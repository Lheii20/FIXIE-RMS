<?php
ob_start(); // Para maiwasan ang anumang header errors
session_start();
require '../config/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

// Support both ?id= at ?notif_id= parameters
$notif_id_val = $_GET['id'] ?? $_GET['notif_id'] ?? null;

if ($notif_id_val) {
    $notif_id = intval($notif_id_val);
    
    // 1. I-mark as read agad ang notification
    $conn->query("UPDATE notifications SET is_read = 1 WHERE notif_id = $notif_id");

    // 2. Kunin ang message para ma-parse
    $res = $conn->query("SELECT message FROM notifications WHERE notif_id = $notif_id");
    
    $redirect_url = "../dashboard.php"; // Ultimate fallback
    
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $message = $row['message'];
        
        // --- PARSER LOGIC ---
        
        // Format A: PO Number (e.g. PO-2026-0075)
        if (preg_match('/PO-\d{4}-\d+/i', $message, $matches)) {
            $po_number = trim($matches[0]);
            
            // Hahanapin ang ID sa DB
            $stmt = $conn->prepare("SELECT po_id FROM purchase_orders WHERE po_number = ? LIMIT 1");
            $stmt->bind_param("s", $po_number);
            $stmt->execute();
            $po_res = $stmt->get_result();
            
            if ($po_res->num_rows > 0) {
                $po_data = $po_res->fetch_assoc();
                $redirect_url = "../view_po.php?id=" . $po_data['po_id'];
            } else {
                $redirect_url = "../po_list.php"; // Kung deleted na ang PO, sa list papunta
            }
        } 
        // Format B: PO ID Number (e.g. PO #102 o PO 102)
        elseif (preg_match('/PO\s*#(\d+)/i', $message, $matches) || preg_match('/PO\s+(\d+)/i', $message, $matches)) {
            $po_id = intval($matches[1]);
            $redirect_url = "../view_po.php?id=" . $po_id;
        }
        // Format C: PR Number (e.g. PR-2026-0001)
        elseif (preg_match('/PR-\d{4}-\d+/i', $message, $matches)) {
            $pr_number = trim($matches[0]);
            
            $stmt = $conn->prepare("SELECT pr_id FROM purchase_requests WHERE pr_number = ? LIMIT 1");
            $stmt->bind_param("s", $pr_number);
            $stmt->execute();
            $pr_res = $stmt->get_result();
            
            if ($pr_res->num_rows > 0) {
                $pr_data = $pr_res->fetch_assoc();
                $redirect_url = "../view_pr.php?id=" . $pr_data['pr_id'];
            } else {
                $redirect_url = "../pr_list.php";
            }
        }
        // Format D: PR ID Number (e.g. PR #102 o PR 102)
        elseif (preg_match('/PR\s*#(\d+)/i', $message, $matches) || preg_match('/PR\s+(\d+)/i', $message, $matches)) {
            $pr_id = intval($matches[1]);
            $redirect_url = "../view_pr.php?id=" . $pr_id;
        }
        // GENERAL FALLBACKS (Kung walang nakitang numero sa message)
        elseif (stripos($message, 'Purchase Order') !== false || stripos($message, 'PO') !== false) {
            $redirect_url = "../po_list.php";
        }
        elseif (stripos($message, 'Purchase Request') !== false || stripos($message, 'PR') !== false) {
            $redirect_url = "../pr_list.php";
        }
    }
    
    // I-redirect ang user sa na-detect na URL
    header("Location: " . $redirect_url);
    exit();
}

// Logic para sa "Mark All as Read"
if (isset($_GET['mark_all']) && $_GET['mark_all'] == 1) {
    $role = $_SESSION['role'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE target_role = ?");
    $stmt->bind_param("s", $role);
    $stmt->execute();
    
    $redirect_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : "../dashboard.php"; 
    header("Location: " . $redirect_url);
    exit();
}

// Fail-safe redirect
header("Location: ../dashboard.php");
exit();
?>