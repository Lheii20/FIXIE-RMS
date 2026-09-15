<?php
require '../config/db_connect.php';
require_once '../config/functions.php';
require_once '../config/official_declarations.php';
require_once '../config/workflow_feedback.php';

if (empty($_SESSION['user_id'])) { header('Location: ../index.php'); exit; }
$target = '../general_docs.php';
$started = false;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($_POST['csrf_token'] ?? null) ||
        !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $_POST['csrf_token']) || empty($_SESSION['csrf_token'])) {
        throw new DomainException('Your session verification expired. Reload the page and try again.');
    }
    if (!drms_signature_tables_ready($conn)) throw new DomainException('Declaration requests are temporarily unavailable.');
    $uid = (int) $_SESSION['user_id'];
    $user = drms_declaration_user($conn, $uid);
    $action = (string) ($_POST['action'] ?? '');
    $docId = (int) ($_POST['doc_id'] ?? 0);
    $requestId = (int) ($_POST['request_id'] ?? 0);
    if ($requestId > 0 && $user['role'] === 'GM') $target = '../official_declarations.php?request_id=' . $requestId;
    $conn->begin_transaction(); $started = true;
    // Consistent lock order: document first, then request, for every decision.
    $s = $conn->prepare('SELECT * FROM documents WHERE doc_id = ? FOR UPDATE');
    $s->bind_param('i', $docId); $s->execute(); $doc = $s->get_result()->fetch_assoc();
    if (!$doc) throw new DomainException('The document is unavailable.');
    if ($action === 'submit') {
        $requestId = drms_declaration_submit($conn, $doc, $user, $_POST);
        $target = '../general_docs.php?type=' . rawurlencode((string) ($doc['category'] ?: $doc['doc_type']));
        $message = 'Request submitted. The General Manager has been notified.';
    } elseif (in_array($action, ['return', 'cancel'], true)) {
        $request = drms_declaration_locked_request($conn, $requestId, $docId);
        $remarks = drms_declaration_text($_POST['remarks'] ?? '', 2000, $action === 'return');
        drms_declaration_close($conn, $request, $user, $action === 'return' ? 'Returned' : 'Cancelled', $remarks);
        $message = $action === 'return' ? 'Request returned to the requester.' : 'Request cancelled. The Company File remains available.';
    } else throw new DomainException('Choose a valid request action.');
    $conn->commit(); $started = false;
    drms_redirect_with_feedback($target, 'success', $message);
} catch (Throwable $error) {
    if ($started) $conn->rollback();
    error_log('Official declaration request: ' . $error->getMessage());
    drms_redirect_with_feedback($target, 'error', $error instanceof DomainException ? $error->getMessage() : 'The request could not be saved. Please try again.');
}
