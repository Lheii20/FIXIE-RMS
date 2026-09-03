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

function resolveDispositionFilePath(string $storedPath): array
{
    $projectRoot = realpath(__DIR__ . '/..');
    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($storedPath, '/\\'));

    if ($projectRoot === false || $uploadsRoot === false || $relativePath === '' || strpos($relativePath, '..') !== false) {
        throw new RuntimeException('The stored file path is invalid.');
    }

    $absolutePath = realpath($projectRoot . DIRECTORY_SEPARATOR . $relativePath);
    $uploadsPrefix = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($absolutePath === false || !is_file($absolutePath) || stripos($absolutePath, $uploadsPrefix) !== 0) {
        throw new RuntimeException('The protected record file is missing or outside the approved uploads directory.');
    }

    return [
        'absolute' => $absolutePath,
        'project_root' => $projectRoot,
        'uploads_root' => $uploadsRoot
    ];
}

function releaseDispositionLock(mysqli $conn, ?string $lockName, bool &$lockAcquired): void
{
    if (!$lockAcquired || $lockName === null) {
        return;
    }

    $stmt = $conn->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->bind_param('s', $lockName);
    $stmt->execute();
    $stmt->close();
    $lockAcquired = false;
}

if (!isset($_SESSION['user_id']) || empty($_SESSION['role'])) {
    dispositionRedirect('error', 'Unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    dispositionRedirect('error', 'Invalid request method.');
}

$sessionToken = $_SESSION['csrf_token'] ?? '';
$requestToken = $_POST['csrf_token'] ?? '';
if (!is_string($sessionToken) || !is_string($requestToken) || $sessionToken === '' || !hash_equals($sessionToken, $requestToken)) {
    dispositionRedirect('error', 'Invalid security token. Refresh the page and try again.');
}

$userId = (int) $_SESSION['user_id'];
$action = is_string($_POST['action'] ?? null) ? trim($_POST['action']) : '';
$executionOriginalPath = null;
$executionQuarantinePath = null;
$executionFileMoved = false;
$executionFileDeleted = false;
$executionCommitted = false;
$certificateLockName = null;
$certificateLockAcquired = false;

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

    if ($action === 'execute_disposition') {
        if (!has_permission($conn, $userId, 'can_execute_disposition')) {
            dispositionRedirect('error', 'You do not have permission to execute approved disposition requests.');
        }

        $requestId = (int) ($_POST['request_id'] ?? 0);
        $confirmation = strtoupper(trim($_POST['execution_confirmation'] ?? ''));
        $executionNotes = trim($_POST['execution_notes'] ?? '');

        if ($requestId < 1) {
            dispositionRedirect('error', 'Select a valid disposition request.');
        }
        if (dispositionTextLength($executionNotes) > 1000) {
            dispositionRedirect('error', 'Execution notes cannot exceed 1000 characters.');
        }

        $conn->begin_transaction();
        $requestStmt = $conn->prepare(
            "SELECT r.*, d.file_name, d.file_path, d.file_hash, d.record_number,
                    d.record_phase, d.status AS document_status,
                    d.disposition_status, d.is_legal_hold,
                    requester.full_name AS requester_name,
                    reviewer.full_name AS reviewer_name
               FROM disposition_requests r
               JOIN documents d ON d.doc_id = r.doc_id
               JOIN users requester ON requester.user_id = r.requested_by
               JOIN users reviewer ON reviewer.user_id = r.reviewed_by
              WHERE r.request_id = ?
              LIMIT 1
              FOR UPDATE"
        );
        $requestStmt->bind_param('i', $requestId);
        $requestStmt->execute();
        $request = $requestStmt->get_result()->fetch_assoc();

        if (!$request) {
            throw new RuntimeException('Approved disposition request not found.');
        }
        if ($request['status'] !== 'Approved') {
            throw new RuntimeException('Only an Approved disposition request can be executed.');
        }
        if ((int) $request['requested_by'] === (int) $request['reviewed_by']) {
            throw new RuntimeException('The request does not have an independent reviewer.');
        }
        if ($request['record_phase'] !== 'Official' || $request['disposition_status'] !== 'Ready for Disposition') {
            throw new RuntimeException('The linked Official Record is no longer ready for disposition.');
        }
        if ((int) $request['is_legal_hold'] === 1) {
            throw new RuntimeException('The linked record is under Legal Hold and cannot be executed.');
        }

        $requestedAction = $request['requested_action'];
        $requiredConfirmation = $requestedAction === 'Destroy' ? 'DESTROY' : 'ARCHIVE';
        if (!hash_equals($requiredConfirmation, $confirmation)) {
            throw new RuntimeException("Type $requiredConfirmation exactly to confirm this execution.");
        }

        $executedAt = date('Y-m-d H:i:s');
        $docId = (int) $request['doc_id'];

        if ($requestedAction === 'Permanent Archive') {
            $executionMethod = 'Permanent digital archive; original verified file retained';
            $resultPayload = [
                'request_id' => $requestId,
                'doc_id' => $docId,
                'action' => $requestedAction,
                'file_sha256' => $request['file_hash'],
                'executed_by' => $userId,
                'executed_at' => $executedAt
            ];
            $resultHash = hash('sha256', json_encode($resultPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $docUpdate = $conn->prepare(
                "UPDATE documents
                    SET status = 'Archived',
                        disposition_status = 'Permanently Archived',
                        dss_recommendation = 'Retention completed and permanent archive execution verified.'
                  WHERE doc_id = ?
                    AND disposition_status = 'Ready for Disposition'
                    AND is_legal_hold = 0"
            );
            $docUpdate->bind_param('i', $docId);
            $docUpdate->execute();
            if ($docUpdate->affected_rows !== 1) {
                throw new RuntimeException('The Official Record changed before archive execution. Refresh and try again.');
            }

            $requestUpdate = $conn->prepare(
                "UPDATE disposition_requests
                    SET status = 'Executed', executed_by = ?, executed_at = ?,
                        execution_method = ?, execution_notes = ?,
                        execution_result_hash = ?, certificate_id = NULL
                  WHERE request_id = ? AND status = 'Approved'"
            );
            $requestUpdate->bind_param('issssi', $userId, $executedAt, $executionMethod, $executionNotes, $resultHash, $requestId);
            $requestUpdate->execute();
            if ($requestUpdate->affected_rows !== 1) {
                throw new RuntimeException('The approved request changed before execution. Refresh and try again.');
            }

            $conn->commit();
            $executionCommitted = true;

            try {
                log_document_action(
                    $conn,
                    $userId,
                    'EXECUTE_PERMANENT_ARCHIVE',
                    $docId,
                    "Executed permanent archive request #$requestId. Result hash: $resultHash",
                    'documents.php?disposition=1'
                );
                notifyDispositionRequester($conn, $request, 'executed for permanent archive');
            } catch (Throwable $sideEffectError) {
                error_log('Permanent archive audit/notification warning: ' . $sideEffectError->getMessage());
            }

            dispositionRedirect('success', "Disposition request #$requestId was completed as a Permanent Archive.");
        }

        if ($requestedAction !== 'Destroy') {
            throw new RuntimeException('The approved request contains an unsupported disposition action.');
        }

        // VC4B1: the execution and certificate concern the digital binary only.
        if (($_POST['digital_scope_confirmed'] ?? '') !== '1') {
            throw new RuntimeException('Confirm that this destroys only the digital file, not the physical paper copy.');
        }

        $resolvedFile = resolveDispositionFilePath((string) $request['file_path']);
        $executionOriginalPath = $resolvedFile['absolute'];

        // The target binary must belong only to this Official Record. New
        // official declarations receive an independent copy. Shared legacy
        // binaries are blocked instead of risking deletion of another record.
        $sharedStmt = $conn->prepare(
            "SELECT doc_id
               FROM documents
              WHERE file_path = ?
                AND doc_id <> ?
                AND disposition_status <> 'Destroyed'
              LIMIT 1
              FOR UPDATE"
        );
        $sharedStmt->bind_param('si', $request['file_path'], $docId);
        $sharedStmt->execute();
        if ($sharedStmt->get_result()->num_rows > 0) {
            throw new RuntimeException('Destruction is blocked because another record still references the same stored file. Create an independent Official Record copy first.');
        }

        $actualHash = hash_file('sha256', $executionOriginalPath);
        if ($actualHash === false || !hash_equals(strtolower((string) $request['file_hash']), strtolower($actualHash))) {
            throw new RuntimeException('File integrity verification failed. The stored file does not match the Official Record hash.');
        }
        $fileSize = filesize($executionOriginalPath);
        if ($fileSize === false) {
            throw new RuntimeException('Unable to verify the stored file size.');
        }

        $quarantineDirectory = $resolvedFile['uploads_root'] . DIRECTORY_SEPARATOR . '.disposition_quarantine';
        if (!is_dir($quarantineDirectory) && !mkdir($quarantineDirectory, 0700, true)) {
            throw new RuntimeException('Unable to create protected disposition storage.');
        }

        $executionQuarantinePath = $quarantineDirectory . DIRECTORY_SEPARATOR
            . 'request_' . $requestId . '_' . bin2hex(random_bytes(8)) . '.pending';
        if (!rename($executionOriginalPath, $executionQuarantinePath)) {
            throw new RuntimeException('Unable to move the file into protected disposition storage. No record was changed.');
        }
        $executionFileMoved = true;

        $year = date('Y');
        $certificateLockName = "destruction_certificate_number_$year";
        $lockStmt = $conn->prepare('SELECT GET_LOCK(?, 5) AS lock_acquired');
        $lockStmt->bind_param('s', $certificateLockName);
        $lockStmt->execute();
        $lockRow = $lockStmt->get_result()->fetch_assoc();
        $certificateLockAcquired = (int) ($lockRow['lock_acquired'] ?? 0) === 1;
        $lockStmt->close();
        if (!$certificateLockAcquired) {
            throw new RuntimeException('The certificate number service is busy. Please try again.');
        }

        $certificatePrefix = "DC-$year-";
        $sequenceStart = strlen($certificatePrefix) + 1;
        $sequenceStmt = $conn->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(certificate_number, ?) AS UNSIGNED)), 0) + 1 AS next_number
               FROM destruction_certificates
              WHERE certificate_number LIKE CONCAT(?, '%')"
        );
        $sequenceStmt->bind_param('is', $sequenceStart, $certificatePrefix);
        $sequenceStmt->execute();
        $sequenceRow = $sequenceStmt->get_result()->fetch_assoc();
        $certificateNumber = sprintf('DC-%s-%06d', $year, (int) $sequenceRow['next_number']);
        $sequenceStmt->close();

        $deletionMethod = 'Digital file only: SHA-256 verified application quarantine and unlink; physical copy not disposed';
        $certificatePayload = [
            'certificate_number' => $certificateNumber,
            'request_id' => $requestId,
            'doc_id' => $docId,
            'file_sha256' => $actualHash,
            'file_size' => (int) $fileSize,
            'requested_by' => (int) $request['requested_by'],
            'reviewed_by' => (int) $request['reviewed_by'],
            'destroyed_by' => $userId,
            'destroyed_at' => $executedAt,
            'method' => $deletionMethod
        ];
        $certificateHash = hash('sha256', json_encode($certificatePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $certificateStmt = $conn->prepare(
            "INSERT INTO destruction_certificates
                (certificate_number, request_id, doc_id, file_name,
                 original_file_path, file_sha256, file_size, reason,
                 retention_policy_id, retention_authority, requested_by,
                 reviewed_by, destroyed_by, destroyed_at, deletion_method,
                 certificate_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $certificateStmt->bind_param(
            'siisssisisiiisss',
            $certificateNumber,
            $requestId,
            $docId,
            $request['file_name'],
            $request['file_path'],
            $actualHash,
            $fileSize,
            $request['reason'],
            $request['retention_policy_id'],
            $request['retention_authority'],
            $request['requested_by'],
            $request['reviewed_by'],
            $userId,
            $executedAt,
            $deletionMethod,
            $certificateHash
        );
        $certificateStmt->execute();
        $certificateId = (int) $certificateStmt->insert_id;

        $destroyedMarker = '[SECURELY DESTROYED - ' . $certificateNumber . ']';
        $docUpdate = $conn->prepare(
            "UPDATE documents
                SET file_path = ?, status = 'Archived',
                    disposition_status = 'Destroyed',
                    dss_recommendation = 'Retention completed and secure destruction certified.'
              WHERE doc_id = ?
                AND disposition_status = 'Ready for Disposition'
                AND is_legal_hold = 0"
        );
        $docUpdate->bind_param('si', $destroyedMarker, $docId);
        $docUpdate->execute();
        if ($docUpdate->affected_rows !== 1) {
            throw new RuntimeException('The Official Record changed before destruction execution. No file was deleted.');
        }

        $requestUpdate = $conn->prepare(
            "UPDATE disposition_requests
                SET status = 'Executed', executed_by = ?, executed_at = ?,
                    execution_method = ?, execution_notes = ?,
                    execution_result_hash = ?, certificate_id = ?
              WHERE request_id = ? AND status = 'Approved'"
        );
        $requestUpdate->bind_param('issssii', $userId, $executedAt, $deletionMethod, $executionNotes, $certificateHash, $certificateId, $requestId);
        $requestUpdate->execute();
        if ($requestUpdate->affected_rows !== 1) {
            throw new RuntimeException('The approved request changed before destruction execution. No file was deleted.');
        }

        $conn->commit();
        $executionCommitted = true;
        releaseDispositionLock($conn, $certificateLockName, $certificateLockAcquired);

        if (!unlink($executionQuarantinePath)) {
            // Compensate the committed database state while the quarantined file
            // is still intact, then restore it to its original location.
            $conn->begin_transaction();
            $revertRequest = $conn->prepare(
                "UPDATE disposition_requests
                    SET status = 'Approved', executed_by = NULL, executed_at = NULL,
                        execution_method = NULL, execution_notes = NULL,
                        execution_result_hash = NULL, certificate_id = NULL
                  WHERE request_id = ? AND status = 'Executed'"
            );
            $revertRequest->bind_param('i', $requestId);
            $revertRequest->execute();

            $revertDocument = $conn->prepare(
                "UPDATE documents
                    SET file_path = ?, status = 'Archived',
                        disposition_status = 'Ready for Disposition',
                        dss_recommendation = 'Approved disposition request is waiting for execution.'
                  WHERE doc_id = ? AND disposition_status = 'Destroyed'"
            );
            $revertDocument->bind_param('si', $request['file_path'], $docId);
            $revertDocument->execute();

            $deleteCertificate = $conn->prepare('DELETE FROM destruction_certificates WHERE certificate_id = ?');
            $deleteCertificate->bind_param('i', $certificateId);
            $deleteCertificate->execute();

            if (!rename($executionQuarantinePath, $executionOriginalPath)) {
                $conn->rollback();
                error_log("CRITICAL: Unable to restore quarantined file for disposition request #$requestId.");
                throw new RuntimeException('Secure deletion could not be completed. Administrator recovery is required.');
            }

            $conn->commit();
            $executionFileMoved = false;
            $executionCommitted = false;
            throw new RuntimeException('Secure deletion could not be completed. The file and Approved request were restored.');
        }

        $executionFileDeleted = true;
        $executionFileMoved = false;

        try {
            log_document_action(
                $conn,
                $userId,
                'EXECUTE_SECURE_DESTRUCTION',
                $docId,
                "Executed destruction request #$requestId; certificate $certificateNumber; certificate hash $certificateHash.",
                'documents.php?disposition=1'
            );
            notifyDispositionRequester($conn, $request, "executed with certificate $certificateNumber");
        } catch (Throwable $sideEffectError) {
            error_log('Destruction audit/notification warning: ' . $sideEffectError->getMessage());
        }

        dispositionRedirect('success', "Digital file destruction completed. Certificate $certificateNumber was generated. Any registered physical copy remains tracked in Virtual Cabinet; it was not disposed.");
    }

    dispositionRedirect('error', 'Unsupported disposition action.');
} catch (Throwable $e) {
    if ($certificateLockAcquired) {
        try {
            releaseDispositionLock($conn, $certificateLockName, $certificateLockAcquired);
        } catch (Throwable $lockError) {
            error_log('Disposition lock release warning: ' . $lockError->getMessage());
        }
    }

    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    if ($executionFileMoved && !$executionFileDeleted && !$executionCommitted
        && $executionQuarantinePath !== null && is_file($executionQuarantinePath)
        && $executionOriginalPath !== null && !file_exists($executionOriginalPath)) {
        if (!@rename($executionQuarantinePath, $executionOriginalPath)) {
            error_log('CRITICAL: Unable to restore a disposition quarantine file after rollback.');
        }
    }

    error_log('Disposition workflow error: ' . $e->getMessage());
    dispositionRedirect('error', $e->getMessage());
}
