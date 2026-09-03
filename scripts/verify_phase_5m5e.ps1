[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\xampp\htdocs\fixie_drms',
    [string]$PhpExecutable = 'C:\xampp\php\php.exe',
    [string]$NodeExecutable = 'node',
    [switch]$IncludePriorChecks
)

$ErrorActionPreference = 'Stop'
$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpExecutable).Path
$nodeCommand = Get-Command $NodeExecutable -CommandType Application -ErrorAction SilentlyContinue
if (-not $nodeCommand) {
    throw 'Node.js is required only for this isolated test. Pass -NodeExecutable with the full node.exe path. No npm install is needed.'
}
$resolvedNode = $nodeCommand.Source
foreach ($relativePath in @('audit_logs.php', 'tests\audit_record_rendering_test.php', 'tests\audit_details_rendering_test.js')) {
    $file = Join-Path $resolvedProject $relativePath
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Missing Phase 5M5E file: $file. Copy all phase files before retrying."
    }
}

foreach ($relativePath in @('audit_logs.php', 'tests\audit_record_rendering_test.php')) {
    & $resolvedPhp -l (Join-Path $resolvedProject $relativePath)
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax check failed: $relativePath" }
}
$jsTest = Join-Path $resolvedProject 'tests\audit_details_rendering_test.js'
& $resolvedNode --check $jsTest
if ($LASTEXITCODE -ne 0) { throw 'JavaScript test syntax check failed.' }

& $resolvedPhp (Join-Path $resolvedProject 'tests\audit_record_rendering_test.php') $resolvedProject
if ($LASTEXITCODE -ne 0) { throw 'Audit sentence escaping regression test failed.' }
& $resolvedNode $jsTest $resolvedProject
if ($LASTEXITCODE -ne 0) { throw 'Audit Details DOM regression test failed.' }

if ($IncludePriorChecks) {
    $prior = Join-Path $resolvedProject 'scripts\verify_phase_5m5d.ps1'
    if (-not (Test-Path -LiteralPath $prior -PathType Leaf)) { throw "Missing installed prior verifier: $prior" }
    & $prior -ProjectRoot $resolvedProject -PhpExecutable $resolvedPhp
    # The older 5M5D verifier prints a historical reminder about 5M5E.
    # The actual rendering tests above, not that reminder, determine this result.
}

Write-Host ''
Write-Host 'Phase 5M5E safe-rendering tests passed. No application requests or database writes were made by these tests.'
Write-Host 'Authenticated browser interaction, layout and export testing remain separate checks.'
