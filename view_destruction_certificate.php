<?php
require 'config/db_connect.php';
require 'config/functions.php';
require_once 'config/physical_records.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

if (!has_permission($conn, $_SESSION['user_id'], 'can_view_disposition')) {
    http_response_code(403);
    die('Access denied.');
}

$certificateId = (int) ($_GET['id'] ?? 0);
if ($certificateId < 1) {
    http_response_code(400);
    die('Invalid certificate.');
}

$stmt = $conn->prepare(
    "SELECT c.*, r.requested_action, r.status AS request_status,
            r.requested_at, r.reviewed_at, r.execution_notes,
            d.record_number, d.doc_type, d.disposition_status,
            p.policy_name,
            requester.full_name AS requester_name,
            reviewer.full_name AS reviewer_name,
            executor.full_name AS executor_name,
            pd.evidence_number AS physical_evidence_number,
            pd.record_number AS physical_record_number,
            pd.source_folder_id AS physical_source_folder_id,
            pd.source_path AS physical_source_path,
            pd.physical_version AS disposed_physical_version,
            pd.copy_status AS disposed_copy_status,
            pd.disposal_method AS physical_disposal_method,
            pd.reason AS physical_disposal_reason,
            pd.digital_certificate_number AS linked_digital_certificate_number,
            pd.digital_certificate_hash AS linked_digital_certificate_hash,
            pd.disposed_by AS physical_disposed_by,
            pd.disposed_by_name AS physical_disposed_by_name,
            pd.disposed_at AS physical_disposed_at,
            pd.evidence_hash AS physical_evidence_hash,
            (SELECT COUNT(*) FROM virt_document_locations location WHERE location.document_id=d.doc_id) AS registered_physical_copy
       FROM destruction_certificates c
       JOIN disposition_requests r ON r.request_id = c.request_id
       JOIN documents d ON d.doc_id = c.doc_id
       JOIN retention_policies p ON p.policy_id = c.retention_policy_id
       JOIN users requester ON requester.user_id = c.requested_by
       JOIN users reviewer ON reviewer.user_id = c.reviewed_by
       JOIN users executor ON executor.user_id = c.destroyed_by
       LEFT JOIN physical_disposition_logs pd ON pd.document_id=c.doc_id
      WHERE c.certificate_id = ?
      LIMIT 1"
);
$stmt->bind_param('i', $certificateId);
$stmt->execute();
$certificate = $stmt->get_result()->fetch_assoc();

if (!$certificate) {
    http_response_code(404);
    die('Destruction certificate not found.');
}

$certificatePayload = [
    'certificate_number' => $certificate['certificate_number'],
    'request_id' => (int) $certificate['request_id'],
    'doc_id' => (int) $certificate['doc_id'],
    'file_sha256' => $certificate['file_sha256'],
    'file_size' => (int) $certificate['file_size'],
    'requested_by' => (int) $certificate['requested_by'],
    'reviewed_by' => (int) $certificate['reviewed_by'],
    'destroyed_by' => (int) $certificate['destroyed_by'],
    'destroyed_at' => $certificate['destroyed_at'],
    'method' => $certificate['deletion_method']
];
$computedCertificateHash = hash(
    'sha256',
    json_encode($certificatePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
$certificateIntegrityValid = hash_equals($certificate['certificate_hash'], $computedCertificateHash);
$digitalOnlyCertificate = str_starts_with((string) $certificate['deletion_method'], 'Digital file only:');
$physicalEvidenceValid = null;
if (!empty($certificate['physical_evidence_number'])) {
    $physicalEvidencePayload = [
        'evidence_number' => $certificate['physical_evidence_number'],
        'document_id' => (int) $certificate['doc_id'],
        'record_number' => $certificate['physical_record_number'],
        'source_folder_id' => (int) $certificate['physical_source_folder_id'],
        'source_path' => $certificate['physical_source_path'],
        'physical_version' => (string) $certificate['disposed_physical_version'],
        'copy_status' => $certificate['disposed_copy_status'],
        'disposal_method' => $certificate['physical_disposal_method'],
        'reason' => $certificate['physical_disposal_reason'],
        'digital_certificate_number' => $certificate['linked_digital_certificate_number'],
        'digital_certificate_hash' => $certificate['linked_digital_certificate_hash'],
        'disposed_by' => (int) $certificate['physical_disposed_by'],
        'disposed_by_name' => $certificate['physical_disposed_by_name'],
        'disposed_at' => $certificate['physical_disposed_at'],
    ];
    $physicalEvidenceValid = hash_equals((string)$certificate['physical_evidence_hash'], drms_copy_disposal_hash($physicalEvidencePayload))
        && hash_equals((string)$certificate['certificate_number'], (string)$certificate['linked_digital_certificate_number'])
        && hash_equals((string)$certificate['certificate_hash'], (string)$certificate['linked_digital_certificate_hash']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($certificate['certificate_number']); ?> - Destruction Certificate</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        .certificate-shell { max-width: 960px; margin: 0 auto; }
        .certificate-card { border: 1px solid #dfe5ec; border-radius: 14px; background: #fff; }
        .certificate-kicker { letter-spacing: .09em; font-size: .72rem; }
        .certificate-title { font-size: 1.35rem; letter-spacing: -.02em; }
        .certificate-label { color: #748094; font-size: .72rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
        .certificate-value { color: #172033; font-size: .9rem; font-weight: 600; }
        .hash-value { overflow-wrap: anywhere; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .76rem; }
        .actor-step { min-height: 76px; border: 1px solid #e5e9ef; border-radius: 10px; }
        @media print {
            body { background: #fff !important; }
            .sidebar, .certificate-actions { display: none !important; }
            .main-content { margin: 0 !important; padding: 0 !important; }
            .certificate-card { border: 1px solid #aeb7c4 !important; box-shadow: none !important; }
        }
    </style>
</head>
<body class="bg-f8f9fa">
<?php include 'sidebar.php'; ?>

<main class="main-content fade-in">
    <div class="certificate-shell py-3">
        <div class="certificate-actions d-flex justify-content-between align-items-center mb-3">
            <a href="documents.php?disposition=1" class="btn btn-sm btn-light border fw-semibold px-3">
                <i class="fas fa-arrow-left me-1"></i> Back to disposition
            </a>
            <button type="button" class="btn btn-sm btn-dark fw-semibold px-3" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print / Save PDF
            </button>
        </div>

        <section class="certificate-card shadow-sm overflow-hidden">
            <header class="px-4 py-4 border-bottom d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <div class="certificate-kicker text-danger fw-bold text-uppercase mb-1">Official disposition record</div>
                    <h1 class="certificate-title fw-bold text-dark mb-1"><?php echo $digitalOnlyCertificate ? 'Certificate of Digital File Destruction' : 'Certificate of Record Destruction'; ?></h1>
                    <p class="text-muted small mb-0"><?php echo $digitalOnlyCertificate ? 'Evidence of digital-file deletion only. This does not certify disposal of any physical paper copy.' : 'Verifiable evidence of an approved and executed retention action.'; ?></p>
                </div>
                <div class="text-md-end">
                    <div class="certificate-label mb-1">Certificate number</div>
                    <div class="fs-5 fw-bold text-dark"><?php echo e($certificate['certificate_number']); ?></div>
                    <?php if ($certificateIntegrityValid): ?>
                        <span class="badge bg-success mt-2 px-3 py-2"><i class="fas fa-check-circle me-1"></i> Integrity verified</span>
                    <?php else: ?>
                        <span class="badge bg-danger mt-2 px-3 py-2"><i class="fas fa-exclamation-triangle me-1"></i> Integrity warning</span>
                    <?php endif; ?>
                </div>
            </header>

            <div class="p-4">
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="certificate-label mb-1">Official record number</div>
                        <div class="certificate-value"><?php echo e($certificate['record_number'] ?: 'Not assigned'); ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="certificate-label mb-1">Document type</div>
                        <div class="certificate-value"><?php echo e($certificate['doc_type']); ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="certificate-label mb-1">Destroyed at</div>
                        <div class="certificate-value"><?php echo date('M d, Y · h:i A', strtotime($certificate['destroyed_at'])); ?></div>
                    </div>
                    <div class="col-md-8">
                        <div class="certificate-label mb-1">Document name</div>
                        <div class="certificate-value"><?php echo e($certificate['file_name']); ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="certificate-label mb-1">Verified size</div>
                        <div class="certificate-value"><?php echo number_format(((int) $certificate['file_size']) / 1024, 2); ?> KB</div>
                    </div>
                </div>

                <div class="border rounded-3 p-3 bg-light mb-4">
                    <div class="certificate-label mb-2">Retention authority</div>
                    <div class="certificate-value mb-2"><?php echo e($certificate['policy_name']); ?></div>
                    <div class="small text-muted"><?php echo e($certificate['retention_authority']); ?></div>
                    <hr class="my-3">
                    <div class="certificate-label mb-2">Approved reason</div>
                    <div class="small text-dark"><?php echo nl2br(e($certificate['reason'])); ?></div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="actor-step p-3">
                            <div class="certificate-label mb-2"><i class="fas fa-paper-plane text-warning me-1"></i> Requested by</div>
                            <div class="certificate-value"><?php echo e($certificate['requester_name']); ?></div>
                            <div class="small text-muted mt-1"><?php echo date('M d, Y · h:i A', strtotime($certificate['requested_at'])); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="actor-step p-3">
                            <div class="certificate-label mb-2"><i class="fas fa-user-check text-success me-1"></i> Independently approved by</div>
                            <div class="certificate-value"><?php echo e($certificate['reviewer_name']); ?></div>
                            <div class="small text-muted mt-1"><?php echo date('M d, Y · h:i A', strtotime($certificate['reviewed_at'])); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="actor-step p-3">
                            <div class="certificate-label mb-2"><i class="fas fa-shield-alt text-danger me-1"></i> Executed by</div>
                            <div class="certificate-value"><?php echo e($certificate['executor_name']); ?></div>
                            <div class="small text-muted mt-1"><?php echo date('M d, Y · h:i A', strtotime($certificate['destroyed_at'])); ?></div>
                        </div>
                    </div>
                </div>

                <div class="border-top pt-3">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="certificate-label mb-1">Deletion method</div>
                            <div class="small text-dark"><?php echo e($certificate['deletion_method']); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="certificate-label mb-1">Destroyed file SHA-256</div>
                            <div class="hash-value text-dark"><?php echo e($certificate['file_sha256']); ?></div>
                        </div>
                        <div class="col-12">
                            <div class="certificate-label mb-1">Certificate integrity hash</div>
                            <div class="hash-value text-dark"><?php echo e($certificate['certificate_hash']); ?></div>
                        </div>
                    </div>
                </div>

                <div class="border-top mt-4 pt-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <div>
                            <div class="certificate-label mb-1">Physical copy status</div>
                            <div class="certificate-value">Separate paper-copy control</div>
                        </div>
                        <?php if ($physicalEvidenceValid === true): ?>
                            <span class="badge bg-success px-3 py-2"><i class="fas fa-check-circle me-1"></i> Physical evidence verified</span>
                        <?php elseif ($physicalEvidenceValid === false): ?>
                            <span class="badge bg-danger px-3 py-2"><i class="fas fa-exclamation-triangle me-1"></i> Physical evidence warning</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($certificate['physical_evidence_number'])): ?>
                        <div class="row g-3">
                            <div class="col-md-4"><div class="certificate-label mb-1">Evidence number</div><div class="certificate-value"><?php echo e($certificate['physical_evidence_number']); ?></div></div>
                            <div class="col-md-4"><div class="certificate-label mb-1">Disposed at</div><div class="certificate-value"><?php echo date('M d, Y · h:i A', strtotime($certificate['physical_disposed_at'])); ?></div></div>
                            <div class="col-md-4"><div class="certificate-label mb-1">Confirmed by</div><div class="certificate-value"><?php echo e($certificate['physical_disposed_by_name']); ?></div></div>
                            <div class="col-md-4"><div class="certificate-label mb-1">Method</div><div class="certificate-value"><?php echo e($certificate['physical_disposal_method']); ?></div></div>
                            <div class="col-md-8"><div class="certificate-label mb-1">Location at disposal</div><div class="certificate-value"><?php echo e($certificate['physical_source_path']); ?></div></div>
                            <div class="col-md-4"><div class="certificate-label mb-1">Recorded paper version</div><div class="certificate-value">v<?php echo e($certificate['disposed_physical_version']); ?> · <?php echo e($certificate['disposed_copy_status']); ?></div></div>
                            <div class="col-md-8"><div class="certificate-label mb-1">Disposal reason</div><div class="small text-dark"><?php echo nl2br(e($certificate['physical_disposal_reason'])); ?></div></div>
                            <div class="col-12"><div class="certificate-label mb-1">Physical evidence SHA-256</div><div class="hash-value text-dark"><?php echo e($certificate['physical_evidence_hash']); ?></div></div>
                        </div>
                    <?php elseif ((int)$certificate['registered_physical_copy'] > 0): ?>
                        <div class="small text-dark border rounded-3 bg-light p-3">The digital file was destroyed, but its registered paper copy remains retained and tracked in the Virtual Cabinet. No physical-disposal evidence has been recorded.</div>
                    <?php else: ?>
                        <div class="small text-muted border rounded-3 bg-light p-3">No registered physical copy and no separate physical-disposal evidence are recorded. This digital certificate alone does not prove that paper was destroyed.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
