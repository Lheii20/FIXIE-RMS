[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\xampp\htdocs\fixie_drms',
    [string]$PhpExecutable = 'C:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'
$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpExecutable).Path

foreach ($relativePath in @(
    'config\audit_bootstrap.php',
    'sidebar.php',
    'tests\audit_capture_test.php'
)) {
    $file = Join-Path $resolvedProject $relativePath
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Missing Phase 5M5C file: $file"
    }
    & $resolvedPhp -l $file
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax check failed: $file" }
}

$bootstrap = Get-Content -LiteralPath (Join-Path $resolvedProject 'config\audit_bootstrap.php') -Raw
if ($bootstrap -notmatch 'function\s+drms_audit_should_capture_request\s*\(') {
    throw 'The automatic capture policy is missing.'
}
foreach ($endpoint in @('api/log_action.php', 'api/log_print.php', 'api/audit_logs_export.php')) {
    if (-not $bootstrap.Contains($endpoint)) {
        throw "The explicit audit endpoint exclusion is missing: $endpoint"
    }
}

$sidebar = Get-Content -LiteralPath (Join-Path $resolvedProject 'sidebar.php') -Raw
if ($sidebar -match '(?:log_audit_action\s*\(|INSERT\s+INTO\s+audit_logs)') {
    throw 'The sidebar still contains its duplicate audit writer.'
}
if ($sidebar -notmatch 'api/check_session\.php') {
    throw 'The existing session-check request is missing from the sidebar.'
}

Write-Host ''
Write-Host 'Running isolated capture tests (no database connection or writes)...'
& $resolvedPhp (Join-Path $resolvedProject 'tests\audit_capture_test.php')
if ($LASTEXITCODE -ne 0) { throw 'An audit-capture regression test failed.' }

Write-Host ''
Write-Host 'Phase 5M5C verification passed. Navigation noise is suppressed; existing audit history is untouched.'
