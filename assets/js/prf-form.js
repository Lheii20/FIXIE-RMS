(function () {
    'use strict';

    const MAX_FILE_SIZE = 10 * 1024 * 1024;
    const ALLOWED_FILE_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];
    const ALLOWED_FILE_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    const currencyFormatter = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    function toNumber(value) {
        const parsed = Number.parseFloat(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function formatCurrency(value) {
        return currencyFormatter.format(toNumber(value)).replace('PHP', '₱').trim();
    }

    function setText(selector, value) {
        const element = document.querySelector(selector);
        if (element) {
            element.textContent = value;
        }
    }

    function clearInvalidState(field) {
        if (field) {
            field.classList.remove('is-invalid');
            field.removeAttribute('aria-invalid');
        }
    }

    function markInvalid(field) {
        if (field) {
            field.classList.add('is-invalid');
            field.setAttribute('aria-invalid', 'true');
        }
    }

    function showValidationMessage(message, field) {
        const messageBox = document.getElementById('prValidationMessage');

        if (messageBox) {
            messageBox.textContent = message;
            messageBox.classList.remove('d-none');
            messageBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        if (field) {
            markInvalid(field);
            field.focus({ preventScroll: true });
        }
    }

    function hideValidationMessage() {
        const messageBox = document.getElementById('prValidationMessage');
        if (messageBox) {
            messageBox.textContent = '';
            messageBox.classList.add('d-none');
        }
    }

    function getFileExtension(fileName) {
        const parts = String(fileName || '').toLowerCase().split('.');
        return parts.length > 1 ? parts.pop() : '';
    }

    function validateSupplierQuoteFile(fileInput) {
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
            return '';
        }

        const file = fileInput.files[0];
        const extension = getFileExtension(file.name);
        const hasAllowedExtension = ALLOWED_FILE_EXTENSIONS.includes(extension);
        const hasAllowedType = file.type === '' || ALLOWED_FILE_TYPES.includes(file.type);

        if (!hasAllowedExtension || !hasAllowedType) {
            return 'Supplier quotation must be a PDF, JPG, JPEG, or PNG file.';
        }

        if (file.size > MAX_FILE_SIZE) {
            return 'Supplier quotation must not exceed 10 MB.';
        }

        return '';
    }

    function initializeStructuredPrf(form) {
        const costRows = Array.from(form.querySelectorAll('[data-prf-cost-row]'));
        const costInputs = Array.from(form.querySelectorAll('[data-prf-unit-cost]'));
        const otherExpenseInput = document.getElementById('otherExpense');
        const paymentMethod = document.getElementById('paymentMethod');
        const paymentPanels = Array.from(form.querySelectorAll('[data-payment-panel]'));
        const fileInput = form.querySelector('[data-prf-file]');
        const fileName = form.querySelector('[data-prf-file-name]');
        const submitButton = form.querySelector('[data-prf-submit]');

        function calculateSummary() {
            let sellingTotal = 0;
            let costOfGoods = 0;

            costRows.forEach(function (row) {
                const quantity = Math.max(0, toNumber(row.dataset.quantity));
                const sellingLineTotal = Math.max(0, toNumber(row.dataset.sellingTotal));
                const unitCostInput = row.querySelector('[data-prf-unit-cost]');
                const unitCost = Math.max(0, toNumber(unitCostInput ? unitCostInput.value : 0));
                const costTotal = quantity * unitCost;
                const lineProfit = sellingLineTotal - costTotal;
                const costTotalCell = row.querySelector('[data-prf-cost-total]');
                const lineProfitCell = row.querySelector('[data-prf-line-profit]');

                sellingTotal += sellingLineTotal;
                costOfGoods += costTotal;

                if (costTotalCell) {
                    costTotalCell.textContent = formatCurrency(costTotal);
                }

                if (lineProfitCell) {
                    lineProfitCell.textContent = formatCurrency(lineProfit);
                    lineProfitCell.classList.toggle('is-negative', lineProfit < 0);
                }
            });

            const otherExpense = Math.max(0, toNumber(otherExpenseInput ? otherExpenseInput.value : 0));
            const requestedFund = costOfGoods + otherExpense;
            const grossProfit = sellingTotal - requestedFund;
            const grossMargin = sellingTotal > 0 ? (grossProfit / sellingTotal) * 100 : 0;
            const profitPanel = form.querySelector('[data-prf-profit-panel]');

            setText('[data-prf-selling-total]', formatCurrency(sellingTotal));
            setText('[data-prf-cogs]', formatCurrency(costOfGoods));
            setText('[data-prf-other-expense]', formatCurrency(otherExpense));
            setText('[data-prf-requested-fund]', formatCurrency(requestedFund));
            setText('[data-prf-gross-profit]', formatCurrency(grossProfit));
            setText('[data-prf-margin]', grossMargin.toFixed(2) + '% margin');

            if (profitPanel) {
                profitPanel.classList.toggle('is-negative', grossProfit < 0);
            }
        }

        function updatePaymentPanels() {
            const selectedMethod = paymentMethod ? paymentMethod.value : '';

            paymentPanels.forEach(function (panel) {
                const isActive = panel.dataset.paymentPanel === selectedMethod;
                panel.hidden = !isActive;

                panel.querySelectorAll('input').forEach(function (input) {
                    input.required = isActive;
                    if (!isActive) {
                        input.value = '';
                        clearInvalidState(input);
                    }
                });
            });
        }

        costInputs.forEach(function (input) {
            input.addEventListener('input', function () {
                clearInvalidState(input);
                hideValidationMessage();
                calculateSummary();
            });
        });

        if (otherExpenseInput) {
            otherExpenseInput.addEventListener('input', function () {
                clearInvalidState(otherExpenseInput);
                hideValidationMessage();
                calculateSummary();
            });
        }

        if (paymentMethod) {
            paymentMethod.addEventListener('change', function () {
                clearInvalidState(paymentMethod);
                hideValidationMessage();
                updatePaymentPanels();
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', function () {
                clearInvalidState(fileInput);
                hideValidationMessage();

                const fileError = validateSupplierQuoteFile(fileInput);
                if (fileError) {
                    markInvalid(fileInput);
                    if (fileName) {
                        fileName.textContent = fileError;
                        fileName.closest('.prf-file-control')?.classList.add('is-invalid');
                    }
                    return;
                }

                fileName?.closest('.prf-file-control')?.classList.remove('is-invalid');
                if (fileName) {
                    fileName.textContent = fileInput.files.length > 0
                        ? fileInput.files[0].name
                        : 'Select PDF or image';
                }
            });
        }

        form.querySelectorAll('input, select, textarea').forEach(function (field) {
            const eventName = field.tagName === 'SELECT' || field.type === 'file' ? 'change' : 'input';
            field.addEventListener(eventName, function () {
                clearInvalidState(field);
            });
        });

        form.addEventListener('submit', function (event) {
            hideValidationMessage();

            if (costRows.length === 0) {
                event.preventDefault();
                showValidationMessage('This PRF has no quoted items to process.');
                return;
            }

            const missingCost = costInputs.find(function (input) {
                return input.value.trim() === '' || toNumber(input.value) <= 0;
            });

            if (missingCost) {
                event.preventDefault();
                showValidationMessage('Enter a supplier unit cost greater than zero for every item.', missingCost);
                return;
            }

            const requiredFields = Array.from(form.querySelectorAll('[required]')).filter(function (field) {
                return !field.disabled && !field.closest('[hidden]');
            });
            const firstMissingField = requiredFields.find(function (field) {
                return String(field.value || '').trim() === '';
            });

            if (firstMissingField) {
                event.preventDefault();
                showValidationMessage('Complete all required supplier and payment fields.', firstMissingField);
                return;
            }

            if (otherExpenseInput && toNumber(otherExpenseInput.value) < 0) {
                event.preventDefault();
                showValidationMessage('Other approved expense cannot be negative.', otherExpenseInput);
                return;
            }

            const invalidNativeField = Array.from(form.querySelectorAll('input, select, textarea')).find(function (field) {
                return !field.disabled && !field.closest('[hidden]') && !field.checkValidity();
            });

            if (invalidNativeField) {
                event.preventDefault();
                showValidationMessage('Review the highlighted field and enter a valid value.', invalidNativeField);
                return;
            }

            const fileError = validateSupplierQuoteFile(fileInput);
            if (fileError) {
                event.preventDefault();
                showValidationMessage(fileError, fileInput);
                fileName?.closest('.prf-file-control')?.classList.add('is-invalid');
                return;
            }

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.classList.add('is-submitting');
                const label = submitButton.querySelector('span');
                if (label) {
                    label.textContent = 'Submitting PRF…';
                }
            }
        });

        updatePaymentPanels();
        calculateSummary();
    }

    function initializeLegacyPr(form) {
        form.addEventListener('submit', function (event) {
            hideValidationMessage();

            const quotationId = document.getElementById('prQuotationId');
            const prNumber = document.getElementById('prNumber');
            const clientName = document.getElementById('prClientName');
            const clientPo = document.getElementById('prClientPo');
            const amount = document.getElementById('prAmount');
            const itemRows = form.querySelectorAll('#prItemsTable tbody tr');

            if (!quotationId || toNumber(quotationId.value) <= 0 ||
                !prNumber || prNumber.value.trim() === '' ||
                !clientName || clientName.value.trim() === '' ||
                !clientPo || clientPo.textContent.trim() === '' ||
                !amount || toNumber(amount.value) <= 0 ||
                itemRows.length === 0) {
                event.preventDefault();
                showValidationMessage(
                    'The legacy PR record is incomplete. Return to the quotation and verify its Client PO details.'
                );
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const structuredForm = document.getElementById('prfV2Form');
        const legacyForm = document.getElementById('legacyPrForm');

        if (structuredForm) {
            initializeStructuredPrf(structuredForm);
        }

        if (legacyForm) {
            initializeLegacyPr(legacyForm);
        }
    });
}());
