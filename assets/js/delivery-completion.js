(function () {
    'use strict';

    const form = document.getElementById('deliveryCompletionForm');
    if (!form) {
        return;
    }

    const actualHandoverInput = document.getElementById('actualHandoverAt');
    const conditionInput = document.getElementById('deliveryCondition');
    const discrepancyField = document.querySelector('[data-discrepancy-field]');
    const discrepancyInput = document.getElementById('discrepancyNotes');
    const quantityInput = document.getElementById('deliveredItemQuantity');
    const proofInput = document.querySelector('[data-delivery-proof]');
    const proofName = document.querySelector('[data-delivery-proof-name]');
    const confirmationInput = document.querySelector('[data-delivery-completion-confirm]');
    const collectionDue = document.querySelector('[data-collection-due]');
    const validationMessage = document.getElementById('deliveryCompletionValidationMessage');
    const submitButton = document.querySelector('[data-delivery-completion-submit]');
    const configuredCollectionTerm = Number(form.dataset.collectionTermDays);
    const collectionTermDays = Number.isInteger(configuredCollectionTerm) &&
        configuredCollectionTerm > 0
        ? configuredCollectionTerm
        : 15;
    const maximumProofSize = 10 * 1024 * 1024;
    const validProofExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

    function pad(value) {
        return String(value).padStart(2, '0');
    }

    function localDateTimeMaximum() {
        const now = new Date();
        return [
            now.getFullYear(),
            '-',
            pad(now.getMonth() + 1),
            '-',
            pad(now.getDate()),
            'T',
            pad(now.getHours()),
            ':',
            pad(now.getMinutes())
        ].join('');
    }

    function parseLocalDateTime(value) {
        if (!value) {
            return null;
        }

        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function formatDueDate(value) {
        const handover = parseLocalDateTime(value);
        if (!handover) {
            return 'Select actual handover date';
        }

        const dueDate = new Date(handover);
        dueDate.setDate(dueDate.getDate() + collectionTermDays);

        return dueDate.toLocaleDateString('en-US', {
            month: 'short',
            day: '2-digit',
            year: 'numeric'
        });
    }

    function showValidation(message, field) {
        validationMessage.innerHTML = '';

        const icon = document.createElement('i');
        icon.className = 'fas fa-exclamation-circle';
        const text = document.createElement('span');
        text.textContent = message;

        validationMessage.append(icon, text);
        validationMessage.classList.remove('d-none');

        if (field) {
            field.classList.add('is-invalid');
            field.focus();
        }

        validationMessage.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function clearValidation() {
        validationMessage.classList.add('d-none');
        validationMessage.innerHTML = '';
        form.querySelectorAll('.is-invalid').forEach(function (field) {
            field.classList.remove('is-invalid');
        });
    }

    function syncDiscrepancyField() {
        const requiresNotes = conditionInput.value === 'Accepted with Noted Issue';

        discrepancyField.classList.toggle('is-hidden', !requiresNotes);
        discrepancyInput.disabled = !requiresNotes;
        discrepancyInput.required = requiresNotes;

        if (!requiresNotes) {
            discrepancyInput.value = '';
            discrepancyInput.classList.remove('is-invalid');
        }
    }

    function syncDueDate() {
        actualHandoverInput.max = localDateTimeMaximum();
        collectionDue.textContent = formatDueDate(actualHandoverInput.value);
    }

    function proofExtension(file) {
        const parts = file.name.toLowerCase().split('.');
        return parts.length > 1 ? parts.pop() : '';
    }

    function proofError() {
        if (!proofInput.files || proofInput.files.length !== 1) {
            return 'Attach one client acknowledgement proof before continuing.';
        }

        const file = proofInput.files[0];
        if (!validProofExtensions.includes(proofExtension(file))) {
            return 'The acknowledgement proof must be a PDF, JPG, JPEG, or PNG file.';
        }

        if (file.size <= 0 || file.size > maximumProofSize) {
            return 'The acknowledgement proof must not exceed 10 MB.';
        }

        return '';
    }

    conditionInput.addEventListener('change', syncDiscrepancyField);
    actualHandoverInput.addEventListener('change', syncDueDate);
    actualHandoverInput.addEventListener('input', syncDueDate);

    proofInput.addEventListener('change', function () {
        proofInput.classList.remove('is-invalid');
        const file = proofInput.files && proofInput.files[0];
        proofName.textContent = file ? file.name : 'Select signed receipt, email, or acknowledgement';
    });

    form.addEventListener('submit', function (event) {
        clearValidation();
        syncDueDate();

        const handover = parseLocalDateTime(actualHandoverInput.value);
        const now = new Date();

        if (!handover) {
            event.preventDefault();
            showValidation('Enter the actual client handover date and time.', actualHandoverInput);
            return;
        }

        if (handover.getTime() > now.getTime()) {
            event.preventDefault();
            showValidation('Actual handover cannot be later than the current date and time.', actualHandoverInput);
            return;
        }

        const requiredField = Array.from(
            form.querySelectorAll('input[required]:not([type="file"]):not([type="checkbox"]), select[required], textarea[required]')
        ).find(function (field) {
            return !field.disabled && !String(field.value).trim();
        });

        if (requiredField) {
            event.preventDefault();
            showValidation('Complete all required client acknowledgement fields.', requiredField);
            return;
        }

        const expectedQuantity = Number(quantityInput.dataset.expectedQuantity);
        const deliveredQuantity = Number(quantityInput.value);
        if (!Number.isInteger(deliveredQuantity) || deliveredQuantity !== expectedQuantity) {
            event.preventDefault();
            showValidation(
                'Delivered quantity must match the complete PO quantity of ' + expectedQuantity + '.',
                quantityInput
            );
            return;
        }

        const fileError = proofError();
        if (fileError) {
            event.preventDefault();
            showValidation(fileError, proofInput);
            return;
        }

        if (!confirmationInput.checked) {
            event.preventDefault();
            showValidation('Confirm the client receipt and authenticity of the attached evidence.', confirmationInput);
            return;
        }

        const signatureConfirmed = form.elements.e_signature_client_confirmed &&
            form.elements.e_signature_client_confirmed.value === '1';
        if (signatureConfirmed) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<span>Signing &amp; recording...</span><i class="fas fa-spinner fa-spin"></i>';
            return;
        }

        event.preventDefault();
        if (!window.DRMSESignature) {
            window.DRMSFeedback?.toast(
                'The electronic-signature component is unavailable. Refresh the page and try again.',
                'danger',
                3000
            );
            return;
        }

        const openSignature = function () {
            window.DRMSESignature.open({
                form: form,
                title: 'Sign delivery completion',
                subtitle: 'Certify the client handover and acknowledgement evidence before the Official Record is filed.',
                recordLabel: form.dataset.poNumber || form.dataset.requestNumber || 'Client delivery receipt',
                stage: 'Delivery Completion',
                consent: 'I certify that the complete PO quantity was handed over to the named client representative and that the attached acknowledgement evidence is authentic.',
                submitText: 'Sign & record receipt'
            });
        };

        if (window.DRMSFeedback) {
            window.DRMSFeedback.confirm({
                title: 'Finalize client delivery?',
                message: 'Your electronic signature will certify the handover evidence, file the receipt as an Official Record, and start Finance collection when a balance remains.',
                confirmText: 'Continue to signature',
                cancelText: 'Review details',
                tone: 'info',
                focusConfirm: true
            }).then(function (approved) {
                if (approved) {
                    openSignature();
                }
            });
            return;
        }

        openSignature();
    });

    form.addEventListener('input', function (event) {
        event.target.classList.remove('is-invalid');
    });

    syncDiscrepancyField();
    syncDueDate();
}());
