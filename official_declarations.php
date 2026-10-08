<?php
require 'config/db_connect.php';
require_once 'config/functions.php';
require_once 'config/official_declarations.php';
require_once 'config/frontend_assets.php';
if (empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$uid = (int) $_SESSION['user_id'];
function dec_escape($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function dec_preview_type(string $fileName): string {
    $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
    if ($extension === 'pdf') return 'pdf';
    return in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) ? 'image' : 'other';
}
$error = ''; $doc = null; $request = null; $rows = []; $total = 0; $pages = 1;
$status = (string) ($_GET['status'] ?? 'Pending');
$statuses = ['Pending', 'Approved', 'Returned', 'Cancelled', 'Superseded', 'All'];
if (!in_array($status, $statuses, true)) $status = 'Pending';
$search = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$page = max(1, min(1000000, (int) ($_GET['page'] ?? 1)));
$docId = (int) ($_GET['doc_id'] ?? 0); $requestId = (int) ($_GET['request_id'] ?? 0);
try {
    $actor = drms_declaration_user($conn, $uid);
    $isGmReviewer = (string) $actor['role'] === 'GM';
    if (!drms_signature_tables_ready($conn)) throw new DomainException('Declaration requests are temporarily unavailable.');
    if ($requestId > 0) {
        if (!$isGmReviewer) {
            throw new DomainException('Only the General Manager can open Official Record declaration requests.');
        }
        $s = $conn->prepare("SELECT r.*, d.file_name, d.file_path, d.status AS document_status,
            d.current_version, u.full_name AS requester_name, v.full_name AS reviewer_name,
            e.signature_id, e.verification_code, e.signer_name, e.signature_image_path
            FROM official_declaration_requests r JOIN documents d ON d.doc_id = r.document_id
            LEFT JOIN users u ON u.user_id = r.requested_by LEFT JOIN users v ON v.user_id = r.reviewed_by
            LEFT JOIN document_signature_events e ON e.request_id = r.request_id AND e.signature_status = 'Valid'
            WHERE r.request_id = ? LIMIT 1");
        $s->bind_param('i', $requestId); $s->execute();
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
        if (!$isGmReviewer) {
            throw new DomainException('Official Record declaration requests are available only to the General Manager.');
        }
        $where = 'r.request_id > 0';
        $params = []; $types = '';
        if ($status !== 'All') { $where .= ' AND r.request_status = ?'; $params[] = $status; $types .= 's'; }
        if ($search !== '') {
            $where .= ' AND (LOCATE(?, r.request_reference) > 0 OR LOCATE(?, d.file_name) > 0 OR LOCATE(?, u.full_name) > 0)';
            array_push($params, $search, $search, $search); $types .= 'sss';
        }
        $from = ' FROM official_declaration_requests r JOIN documents d ON d.doc_id = r.document_id LEFT JOIN users u ON u.user_id = r.requested_by WHERE ' . $where;
        $s = $conn->prepare('SELECT COUNT(*)' . $from); if ($types !== '') $s->bind_param($types, ...$params); $s->execute();
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
$declarationSearchClearUrl = 'official_declarations.php?' . http_build_query(['status' => $status]);
$declarationFilterResetUrl = 'official_declarations.php?' . http_build_query(['q' => $search, 'status' => 'Pending']);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Declaration requests · Fixie DRMS</title>
<link rel="stylesheet" href="assets/css/bootstrap.min.css"><link rel="stylesheet" href="assets/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css"><link rel="stylesheet" href="assets/css/workflow-ui.css"><link rel="stylesheet" href="assets/css/e-signature.css?v=<?php echo (string) (@filemtime(__DIR__.'/assets/css/e-signature.css') ?: 1); ?>">
</head><body class="workflow-ui">
<?php include 'sidebar.php'; ?>
<link rel="stylesheet" href="assets/css/official-declarations.css?v=<?php echo filemtime(__DIR__.'/assets/css/official-declarations.css'); ?>">
<main class="main-content"><div class="dec-workspace<?php echo $doc ? ' dec-workspace-editor' : ''; ?>">
<header class="dec-header"><div class="d-flex align-items-center gap-3"><button class="dec-btn bg-transparent flex-shrink-0" type="button" aria-label="Back" title="Back" onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href = 'documents.php'; }"><i class="fas fa-arrow-left" aria-hidden="true"></i></button><div style="min-width:0"><span class="dec-eyebrow">Records management</span>
<h1><?php echo $doc ? ($isGmReviewer ? 'Declare as Official Record' : 'Request official declaration') : 'Declaration Requests'; ?></h1>
<p><?php echo $doc ? ($isGmReviewer ? 'Review the exact file before locking and filing it as an Official Record.' : 'Send the file to the General Manager for review and filing.') : 'Review files awaiting a GM decision.'; ?></p></div></div>
<?php if (!$doc && !$request && $error === ''): ?>
<form method="get" action="official_declarations.php" class="dec-search" role="search">
    <div class="dec-search-control">
        <label class="dec-search-input" for="declarationSearchInput">
            <span class="dec-search-icon" aria-hidden="true"><i class="fas fa-search"></i></span>
            <input id="declarationSearchInput" type="search" name="q" value="<?php echo dec_escape($search); ?>" placeholder="Search file, request or requester" aria-label="Search declaration requests" maxlength="100" autocomplete="off">
            <?php if ($search !== ''): ?><a class="dec-search-clear" href="<?php echo dec_escape($declarationSearchClearUrl); ?>" aria-label="Clear search" title="Clear search"><i class="fas fa-times" aria-hidden="true"></i></a><?php endif; ?>
        </label>
        <button class="dec-search-submit" type="submit" aria-label="Search requests" title="Search requests"><i class="fas fa-arrow-right" aria-hidden="true"></i></button>
        <div class="dropdown dec-filter-dropdown">
            <button class="dec-filter-toggle<?php echo $status !== 'Pending' ? ' is-active' : ''; ?>" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Filter declaration requests">
                <i class="fas fa-sliders-h" aria-hidden="true"></i><span class="dec-filter-text">Filters</span><?php if ($status !== 'Pending'): ?><span class="dec-filter-count" aria-label="1 active filter">1</span><?php endif; ?>
            </button>
            <div class="dropdown-menu dropdown-menu-end dec-filter-menu">
                <div class="dec-filter-heading"><strong>Refine requests</strong><small>Choose a request status.</small></div>
                <label class="dec-filter-field" for="declarationStatusFilter"><span>Request status</span><select id="declarationStatusFilter" name="status"><?php foreach ($statuses as $option): ?><option value="<?php echo dec_escape($option); ?>" <?php echo $status === $option ? 'selected' : ''; ?>><?php echo dec_escape($option); ?></option><?php endforeach; ?></select></label>
                <div class="dec-filter-actions"><a href="<?php echo dec_escape($declarationFilterResetUrl); ?>">Reset filters</a><button type="submit"><i class="fas fa-check" aria-hidden="true"></i> Apply</button></div>
            </div>
        </div>
    </div>
</form>
<?php endif; ?>
</header>
<?php if ($feedback !== ''): ?><div class="dec-feedback" data-declaration-feedback="<?php echo dec_escape($feedbackTone); ?>" role="status"><?php echo dec_escape($feedback); ?></div><?php endif; ?>
<?php if ($doc): ?>
<section class="dec-panel dec-editor" aria-label="<?php echo $isGmReviewer ? 'Official Record review' : 'Declaration request'; ?>">
    <div class="dec-editor-grid">
        <div class="dec-file-column">
            <div class="dec-section-heading"><span class="dec-step">1</span><div><h2>Review the file</h2><p>Open the file and check its contents before continuing.</p></div></div>
            <div class="dec-file-card">
                <div class="dec-file-icon"><i class="fas fa-file-alt" aria-hidden="true"></i></div>
                <div class="dec-file-info"><h3><?php echo dec_escape($doc['file_name']); ?></h3><p><?php echo dec_escape($doc['category']); ?> <span aria-hidden="true">·</span> Version <?php echo dec_escape($doc['current_version']); ?></p></div>
                <button class="dec-btn dec-view-copy" type="button" data-declaration-preview data-file-url="download.php?type=document&amp;record_id=<?php echo $docId; ?>" data-file-name="<?php echo dec_escape($doc['file_name']); ?>" data-preview-type="<?php echo dec_preview_type((string) $doc['file_name']); ?>"><i class="fas fa-eye" aria-hidden="true"></i> Preview this file</button>
            </div>
            <div class="dec-guidance" role="note">
                <span class="dec-guidance-icon"><i class="fas fa-info-circle" aria-hidden="true"></i></span>
                <p><?php echo $isGmReviewer
                    ? 'Declaring this file will lock it as an Official Record. Your decision and electronic signature will be recorded.'
                    : 'The General Manager will make the final decision after reviewing this file.'; ?></p>
            </div>
        </div>
        <form action="<?php echo $isGmReviewer ? 'actions/document_handler.php' : 'actions/official_declaration_handler.php'; ?>" method="post" class="dec-form dec-editor-form" data-declaration-form <?php echo $isGmReviewer ? 'data-gm-direct-declaration' : ''; ?> novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo dec_escape($csrf); ?>">
            <input type="hidden" name="action" value="<?php echo $isGmReviewer ? 'declare_official' : 'submit'; ?>">
            <input type="hidden" name="doc_id" value="<?php echo $docId; ?>">
            <input type="hidden" name="signer_role" value="GM">
            <div class="dec-section-heading"><span class="dec-step">2</span><div><h2><?php echo $isGmReviewer ? 'Confirm your decision' : 'Send for GM review'; ?></h2><p><?php echo $isGmReviewer ? 'Your confirmation applies to this file version.' : 'The GM will review this file version.'; ?></p></div></div>
            <label class="dec-field"><?php echo $isGmReviewer ? 'Review remarks (optional)' : 'Notes for the General Manager (optional)'; ?><textarea name="remarks" maxlength="2000" rows="2" placeholder="<?php echo $isGmReviewer ? 'Add a note if needed' : 'Add context if needed'; ?>"></textarea></label>
            <?php if ($isGmReviewer): ?>
                <label class="dec-check"><input type="checkbox" name="gm_review_confirmed" value="1" required><span>I reviewed this file and confirm it is complete and ready to be filed as an Official Record.</span></label>
                <p class="dec-esign-note"><i class="fas fa-signature" aria-hidden="true"></i> Your electronic signature will confirm this decision.</p>
            <?php else: ?>
                <label class="dec-check"><input type="checkbox" name="file_review_confirmed" value="1" required><span>I reviewed this exact file and confirm that it is ready for the General Manager's review.</span></label>
            <?php endif; ?>
            <div class="dec-error" role="alert" tabindex="-1" hidden></div>
            <div class="dec-form-footer"><a class="dec-btn dec-cancel" href="<?php echo dec_escape($returnUrl); ?>">Cancel</a>
                <?php if ($isGmReviewer): ?><button class="dec-btn dec-primary" type="button" data-declaration-sign><i class="fas fa-file-signature" aria-hidden="true"></i> Declare as Official Record</button><?php else: ?><button class="dec-btn dec-primary" type="submit"><i class="fas fa-paper-plane" aria-hidden="true"></i> Send to General Manager</button><?php endif; ?>
            </div>
        </form>
    </div>
</section>
<?php elseif ($request): ?>
<section class="dec-panel"><div class="dec-panel-heading"><div><h2><?php echo dec_escape($request['file_name']); ?></h2><p><?php echo dec_escape($request['request_reference']); ?></p></div><span class="dec-badge"><?php echo dec_escape($request['request_status']); ?></span></div>
<?php $directGmFiling = $request['request_status'] === 'Approved' && (int) $request['requested_by'] === (int) $request['reviewed_by']; ?>
<dl class="dec-details"><div><dt><?php echo $directGmFiling ? 'Declared directly by' : 'Submitted by'; ?></dt><dd><?php echo dec_escape($request['requester_name']); ?></dd></div><div><dt>Assigned reviewer</dt><dd><?php echo dec_escape($request['requested_signer_role']); ?></dd></div><div><dt><?php echo $directGmFiling ? 'Declared' : 'Submitted'; ?></dt><dd><?php echo dec_escape($directGmFiling ? $request['reviewed_at'] : $request['requested_at']); ?></dd></div><div><dt>Submitted version</dt><dd><?php echo dec_escape($request['source_version']); ?></dd></div><div><dt>Review basis</dt><dd><?php echo $request['declaration_basis'] === 'External Signed Copy' ? 'Legacy declaration request' : 'File review and GM approval'; ?></dd></div></dl>
<?php if ($request['request_remarks']): ?><p class="dec-note"><?php echo nl2br(dec_escape($request['request_remarks'])); ?></p><?php endif; ?>
<div class="dec-actions"><button class="dec-btn" type="button" data-declaration-preview data-file-url="download.php?type=document&amp;record_id=<?php echo (int) $request['document_id']; ?>" data-file-name="<?php echo dec_escape($request['file_name']); ?>" data-preview-type="<?php echo dec_preview_type((string) $request['file_name']); ?>"><i class="fas fa-eye" aria-hidden="true"></i> Preview current file</button>
<?php if ($request['official_document_id']): ?><a class="dec-btn dec-primary" href="download.php?type=document&amp;record_id=<?php echo (int) $request['official_document_id']; ?>" target="_blank" rel="noopener">View Official Record</a><?php endif; ?></div>
<?php if ($request['request_status'] === 'Pending' && $isGmReviewer): ?>
<form id="declarationDecisionForm" action="actions/document_handler.php" method="post" class="dec-form dec-divider dec-decision-form" data-declaration-form novalidate>
<input type="hidden" name="csrf_token" value="<?php echo dec_escape($csrf); ?>"><input type="hidden" name="doc_id" value="<?php echo (int) $request['document_id']; ?>"><input type="hidden" name="request_id" value="<?php echo $requestId; ?>"><input type="hidden" name="action" id="declarationDecisionAction" value="">
<div class="dec-form-intro"><h3>Make your decision</h3><p>Please open the copy above and check that it is complete and appropriate for Official Record filing. If the file changed, return it so the requester can submit the latest copy.</p></div>
<label>Decision remarks <textarea name="remarks" maxlength="2000" rows="3" placeholder="Required when returning the request"></textarea></label>
<label class="dec-check"><input type="checkbox" name="gm_review_confirmed" value="1"><span>I opened and reviewed this exact file and confirm that it is complete and ready for Official Record filing.</span></label>
<p class="dec-esign-note"><i class="fas fa-signature" aria-hidden="true"></i>Your active management account and electronic-signature consent will be recorded when you verify and file this Official Record.</p>
<div class="dec-error" role="alert" tabindex="-1" hidden></div><div class="dec-form-footer"><button class="dec-btn dec-cancel" type="button" data-declaration-return>Return to requester</button><button class="dec-btn dec-primary" type="button" data-declaration-sign>Approve &amp; file Official Record</button></div></form>
<?php endif; ?>
<?php if ($request['request_status'] === 'Pending' && (int) $request['requested_by'] === $uid): ?>
<form action="actions/official_declaration_handler.php" method="post" class="dec-form dec-divider" data-declaration-form novalidate><input type="hidden" name="csrf_token" value="<?php echo dec_escape($csrf); ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="doc_id" value="<?php echo (int) $request['document_id']; ?>"><input type="hidden" name="request_id" value="<?php echo $requestId; ?>"><div class="dec-error" role="alert" tabindex="-1" hidden></div><button class="dec-btn" type="submit">Cancel my request</button></form>
<?php endif; ?>
<?php if ($request['request_status'] !== 'Pending'): ?><div class="dec-divider"><p><?php echo dec_escape($request['request_status']); ?> by <?php echo dec_escape($request['reviewer_name'] ?: 'Recorded user'); ?> · <?php echo dec_escape($request['reviewed_at']); ?></p><p class="dec-note"><?php echo nl2br(dec_escape($request['review_remarks'])); ?></p><?php if ($request['verification_code']): ?><div class="dec-signature-evidence"><span>GM electronic approval</span><div class="dec-signature-image"><?php if (!empty($request['signature_image_path'])): ?><img src="signature_print_image.php?id=<?php echo (int) $request['signature_id']; ?>" alt="Electronic approval of <?php echo dec_escape($request['signer_name']); ?>"><?php else: ?><strong>/s/ <?php echo dec_escape($request['signer_name'] ?: $request['reviewer_name']); ?></strong><?php endif; ?></div><small><i class="fas fa-shield-check" aria-hidden="true"></i> Verified · <?php echo dec_escape($request['verification_code']); ?></small></div><?php endif; ?><?php if (in_array($request['request_status'], ['Returned','Cancelled','Superseded'], true) && (int) $request['requested_by'] === $uid): ?><a class="dec-btn" href="official_declarations.php?doc_id=<?php echo (int) $request['document_id']; ?>">Submit latest copy for review</a><?php endif; ?></div><?php endif; ?></section>
<?php elseif ($error === ''): ?>
<section class="dec-panel dec-list">
<div class="dec-table-scroll"><table><thead><tr><th>Document / request</th><th>Requester</th><th>Reviewer</th><th>Status</th><th>Submitted</th><th><span class="visually-hidden">Open</span></th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr class="dec-request-row">
<td title="<?php echo dec_escape($row['file_name']); ?>"><strong><?php echo dec_escape($row['file_name']); ?></strong><small><?php echo dec_escape($row['request_reference']); ?></small></td>
<td data-label="Requested by"><?php echo dec_escape($row['requester_name']); ?></td>
<td data-label="Reviewer"><?php echo dec_escape($row['requested_signer_role']); ?></td>
<td><span class="dec-badge"><?php echo dec_escape($row['request_status']); ?></span></td>
<td data-label="Submitted"><?php echo dec_escape($row['requested_at']); ?></td>
<td class="dec-open-cell"><a class="dec-btn dec-open-request" href="official_declarations.php?request_id=<?php echo (int) $row['request_id']; ?>" aria-label="Open <?php echo dec_escape($row['request_reference']); ?>"><span class="dec-open-label">Open request</span><i class="fas fa-arrow-right" aria-hidden="true"></i></a></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr class="dec-empty-row"><td colspan="6" class="dec-empty">No requests match this view. Submit a Company File for GM review.</td></tr><?php endif; ?></tbody></table></div>
<footer class="dec-pagination"><span><?php echo $total; ?> request(s) · Page <?php echo $page; ?> of <?php echo $pages; ?></span><nav class="dec-actions" aria-label="Request pages"><?php foreach (['Previous' => $page - 1, 'Next' => $page + 1] as $label => $number): ?><?php if ($number >= 1 && $number <= $pages): ?><a class="dec-btn" href="?<?php echo dec_escape(http_build_query(['q'=>$search,'status'=>$status,'page'=>$number])); ?>"><?php echo $label; ?></a><?php else: ?><span class="dec-btn" aria-disabled="true"><?php echo $label; ?></span><?php endif; ?><?php endforeach; ?></nav></footer></section>
<?php endif; ?>
</div></main>
<div class="modal fade dec-preview-modal" id="declarationPreviewModal" tabindex="-1" aria-labelledby="declarationPreviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="dec-preview-heading"><span class="dec-preview-icon"><i class="fas fa-file-alt" aria-hidden="true"></i></span><div><span class="dec-preview-eyebrow">Document review</span><h2 class="modal-title" id="declarationPreviewTitle">File preview</h2></div></div>
                <button type="button" class="dec-preview-close" data-bs-dismiss="modal" aria-label="Close preview"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <div class="modal-body">
                <div class="dec-preview-loading" data-preview-loading role="status">Opening your file…</div>
                <img class="dec-preview-image" data-preview-image alt="" hidden>
                <div class="dec-pdf-viewer" data-preview-pdf hidden>
                    <div class="dec-pdf-toolbar" aria-label="PDF page controls">
                        <button type="button" data-preview-prev aria-label="Previous page" title="Previous page"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
                        <span data-preview-page aria-live="polite">Page 1 of 1</span>
                        <button type="button" data-preview-next aria-label="Next page" title="Next page"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
                        <span class="dec-pdf-toolbar-divider" aria-hidden="true"></span>
                        <button type="button" data-preview-zoom-out aria-label="Zoom out" title="Zoom out"><i class="fas fa-minus" aria-hidden="true"></i></button>
                        <button type="button" data-preview-zoom-in aria-label="Zoom in" title="Zoom in"><i class="fas fa-plus" aria-hidden="true"></i></button>
                    </div>
                    <div class="dec-pdf-page" data-preview-page-scroll><canvas data-preview-canvas aria-label="PDF page preview"></canvas></div>
                </div>
                <div class="dec-preview-fallback" data-preview-fallback hidden><i class="fas fa-file-download" aria-hidden="true"></i><h3>Preview is not available for this file type</h3><p>Please download and open the file to review it before you continue.</p></div>
            </div>
            <div class="modal-footer"><button type="button" class="dec-btn dec-preview-done" data-bs-dismiss="modal">Close preview</button><a class="dec-btn dec-primary" data-preview-download href="#" download><i class="fas fa-download" aria-hidden="true"></i> Download file</a></div>
        </div>
    </div>
</div>
<?php echo drms_frontend_script_tags(['bootstrap']); ?>
<script src="assets/js/e-signature.js?v=<?php echo (string) (@filemtime(__DIR__.'/assets/js/e-signature.js') ?: 1); ?>"></script>
<script src="assets/js/local-pdf-loader.js?v=<?php echo (string) (@filemtime(__DIR__.'/assets/js/local-pdf-loader.js') ?: 1); ?>"></script>
<script src="assets/js/official-declarations.js?v=<?php echo filemtime(__DIR__.'/assets/js/official-declarations.js'); ?>"></script>
</body></html>
