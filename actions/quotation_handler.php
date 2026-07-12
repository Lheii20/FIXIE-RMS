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

    // CLIENT APPROVAL SUBMISSION AND PROOF UPLOAD
    if ($action == 'receive_po') {
        $quotation_id = intval($_POST['quotation_id']);
        $approval_mode = trim($_POST['approval_mode'] ?? '');
        $user_id = $_SESSION['user_id'];
        $allowed_modes = [
            'Messenger Chat',
            'Viber / WhatsApp Chat',
            'Email Confirmation',
            'Signed Quotation',
            'Official Client PO',
            'In-Person Confirmation',
            'Other Written Confirmation'
        ];

        if ($quotation_id < 1 || !in_array($approval_mode, $allowed_modes, true)) {
            header("Location: ../quotations_list.php?error=Please provide a valid client approval mode.");
            exit();
        }

        if (!isset($_FILES['po_file']) || $_FILES['po_file']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['po_file']['tmp_name'])) {
            header("Location: ../quotations_list.php?error=Please attach the client approval proof.");
            exit();
        }

        $file = $_FILES['po_file'];
        $max_file_size = 10 * 1024 * 1024; // 10 MB
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_files = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png'
        ];

        if ($file['size'] < 1 || $file['size'] > $max_file_size || !array_key_exists($file_ext, $allowed_files)) {
            header("Location: ../quotations_list.php?error=Proof must be a PDF, JPG, or PNG file no larger than 10 MB.");
            exit();
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($file['tmp_name']);
        if ($mime_type !== $allowed_files[$file_ext]) {
            header("Location: ../quotations_list.php?error=The uploaded proof file is not a valid PDF or image.");
            exit();
        }

        $upload_dir = __DIR__ . '/../uploads/pos/';
        $po_file_path = null;

        try {
            if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
                throw new RuntimeException('Unable to create the approval-proof directory.');
            }

            $conn->begin_transaction();

            // Lock the selected quotation so an approval cannot be submitted twice.
            $quote_stmt = $conn->prepare("SELECT status FROM quotations WHERE quotation_id = ? FOR UPDATE");
            $quote_stmt->bind_param("i", $quotation_id);
            $quote_stmt->execute();
            $quote = $quote_stmt->get_result()->fetch_assoc();

            if (!$quote || !in_array($quote['status'], ['Pending Approval', 'Pending PO'], true)) {
                throw new RuntimeException('This quotation is no longer waiting for client approval.');
            }

            // Generate a sequential internal reference for the received client approval.
            $year = date('Y');
            $cpo_prefix = "CPO-{$year}-";
            $like_prefix = $cpo_prefix . '%';
            $number_stmt = $conn->prepare(
                "SELECT client_po_number FROM quotations
                 WHERE client_po_number LIKE ?
                 ORDER BY client_po_number DESC LIMIT 1 FOR UPDATE"
            );
            $number_stmt->bind_param("s", $like_prefix);
            $number_stmt->execute();
            $last_number = $number_stmt->get_result()->fetch_assoc()['client_po_number'] ?? null;
            $next_number = $last_number ? ((int) substr($last_number, -4)) + 1 : 1;
            $client_po_number = $cpo_prefix . str_pad((string) $next_number, 4, '0', STR_PAD_LEFT);

            $po_file_path = time() . '_' . $client_po_number . '_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
            if (!move_uploaded_file($file['tmp_name'], $upload_dir . $po_file_path)) {
                throw new RuntimeException('The approval proof could not be saved.');
            }

            if (!receive_client_po($conn, $quotation_id, $client_po_number, $approval_mode, $po_file_path, $user_id)) {
                throw new RuntimeException('The quotation could not be updated.');
            }

            $conn->commit();
            header("Location: ../quotations_list.php?success=Client approval submitted successfully. Reference: $client_po_number");
        } catch (Throwable $e) {
            $conn->rollback();
            if ($po_file_path && is_file($upload_dir . $po_file_path)) {
                unlink($upload_dir . $po_file_path);
            }
            header("Location: ../quotations_list.php?error=" . rawurlencode($e->getMessage()));
        }
        exit();
    }
}
header("Location: ../dashboard.php");
?>
