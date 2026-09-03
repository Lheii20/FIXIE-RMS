[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\xampp\htdocs\fixie_drms',
    [string]$PhpExecutable = 'C:\xampp\php\php.exe',
    [string]$NodeExecutable = 'node'
)
$ErrorActionPreference = 'Stop'
$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpExecutable).Path
$resolvedNode = (Get-Command $NodeExecutable -CommandType Application -ErrorAction Stop).Source
foreach ($relative in @('audit_logs.php', 'config\frontend_assets.php', 'config\frontend_assets.json', 'scripts\install_phase_5m6a_assets.js')) {
    if (-not (Test-Path -LiteralPath (Join-Path $resolvedProject $relative) -PathType Leaf)) { throw "Missing Phase 5M6A file: $relative" }
}
foreach ($relative in @('audit_logs.php', 'config\frontend_assets.php')) {
    & $resolvedPhp -l (Join-Path $resolvedProject $relative)
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax error: $relative" }
}
$installer = Join-Path $resolvedProject 'scripts\install_phase_5m6a_assets.js'
& $resolvedNode --check $installer
if ($LASTEXITCODE -ne 0) { throw 'Installer syntax check failed.' }
& $resolvedNode $installer --project-root $resolvedProject --check
if ($LASTEXITCODE -ne 0) { throw 'Missing or modified local assets. Run the installer before replacing the Audit Trail page.' }

$page = Get-Content -LiteralPath (Join-Path $resolvedProject 'audit_logs.php') -Raw
if ($page -notmatch 'drms_frontend_script_tags\(\[''jquery'', ''bootstrap'', ''xlsx''\]\)' -or $page -notmatch 'config/frontend_assets\.php') {
    throw 'The Phase 5M6A Audit Trail include has not been installed.'
}
if ($page -match '<script\b[^>]*\bsrc\s*=\s*["''](?:https?:)?//') { throw 'Audit Trail still contains a remote script tag.' }
if ($page -match '<script\b[^>]*\bsrc\s*=\s*["''][^"'']*(?:jquery|bootstrap|xlsx)') { throw 'A duplicate library script tag exists outside the shared include.' }

$probe = @'
<?php
require getenv('DRMS_FRONTEND_VERIFY_ROOT') . '/config/frontend_assets.php';
$html = drms_frontend_script_tags(['jquery', 'bootstrap', 'xlsx']);
if (substr_count($html, '<script ') !== 3 || substr_count($html, 'integrity="sha384-') !== 3 || strpos($html, 'https://') !== false) exit(2);
if (!(strpos($html, 'jquery.min.js') < strpos($html, 'bootstrap.bundle.min.js') && strpos($html, 'bootstrap.bundle.min.js') < strpos($html, 'xlsx.full.min.js'))) exit(3);
if (drms_frontend_script_tags(['jquery', 'bootstrap', 'xlsx']) !== '') exit(4);
try { drms_frontend_script_tags(['unknown-script']); exit(5); } catch (InvalidArgumentException $expected) {}
echo "PASS: local-only tags, pinned integrity, load order, deduplication and unknown-ID guard.\n";
'@
# Windows PowerShell 5.1 rewrites quotes in native command-line arguments.
# Send the PHP source through stdin instead; keep the project path out of code.
# Restore the caller's environment and encoding even if the probe fails.
$previousVerifyRoot = $env:DRMS_FRONTEND_VERIFY_ROOT
$previousOutputEncoding = $OutputEncoding
$probeExitCode = 1
try {
    $env:DRMS_FRONTEND_VERIFY_ROOT = $resolvedProject
    $OutputEncoding = New-Object System.Text.UTF8Encoding($false)
    $probe | & $resolvedPhp
    $probeExitCode = $LASTEXITCODE
} finally {
    $env:DRMS_FRONTEND_VERIFY_ROOT = $previousVerifyRoot
    $OutputEncoding = $previousOutputEncoding
}
if ($probeExitCode -ne 0) { throw "Local script renderer regression check failed (PHP exit code $probeExitCode)." }

Write-Host ''
Write-Host 'Phase 5M6A checks passed (read-only, no network).'
Write-Host 'Scope: Audit Trail JavaScript only. Fonts, other pages and actual signed-in offline export need their separate checks.'
