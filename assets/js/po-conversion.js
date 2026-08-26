(function () {
    'use strict';

    const maximumFileSize = 10 * 1024 * 1024;
    const allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

    function showValidationMessage(message, focusTarget) {
        const alert = document.getElementById('poValidationMessage');
        if (!alert) {
            return;
        }

        alert.innerHTML = '';

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

    function hideValidationMessage() {
        const alert = document.getElementById('poValidationMessage');
        if (alert) {
            alert.classList.add('d-none');
            alert.replaceChildren();
        }
    }

    function getFileError(fileInput) {
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            return '';
        }

        const file = fileInput.files[0];
        const nameParts = file.name.toLowerCase().split('.');
        const extension = nameParts.length > 1 ? nameParts.pop() : '';

        if (!allowedExtensions.includes(extension)) {
            return 'Supporting document must be a PDF, JPG, or PNG file.';
        }

        if (file.size < 1 || file.size > maximumFileSize) {
            return 'Supporting document must not exceed 10 MB.';
        }

        return '';
    }

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('approvedPrfPoForm');
        if (!form) {
            return;
        }

        const fileInput = form.querySelector('[data-po-file]');
        const fileName = form.querySelector('[data-po-file-name]');
        const confirmation = form.querySelector('[data-po-confirm]');
        const confirmationLabel = confirmation
            ? confirmation.closest('.po-confirmation')
            : null;
        const submitButton = form.querySelector('[data-po-submit]');

        if (fileInput) {
            fileInput.addEventListener('change', function () {
                hideValidationMessage();

                const error = getFileError(fileInput);
                const control = fileInput.previousElementSibling;
                control?.classList.toggle('is-invalid', error !== '');

                if (fileName) {
                    fileName.textContent = error || (
                        fileInput.files.length > 0
                            ? fileInput.files[0].name
                            : 'Select supporting file'
                    );
                }

                if (error) {
                    showValidationMessage(error, fileInput);
                }
            });
        }

        if (confirmation) {
            confirmation.addEventListener('change', function () {
                hideValidationMessage();
                confirmationLabel?.classList.toggle(
                    'is-invalid',
                    !confirmation.checked
                );
            });
        }

        form.addEventListener('submit', function (event) {
            hideValidationMessage();

            if (!confirmation || !confirmation.checked) {
                event.preventDefault();
                confirmationLabel?.classList.add('is-invalid');
                showValidationMessage(
                    'Confirm that you reviewed the locked PRF, supplier, and Client PO references.',
                    confirmation
                );
                return;
            }

            const fileError = getFileError(fileInput);
            if (fileError) {
                event.preventDefault();
                fileInput?.previousElementSibling?.classList.add('is-invalid');
                showValidationMessage(fileError, fileInput);
                return;
            }

            if (!form.checkValidity()) {
                event.preventDefault();
                const invalidField = form.querySelector(':invalid');
                showValidationMessage(
                    'Review the confirmation and supporting document before creating the PO.',
                    invalidField
                );
                return;
            }

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.classList.add('is-submitting');

                const label = submitButton.querySelector('span');
                if (label) {
                    label.textContent = 'Creating PO…';
                }
            }
        });
    });
}());
