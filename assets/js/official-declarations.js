(() => {
    'use strict';

    const feedback = document.querySelector('[data-declaration-feedback]');
    if (feedback && window.DRMSFeedback) {
        window.DRMSFeedback.toast(feedback.textContent, feedback.dataset.declarationFeedback, 3000);
        feedback.remove();
    }

    const url = new URL(location.href);
    url.searchParams.delete('error');
    url.searchParams.delete('success');
    history.replaceState(null, '', url.pathname + url.search);

    const previewElement = document.getElementById('declarationPreviewModal');
    if (previewElement) {
        const previewModal = window.bootstrap?.Modal.getOrCreateInstance(previewElement);
        const image = previewElement.querySelector('[data-preview-image]');
        const pdfViewer = previewElement.querySelector('[data-preview-pdf]');
        const pdfCanvas = previewElement.querySelector('[data-preview-canvas]');
        const pdfScroll = previewElement.querySelector('[data-preview-page-scroll]');
        const pageLabel = previewElement.querySelector('[data-preview-page]');
        const previousPage = previewElement.querySelector('[data-preview-prev]');
        const nextPage = previewElement.querySelector('[data-preview-next]');
        const zoomOut = previewElement.querySelector('[data-preview-zoom-out]');
        const zoomIn = previewElement.querySelector('[data-preview-zoom-in]');
        const loading = previewElement.querySelector('[data-preview-loading]');
        const fallback = previewElement.querySelector('[data-preview-fallback]');
        const download = previewElement.querySelector('[data-preview-download]');
        const title = previewElement.querySelector('#declarationPreviewTitle');
        let previewToken = 0;
        let pdfDocument = null;
        let loadingTask = null;
        let renderTask = null;
        let renderToken = 0;
        let pageNumber = 1;
        let zoom = 1;

        const updateControls = () => {
            const count = pdfDocument?.numPages || 1;
            pageLabel.textContent = `Page ${pageNumber} of ${count}`;
            previousPage.disabled = pageNumber <= 1;
            nextPage.disabled = pageNumber >= count;
            zoomOut.disabled = zoom <= 0.75;
            zoomIn.disabled = zoom >= 1.5;
        };

        const clearPdf = () => {
            renderToken++;
            if (renderTask) renderTask.cancel();
            renderTask = null;
            if (loadingTask) loadingTask.destroy();
            loadingTask = null;
            if (pdfDocument) pdfDocument.destroy().catch(() => {});
            pdfDocument = null;
            pageNumber = 1;
            zoom = 1;
            pdfCanvas.width = 0;
            pdfCanvas.height = 0;
            pdfViewer.hidden = true;
            updateControls();
        };

        const renderPage = async () => {
            if (!pdfDocument) return;
            const token = ++renderToken;
            if (renderTask) renderTask.cancel();
            renderTask = null;
            loading.hidden = false;
            updateControls();
            try {
                const page = await pdfDocument.getPage(pageNumber);
                if (token !== renderToken) return;
                const natural = page.getViewport({ scale: 1 });
                const availableWidth = Math.max(280, pdfScroll.clientWidth - 28);
                const fit = Math.min(1.5, availableWidth / natural.width);
                const viewport = page.getViewport({ scale: fit * zoom });
                const ratio = Math.min(window.devicePixelRatio || 1, 2);
                pdfCanvas.width = Math.ceil(viewport.width * ratio);
                pdfCanvas.height = Math.ceil(viewport.height * ratio);
                pdfCanvas.style.width = `${viewport.width}px`;
                pdfCanvas.style.height = `${viewport.height}px`;
                const context = pdfCanvas.getContext('2d');
                const task = page.render({ canvasContext: context, viewport, transform: [ratio, 0, 0, ratio, 0, 0] });
                renderTask = task;
                await task.promise;
                if (token !== renderToken) return;
                renderTask = null;
                pdfScroll.scrollTop = 0;
                loading.hidden = true;
            } catch (error) {
                if (token !== renderToken || error?.name === 'RenderingCancelledException') return;
                showFallback('This PDF could not be previewed. Download the file to review it.');
            }
        };

        const openPdf = async (fileUrl, token) => {
            try {
                if (!window.FixiePDF?.load) throw new Error('PDF preview unavailable');
                const [pdfjs, response] = await Promise.all([
                    window.FixiePDF.load(),
                    fetch(fileUrl, { credentials: 'same-origin' })
                ]);
                if (token !== previewToken) return;
                if (!response.ok || !response.headers.get('content-type')?.toLowerCase().includes('pdf')) {
                    throw new Error('Protected file could not be opened');
                }
                const data = new Uint8Array(await response.arrayBuffer());
                if (token !== previewToken) return;
                const task = pdfjs.getDocument({ data });
                loadingTask = task;
                const document = await task.promise;
                if (token !== previewToken) {
                    await document.destroy();
                    return;
                }
                loadingTask = null;
                pdfDocument = document;
                pdfViewer.hidden = false;
                await renderPage();
            } catch (_) {
                if (token === previewToken) showFallback('This PDF could not be previewed. Download the file to review it.');
            }
        };

        const showFallback = message => {
            loading.hidden = true;
            image.hidden = true;
            pdfViewer.hidden = true;
            fallback.querySelector('p').textContent = message;
            fallback.hidden = false;
        };

        document.querySelectorAll('[data-declaration-preview]').forEach(button => {
            button.addEventListener('click', () => {
                if (!previewModal) {
                    window.open(button.dataset.fileUrl, '_blank', 'noopener');
                    return;
                }
                const name = button.dataset.fileName || 'Document';
                const type = button.dataset.previewType || 'other';
                const fileUrl = button.dataset.fileUrl || '';
                const token = ++previewToken;
                clearPdf();
                title.textContent = name;
                download.href = fileUrl;
                download.download = name;
                loading.hidden = false;
                fallback.hidden = true;
                image.hidden = true;
                image.removeAttribute('src');
                previewModal.show();

                if (type === 'image') {
                    image.alt = 'Preview of ' + name;
                    image.onload = () => { if (token === previewToken) { loading.hidden = true; image.hidden = false; } };
                    image.onerror = () => { if (token === previewToken) showFallback('This image could not be previewed. Download the file to review it.'); };
                    image.src = fileUrl;
                } else if (type === 'pdf') {
                    void openPdf(fileUrl, token);
                } else {
                    showFallback('Please download and open this file to review it before you continue.');
                }
            });
        });
        previousPage.addEventListener('click', () => { if (pdfDocument && pageNumber > 1) { pageNumber--; void renderPage(); } });
        nextPage.addEventListener('click', () => { if (pdfDocument && pageNumber < pdfDocument.numPages) { pageNumber++; void renderPage(); } });
        zoomOut.addEventListener('click', () => { if (pdfDocument && zoom > 0.75) { zoom = Math.max(0.75, +(zoom - 0.25).toFixed(2)); void renderPage(); } });
        zoomIn.addEventListener('click', () => { if (pdfDocument && zoom < 1.5) { zoom = Math.min(1.5, +(zoom + 0.25).toFixed(2)); void renderPage(); } });
        previewElement.addEventListener('hidden.bs.modal', () => {
            previewToken++;
            clearPdf();
            image.onload = null;
            image.onerror = null;
            image.removeAttribute('src');
            download.removeAttribute('href');
        });
    }

    function showFormError(form, message) {
        const error = form.querySelector('.dec-error');
        if (!error) return;
        error.textContent = message;
        error.hidden = !message;
        if (message) error.focus();
    }

    function setBusy(form, button) {
        form.querySelectorAll('button').forEach(item => { item.disabled = true; });
        if (button) button.textContent = 'Saving…';
    }

    function actionFor(form, submitter) {
        if (submitter?.name === 'action') return submitter.value;
        return form.querySelector('[name=action]')?.value || '';
    }

    document.querySelectorAll('[data-declaration-form]').forEach(form => {
        let busy = false;
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (busy) return;

            const button = event.submitter;
            const action = actionFor(form, button);
            const value = name => (form.elements.namedItem(name)?.value || '').trim();
            const signedOfficialDeclaration = action === 'declare_official' &&
                form.elements.e_signature_client_confirmed?.value === '1';
            let message = '';

            if (action === 'submit') {
                if (value('signer_role') !== 'GM' || !form.elements.file_review_confirmed?.checked) {
                    message = 'Review the exact file and confirm that it is ready for General Manager review.';
                }
            }
            if (action === 'return' && !value('remarks')) {
                message = 'Enter the reason for returning this request.';
            }
            if (action === 'declare_official' && !signedOfficialDeclaration) {
                message = 'Use the electronic-signature confirmation before filing this Official Record.';
            }
            if (action === 'declare_official' && !form.elements.gm_review_confirmed?.checked) {
                message = 'Open the exact file and confirm that it is complete and ready for Official Record filing.';
            }
            if (!message && !signedOfficialDeclaration && !window.DRMSFeedback) {
                message = 'The confirmation service is unavailable. Refresh the page before submitting.';
            }
            showFormError(form, message);
            if (message) return;

            if (signedOfficialDeclaration) {
                busy = true;
                setBusy(form, null);
                HTMLFormElement.prototype.submit.call(form);
                return;
            }

            busy = true;
            try {
                const approved = await window.DRMSFeedback.confirm({
                    title: action === 'return' ? 'Return this request?' : action === 'cancel' ? 'Cancel your request?' : 'Submit for verification?',
                    message: 'Your decision will be recorded and the request status will be updated.',
                    confirmText: action === 'return' ? 'Return request' : action === 'cancel' ? 'Cancel request' : 'Submit request',
                    cancelText: 'Go back',
                    tone: action === 'return' || action === 'cancel' ? 'warning' : 'info'
                });
                if (!approved) {
                    busy = false;
                    return;
                }
                if (button?.hasAttribute('formaction')) form.action = button.getAttribute('formaction');
                if (button?.name === 'action') {
                    const input = document.createElement('input');
                    input.type = 'hidden'; input.name = button.name; input.value = button.value;
                    form.append(input);
                }
                setBusy(form, button);
                HTMLFormElement.prototype.submit.call(form);
            } catch (_) {
                busy = false;
                showFormError(form, 'The action could not be submitted. Refresh and try again.');
            }
        });
    });

    const decisionForm = document.getElementById('declarationDecisionForm') ||
        document.querySelector('[data-declaration-form] [data-declaration-sign]')?.closest('form');
    if (!decisionForm) return;

    const actionInput = decisionForm.querySelector('[name=action]');
    const signButton = decisionForm.querySelector('[data-declaration-sign]');
    const returnButton = decisionForm.querySelector('[data-declaration-return]');

    if (signButton && actionInput) {
        signButton.addEventListener('click', () => {
            // Direct GM filing and queued GM approval use the same review gate.
            if (!decisionForm.elements.gm_review_confirmed?.checked) {
                showFormError(decisionForm, 'Open the exact file and confirm that it is complete and ready for Official Record filing.');
                return;
            }
            if (!window.DRMSESignature) {
                showFormError(decisionForm, 'The electronic-signature component is unavailable. Refresh the page and try again.');
                return;
            }
            actionInput.value = 'declare_official';
            decisionForm.action = 'actions/document_handler.php';
            window.DRMSESignature.open({
                form: decisionForm,
                title: 'Sign Official Record declaration',
                subtitle: 'Confirm that the reviewed file may be locked and filed as an Official Record. This cannot be undone from this screen.',
                recordLabel: decisionForm.closest('.dec-panel')?.querySelector('h2')?.textContent?.trim() || 'Official Record declaration',
                stage: 'Official Declaration',
                consent: 'I reviewed this exact document version and confirm that it is complete and appropriate for Official Record filing. I authorize filing it as an Official Record through my electronic signature.'
            });
        });
    }

    if (returnButton && actionInput) {
        returnButton.addEventListener('click', () => {
            actionInput.value = 'return';
            decisionForm.action = 'actions/official_declaration_handler.php';
            if (decisionForm.requestSubmit) decisionForm.requestSubmit();
            else decisionForm.submit();
        });
    }
})();
