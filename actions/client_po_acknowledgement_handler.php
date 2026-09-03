<?php
session_start();

require '../config/db_connect.php';
require '../config/functions.php';
require_once '../config/workflow_access.php';
require_once '../config/client_po_acknowledgement.php';
require_once '../config/workflow_feedback.php';

date_default_timezone_set('Asia/Manila');

drms_require_workflow_roles(['GM'], '../dashboard.php', '../index.php');

function phase6b2_redirect(int $quotation_id, string $type, string $message): void
{
    $target = '../view_quotation.php?id=' . $quotation_id;
    $public_message = $type === 'error'
        ? drms_public_feedback_message(
            $message,
            'The Client PO review could not be completed. No workflow changes were saved.'
        )
        : drms_feedback_clean_text($message);
    drms_redirect_with_feedback($target, $type, $public_message);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php');
    exit();
}

$quotation_id = (int) ($_POST['quotation_id'] ?? 0);
$approval_record_id = (int) ($_POST['approval_record_id'] ?? 0);
$decision = trim((string) ($_POST['decision'] ?? ''));
$remarks = trim((string) ($_POST['remarks'] ?? ''));

if (
    !isset($_POST['csrf_token']) ||
    !hash_equals(
        (string) ($_SESSION['csrf_token'] ?? ''),
        (string) $_POST['csrf_token']
    )
) {
    phase6b2_redirect(
        $quotation_id,
        'error',
        'Your session verification expired. Please reload the page and try again.'
    );
}

if ($quotation_id < 1 || $approval_record_id < 1) {
    phase6b2_redirect(0, 'error', 'The selected Client PO is invalid.');
}

if (!in_array($decision, ['Acknowledged', 'Returned'], true)) {
    phase6b2_redirect($quotation_id, 'error', 'Select a valid review action.');
}

if (strlen($remarks) > 1000) {
    phase6b2_redirect($quotation_id, 'error', 'Remarks must not exceed 1,000 characters.');
}

if ($decision === 'Acknowledged' && (string) ($_POST['confirmation'] ?? '') !== '1') {
    phase6b2_redirect(
        $quotation_id,
        'error',
        'Confirm that you reviewed the official Client PO before signing.'
    );
}

if ($decision === 'Returned' && strlen($remarks) < 10) {
    phase6b2_redirect(
        $quotation_id,
        'error',
        'Enter a clear return reason with at least 10 characters.'
    );
}

if (!phase6b2_is_installed($conn)) {
    phase6b2_redirect(
        $quotation_id,
        'error',
        'The Client PO review configuration is unavailable. Please ask an administrator to review the workflow setup.'
    );
}

if (
    $decision === 'Acknowledged' &&
    (
        !function_exists('drms_official_source_linkage_is_installed') ||
        !drms_official_source_linkage_is_installed($conn) ||
        !function_exists('drms_official_folder_schema_is_installed') ||
        !drms_official_folder_schema_is_installed($conn)
    )
) {
    phase6b2_redirect(
        $quotation_id,
        'error',
        'Install the controlled Official Records folder migration before acknowledging an official Client PO.'
    );
}

$transaction_started = false;
$official_record_storage_path = null;
$official_record_number = null;
$official_record_doc_id = null;

try {
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('The review transaction could not be started.');
    }
    $transaction_started = true;

    $quotation_statement = $conn->prepare(
        "SELECT
            q.quotation_id,
            q.quotation_number,
            q.client_name,
            q.client_po_number,
            q.status,
            q.created_by
         FROM quotations q
         WHERE q.quotation_id = ?
         FOR UPDATE"
    );
    $quotation_statement->bind_param('i', $quotation_id);
    $quotation_statement->execute();
    $quotation = $quotation_statement->get_result()->fetch_assoc();

    if (!$quotation || $quotation['status'] !== 'For GM Acknowledgement') {
        throw new RuntimeException(
            'This Client PO is no longer waiting for General Manager review.'
        );
    }

    $official_po_statement = $conn->prepare(
         "SELECT
            approval_record_id,
            internal_reference,
            actual_client_po_number,
            client_po_date,
            final_approval_date,
            proof_original_name,
            proof_file_path,
            proof_file_hash,
            recorded_by,
            record_status
         FROM client_approval_records
         WHERE approval_record_id = ?
           AND quotation_id = ?
           AND record_type = 'Official Client PO'
         FOR UPDATE"
    );
    $official_po_statement->bind_param(
        'ii',
        $approval_record_id,
        $quotation_id
    );
    $official_po_statement->execute();
    $official_po = $official_po_statement->get_result()->fetch_assoc();

    if (!$official_po || $official_po['record_status'] !== 'Active') {
        throw new RuntimeException('The active official Client PO record was not found.');
    }

    if (
        empty($official_po['actual_client_po_number']) ||
        empty($official_po['client_po_date']) ||
        empty($official_po['final_approval_date']) ||
        empty($official_po['proof_file_path'])
    ) {
        throw new RuntimeException(
            'The official Client PO is incomplete and cannot be acknowledged.'
        );
    }

    if ($decision === 'Acknowledged') {
        $official_po_path = dirname(__DIR__) . DIRECTORY_SEPARATOR .
            'uploads' . DIRECTORY_SEPARATOR . 'pos' . DIRECTORY_SEPARATOR .
            basename((string) $official_po['proof_file_path']);

        if (!is_file($official_po_path)) {
            throw new RuntimeException(
                'The attached official Client PO file is missing and cannot be signed.'
            );
        }
    }

    $existing_statement = $conn->prepare(
        "SELECT acknowledgement_id
         FROM client_po_internal_acknowledgements
         WHERE approval_record_id = ?
         LIMIT 1
         FOR UPDATE"
    );
    $existing_statement->bind_param('i', $approval_record_id);
    $existing_statement->execute();

    if ($existing_statement->get_result()->num_rows > 0) {
        throw new RuntimeException('This official Client PO was already reviewed.');
    }

    $gm_id = (int) $_SESSION['user_id'];
    $gm_statement = $conn->prepare(
        "SELECT full_name
         FROM users
         WHERE user_id = ?
           AND role = 'GM'
           AND status = 'Active'
         LIMIT 1
         FOR UPDATE"
    );
    $gm_statement->bind_param('i', $gm_id);
    $gm_statement->execute();
    $gm = $gm_statement->get_result()->fetch_assoc();

    if (!$gm) {
        throw new RuntimeException('Your active General Manager account could not be verified.');
    }

    $signatory_name = (string) $gm['full_name'];
    $signatory_role = 'GM';
    $method = $decision === 'Acknowledged'
        ? 'Authenticated Digital Sign-off'
        : 'Authenticated Review';
    $stored_remarks = $remarks !== '' ? $remarks : null;
    $acted_at = date('Y-m-d H:i:s');

    $insert_statement = $conn->prepare(
        "INSERT INTO client_po_internal_acknowledgements (
            approval_record_id,
            quotation_id,
            decision,
            acknowledgement_method,
            signatory_name,
            signatory_role,
            remarks,
            acted_by,
            acted_at,
            record_status
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')"
    );
    $insert_statement->bind_param(
        'iisssssis',
        $approval_record_id,
        $quotation_id,
        $decision,
        $method,
        $signatory_name,
        $signatory_role,
        $stored_remarks,
        $gm_id,
        $acted_at
    );
    $insert_statement->execute();
    $acknowledgement_id = (int) $conn->insert_id;

    if ($decision === 'Acknowledged') {
        $quotation_update = $conn->prepare(
            "UPDATE quotations
             SET status = 'PO Received'
             WHERE quotation_id = ?
               AND status = 'For GM Acknowledgement'"
        );
        $quotation_update->bind_param('i', $quotation_id);
        $quotation_update->execute();

        if ($quotation_update->affected_rows !== 1) {
            throw new RuntimeException(
                'The quotation status changed before the sign-off was completed.'
            );
        }

        $official_record = drms_file_existing_source_as_official_record(
            $conn,
            $official_po_path,
            (string) $official_po['proof_original_name'],
            (string) $official_po['proof_file_hash'],
            'Signed Client Purchase Orders',
            'Signed Client Purchase Order',
            $gm_id,
            $acted_at,
            'Client PO Approval',
            $approval_record_id,
            (string) $official_po['actual_client_po_number'],
            !empty($official_po['recorded_by'])
                ? (int) $official_po['recorded_by']
                : $gm_id,
            null,
            'client po,quotation ' . $quotation['quotation_number'] .
                ',approval ' . $official_po['internal_reference']
        );
        $official_record_number = (string) $official_record['record_number'];
        $official_record_doc_id = (int) $official_record['doc_id'];
        $official_record_storage_path =
            $official_record['storage_absolute_path'] ?? null;

        $sales_message = sprintf(
            'GM acknowledged Client PO %s for %s. Official Record %s was filed and the PRF can now be prepared.',
            $official_po['actual_client_po_number'],
            $quotation['quotation_number'],
            $official_record_number
        );
        $sales_key = 'client-po:acknowledged:' . $approval_record_id;
        $success_message =
            'Official Client PO acknowledged and filed as ' .
            $official_record_number . '. Sales may now prepare the PRF.';
        $audit_action = 'ACKNOWLEDGE_CLIENT_PO';
        $audit_description = sprintf(
            'Acknowledged official Client PO %s for Quotation %s and filed Official Record %s.',
            $official_po['actual_client_po_number'],
            $quotation['quotation_number'],
            $official_record_number
        );
    } else {
        $record_update = $conn->prepare(
            "UPDATE client_approval_records
             SET record_status = 'Returned'
             WHERE approval_record_id = ?
               AND record_status = 'Active'"
        );
        $record_update->bind_param('i', $approval_record_id);
        $record_update->execute();

        if ($record_update->affected_rows !== 1) {
            throw new RuntimeException('The official Client PO could not be returned.');
        }

        $quotation_update = $conn->prepare(
            "UPDATE quotations
             SET
                client_po_number = NULL,
                approval_mode = 'Formal PO',
                po_file_path = NULL,
                status = 'Pending Approval'
             WHERE quotation_id = ?
               AND status = 'For GM Acknowledgement'"
        );
        $quotation_update->bind_param('i', $quotation_id);
        $quotation_update->execute();

        if ($quotation_update->affected_rows !== 1) {
            throw new RuntimeException(
                'The quotation status changed before the return was completed.'
            );
        }

        $sales_message = sprintf(
            'GM returned Client PO %s for %s. Reason: %s',
            $official_po['actual_client_po_number'],
            $quotation['quotation_number'],
            $remarks
        );
        $sales_key = 'client-po:returned:' . $approval_record_id;
        $success_message =
            'Official Client PO returned to Sales for correction.';
        $audit_action = 'RETURN_CLIENT_PO';
        $audit_description = sprintf(
            'Returned official Client PO %s for Quotation %s.',
            $official_po['actual_client_po_number'],
            $quotation['quotation_number']
        );
    }

    if (!empty($quotation['created_by'])) {
        phase6b2_create_notification(
            $conn,
            'Sales Staff',
            $sales_message,
            'view_quotation.php?id=' . $quotation_id,
            $sales_key,
            (int) $quotation['created_by']
        );
    }

    phase6b2_mark_notification_read(
        $conn,
        'client-po:gm-review:' . $approval_record_id
    );

    log_audit_action(
        $conn,
        $gm_id,
        $audit_action,
        $audit_description,
        null,
        [
            'acknowledgement_id' => $acknowledgement_id,
            'approval_record_id' => $approval_record_id,
            'quotation_id' => $quotation_id,
            'quotation_number' => $quotation['quotation_number'],
            'client_po_number' => $official_po['actual_client_po_number'],
            'decision' => $decision,
            'method' => $method,
            'remarks' => $stored_remarks,
            'official_record_doc_id' => $official_record_doc_id,
            'official_record_number' => $official_record_number,
        ]
    );

    $conn->commit();
    $transaction_started = false;
    $official_record_storage_path = null;

    phase6b2_redirect($quotation_id, 'success', $success_message);
} catch (Throwable $exception) {
    if ($transaction_started) {
        $conn->rollback();
    }

    if (
        $official_record_storage_path !== null &&
        is_file($official_record_storage_path)
    ) {
        @unlink($official_record_storage_path);
    }

    drms_log_workflow_failure(
        'Client PO GM acknowledgement for quotation ' . $quotation_id,
        $exception
    );

    $safe_message = $exception->getMessage();

    phase6b2_redirect($quotation_id, 'error', $safe_message);
}

