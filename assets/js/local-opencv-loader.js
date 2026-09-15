(function (global) {
    'use strict';

    const SCRIPT_PATH = 'assets/vendor/opencv/4.8.0/opencv.js';
    const RUNTIME_WAIT_MS = 30000;
    let enginePromise = null;
    let scriptElement = null;

    function getIfReady() {
        return global.cv && global.cv.Mat ? global.cv : null;
    }

    function waitForRuntime() {
        if (getIfReady()) {
            return Promise.resolve(true);
        }

        return new Promise(function (resolve, reject) {
            const startedAt = Date.now();
            let settled = false;

            function finishReady(module) {
                if (settled) return;

                const resolvedModule = module && module.Mat ? module : getIfReady();
                if (!resolvedModule) return;

                settled = true;
                global.cv = resolvedModule;
                resolve(true);
            }

            function poll() {
                if (settled) return;
                if (getIfReady()) {
                    finishReady(global.cv);
                    return;
                }

                if (Date.now() - startedAt >= RUNTIME_WAIT_MS) {
                    settled = true;
                    reject(new Error('The local OpenCV runtime did not become ready in time.'));
                    return;
                }

                global.setTimeout(poll, 50);
            }

            const candidate = global.cv;
            if (candidate && typeof candidate.then === 'function') {
                try {
                    candidate.then(finishReady);
                } catch (error) {
                    // Some builds expose a temporary thenable before runtime
                    // initialization. Polling below still detects readiness.
                }
            }

            poll();
        });
    }

    function beginLoad() {
        if (getIfReady()) {
            return Promise.resolve(true);
        }

        if (enginePromise) {
            return enginePromise;
        }

        enginePromise = new Promise(function (resolve, reject) {
            scriptElement = document.createElement('script');
            scriptElement.src = new URL(SCRIPT_PATH, document.baseURI).href;
            scriptElement.async = true;
            scriptElement.dataset.fixieOpenCv = 'local';
            scriptElement.onload = function () {
                waitForRuntime().then(resolve, reject);
            };
            scriptElement.onerror = function () {
                reject(new Error('The local OpenCV asset could not be loaded.'));
            };
            document.head.appendChild(scriptElement);
        }).catch(function (error) {
            enginePromise = null;
            if (scriptElement && scriptElement.parentNode) {
                scriptElement.parentNode.removeChild(scriptElement);
            }
            scriptElement = null;
            throw error;
        });

        return enginePromise;
    }

    function withTimeout(promise, timeoutMs) {
        return new Promise(function (resolve, reject) {
            let settled = false;
            const timer = global.setTimeout(function () {
                if (settled) return;
                settled = true;
                reject(new Error('The local OpenCV engine is still loading.'));
            }, timeoutMs);

            promise.then(function (value) {
                if (settled) return;
                settled = true;
                global.clearTimeout(timer);
                resolve(value);
            }, function (error) {
                if (settled) return;
                settled = true;
                global.clearTimeout(timer);
                reject(error);
            });
        });
    }

    function load(options) {
        const requestedTimeout = options && Number(options.timeoutMs);
        const timeoutMs = Number.isFinite(requestedTimeout) && requestedTimeout > 0
            ? requestedTimeout
            : 5000;

        return withTimeout(beginLoad(), timeoutMs);
    }

    function preload() {
        beginLoad().catch(function (error) {
            console.warn('Local OpenCV preload failed. Normal camera crop remains available.', error);
        });
    }

    global.FixieOpenCV = Object.freeze({
        getIfReady: getIfReady,
        load: load,
        preload: preload
    });
}(window));
