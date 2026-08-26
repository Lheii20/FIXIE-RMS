(function () {
    'use strict';

    const form = document.getElementById('deliveryRequestForm');
    if (!form) {
        return;
    }

    const requestType = document.getElementById('deliveryRequestType');
    const supplierReadyAt = document.getElementById('supplierReadyAt');
    const pickupAt = document.getElementById('preferredPickupAt');
    const deliveryAt = document.getElementById('preferredDeliveryAt');
    const packageCount = document.getElementById('packageCount');
    const confirmation = form.querySelector('[data-delivery-confirm]');
    const submitButton = form.querySelector('[data-delivery-submit]');
    const isResubmission = submitButton.textContent.includes('Resubmit');
    const validationMessage = document.getElementById(
        'deliveryRequestValidationMessage'
    );
    const pickupGroups = Array.from(
        form.querySelectorAll('[data-pickup-field]')
    );
    const deliveryGroups = Array.from(
        form.querySelectorAll('[data-delivery-field]')
    );

    function groupControls(groups) {
        return groups.flatMap(function (group) {
            return Array.from(group.querySelectorAll('input, textarea, select'));
        });
    }

    const pickupControls = groupControls(pickupGroups);
    const deliveryControls = groupControls(deliveryGroups);

    function setGroupState(groups, controls, visible) {
        groups.forEach(function (group) {
            group.classList.toggle('is-hidden', !visible);
        });

        controls.forEach(function (control) {
            control.disabled = !visible;
            control.required = visible;
            if (!visible) {
                control.classList.remove('is-invalid');
            }
        });
    }

    function syncRequestType() {
        const type = requestType.value;
        setGroupState(
            pickupGroups,
            pickupControls,
            type === 'Pick-up and Delivery' || type === 'Client Pick-up'
        );
        setGroupState(
            deliveryGroups,
            deliveryControls,
            type === 'Pick-up and Delivery' || type === 'Delivery Only'
        );
    }

    function parseLocalDate(value) {
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

    function validateForm() {
        clearInvalid();
        const messages = [];
        const now = new Date();
        const fiveMinutesAgo = new Date(now.getTime() - 5 * 60 * 1000);
        const readyDate = parseLocalDate(supplierReadyAt.value);
        const pickupDate = pickupAt.disabled
            ? null
            : parseLocalDate(pickupAt.value);
        const deliveryDate = deliveryAt.disabled
            ? null
            : parseLocalDate(deliveryAt.value);

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
            messages.push('Complete all required fields using valid values.');
        }

        if (!readyDate || readyDate > now) {
            markInvalid(supplierReadyAt);
            messages.push('Supplier readiness must contain a valid non-future date and time.');
        }

        if (pickupAt.required && (!pickupDate || pickupDate < fiveMinutesAgo)) {
            markInvalid(pickupAt);
            messages.push('Preferred pick-up must not be in the past.');
        }

        if (
            deliveryAt.required &&
            (!deliveryDate || deliveryDate < fiveMinutesAgo)
        ) {
            markInvalid(deliveryAt);
            messages.push('Preferred delivery must not be in the past.');
        }

        if (pickupDate && deliveryDate && deliveryDate < pickupDate) {
            markInvalid(pickupAt);
            markInvalid(deliveryAt);
            messages.push('Preferred delivery cannot be earlier than preferred pick-up.');
        }

        const packageValue = Number(packageCount.value);
        if (
            !Number.isInteger(packageValue) ||
            packageValue < 1 ||
            packageValue > 100000
        ) {
            markInvalid(packageCount);
            messages.push('Package count must be a whole number from 1 to 100,000.');
        }

        if (!confirmation.checked) {
            messages.push('Confirm supplier readiness and request accuracy before submitting.');
        }

        return Array.from(new Set(messages));
    }

    requestType.addEventListener('change', function () {
        syncRequestType();
        showMessage([]);
    });

    form.addEventListener('input', function (event) {
        if (event.target.matches('input, textarea, select')) {
            event.target.classList.remove('is-invalid');
        }
    });

    form.addEventListener('submit', function (event) {
        const messages = validateForm();
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
            '<span>' +
            (isResubmission ? 'Resubmitting request…' : 'Submitting request…') +
            '</span><i class="fas fa-spinner fa-spin"></i>';
    });

    syncRequestType();
}());

