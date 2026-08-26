(function () {
    'use strict';

    const form = document.getElementById('collectionFollowupForm');
    if (!form) {
        return;
    }

    const contactDateTime = document.getElementById('contactAttemptedAt');
    const outcome = document.getElementById('followupOutcome');
    const promisePanel = document.querySelector('[data-promise-panel]');
    const commitmentAmount = document.getElementById('commitmentAmount');
    const promisedPaymentDate = document.getElementById('promisedPaymentDate');
    const nextFollowupDate = document.getElementById('nextFollowupDate');
    const notes = document.getElementById('followupNotes');
    const noteCount = document.querySelector('[data-followup-note-count]');
    const nextPreview = document.querySelector('[data-next-followup-preview]');
    const confirmation = document.getElementById('followupConfirmation');
    const validationMessage = document.getElementById('followupValidationMessage');
    const submitButton = document.querySelector('[data-followup-submit]');

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

    function localDateValue(date) {
        return [
            date.getFullYear(),
            '-',
            pad(date.getMonth() + 1),
            '-',
            pad(date.getDate())
        ].join('');
    }

    function contactDateValue() {
        return contactDateTime.value ? contactDateTime.value.slice(0, 10) : '';
    }

    function parseLocalDate(value) {
        if (!value) {
            return null;
        }

        const parts = value.split('-').map(Number);
        if (parts.length !== 3 || parts.some(Number.isNaN)) {
            return null;
        }

        const parsed = new Date(parts[0], parts[1] - 1, parts[2]);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function formatDate(value) {
        const parsed = parseLocalDate(value);
        if (!parsed) {
            return 'Select a date';
        }

        return parsed.toLocaleDateString('en-US', {
            month: 'short',
            day: '2-digit',
            year: 'numeric'
        });
    }

    function clearValidation() {
        validationMessage.classList.add('d-none');
        validationMessage.innerHTML = '';
        form.querySelectorAll('.is-invalid').forEach(function (field) {
            field.classList.remove('is-invalid');
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

    function syncDateLimits() {
        contactDateTime.max = localDateTimeMaximum();
        const contactDate = contactDateValue();
        const today = new Date();
        const todayValue = localDateValue(today);
        const maximumFollowup = new Date(today);
        maximumFollowup.setFullYear(maximumFollowup.getFullYear() + 1);

        nextFollowupDate.min = contactDate && contactDate > todayValue
            ? contactDate
            : todayValue;
        nextFollowupDate.max = localDateValue(maximumFollowup);

        if (contactDate) {
            promisedPaymentDate.min = contactDate;
        }

        nextPreview.textContent = formatDate(nextFollowupDate.value);
    }

    function syncPromisePanel() {
        const requiresPromise = outcome.value === 'Promise to Pay';

        promisePanel.hidden = !requiresPromise;
        commitmentAmount.disabled = !requiresPromise;
        commitmentAmount.required = requiresPromise;
        promisedPaymentDate.disabled = !requiresPromise;
        promisedPaymentDate.required = requiresPromise;

        if (!requiresPromise) {
            commitmentAmount.value = '';
            promisedPaymentDate.value = '';
            commitmentAmount.classList.remove('is-invalid');
            promisedPaymentDate.classList.remove('is-invalid');
        }
    }

    function updateNoteCount() {
        noteCount.textContent = String(notes.value.length);
    }

    outcome.addEventListener('change', syncPromisePanel);
    contactDateTime.addEventListener('change', syncDateLimits);
    contactDateTime.addEventListener('input', syncDateLimits);
    nextFollowupDate.addEventListener('change', syncDateLimits);
    notes.addEventListener('input', updateNoteCount);

    form.addEventListener('input', function (event) {
        event.target.classList.remove('is-invalid');
    });

    form.addEventListener('submit', function (event) {
        clearValidation();
        syncDateLimits();

        const attemptedAt = contactDateTime.value
            ? new Date(contactDateTime.value)
            : null;
        if (!attemptedAt || Number.isNaN(attemptedAt.getTime())) {
            event.preventDefault();
            showValidation('Enter the client contact date and time.', contactDateTime);
            return;
        }

        if (attemptedAt.getTime() > Date.now()) {
            event.preventDefault();
            showValidation('Client contact time cannot be in the future.', contactDateTime);
            return;
        }

        const requiredField = Array.from(
            form.querySelectorAll('input[required]:not([type="checkbox"]), select[required], textarea[required]')
        ).find(function (field) {
            return !field.disabled && !String(field.value).trim();
        });

        if (requiredField) {
            event.preventDefault();
            showValidation('Complete all required collection follow-up fields.', requiredField);
            return;
        }

        const contactDate = parseLocalDate(contactDateValue());
        const nextDate = parseLocalDate(nextFollowupDate.value);
        const today = parseLocalDate(localDateValue(new Date()));
        const maximumFollowupValue = new Date();
        maximumFollowupValue.setFullYear(
            maximumFollowupValue.getFullYear() + 1
        );
        const maximumFollowup = parseLocalDate(
            localDateValue(maximumFollowupValue)
        );
        if (
            !contactDate ||
            !nextDate ||
            !today ||
            nextDate < contactDate ||
            nextDate < today
        ) {
            event.preventDefault();
            showValidation('Next follow-up must be today or later.', nextFollowupDate);
            return;
        }

        if (maximumFollowup && nextDate > maximumFollowup) {
            event.preventDefault();
            showValidation('Next follow-up must be within one year.', nextFollowupDate);
            return;
        }

        if (outcome.value === 'Promise to Pay') {
            const amount = Number(commitmentAmount.value);
            const balance = Number(commitmentAmount.dataset.outstandingBalance);
            if (!Number.isFinite(amount) || amount <= 0 || amount > balance) {
                event.preventDefault();
                showValidation(
                    'Promised amount must be greater than zero and cannot exceed the outstanding balance.',
                    commitmentAmount
                );
                return;
            }

            const promiseDate = parseLocalDate(promisedPaymentDate.value);
            if (!promiseDate || promiseDate < contactDate) {
                event.preventDefault();
                showValidation(
                    'Promised payment date cannot be earlier than the contact date.',
                    promisedPaymentDate
                );
                return;
            }
        }

        if (notes.value.trim().length < 10) {
            event.preventDefault();
            showValidation('Follow-up notes must contain at least 10 characters.', notes);
            return;
        }

        if (!confirmation.checked) {
            event.preventDefault();
            showValidation('Confirm that the client contact details are accurate.', confirmation);
            return;
        }

        submitButton.disabled = true;
        submitButton.innerHTML = '<span>Saving follow-up...</span><i class="fas fa-spinner fa-spin"></i>';
    });

    syncPromisePanel();
    syncDateLimits();
    updateNoteCount();
}());
