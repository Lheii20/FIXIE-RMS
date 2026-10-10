/* Shared, in-system file viewer for protected document links. */
(() => {
    'use strict';

    let root = null;
    let source = null;
    let previousFocus = null;
    let onClose = null;
    let request = null;
    let objectUrl = null;
    let pdf = null;
    let renderTask = null;
    let loadTask = null;
    let serial = 0;
    let pageNumber = 1;
    let zoom = 1;
    let kind = '';
    let loaderPromise = null;

    function elements() {
        return {
            title: root.querySelector('[data-file-title]'),
            status: root.querySelector('[data-file-status]'),
            image: root.querySelector('[data-file-image]'),
            imageArea: root.querySelector('[data-file-image-area]'),
            pdfArea: root.querySelector('[data-file-pdf-area]'),
            canvas: root.querySelector('[data-file-canvas]'),
            fallback: root.querySelector('[data-file-fallback]'),
            pages: root.querySelector('[data-file-pages]'),
            pageText: root.querySelector('[data-file-page-text]'),
            previous: root.querySelector('[data-file-previous]'),
            next: root.querySelector('[data-file-next]'),
            zoomOut: root.querySelector('[data-file-zoom-out]'),
            zoomIn: root.querySelector('[data-file-zoom-in]'),
            zoomText: root.querySelector('[data-file-zoom-text]'),
            download: root.querySelector('[data-file-download]')
        };
    }

    function installDrag(area) {
        let drag = null;
        area.addEventListener('pointerdown', event => {
            if (event.pointerType === 'touch' || event.button !== 0 || !kind) return;
            drag = { x: event.clientX, y: event.clientY, left: area.scrollLeft, top: area.scrollTop };
            area.setPointerCapture(event.pointerId);
            area.classList.add('is-dragging');
            event.preventDefault();
        });
        area.addEventListener('pointermove', event => {
            if (!drag) return;
            area.scrollLeft = drag.left - event.clientX + drag.x;
            area.scrollTop = drag.top - event.clientY + drag.y;
        });
        const stop = () => { drag = null; area.classList.remove('is-dragging'); };
        area.addEventListener('pointerup', stop);
        area.addEventListener('pointercancel', stop);
        area.addEventListener('lostpointercapture', stop);
    }

    function mount() {
        if (root) return;
        root = document.createElement('div');
        root.className = 'drms-file-preview';
        root.hidden = true;
        root.innerHTML = `
            <div class="drms-file-preview__panel" role="dialog" aria-modal="true" aria-labelledby="drmsFilePreviewTitle">
                <header class="drms-file-preview__header">
                    <div class="drms-file-preview__heading"><span>File preview</span><strong id="drmsFilePreviewTitle" data-file-title></strong></div>
                    <div class="drms-file-preview__header-actions">
                        <a class="drms-file-preview__download" data-file-download href="#" download><i class="fas fa-download" aria-hidden="true"></i><span>Download</span></a>
                        <button type="button" class="drms-file-preview__close" data-file-close aria-label="Close preview" title="Close preview"><i class="fas fa-times" aria-hidden="true"></i></button>
                    </div>
                </header>
                <div class="drms-file-preview__toolbar" aria-label="Preview controls">
                    <div class="drms-file-preview__pages" data-file-pages hidden>
                        <button type="button" data-file-previous aria-label="Previous page" title="Previous page"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
                        <span data-file-page-text aria-live="polite">Page 1 of 1</span>
                        <button type="button" data-file-next aria-label="Next page" title="Next page"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
                    </div>
                    <div class="drms-file-preview__zoom">
                        <button type="button" data-file-zoom-out aria-label="Zoom out" title="Zoom out"><i class="fas fa-minus" aria-hidden="true"></i></button>
                        <span data-file-zoom-text aria-live="polite">100%</span>
                        <button type="button" data-file-zoom-in aria-label="Zoom in" title="Zoom in"><i class="fas fa-plus" aria-hidden="true"></i></button>
                        <button type="button" data-file-fit aria-label="Fit to screen" title="Fit to screen"><i class="fas fa-expand" aria-hidden="true"></i></button>
                    </div>
                    <span class="drms-file-preview__hint">Drag to move · Scroll to browse</span>
                </div>
                <div class="drms-file-preview__body">
                    <div class="drms-file-preview__status" data-file-status role="status">Opening file…</div>
                    <div class="drms-file-preview__area" data-file-image-area hidden><img data-file-image alt="" draggable="false"></div>
                    <div class="drms-file-preview__area" data-file-pdf-area hidden><canvas data-file-canvas aria-label="PDF page preview"></canvas></div>
                    <div class="drms-file-preview__fallback" data-file-fallback hidden><i class="fas fa-file-download" aria-hidden="true"></i><strong>Preview unavailable</strong><p>Download this file to review it.</p></div>
                </div>
            </div>`;
        document.body.appendChild(root);
        const ui = elements();
        root.querySelector('[data-file-close]').addEventListener('click', close);
        ui.previous.addEventListener('click', () => { if (pdf && pageNumber > 1) { pageNumber--; void renderPage(); } });
        ui.next.addEventListener('click', () => { if (pdf && pageNumber < pdf.numPages) { pageNumber++; void renderPage(); } });
        ui.zoomOut.addEventListener('click', () => changeZoom(-0.25));
        ui.zoomIn.addEventListener('click', () => changeZoom(0.25));
        root.querySelector('[data-file-fit]').addEventListener('click', () => changeZoom(1 - zoom));
        installDrag(ui.imageArea);
        installDrag(ui.pdfArea);
    }

    function controls() {
        const ui = elements();
        ui.pages.hidden = kind !== 'pdf';
        ui.pageText.textContent = `Page ${pageNumber} of ${pdf?.numPages || 1}`;
        ui.previous.disabled = !pdf || pageNumber <= 1;
        ui.next.disabled = !pdf || pageNumber >= pdf.numPages;
        ui.zoomOut.disabled = !kind || zoom <= 0.5;
        ui.zoomIn.disabled = !kind || zoom >= 3;
        ui.zoomText.textContent = `${Math.round(zoom * 100)}%`;
    }

    function center(area) {
        return {
            x: (area.scrollLeft + area.clientWidth / 2) / Math.max(area.scrollWidth, 1),
            y: (area.scrollTop + area.clientHeight / 2) / Math.max(area.scrollHeight, 1)
        };
    }

    function restore(area, point) {
        area.scrollLeft = point.x * area.scrollWidth - area.clientWidth / 2;
        area.scrollTop = point.y * area.scrollHeight - area.clientHeight / 2;
    }

    function sizeImage(point = { x: 0.5, y: 0.5 }) {
        const ui = elements();
        if (!ui.image.naturalWidth) return;
        const fit = Math.min(1,
            Math.max(1, ui.imageArea.clientWidth - 24) / ui.image.naturalWidth,
            Math.max(1, ui.imageArea.clientHeight - 24) / ui.image.naturalHeight);
        ui.image.style.width = `${ui.image.naturalWidth * fit * zoom}px`;
        ui.image.style.height = `${ui.image.naturalHeight * fit * zoom}px`;
        restore(ui.imageArea, point);
    }

    async function renderPage(point = { x: 0.5, y: 0 }) {
        if (!pdf) return;
        const current = ++serial;
        const ui = elements();
        if (renderTask) renderTask.cancel();
        renderTask = null;
        ui.status.hidden = false;
        controls();
        try {
            const page = await pdf.getPage(pageNumber);
            if (current !== serial) return;
            const natural = page.getViewport({ scale: 1 });
            const fit = Math.min(1.5, Math.max(280, ui.pdfArea.clientWidth - 28) / natural.width);
            const viewport = page.getViewport({ scale: fit * zoom });
            const ratio = Math.min(window.devicePixelRatio || 1, 2,
                Math.sqrt(16000000 / (viewport.width * viewport.height)));
            ui.canvas.width = Math.ceil(viewport.width * ratio);
            ui.canvas.height = Math.ceil(viewport.height * ratio);
            ui.canvas.style.width = `${viewport.width}px`;
            ui.canvas.style.height = `${viewport.height}px`;
            const task = page.render({
                canvasContext: ui.canvas.getContext('2d'), viewport,
                transform: [ratio, 0, 0, ratio, 0, 0]
            });
            renderTask = task;
            await task.promise;
            if (current !== serial) return;
            renderTask = null;
            restore(ui.pdfArea, point);
            ui.status.hidden = true;
        } catch (error) {
            if (current === serial && error?.name !== 'RenderingCancelledException') {
                fallback('This PDF could not be previewed. Download it to review the file.');
            }
        }
    }

    function changeZoom(amount) {
        if (!kind) return;
        const next = Math.max(0.5, Math.min(3, +(zoom + amount).toFixed(2)));
        if (next === zoom) return;
        const ui = elements();
        const area = kind === 'pdf' ? ui.pdfArea : ui.imageArea;
        const point = center(area);
        zoom = next;
        controls();
        if (kind === 'pdf') void renderPage(point);
        else sizeImage(point);
    }

    function fallback(message) {
        const ui = elements();
        kind = '';
        ui.status.hidden = true;
        ui.imageArea.hidden = true;
        ui.pdfArea.hidden = true;
        ui.fallback.querySelector('p').textContent = message;
        ui.fallback.hidden = false;
        controls();
    }

    function loadPdfEngine() {
        if (window.FixiePDF?.load) return window.FixiePDF.load();
        if (!loaderPromise) {
            loaderPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = new URL('assets/js/local-pdf-loader.js', document.baseURI).href;
                script.onload = () => window.FixiePDF?.load ? resolve(window.FixiePDF.load()) : reject(new Error('PDF reader unavailable'));
                script.onerror = () => reject(new Error('PDF reader unavailable'));
                document.head.appendChild(script);
            }).catch(error => { loaderPromise = null; throw error; });
        }
        return loaderPromise;
    }

    function cleanup() {
        serial++;
        if (request) request.abort();
        request = null;
        if (renderTask) renderTask.cancel();
        renderTask = null;
        if (loadTask) loadTask.destroy();
        loadTask = null;
        if (pdf) pdf.destroy().catch(() => {});
        pdf = null;
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        if (root) {
            const ui = elements();
            ui.image.onload = null;
            ui.image.onerror = null;
            ui.image.removeAttribute('src');
            ui.imageArea.hidden = true;
            ui.pdfArea.hidden = true;
            ui.fallback.hidden = true;
            ui.status.hidden = false;
        }
        kind = '';
        pageNumber = 1;
        zoom = 1;
    }

    function close() {
        if (!root || root.hidden) return;
        cleanup();
        root.hidden = true;
        document.body.classList.remove('drms-file-preview-open');
        const callback = onClose;
        onClose = null;
        if (previousFocus?.isConnected) previousFocus.focus();
        previousFocus = null;
        if (callback) callback();
    }

    async function open(options) {
        if (!options?.url) return;
        let fileUrl;
        try { fileUrl = new URL(options.url, location.href); } catch (_) { return; }
        if (fileUrl.origin !== location.origin) return;
        mount();
        if (!root.hidden) cleanup();
        const ui = elements();
        source = fileUrl.href;
        previousFocus = document.activeElement;
        onClose = typeof options.onClose === 'function' ? options.onClose : null;
        ui.title.textContent = options.name || 'Document';
        ui.download.href = source;
        ui.download.download = options.name || '';
        ui.status.textContent = 'Opening file…';
        ui.status.hidden = false;
        ui.fallback.hidden = true;
        root.hidden = false;
        document.body.classList.add('drms-file-preview-open');
        root.querySelector('[data-file-close]').focus();
        controls();
        const current = ++serial;
        request = new AbortController();
        try {
            const response = await fetch(source, { credentials: 'same-origin', signal: request.signal });
            if (current !== serial) return;
            if (!response.ok) throw new Error('File unavailable');
            const mime = (response.headers.get('content-type') || '').split(';')[0].trim().toLowerCase();
            const blob = await response.blob();
            if (current !== serial) return;
            const name = (options.name || '').toLowerCase();
            const hint = options.type || '';
            const isPdf = mime === 'application/pdf' ||
                (mime === 'application/octet-stream' && (hint === 'pdf' || name.endsWith('.pdf')));
            const isImage = /^image\/(png|jpeg|gif|webp)$/.test(mime) ||
                (mime === 'application/octet-stream' && (hint === 'image' || /\.(png|jpe?g|gif|webp)$/.test(name)));
            if (isPdf) {
                const engine = await loadPdfEngine();
                if (current !== serial) return;
                loadTask = engine.getDocument({ data: new Uint8Array(await blob.arrayBuffer()) });
                const documentFile = await loadTask.promise;
                if (current !== serial) { await documentFile.destroy(); return; }
                loadTask = null;
                pdf = documentFile;
                kind = 'pdf';
                ui.pdfArea.hidden = false;
                controls();
                await renderPage();
            } else if (isImage) {
                kind = 'image';
                objectUrl = URL.createObjectURL(blob);
                ui.image.alt = `Preview of ${options.name || 'file'}`;
                ui.image.onload = () => {
                    if (current !== serial) return;
                    ui.imageArea.hidden = false;
                    ui.status.hidden = true;
                    controls();
                    sizeImage();
                };
                ui.image.onerror = () => { if (current === serial) fallback('This image could not be previewed. Download it to review the file.'); };
                ui.image.src = objectUrl;
            } else {
                fallback('This file type cannot be previewed here. Download it to review the file.');
            }
        } catch (error) {
            if (current === serial && error?.name !== 'AbortError') {
                fallback('This file could not be opened. Check your access or download it to review.');
            }
        }
    }

    document.addEventListener('keydown', event => {
        if (!root || root.hidden) return;
        if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); close(); }
        if (event.key === '+' || event.key === '=') { event.preventDefault(); changeZoom(0.25); }
        if (event.key === '-') { event.preventDefault(); changeZoom(-0.25); }
        if (event.key === 'Tab') {
            const focusable = Array.from(root.querySelectorAll('button:not(:disabled),a[href]')).filter(item => !item.closest('[hidden]'));
            if (!focusable.length) return;
            if (event.shiftKey && document.activeElement === focusable[0]) { event.preventDefault(); focusable.at(-1).focus(); }
            else if (!event.shiftKey && document.activeElement === focusable.at(-1)) { event.preventDefault(); focusable[0].focus(); }
        }
    }, true);

    // These existing pages call their own preview functions from row and file links.
    // Replace only those entry points; the protected download endpoint still checks access.
    window.openDocumentViewer = (url, name, isImage) => open({ url, name, type: isImage ? 'image' : '' });
    window.viewFile = (url, type) => open({ url, type, name: document.activeElement?.tagName === 'A'
        ? document.activeElement.textContent.trim().slice(0, 140) || 'Document' : 'Document' });

    document.addEventListener('click', event => {
        const signature = event.target.closest('[data-esign-preview] img, [data-esign-current-image]');
        if (signature?.src && signature.src.startsWith(location.origin + '/')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            void open({ url: signature.src, name: 'Electronic signature', type: 'image' });
            return;
        }
        const declaration = event.target.closest('[data-declaration-preview]');
        if (declaration) {
            event.preventDefault();
            event.stopImmediatePropagation();
            void open({ url: declaration.dataset.fileUrl, name: declaration.dataset.fileName, type: declaration.dataset.previewType });
            return;
        }
        const link = event.target.closest('a[target="_blank"][href]');
        if (!link) return;
        let url;
        try { url = new URL(link.href, location.href); } catch (_) { return; }
        if (url.origin !== location.origin || !url.pathname.endsWith('/download.php')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        void open({ url: url.href, name: link.getAttribute('aria-label') || link.textContent.trim() || 'Document' });
    }, true);

    window.DRMSFilePreview = Object.freeze({ open, close });
})();
