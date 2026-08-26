(function () {
    'use strict';

    const form = document.getElementById('logisticsReviewForm');
    if (!form) {
        return;
    }

    const request = window.fixieLogisticsRequest || {};
    const actionInput = document.getElementById('logisticsAction');
    const providerType = document.getElementById('providerType');
    const providerName = document.getElementById('providerName');
    const plannedPickupAt = document.getElementById('plannedPickupAt');
    const plannedDeliveryAt = document.getElementById('plannedDeliveryAt');
    const pickupGroup = form.querySelector('[data-logistics-pickup]');
    const deliveryGroup = form.querySelector('[data-logistics-delivery]');
    const confirmation = form.querySelector('[data-logistics-confirm]');
    const submitButton = form.querySelector('[data-logistics-submit]');
    const validationMessage = document.getElementById(
        'logisticsValidationMessage'
    );
    const returnOverlay = form.querySelector('[data-return-overlay]');
    const returnOpen = form.querySelector('[data-return-open]');
    const returnCloseButtons = form.querySelectorAll('[data-return-close]');
    const returnConfirm = form.querySelector('[data-return-confirm]');
    const returnReason = document.getElementById('returnReason');
    const returnCount = form.querySelector('[data-return-count]');

    function setScheduleState(group, control, visible) {
        group.classList.toggle('is-hidden', !visible);
        control.disabled = !visible;
        control.required = visible;
        if (!visible) {
            control.classList.remove('is-invalid');
        }
    }

    function syncRequestSchedule() {
        const type = request.requestType || 'Pick-up and Delivery';
        setScheduleState(
            pickupGroup,
            plannedPickupAt,
            type === 'Pick-up and Delivery' || type === 'Client Pick-up'
        );
        setScheduleState(
            deliveryGroup,
            plannedDeliveryAt,
            type === 'Pick-up and Delivery' || type === 'Delivery Only'
        );
    }

    function syncProviderName() {
        const defaults = {
            'Company Fleet': 'Fixie Computer Ventures',
            'Supplier Delivery': request.supplierName || '',
            'Client Pick-up': request.clientName || '',
            'Third-Party Logistics': ''
        };
        providerName.value = defaults[providerType.value] || '';
        providerName.placeholder = providerType.value === 'Third-Party Logistics'
            ? 'Enter approved courier or logistics company'
            : 'Company, supplier, or responsible client';
        providerName.required = true;
        providerName.classList.remove('is-invalid');
    }

    function parseDate(value) {
        if (!value) {
            return null;
        }
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function markInvalid(control) {
        if (control) {
            control.classList.add('is-invalid');
        }
    }

    function clearInvalid() {
        form.querySelectorAll('.is-invalid').forEach(function (control) {
            control.classList.remove('is-invalid');
        });
    }

    function showMessage(messages) {
        if (!validationMessage) {
            return;
        }

        if (!messages.length) {
            validationMessage.classList.add('d-none');
            validationMessage.textContent = '';
            return;
        }

        validationMessage.classList.remove('d-none');
        validationMessage.innerHTML =
            '<i class="fas fa-exclamation-circle"></i><span>' +
            messages.join(' ') +
            '</span>';
        validationMessage.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function validateApproval() {
        clearInvalid();
        const messages = [];
        const nowFloor = new Date(Date.now() - 5 * 60 * 1000);
        const pickupDate = plannedPickupAt.disabled
            ? null
            : parseDate(plannedPickupAt.value);
        const deliveryDate = plannedDeliveryAt.disabled
            ? null
            : parseDate(plannedDeliveryAt.value);

        Array.from(form.querySelectorAll('[required]:not(:disabled)')).forEach(
            function (control) {
                const empty = control.type === 'checkbox'
                    ? !control.checked
                    : !String(control.value || '').trim();
                if (empty) {
                    markInvalid(control);
                }
            }
        );

        if (!form.checkValidity()) {
            messages.push('Complete all required logistics fields.');
        }

        if (!String(providerName.value || '').trim()) {
            markInvalid(providerName);
            messages.push('Enter the provider or responsible party.');
        }

        if (
            plannedPickupAt.required &&
            (!pickupDate || pickupDate < nowFloor)
        ) {
            markInvalid(plannedPickupAt);
            messages.push('Final pick-up schedule must not be in the past.');
        }

        if (
            plannedDeliveryAt.required &&
            (!deliveryDate || deliveryDate < nowFloor)
        ) {
            markInvalid(plannedDeliveryAt);
            messages.push('Final delivery schedule must not be in the past.');
        }

        if (pickupDate && deliveryDate && deliveryDate < pickupDate) {
            markInvalid(plannedPickupAt);
            markInvalid(plannedDeliveryAt);
            messages.push('Final delivery cannot be earlier than final pick-up.');
        }

        if (!confirmation.checked) {
            messages.push('Confirm that the logistics plan is workable before approval.');
        }

        return Array.from(new Set(messages));
    }

    function openReturnDialog() {
        returnOverlay.hidden = false;
        document.body.classList.add('logistics-dialog-open');
        returnReason.classList.remove('is-invalid');
        window.setTimeout(function () {
            returnReason.focus();
        }, 50);
    }

    function closeReturnDialog() {
        returnOverlay.hidden = true;
        document.body.classList.remove('logistics-dialog-open');
        actionInput.value = 'approve_delivery_schedule';
    }

    providerType.addEventListener('change', syncProviderName);

    form.addEventListener('input', function (event) {
        if (event.target.matches('input, textarea, select')) {
            event.target.classList.remove('is-invalid');
        }
    });

    form.addEventListener('submit', function (event) {
        actionInput.value = 'approve_delivery_schedule';
        const messages = validateApproval();
        if (messages.length) {
            event.preventDefault();
            showMessage(messages);
            const firstInvalid = form.querySelector('.is-invalid:not(:disabled)');
            if (firstInvalid) {
                firstInvalid.focus({ preventScroll: true });
            }
            return;
        }

        showMessage([]);
        submitButton.disabled = true;
        submitButton.innerHTML =
            '<span>Saving schedule…</span><i class="fas fa-spinner fa-spin"></i>';
    });

    returnOpen.addEventListener('click', openReturnDialog);
    returnCloseButtons.forEach(function (button) {
        button.addEventListener('click', closeReturnDialog);
    });

    returnOverlay.addEventListener('click', function (event) {
        if (event.target === returnOverlay) {
            closeReturnDialog();
        }
    });

    returnReason.addEventListener('input', function () {
        returnCount.textContent = String(returnReason.value.length);
        returnReason.classList.remove('is-invalid');
    });

    returnConfirm.addEventListener('click', function () {
        const reason = returnReason.value.trim();
        if (reason.length < 10 || reason.length > 500) {
            returnReason.classList.add('is-invalid');
            returnReason.focus();
            returnCount.textContent = String(returnReason.value.length);
            return;
        }

        actionInput.value = 'return_delivery_request';
        returnConfirm.disabled = true;
        returnConfirm.innerHTML =
            'Returning… <i class="fas fa-spinner fa-spin"></i>';
        HTMLFormElement.prototype.submit.call(form);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !returnOverlay.hidden) {
            closeReturnDialog();
        }
    });

    syncRequestSchedule();
}());
