(function () {
    'use strict';

    const mobileQuery = window.matchMedia('(max-width: 767.98px)');
    const modal = document.getElementById('documentViewerModal');
    let viewportSyncFrame = 0;

    function applyMobileVisualViewport() {
        viewportSyncFrame = 0;

        if (!mobileQuery.matches) {
            document.documentElement.style.removeProperty('--fixie-mobile-viewport-height');
            return;
        }

        const viewportHeight = window.visualViewport
            ? window.visualViewport.height
            : window.innerHeight;

        document.documentElement.style.setProperty(
            '--fixie-mobile-viewport-height',
            `${Math.round(viewportHeight)}px`
        );
    }

    function syncMobileVisualViewport() {
        if (viewportSyncFrame) return;
        viewportSyncFrame = window.requestAnimationFrame(applyMobileVisualViewport);
    }

    syncMobileVisualViewport();
    window.addEventListener('resize', syncMobileVisualViewport, { passive: true });
    window.addEventListener('orientationchange', syncMobileVisualViewport, { passive: true });

    if (typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', syncMobileVisualViewport);
    } else if (typeof mobileQuery.addListener === 'function') {
        mobileQuery.addListener(syncMobileVisualViewport);
    }

    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', syncMobileVisualViewport, { passive: true });
        window.visualViewport.addEventListener('scroll', syncMobileVisualViewport, { passive: true });
    }

    if (!modal) return;

    const surface = modal.querySelector('.modal-body');
    const wrapper = document.getElementById('viewerContentWrapper');
    const image = document.getElementById('documentViewerImage');

    if (!surface || !wrapper || !image) return;

    let scale = 1;
    let panX = 0;
    let panY = 0;
    let gesture = null;
    let startDistance = 0;
    let startScale = 1;
    let startPanX = 0;
    let startPanY = 0;
    let startTouchX = 0;
    let startTouchY = 0;
    let startMidX = 0;
    let startMidY = 0;
    let gestureMoved = false;
    let lastTapAt = 0;

    function imageIsActive() {
        return mobileQuery.matches && image.src && window.getComputedStyle(image).display !== 'none';
    }

    function distance(touchA, touchB) {
        return Math.hypot(
            touchB.clientX - touchA.clientX,
            touchB.clientY - touchA.clientY
        );
    }

    function midpoint(touchA, touchB) {
        return {
            x: (touchA.clientX + touchB.clientX) / 2,
            y: (touchA.clientY + touchB.clientY) / 2
        };
    }

    function clamp(value, minimum, maximum) {
        return Math.min(maximum, Math.max(minimum, value));
    }

    function clampPan() {
        if (scale <= 1) {
            panX = 0;
            panY = 0;
            return;
        }

        const surfaceRect = surface.getBoundingClientRect();
        const maxX = Math.max(0, (surfaceRect.width * (scale - 1)) / 2 + 24);
        const maxY = Math.max(0, (surfaceRect.height * (scale - 1)) / 2 + 24);

        panX = clamp(panX, -maxX, maxX);
        panY = clamp(panY, -maxY, maxY);
    }

    function applyTransform(animate) {
        clampPan();
        wrapper.style.transition = animate ? 'transform 0.18s ease' : 'none';
        wrapper.style.transformOrigin = 'center center';
        wrapper.style.transform = `translate3d(${panX}px, ${panY}px, 0) scale(${scale})`;
    }

    function resetViewer(animate) {
        scale = 1;
        panX = 0;
        panY = 0;
        gesture = null;
        applyTransform(Boolean(animate));
    }

    surface.addEventListener('touchstart', function (event) {
        if (!imageIsActive()) return;

        gestureMoved = false;

        if (event.touches.length === 2) {
            event.preventDefault();
            gesture = 'pinch';
            startDistance = distance(event.touches[0], event.touches[1]);
            startScale = scale;
            startPanX = panX;
            startPanY = panY;

            const mid = midpoint(event.touches[0], event.touches[1]);
            startMidX = mid.x;
            startMidY = mid.y;
            wrapper.style.transition = 'none';
            return;
        }

        if (event.touches.length === 1 && scale > 1) {
            event.preventDefault();
            gesture = 'pan';
            startTouchX = event.touches[0].clientX;
            startTouchY = event.touches[0].clientY;
            startPanX = panX;
            startPanY = panY;
            wrapper.style.transition = 'none';
        }
    }, { passive: false });

    surface.addEventListener('touchmove', function (event) {
        if (!imageIsActive()) return;

        if (gesture === 'pinch' && event.touches.length === 2) {
            event.preventDefault();

            const newDistance = distance(event.touches[0], event.touches[1]);
            const mid = midpoint(event.touches[0], event.touches[1]);

            if (startDistance > 0) {
                scale = clamp(startScale * (newDistance / startDistance), 1, 4);
            }

            panX = startPanX + (mid.x - startMidX);
            panY = startPanY + (mid.y - startMidY);
            gestureMoved = true;
            applyTransform(false);
            return;
        }

        if (gesture === 'pan' && event.touches.length === 1 && scale > 1) {
            event.preventDefault();
            panX = startPanX + (event.touches[0].clientX - startTouchX);
            panY = startPanY + (event.touches[0].clientY - startTouchY);
            gestureMoved = true;
            applyTransform(false);
        }
    }, { passive: false });

    surface.addEventListener('touchend', function (event) {
        if (!imageIsActive()) return;

        if (event.touches.length === 1 && gesture === 'pinch') {
            gesture = scale > 1 ? 'pan' : null;
            startTouchX = event.touches[0].clientX;
            startTouchY = event.touches[0].clientY;
            startPanX = panX;
            startPanY = panY;
            return;
        }

        if (event.touches.length > 0) return;

        gesture = null;
        applyTransform(true);

        if (!gestureMoved && event.changedTouches.length === 1) {
            const now = Date.now();

            if (now - lastTapAt < 280) {
                if (scale > 1) {
                    resetViewer(true);
                } else {
                    scale = 2;
                    panX = 0;
                    panY = 0;
                    applyTransform(true);
                }
                lastTapAt = 0;
            } else {
                lastTapAt = now;
            }
        }
    }, { passive: false });

    surface.addEventListener('touchcancel', function () {
        gesture = null;
        applyTransform(true);
    }, { passive: true });

    image.addEventListener('dragstart', function (event) {
        event.preventDefault();
    });

    modal.addEventListener('shown.bs.modal', function () {
        if (mobileQuery.matches) resetViewer(false);
    });

    modal.addEventListener('hidden.bs.modal', function () {
        resetViewer(false);
    });

    window.addEventListener('resize', function () {
        if (!mobileQuery.matches || scale <= 1) return;
        applyTransform(false);
    });
})();
