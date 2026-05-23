<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Sales Staff') { 
    header("Location: ../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Security Error: Invalid CSRF Token");
    }

    $action = $_POST['action'];

    if ($action == 'create_detailed_quotation') {
        $data = [
            'quotation_number' => trim($_POST['quotation_number']),
            'client_name' => trim($_POST['client_name']),
            'grand_total' => floatval($_POST['amount']),
            'items' => $_POST['items'] ?? []
        ];

        if (empty($data['items'])) {
            header("Location: ../create_quotation.php?error=Quotation must have at least one item.");
            exit();
        }

        $result = create_detailed_quotation($conn, $data, $_SESSION['user_id']);
        
        if ($result) {
            header("Location: ../quotations_list.php?success=Detailed Quotation Created.");
        } else {
            header("Location: ../create_quotation.php?error=Failed to save quotation.");
        }
        exit();
    }

    // AUTOMATED PO AT FILE UPLOAD LOGIC
    if ($action == 'receive_po') {
        $quotation_id = intval($_POST['quotation_id']);
        $approval_mode = trim($_POST['approval_mode']);
        $user_id = $_SESSION['user_id'];
        $po_file_path = null;

        // 1. AUTO-GENERATE CLIENT PO NUMBER (Format: CPO-YYYY-0001)
        $year = date('Y');
        $cpo_prefix = "CPO-" . $year . "-";
        $like_prefix = $cpo_prefix . "%";
        
        $stmt_po = $conn->prepare("SELECT client_po_number FROM quotations WHERE client_po_number LIKE ? ORDER BY CAST(SUBSTRING_INDEX(client_po_number, '-', -1) AS UNSIGNED) DESC LIMIT 1");
        $stmt_po->bind_param("s", $like_prefix);
        $stmt_po->execute();
        $res_po = $stmt_po->get_result();
        
        if ($res_po->num_rows > 0) {
            $last_cpo = $res_po->fetch_assoc()['client_po_number'];
            $last_num = intval(substr($last_cpo, -4));
            $next_num = $last_num + 1;
        } else {
            $next_num = 1;
        }
        
        $client_po_number = $cpo_prefix . str_pad($next_num, 4, "0", STR_PAD_LEFT); // Ex: CPO-2026-0001

        // 2. FILE UPLOAD HANDLING
        if (isset($_FILES['po_file']) && $_FILES['po_file']['error'] == 0) {
            $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];
            $file_name = $_FILES['po_file']['name'];
            $file_tmp = $_FILES['po_file']['tmp_name'];
            
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            
            if (!in_array($file_ext, $allowed_ext)) {
                header("Location: ../quotations_list.php?error=Invalid file type. Only PDF, JPG, and PNG are allowed.");
                exit();
            }

            // Secure unique file name with the new CPO Number
            $new_file_name = time() . "_" . $client_po_number . "_" . bin2hex(random_bytes(4)) . "." . $file_ext;
            
            // Siguraduhing may folder na "pos" sa loob ng uploads
            $upload_dir = '../uploads/pos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $destination = $upload_dir . $new_file_name;

            if (move_uploaded_file($file_tmp, $destination)) {
                $po_file_path = $new_file_name;
            } else {
                header("Location: ../quotations_list.php?error=Failed to upload file to server.");
                exit();
            }
        } else {
            header("Location: ../quotations_list.php?error=Proof of approval file is required.");
            exit();
        }

        // 3. I-SAVE ANG LAHAT SA DATABASE
        if (receive_client_po($conn, $quotation_id, $client_po_number, $approval_mode, $po_file_path, $user_id)) {
            header("Location: ../quotations_list.php?success=Approval Uploaded. Auto-Generated Tracker: $client_po_number");
        } else {
            header("Location: ../quotations_list.php?error=Database Update Failed.");
        }
        exit();
    }
}
header("Location: ../dashboard.php");
?>