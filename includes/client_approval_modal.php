<?php
// Include-only UI partial: reject direct web execution without changing its
// behavior when rendered by quotation pages.
if (
    PHP_SAPI !== 'cli' &&
    isset($_SERVER['SCRIPT_FILENAME']) &&
    realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(404);
    exit('Not found.');
}

$client_approval_modal_id = preg_replace(
    '/[^A-Za-z0-9_-]/',
    '',
    $client_approval_modal_id ?? 'clientApprovalModal'
);

$client_approval_quotation_id = $client_approval_quotation_id ?? '';
$client_approval_quotation_number = $client_approval_quotation_number ?? '';
$client_approval_today = date('Y-m-d');

$mode_id = $client_approval_modal_id . 'Mode';
$po_number_id = $client_approval_modal_id . 'PoNumber';
$po_date_id = $client_approval_modal_id . 'PoDate';
$approval_date_id = $client_approval_modal_id . 'ApprovalDate';
$file_id = $client_approval_modal_id . 'File';
$remarks_id = $client_approval_modal_id . 'Remarks';
?>

<div
    class="modal fade client-approval-modal"
    id="<?php echo htmlspecialchars($client_approval_modal_id); ?>"
    tabindex="-1"
    aria-labelledby="<?php echo htmlspecialchars($client_approval_modal_id); ?>Title"
    aria-hidden="true"
    data-client-approval-modal
>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form
                action="actions/quotation_handler.php"
                method="POST"
                enctype="multipart/form-data"
                data-client-approval-form
            >
                <div class="modal-header">
                    <div class="client-approval-heading">
                        <div class="client-approval-eyebrow">Client document control</div>
                        <h5
                            class="modal-title"
                            id="<?php echo htmlspecialchars($client_approval_modal_id); ?>Title"
                        >
                            Record client response
                        </h5>
                        <p>
                            Quotation
                            <strong data-client-approval-quote-label>
                                <?php echo htmlspecialchars($client_approval_quotation_number); ?>
                            </strong>
                        </p>
                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"
                    ></button>
                </div>

                <div class="modal-body">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>"
                    >
                    <input type="hidden" name="action" value="receive_po">
                    <input
                        type="hidden"
                        name="quotation_id"
                        value="<?php echo htmlspecialchars((string) $client_approval_quotation_id); ?>"
                        data-client-approval-quotation-id
                    >

                    <div class="client-approval-guidance" data-client-approval-guidance>
                        <i class="fas fa-info-circle"></i>
                        <span>
                            Select the response type. Supporting confirmations remain
                            pending until an official signed Client PO is recorded.
                        </span>
                    </div>

                    <div class="client-approval-field">
                        <label for="<?php echo htmlspecialchars($mode_id); ?>">
                            Approval channel <span class="required-mark">*</span>
                        </label>
                        <select
                            name="approval_mode"
                            id="<?php echo htmlspecialchars($mode_id); ?>"
                            class="form-select"
                            required
                            data-client-approval-mode
                        >
                            <option value="" selected disabled>Select a response type</option>
                            <optgroup label="Supporting confirmation">
                                <option value="Messenger Chat">Messenger Chat</option>
                                <option value="Viber / WhatsApp Chat">Viber / WhatsApp Chat</option>
                                <option value="Email Confirmation">Email Confirmation</option>
                                <option value="Signed Quotation">Signed Quotation</option>
                                <option value="In-Person Confirmation">In-Person Confirmation</option>
                                <option value="Other Written Confirmation">Other Written Confirmation</option>
                            </optgroup>
                            <optgroup label="Final official document">
                                <option value="Official Client PO">Official Client PO</option>
                            </optgroup>
                        </select>
                    </div>

                    <div
                        class="client-approval-official-fields"
                        data-client-approval-official-fields
                        hidden
                    >
                        <div class="client-approval-section-label">Official Client PO details</div>

                        <div class="client-approval-field">
                            <label for="<?php echo htmlspecialchars($po_number_id); ?>">
                                Actual Client PO number <span class="required-mark">*</span>
                            </label>
                            <input
                                type="text"
                                name="actual_client_po_number"
                                id="<?php echo htmlspecialchars($po_number_id); ?>"
                                class="form-control"
                                maxlength="100"
                                autocomplete="off"
                                placeholder="e.g. PO-2026-00481"
                                data-official-required
                            >
                        </div>

                        <div class="row g-3">
                            <div class="col-sm-6">
                                <div class="client-approval-field mb-0">
                                    <label for="<?php echo htmlspecialchars($po_date_id); ?>">
                                        Client PO date <span class="required-mark">*</span>
                                    </label>
                                    <input
                                        type="date"
                                        name="client_po_date"
                                        id="<?php echo htmlspecialchars($po_date_id); ?>"
                                        class="form-control"
                                        max="<?php echo htmlspecialchars($client_approval_today); ?>"
                                        data-official-required
                                    >
                                </div>
                            </div>

                            <div class="col-sm-6">
                                <div class="client-approval-field mb-0">
                                    <label for="<?php echo htmlspecialchars($approval_date_id); ?>">
                                        Final approval date <span class="required-mark">*</span>
                                    </label>
                                    <input
                                        type="date"
                                        name="final_approval_date"
                                        id="<?php echo htmlspecialchars($approval_date_id); ?>"
                                        class="form-control"
                                        max="<?php echo htmlspecialchars($client_approval_today); ?>"
                                        data-official-required
                                    >
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="client-approval-field">
                        <label for="<?php echo htmlspecialchars($file_id); ?>">
                            <span data-client-approval-file-label>Proof of client response</span>
                            <span class="required-mark">*</span>
                        </label>
                        <label
                            class="client-approval-file-control"
                            for="<?php echo htmlspecialchars($file_id); ?>"
                        >
                            <i class="fas fa-paperclip"></i>
                            <span class="client-approval-file-copy">
                                <strong data-client-approval-file-name>Select PDF or image</strong>
                                <small>PDF, JPG, or PNG · maximum 10 MB</small>
                            </span>
                            <span class="client-approval-browse">Browse</span>
                        </label>
                        <input
                            type="file"
                            name="po_file"
                            id="<?php echo htmlspecialchars($file_id); ?>"
                            class="visually-hidden"
                            accept=".pdf,.jpg,.jpeg,.png"
                            required
                            data-client-approval-file
                        >
                    </div>

                    <div class="client-approval-field mb-0">
                        <label for="<?php echo htmlspecialchars($remarks_id); ?>">
                            Remarks <span class="optional-label">Optional</span>
                        </label>
                        <textarea
                            name="remarks"
                            id="<?php echo htmlspecialchars($remarks_id); ?>"
                            class="form-control"
                            rows="2"
                            maxlength="2000"
                            placeholder="Add a concise note for the record"
                        ></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-light client-approval-cancel"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="btn btn-primary client-approval-submit"
                        data-client-approval-submit
                    >
                        <i class="fas fa-check me-1"></i>
                        <span data-client-approval-submit-label>Save confirmation</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
