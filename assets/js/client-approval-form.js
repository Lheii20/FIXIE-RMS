(function () {
    'use strict';

    const OFFICIAL_MODE = 'Official Client PO';
    const MAX_FILE_SIZE = 10 * 1024 * 1024;
    const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    function getFileExtension(fileName) {
        const parts = String(fileName || '').toLowerCase().split('.');
        return parts.length > 1 ? parts.pop() : '';
    }

    function validateFile(fileInput) {
        if (!fileInput) return true;
        fileInput.setCustomValidity('');
        if (!fileInput.files.length) return true;

        const file = fileInput.files[0];
        const extension = getFileExtension(file.name);

        if (!ALLOWED_EXTENSIONS.includes(extension)) {
            fileInput.setCustomValidity('Select a PDF, JPG, JPEG, or PNG file.');
            fileInput.reportValidity();
            return false;
        }

        if (file.size < 1 || file.size > MAX_FILE_SIZE) {
            fileInput.setCustomValidity('The selected file must not exceed 10 MB.');
            fileInput.reportValidity();
            return false;
        }

        return true;
    }

    function updateFileName(modal, fileInput) {
        const fileName = modal.querySelector('[data-client-approval-file-name]');
        if (!fileName) return;
        fileName.textContent = fileInput && fileInput.files.length
            ? fileInput.files[0].name
            : 'Select PDF or image';
    }

    function updateModeState(modal) {
        const mode = modal.querySelector('[data-client-approval-mode]');
        const officialFields = modal.querySelector('[data-client-approval-official-fields]');
        const guidance = modal.querySelector('[data-client-approval-guidance]');
        const fileLabel = modal.querySelector('[data-client-approval-file-label]');
        const submitLabel = modal.querySelector('[data-client-approval-submit-label]');

        if (!mode || !officialFields) return;
        const isOfficial = mode.value === OFFICIAL_MODE;
        officialFields.hidden = !isOfficial;

        officialFields.querySelectorAll('[data-official-required]').forEach(function (field) {
            field.required = isOfficial;
            if (!isOfficial) {
                field.value = '';
                field.setCustomValidity('');
            }
        });

        if (guidance) {
            guidance.classList.toggle('is-official', isOfficial);
            guidance.innerHTML = isOfficial
                ? '<i class="fas fa-check-circle"></i><span>This will mark the quotation as <strong>Official Client PO Received</strong> and make it eligible for PR creation.</span>'
                : '<i class="fas fa-info-circle"></i><span>This will be stored as supporting confirmation only. The quotation remains pending until an official signed Client PO is recorded.</span>';
        }

        if (fileLabel) {
            fileLabel.textContent = isOfficial
                ? 'Signed official Client PO'
                : 'Proof of client response';
        }

        if (submitLabel) {
            submitLabel.textContent = isOfficial
                ? 'Record official PO'
                : 'Save confirmation';
        }
    }

    function resetModalForm(modal) {
        const form = modal.querySelector('[data-client-approval-form]');
        if (!form) return;
        form.reset();

        const submitButton = modal.querySelector('[data-client-approval-submit]');
        if (submitButton) submitButton.disabled = false;

        const fileInput = modal.querySelector('[data-client-approval-file]');
        if (fileInput) fileInput.setCustomValidity('');
        updateFileName(modal, fileInput);
        updateModeState(modal);
    }

    function assignQuotation(modal, quotationId, quotationNumber) {
        const idField = modal.querySelector('[data-client-approval-quotation-id]');
        const quoteLabel = modal.querySelector('[data-client-approval-quote-label]');
        if (idField && quotationId) idField.value = quotationId;
        if (quoteLabel && quotationNumber) quoteLabel.textContent = quotationNumber;
    }

    document.querySelectorAll('[data-client-approval-modal]').forEach(function (modal) {
        const mode = modal.querySelector('[data-client-approval-mode]');
        const fileInput = modal.querySelector('[data-client-approval-file]');
        const form = modal.querySelector('[data-client-approval-form]');

        if (mode) {
            mode.addEventListener('change', function () {
                updateModeState(modal);
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', function () {
                validateFile(fileInput);
                updateFileName(modal, fileInput);
            });
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                if (!validateFile(fileInput) || !form.checkValidity()) {
                    event.preventDefault();
                    form.reportValidity();
                    return;
                }

                const submitButton = modal.querySelector('[data-client-approval-submit]');
                if (submitButton) submitButton.disabled = true;
            });
        }

        modal.addEventListener('hidden.bs.modal', function () {
            resetModalForm(modal);
        });

        updateModeState(modal);
    });

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-client-approval-trigger]');
        if (!trigger) return;

        const modalId = trigger.dataset.clientApprovalModalId || 'clientApprovalModal';
        const modal = document.getElementById(modalId);
        if (!modal) return;

        resetModalForm(modal);
        assignQuotation(
            modal,
            trigger.dataset.quotationId || '',
            trigger.dataset.quotationNumber || ''
        );

        bootstrap.Modal.getOrCreateInstance(modal).show();
    });
})();
