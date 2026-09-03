'use strict';

// Node.js 18+ built-ins only. Installs version-locked libraries and their
// licenses, not PHP application code. Existing mismatched files are refused.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const args = process.argv.slice(2);
const checkOnly = args.includes('--check');
const rootIndex = args.indexOf('--project-root');
let projectRoot;
const hash = (buffer, algorithm = 'sha256', encoding = 'hex') => crypto.createHash(algorithm).update(buffer).digest(encoding);

function safeTarget(relative) {
    if (typeof relative !== 'string' || !/^assets\/vendor\/[a-z0-9./_-]+$/i.test(relative) || relative.includes('..')) {
        throw new Error('Unsafe asset destination in manifest.');
    }
    const target = path.resolve(projectRoot, relative);
    if (!target.startsWith(projectRoot + path.sep)) throw new Error('Asset path escapes the project.');
    // Do not follow junctions/symlinks in assets, vendor, or the target file.
    let current = projectRoot;
    for (const part of relative.split('/')) {
        current = path.join(current, part);
        try {
            if (fs.lstatSync(current).isSymbolicLink()) throw new Error('Linked asset path refused: ' + relative);
        } catch (error) {
            if (error.code !== 'ENOENT') throw error;
        }
    }
    return target;
}

function validateBytes(asset, buffer) {
    if (buffer.length !== asset.bytes || hash(buffer) !== asset.sha256) {
        throw new Error('Checksum/size mismatch: ' + asset.path + '. No overwrite performed.');
    }
    if (['script', 'style'].includes(asset.kind) && asset.integrity !== 'sha384-' + hash(buffer, 'sha384', 'base64')) {
        throw new Error('Browser integrity mismatch: ' + asset.path);
    }
}

async function main() {
    if (rootIndex >= 0 && !args[rootIndex + 1]) throw new Error('--project-root requires a folder path.');
    projectRoot = fs.realpathSync(rootIndex < 0 ? path.resolve(__dirname, '..') : args[rootIndex + 1]);
    const manifestPath = path.join(projectRoot, 'config', 'frontend_assets.json');
    if (!fs.existsSync(manifestPath)) throw new Error('Copy config/frontend_assets.json before running this installer.');
    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
    if (manifest.schema !== 1 || !Array.isArray(manifest.assets) || !manifest.assets.length) throw new Error('Invalid asset manifest.');
    const pending = [];
    const seen = new Set();
    // Complete existing-file preflight before downloading or writing anything.
    for (const asset of manifest.assets) {
        const target = safeTarget(asset.path);
        if (seen.has(target.toLowerCase())) throw new Error('Duplicate asset destination.');
        seen.add(target.toLowerCase());
        const url = new URL(asset.url);
        if (url.protocol !== 'https:' || !['code.jquery.com', 'cdn.jsdelivr.net', 'cdn.datatables.net'].includes(url.hostname) || url.username || url.password) {
            throw new Error('Unapproved asset source.');
        }
        if (!Number.isInteger(asset.bytes) || asset.bytes <= 0 || asset.bytes > 2000000 || !/^[a-f0-9]{64}$/.test(asset.sha256)) {
            throw new Error('Invalid size/hash in asset manifest.');
        }
        if (fs.existsSync(target)) {
            validateBytes(asset, fs.readFileSync(target));
            console.log('PASS existing: ' + asset.path);
        } else {
            pending.push({ asset, target });
        }
    }
    if (checkOnly) {
        if (pending.length) throw new Error('Missing assets: ' + pending.map(item => item.asset.path).join(', '));
        console.log('All ' + manifest.phase + ' asset hashes and licenses verified. No network requests or writes.');
        return;
    }
    if (pending.length && (typeof fetch !== 'function' || typeof AbortSignal.timeout !== 'function')) {
        throw new Error('Node.js 18 or newer is needed for the one-time HTTPS download.');
    }
    // Download and verify every missing file before publishing any of them.
    for (const item of pending) {
        const response = await fetch(item.asset.url, { redirect: 'error', signal: AbortSignal.timeout(30000) });
        if (!response.ok) throw new Error('Download failed with HTTP ' + response.status + ': ' + item.asset.id);
        item.buffer = Buffer.from(await response.arrayBuffer());
        validateBytes(item.asset, item.buffer);
        console.log('Verified download: ' + item.asset.id);
    }
    for (const item of pending) {
        safeTarget(item.asset.path);
        fs.mkdirSync(path.dirname(item.target), { recursive: true });
        fs.writeFileSync(item.target, item.buffer, { flag: 'wx' });
        console.log('Installed: ' + item.asset.path);
    }
    console.log('Phase ' + manifest.phase + ' assets ready. PHP files, records and database were not changed.');
}

main().catch(error => { console.error('STOP: ' + error.message); process.exitCode = 1; });
