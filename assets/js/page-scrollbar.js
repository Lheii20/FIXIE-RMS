(() => {
    'use strict';

    if (window.__drmsPageScrollbar || !document.body || !window.matchMedia) return;
    window.__drmsPageScrollbar = true;

    const media = window.matchMedia('(min-width: 768px) and (pointer: fine)');
    const rail = document.createElement('div');
    rail.className = 'drms-page-scrollbar';
    rail.setAttribute('aria-hidden', 'true');
    rail.innerHTML = '<div class="drms-page-scrollbar__thumb"></div>';
    document.body.appendChild(rail);
    const thumb = rail.firstElementChild;
    let frame = 0;
    let maxScroll = 0;
    let thumbTravel = 0;
    let thumbHeight = 0;
    let drag = null;

    function scrollingElement() {
        return document.scrollingElement || document.documentElement;
    }

    function update() {
        frame = 0;
        if (!media.matches || document.body.classList.contains('modal-open') ||
            document.body.classList.contains('swal2-shown')) {
            rail.hidden = true;
            return;
        }
        const page = scrollingElement();
        const viewport = page.clientHeight || window.innerHeight;
        const documentHeight = Math.max(page.scrollHeight, document.body.scrollHeight);
        maxScroll = Math.max(0, documentHeight - viewport);
        const railHeight = rail.clientHeight;
        if (maxScroll <= 2 || railHeight <= 0) {
            rail.hidden = true;
            return;
        }
        rail.hidden = false;
        thumbHeight = Math.min(railHeight, Math.max(32, Math.round(railHeight * viewport / documentHeight)));
        thumbTravel = Math.max(0, railHeight - thumbHeight);
        thumb.style.height = thumbHeight + 'px';
        thumb.style.transform = 'translate3d(0,' +
            Math.round((page.scrollTop / maxScroll) * thumbTravel) + 'px,0)';
    }

    function schedule() {
        if (!frame) frame = window.requestAnimationFrame(update);
    }

    function syncMode() {
        document.documentElement.classList.toggle('drms-overlay-scroll-ready', media.matches);
        schedule();
    }

    rail.addEventListener('pointerdown', (event) => {
        if (rail.hidden || !maxScroll || !thumbTravel) return;
        event.preventDefault();
        const page = scrollingElement();
        if (event.target === thumb) {
            drag = { pointerId: event.pointerId, y: event.clientY, scrollTop: page.scrollTop };
            rail.setPointerCapture(event.pointerId);
            return;
        }
        const offset = event.clientY - rail.getBoundingClientRect().top - thumbHeight / 2;
        page.scrollTop = Math.max(0, Math.min(maxScroll, (offset / thumbTravel) * maxScroll));
        schedule();
    });

    rail.addEventListener('pointermove', (event) => {
        if (!drag || drag.pointerId !== event.pointerId) return;
        scrollingElement().scrollTop = drag.scrollTop +
            ((event.clientY - drag.y) / thumbTravel) * maxScroll;
        schedule();
    });

    function stopDrag(event) {
        if (!drag || drag.pointerId !== event.pointerId) return;
        drag = null;
        if (rail.hasPointerCapture(event.pointerId)) rail.releasePointerCapture(event.pointerId);
    }
    rail.addEventListener('pointerup', stopDrag);
    rail.addEventListener('pointercancel', stopDrag);

    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule, { passive: true });
    window.addEventListener('load', schedule, { once: true });
    document.addEventListener('DOMContentLoaded', schedule, { once: true });
    if (media.addEventListener) media.addEventListener('change', syncMode);
    else media.addListener(syncMode);
    if (window.ResizeObserver) {
        const observer = new ResizeObserver(schedule);
        observer.observe(document.body);
        observer.observe(document.documentElement);
    }
    const lockObserver = new MutationObserver(schedule);
    lockObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    syncMode();
})();
