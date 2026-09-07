(function () {
    'use strict';

    if (window.DRMSFeedback && window.DRMSFeedback.__initialized) {
        return;
    }

    const ICONS = {
        info: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v5"></path><path d="M12 8h.01"></path></svg>',
        success: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="m8.5 12 2.2 2.2 4.8-5"></path></svg>',
        warning: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.4 4.2 3.2 17a2 2 0 0 0 1.8 3h14a2 2 0 0 0 1.8-3L13.6 4.2a1.85 1.85 0 0 0-3.2 0Z"></path><path d="M12 9v4"></path><path d="M12 16.5h.01"></path></svg>',
        danger: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M9 9l6 6"></path><path d="m15 9-6 6"></path></svg>'
    };

    let overlay = null;
    let dialog = null;
    let eyebrowNode = null;
    let titleNode = null;
    let messageNode = null;
    let iconNode = null;
    let promptWrap = null;
    let promptLabel = null;
    let promptInput = null;
    let promptTextarea = null;
    let promptError = null;
    let cancelButton = null;
    let confirmButton = null;
    let toastRegion = null;
    let activeResolve = null;
    let activeKind = 'confirm';
    let activePromptOptions = null;
    let previousFocus = null;
    let closeTimer = null;
    let invalidTimer = null;
    let invalidFields = [];

    function normalizeTone(tone) {
        return ['info', 'success', 'warning', 'danger'].includes(tone) ? tone : 'info';
    }

    function ensureUi() {
        if (overlay) {
            return;
        }

        overlay = document.createElement('div');
        overlay.className = 'drms-feedback-overlay';
        overlay.hidden = true;
        overlay.innerHTML = [
            '<section class="drms-feedback-dialog" role="alertdialog" aria-modal="true" aria-labelledby="drmsFeedbackTitle" aria-describedby="drmsFeedbackMessage" data-tone="info">',
            '  <div class="drms-feedback-dialog__body">',
            '    <div class="drms-feedback-dialog__icon" aria-hidden="true"></div>',
            '    <div class="drms-feedback-dialog__copy">',
            '      <div class="drms-feedback-dialog__eyebrow"></div>',
            '      <h2 class="drms-feedback-dialog__title" id="drmsFeedbackTitle"></h2>',
            '      <p class="drms-feedback-dialog__message" id="drmsFeedbackMessage"></p>',
            '      <div class="drms-feedback-dialog__prompt" hidden>',
            '        <label class="drms-feedback-dialog__prompt-label" for="drmsFeedbackInput"></label>',
            '        <input class="drms-feedback-dialog__input" id="drmsFeedbackInput" type="text" aria-describedby="drmsFeedbackInputError">',
            '        <textarea class="drms-feedback-dialog__input drms-feedback-dialog__textarea" id="drmsFeedbackTextarea" rows="3" aria-describedby="drmsFeedbackInputError" hidden></textarea>',
            '        <div class="drms-feedback-dialog__input-error" id="drmsFeedbackInputError" role="alert" hidden></div>',
            '      </div>',
            '    </div>',
            '  </div>',
            '  <div class="drms-feedback-dialog__actions">',
            '    <button type="button" class="drms-feedback-button drms-feedback-button--cancel"></button>',
            '    <button type="button" class="drms-feedback-button drms-feedback-button--confirm"></button>',
            '  </div>',
            '</section>'
        ].join('');

        dialog = overlay.querySelector('.drms-feedback-dialog');
        eyebrowNode = overlay.querySelector('.drms-feedback-dialog__eyebrow');
        titleNode = overlay.querySelector('.drms-feedback-dialog__title');
        messageNode = overlay.querySelector('.drms-feedback-dialog__message');
        iconNode = overlay.querySelector('.drms-feedback-dialog__icon');
        promptWrap = overlay.querySelector('.drms-feedback-dialog__prompt');
        promptLabel = overlay.querySelector('.drms-feedback-dialog__prompt-label');
        promptInput = overlay.querySelector('#drmsFeedbackInput');
        promptTextarea = overlay.querySelector('#drmsFeedbackTextarea');
        promptError = overlay.querySelector('.drms-feedback-dialog__input-error');
        cancelButton = overlay.querySelector('.drms-feedback-button--cancel');
        confirmButton = overlay.querySelector('.drms-feedback-button--confirm');

        toastRegion = document.createElement('div');
        toastRegion.className = 'drms-feedback-toast-region';
        toastRegion.setAttribute('aria-live', 'polite');
        toastRegion.setAttribute('aria-atomic', 'false');

        document.body.append(overlay, toastRegion);

        cancelButton.addEventListener('click', function () {
            closeDialog(false);
        });

        confirmButton.addEventListener('click', function () {
            closeDialog(true);
        });

        promptInput.addEventListener('input', clearPromptError);
        promptTextarea.addEventListener('input', clearPromptError);
        promptInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                closeDialog(true);
            }
        });
        promptTextarea.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                closeDialog(true);
            }
        });

        overlay.addEventListener('mousedown', function (event) {
            if (event.target === overlay && overlay.dataset.outsideClose !== 'false') {
                closeDialog(false);
            }
        });

        document.addEventListener('keydown', handleDialogKeyboard, true);
    }

    function handleDialogKeyboard(event) {
        if (!overlay || overlay.hidden) {
            return;
        }

        if (event.key === 'Escape' && overlay.dataset.escapeClose !== 'false') {
            event.preventDefault();
            closeDialog(false);
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusable = [activePromptControl(), cancelButton, confirmButton].filter(function (button) {
            return !button.hidden && !button.disabled;
        });

        if (!focusable.length) {
            event.preventDefault();
            return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function activePromptControl() {
        return activePromptOptions && activePromptOptions.inputType === 'textarea'
            ? promptTextarea
            : promptInput;
    }

    function clearPromptError() {
        if (!promptError) {
            return;
        }
        promptError.textContent = '';
        promptError.hidden = true;
        promptInput.classList.remove('is-invalid');
        promptTextarea.classList.remove('is-invalid');
        promptInput.removeAttribute('aria-invalid');
        promptTextarea.removeAttribute('aria-invalid');
    }

    function showPromptError(message) {
        const control = activePromptControl();
        promptError.textContent = message;
        promptError.hidden = false;
        control.classList.add('is-invalid');
        control.setAttribute('aria-invalid', 'true');
        control.focus();
    }

    function promptValue() {
        const control = activePromptControl();
        const value = control.value;
        return activePromptOptions && activePromptOptions.trim === false ? value : value.trim();
    }

    function validatePromptValue(value) {
        const options = activePromptOptions || {};
        if (options.required !== false && !value) {
            return options.requiredMessage || 'Enter a value before continuing.';
        }
        if (options.minLength && value.length < Number(options.minLength)) {
            return options.minLengthMessage || 'Enter at least ' + options.minLength + ' characters.';
        }
        if (options.maxLength && value.length > Number(options.maxLength)) {
            return options.maxLengthMessage || 'Use no more than ' + options.maxLength + ' characters.';
        }
        if (typeof options.validate === 'function') {
            return options.validate(value) || '';
        }
        return '';
    }

    function openDialog(options, showCancel, kind) {
        ensureUi();
        window.clearTimeout(closeTimer);

        if (activeResolve) {
            const previousResolve = activeResolve;
            const previousKind = activeKind;
            activeResolve = null;
            previousResolve(previousKind === 'prompt' ? null : false);
        }

        activeKind = kind || 'confirm';
        activePromptOptions = activeKind === 'prompt' ? options : null;
        const tone = normalizeTone(options.tone || (showCancel ? 'warning' : 'info'));
        previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        dialog.dataset.tone = tone;
        dialog.dataset.kind = activeKind;
        iconNode.innerHTML = ICONS[tone];
        eyebrowNode.textContent = options.eyebrow || (
            activeKind === 'prompt'
                ? 'Information required'
                : (showCancel ? 'Confirmation' : (tone === 'danger' ? 'Action required' : 'System notice'))
        );
        titleNode.textContent = options.title || (showCancel ? 'Confirm action' : 'Notice');
        messageNode.textContent = options.message || '';
        messageNode.hidden = !options.message;
        promptWrap.hidden = activeKind !== 'prompt';
        clearPromptError();
        if (activeKind === 'prompt') {
            const useTextarea = options.inputType === 'textarea';
            promptInput.hidden = useTextarea;
            promptTextarea.hidden = !useTextarea;
            promptLabel.textContent = options.inputLabel || 'Value';
            promptLabel.htmlFor = useTextarea ? 'drmsFeedbackTextarea' : 'drmsFeedbackInput';
            const control = activePromptControl();
            control.value = options.value == null ? '' : String(options.value);
            control.placeholder = options.placeholder || '';
            if (options.maxLength) {
                control.maxLength = Number(options.maxLength);
            } else {
                control.removeAttribute('maxlength');
            }
            control.autocomplete = options.autocomplete || 'off';
        }
        cancelButton.textContent = options.cancelText || 'Cancel';
        cancelButton.hidden = !showCancel;
        confirmButton.textContent = options.confirmText || 'Okay';
        overlay.dataset.outsideClose = options.allowOutsideClick === false ? 'false' : 'true';
        overlay.dataset.escapeClose = options.allowEscape === false ? 'false' : 'true';
        overlay.hidden = false;
        document.body.classList.add('drms-feedback-open');

        window.requestAnimationFrame(function () {
            overlay.classList.add('is-visible');
            window.setTimeout(function () {
                if (overlay.hidden || !activeResolve) {
                    return;
                }
                const focusTarget = activeKind === 'prompt'
                    ? activePromptControl()
                    : (showCancel && options.focusConfirm !== true ? cancelButton : confirmButton);
                focusTarget.focus();
                if (activeKind === 'prompt' && typeof focusTarget.select === 'function') {
                    focusTarget.select();
                }
            }, 40);
        });

        return new Promise(function (resolve) {
            activeResolve = resolve;
        });
    }

    function closeDialog(result) {
        if (!overlay || overlay.hidden || !activeResolve) {
            return;
        }

        let resolvedValue = Boolean(result);
        if (activeKind === 'prompt') {
            if (!result) {
                resolvedValue = null;
            } else {
                const value = promptValue();
                const validationError = validatePromptValue(value);
                if (validationError) {
                    showPromptError(validationError);
                    return;
                }
                resolvedValue = value;
            }
        }

        const resolve = activeResolve;
        const restoreTarget = previousFocus;
        activeResolve = null;
        activePromptOptions = null;
        overlay.classList.remove('is-visible');
        document.body.classList.remove('drms-feedback-open');

        closeTimer = window.setTimeout(function () {
            overlay.hidden = true;
            if (restoreTarget && document.contains(restoreTarget)) {
                restoreTarget.focus({ preventScroll: true });
            }
        }, 160);

        resolve(resolvedValue);
    }

    function confirmAction(options) {
        return openDialog(options || {}, true, 'confirm');
    }

    function alertAction(options) {
        if (typeof options === 'string') {
            options = { message: options };
        }
        return openDialog(options || {}, false, 'alert');
    }

    function promptAction(options) {
        return openDialog(options || {}, true, 'prompt');
    }

    function removeToast(toast) {
        if (!toast || !toast.isConnected || toast.classList.contains('is-leaving')) {
            return;
        }
        window.clearTimeout(toast._drmsToastTimer);
        toast.classList.add('is-leaving');
        window.setTimeout(function () {
            toast.remove();
        }, 220);
    }

    function toast(message, tone, duration) {
        ensureUi();
        const safeTone = normalizeTone(tone || 'info');
        const durationMs = Math.max(1800, Number(duration) || 3000);
        const item = document.createElement('div');
        item.className = 'drms-feedback-toast';
        item.dataset.tone = safeTone;
        item.setAttribute('role', safeTone === 'danger' ? 'alert' : 'status');
        item.style.setProperty('--drms-toast-duration', durationMs + 'ms');

        const icon = document.createElement('span');
        icon.className = 'drms-feedback-toast__icon';
        icon.innerHTML = ICONS[safeTone];

        const copy = document.createElement('div');
        copy.className = 'drms-feedback-toast__copy';
        copy.textContent = message || 'The action has been completed.';

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'drms-feedback-toast__close';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.textContent = '×';
        close.addEventListener('click', function () {
            removeToast(item);
        });

        const progress = document.createElement('span');
        progress.className = 'drms-feedback-toast__progress';
        progress.setAttribute('aria-hidden', 'true');

        item.append(icon, copy, close, progress);
        toastRegion.append(item);
        while (toastRegion.children.length > 3) {
            const oldest = toastRegion.firstElementChild;
            window.clearTimeout(oldest._drmsToastTimer);
            oldest.remove();
        }

        window.requestAnimationFrame(function () {
            item.classList.add('is-visible');
        });
        item._drmsToastTimer = window.setTimeout(function () {
            removeToast(item);
        }, durationMs);
        return item;
    }

    function fieldLabel(field) {
        if (field.labels && field.labels.length) {
            const label = field.labels[0].textContent.replace(/\s+/g, ' ').trim().replace(/[\s:*]+$/, '');
            if (label) {
                return label;
            }
        }

        const fallback = field.getAttribute('aria-label') || field.getAttribute('placeholder') || field.name || 'This field';
        return String(fallback).replace(/[_-]+/g, ' ').trim();
    }

    function validationMessage(field) {
        const name = fieldLabel(field);
        const validity = field.validity;

        if (validity.valueMissing) {
            return name + ' is required.';
        }
        if (validity.typeMismatch && field.type === 'email') {
            return 'Enter a valid email address.';
        }
        if (validity.typeMismatch) {
            return 'Enter a valid value for ' + name + '.';
        }
        if (validity.tooShort) {
            return name + ' must contain at least ' + field.minLength + ' characters.';
        }
        if (validity.tooLong) {
            return name + ' must not exceed ' + field.maxLength + ' characters.';
        }
        if (validity.rangeUnderflow) {
            return name + ' must be at least ' + field.min + '.';
        }
        if (validity.rangeOverflow) {
            return name + ' must not exceed ' + field.max + '.';
        }
        if (validity.patternMismatch) {
            return field.title || 'Check the required format for ' + name + '.';
        }
        if (validity.badInput || validity.stepMismatch) {
            return 'Enter a valid value for ' + name + '.';
        }
        return field.validationMessage || 'Please review ' + name + '.';
    }

    function errorInsertionTarget(field) {
        return field.closest('.input-group, .password-wrapper, .password-input-wrapper, .input-group-modern') || field;
    }

    function showFieldError(field, message) {
        if (!(field instanceof HTMLElement)) {
            return;
        }

        clearFieldError(field);
        const error = document.createElement('div');
        const errorId = (field.id || 'drmsField') + 'Error' + Math.random().toString(36).slice(2, 7);
        error.id = errorId;
        error.className = 'drms-field-error';
        error.dataset.drmsGeneratedError = '1';
        error.textContent = message;

        field.classList.add('is-invalid');
        field.dataset.drmsNativeInvalid = '1';
        field.setAttribute('aria-invalid', 'true');
        const describedBy = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        describedBy.push(errorId);
        field.setAttribute('aria-describedby', Array.from(new Set(describedBy)).join(' '));
        errorInsertionTarget(field).insertAdjacentElement('afterend', error);
    }

    function clearFieldError(field) {
        if (!(field instanceof HTMLElement)) {
            return;
        }

        const target = errorInsertionTarget(field);
        const possibleErrors = [];
        if (target.nextElementSibling && target.nextElementSibling.matches('[data-drms-generated-error="1"]')) {
            possibleErrors.push(target.nextElementSibling);
        }
        if (field.nextElementSibling && field.nextElementSibling.matches('[data-drms-generated-error="1"]')) {
            possibleErrors.push(field.nextElementSibling);
        }

        possibleErrors.forEach(function (error) {
            const errorId = error.id;
            error.remove();
            if (errorId) {
                const describedBy = (field.getAttribute('aria-describedby') || '')
                    .split(/\s+/)
                    .filter(function (id) { return id && id !== errorId; });
                if (describedBy.length) {
                    field.setAttribute('aria-describedby', describedBy.join(' '));
                } else {
                    field.removeAttribute('aria-describedby');
                }
            }
        });

        if (field.dataset.drmsNativeInvalid === '1') {
            field.classList.remove('is-invalid');
            field.removeAttribute('aria-invalid');
            delete field.dataset.drmsNativeInvalid;
        }
    }

    function queueInvalidSummary(field) {
        invalidFields.push(field);
        window.clearTimeout(invalidTimer);
        invalidTimer = window.setTimeout(function () {
            const uniqueFields = Array.from(new Set(invalidFields)).filter(function (item) {
                return item && item.isConnected;
            });
            invalidFields = [];
            if (!uniqueFields.length) {
                return;
            }

            const first = uniqueFields[0];
            first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            window.setTimeout(function () {
                first.focus({ preventScroll: true });
            }, 180);
            toast(
                uniqueFields.length === 1
                    ? 'Please correct the highlighted field.'
                    : 'Please correct the ' + uniqueFields.length + ' highlighted fields.',
                'warning',
                3600
            );
        }, 0);
    }

    function plainText(value) {
        if (value == null) {
            return '';
        }
        const decoder = document.createElement('div');
        decoder.innerHTML = String(value);
        return (decoder.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function sweetAlertTone(options) {
        const combined = [
            plainText(options.title),
            plainText(options.text || options.html),
            plainText(options.confirmButtonText)
        ].join(' ').toLocaleLowerCase();

        if (
            options.icon === 'error' ||
            /\b(delete|destroy|reject|remove|revoke|suspend|wipe|discard|terminate|restore)\b/.test(combined)
        ) {
            return 'danger';
        }
        if (options.icon === 'success' || /\b(reactivate|restore access)\b/.test(combined)) {
            return 'success';
        }
        if (options.icon === 'question' || options.icon === 'info') {
            return 'info';
        }
        return 'warning';
    }

    function sweetAlertResult(confirmed, value) {
        return {
            isConfirmed: confirmed,
            isDenied: false,
            isDismissed: !confirmed,
            value: confirmed ? value : undefined,
            dismiss: confirmed ? undefined : 'cancel'
        };
    }

    function hasInteractiveHtml(options) {
        return typeof options.html === 'string' && /<(form|input|textarea|select|button)\b/i.test(options.html);
    }

    function adaptSweetAlertPrompt(swal, options) {
        let capturedValidationMessage = '';
        const preConfirm = typeof options.preConfirm === 'function' ? options.preConfirm : null;
        const preConfirmIsAsync = preConfirm && preConfirm.constructor && preConfirm.constructor.name === 'AsyncFunction';
        if (preConfirmIsAsync) {
            return null;
        }

        return promptAction({
            title: plainText(options.title) || 'Enter the required information',
            message: plainText(options.text || options.html),
            inputLabel: plainText(options.inputLabel) || (options.input === 'textarea' ? 'Reason or remarks' : 'Value'),
            inputType: options.input === 'textarea' ? 'textarea' : 'text',
            value: options.inputValue || '',
            placeholder: plainText(options.inputPlaceholder),
            required: Boolean(preConfirm || (options.inputAttributes && options.inputAttributes.required)),
            requiredMessage: 'This field is required before you can continue.',
            maxLength: options.inputAttributes && options.inputAttributes.maxlength
                ? Number(options.inputAttributes.maxlength)
                : null,
            confirmText: plainText(options.confirmButtonText) || 'Continue',
            cancelText: plainText(options.cancelButtonText) || 'Cancel',
            tone: sweetAlertTone(options),
            allowOutsideClick: options.allowOutsideClick !== false,
            allowEscape: options.allowEscapeKey !== false,
            validate: function (value) {
                if (!preConfirm) {
                    return '';
                }

                capturedValidationMessage = '';
                const originalShowValidation = swal.showValidationMessage;
                swal.showValidationMessage = function (message) {
                    capturedValidationMessage = plainText(message);
                };

                try {
                    const result = preConfirm(value);
                    if (result && typeof result.then === 'function') {
                        return 'This action requires its original secure validation dialog.';
                    }
                    if (result === false) {
                        return capturedValidationMessage || 'Review the entered value before continuing.';
                    }
                    return '';
                } catch (error) {
                    return plainText(error && error.message) || 'Review the entered value before continuing.';
                } finally {
                    swal.showValidationMessage = originalShowValidation;
                }
            }
        }).then(function (value) {
            return sweetAlertResult(value !== null, value);
        });
    }

    function sweetAlertToastTone(icon) {
        if (icon === 'success') return 'success';
        if (icon === 'warning') return 'warning';
        if (icon === 'error') return 'danger';
        return 'info';
    }

    function adaptSweetAlertToast(options) {
        const message = plainText(options.title || options.text || options.html) || 'The action has been completed.';
        const duration = 3000;
        toast(message, sweetAlertToastTone(options.icon), duration);
        return new Promise(function (resolve) {
            window.setTimeout(function () {
                resolve({
                    isConfirmed: false,
                    isDenied: false,
                    isDismissed: true,
                    value: undefined,
                    dismiss: 'timer'
                });
            }, duration);
        });
    }

    function normalizeSweetAlertOptions(args) {
        if (args.length === 1 && args[0] && typeof args[0] === 'object') {
            return args[0];
        }
        return {
            title: args[0] || '',
            text: args[1] || '',
            icon: args[2] || ''
        };
    }

    function installSweetAlertAdapter() {
        const swal = window.Swal;
        if (!swal || typeof swal.fire !== 'function' || swal.__drmsFeedbackAdapter) {
            return Boolean(swal && swal.__drmsFeedbackAdapter);
        }

        const originalFire = swal.fire.bind(swal);
        const originalMixin = typeof swal.mixin === 'function' ? swal.mixin.bind(swal) : null;
        swal.__drmsFeedbackOriginalFire = originalFire;
        swal.fire = function () {
            const args = Array.from(arguments);
            const options = normalizeSweetAlertOptions(args);
            if (options.toast === true) {
                return adaptSweetAlertToast(options);
            }
            if (!options || options.showCancelButton !== true || options.showConfirmButton === false) {
                return originalFire.apply(swal, args);
            }

            if (['text', 'textarea'].includes(options.input) && !hasInteractiveHtml(options)) {
                const promptResult = adaptSweetAlertPrompt(swal, options);
                if (promptResult) {
                    return promptResult;
                }
            }

            if (options.input || options.preConfirm || options.didOpen || hasInteractiveHtml(options)) {
                return originalFire.apply(swal, args);
            }

            const tone = sweetAlertTone(options);
            let title = plainText(options.title);
            let message = plainText(options.text || options.html);
            if (!title && message.length <= 110 && /[?]$/.test(message)) {
                title = message;
                message = '';
            }
            return confirmAction({
                title: title || 'Confirm this action?',
                message: message,
                confirmText: plainText(options.confirmButtonText) || 'Continue',
                cancelText: plainText(options.cancelButtonText) || 'Cancel',
                tone: tone,
                focusConfirm: options.focusConfirm === true && tone !== 'danger',
                allowOutsideClick: options.allowOutsideClick !== false,
                allowEscape: options.allowEscapeKey !== false
            }).then(function (approved) {
                return sweetAlertResult(approved, approved);
            });
        };

        if (originalMixin) {
            swal.__drmsFeedbackOriginalMixin = originalMixin;
            swal.mixin = function (defaults) {
                const mixed = originalMixin(defaults);
                if (!defaults || defaults.toast !== true || !mixed || typeof mixed.fire !== 'function') {
                    return mixed;
                }

                try {
                    mixed.fire = function () {
                        const options = Object.assign({}, defaults, normalizeSweetAlertOptions(Array.from(arguments)));
                        return adaptSweetAlertToast(options);
                    };
                } catch (error) {
                    return mixed;
                }
                return mixed;
            };
        }
        swal.__drmsFeedbackAdapter = true;
        return true;
    }

    function watchSweetAlertAssignment() {
        if (typeof window.Swal !== 'undefined') {
            return;
        }

        const descriptor = Object.getOwnPropertyDescriptor(window, 'Swal');
        if (descriptor && descriptor.configurable === false) {
            return;
        }

        let assignedSwal;
        try {
            Object.defineProperty(window, 'Swal', {
                configurable: true,
                enumerable: true,
                get: function () {
                    return assignedSwal;
                },
                set: function (value) {
                    assignedSwal = value;
                    installSweetAlertAdapter();
                }
            });
        } catch (error) {
            // The short polling fallback below still installs the adapter safely.
        }
    }

    watchSweetAlertAssignment();
    let sweetAlertAdapterAttempts = 0;
    const sweetAlertAdapterTimer = window.setInterval(function () {
        sweetAlertAdapterAttempts += 1;
        if (installSweetAlertAdapter() || sweetAlertAdapterAttempts >= 400) {
            window.clearInterval(sweetAlertAdapterTimer);
        }
    }, 50);
    installSweetAlertAdapter();

    function flashMessageText(element) {
        const clone = element.cloneNode(true);
        clone.querySelectorAll('button, .btn-close, [data-dismiss-prf-alert], i, svg').forEach(function (node) {
            node.remove();
        });
        return (clone.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function convertRenderedActionFeedback() {
        const selector = [
            '[data-drms-flash]',
            '.alert.alert-dismissible.alert-success',
            '.alert.alert-dismissible.alert-danger',
            '.collection-alert-success[role="status"]',
            '[data-prf-review-alert].is-success',
            '[data-prf-review-alert].is-error'
        ].join(',');
        let converted = false;

        document.querySelectorAll(selector).forEach(function (element) {
            if (element.dataset.drmsToastConverted === '1' || element.hidden || element.classList.contains('d-none')) {
                return;
            }
            const message = flashMessageText(element);
            if (!message) {
                return;
            }

            element.dataset.drmsToastConverted = '1';
            const isDanger = element.matches('.alert-danger, .is-error, [data-drms-flash="danger"], [data-drms-flash="error"]');
            const isWarning = element.matches('.alert-warning, [data-drms-flash="warning"]');
            const tone = isDanger ? 'danger' : (isWarning ? 'warning' : 'success');
            element.hidden = true;
            element.setAttribute('aria-hidden', 'true');
            window.setTimeout(function () { element.remove(); }, 250);
            toast(message, tone, 3000);
            converted = true;
        });

        if (converted && window.history && typeof window.history.replaceState === 'function') {
            const cleanUrl = new URL(window.location.href);
            cleanUrl.searchParams.delete('success');
            cleanUrl.searchParams.delete('error');
            window.history.replaceState(null, '', cleanUrl.pathname + cleanUrl.search + cleanUrl.hash);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', convertRenderedActionFeedback, { once: true });
    } else {
        convertRenderedActionFeedback();
    }

    function readConfirmOptions(element) {
        return {
            title: element.dataset.drmsConfirmTitle || 'Confirm action',
            message: element.dataset.drmsConfirm || 'Do you want to continue?',
            confirmText: element.dataset.drmsConfirmButton || 'Continue',
            cancelText: element.dataset.drmsCancelButton || 'Cancel',
            tone: element.dataset.drmsConfirmTone || 'warning',
            focusConfirm: element.dataset.drmsFocusConfirm === 'true',
            allowOutsideClick: element.dataset.drmsOutsideClose !== 'false',
            allowEscape: element.dataset.drmsEscapeClose !== 'false'
        };
    }

    document.addEventListener('invalid', function (event) {
        const field = event.target;
        if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement)) {
            return;
        }
        event.preventDefault();
        showFieldError(field, validationMessage(field));
        queueInvalidSummary(field);
    }, true);

    ['input', 'change'].forEach(function (eventName) {
        document.addEventListener(eventName, function (event) {
            const field = event.target;
            if (
                field instanceof HTMLInputElement ||
                field instanceof HTMLSelectElement ||
                field instanceof HTMLTextAreaElement
            ) {
                if (field.dataset.drmsNativeInvalid === '1' && field.validity.valid) {
                    clearFieldError(field);
                }
            }
        }, true);
    });

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('[data-drms-confirm]')) {
            return;
        }

        if (form.dataset.drmsConfirmBypass === '1') {
            delete form.dataset.drmsConfirmBypass;
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;

        confirmAction(readConfirmOptions(form)).then(function (approved) {
            if (!approved) {
                return;
            }
            form.dataset.drmsConfirmBypass = '1';
            if (typeof form.requestSubmit === 'function') {
                try {
                    form.requestSubmit(submitter);
                } catch (error) {
                    form.requestSubmit();
                }
            } else {
                form.submit();
            }
        });
    }, true);

    document.addEventListener('click', function (event) {
        const trigger = event.target.closest('[data-drms-confirm]:not(form)');
        if (!trigger || trigger.dataset.drmsConfirmBypass === '1') {
            if (trigger) {
                delete trigger.dataset.drmsConfirmBypass;
            }
            return;
        }

        const form = trigger.form instanceof HTMLFormElement ? trigger.form : null;
        if (form && form.matches('[data-drms-confirm]')) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        confirmAction(readConfirmOptions(trigger)).then(function (approved) {
            if (!approved) {
                return;
            }

            if (trigger instanceof HTMLAnchorElement && trigger.href) {
                window.location.assign(trigger.href);
                return;
            }

            if (form && typeof form.requestSubmit === 'function') {
                form.requestSubmit(trigger);
                return;
            }

            trigger.dispatchEvent(new CustomEvent('drms:confirmed', { bubbles: true }));
        });
    }, true);

    window.DRMSFeedback = {
        __initialized: true,
        confirm: confirmAction,
        prompt: promptAction,
        alert: alertAction,
        toast: toast,
        showFieldError: showFieldError,
        clearFieldError: clearFieldError
    };
})();
