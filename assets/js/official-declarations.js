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
        const viewer = previewElement.querySelector('[data-preview-viewer]');
        const image = previewElement.querySelector('[data-preview-image]');
        const imageScroll = previewElement.querySelector('[data-preview-image-scroll]');
        const pdfViewer = previewElement.querySelector('[data-preview-pdf]');
        const pdfCanvas = previewElement.querySelector('[data-preview-canvas]');
        const pdfScroll = previewElement.querySelector('[data-preview-page-scroll]');
        const pageControls = previewElement.querySelector('[data-preview-page-controls]');
        const pageLabel = previewElement.querySelector('[data-preview-page]');
        const previousPage = previewElement.querySelector('[data-preview-prev]');
        const nextPage = previewElement.querySelector('[data-preview-next]');
        const zoomOut = previewElement.querySelector('[data-preview-zoom-out]');
        const zoomIn = previewElement.querySelector('[data-preview-zoom-in]');
        const zoomLevel = previewElement.querySelector('[data-preview-zoom-level]');
        const loading = previewElement.querySelector('[data-preview-loading]');
        const fallback = previewElement.querySelector('[data-preview-fallback]');
        const download = previewElement.querySelector('[data-preview-download]');
        const title = previewElement.querySelector('#declarationPreviewTitle');
        let previewToken = 0;
        let fetchController = null;
        let imageUrl = null;
        let activeType = null;
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
            zoomOut.disabled = !activeType || zoom <= 0.5;
            zoomIn.disabled = !activeType || zoom >= 3;
            zoomLevel.textContent = `${Math.round(zoom * 100)}%`;
            pageControls.hidden = activeType !== 'pdf';
        };

        const viewCenter = area => ({
            x: (area.scrollLeft + area.clientWidth / 2) / Math.max(area.scrollWidth, 1),
            y: (area.scrollTop + area.clientHeight / 2) / Math.max(area.scrollHeight, 1)
        });

        const restoreCenter = (area, center) => {
            area.scrollLeft = center.x * area.scrollWidth - area.clientWidth / 2;
            area.scrollTop = center.y * area.scrollHeight - area.clientHeight / 2;
        };

        const renderImage = (center = null) => {
            if (activeType !== 'image' || !image.naturalWidth) return;
            const fit = Math.min(1,
                Math.max(1, imageScroll.clientWidth - 24) / image.naturalWidth,
                Math.max(1, imageScroll.clientHeight - 24) / image.naturalHeight);
            image.style.width = `${image.naturalWidth * fit * zoom}px`;
            image.style.height = `${image.naturalHeight * fit * zoom}px`;
            restoreCenter(imageScroll, center || { x: 0.5, y: 0.5 });
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
            updateControls();
        };

        const renderPage = async (center = null) => {
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
                const ratio = Math.min(window.devicePixelRatio || 1, 2,
                    Math.sqrt(16000000 / (viewport.width * viewport.height)));
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
                restoreCenter(pdfScroll, center || { x: 0.5, y: 0 });
                loading.hidden = true;
            } catch (error) {
                if (token !== renderToken || error?.name === 'RenderingCancelledException') return;
                showFallback('This PDF could not be previewed. Download the file to review it.');
            }
        };

        const openPdf = async (data, token) => {
            try {
                if (!window.FixiePDF?.load) throw new Error('PDF preview unavailable');
                const pdfjs = await window.FixiePDF.load();
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
                activeType = 'pdf';
                viewer.hidden = false;
                pdfViewer.hidden = false;
                await renderPage();
            } catch (_) {
                if (token === previewToken) showFallback('This PDF could not be previewed. Download the file to review it.');
            }
        };

        const showFallback = message => {
            loading.hidden = true;
            viewer.hidden = true;
            imageScroll.hidden = true;
            pdfViewer.hidden = true;
            fallback.querySelector('p').textContent = message;
            fallback.hidden = false;
        };

        const openFile = async (fileUrl, hint, name, token) => {
            fetchController = new AbortController();
            try {
                const response = await fetch(fileUrl, { credentials: 'same-origin', signal: fetchController.signal });
                if (token !== previewToken) return;
                if (!response.ok) throw new Error('Protected file could not be opened');
                const mime = (response.headers.get('content-type') || '').split(';')[0].trim().toLowerCase();
                const blob = await response.blob();
                if (token !== previewToken) return;
                const isPdf = mime === 'application/pdf' ||
                    (hint === 'pdf' && mime === 'application/octet-stream');
                const isImage = /^image\/(png|jpeg|gif|webp)$/.test(mime) ||
                    (hint === 'image' && mime === 'application/octet-stream');
                if (isPdf) {
                    await openPdf(new Uint8Array(await blob.arrayBuffer()), token);
                } else if (isImage) {
                    activeType = 'image';
                    image.alt = 'Preview of ' + name;
                    image.onload = () => {
                        if (token !== previewToken) return;
                        viewer.hidden = false;
                        imageScroll.hidden = false;
                        loading.hidden = true;
                        updateControls();
                        renderImage();
                    };
                    image.onerror = () => { if (token === previewToken) showFallback('This image could not be previewed. Download the file to review it.'); };
                    imageUrl = URL.createObjectURL(blob);
                    image.src = imageUrl;
                } else {
                    showFallback('Please download and open this file to review it before you continue.');
                }
            } catch (error) {
                if (token === previewToken && error?.name !== 'AbortError') {
                    showFallback('This file could not be previewed. Download it to review the file.');
                }
            }
        };

        const resetPreview = () => {
            if (fetchController) fetchController.abort();
            fetchController = null;
            clearPdf();
            activeType = null;
            zoom = 1;
            image.onload = null;
            image.onerror = null;
            image.removeAttribute('src');
            if (imageUrl) URL.revokeObjectURL(imageUrl);
            imageUrl = null;
            image.style.width = '';
            image.style.height = '';
            viewer.hidden = true;
            imageScroll.hidden = true;
            pdfViewer.hidden = true;
            updateControls();
        };

        document.querySelectorAll('[data-declaration-preview]').forEach(button => {
            button.addEventListener('click', () => {
                if (!previewModal) return;
                const name = button.dataset.fileName || 'Document';
                const type = button.dataset.previewType || 'other';
                const fileUrl = button.dataset.fileUrl || '';
                const token = ++previewToken;
                resetPreview();
                title.textContent = name;
                download.href = fileUrl;
                download.download = name;
                loading.hidden = false;
                fallback.hidden = true;
                previewModal.show();
                void openFile(fileUrl, type, name, token);
            });
        });
        previousPage.addEventListener('click', () => { if (pdfDocument && pageNumber > 1) { pageNumber--; void renderPage(); } });
        nextPage.addEventListener('click', () => { if (pdfDocument && pageNumber < pdfDocument.numPages) { pageNumber++; void renderPage(); } });
        const changeZoom = amount => {
            if (!activeType) return;
            const next = Math.max(0.5, Math.min(3, +(zoom + amount).toFixed(2)));
            if (next === zoom) return;
            const area = activeType === 'pdf' ? pdfScroll : imageScroll;
            const center = viewCenter(area);
            zoom = next;
            updateControls();
            if (activeType === 'pdf') void renderPage(center);
            else renderImage(center);
        };
        zoomOut.addEventListener('click', () => changeZoom(-0.25));
        zoomIn.addEventListener('click', () => changeZoom(0.25));

        [imageScroll, pdfScroll].forEach(area => {
            let drag = null;
            area.addEventListener('pointerdown', event => {
                if (event.pointerType === 'touch' || event.button !== 0) return;
                drag = { x: event.clientX, y: event.clientY, left: area.scrollLeft, top: area.scrollTop };
                area.setPointerCapture(event.pointerId);
                area.classList.add('is-dragging');
                event.preventDefault();
            });
            area.addEventListener('pointermove', event => {
                if (!drag) return;
                area.scrollLeft = drag.left - (event.clientX - drag.x);
                area.scrollTop = drag.top - (event.clientY - drag.y);
            });
            const stopDrag = () => { drag = null; area.classList.remove('is-dragging'); };
            area.addEventListener('pointerup', stopDrag);
            area.addEventListener('pointercancel', stopDrag);
            area.addEventListener('lostpointercapture', stopDrag);
        });
        previewElement.addEventListener('hidden.bs.modal', () => {
            previewToken++;
            resetPreview();
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
