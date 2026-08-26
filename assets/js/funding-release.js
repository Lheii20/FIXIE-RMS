(function () {
    'use strict';

    const maximumFileSize = 10 * 1024 * 1024;
    const allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

    function showMessage(message, focusTarget) {
        const alert = document.getElementById('fundingValidationMessage');
        if (!alert) {
            return;
        }

        alert.replaceChildren();

        const icon = document.createElement('i');
        icon.className = 'fas fa-exclamation-circle';

        const text = document.createElement('span');
        text.textContent = message;

        alert.append(icon, text);
        alert.classList.remove('d-none');
        alert.scrollIntoView({ behavior: 'smooth', block: 'center' });

        if (focusTarget) {
            window.setTimeout(function () {
                focusTarget.focus({ preventScroll: true });
            }, 250);
        }
    }

    function hideMessage() {
        const alert = document.getElementById('fundingValidationMessage');
        if (alert) {
            alert.classList.add('d-none');
            alert.replaceChildren();
        }
    }

    function getFileError(fileInput) {
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            return 'Attach the supplier payment proof.';
        }

        const file = fileInput.files[0];
        const nameParts = file.name.toLowerCase().split('.');
        const extension = nameParts.length > 1 ? nameParts.pop() : '';

        if (!allowedExtensions.includes(extension)) {
            return 'Payment proof must be a PDF, JPG, or PNG file.';
        }

        if (file.size < 1 || file.size > maximumFileSize) {
            return 'Payment proof must not exceed 10 MB.';
        }

        return '';
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('supplierFundingForm');
        if (!form) {
            return;
        }

        const fileInput = form.querySelector('[data-funding-file]');
        const fileName = form.querySelector('[data-funding-file-name]');
        const confirmation = form.querySelector('[data-funding-confirm]');
        const confirmationLabel = confirmation
            ? confirmation.closest('.funding-confirmation')
            : null;
        const submitButton = form.querySelector('[data-funding-submit]');

        form.querySelectorAll('input, textarea').forEach(function (field) {
            const eventName = field.type === 'file' || field.type === 'checkbox'
                ? 'change'
                : 'input';

            field.addEventListener(eventName, function () {
                hideMessage();
                field.classList.remove('is-invalid');
            });
        });

        if (fileInput) {
            fileInput.addEventListener('change', function () {
                const error = getFileError(fileInput);
                const control = fileInput.previousElementSibling;
                control?.classList.toggle('is-invalid', error !== '');

                if (fileName) {
                    fileName.textContent = error || fileInput.files[0].name;
                }

                if (error) {
                    showMessage(error, fileInput);
                }
            });
        }

        if (confirmation) {
            confirmation.addEventListener('change', function () {
                confirmationLabel?.classList.toggle(
                    'is-invalid',
                    !confirmation.checked
                );
            });
        }

        form.addEventListener('submit', function (event) {
            hideMessage();

            const requiredFields = Array.from(
                form.querySelectorAll('[required]')
            );
            const emptyField = requiredFields.find(function (field) {
                if (field.type === 'checkbox') {
                    return !field.checked;
                }
                if (field.type === 'file') {
                    return !field.files || field.files.length === 0;
                }
                return String(field.value || '').trim() === '';
            });

            if (emptyField) {
                event.preventDefault();

                if (emptyField === confirmation) {
                    confirmationLabel?.classList.add('is-invalid');
                    showMessage(
                        'Confirm the supplier funding release before submitting.',
                        confirmation
                    );
                } else {
                    emptyField.classList.add('is-invalid');
                    showMessage(
                        'Complete every required funding field.',
                        emptyField
                    );
                }
                return;
            }

            const fileError = getFileError(fileInput);
            if (fileError) {
                event.preventDefault();
                fileInput?.previousElementSibling?.classList.add('is-invalid');
                showMessage(fileError, fileInput);
                return;
            }

            if (!form.checkValidity()) {
                event.preventDefault();
                const invalidField = form.querySelector(':invalid');
                invalidField?.classList.add('is-invalid');
                showMessage(
                    'Review the highlighted field and enter a valid value.',
                    invalidField
                );
                return;
            }

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.classList.add('is-submitting');

                const label = submitButton.querySelector('span');
                if (label) {
                    label.textContent = 'Recording funding…';
                }
            }
        });
    });
}());
