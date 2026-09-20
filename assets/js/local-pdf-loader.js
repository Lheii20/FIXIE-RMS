(function (global) {
    'use strict';

    const ASSET_ROOT = 'assets/vendor/pdfjs/2.16.105/';
    let enginePromise = null;

    function assetUrl(relativePath) {
        return new URL(ASSET_ROOT + relativePath, document.baseURI).href;
    }

    function getEngine() {
        return global['pdfjs-dist/build/pdf'] || global.pdfjsLib || null;
    }

    function configureEngine(engine) {
        if (!engine || typeof engine.getDocument !== 'function') {
            throw new Error('The local PDF reader did not initialize.');
        }

        if (engine.GlobalWorkerOptions) {
            engine.GlobalWorkerOptions.workerSrc = assetUrl('pdf.worker.min.js');
        }

        return engine;
    }

    function loadEngine() {
        const availableEngine = getEngine();
        if (availableEngine) {
            return Promise.resolve(configureEngine(availableEngine));
        }

        if (enginePromise) {
            return enginePromise;
        }

        enginePromise = new Promise(function (resolve, reject) {
            const script = document.createElement('script');
            script.src = assetUrl('pdf.min.js');
            script.async = true;
            script.dataset.fixieLazyAsset = 'pdfjs';

            script.addEventListener('load', function () {
                try {
                    resolve(configureEngine(getEngine()));
                } catch (error) {
                    reject(error);
                }
            }, { once: true });

            script.addEventListener('error', function () {
                reject(new Error('The local PDF reader could not be loaded.'));
            }, { once: true });

            document.head.appendChild(script);
        }).catch(function (error) {
            enginePromise = null;
            const failedScript = document.querySelector('script[data-fixie-lazy-asset="pdfjs"]');
            if (failedScript && !getEngine()) {
                failedScript.remove();
            }
            throw error;
        });

        return enginePromise;
    }

    global.FixiePDF = Object.freeze({
        load: loadEngine,
        isAvailable: function () {
            return Boolean(getEngine());
        }
    });
})(window);
