<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(404);
    exit;
}

/**
 * Shared local-only script tags for root-level pages. No downloads, database
 * connection, remote fallback, async/defer, or schema changes during a request.
 * Supply dependencies in their required order (e.g. jquery before its plugins).
 */
function drms_frontend_script_tags(array $libraries): string
{
    static $catalog = null;
    static $emitted = [];
    if ($catalog === null) {
        $data = json_decode(file_get_contents(__DIR__ . '/frontend_assets.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($data['schema'] ?? null) !== 1 || !is_array($data['assets'] ?? null)) {
            throw new RuntimeException('Invalid local frontend asset manifest.');
        }
        $catalog = [];
        foreach ($data['assets'] as $asset) {
            if (($asset['kind'] ?? '') === 'script') {
                $catalog[$asset['id']] = $asset;
            }
        }
    }

    $pending = [];
    foreach ($libraries as $id) {
        if (!is_string($id) || !isset($catalog[$id])) {
            throw new InvalidArgumentException('Unknown local frontend script.');
        }
        if (isset($emitted[$id]) || isset($pending[$id])) continue;
        $asset = $catalog[$id];
        $path = (string) ($asset['path'] ?? '');
        $integrity = (string) ($asset['integrity'] ?? '');
        if (!preg_match('~\Aassets/vendor/[a-z0-9./_-]+\.js\z~i', $path) ||
            strpos($path, '..') !== false || !preg_match('~\Asha384-[A-Za-z0-9+/]{64}\z~', $integrity)) {
            throw new RuntimeException('Invalid local frontend script definition.');
        }
        if (!is_file(dirname(__DIR__) . '/' . $path)) {
            throw new RuntimeException('Local frontend assets are missing. Run the Phase 5M6A asset installer.');
        }
        $pending[$id] = '<script src="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') .
            '" integrity="' . htmlspecialchars($integrity, ENT_QUOTES, 'UTF-8') .
            '" crossorigin="anonymous"></script>';
    }
    foreach ($pending as $id => $_) $emitted[$id] = true;
    return implode("\n    ", $pending);
}

/** Local CSS links, emitted in the caller's existing cascade order. */
function drms_frontend_style_tags(array $libraries): string
{
    static $catalog = null;
    static $emitted = [];
    if ($catalog === null) {
        $data = json_decode(file_get_contents(__DIR__ . '/frontend_assets.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($data['schema'] ?? null) !== 1 || !is_array($data['assets'] ?? null)) {
            throw new RuntimeException('Invalid local frontend asset manifest.');
        }
        $catalog = [];
        foreach ($data['assets'] as $asset) {
            if (($asset['kind'] ?? '') === 'style') $catalog[$asset['id']] = $asset;
        }
    }
    $pending = [];
    foreach ($libraries as $id) {
        if (!is_string($id) || !isset($catalog[$id])) throw new InvalidArgumentException('Unknown local frontend stylesheet.');
        if (isset($emitted[$id]) || isset($pending[$id])) continue;
        $asset = $catalog[$id];
        $path = (string) ($asset['path'] ?? '');
        $integrity = (string) ($asset['integrity'] ?? '');
        if (!preg_match('~\Aassets/vendor/[a-z0-9./_-]+\.css\z~i', $path) || strpos($path, '..') !== false ||
            !preg_match('~\Asha384-[A-Za-z0-9+/]{64}\z~', $integrity)) {
            throw new RuntimeException('Invalid local frontend stylesheet definition.');
        }
        if (!is_file(dirname(__DIR__) . '/' . $path)) throw new RuntimeException('Local frontend styles are missing. Run the asset installer.');
        $pending[$id] = '<link rel="stylesheet" href="' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8') .
            '" integrity="' . htmlspecialchars($integrity, ENT_QUOTES, 'UTF-8') . '" crossorigin="anonymous">';
    }
    foreach ($pending as $id => $_) $emitted[$id] = true;
    return implode("\n    ", $pending);
}
