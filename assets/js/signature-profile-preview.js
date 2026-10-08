(() => {
  'use strict';

  const previews = document.querySelectorAll('[data-esign-preview]');
  const input = document.querySelector('[data-esign-image-input]');
  const note = document.querySelector('[data-esign-preview-note]');
  let objectUrl = null;

  function fallback(container, text) {
    const label = document.createElement('span');
    label.className = 'drms-esign-image__empty';
    label.textContent = text;
    container.replaceChildren(label);
  }

  function observeSavedImage(container) {
    const image = container.querySelector('[data-esign-current-image]');
    if (!image) return;
    const failed = () => {
      fallback(container, 'Saved image unavailable. Upload a replacement.');
      if (note) note.textContent = 'The saved image could not be displayed. Select a replacement, then save with your password.';
    };
    image.addEventListener('error', failed, {once: true});
    if (image.complete && image.naturalWidth === 0) failed();
  }

  previews.forEach(observeSavedImage);
  if (!input) return;

  const preview = input.closest('.row')?.querySelector('[data-esign-preview]');
  if (!preview) return;
  const original = preview.cloneNode(true);
  const originalNote = note?.textContent || '';
  const allowed = new Set(['image/jpeg', 'image/png', 'image/webp']);

  function releaseObjectUrl() {
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = null;
  }

  input.addEventListener('change', () => {
    releaseObjectUrl();
    const file = input.files?.[0];
    if (!file) {
      preview.replaceChildren(...Array.from(original.childNodes, child => child.cloneNode(true)));
      observeSavedImage(preview);
      if (note) note.textContent = originalNote;
      return;
    }
    if (!allowed.has(file.type) || file.size > 2 * 1024 * 1024 || file.size === 0) {
      fallback(preview, 'Choose a JPG, PNG, or WebP image up to 2 MB.');
      if (note) note.textContent = 'This file cannot be used as a visual signature.';
      return;
    }

    objectUrl = URL.createObjectURL(file);
    const image = document.createElement('img');
    image.alt = 'Selected signature image preview';
    image.addEventListener('load', () => {
      if (note) note.textContent = 'Preview only. Enter your password and save to replace the current image.';
    }, {once: true});
    image.addEventListener('error', () => {
      fallback(preview, 'This image could not be previewed. Choose another file.');
      if (note) note.textContent = 'The selected image could not be decoded.';
      releaseObjectUrl();
    }, {once: true});
    preview.replaceChildren(image);
    image.src = objectUrl;
  });

  window.addEventListener('pagehide', releaseObjectUrl, {once: true});
})();
