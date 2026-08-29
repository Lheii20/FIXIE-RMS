<?php
session_start();
require '../config/db_connect.php';
require '../config/functions.php';
require_once '../config/workflow_feedback.php';

if (!function_exists('po_handler_redirect')) {
    function po_handler_redirect(
        string $destination,
        string $type,
        string $message,
        string $fallback = 'The requested action could not be completed. No changes were saved.'
    ): void {
        $public_message = $type === 'error'
            ? drms_public_feedback_message($message, $fallback)
            : drms_feedback_clean_text($message);
        drms_redirect_with_feedback($destination, $type, $public_message);
    }
}

if (!isset($_SESSION['user_id'])) {
    po_handler_redirect(
        '../index.php',
        'error',
        'Your session has ended. Please sign in again.'
    );
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $session_token = (string) ($_SESSION['csrf_token'] ?? '');
    $posted_token = (string) ($_POST['csrf_token'] ?? '');
    if (
        $session_token === '' ||
        $posted_token === '' ||
        !hash_equals($session_token, $posted_token)
    ) {
        po_handler_redirect(
            '../po_list.php',
            'error',
            'Security token validation failed. Refresh the page and try again.'
        );
    }

    $action = $_POST['action'] ?? 'upload';
    $user_id = $_SESSION['user_id'];

    // Helper function for Hybrid Auto-Assignment (Load Balanced)
    function auto_assign_po_hybrid($conn, $po_id, $required_role, $assigned_by_user_id, $exclude_user_id = null) {
        $assigned_by_user_id = (int) $assigned_by_user_id;
        if (empty($required_role) || $assigned_by_user_id < 1) return false;

        $query = "SELECT user_id FROM users WHERE role = ? AND status = 'Active'";
        if ($exclude_user_id) { $query .= " AND user_id != " . intval($exclude_user_id); }
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $required_role);
        $stmt->execute();
        $res = $stmt->get_result();
        
        $eligible_users = [];
        while ($row = $res->fetch_assoc()) { $eligible_users[] = $row['user_id']; }
        if (empty($eligible_users)) return false;

        if (count($eligible_users) === 1) {
            $assigned_user = $eligible_users[0];
        } else {
            // Load balancing: Find user with the minimum active tasks
            $placeholders = implode(',', array_fill(0, count($eligible_users), '?'));
            $lb_query = "SELECT u.user_id, COUNT(a.assignment_id) as active_tasks 
                         FROM users u 
                         LEFT JOIN purchase_order_task_assignments a ON u.user_id = a.assigned_to AND a.assignment_status = 'Active' 
                         WHERE u.user_id IN ($placeholders) 
                         GROUP BY u.user_id ORDER BY active_tasks ASC, u.user_id ASC LIMIT 1";
            $stmt_lb = $conn->prepare($lb_query);
            $types = str_repeat('i', count($eligible_users));
            $stmt_lb->bind_param($types, ...$eligible_users);
            $stmt_lb->execute();
            $lb_res = $stmt_lb->get_result();
            if ($lb_row = $lb_res->fetch_assoc()) { $assigned_user = $lb_row['user_id']; } 
            else { $assigned_user = $eligible_users[0]; }
        }

        // Assign
        $stmt_assign = $conn->prepare("INSERT INTO purchase_order_task_assignments (po_id, assigned_to, assigned_by, assigned_role, assignment_status, assigned_at) VALUES (?, ?, ?, ?, 'Active', NOW())");
        $stmt_assign->bind_param("iiis", $po_id, $assigned_user, $assigned_by_user_id, $required_role);
        $stmt_assign->execute();
        return $assigned_user;
    }

    function getRedirectUrl($conn, $doc_id = null, $po_id = null) {
        if ($po_id) {
            return "../view_po.php?id=" . $po_id;
        }
        if ($doc_id) {
            $stmt = $conn->prepare("SELECT po_id FROM documents WHERE doc_id = ?");
            $stmt->bind_param("i", $doc_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                if ($row['po_id']) {
                    return "../view_po.php?id=" . $row['po_id'];
                }
            }
        }
        return "../general_docs.php"; 
    }

    if ($action == 'create') {
        if ($_SESSION['role'] !== 'Procurement') {
            header("Location: ../po_list.php?error=Only Procurement can create a Purchase Order.");
            exit();
        }

        $pr_id = (int) ($_POST['pr_id'] ?? 0);
        if ($pr_id < 1) {
            header(
                "Location: ../create_po.php?error=" .
                rawurlencode('Select an officially approved PRF before creating a Purchase Order.')
            );
            exit();
        }

        $created_po_document_path = null;

        try {
            $conn->begin_transaction();

            // The PRF is the authoritative PO source. Browser-posted client, item,
            // selling-price, and cost values are intentionally ignored.
            $pr_stmt = $conn->prepare(
                "SELECT
                    pr.pr_id,
                    pr.pr_number,
                    pr.quotation_id,
                    pr.client_approval_record_id,
                    pr.client_name,
                    pr.amount,
                    pr.cost_of_goods_amount,
                    pr.other_expense_amount,
                    pr.requested_fund_amount,
                    pr.gross_profit_amount,
                    pr.gross_margin_percent,
                    pr.workflow_version,
                    pr.current_approval_stage,
                    pr.final_approved_by,
                    pr.final_approved_at,
                    q.quotation_number AS source_quotation_number,
                    client_po.actual_client_po_number,
                    client_po.proof_file_path AS client_po_proof_path,
                    client_po.proof_file_hash AS client_po_proof_hash,
                    supplier.supplier_detail_id,
                    supplier.supplier_name,
                    supplier.quoted_cost_amount
                 FROM purchase_requests pr
                 INNER JOIN quotations q
                    ON q.quotation_id = pr.quotation_id
                 INNER JOIN client_approval_records client_po
                    ON client_po.approval_record_id = pr.client_approval_record_id
                   AND client_po.quotation_id = pr.quotation_id
                   AND client_po.record_type = 'Official Client PO'
                   AND client_po.record_status = 'Active'
                 INNER JOIN pr_supplier_details supplier
                    ON supplier.pr_id = pr.pr_id
                   AND supplier.record_status = 'Active'
                 WHERE pr.pr_id = ?
                   AND pr.status = 'Approved'
                   AND pr.current_approval_stage = 'Official Approved'
                   AND pr.final_approved_by IS NOT NULL
                   AND pr.final_approved_at IS NOT NULL
                   AND client_po.actual_client_po_number IS NOT NULL
                   AND client_po.actual_client_po_number <> ''
                   AND client_po.final_approval_date IS NOT NULL
                 LIMIT 1
                 FOR UPDATE"
            );
            $pr_stmt->bind_param('i', $pr_id);
            if (!$pr_stmt->execute()) {
                throw new RuntimeException('The approved PRF could not be checked.');
            }

            $source_pr = $pr_stmt->get_result()->fetch_assoc();
            if (!$source_pr) {
                throw new DomainException(
                    'Only an officially approved PRF with an active official Client PO can be converted.'
                );
            }

            // The official Client PO proof must still exist and match its recorded hash.
            $client_po_proof_path = dirname(__DIR__) . DIRECTORY_SEPARATOR .
                'uploads' . DIRECTORY_SEPARATOR . 'pos' . DIRECTORY_SEPARATOR .
                basename((string) $source_pr['client_po_proof_path']);

            if (!is_file($client_po_proof_path)) {
                throw new DomainException(
                    'The official Client PO attachment is missing. Restore the source document before creating the PO.'
                );
            }

            $expected_client_po_hash = strtolower(
                trim((string) $source_pr['client_po_proof_hash'])
            );
            $actual_client_po_hash = strtolower(
                (string) hash_file('sha256', $client_po_proof_path)
            );

            if (
                $expected_client_po_hash === '' ||
                !hash_equals($expected_client_po_hash, $actual_client_po_hash)
            ) {
                throw new DomainException(
                    'The official Client PO attachment failed its integrity check.'
                );
            }

            // Re-confirm the latest GM -> Finance -> Owner approval cycle.
            $approval_stmt = $conn->prepare(
                "SELECT
                    approval_cycle,
                    stage_sequence,
                    approval_stage,
                    required_role,
                    decision,
                    acted_by,
                    acted_at
                 FROM pr_approval_records
                 WHERE pr_id = ?
                 ORDER BY approval_cycle DESC, stage_sequence ASC
                 FOR UPDATE"
            );
            $approval_stmt->bind_param('i', $pr_id);
            if (!$approval_stmt->execute()) {
                throw new RuntimeException('The PRF approval history could not be checked.');
            }

            $approval_result = $approval_stmt->get_result();
            $latest_cycle = null;
            $latest_approvals = [];

            while ($approval = $approval_result->fetch_assoc()) {
                $cycle = (int) $approval['approval_cycle'];
                if ($latest_cycle === null) {
                    $latest_cycle = $cycle;
                }
                if ($cycle !== $latest_cycle) {
                    continue;
                }
                $latest_approvals[$approval['approval_stage']] = $approval;
            }

            $required_approvals = [
                'GM Review' => ['role' => 'GM', 'sequence' => 1],
                'Finance Review' => ['role' => 'Finance', 'sequence' => 2],
                'Owner Approval' => ['role' => 'President', 'sequence' => 3],
            ];

            if (count($latest_approvals) !== count($required_approvals)) {
                throw new DomainException(
                    'The latest PRF approval cycle is incomplete.'
                );
            }

            foreach ($required_approvals as $stage => $requirement) {
                $approval = $latest_approvals[$stage] ?? null;
                if (
                    !$approval ||
                    $approval['required_role'] !== $requirement['role'] ||
                    (int) $approval['stage_sequence'] !== $requirement['sequence'] ||
                    $approval['decision'] !== 'Approved' ||
                    (int) $approval['acted_by'] < 1 ||
                    empty($approval['acted_at'])
                ) {
                    throw new DomainException(
                        'The PRF must complete GM, Finance, and Owner approval in sequence.'
                    );
                }
            }

            if (
                (int) $source_pr['final_approved_by'] !==
                (int) $latest_approvals['Owner Approval']['acted_by']
            ) {
                throw new DomainException(
                    'The PRF final approver does not match its Owner approval record.'
                );
            }

            // Load immutable item values from the approved PRF.
            $source_items_stmt = $conn->prepare(
                "SELECT
                    item_id,
                    category,
                    brand,
                    item_name,
                    specifications,
                    quantity,
                    unit_price,
                    unit_cost,
                    total_price,
                    total_cost,
                    line_profit_amount
                 FROM pr_items
                 WHERE pr_id = ?
                 ORDER BY item_id
                 FOR UPDATE"
            );
            $source_items_stmt->bind_param('i', $pr_id);
            if (!$source_items_stmt->execute()) {
                throw new RuntimeException('The approved PRF items could not be checked.');
            }

            $source_items_result = $source_items_stmt->get_result();
            $prepared_items = [];
            $calculated_selling_amount = 0.00;
            $calculated_cogs_amount = 0.00;

            while ($source_item = $source_items_result->fetch_assoc()) {
                $source_pr_item_id = (int) $source_item['item_id'];
                $quantity = (int) $source_item['quantity'];
                $item_name = trim((string) $source_item['item_name']);

                if (
                    $source_pr_item_id < 1 ||
                    $quantity < 1 ||
                    $item_name === '' ||
                    !is_numeric($source_item['unit_price']) ||
                    !is_numeric($source_item['unit_cost']) ||
                    !is_numeric($source_item['total_price']) ||
                    !is_numeric($source_item['total_cost']) ||
                    !is_numeric($source_item['line_profit_amount'])
                ) {
                    throw new DomainException(
                        'An approved PRF item contains incomplete financial values.'
                    );
                }

                $unit_price = round((float) $source_item['unit_price'], 2);
                $unit_cost = round((float) $source_item['unit_cost'], 2);
                $total_price = round((float) $source_item['total_price'], 2);
                $total_cost = round((float) $source_item['total_cost'], 2);
                $line_profit_amount = round(
                    (float) $source_item['line_profit_amount'],
                    2
                );

                if (
                    $unit_price <= 0 ||
                    $unit_cost <= 0 ||
                    $total_price <= 0 ||
                    $total_cost <= 0
                ) {
                    throw new DomainException(
                        'Approved PRF item prices and costs must be greater than zero.'
                    );
                }

                $expected_total_cost = round($unit_cost * $quantity, 2);
                $expected_line_profit = round($total_price - $total_cost, 2);

                if (
                    abs($total_cost - $expected_total_cost) > 0.01 ||
                    abs($line_profit_amount - $expected_line_profit) > 0.01
                ) {
                    throw new DomainException(
                        'An approved PRF item no longer matches its cost or profit calculation.'
                    );
                }

                $calculated_selling_amount = round(
                    $calculated_selling_amount + $total_price,
                    2
                );
                $calculated_cogs_amount = round(
                    $calculated_cogs_amount + $total_cost,
                    2
                );

                $prepared_items[] = [
                    'source_pr_item_id' => $source_pr_item_id,
                    'category' => trim((string) ($source_item['category'] ?? '')),
                    'brand' => trim((string) ($source_item['brand'] ?? '')),
                    'item_name' => $item_name,
                    'specifications' => trim(
                        (string) ($source_item['specifications'] ?? '')
                    ),
                    'quantity' => $quantity,
                    'unit_price' => $unit_price,
                    'unit_cost' => $unit_cost,
                    'total_price' => $total_price,
                    'total_cost' => $total_cost,
                    'line_profit_amount' => $line_profit_amount,
                ];
            }

            if (empty($prepared_items)) {
                throw new DomainException(
                    'The approved PRF does not contain any items.'
                );
            }

            $required_money_fields = [
                'amount',
                'cost_of_goods_amount',
                'other_expense_amount',
                'requested_fund_amount',
                'gross_profit_amount',
                'gross_margin_percent',
                'quoted_cost_amount',
            ];

            foreach ($required_money_fields as $field) {
                if (!is_numeric($source_pr[$field])) {
                    throw new DomainException(
                        'The approved PRF contains incomplete financial totals.'
                    );
                }
            }

            $definitive_amount = round((float) $source_pr['amount'], 2);
            $cost_of_goods_amount = round(
                (float) $source_pr['cost_of_goods_amount'],
                2
            );
            $other_expense_amount = round(
                (float) $source_pr['other_expense_amount'],
                2
            );
            $requested_fund_amount = round(
                (float) $source_pr['requested_fund_amount'],
                2
            );
            $gross_profit_amount = round(
                (float) $source_pr['gross_profit_amount'],
                2
            );
            $gross_margin_percent = round(
                (float) $source_pr['gross_margin_percent'],
                4
            );
            $supplier_quoted_cost = round(
                (float) $source_pr['quoted_cost_amount'],
                2
            );

            $expected_requested_fund = round(
                $cost_of_goods_amount + $other_expense_amount,
                2
            );
            $expected_gross_profit = round(
                $definitive_amount - $requested_fund_amount,
                2
            );
            $expected_gross_margin = $definitive_amount > 0
                ? round(($expected_gross_profit / $definitive_amount) * 100, 4)
                : 0.0000;

            if (
                $definitive_amount <= 0 ||
                $cost_of_goods_amount <= 0 ||
                $other_expense_amount < 0 ||
                abs($definitive_amount - $calculated_selling_amount) > 0.01 ||
                abs($cost_of_goods_amount - $calculated_cogs_amount) > 0.01 ||
                abs($supplier_quoted_cost - $cost_of_goods_amount) > 0.01 ||
                abs($requested_fund_amount - $expected_requested_fund) > 0.01 ||
                abs($gross_profit_amount - $expected_gross_profit) > 0.01 ||
                abs($gross_margin_percent - $expected_gross_margin) > 0.001
            ) {
                throw new DomainException(
                    'The approved PRF header, supplier cost, and item totals do not match.'
                );
            }

            // Generate the next internal PO number under a database row lock.
            $year = date('Y');
            $po_prefix = 'PO-' . $year . '-';
            $like_prefix = $po_prefix . '%';

            $po_number_stmt = $conn->prepare(
                "SELECT po_number
                 FROM purchase_orders
                 WHERE po_number LIKE ?
                 ORDER BY CAST(
                    SUBSTRING_INDEX(po_number, '-', -1) AS UNSIGNED
                 ) DESC
                 LIMIT 1
                 FOR UPDATE"
            );
            $po_number_stmt->bind_param('s', $like_prefix);
            if (!$po_number_stmt->execute()) {
                throw new RuntimeException('The next PO number could not be generated.');
            }

            $po_number_result = $po_number_stmt->get_result();
            if ($po_number_result->num_rows > 0) {
                $last_po_number = $po_number_result->fetch_assoc()['po_number'];
                $last_sequence = (int) substr(
                    $last_po_number,
                    strlen($po_prefix)
                );
                $next_sequence = $last_sequence + 1;
            } else {
                $next_sequence = 1;
            }

            $po_number = $po_prefix .
                str_pad($next_sequence, 4, '0', STR_PAD_LEFT);
            $quotation_number = (string) $source_pr['source_quotation_number'];
            $client_name = (string) $source_pr['client_name'];
            // The official PRF already completed GM, Finance, and Owner approval.
            // Continue at funding instead of repeating the same approval chain on the PO.
            $status = 'President-Approved';
            $location = 'Finance Dept.';
            $source_pr_workflow_version = (int) $source_pr['workflow_version'];
            $supplier_detail_id = (int) $source_pr['supplier_detail_id'];
            $source_pr_final_approved_at =
                (string) $source_pr['final_approved_at'];

            if (
                $quotation_number === '' ||
                trim($client_name) === '' ||
                $supplier_detail_id < 1 ||
                trim((string) $source_pr['supplier_name']) === ''
            ) {
                throw new DomainException(
                    'The approved PRF is missing its quotation, client, or supplier reference.'
                );
            }

            $po_insert_stmt = $conn->prepare(
                "INSERT INTO purchase_orders (
                    po_number,
                    quotation_number,
                    client_name,
                    amount,
                    cost_of_goods_amount,
                    other_expense_amount,
                    requested_fund_amount,
                    gross_profit_amount,
                    gross_margin_percent,
                    status,
                    current_location,
                    created_by,
                    pr_id,
                    source_pr_workflow_version,
                    supplier_detail_id,
                    source_pr_final_approved_at,
                    pr_financial_snapshot_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $po_insert_stmt->bind_param(
                'sssddddddssiiiis',
                $po_number,
                $quotation_number,
                $client_name,
                $definitive_amount,
                $cost_of_goods_amount,
                $other_expense_amount,
                $requested_fund_amount,
                $gross_profit_amount,
                $gross_margin_percent,
                $status,
                $location,
                $user_id,
                $pr_id,
                $source_pr_workflow_version,
                $supplier_detail_id,
                $source_pr_final_approved_at
            );
            if (!$po_insert_stmt->execute()) {
                throw new RuntimeException('The Purchase Order could not be saved.');
            }
            $po_id = (int) $conn->insert_id;

            $item_insert_stmt = $conn->prepare(
                "INSERT INTO po_items (
                    po_id,
                    source_pr_item_id,
                    category,
                    brand,
                    item_name,
                    specifications,
                    quantity,
                    unit_price,
                    unit_cost,
                    total_price,
                    total_cost,
                    line_profit_amount
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            foreach ($prepared_items as $prepared_item) {
                $source_pr_item_id = $prepared_item['source_pr_item_id'];
                $category = $prepared_item['category'];
                $brand = $prepared_item['brand'];
                $item_name = $prepared_item['item_name'];
                $specifications = $prepared_item['specifications'];
                $quantity = $prepared_item['quantity'];
                $unit_price = $prepared_item['unit_price'];
                $unit_cost = $prepared_item['unit_cost'];
                $total_price = $prepared_item['total_price'];
                $total_cost = $prepared_item['total_cost'];
                $line_profit_amount = $prepared_item['line_profit_amount'];

                $item_insert_stmt->bind_param(
                    'iissssiddddd',
                    $po_id,
                    $source_pr_item_id,
                    $category,
                    $brand,
                    $item_name,
                    $specifications,
                    $quantity,
                    $unit_price,
                    $unit_cost,
                    $total_price,
                    $total_cost,
                    $line_profit_amount
                );
                if (!$item_insert_stmt->execute()) {
                    throw new RuntimeException(
                        'A Purchase Order item could not be saved.'
                    );
                }
            }

            $pr_update_stmt = $conn->prepare(
                "UPDATE purchase_requests
                 SET status = 'Converted_to_PO'
                 WHERE pr_id = ?
                   AND status = 'Approved'
                   AND current_approval_stage = 'Official Approved'"
            );
            $pr_update_stmt->bind_param('i', $pr_id);
            if (!$pr_update_stmt->execute() ||
                $pr_update_stmt->affected_rows !== 1) {
                throw new DomainException(
                    'The PRF was already converted or changed before the PO could be saved.'
                );
            }

            $history_stmt = $conn->prepare(
                "INSERT INTO po_history (
                    po_id,
                    changed_by,
                    status_from,
                    status_to,
                    remarks
                 ) VALUES (?, ?, 'Official PRF', 'President-Approved', ?)"
            );
            $history_remarks =
                'Created from official PRF ' . $source_pr['pr_number'] .
                ', Client PO ' . $source_pr['actual_client_po_number'] . '.';
            $history_stmt->bind_param(
                'iis',
                $po_id,
                $user_id,
                $history_remarks
            );
            if (!$history_stmt->execute()) {
                throw new RuntimeException('The PO history could not be saved.');
            }

            create_role_notification(
                $conn,
                'Finance',
                'PO ' . $po_number . ' is ready for funding release.'
            );

            $assigned_finance = auto_assign_po_hybrid(
                $conn,
                $po_id,
                'Finance',
                $user_id
            );
            if (!$assigned_finance) {
                throw new DomainException(
                    'No active Finance user is available for the funding step.'
                );
            }

            // Preserve the existing optional supporting-document upload.
            if (
                isset($_FILES['po_document']) &&
                ($_FILES['po_document']['error'] ?? UPLOAD_ERR_NO_FILE) !==
                    UPLOAD_ERR_NO_FILE
            ) {
                $file = $_FILES['po_document'];
                if (
                    $file['error'] !== UPLOAD_ERR_OK ||
                    !is_uploaded_file($file['tmp_name'])
                ) {
                    throw new DomainException(
                        'The optional PO supporting document could not be uploaded.'
                    );
                }

                $allowed_documents = [
                    'pdf' => 'application/pdf',
                    'png' => 'image/png',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                ];
                $extension = strtolower(
                    pathinfo($file['name'], PATHINFO_EXTENSION)
                );
                $mime_type = (new finfo(FILEINFO_MIME_TYPE))
                    ->file($file['tmp_name']);

                if (
                    $file['size'] < 1 ||
                    $file['size'] > 10 * 1024 * 1024 ||
                    !isset($allowed_documents[$extension]) ||
                    $mime_type !== $allowed_documents[$extension]
                ) {
                    throw new DomainException(
                        'The supporting document must be a valid PDF, JPG, or PNG file up to 10 MB.'
                    );
                }

                $upload_directory =
                    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' .
                    DIRECTORY_SEPARATOR;
                if (
                    !is_dir($upload_directory) &&
                    !mkdir($upload_directory, 0755, true) &&
                    !is_dir($upload_directory)
                ) {
                    throw new RuntimeException(
                        'The supporting-document directory could not be prepared.'
                    );
                }

                $stored_file_name = time() . '_quote_' .
                    bin2hex(random_bytes(8)) . '.' . $extension;
                $created_po_document_path =
                    $upload_directory . $stored_file_name;

                if (!move_uploaded_file(
                    $file['tmp_name'],
                    $created_po_document_path
                )) {
                    throw new RuntimeException(
                        'The supporting document could not be saved.'
                    );
                }

                $database_file_path = 'uploads/' . $stored_file_name;
                $file_hash = hash_file(
                    'sha256',
                    $created_po_document_path
                );
                $original_file_name = basename((string) $file['name']);

                $document_stmt = $conn->prepare(
                    "INSERT INTO documents (
                        po_id,
                        doc_type,
                        file_name,
                        file_path,
                        file_hash,
                        uploaded_by,
                        status
                     ) VALUES (?, 'Quotation', ?, ?, ?, ?, 'Active')"
                );
                $document_stmt->bind_param(
                    'isssi',
                    $po_id,
                    $original_file_name,
                    $database_file_path,
                    $file_hash,
                    $user_id
                );
                if (!$document_stmt->execute()) {
                    throw new RuntimeException(
                        'The supporting-document record could not be saved.'
                    );
                }
            }

            log_audit_action(
                $conn,
                $user_id,
                'CREATE_PO',
                'Created PO ' . $po_number .
                    ' from official PRF ' . $source_pr['pr_number'] . '.',
                null,
                [
                    'po_id' => $po_id,
                    'po_number' => $po_number,
                    'pr_id' => $pr_id,
                    'client_po_number' =>
                        $source_pr['actual_client_po_number'],
                    'selling_amount' => $definitive_amount,
                    'cost_of_goods_amount' => $cost_of_goods_amount,
                    'requested_fund_amount' => $requested_fund_amount,
                    'gross_profit_amount' => $gross_profit_amount,
                    'supplier_detail_id' => $supplier_detail_id,
                    'next_workflow_role' => 'Finance',
                    'next_workflow_action' => 'Release Funding',
                ]
            );

            if (!$conn->commit()) {
                throw new RuntimeException(
                    'The Purchase Order transaction could not be completed.'
                );
            }

            header(
                "Location: ../view_po.php?id=" . $po_id .
                "&success=" .
                rawurlencode('PO created and forwarded to Finance for funding.')
            );
            exit();
        } catch (Throwable $e) {
            $conn->rollback();

            if (
                $created_po_document_path &&
                is_file($created_po_document_path)
            ) {
                unlink($created_po_document_path);
            }

            drms_log_workflow_failure(
                'PO creation from PRF ' . $pr_id,
                $e
            );

            $public_error = $e instanceof DomainException
                ? $e->getMessage()
                : 'The Purchase Order could not be created. No records were changed. Please try again.';

            $public_error = drms_public_feedback_message(
                $public_error,
                'The Purchase Order could not be created. No records were changed. Please try again.'
            );

            header(
                "Location: ../create_po.php?pr_id=" . $pr_id .
                "&error=" . rawurlencode($public_error)
            );
            exit();
        }
    }

    // Claim/release preserves shared visibility, while establishing one accountable owner.
    // Hybrid Re-Assign Task Logic
    if ($action === 'reassign_task') {
        $po_id = (int)($_POST['po_id'] ?? 0);
        if ($po_id < 1) { header("Location: ../po_list.php?error=Invalid Purchase Order."); exit(); }
        
        try {
            $conn->begin_transaction();
            // Tapusin ang assignment ng kasalukuyang user
            release_po_task($conn, $po_id, $user_id);
            // I-load balance papunta sa ibang user na may same role (Excluding current user)
            auto_assign_po_hybrid($conn, $po_id, $_SESSION['role'], $user_id, $user_id);
            
            log_audit_action($conn, $user_id, 'REASSIGN_TASK', "Re-assigned PO task #$po_id to another available user.");
            $conn->commit();
            header("Location: ../view_po.php?id=$po_id&success=" . rawurlencode("Task smoothly re-assigned to the next available user."));
        } catch (Exception $e) {
            $conn->rollback();
            drms_log_workflow_failure(
                'PO task reassignment for PO ' . $po_id,
                $e
            );
            po_handler_redirect(
                '../view_po.php?id=' . $po_id,
                'error',
                $e->getMessage()
            );
        }
        exit();
    }

    // Client payments use the dedicated evidence-validated handler and never
    // rewrite the operational PO status.
    if ($action === 'add_payment') {
        $po_id = (int) ($_POST['po_id'] ?? 0);
        $destination = $po_id > 0
            ? '../record_collection_payment.php?po_id=' . $po_id
            : '../collection_monitoring.php';
        $separator = strpos($destination, '?') === false ? '?' : '&';
        header(
            'Location: ' . $destination . $separator . 'error=' .
            rawurlencode(
                'Use the verified Collection Payment form. Direct payment entry through this endpoint is disabled.'
            )
        );
        exit();
    }

    // Final delivery must capture the logistics plan, receiving person,
    // complete quantity, actual handover time, and acknowledgement proof.
    if ($action === 'mark_delivered') {
        $po_id = (int) ($_POST['po_id'] ?? 0);
        $destination = $po_id > 0
            ? '../complete_delivery.php?po_id=' . $po_id
            : '../po_list.php';
        $separator = strpos($destination, '?') === false ? '?' : '&';
        header(
            'Location: ' . $destination . $separator . 'error=' .
            rawurlencode(
                'Use the Complete Client Delivery form. Direct delivery completion is disabled.'
            )
        );
        exit();
    }

    $workflow_actions = ['approve_gm', 'approve_finance', 'approve_president', 'mark_funded', 'reject'];

    if (in_array($action, $workflow_actions, true)) {
        $po_id = intval($_POST['po_id'] ?? 0);
        if ($po_id < 1) {
            header("Location: ../po_list.php?error=Invalid Purchase Order.");
            exit();
        }

        // Supplier funding always requires the verified release record and proof.
        if ($action === 'mark_funded') {
            if ($_SESSION['role'] !== 'Finance') {
                    header(
                        "Location: ../view_po.php?id=" . $po_id .
                        "&error=" .
                        rawurlencode('Only Finance can release approved supplier funding.')
                    );
                    exit();
                }

                $posted_release_amount =
                    trim((string) ($_POST['released_amount'] ?? ''));
                $release_method =
                    trim((string) ($_POST['release_method'] ?? ''));
                $reference_number =
                    trim((string) ($_POST['reference_number'] ?? ''));
                $released_at_input =
                    trim((string) ($_POST['released_at'] ?? ''));
                $funding_remarks =
                    trim((string) ($_POST['funding_remarks'] ?? ''));

                $allowed_release_methods = [
                    'Cash',
                    'Bank Transfer',
                    'Check',
                    'Cash on Delivery',
                    'Other',
                ];

                $funding_timezone = new DateTimeZone('Asia/Manila');
                $released_datetime = DateTime::createFromFormat(
                    'Y-m-d\TH:i',
                    $released_at_input,
                    $funding_timezone
                );
                $released_date_errors = DateTime::getLastErrors();
                $released_date_is_invalid =
                    !$released_datetime ||
                    $released_datetime->format('Y-m-d\TH:i') !==
                        $released_at_input ||
                    (
                        is_array($released_date_errors) &&
                        (
                            $released_date_errors['warning_count'] > 0 ||
                            $released_date_errors['error_count'] > 0
                        )
                    );

                if (
                    $posted_release_amount === '' ||
                    !is_numeric($posted_release_amount) ||
                    (float) $posted_release_amount <= 0
                ) {
                    header(
                        "Location: ../release_funding.php?po_id=" . $po_id .
                        "&error=" .
                        rawurlencode('The approved fund amount is invalid.')
                    );
                    exit();
                }

                if (!in_array(
                    $release_method,
                    $allowed_release_methods,
                    true
                )) {
                    header(
                        "Location: ../release_funding.php?po_id=" . $po_id .
                        "&error=" .
                        rawurlencode('The supplier payment method is invalid.')
                    );
                    exit();
                }

                if (
                    $reference_number === '' ||
                    strlen($reference_number) > 100
                ) {
                    header(
                        "Location: ../release_funding.php?po_id=" . $po_id .
                        "&error=" .
                        rawurlencode('Enter a payment or receipt reference up to 100 characters.')
                    );
                    exit();
                }

                if (
                    $released_date_is_invalid ||
                    $released_datetime > new DateTime(
                        'now',
                        $funding_timezone
                    )
                ) {
                    header(
                        "Location: ../release_funding.php?po_id=" . $po_id .
                        "&error=" .
                        rawurlencode('Enter a valid non-future release date and time.')
                    );
                    exit();
                }

                if (strlen($funding_remarks) > 2000) {
                    header(
                        "Location: ../release_funding.php?po_id=" . $po_id .
                        "&error=" .
                        rawurlencode('Funding remarks must not exceed 2,000 characters.')
                    );
                    exit();
                }

                if (
                    !isset($_FILES['funding_proof']) ||
                    $_FILES['funding_proof']['error'] !== UPLOAD_ERR_OK ||
                    !is_uploaded_file($_FILES['funding_proof']['tmp_name'])
                ) {
                    header(
                        "Location: ../release_funding.php?po_id=" . $po_id .
                        "&error=" .
                        rawurlencode('Payment proof is required before releasing funding.')
                    );
                    exit();
                }

                $funding_proof = $_FILES['funding_proof'];
                $funding_extension = strtolower(
                    pathinfo($funding_proof['name'], PATHINFO_EXTENSION)
                );
                $allowed_funding_proofs = [
                    'pdf' => 'application/pdf',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                ];
                $funding_mime = (new finfo(FILEINFO_MIME_TYPE))
                    ->file($funding_proof['tmp_name']);

                if (
                    $funding_proof['size'] < 1 ||
                    $funding_proof['size'] > 10 * 1024 * 1024 ||
                    !isset($allowed_funding_proofs[$funding_extension]) ||
                    $funding_mime !==
                        $allowed_funding_proofs[$funding_extension]
                ) {
                    header(
                        "Location: ../release_funding.php?po_id=" . $po_id .
                        "&error=" .
                        rawurlencode('Payment proof must be a valid PDF, JPG, or PNG file up to 10 MB.')
                    );
                    exit();
                }

                $funding_directory =
                    __DIR__ . '/../uploads/fund_releases/';
                $stored_funding_absolute_path = null;

                try {
                    if (
                        !is_dir($funding_directory) &&
                        !mkdir($funding_directory, 0755, true) &&
                        !is_dir($funding_directory)
                    ) {
                        throw new RuntimeException(
                            'The fund-release proof directory could not be prepared.'
                        );
                    }

                    $conn->begin_transaction();

                    $funding_po_stmt = $conn->prepare(
                        "SELECT
                            po.po_number,
                            po.status,
                            po.requested_fund_amount,
                            po.supplier_detail_id,
                            supplier.supplier_name,
                            supplier.payment_method
                         FROM purchase_orders po
                         INNER JOIN pr_supplier_details supplier
                            ON supplier.supplier_detail_id =
                                po.supplier_detail_id
                           AND supplier.pr_id = po.pr_id
                           AND supplier.record_status = 'Active'
                         WHERE po.po_id = ?
                         LIMIT 1
                         FOR UPDATE"
                    );
                    $funding_po_stmt->bind_param('i', $po_id);
                    $funding_po_stmt->execute();
                    $funding_po =
                        $funding_po_stmt->get_result()->fetch_assoc();

                    if (
                        !$funding_po ||
                        $funding_po['status'] !== 'President-Approved'
                    ) {
                        throw new DomainException(
                            'This PO is no longer ready for supplier funding.'
                        );
                    }

                    enforce_po_task_ownership(
                        $conn,
                        $po_id,
                        $user_id,
                        'Finance'
                    );

                    $funding_rule_stmt = $conn->prepare(
                        "SELECT next_status, notify_target
                         FROM workflow_rules
                         WHERE current_status = 'President-Approved'
                           AND action_key = 'mark_funded'
                           AND required_role = 'Finance'
                         LIMIT 1"
                    );
                    $funding_rule_stmt->execute();
                    $funding_rule =
                        $funding_rule_stmt->get_result()->fetch_assoc();

                    if (
                        !$funding_rule ||
                        $funding_rule['next_status'] !== 'Funded'
                    ) {
                        throw new DomainException(
                            'The Finance funding workflow rule is unavailable.'
                        );
                    }

                    if (
                        !is_numeric(
                            $funding_po['requested_fund_amount']
                        ) ||
                        (float) $funding_po['requested_fund_amount'] <= 0
                    ) {
                        throw new DomainException(
                            'The PO does not contain a valid approved fund amount.'
                        );
                    }

                    $approved_requested_fund_amount = round(
                        (float) $funding_po['requested_fund_amount'],
                        2
                    );
                    $released_amount = round(
                        (float) $posted_release_amount,
                        2
                    );

                    if (
                        abs(
                            $released_amount -
                            $approved_requested_fund_amount
                        ) > 0.01
                    ) {
                        throw new DomainException(
                            'The released amount must match the approved PRF fund amount.'
                        );
                    }

                    if (
                        $release_method !==
                        $funding_po['payment_method']
                    ) {
                        throw new DomainException(
                            'The release method must match the Finance-approved supplier payment method.'
                        );
                    }

                    $existing_release_stmt = $conn->prepare(
                        "SELECT fund_release_id
                         FROM po_supplier_fund_releases
                         WHERE po_id = ?
                           AND record_status = 'Active'
                         LIMIT 1
                         FOR UPDATE"
                    );
                    $existing_release_stmt->bind_param('i', $po_id);
                    $existing_release_stmt->execute();

                    if (
                        $existing_release_stmt->get_result()->num_rows > 0
                    ) {
                        throw new DomainException(
                            'An active supplier fund-release record already exists for this PO.'
                        );
                    }

                    $cycle_stmt = $conn->prepare(
                        "SELECT
                            COALESCE(MAX(release_cycle), 0) + 1
                                AS next_cycle
                         FROM po_supplier_fund_releases
                         WHERE po_id = ?
                         FOR UPDATE"
                    );
                    $cycle_stmt->bind_param('i', $po_id);
                    $cycle_stmt->execute();
                    $release_cycle = (int) (
                        $cycle_stmt->get_result()
                            ->fetch_assoc()['next_cycle'] ?? 1
                    );

                    $stored_funding_file_name =
                        date('YmdHis') . '_fund_release_' .
                        bin2hex(random_bytes(12)) . '.' .
                        $funding_extension;
                    $stored_funding_absolute_path =
                        $funding_directory .
                        $stored_funding_file_name;

                    if (!move_uploaded_file(
                        $funding_proof['tmp_name'],
                        $stored_funding_absolute_path
                    )) {
                        throw new RuntimeException(
                            'The fund-release payment proof could not be saved.'
                        );
                    }

                    $funding_file_hash = hash_file(
                        'sha256',
                        $stored_funding_absolute_path
                    );
                    if (!$funding_file_hash) {
                        throw new RuntimeException(
                            'The fund-release proof hash could not be generated.'
                        );
                    }

                    $supplier_detail_id =
                        (int) $funding_po['supplier_detail_id'];
                    $released_at =
                        $released_datetime->format('Y-m-d H:i:s');
                    $funding_original_name = substr(
                        basename(
                            (string) $funding_proof['name']
                        ),
                        0,
                        255
                    );

                    $fund_release_stmt = $conn->prepare(
                        "INSERT INTO po_supplier_fund_releases (
                            po_id,
                            release_cycle,
                            supplier_detail_id,
                            approved_requested_fund_amount,
                            released_amount,
                            release_method,
                            reference_number,
                            released_at,
                            proof_original_name,
                            proof_file_path,
                            proof_file_hash,
                            remarks,
                            released_by
                         ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                         )"
                    );
                    $fund_release_stmt->bind_param(
                        'iiiddsssssssi',
                        $po_id,
                        $release_cycle,
                        $supplier_detail_id,
                        $approved_requested_fund_amount,
                        $released_amount,
                        $release_method,
                        $reference_number,
                        $released_at,
                        $funding_original_name,
                        $stored_funding_file_name,
                        $funding_file_hash,
                        $funding_remarks,
                        $user_id
                    );
                    $fund_release_stmt->execute();
                    $fund_release_id = (int) $conn->insert_id;

                    if ($fund_release_id < 1) {
                        throw new RuntimeException(
                            'The supplier fund-release record could not be saved.'
                        );
                    }

                    $new_funding_status = 'Funded';
                    // Phase 4: Procurement confirms supplier readiness and
                    // prepares the delivery request before Supply Chain plots it.
                    $new_funding_location = 'Procurement Dept.';
                    $funded_update_stmt = $conn->prepare(
                        "UPDATE purchase_orders
                         SET status = ?,
                             current_location = ?,
                             is_viewed = 0
                         WHERE po_id = ?
                           AND status = 'President-Approved'"
                    );
                    $funded_update_stmt->bind_param(
                        'ssi',
                        $new_funding_status,
                        $new_funding_location,
                        $po_id
                    );
                    $funded_update_stmt->execute();

                    if ($funded_update_stmt->affected_rows !== 1) {
                        throw new DomainException(
                            'The PO status changed before funding could be recorded.'
                        );
                    }

                    $funding_history_stmt = $conn->prepare(
                        "INSERT INTO po_history (
                            po_id,
                            changed_by,
                            status_from,
                            status_to,
                            remarks
                         ) VALUES (
                            ?,
                            ?,
                            'President-Approved',
                            'Funded',
                            ?
                         )"
                    );
                    $funding_history_remarks =
                        'Supplier funding released through ' .
                        $release_method . '. Reference: ' .
                        $reference_number . '.';
                    $funding_history_stmt->bind_param(
                        'iis',
                        $po_id,
                        $user_id,
                        $funding_history_remarks
                    );
                    $funding_history_stmt->execute();

                    $funding_notify_target = 'Procurement';
                    create_role_notification(
                        $conn,
                        $funding_notify_target,
                        'PO ' . $funding_po['po_number'] .
                            ' is funded with verified payment proof. Confirm supplier readiness and prepare its delivery request.'
                    );

                    complete_po_task_assignment(
                        $conn,
                        $po_id,
                        $user_id,
                        'Supplier funding released'
                    );

                    $next_funding_role_stmt = $conn->prepare(
                        "SELECT required_role
                         FROM workflow_rules
                         WHERE current_status = 'Funded'
                           AND action_key = 'create_delivery_request'
                           AND required_role = 'Procurement'
                         LIMIT 1"
                    );
                    $next_funding_role_stmt->execute();
                    $next_funding_role = $next_funding_role_stmt
                        ->get_result()
                        ->fetch_assoc();

                    if (!$next_funding_role) {
                        throw new DomainException(
                            'The Procurement delivery-request workflow rule is currently unavailable. Please ask an administrator to review the workflow configuration.'
                        );
                    }

                    $assigned_procurement_user = auto_assign_po_hybrid(
                        $conn,
                        $po_id,
                        'Procurement',
                        $user_id
                    );
                    if (!$assigned_procurement_user) {
                        throw new DomainException(
                            'No active Procurement user is available for delivery coordination.'
                        );
                    }

                    log_audit_action(
                        $conn,
                        $user_id,
                        'RELEASE_SUPPLIER_FUNDING',
                        'Released supplier funding for PO ' .
                            $funding_po['po_number'] . '.',
                        ['status' => 'President-Approved'],
                        [
                            'status' => 'Funded',
                            'fund_release_id' => $fund_release_id,
                            'supplier_name' =>
                                $funding_po['supplier_name'],
                            'released_amount' => $released_amount,
                            'release_method' => $release_method,
                            'reference_number' => $reference_number,
                            'released_at' => $released_at,
                        ]
                    );

                    if (!$conn->commit()) {
                        throw new RuntimeException(
                            'The supplier funding transaction could not be completed.'
                        );
                    }

                    header(
                        "Location: ../view_po.php?id=" . $po_id .
                        "&success=" .
                        rawurlencode(
                            'Supplier funding recorded and forwarded to Procurement for delivery coordination.'
                        )
                    );
                } catch (Throwable $e) {
                    $conn->rollback();

                    if (
                        $stored_funding_absolute_path &&
                        is_file($stored_funding_absolute_path)
                    ) {
                        unlink($stored_funding_absolute_path);
                    }

                    drms_log_workflow_failure(
                        'Supplier funding release for PO ' . $po_id,
                        $e
                    );

                    $funding_public_error =
                        $e instanceof DomainException
                            ? $e->getMessage()
                            : 'The supplier funding record could not be completed. Please try again.';

                    $funding_public_error = drms_public_feedback_message(
                        $funding_public_error,
                        'The supplier funding record could not be completed. No workflow changes were saved. Please try again.'
                    );

                    header(
                        "Location: ../release_funding.php?po_id=" .
                        $po_id . "&error=" .
                        rawurlencode($funding_public_error)
                    );
                }
                exit();
        }

        // Every approval/rejection must match a rule for both the current status and the logged-in role.
        try {
            $conn->begin_transaction();
            $po_stmt = $conn->prepare("SELECT po_number, status FROM purchase_orders WHERE po_id = ? FOR UPDATE");
            $po_stmt->bind_param("i", $po_id);
            $po_stmt->execute();
            $po = $po_stmt->get_result()->fetch_assoc();
            if (!$po) {
                throw new Exception('Purchase Order not found.');
            }

            $rule_stmt = $conn->prepare("SELECT next_status, notify_target FROM workflow_rules WHERE current_status = ? AND action_key = ? AND required_role = ? LIMIT 1");
            $rule_stmt->bind_param("sss", $po['status'], $action, $_SESSION['role']);
            $rule_stmt->execute();
            $rule = $rule_stmt->get_result()->fetch_assoc();
            if (!$rule) {
                throw new Exception('This action is not allowed for your role at the current PO status.');
            }
            enforce_po_task_ownership($conn, $po_id, $user_id, $_SESSION['role']);

            $location_map = [
                'approve_gm' => 'Finance Dept.',
                'approve_finance' => 'Office of the President',
                'approve_president' => 'Finance Dept.',
                'mark_funded' => 'Supply Chain Dept.',
                'reject' => 'Voided'
            ];
            $new_status = $rule['next_status'];
            $new_location = $location_map[$action] ?? $po['status'];
            $remarks = trim($_POST['remarks'] ?? '');

            if ($action === 'reject' && $remarks === '') {
                throw new Exception('A rejection reason is required.');
            }

            if ($action === 'reject') {
                $update = $conn->prepare("UPDATE purchase_orders SET status = ?, current_location = ?, remarks = ?, is_viewed = 0 WHERE po_id = ? AND status = ?");
                $update->bind_param("sssis", $new_status, $new_location, $remarks, $po_id, $po['status']);
            } else {
                $update = $conn->prepare("UPDATE purchase_orders SET status = ?, current_location = ?, is_viewed = 0 WHERE po_id = ? AND status = ?");
                $update->bind_param("ssis", $new_status, $new_location, $po_id, $po['status']);
            }
            $update->execute();
            if ($update->affected_rows !== 1) {
                throw new Exception('The PO status changed before the action could be saved.');
            }

            $history_stmt = $conn->prepare("INSERT INTO po_history (po_id, changed_by, status_from, status_to, remarks) VALUES (?, ?, ?, ?, ?)");
            $history_stmt->bind_param("iisss", $po_id, $user_id, $po['status'], $new_status, $remarks);
            $history_stmt->execute();

            if (!empty($rule['notify_target'])) {
                $notification = "PO {$po['po_number']} is now $new_status.";
                create_role_notification($conn, $rule['notify_target'], $notification);
            }

            complete_po_task_assignment($conn, $po_id, $user_id, 'Completed through ' . $action);

            // Awtomatikong i-assign sa next role
            $req_stmt = $conn->prepare("SELECT required_role FROM workflow_rules WHERE current_status = ? LIMIT 1");
            $req_stmt->bind_param("s", $new_status);
            $req_stmt->execute();
            $req_res = $req_stmt->get_result()->fetch_assoc();
            if ($req_res && !empty($req_res['required_role'])) {
                auto_assign_po_hybrid($conn, $po_id, $req_res['required_role'], $user_id);
            }

            $audit_action = $action === 'reject' ? 'REJECT_PO' : 'WORKFLOW_ACTION';
            log_audit_action($conn, $user_id, $audit_action, "PO {$po['po_number']}: {$po['status']} to $new_status", ['status' => $po['status']], ['status' => $new_status, 'remarks' => $remarks]);
            $conn->commit();
            header("Location: ../view_po.php?id=$po_id&success=PO Updated Successfully");
        } catch (Exception $e) {
            $conn->rollback();
            drms_log_workflow_failure(
                'PO workflow action for PO ' . $po_id,
                $e
            );
            po_handler_redirect(
                '../view_po.php?id=' . $po_id,
                'error',
                $e->getMessage()
            );
        }
        exit();
    }

    if ($action == 'archive') {
        $allowed = ['GM', 'President', 'Procurement'];
        if (!in_array($_SESSION['role'], $allowed, true)) {
            po_handler_redirect(
                '../po_list.php',
                'error',
                'Your account is not allowed to archive this document.'
            );
        }

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id);

        try {
            $stmt = $conn->prepare("UPDATE documents SET status = 'Archived' WHERE doc_id = ?");
            $stmt->bind_param("i", $doc_id);
            
            if ($stmt->execute()) {
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'ARCHIVE_FILE', $doc_id, "Archived document ID: $doc_id", $redirectUrl);
                } else {
                    log_audit_action($conn, $user_id, 'ARCHIVE_FILE', "Archived document ID: $doc_id");
                }
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Archived");
            } else {
                throw new Exception("Execute failed");
            }
        } catch (Exception $e) {
            drms_log_workflow_failure('Document archive', $e);
            po_handler_redirect(
                $redirectUrl,
                'error',
                'The document could not be archived. Please try again.'
            );
        }
        exit();
    }

    if ($action == 'restore') {
        $allowed = ['GM', 'President', 'Procurement'];
        if (!in_array($_SESSION['role'], $allowed, true)) {
            po_handler_redirect(
                '../po_list.php',
                'error',
                'Your account is not allowed to restore this document.'
            );
        }

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id);
        
        try {
            $stmt = $conn->prepare("UPDATE documents SET status = 'Active' WHERE doc_id = ?");
            $stmt->bind_param("i", $doc_id);
            
            if ($stmt->execute()) {
                if (function_exists('log_document_action')) {
                    log_document_action($conn, $user_id, 'RESTORE_FILE', $doc_id, "Restored document ID: $doc_id", $redirectUrl);
                } else {
                    log_audit_action($conn, $user_id, 'RESTORE_FILE', "Restored document ID: $doc_id");
                }
                header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Restored");
            } else {
                throw new Exception("Execute failed");
            }
        } catch (Exception $e) {
            drms_log_workflow_failure('Document restore', $e);
            po_handler_redirect(
                $redirectUrl,
                'error',
                'The document could not be restored. Please try again.'
            );
        }
        exit();
    }

    if ($action == 'delete') {
        $allowed = ['GM', 'President', 'Procurement'];
        if (!in_array($_SESSION['role'], $allowed, true)) {
            po_handler_redirect(
                '../po_list.php',
                'error',
                'Your account is not allowed to delete this document.'
            );
        }

        $doc_id = intval($_POST['doc_id']);
        $redirectUrl = getRedirectUrl($conn, $doc_id);
        
        $stmt = $conn->prepare("SELECT file_path, file_name FROM documents WHERE doc_id = ?");
        $stmt->bind_param("i", $doc_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if($res->num_rows > 0) {
            $file = $res->fetch_assoc();
            
            $fixedUploadDir = "../uploads/";
            $safeFileName = basename($file['file_name']); 
            $physicalPath = $fixedUploadDir . $safeFileName;

            if (file_exists($physicalPath)) {
                unlink($physicalPath);
            }
            
            $del = $conn->prepare("DELETE FROM documents WHERE doc_id = ?");
            $del->bind_param("i", $doc_id);
            $del->execute();
            
            $desc = "Deleted file: " . $file['file_name'];
            if (function_exists('log_document_action')) {
                log_document_action($conn, $user_id, 'DELETE_FILE', $doc_id, $desc, $redirectUrl);
            } else {
                log_audit_action($conn, $user_id, 'DELETE_FILE', $desc);
            }
        }
        
        header("Location: " . $redirectUrl . (strpos($redirectUrl, '?') ? '&' : '?') . "success=Deleted");
        exit();
    }

    if ($action == 'upload' || isset($_FILES['document'])) {
        $po_id = isset($_POST['po_id']) && !empty($_POST['po_id']) ? intval($_POST['po_id']) : null;
        $doc_type = $_POST['doc_type'] ?? 'General'; 
        $redirectUrl = getRedirectUrl($conn, null, $po_id);

        if (
            !isset($_FILES['document']) ||
            ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {
            po_handler_redirect(
                $redirectUrl,
                'error',
                'Select a valid file and try the upload again.'
            );
        }
        $file = $_FILES['document'];

        $max_file_size = 10 * 1024 * 1024;
        if ($file['size'] > $max_file_size) {
            po_handler_redirect(
                $redirectUrl,
                'error',
                'The selected file is too large. Choose a file no larger than 10 MB.'
            );
        }

        $allowedMimeTypes = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'
        ];

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedMimeTypes, true)) {
            po_handler_redirect(
                $redirectUrl,
                'error',
                'The selected file type is not allowed. Choose a PDF, image, Word, or Excel file.'
            );
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $fileMimeType = $finfo->file($file['tmp_name']);
        if (!array_key_exists($fileMimeType, $allowedMimeTypes)) {
            po_handler_redirect(
                $redirectUrl,
                'error',
                'The selected file could not be verified. Choose a valid document or image file.'
            );
        }

        $fileHash = hash_file('sha256', $file['tmp_name']);
        $checkStmt = $conn->prepare("SELECT doc_id FROM documents WHERE file_hash = ?");
        $checkStmt->bind_param("s", $fileHash);
        $checkStmt->execute();
        
        if ($checkStmt->get_result()->num_rows > 0) {
            po_handler_redirect(
                $redirectUrl,
                'error',
                'This file has already been uploaded. Review the existing attachment or choose another file.'
            );
        }

        $uploadDir = "../uploads/"; 
        $dbDir = "uploads/";        

        if (!file_exists($uploadDir)) mkdir($uploadDir, 0755, true);

        $newFileName = time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
        $targetPath = $uploadDir . $newFileName; 
        $dbPath = $dbDir . $newFileName;         

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            if ($po_id === null) {
                $stmt = $conn->prepare("INSERT INTO documents (po_id, doc_type, file_name, file_path, file_hash, uploaded_by, status) VALUES (NULL, ?, ?, ?, ?, ?, 'Active')");
                $stmt->bind_param("ssssi", $doc_type, $newFileName, $dbPath, $fileHash, $user_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO documents (po_id, doc_type, file_name, file_path, file_hash, uploaded_by, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
                $stmt->bind_param("issssi", $po_id, $doc_type, $newFileName, $dbPath, $fileHash, $user_id);
            }
            
            if($stmt->execute()) {
                log_audit_action($conn, $user_id, 'UPLOAD', "Uploaded file: $newFileName");
                po_handler_redirect(
                    $redirectUrl,
                    'success',
                    'File uploaded successfully.'
                );
            } else {
                if (is_file($targetPath)) {
                    unlink($targetPath);
                }
                po_handler_redirect(
                    $redirectUrl,
                    'error',
                    'The file could not be saved. No attachment was added. Please try again.'
                );
            }
        } else {
            po_handler_redirect(
                $redirectUrl,
                'error',
                'The file could not be uploaded. Check the file and try again.'
            );
        }
    }
}
?>



