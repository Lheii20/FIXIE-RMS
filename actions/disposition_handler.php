<?php
session_start();
require_once '../config/db_connect.php';
require_once '../config/functions.php';

function dispositionRedirect(string $type, string $message): void
{
    $type = $type === 'success' ? 'success' : 'error';
    header('Location: ../documents.php?disposition=1&' . $type . '=' . urlencode($message));
    exit();
}

function dispositionTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function notifyDispositionApprovers(mysqli $conn, int $requestId, int $requesterId, string $fileName): void
{
    $approverStmt = $conn->prepare(
        "SELECT DISTINCT u.user_id
           FROM users u
           JOIN user_permissions up ON up.user_id = u.user_id
          WHERE up.permission_name = 'can_approve_disposition'
            AND u.status = 'Active'
            AND u.user_id <> ?"
    );
    $approverStmt->bind_param('i', $requesterId);
    $approverStmt->execute();
    $approvers = $approverStmt->get_result();

    $message = "Disposition request #$requestId for '$fileName' is waiting for your independent review.";
    $targetUrl = 'documents.php?disposition=1';
    $insert = $conn->prepare(
        "INSERT IGNORE INTO notifications
            (recipient_user_id, message, target_url, notification_key, created_at)
         VALUES (?, ?, ?, ?, NOW())"
    );

    while ($approver = $approvers->fetch_assoc()) {
        $approverId = (int) $approver['user_id'];
        $notificationKey = "disposition:request:$requestId:review:$approverId";
        $insert->bind_param('isss', $approverId, $message, $targetUrl, $notificationKey);
        $insert->execute();
    }
}

function notifyDispositionRequester(mysqli $conn, array $request, string $decision): void
{
    $requesterId = (int) $request['requested_by'];
    $requestId = (int) $request['request_id'];
    $fileName = $request['file_name'];
    $message = "Disposition request #$requestId for '$fileName' was $decision.";
    $targetUrl = 'documents.php?disposition=1';
    $notificationKey = "disposition:request:$requestId:$decision:$requesterId";

    $stmt = $conn->prepare(
        "INSERT IGNORE INTO notifications
            (recipient_user_id, message, target_url, notification_key, created_at)
         VALUES (?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param('isss', $requesterId, $message, $targetUrl, $notificationKey);
    $stmt->execute();
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['role'])) {
    dispositionRedirect('error', 'Unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dispositionRedirect('error', 'Invalid request method.');
}

$sessionToken = $_SESSION['csrf_token'] ?? '';
$requestToken = $_POST['csrf_token'] ?? '';
if ($sessionToken === '' || !hash_equals($sessionToken, $requestToken)) {
    dispositionRedirect('error', 'Invalid security token. Refresh the page and try again.');
}

$userId = (int) $_SESSION['user_id'];
$action = trim($_POST['action'] ?? '');

try {
    if ($action === 'request_disposition') {
        if (!has_permission($conn, $userId, 'can_manage_disposition')) {
            dispositionRedirect('error', 'You do not have permission to submit disposition requests.');
        }

        $docId = (int) ($_POST['doc_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($docId < 1) {
            dispositionRedirect('error', 'Select a valid document.');
        }
        if (dispositionTextLength($reason) < 10 || dispositionTextLength($reason) > 1000) {
            dispositionRedirect('error', 'Disposition reason must contain 10 to 1000 characters.');
        }

        $conn->begin_transaction();

        $docStmt = $conn->prepare(
            "SELECT d.doc_id, d.file_name, d.record_phase, d.status,
                    d.disposition_status, d.is_legal_hold,
                    p.policy_id, p.policy_name, p.active_years, p.active_months,
                    p.archive_years, p.archive_months, p.action_after_retention,
                    DATE_ADD(
                        DATE_ADD(
                            COALESCE(d.declared_at, d.uploaded_at),
                            INTERVAL (COALESCE(p.active_years, 0) + COALESCE(p.archive_years, 0)) YEAR
                        ),
                        INTERVAL (COALESCE(p.active_months, 0) + COALESCE(p.archive_months, 0)) MONTH
                    ) AS retention_due_at
               FROM documents d
               LEFT JOIN document_categories dc ON dc.sub_category = d.category
               LEFT JOIN retention_policies p ON p.policy_id = COALESCE(d.policy_id, dc.policy_id)
              WHERE d.doc_id = ?
              LIMIT 1
              FOR UPDATE"
        );
        $docStmt->bind_param('i', $docId);
        $docStmt->execute();
        $document = $docStmt->get_result()->fetch_assoc();

        if (!$document) {
            throw new RuntimeException('Document not found.');
        }
        if ($document['record_phase'] !== 'Official') {
            throw new RuntimeException('Only Official Records can enter disposition review.');
        }
        if ((int) $document['is_legal_hold'] === 1) {
            throw new RuntimeException('This record is under Legal Hold and cannot enter disposition review.');
        }
        if (empty($document['policy_id']) || empty($document['retention_due_at'])) {
            throw new RuntimeException('Assign a valid retention policy before requesting disposition.');
        }
        if (strtotime($document['retention_due_at']) > time()) {
            throw new RuntimeException('This record has not completed its retention period.');
        }

        if ($document['status'] !== 'Archived' || $document['disposition_status'] !== 'Ready for Disposition') {
            $readyStmt = $conn->prepare(
                "UPDATE documents
                    SET status = 'Archived', disposition_status = 'Ready for Disposition'
                  WHERE doc_id = ?"
            );
            $readyStmt->bind_param('i', $docId);
            $readyStmt->execute();
        }

        $activeStmt = $conn->prepare(
            "SELECT request_id
               FROM disposition_requests
              WHERE doc_id = ?
                AND status IN ('Pending', 'Approved')
              LIMIT 1
              FOR UPDATE"
        );
        $activeStmt->bind_param('i', $docId);
        $activeStmt->execute();
        if ($activeStmt->get_result()->num_rows > 0) {
            throw new RuntimeException('This record already has an active disposition request.');
        }

        $requestedAction = $document['action_after_retention'];
        $policyId = (int) $document['policy_id'];
        $retentionAuthority = sprintf(
            'Retention Policy: %s; Active: %dY %dM; Archive: %dY %dM',
            $document['policy_name'],
            (int) $document['active_years'],
            (int) $document['active_months'],
            (int) $document['archive_years'],
            (int) $document['archive_months']
        );

        $insertStmt = $conn->prepare(
            "INSERT INTO disposition_requests
                (doc_id, requested_action, reason, retention_policy_id,
                 retention_authority, requested_by, status)
             VALUES (?, ?, ?, ?, ?, ?, 'Pending')"
        );
        $insertStmt->bind_param(
            'issisi',
            $docId,
            $requestedAction,
            $reason,
            $policyId,
            $retentionAuthority,
            $userId
        );
        $insertStmt->execute();
        $requestId = (int) $insertStmt->insert_id;

        $conn->commit();

        try {
            log_document_action(
                $conn,
                $userId,
                'REQUEST_DISPOSITION',
                $docId,
                "Submitted disposition request #$requestId for $requestedAction.",
                'documents.php?disposition=1'
            );
            notifyDispositionApprovers($conn, $requestId, $userId, $document['file_name']);
        } catch (Throwable $sideEffectError) {
            error_log('Disposition request audit/notification warning: ' . $sideEffectError->getMessage());
        }

        dispositionRedirect('success', "Disposition request #$requestId was submitted for independent review.");
    }

    if (in_array($action, ['approve_disposition', 'reject_disposition'], true)) {
        if (!has_permission($conn, $userId, 'can_approve_disposition')) {
            dispositionRedirect('error', 'You do not have permission to review disposition requests.');
        }

        $requestId = (int) ($_POST['request_id'] ?? 0);
        $reviewNotes = trim($_POST['review_notes'] ?? '');
        if ($requestId < 1) {
            dispositionRedirect('error', 'Select a valid disposition request.');
        }
        if (dispositionTextLength($reviewNotes) > 1000) {
            dispositionRedirect('error', 'Review notes cannot exceed 1000 characters.');
        }
        if ($action === 'reject_disposition' && dispositionTextLength($reviewNotes) < 10) {
            dispositionRedirect('error', 'Provide at least 10 characters explaining the rejection.');
        }

        $conn->begin_transaction();
        $requestStmt = $conn->prepare(
            "SELECT r.*, d.file_name, d.record_phase, d.disposition_status,
                    d.is_legal_hold
               FROM disposition_requests r
               JOIN documents d ON d.doc_id = r.doc_id
              WHERE r.request_id = ?
              LIMIT 1
              FOR UPDATE"
        );
        $requestStmt->bind_param('i', $requestId);
        $requestStmt->execute();
        $request = $requestStmt->get_result()->fetch_assoc();

        if (!$request) {
            throw new RuntimeException('Disposition request not found.');
        }
        if ($request['status'] !== 'Pending') {
            throw new RuntimeException('Only a Pending request can be reviewed.');
        }
        if ((int) $request['requested_by'] === $userId) {
            throw new RuntimeException('The requester cannot review their own disposition request.');
        }
        if ($request['record_phase'] !== 'Official' || $request['disposition_status'] !== 'Ready for Disposition') {
            throw new RuntimeException('The linked record is no longer eligible for disposition review.');
        }
        if ((int) $request['is_legal_hold'] === 1) {
            throw new RuntimeException('The linked record is under Legal Hold.');
        }

        $decision = $action === 'approve_disposition' ? 'Approved' : 'Rejected';
        $updateStmt = $conn->prepare(
            "UPDATE disposition_requests
                SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
              WHERE request_id = ? AND status = 'Pending'"
        );
        $updateStmt->bind_param('sisi', $decision, $userId, $reviewNotes, $requestId);
        $updateStmt->execute();
        if ($updateStmt->affected_rows !== 1) {
            throw new RuntimeException('The request changed before the review was saved. Refresh and try again.');
        }

        $conn->commit();

        try {
            $auditAction = $decision === 'Approved' ? 'APPROVE_DISPOSITION' : 'REJECT_DISPOSITION';
            log_document_action(
                $conn,
                $userId,
                $auditAction,
                (int) $request['doc_id'],
                "$decision disposition request #$requestId.",
                'documents.php?disposition=1'
            );
            notifyDispositionRequester($conn, $request, strtolower($decision));
        } catch (Throwable $sideEffectError) {
            error_log('Disposition review audit/notification warning: ' . $sideEffectError->getMessage());
        }

        dispositionRedirect('success', "Disposition request #$requestId was " . strtolower($decision) . '.');
    }

    if ($action === 'cancel_disposition') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        if ($requestId < 1) {
            dispositionRedirect('error', 'Select a valid disposition request.');
        }

        $conn->begin_transaction();
        $requestStmt = $conn->prepare(
            "SELECT r.request_id, r.doc_id, r.requested_by, r.status, d.file_name
               FROM disposition_requests r
               JOIN documents d ON d.doc_id = r.doc_id
              WHERE r.request_id = ?
              LIMIT 1
              FOR UPDATE"
        );
        $requestStmt->bind_param('i', $requestId);
        $requestStmt->execute();
        $request = $requestStmt->get_result()->fetch_assoc();

        if (!$request) {
            throw new RuntimeException('Disposition request not found.');
        }
        if ((int) $request['requested_by'] !== $userId) {
            throw new RuntimeException('Only the requester can cancel this request.');
        }
        if ($request['status'] !== 'Pending') {
            throw new RuntimeException('Only a Pending request can be cancelled.');
        }

        $cancelStmt = $conn->prepare(
            "UPDATE disposition_requests
                SET status = 'Cancelled'
              WHERE request_id = ? AND requested_by = ? AND status = 'Pending'"
        );
        $cancelStmt->bind_param('ii', $requestId, $userId);
        $cancelStmt->execute();
        if ($cancelStmt->affected_rows !== 1) {
            throw new RuntimeException('The request changed before cancellation. Refresh and try again.');
        }

        $conn->commit();

        try {
            log_document_action(
                $conn,
                $userId,
                'CANCEL_DISPOSITION',
                (int) $request['doc_id'],
                "Cancelled disposition request #$requestId.",
                'documents.php?disposition=1'
            );
        } catch (Throwable $sideEffectError) {
            error_log('Disposition cancellation audit warning: ' . $sideEffectError->getMessage());
        }

        dispositionRedirect('success', "Disposition request #$requestId was cancelled.");
    }

    dispositionRedirect('error', 'Unsupported disposition action.');
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    error_log('Disposition workflow error: ' . $e->getMessage());
    dispositionRedirect('error', $e->getMessage());
}
