<?php
require 'config/db_connect.php';
require_once 'config/functions.php';
require_once 'config/official_declarations.php';
require_once 'config/frontend_assets.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$uid = (int) $_SESSION['user_id'];
function dec_escape($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
$error = ''; $doc = null; $request = null; $rows = []; $total = 0; $pages = 1;
$status = (string) ($_GET['status'] ?? 'Pending');
$statuses = ['Pending', 'Approved', 'Returned', 'Cancelled', 'Superseded', 'All'];
if (!in_array($status, $statuses, true)) $status = 'Pending';
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$page = max(1, min(1000000, (int) ($_GET['page'] ?? 1)));
$docId = (int) ($_GET['doc_id'] ?? 0); $requestId = (int) ($_GET['request_id'] ?? 0);
try {
    $actor = drms_declaration_user($conn, $uid);
    if (!drms_signature_tables_ready($conn)) throw new DomainException('Declaration requests are temporarily unavailable.');
    if ($requestId > 0) {
        $s = $conn->prepare("SELECT r.*, d.file_name, d.file_path, d.status AS document_status,
            d.current_version, u.full_name AS requester_name, v.full_name AS reviewer_name,
            e.signature_id, e.verification_code, e.signer_name, e.signature_image_path
            FROM official_declaration_requests r JOIN documents d ON d.doc_id = r.document_id
            LEFT JOIN users u ON u.user_id = r.requested_by LEFT JOIN users v ON v.user_id = r.reviewed_by
            LEFT JOIN document_signature_events e ON e.request_id = r.request_id AND e.signature_status = 'Valid'
            WHERE r.request_id = ? AND (r.requested_by = ? OR r.requested_signer_role = ?) LIMIT 1");
        $s->bind_param('iis', $requestId, $uid, $actor['role']); $s->execute();
        $request = $s->get_result()->fetch_assoc();
        if (!$request) throw new DomainException('This request is unavailable to your account.');
    } elseif ($docId > 0) {
        $s = $conn->prepare('SELECT * FROM documents WHERE doc_id = ?');
        $s->bind_param('i', $docId); $s->execute(); $doc = $s->get_result()->fetch_assoc();
        if (!$doc || !drms_declaration_can_submit($doc, $actor)) {
            $doc = null; throw new DomainException('This file is unavailable for submission by your account.');
        }
        $folder = drms_get_official_folder_profile($conn, (string) ($doc['category'] ?: $doc['doc_type']));
        drms_declaration_working($doc, $folder);
    } else {
        $where = '(r.requested_by = ? OR r.requested_signer_role = ?)';
        $params = [$uid, $actor['role']]; $types = 'is';
        if ($status !== 'All') { $where .= ' AND r.request_status = ?'; $params[] = $status; $types .= 's'; }
        if ($search !== '') {
            $where .= ' AND (LOCATE(?, r.request_reference) > 0 OR LOCATE(?, d.file_name) > 0 OR LOCATE(?, u.full_name) > 0)';
            array_push($params, $search, $search, $search); $types .= 'sss';
        }
        $from = ' FROM official_declaration_requests r JOIN documents d ON d.doc_id = r.document_id LEFT JOIN users u ON u.user_id = r.requested_by WHERE ' . $where;
        $s = $conn->prepare('SELECT COUNT(*)' . $from); $s->bind_param($types, ...$params); $s->execute();
        $total = (int) $s->get_result()->fetch_row()[0]; $pages = max(1, (int) ceil($total / 15));
        $page = min($page, $pages); $offset = ($page - 1) * 15;
        $s = $conn->prepare('SELECT r.*, d.file_name, u.full_name AS requester_name' . $from . ' ORDER BY r.requested_at DESC, r.request_id DESC LIMIT 15 OFFSET ?');
        $params[] = $offset; $types .= 'i'; $s->bind_param($types, ...$params); $s->execute();
        $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $e) {
    error_log('Declaration page: ' . $e->getMessage());
    $error = $e instanceof DomainException ? $e->getMessage() : 'The request workspace is temporarily unavailable.';
    $doc = null; $request = null;
    if (!isset($actor)) { http_response_code(403); exit(dec_escape($error)); }
}
$csrf = (string) ($_SESSION['csrf_token'] ?? '');
$returnUrl = 'general_docs.php' . ($doc ? '?type=' . rawurlencode((string) $doc['category']) : '');
$feedback = $error ?: (string) ($_GET['error'] ?? $_GET['success'] ?? '');
$feedbackTone = ($error !== '' || isset($_GET['error'])) ? 'error' : 'success';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Declaration requests · Fixie DRMS</title>
<link rel="stylesheet" href="assets/css/bootstrap.min.css"><link rel="stylesheet" href="assets/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/workflow-ui.css"><link rel="stylesheet" href="assets/css/e-signature.css?v=<?php echo (string) (@filemtime(__DIR__.'/assets/css/e-signature.css') ?: 1); ?>">
</head><body class="workflow-ui">
<?php include 'sidebar.php'; ?>
<link rel="stylesheet" href="assets/css/official-declarations.css?v=<?php echo filemtime(__DIR__.'/assets/css/official-declarations.css'); ?>">
<main class="main-content"><div class="dec-workspace">
<header class="dec-header"><div><span class="dec-eyebrow">Records management</span>
<h1><?php echo $doc ? 'Request official declaration' : 'Declaration requests'; ?></h1>
<p><?php echo $doc ? 'Send the signed copy to management for verification and filing.' : 'Track submitted documents and management decisions.'; ?></p></div>
<nav class="dec-actions" aria-label="Records navigation"><a class="dec-btn" href="<?php echo dec_escape($returnUrl); ?>">Company Files</a><a class="dec-btn" href="documents.php">Official Records</a>
<?php if ($doc || $request): ?><a class="dec-btn" href="official_declarations.php">All requests</a><?php endif; ?></nav></header>
<?php if ($feedback !== ''): ?><div class="dec-feedback" data-declaration-feedback="<?php echo dec_escape($feedbackTone); ?>" role="status"><?php echo dec_escape($feedback); ?></div><?php endif; ?>
<?php if ($doc): ?>
<section class="dec-panel"><div class="dec-panel-heading"><div><h2><?php echo dec_escape($doc['file_name']); ?></h2><p>Version <?php echo dec_escape($doc['current_version']); ?> · <?php echo dec_escape($doc['category']); ?></p></div><a class="dec-btn" href="download.php?type=document&amp;record_id=<?php echo $docId; ?>" target="_blank" rel="noopener"><i class="fas fa-file-alt" aria-hidden="true"></i> View copy</a></div>
<form action="actions/official_declaration_handler.php" method="post" class="dec-form" data-declaration-form novalidate>
<input type="hidden" name="csrf_token" value="<?php echo dec_escape($csrf); ?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="doc_id" value="<?php echo $docId; ?>">
<div class="dec-grid"><label>Send to <select name="signer_role" required><option value="GM">General Manager</option><option value="President">President</option></select></label>
<label>Name of signatory on the document <input name="external_name" maxlength="150" required placeholder="Name shown with the signature"></label>
<label>Signatory role / organization <input name="external_role" maxlength="100" placeholder="Optional"></label>
<label>Signature date <input name="external_date" type="date" max="<?php echo date('Y-m-d'); ?>"><small>Leave blank if the signed copy has no date.</small></label></div>
<label>Notes for management <textarea name="remarks" maxlength="2000" rows="3" placeholder="Optional · up to 2,000 characters"></textarea></label>
<label class="dec-check"><input type="checkbox" name="signed_copy_confirmed" value="1" required><span>The uploaded copy already contains the required signatures.</span></label>
<div class="dec-error" role="alert" tabindex="-1" hidden></div><div class="dec-actions"><a class="dec-btn" href="<?php echo dec_escape($returnUrl); ?>">Cancel</a><button class="dec-btn dec-primary" type="submit"><i class="fas fa-paper-plane" aria-hidden="true"></i> Submit request</button></div></form></section>
<?php elseif ($request): ?>
<section class="dec-panel"><div class="dec-panel-heading"><div><h2><?php echo dec_escape($request['file_name']); ?></h2><p><?php echo dec_escape($request['request_reference']); ?></p></div><span class="dec-badge"><?php echo dec_escape($request['request_status']); ?></span></div>
<dl class="dec-details"><div><dt>Submitted by</dt><dd><?php echo dec_escape($request['requester_name']); ?></dd></div><div><dt>Assigned reviewer</dt><dd><?php echo dec_escape($request['requested_signer_role']); ?></dd></div><div><dt>Submitted</dt><dd><?php echo dec_escape($request['requested_at']); ?></dd></div><div><dt>Submitted version</dt><dd><?php echo dec_escape($request['source_version']); ?></dd></div><div><dt>Signatory on copy</dt><dd><?php echo dec_escape($request['external_signatory_name']); ?></dd></div><div><dt>Signatory role / organization</dt><dd><?php echo dec_escape($request['external_signatory_role'] ?: 'Not provided'); ?></dd></div><div><dt>Signature date</dt><dd><?php echo dec_escape($request['external_signature_date'] ?: 'Not provided'); ?></dd></div></dl>
<?php if ($request['request_remarks']): ?><p class="dec-note"><?php echo nl2br(dec_escape($request['request_remarks'])); ?></p><?php endif; ?>
<div class="dec-actions"><a class="dec-btn" href="download.php?type=document&amp;record_id=<?php echo (int) $request['document_id']; ?>" target="_blank" rel="noopener"><i class="fas fa-file-alt" aria-hidden="true"></i> View current copy</a>
<?php if ($request['official_document_id']): ?><a class="dec-btn dec-primary" href="download.php?type=document&amp;record_id=<?php echo (int) $request['official_document_id']; ?>" target="_blank" rel="noopener">View Official Record</a><?php endif; ?></div>
<?php if ($request['request_status'] === 'Pending' && $request['requested_signer_role'] === $actor['role']): ?>
<form id="declarationDecisionForm" action="actions/document_handler.php" method="post" class="dec-form dec-divider" data-declaration-form novalidate>
<input type="hidden" name="csrf_token" value="<?php echo dec_escape($csrf); ?>"><input type="hidden" name="doc_id" value="<?php echo (int) $request['document_id']; ?>"><input type="hidden" name="request_id" value="<?php echo $requestId; ?>"><input type="hidden" name="action" id="declarationDecisionAction" value="">
<h3>Verify the signed copy</h3><p>Review the document before filing. If the file changed after submission, return it so the requester can submit the latest signed copy.</p>
<label>Decision remarks <textarea name="remarks" maxlength="2000" rows="3" placeholder="Required when returning the request"></textarea></label>
<p class="dec-esign-note"><i class="fas fa-signature" aria-hidden="true"></i>Your active management account and electronic-signature consent will be recorded when you verify and file this Official Record.</p>
<div class="dec-error" role="alert" tabindex="-1" hidden></div><div class="dec-actions"><button class="dec-btn" type="button" data-declaration-return>Return to requester</button><button class="dec-btn dec-primary" type="button" data-declaration-sign>Verify signatures &amp; file</button></div></form>
<?php endif; ?>
<?php if ($request['request_status'] === 'Pending' && (int) $request['requested_by'] === $uid): ?>
<form action="actions/official_declaration_handler.php" method="post" class="dec-form dec-divider" data-declaration-form novalidate><input type="hidden" name="csrf_token" value="<?php echo dec_escape($csrf); ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="doc_id" value="<?php echo (int) $request['document_id']; ?>"><input type="hidden" name="request_id" value="<?php echo $requestId; ?>"><div class="dec-error" role="alert" tabindex="-1" hidden></div><button class="dec-btn" type="submit">Cancel my request</button></form>
<?php endif; ?>
<?php if ($request['request_status'] !== 'Pending'): ?><div class="dec-divider"><p><?php echo dec_escape($request['request_status']); ?> by <?php echo dec_escape($request['reviewer_name'] ?: 'Recorded user'); ?> · <?php echo dec_escape($request['reviewed_at']); ?></p><p class="dec-note"><?php echo nl2br(dec_escape($request['review_remarks'])); ?></p><?php if ($request['verification_code']): ?><div class="dec-signature-evidence"><span>Electronic signature</span><div class="dec-signature-image"><?php if (!empty($request['signature_image_path'])): ?><img src="signature_print_image.php?id=<?php echo (int) $request['signature_id']; ?>" alt="Electronic signature of <?php echo dec_escape($request['signer_name']); ?>"><?php else: ?><strong>/s/ <?php echo dec_escape($request['signer_name'] ?: $request['reviewer_name']); ?></strong><?php endif; ?></div><small><i class="fas fa-shield-check" aria-hidden="true"></i> Verified · <?php echo dec_escape($request['verification_code']); ?></small></div><?php endif; ?><?php if (in_array($request['request_status'], ['Returned','Cancelled','Superseded'], true) && (int) $request['requested_by'] === $uid): ?><a class="dec-btn" href="official_declarations.php?doc_id=<?php echo (int) $request['document_id']; ?>">Submit latest signed copy</a><?php endif; ?></div><?php endif; ?></section>
<?php elseif ($error === ''): ?>
<section class="dec-panel dec-list"><form method="get" class="dec-search" role="search"><label class="dec-search-input"><i class="fas fa-search" aria-hidden="true"></i><input type="search" name="q" value="<?php echo dec_escape($search); ?>" placeholder="Search request, file or requester" aria-label="Search requests" maxlength="100"></label><select name="status" aria-label="Request status"><?php foreach ($statuses as $option): ?><option <?php echo $status === $option ? 'selected' : ''; ?>><?php echo dec_escape($option); ?></option><?php endforeach; ?></select><button class="dec-btn" type="submit">Apply</button></form>
<div class="dec-table-scroll"><table><thead><tr><th>Document / request</th><th>Requester</th><th>Reviewer</th><th>Status</th><th>Submitted</th><th><span class="visually-hidden">Open</span></th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td title="<?php echo dec_escape($row['file_name']); ?>"><strong><?php echo dec_escape($row['file_name']); ?></strong><small><?php echo dec_escape($row['request_reference']); ?></small></td><td><?php echo dec_escape($row['requester_name']); ?></td><td><?php echo dec_escape($row['requested_signer_role']); ?></td><td><span class="dec-badge"><?php echo dec_escape($row['request_status']); ?></span></td><td><?php echo dec_escape($row['requested_at']); ?></td><td><a class="dec-btn" href="official_declarations.php?request_id=<?php echo (int) $row['request_id']; ?>" aria-label="Open <?php echo dec_escape($row['request_reference']); ?>"><i class="fas fa-arrow-right" aria-hidden="true"></i></a></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="dec-empty">No requests match this view. Submit a signed document from Company Files.</td></tr><?php endif; ?></tbody></table></div>
<footer class="dec-pagination"><span><?php echo $total; ?> request(s) · Page <?php echo $page; ?> of <?php echo $pages; ?></span><nav class="dec-actions" aria-label="Request pages"><?php foreach (['Previous' => $page - 1, 'Next' => $page + 1] as $label => $number): ?><?php if ($number >= 1 && $number <= $pages): ?><a class="dec-btn" href="?<?php echo dec_escape(http_build_query(['q'=>$search,'status'=>$status,'page'=>$number])); ?>"><?php echo $label; ?></a><?php else: ?><span class="dec-btn" aria-disabled="true"><?php echo $label; ?></span><?php endif; ?><?php endforeach; ?></nav></footer></section>
<?php endif; ?>
</div></main>
<?php echo drms_frontend_script_tags(['bootstrap']); ?>
<script src="assets/js/e-signature.js?v=<?php echo (string) (@filemtime(__DIR__.'/assets/js/e-signature.js') ?: 1); ?>"></script>
<script src="assets/js/official-declarations.js?v=<?php echo filemtime(__DIR__.'/assets/js/official-declarations.js'); ?>"></script>
</body></html>
