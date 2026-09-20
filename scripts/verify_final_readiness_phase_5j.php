<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$errors = [];

function phase5jRead(string $path, array &$errors): string
{
    if (!is_file($path)) {
        $errors[] = 'Missing required file: ' . str_replace('\\', '/', $path);
        return '';
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        $errors[] = 'Unable to read: ' . str_replace('\\', '/', $path);
        return '';
    }

    return $contents;
}

function phase5jRequire(string $contents, string $needle, string $label, array &$errors): void
{
    if (strpos($contents, $needle) === false) {
        $errors[] = $label;
    }
}

function phase5jReject(string $contents, string $needle, string $label, array &$errors): void
{
    if (strpos($contents, $needle) !== false) {
        $errors[] = $label;
    }
}

$htaccess = phase5jRead($projectRoot . DIRECTORY_SEPARATOR . '.htaccess', $errors);
$localOcr = phase5jRead($projectRoot . '/assets/js/local-ocr.js', $errors);
$localPdf = phase5jRead($projectRoot . '/assets/js/local-pdf-loader.js', $errors);

phase5jRequire($htaccess, '<IfModule mod_deflate.c>', '.htaccess is missing conditional response compression.', $errors);
phase5jRequire($htaccess, 'Cache-Control "public, max-age=604800"', '.htaccess is missing the CSS/JavaScript cache policy.', $errors);
phase5jRequire($htaccess, 'Cache-Control "public, max-age=2592000"', '.htaccess is missing the image/font cache policy.', $errors);
phase5jReject($htaccess, 'FilesMatch "\\.(?:php', '.htaccess must not cache dynamic PHP responses.', $errors);

phase5jRequire($localOcr, 'function loadEngine()', 'The local OCR loader is missing its on-demand engine loader.', $errors);
phase5jRequire($localOcr, "script.dataset.fixieLazyAsset = 'tesseract';", 'The local OCR loader is missing its lazy-load marker.', $errors);
phase5jRequire($localOcr, 'const tesseract = await loadEngine();', 'OCR recognition is not waiting for the on-demand engine.', $errors);
phase5jRequire($localPdf, 'function loadEngine()', 'The local PDF loader is missing its on-demand engine loader.', $errors);
phase5jRequire($localPdf, "script.dataset.fixieLazyAsset = 'pdfjs';", 'The local PDF loader is missing its lazy-load marker.', $errors);
phase5jRequire($localPdf, "engine.GlobalWorkerOptions.workerSrc = assetUrl('pdf.worker.min.js');", 'The PDF worker is not configured to use the local vendor asset.', $errors);

foreach (['general_docs.php', 'documents.php'] as $pageName) {
    $page = phase5jRead($projectRoot . DIRECTORY_SEPARATOR . $pageName, $errors);
    phase5jRequire($page, 'assets/js/local-ocr.js?v=', $pageName . ' is missing the versioned local OCR loader.', $errors);
    phase5jRequire($page, 'assets/js/local-pdf-loader.js?v=', $pageName . ' is missing the versioned local PDF loader.', $errors);
    phase5jRequire($page, "typeof window.FixieLocalOCR.recognize !== 'function'", $pageName . ' is missing the lazy-compatible OCR availability check.', $errors);
    phase5jRequire($page, 'await window.FixiePDF.load();', $pageName . ' is not loading PDF.js on demand.', $errors);
    phase5jReject($page, '<script src="assets/vendor/tesseract/5.1.1/tesseract.min.js"></script>', $pageName . ' still loads Tesseract during initial page rendering.', $errors);
    phase5jReject($page, '<script src="assets/vendor/pdfjs/2.16.105/pdf.min.js"></script>', $pageName . ' still loads PDF.js during initial page rendering.', $errors);
    phase5jReject($page, "window['pdfjs-dist/build/pdf']", $pageName . ' still uses the eager PDF.js access path.', $errors);
}

$deferredBytes = 0;
foreach ([
    $projectRoot . '/assets/vendor/tesseract/5.1.1/tesseract.min.js',
    $projectRoot . '/assets/vendor/pdfjs/2.16.105/pdf.min.js',
] as $assetPath) {
    if (is_file($assetPath)) {
        $deferredBytes += (int) filesize($assetPath);
    } else {
        $errors[] = 'Missing vendor asset required for on-demand loading: ' . str_replace('\\', '/', $assetPath);
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Phase 5J verification FAILED:\n\n");
    foreach ($errors as $error) {
        fwrite(STDERR, '- ' . $error . "\n");
    }
    exit(1);
}

echo "Phase 5J verification PASSED.\n";
echo '- Initial Company Files and Official Records rendering now defers '
    . number_format($deferredBytes)
    . " bytes of OCR/PDF engine code until document analysis is requested.\n";
echo "- Static browser caching is enabled without caching dynamic PHP responses.\n";
echo "- Compression remains hosting-compatible through a conditional Apache rule.\n";
