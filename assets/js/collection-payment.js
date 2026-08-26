(function () {
    'use strict';

    var form = document.getElementById('collectionPaymentForm');
    if (!form) {
        return;
    }

    var balance = Number.parseFloat(form.dataset.balance || '0');
    var poCreated = form.dataset.poCreated || '';
    var delivered = form.dataset.delivered || '';
    var currentTime = form.dataset.now || '';
    var amountInput = document.getElementById('paymentAmount');
    var dateInput = document.getElementById('paymentDate');
    var dateHelp = document.getElementById('paymentDateHelp');
    var methodInput = document.getElementById('paymentMethod');
    var referenceInput = document.getElementById('referenceNumber');
    var proofInput = document.getElementById('paymentProof');
    var proofName = document.getElementById('paymentProofName');
    var remarksInput = document.getElementById('paymentRemarks');
    var remarksCount = document.getElementById('paymentRemarksCount');
    var confirmation = document.getElementById('paymentConfirmation');
    var confirmationLabel = confirmation ? confirmation.closest('.payment-confirmation') : null;
    var submitButton = document.getElementById('paymentSubmitButton');
    var messageBox = document.getElementById('paymentValidationMessage');
    var paymentPreview = document.getElementById('paymentPreview');
    var balanceAfterPreview = document.getElementById('balanceAfterPreview');
    var statusPreview = document.getElementById('paymentStatusPreview');
    var classificationInputs = Array.prototype.slice.call(
        form.querySelectorAll('input[name="payment_classification"]')
    );

    function money(value) {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(Number.isFinite(value) ? value : 0).replace('PHP', '₱');
    }

    function selectedClassification() {
        var selected = classificationInputs.find(function (input) {
            return input.checked;
        });
        return selected ? selected.value : '';
    }

    function clearErrors() {
        [amountInput, dateInput, methodInput, referenceInput].forEach(function (input) {
            if (input) {
                input.classList.remove('is-invalid');
            }
        });
        if (proofInput && proofInput.closest('.payment-file-picker')) {
            proofInput.closest('.payment-file-picker').classList.remove('is-invalid');
        }
        if (confirmationLabel) {
            confirmationLabel.classList.remove('is-invalid');
        }
        if (messageBox) {
            messageBox.classList.add('d-none');
            messageBox.innerHTML = '';
        }
    }

    function showError(message, input) {
        if (messageBox) {
            messageBox.innerHTML = '<i class="fas fa-exclamation-circle"></i><span>' + message + '</span>';
            messageBox.classList.remove('d-none');
            messageBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        if (input) {
            input.classList.add('is-invalid');
            input.focus();
        }
    }

    function updateTypeCards() {
        classificationInputs.forEach(function (input) {
            var card = input.closest('.payment-type-option');
            if (card) {
                card.classList.toggle('payment-type-option-active', input.checked);
            }
        });
    }

    function updateDateBoundary() {
        var classification = selectedClassification();
        if (!dateInput) {
            return;
        }

        dateInput.max = currentTime;
        if (classification === 'Advance / Down Payment') {
            dateInput.min = poCreated;
            if (delivered && delivered < currentTime) {
                dateInput.max = delivered;
            }
            if (!dateInput.value || dateInput.value > dateInput.max || dateInput.value < dateInput.min) {
                dateInput.value = dateInput.max || delivered || currentTime;
            }
            if (dateHelp) {
                dateHelp.textContent = 'Advance/down payment must be dated from PO creation up to client delivery.';
            }
        } else {
            dateInput.min = delivered;
            dateInput.max = currentTime;
            if (!dateInput.value || dateInput.value < delivered || dateInput.value > currentTime) {
                dateInput.value = currentTime;
            }
            if (dateHelp) {
                dateHelp.textContent = 'Partial or full payment must be received on or after client delivery.';
            }
        }
    }

    function updateAmountBoundary() {
        var classification = selectedClassification();
        if (!amountInput) {
            return;
        }

        amountInput.readOnly = false;
        amountInput.max = balance.toFixed(2);
        if (classification === 'Full Payment') {
            amountInput.value = balance.toFixed(2);
            amountInput.readOnly = true;
        } else if (classification === 'Partial Payment') {
            amountInput.max = Math.max(balance - 0.01, 0.01).toFixed(2);
            if (Number.parseFloat(amountInput.value || '0') >= balance) {
                amountInput.value = '';
            }
        } else if (Number.parseFloat(amountInput.value || '0') > balance) {
            amountInput.value = '';
        }
    }

    function updatePreview() {
        var amount = Number.parseFloat(amountInput ? amountInput.value : '0');
        amount = Number.isFinite(amount) && amount > 0 ? amount : 0;
        var after = Math.max(balance - amount, 0);

        if (paymentPreview) {
            paymentPreview.textContent = money(amount);
        }
        if (balanceAfterPreview) {
            balanceAfterPreview.textContent = money(after);
        }
        if (!statusPreview) {
            return;
        }

        statusPreview.classList.remove('is-partial', 'is-collected');
        if (amount <= 0) {
            statusPreview.innerHTML = '<i class="fas fa-circle-info"></i><span>Enter a payment amount to preview the PO status.</span>';
        } else if (amount >= balance) {
            statusPreview.classList.add('is-collected');
            statusPreview.innerHTML = '<i class="fas fa-circle-check"></i><span>Balance becomes zero. The PO will move to <strong>Collected</strong>.</span>';
        } else {
            statusPreview.classList.add('is-partial');
            statusPreview.innerHTML = '<i class="fas fa-chart-pie"></i><span>The PO remains <strong>Partially-Collected</strong> with ' + money(after) + ' outstanding.</span>';
        }
    }

    function syncClassification() {
        clearErrors();
        updateTypeCards();
        updateAmountBoundary();
        updateDateBoundary();
        updatePreview();
    }

    classificationInputs.forEach(function (input) {
        input.addEventListener('change', syncClassification);
    });

    if (amountInput) {
        amountInput.addEventListener('input', function () {
            amountInput.classList.remove('is-invalid');
            updatePreview();
        });
    }

    if (proofInput) {
        proofInput.addEventListener('change', function () {
            var file = proofInput.files && proofInput.files[0];
            proofName.textContent = file ? file.name : 'No file selected';
            proofInput.closest('.payment-file-picker').classList.remove('is-invalid');
        });
    }

    if (remarksInput) {
        remarksInput.addEventListener('input', function () {
            remarksCount.textContent = remarksInput.value.length + ' / 1000';
        });
    }

    if (confirmation) {
        confirmation.addEventListener('change', function () {
            if (confirmationLabel) {
                confirmationLabel.classList.remove('is-invalid');
            }
        });
    }

    form.addEventListener('submit', function (event) {
        clearErrors();

        var classification = selectedClassification();
        var amount = Number.parseFloat(amountInput.value || '0');
        var file = proofInput.files && proofInput.files[0];
        var allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        var allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

        if (!classification) {
            event.preventDefault();
            showError('Select the correct payment classification.');
            return;
        }
        if (!dateInput.value || dateInput.value > currentTime) {
            event.preventDefault();
            showError('Enter a valid payment date that is not in the future.', dateInput);
            return;
        }
        if (classification === 'Advance / Down Payment') {
            if (dateInput.value < poCreated || dateInput.value > delivered) {
                event.preventDefault();
                showError('Advance/down payment must be dated between PO creation and client delivery.', dateInput);
                return;
            }
        } else if (dateInput.value < delivered) {
            event.preventDefault();
            showError('Use Advance / Down Payment for a payment received before delivery.', dateInput);
            return;
        }
        if (!methodInput.value) {
            event.preventDefault();
            showError('Select the verified payment method.', methodInput);
            return;
        }
        if (!Number.isFinite(amount) || amount <= 0 || amount > balance) {
            event.preventDefault();
            showError('Enter a payment amount within the outstanding balance.', amountInput);
            return;
        }
        if (classification === 'Full Payment' && Math.abs(amount - balance) >= 0.005) {
            event.preventDefault();
            showError('Full payment must equal the exact outstanding balance.', amountInput);
            return;
        }
        if (classification === 'Partial Payment' && amount >= balance) {
            event.preventDefault();
            showError('Partial payment must be lower than the outstanding balance.', amountInput);
            return;
        }
        if (!referenceInput.value.trim()) {
            event.preventDefault();
            showError('Enter the verified transaction or receipt reference.', referenceInput);
            return;
        }
        if (!file) {
            event.preventDefault();
            proofInput.closest('.payment-file-picker').classList.add('is-invalid');
            showError('Attach the payment proof before submitting.');
            return;
        }
        var fileExtension = file.name.split('.').pop().toLowerCase();
        var hasAcceptedType = file.type === '' || allowedTypes.indexOf(file.type) !== -1;
        if (file.size < 1 || file.size > 10 * 1024 * 1024 || !hasAcceptedType || allowedExtensions.indexOf(fileExtension) === -1) {
            event.preventDefault();
            proofInput.closest('.payment-file-picker').classList.add('is-invalid');
            showError('Payment proof must be a valid PDF, JPG, or PNG file up to 10 MB.');
            return;
        }
        if (!confirmation.checked) {
            event.preventDefault();
            confirmationLabel.classList.add('is-invalid');
            showError('Confirm that Finance verified the payment details and proof.');
            return;
        }

        submitButton.disabled = true;
        submitButton.innerHTML = '<span>Recording payment...</span><i class="fas fa-circle-notch fa-spin"></i>';
    });

    syncClassification();
    updatePreview();
}());
