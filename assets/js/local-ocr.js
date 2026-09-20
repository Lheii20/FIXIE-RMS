(function (global) {
    'use strict';

    const ASSET_ROOT = 'assets/vendor/tesseract/5.1.1/';
    const DEFAULT_TIMEOUT_MS = 60000;
    let enginePromise = null;

    function assetUrl(relativePath) {
        return new URL(ASSET_ROOT + relativePath, document.baseURI).href;
    }

    function withTimeout(promise, timeoutMs, message, onLateResolve) {
        return new Promise(function (resolve, reject) {
            let settled = false;
            const timerId = global.setTimeout(function () {
                if (settled) return;
                settled = true;
                reject(new Error(message));
            }, timeoutMs);

            Promise.resolve(promise).then(function (value) {
                if (settled) {
                    if (typeof onLateResolve === 'function') {
                        Promise.resolve(onLateResolve(value)).catch(function () {});
                    }
                    return;
                }

                settled = true;
                global.clearTimeout(timerId);
                resolve(value);
            }, function (error) {
                if (settled) return;
                settled = true;
                global.clearTimeout(timerId);
                reject(error instanceof Error ? error : new Error(String(error || 'OCR operation failed.')));
            });
        });
    }

    async function terminateWorker(worker) {
        if (!worker || typeof worker.terminate !== 'function') return;

        try {
            await worker.terminate();
        } catch (error) {
            console.warn('Unable to terminate the local OCR worker cleanly.', error);
        }
    }

    function isAvailable() {
        return Boolean(global.Tesseract && typeof global.Tesseract.createWorker === 'function');
    }

    function loadEngine() {
        if (isAvailable()) {
            return Promise.resolve(global.Tesseract);
        }

        if (enginePromise) {
            return enginePromise;
        }

        enginePromise = new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            script.src = assetUrl('tesseract.min.js');
            script.async = true;
            script.dataset.fixieLazyAsset = 'tesseract';

            script.addEventListener('load', function () {
                if (isAvailable()) {
                    resolve(global.Tesseract);
                    return;
                }

                reject(new Error('The local OCR engine did not initialize.'));
            }, { once: true });

            script.addEventListener('error', function () {
                reject(new Error('The local OCR engine could not be loaded.'));
            }, { once: true });

            document.head.appendChild(script);
        }).catch(function (error) {
            enginePromise = null;
            const failedScript = document.querySelector('script[data-fixie-lazy-asset="tesseract"]');
            if (failedScript && !isAvailable()) {
                failedScript.remove();
            }
            throw error;
        });

        return enginePromise;
    }

    async function recognize(image, options) {
        const settings = options && typeof options === 'object' ? options : {};
        const timeoutMs = Number.isFinite(settings.timeoutMs) && settings.timeoutMs >= 5000
            ? settings.timeoutMs
            : DEFAULT_TIMEOUT_MS;

        const tesseract = await loadEngine();

        let worker = null;
        const workerPromise = tesseract.createWorker('eng', 1, {
            workerPath: assetUrl('worker.min.js'),
            corePath: assetUrl('core/'),
            langPath: assetUrl('lang/'),
            workerBlobURL: false,
            gzip: true,
            logger: typeof settings.onProgress === 'function' ? settings.onProgress : function () {},
            errorHandler: function (error) {
                console.warn('Local OCR worker reported an error.', error);
            }
        });

        try {
            worker = await withTimeout(
                workerPromise,
                timeoutMs,
                'Local OCR initialization timed out.',
                terminateWorker
            );

            const result = await withTimeout(
                worker.recognize(image),
                timeoutMs,
                'Local OCR recognition timed out.'
            );

            return result && result.data && typeof result.data.text === 'string'
                ? result.data.text
                : '';
        } finally {
            await terminateWorker(worker);
        }
    }

    global.FixieLocalOCR = Object.freeze({
        load: loadEngine,
        recognize: recognize,
        isAvailable: isAvailable
    });
})(window);
