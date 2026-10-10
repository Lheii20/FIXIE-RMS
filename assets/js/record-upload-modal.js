(function () {
    'use strict';

    const modal = document.getElementById('uploadModal');
    const form = document.getElementById('recordUploadForm');
    const zone = document.getElementById('uploadDropZone');
    const fileInput = document.getElementById('uploadDocumentInput');
    if (!modal || !form || !zone || !fileInput) return;

    const folderInput = document.getElementById('uploadCategoryInput');
    const folderButton = document.getElementById('uploadCategoryBtn');
    const folderError = document.getElementById('uploadFolderError');
    const fileError = document.getElementById('uploadFileTypeError');
    const confirmation = document.getElementById('uploadSignatureConfirmed');
    const confirmationError = document.getElementById('uploadConfirmationError');
    const submitButton = document.getElementById('uploadSubmitBtn');
    const selectedCard = document.getElementById('uploadSelectedFile');
    const removeButton = document.getElementById('uploadRemoveFileBtn');
    const previewButton = document.getElementById('uploadPreviewFileBtn');
    const previewPanel = document.getElementById('recordUploadPreviewPanel');
    const previewName = document.getElementById('recordUploadPreviewName');
    const previewContent = document.getElementById('recordUploadPreviewContent');
    const backButton = document.getElementById('uploadBackFromPreview');
    const zoomControls = document.getElementById('recordUploadZoomControls');
    const zoomOutButton = document.getElementById('uploadZoomOut');
    const zoomInButton = document.getElementById('uploadZoomIn');
    const zoomResetButton = document.getElementById('uploadZoomReset');
    const zoomLevel = document.getElementById('uploadZoomLevel');
    const previewShell = previewPanel ? previewPanel.querySelector('.record-upload-preview-shell') : null;
    const panHint = document.createElement('span');
    panHint.className = 'record-upload-pan-hint d-none';
    panHint.textContent = 'Drag to move';
    if (previewShell) previewShell.appendChild(panHint);
    let dragDepth = 0;
    let previewUrl = null;
    let previewRequest = 0;
    let previewImage = null;
    let zoomTarget = null;
    let activePdf = null;
    let pdfObserver = null;
    let imageStage = null;
    let imageScale = 1;
    let imageX = 0;
    let imageY = 0;

    function showError(element, message) {
        if (!element) return;
        element.textContent = message;
        element.classList.remove('d-none');
    }
    function hideError(element) {
        if (element) element.classList.add('d-none');
    }
    function refreshSelectedFile() {
        const hasFile = fileInput.files.length > 0;
        if (selectedCard) selectedCard.classList.toggle('d-none', !hasFile);
        if (hasFile) hideError(fileError);
    }
    function clearPreviewContent() {
        previewRequest++;
        if (pdfObserver) pdfObserver.disconnect();
        pdfObserver = null;
        if (activePdf) activePdf.destroy().catch(function () {});
        activePdf = null;
        if (previewContent) previewContent.replaceChildren();
        if (previewContent) previewContent.classList.remove('is-panning');
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null;
        previewImage = null;
        zoomTarget = null;
        imageStage = null;
        imageScale = 1;
        imageX = 0;
        imageY = 0;
        updateImageZoom();
    }
    function closePreview() {
        clearPreviewContent();
        if (previewPanel) previewPanel.classList.add('d-none');
    }
    function updateImageZoom() {
        if (previewImage) {
            previewImage.style.transform = 'translate(' + imageX + 'px, ' + imageY + 'px) scale(' + imageScale + ')';
            previewImage.classList.toggle('is-zoomed', imageScale > 1);
            if (imageStage) imageStage.classList.toggle('is-zoomed', imageScale > 1);
        }
        if (zoomTarget) zoomTarget.style.zoom = String(imageScale);
        if (previewContent) previewContent.classList.toggle('is-pannable', imageScale > 1 && !!zoomTarget);
        if (panHint) panHint.classList.toggle('d-none', imageScale <= 1 || (!previewImage && !zoomTarget));
        if (zoomLevel) zoomLevel.textContent = Math.round(imageScale * 100) + '%';
        if (zoomOutButton) zoomOutButton.disabled = imageScale <= 1 || (!previewImage && !zoomTarget);
        if (zoomInButton) zoomInButton.disabled = imageScale >= 6 || (!previewImage && !zoomTarget);
        if (zoomResetButton) zoomResetButton.disabled = !previewImage && !zoomTarget;
    }
    function changeImageZoom(delta) {
        if (!previewImage && !zoomTarget) return;
        imageScale = Math.max(1, Math.min(6, Math.round((imageScale + delta) * 100) / 100));
        if (imageScale === 1) { imageX = 0; imageY = 0; }
        updateImageZoom();
    }
    function installImagePan(stage) {
        let pointerId = null;
        let lastX = 0;
        let lastY = 0;
        stage.addEventListener('pointerdown', function (event) {
            if (imageScale <= 1 || event.button > 0) return;
            pointerId = event.pointerId;
            lastX = event.clientX;
            lastY = event.clientY;
            stage.setPointerCapture(pointerId);
            stage.classList.add('is-panning');
            event.preventDefault();
        });
        stage.addEventListener('pointermove', function (event) {
            if (event.pointerId !== pointerId) return;
            imageX += event.clientX - lastX;
            imageY += event.clientY - lastY;
            lastX = event.clientX;
            lastY = event.clientY;
            updateImageZoom();
        });
        function endPan(event) {
            if (event.pointerId !== pointerId) return;
            pointerId = null;
            stage.classList.remove('is-panning');
        }
        stage.addEventListener('pointerup', endPan);
        stage.addEventListener('pointercancel', endPan);
    }
    function installDocumentPan() {
        let pointerId = null;
        let lastX = 0;
        let lastY = 0;
        previewContent.addEventListener('pointerdown', function (event) {
            if (!zoomTarget || previewImage || imageScale <= 1 || event.button > 0) return;
            pointerId = event.pointerId;
            lastX = event.clientX;
            lastY = event.clientY;
            previewContent.setPointerCapture(pointerId);
            previewContent.classList.add('is-panning');
            event.preventDefault();
        });
        previewContent.addEventListener('pointermove', function (event) {
            if (event.pointerId !== pointerId) return;
            previewContent.scrollLeft -= event.clientX - lastX;
            previewContent.scrollTop -= event.clientY - lastY;
            lastX = event.clientX;
            lastY = event.clientY;
        });
        function endPan(event) {
            if (event.pointerId !== pointerId) return;
            pointerId = null;
            previewContent.classList.remove('is-panning');
        }
        previewContent.addEventListener('pointerup', endPan);
        previewContent.addEventListener('pointercancel', endPan);
    }
    installDocumentPan();
    function documentSurface(className) {
        const surface = document.createElement('div');
        surface.className = 'record-upload-document-surface ' + className;
        previewContent.appendChild(surface);
        zoomTarget = surface;
        updateImageZoom();
        return surface;
    }
    function note(message) {
        const block = document.createElement('div');
        block.className = 'preview-note';
        block.textContent = message;
        return block;
    }
    function loadExcelLibrary() {
        if (window.XLSX) return Promise.resolve(window.XLSX);
        if (!window.fixieUploadXlsxLoader) {
            window.fixieUploadXlsxLoader = new Promise(function (resolve, reject) {
                const script = document.createElement('script');
                script.src = 'assets/vendor/xlsx/0.18.5/xlsx.full.min.js';
                script.onload = function () { window.XLSX ? resolve(window.XLSX) : reject(new Error('Excel preview unavailable')); };
                script.onerror = function () { reject(new Error('Excel preview unavailable')); };
                document.head.appendChild(script);
            });
        }
        return window.fixieUploadXlsxLoader;
    }
    async function previewExcel(file, request) {
        const XLSX = await loadExcelLibrary();
        const workbook = XLSX.read(await file.arrayBuffer(), { type: 'array', cellFormula: false });
        if (request !== previewRequest) return;
        const sheet = workbook.Sheets[workbook.SheetNames[0]];
        if (!sheet) { previewContent.appendChild(note('This workbook has no visible sheet to preview.')); return; }
        const surface = documentSurface('record-upload-sheet');
        const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, raw: false, blankrows: false }).slice(0, 40);
        const caption = note('First sheet: ' + workbook.SheetNames[0] + '. Showing up to 40 rows and 12 columns.');
        surface.appendChild(caption);
        const wrapper = document.createElement('div');
        wrapper.className = 'preview-table-wrap';
        const table = document.createElement('table');
        rows.forEach(function (row) {
            const tr = document.createElement('tr');
            row.slice(0, 12).forEach(function (value) {
                const td = document.createElement('td');
                td.textContent = String(value == null ? '' : value);
                tr.appendChild(td);
            });
            table.appendChild(tr);
        });
        wrapper.appendChild(table);
        surface.appendChild(wrapper);
    }
    async function previewPdf(file, request) {
        if (!window.FixiePDF || typeof window.FixiePDF.load !== 'function') throw new Error('PDF renderer unavailable');
        const pdfjs = await window.FixiePDF.load();
        const pdf = await pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
        if (request !== previewRequest) { await pdf.destroy(); return; }
        activePdf = pdf;
        const surface = documentSurface('record-upload-pdf');
        const firstPage = await pdf.getPage(1);
        if (request !== previewRequest) return;
        const firstViewport = firstPage.getViewport({ scale: 1 });
        const pageWidth = Math.max(260, Math.min(900, previewContent.clientWidth - 48));
        const pageHeight = Math.round(pageWidth * firstViewport.height / firstViewport.width);
        const boxes = [];
        for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
            const box = document.createElement('div');
            box.className = 'record-upload-pdf-page';
            box.style.width = pageWidth + 'px';
            box.style.minHeight = pageHeight + 'px';
            box.dataset.page = String(pageNumber);
            box.setAttribute('aria-label', 'PDF page ' + pageNumber + ' of ' + pdf.numPages);
            surface.appendChild(box);
            boxes.push(box);
        }
        async function renderPage(box) {
            if (box.dataset.rendered) return;
            box.dataset.rendered = '1';
            const pageNumber = Number(box.dataset.page);
            try {
                const page = pageNumber === 1 ? firstPage : await pdf.getPage(pageNumber);
                if (request !== previewRequest) return;
                const display = page.getViewport({ scale: pageWidth / page.getViewport({ scale: 1 }).width });
                const pixelRatio = Math.min(2, window.devicePixelRatio || 1.5);
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(display.width * pixelRatio);
                canvas.height = Math.round(display.height * pixelRatio);
                canvas.style.width = Math.round(display.width) + 'px';
                canvas.style.height = Math.round(display.height) + 'px';
                await page.render({ canvasContext: canvas.getContext('2d'), viewport: page.getViewport({ scale: pageWidth * pixelRatio / page.getViewport({ scale: 1 }).width }) }).promise;
                if (request !== previewRequest) return;
                box.replaceChildren(canvas);
                box.style.minHeight = '0';
            } catch (error) {
                if (request === previewRequest) box.replaceChildren(note('Page ' + pageNumber + ' could not be displayed.'));
            }
        }
        if ('IntersectionObserver' in window) {
            pdfObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting || request !== previewRequest || !pdfObserver) return;
                    pdfObserver.unobserve(entry.target);
                    renderPage(entry.target);
                });
            }, { root: previewContent, rootMargin: '650px 0px' });
            boxes.forEach(function (box) { pdfObserver.observe(box); });
        } else {
            boxes.forEach(function (box) { renderPage(box); });
        }
    }
    async function docxText(file) {
        const buffer = await file.arrayBuffer();
        const view = new DataView(buffer);
        let end = -1;
        for (let offset = buffer.byteLength - 22; offset >= Math.max(0, buffer.byteLength - 65557); offset--) {
            if (view.getUint32(offset, true) === 0x06054b50) { end = offset; break; }
        }
        if (end < 0) throw new Error('DOCX package not readable');
        let entry = view.getUint32(end + 16, true);
        const count = Math.min(view.getUint16(end + 10, true), 10000);
        const decoder = new TextDecoder();
        for (let i = 0; i < count; i++) {
            if (view.getUint32(entry, true) !== 0x02014b50) break;
            const nameLength = view.getUint16(entry + 28, true);
            const extraLength = view.getUint16(entry + 30, true);
            const commentLength = view.getUint16(entry + 32, true);
            const name = decoder.decode(new Uint8Array(buffer, entry + 46, nameLength));
            if (name === 'word/document.xml') {
                const method = view.getUint16(entry + 10, true);
                const compressedSize = view.getUint32(entry + 20, true);
                const uncompressedSize = view.getUint32(entry + 24, true);
                const local = view.getUint32(entry + 42, true);
                if (uncompressedSize > 4 * 1024 * 1024 || compressedSize > 4 * 1024 * 1024) throw new Error('DOCX preview too large');
                const dataStart = local + 30 + view.getUint16(local + 26, true) + view.getUint16(local + 28, true);
                const compressed = new Uint8Array(buffer, dataStart, compressedSize);
                let xmlBytes;
                if (method === 0) xmlBytes = compressed;
                else if (method === 8 && typeof DecompressionStream !== 'undefined') {
                    const stream = new Blob([compressed]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
                    xmlBytes = new Uint8Array(await new Response(stream).arrayBuffer());
                } else throw new Error('DOCX preview not supported in this browser');
                const xml = new DOMParser().parseFromString(decoder.decode(xmlBytes), 'application/xml');
                if (xml.querySelector('parsererror')) throw new Error('DOCX text not readable');
                const paragraphs = Array.from(xml.getElementsByTagNameNS('*', 'p'));
                return paragraphs.map(function (paragraph) {
                    return Array.from(paragraph.getElementsByTagNameNS('*', 't')).map(function (node) { return node.textContent; }).join('');
                }).filter(Boolean).join('\n').slice(0, 30000);
            }
            entry += 46 + nameLength + extraLength + commentLength;
        }
        throw new Error('Document text not found');
    }
    async function showPreview() {
        const file = fileInput.files[0];
        if (!file || !previewPanel || !previewContent) return;
        clearPreviewContent();
        const request = previewRequest;
        previewPanel.classList.remove('d-none');
        previewName.textContent = file.name;
        if (backButton) backButton.focus();
        previewContent.appendChild(note('Opening file preview…'));
        const ext = file.name.split('.').pop().toLowerCase();
        try {
            previewContent.replaceChildren();
            if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'avif'].includes(ext)) {
                previewUrl = URL.createObjectURL(file);
                const stage = document.createElement('div');
                stage.className = 'record-upload-image-stage';
                const image = document.createElement('img');
                image.alt = 'Preview of ' + file.name;
                image.draggable = false;
                image.src = previewUrl;
                image.onerror = function () {
                    if (request !== previewRequest) return;
                    previewContent.replaceChildren(note('This image format cannot be displayed by this browser. Check the selected file before uploading.'));
                    previewImage = null;
                    imageStage = null;
                    updateImageZoom();
                };
                stage.appendChild(image);
                previewContent.appendChild(stage);
                previewImage = image;
                imageStage = stage;
                installImagePan(stage);
                updateImageZoom();
            } else if (ext === 'pdf') {
                await previewPdf(file, request);
            } else if (ext === 'xls' || ext === 'xlsx') {
                await previewExcel(file, request);
            } else if (ext === 'docx') {
                const text = await docxText(file);
                if (request !== previewRequest) return;
                const surface = documentSurface('record-upload-docx');
                surface.appendChild(note('Text preview. Formatting and images may differ from the original.'));
                const body = document.createElement('div');
                body.className = 'preview-text';
                body.textContent = text || 'No readable text found in this document.';
                surface.appendChild(body);
            }
        } catch (error) {
            if (request === previewRequest) {
                previewContent.replaceChildren(note('Preview is unavailable for this file. Please check it on your device before uploading.'));
                previewImage = null;
                zoomTarget = null;
                updateImageZoom();
            }
        }
    }

    zone.addEventListener('click', function (event) {
        if (event.target.closest('button')) return;
        fileInput.click();
    });
    zone.addEventListener('keydown', function (event) {
        if (event.target !== zone || (event.key !== 'Enter' && event.key !== ' ')) return;
        event.preventDefault();
        fileInput.click();
    });
    zone.addEventListener('dragenter', function (event) {
        event.preventDefault();
        dragDepth++;
        zone.classList.add('is-dragging');
    });
    zone.addEventListener('dragover', function (event) {
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
        zone.classList.add('is-dragging');
    });
    zone.addEventListener('dragleave', function (event) {
        event.preventDefault();
        dragDepth = Math.max(0, dragDepth - 1);
        if (dragDepth === 0) zone.classList.remove('is-dragging');
    });
    zone.addEventListener('drop', function (event) {
        event.preventDefault();
        dragDepth = 0;
        zone.classList.remove('is-dragging');
        const files = event.dataTransfer && event.dataTransfer.files;
        if (!files || !files.length) return;
        if (files.length !== 1) { showError(fileError, 'Add one file at a time. Drop just one file here.'); return; }
        try {
            const transfer = new DataTransfer();
            transfer.items.add(files[0]);
            fileInput.files = transfer.files;
            if (fileInput.files.length !== 1) throw new Error('File transfer unavailable');
            fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (error) {
            showError(fileError, 'This browser could not add the dropped file. Select Browse files instead.');
        }
    });
    modal.addEventListener('dragover', function (event) { event.preventDefault(); });
    modal.addEventListener('drop', function (event) {
        if (!zone.contains(event.target)) event.preventDefault();
    });
    fileInput.addEventListener('change', function () {
        closePreview();
        refreshSelectedFile();
    });
    if (removeButton) removeButton.addEventListener('click', function () {
        fileInput.value = '';
        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        hideError(fileError);
        zone.focus();
    });
    if (previewButton) previewButton.addEventListener('click', showPreview);
    if (backButton) backButton.addEventListener('click', function () { closePreview(); if (previewButton) previewButton.focus(); });
    if (zoomInButton) zoomInButton.addEventListener('click', function () { changeImageZoom(0.25); });
    if (zoomOutButton) zoomOutButton.addEventListener('click', function () { changeImageZoom(-0.25); });
    if (zoomResetButton) zoomResetButton.addEventListener('click', function () {
        imageScale = 1; imageX = 0; imageY = 0; updateImageZoom();
    });
    if (previewPanel) previewPanel.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        event.stopPropagation();
        closePreview();
        if (previewButton) previewButton.focus();
    });
    if (confirmation) confirmation.addEventListener('change', function () {
        if (confirmation.checked) hideError(confirmationError);
    });
    form.addEventListener('submit', function (event) {
        hideError(folderError);
        hideError(confirmationError);
        let firstInvalid = null;
        if (!folderInput || !folderInput.value.trim()) {
            showError(folderError, 'Choose the folder where this file belongs.');
            firstInvalid = folderButton;
        }
        if (!fileInput.files.length) {
            if (fileError && fileError.classList.contains('d-none')) showError(fileError, 'Add a file before uploading.');
            if (!firstInvalid) firstInvalid = zone;
        }
        if (confirmation && !confirmation.checked) {
            showError(confirmationError, 'Please confirm where this file will be saved.');
            if (!firstInvalid) firstInvalid = confirmation;
        }
        if (firstInvalid) {
            event.preventDefault();
            firstInvalid.focus();
            firstInvalid.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            return;
        }
        if (submitButton) submitButton.disabled = true;
    });
    modal.addEventListener('hidden.bs.modal', function () {
        closePreview();
        refreshSelectedFile();
        dragDepth = 0;
        zone.classList.remove('is-dragging');
        hideError(folderError);
        hideError(confirmationError);
        if (submitButton) submitButton.disabled = false;
    });
})();
