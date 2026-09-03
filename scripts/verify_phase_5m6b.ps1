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
$pages = @('po_list.php', 'pr_list.php', 'quotations_list.php')
foreach ($relative in ($pages + @('config\frontend_assets.php', 'config\frontend_assets.json', 'scripts\install_phase_5m6a_assets.js'))) {
    if (-not (Test-Path -LiteralPath (Join-Path $resolvedProject $relative) -PathType Leaf)) { throw "Missing Phase 5M6B file: $relative" }
}
foreach ($relative in ($pages + @('config\frontend_assets.php'))) {
    & $resolvedPhp -l (Join-Path $resolvedProject $relative)
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax error: $relative" }
}
$installer = Join-Path $resolvedProject 'scripts\install_phase_5m6a_assets.js'
& $resolvedNode --check $installer
if ($LASTEXITCODE -ne 0) { throw 'Installer syntax error.' }
& $resolvedNode $installer --project-root $resolvedProject --check
if ($LASTEXITCODE -ne 0) { throw 'Local asset checks failed. Install the updated manifest and shared installer first.' }

$manifest = Get-Content -LiteralPath (Join-Path $resolvedProject 'config\frontend_assets.json') -Raw | ConvertFrom-Json
foreach ($id in @('jquery','bootstrap','xlsx','datatables','datatables-bs5','datatables-bs5-css','sweetalert2','sweetalert2-css')) {
    if (@($manifest.assets | Where-Object { $_.id -eq $id }).Count -ne 1) { throw "Missing or duplicate manifest asset: $id" }
}
foreach ($asset in $manifest.assets) {
    if ($asset.kind -eq 'script') {
        & $resolvedNode --check (Join-Path $resolvedProject $asset.path)
        if ($LASTEXITCODE -ne 0) { throw "Library syntax error: $($asset.path)" }
    }
    if ($asset.kind -eq 'style') {
        $css = Get-Content -LiteralPath (Join-Path $resolvedProject $asset.path) -Raw
        if ($css -match '@import\b|url\(\s*["'']?(?:https?:)?//') { throw "External dependency in local library stylesheet: $($asset.path)" }
    }
}
foreach ($relative in $pages) {
    $page = Get-Content -LiteralPath (Join-Path $resolvedProject $relative) -Raw
    $scripts = if ($relative -eq 'pr_list.php') { "['jquery', 'bootstrap', 'datatables', 'datatables-bs5']" } else { "['jquery', 'bootstrap', 'datatables', 'datatables-bs5', 'sweetalert2']" }
    $styles = if ($relative -eq 'pr_list.php') { "['datatables-bs5-css']" } else { "['datatables-bs5-css', 'sweetalert2-css']" }
    if (-not $page.Contains("drms_frontend_script_tags($scripts)") -or -not $page.Contains("drms_frontend_style_tags($styles)")) {
        throw "The expected local library calls are missing or reordered: $relative"
    }
    if (-not $page.Contains("require_once __DIR__ . '/config/frontend_assets.php';")) { throw "Shared include missing: $relative" }
    if ($page -match '<script\b[^>]*src\s*=\s*["''](?:https?:)?//' -or $page -match 'https://(?:cdn\.datatables\.net|cdn\.jsdelivr\.net)') {
        throw "An operations library still points to the CDN: $relative"
    }
    Write-Host "PASS: local dependency references and expected order: $relative"
}

# Standard input avoids the Windows PowerShell 5.1 php -r quoting issue.
$probe = @'
<?php
require getenv('DRMS_FRONTEND_VERIFY_ROOT') . '/config/frontend_assets.php';
function must($condition, $label) { if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } echo "PASS: $label\n"; }
$styles = drms_frontend_style_tags(['datatables-bs5-css', 'sweetalert2-css']);
must(substr_count($styles, '<link ') === 2 && substr_count($styles, 'integrity="sha384-') === 2, 'two pinned local stylesheets');
must(strpos($styles, 'dataTables.bootstrap5.min.css') < strpos($styles, 'sweetalert2.min.css'), 'CSS cascade order');
must(drms_frontend_style_tags(['datatables-bs5-css','sweetalert2-css']) === '', 'CSS duplicate suppression');
$scripts = drms_frontend_script_tags(['jquery','bootstrap','datatables','datatables-bs5','sweetalert2']);
must(substr_count($scripts, '<script ') === 5 && substr_count($scripts, 'integrity="sha384-') === 5, 'five pinned local scripts');
$last = -1;
foreach (['jquery.min.js','bootstrap.bundle.min.js','jquery.dataTables.min.js','dataTables.bootstrap5.min.js','sweetalert2.all.min.js'] as $file) {
    $position = strpos($scripts, $file); must($position !== false && $position > $last, 'script order: ' . $file); $last = $position;
}
must(strpos($scripts . $styles, 'https://') === false, 'no CDN fallback');
must(drms_frontend_script_tags(['jquery','bootstrap','datatables','datatables-bs5','sweetalert2']) === '', 'script duplicate suppression');
$audit = drms_frontend_script_tags(['jquery','bootstrap','xlsx']);
must(substr_count($audit, '<script ') === 1 && strpos($audit, 'xlsx.full.min.js') !== false, 'existing Audit Trail API and XLSX remain available');
try { drms_frontend_style_tags(['jquery']); must(false, 'wrong asset kind refused'); } catch (InvalidArgumentException $expected) { echo "PASS: wrong asset kind refused\n"; }
try { drms_frontend_script_tags(['missing']); must(false, 'unknown ID refused'); } catch (InvalidArgumentException $expected) { echo "PASS: unknown ID refused\n"; }
'@
$previousRoot = $env:DRMS_FRONTEND_VERIFY_ROOT
$previousEncoding = $OutputEncoding
$probeExitCode = 1
try {
    $env:DRMS_FRONTEND_VERIFY_ROOT = $resolvedProject
    $OutputEncoding = New-Object System.Text.UTF8Encoding($false)
    $probe | & $resolvedPhp
    $probeExitCode = $LASTEXITCODE
} finally {
    $env:DRMS_FRONTEND_VERIFY_ROOT = $previousRoot
    $OutputEncoding = $previousEncoding
}
if ($probeExitCode -ne 0) { throw "Frontend renderer regression failed (PHP exit code $probeExitCode)." }
Write-Host ''
Write-Host 'Phase 5M6B checks passed. Read-only: no network, database or application requests.'
Write-Host 'Scope: PO, PR and Quotation list libraries. Fonts and other pages still need their later phases.'
c