<?php

declare(strict_types=1);

require 'config/db_connect.php';
require 'config/functions.php';
require_once 'config/workflow_access.php';
require_once 'config/navigation_context.php';
require_once 'config/payment_signature.php';
require_once 'config/payment_signature_ui.php';

date_default_timezone_set('Asia/Manila');

drms_require_workflow_roles([
    'Procurement',
    'GM',
    'President',
    'Finance',
    'Supply Chain',
]);

$paymentId = (int) ($_GET['payment_id'] ?? 0);
$payment = null;
$pageError = '';
$evidence = ['state' => 'legacy', 'event' => null];

if ($paymentId < 1) {
    $pageError = 'Select a valid client payment record.';
} else {
    try {
        $statement = $conn->prepare(
            "SELECT
                payment.*,
                po.po_number,
                po.client_name,
                po.amount AS po_amount,
                recorder.full_name AS recorded_by_name,
                payment_record.doc_id AS payment_record_doc_id,
                payment_record.record_number AS payment_record_number
             FROM payments payment
             INNER JOIN purchase_orders po
                ON po.po_id = payment.po_id
             LEFT JOIN users recorder
                ON recorder.user_id = payment.recorded_by
             LEFT JOIN documents payment_record
                ON payment_record.source_module = 'Client Payment'
               AND payment_record.source_record_id = payment.payment_id
               AND payment_record.record_phase = 'Official'
               AND payment_record.status <> 'Recycled'
               AND payment_record.is_locked = 1
             WHERE payment.payment_id = ?
             LIMIT 1"
        );
        $statement->bind_param('i', $paymentId);
        $statement->execute();
        $payment = $statement->get_result()->fetch_assoc();
        $statement->close();

        if (!$payment) {
            $pageError = 'The client payment record is unavailable.';
        } else {
            $evidence = drms_payment_signature_evidence($conn, $paymentId);
        }
    } catch (Throwable $error) {
        drms_log_workflow_failure(
            'Payment signature evidence page for payment ' . $paymentId,
            $error
        );
        $pageError =
            'The payment signature evidence could not be loaded. Try again later.';
    }
}

$backUrl = drms_contextual_back_url(
    $payment && !empty($payment['po_id'])
        ? 'view_po.php?id=' . (int) $payment['po_id']
        : 'collection_ledger.php',
    'payment-signature:' . $paymentId
);
$state = (string) ($evidence['state'] ?? 'legacy');
$event = is_array($evidence['event'] ?? null) ? $evidence['event'] : [];

function phase9d2_datetime(?string $value): string
{
    if (!$value || strtotime($value) === false) {
        return 'Not recorded';
    }

    return date('M d, Y · g:i A', strtotime($value));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Signature Evidence - Fixie DRMS</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link href="assets/css/workflow-ui.css?v=<?php echo filemtime(__DIR__ . '/assets/css/workflow-ui.css'); ?>" rel="stylesheet">
    <link href="assets/css/payment-signature.css?v=<?php echo filemtime(__DIR__ . '/assets/css/payment-signature.css'); ?>" rel="stylesheet">
</head>
<body class="workflow-ui payment-signature-page">
    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <div class="payment-signature-shell">
            <header class="payment-signature-header">
                <a href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES); ?>" class="payment-signature-back" aria-label="Back">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div class="payment-signature-heading">
                    <span>Controlled financial evidence</span>
                    <h1>Payment signature evidence</h1>
                    <strong><?php echo $payment ? htmlspecialchars((string) $payment['po_number'] . ' · ' . (string) $payment['client_name']) : 'Client payment verification'; ?></strong>
                </div>
                <?php if ($payment): ?>
                    <?php echo drms_payment_signature_chip($evidence, $paymentId, false); ?>
                <?php endif; ?>
            </header>

            <?php if ($pageError !== ''): ?>
                <div class="payment-signature-state-message payment-signature-state-message--invalid" role="alert">
                    <i class="fas fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($pageError); ?></span>
                </div>
            <?php elseif ($payment): ?>
                <div class="payment-signature-grid">
                    <section class="payment-signature-card">
                        <header class="payment-signature-card__head">
                            <div><span>Signature verification</span><strong>Finance certification</strong></div>
                            <?php echo drms_payment_signature_chip($evidence, $paymentId, false); ?>
                        </header>
                        <div class="payment-signature-body">
                            <?php if ($state === 'verified'): ?>
                                <div class="payment-signature-mark">
                                    <?php if (!empty($event['verified_signature_image_path'])): ?>
                                        <img src="signature_print_image.php?id=<?php echo (int) $event['signature_id']; ?>" alt="Verified Finance electronic signature">
                                    <?php else: ?>
                                        <span>/s/ <?php echo htmlspecialchars((string) $event['signer_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="payment-signature-facts">
                                    <div class="payment-signature-fact"><span>Signatory</span><strong><?php echo htmlspecialchars((string) $event['signer_name']); ?></strong></div>
                                    <div class="payment-signature-fact"><span>Organizational role</span><strong><?php echo htmlspecialchars((string) $event['signer_role']); ?></strong></div>
                                    <div class="payment-signature-fact"><span>Signed</span><strong><?php echo htmlspecialchars(phase9d2_datetime((string) $event['signed_at'])); ?></strong></div>
                                    <div class="payment-signature-fact"><span>Verification code</span><code title="<?php echo htmlspecialchars((string) $event['verification_code'], ENT_QUOTES); ?>"><?php echo htmlspecialchars((string) $event['verification_code']); ?></code></div>
                                </div>
                            <?php elseif ($state === 'invalid'): ?>
                                <div class="payment-signature-state-message payment-signature-state-message--invalid" role="alert">
                                    <i class="fas fa-triangle-exclamation"></i>
                                    <span>The stored Finance signature does not match the current payment data or acknowledgement-proof hash. Do not treat this payment as electronically verified until an authorized administrator reviews the audit log.</span>
                                </div>
                            <?php else: ?>
                                <div class="payment-signature-state-message">
                                    <i class="fas fa-clock-rotate-left"></i>
                                    <span>This payment was recorded before Signature Phase 9D1. Its historical payment and Official Record remain readable, but no Finance electronic-signature event exists.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="payment-signature-card">
                        <header class="payment-signature-card__head">
                            <div><span>Signed record context</span><strong>Client payment details</strong></div>
                        </header>
                        <div class="payment-signature-body">
                            <div class="payment-record-facts">
                                <div class="payment-signature-fact"><span>Amount received</span><strong>₱<?php echo number_format((float) $payment['amount_paid'], 2); ?></strong></div>
                                <div class="payment-signature-fact"><span>Classification</span><strong><?php echo htmlspecialchars((string) $payment['payment_classification']); ?></strong></div>
                                <div class="payment-signature-fact"><span>Payment date</span><strong><?php echo htmlspecialchars(phase9d2_datetime((string) $payment['payment_date'])); ?></strong></div>
                                <div class="payment-signature-fact"><span>Method</span><strong><?php echo htmlspecialchars((string) $payment['payment_method']); ?></strong></div>
                                <div class="payment-signature-fact"><span>Reference</span><strong><?php echo htmlspecialchars((string) $payment['reference_number']); ?></strong></div>
                                <div class="payment-signature-fact"><span>Recorded by</span><strong><?php echo htmlspecialchars((string) ($payment['recorded_by_name'] ?: 'Finance')); ?></strong></div>
                                <div class="payment-signature-fact"><span>Proof SHA-256</span><code title="<?php echo htmlspecialchars((string) $payment['proof_file_hash'], ENT_QUOTES); ?>"><?php echo htmlspecialchars((string) $payment['proof_file_hash']); ?></code></div>
                            </div>

                            <div class="payment-signature-proof">
                                <div>
                                    <span>Official payment proof</span>
                                    <strong><?php echo htmlspecialchars((string) ($payment['payment_record_number'] ?: $payment['proof_original_name'] ?: 'Not attached')); ?></strong>
                                </div>
                                <?php if (!empty($payment['payment_record_doc_id'])): ?>
                                    <a href="download.php?type=document&amp;record_id=<?php echo (int) $payment['payment_record_doc_id']; ?>" target="_blank" rel="noopener">
                                        <i class="fas fa-paperclip"></i>Open Official Record
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
