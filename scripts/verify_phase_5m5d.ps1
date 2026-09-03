[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\xampp\htdocs\fixie_drms',
    [string]$PhpExecutable = 'C:\xampp\php\php.exe',
    [switch]$CheckHttp,
    [string]$BaseUrl = 'http://localhost/fixie_drms'
)

$ErrorActionPreference = 'Stop'
$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpExecutable).Path

# Preflight before any checks: do not silently run the older fixture-dependent tests.
$requiredFiles = @(
    'scripts\verify_phase_5m5a.ps1',
    'scripts\verify_phase_5m5b.ps1',
    'scripts\verify_phase_5m5c.ps1',
    'tests\audit_query_test.php',
    'tests\audit_capture_test.php',
    'config\maintenance_db.php',
    'database\migrations\phase_5m5b_audit_indexes.sql'
)
foreach ($relativePath in $requiredFiles) {
    $file = Join-Path $resolvedProject $relativePath
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Missing prerequisite: $file. Copy the complete file from its phase before retrying."
    }
}

$queryTest = Get-Content -LiteralPath (Join-Path $resolvedProject 'tests\audit_query_test.php') -Raw
if ($queryTest -notmatch 'function\s+audit_fixture_sql\s*\(' -or
    $queryTest -notmatch 'MYSQLI_TRANS_START_READ_ONLY') {
    throw 'Install the Phase 5M5D replacement tests\audit_query_test.php before running this check.'
}
$endpointVerifier = Get-Content -LiteralPath (Join-Path $resolvedProject 'scripts\verify_phase_5m5a.ps1') -Raw
if (-not $endpointVerifier.Contains('Fixture URLs in CLI-only regression tests')) {
    throw 'Install the Phase 5M5D replacement scripts\verify_phase_5m5a.ps1 before running this check.'
}

$httpBase = $null
$curlPath = $null
if ($CheckHttp) {
    $httpBase = [uri]$BaseUrl
    if (-not $httpBase.IsAbsoluteUri -or $httpBase.Scheme -notin @('http', 'https') -or
        -not $httpBase.IsLoopback -or $httpBase.UserInfo -or $httpBase.Query -or $httpBase.Fragment) {
        throw 'BaseUrl must be a local HTTP(S) application URL, without credentials, query, or fragment.'
    }
    $curlPath = (Get-Command curl.exe -ErrorAction Stop).Source
}

foreach ($phase in @('5m5a', '5m5b', '5m5c')) {
    Write-Host ''
    Write-Host ("Checking Phase {0}..." -f $phase.ToUpperInvariant())
    $script = Join-Path $resolvedProject ("scripts\verify_phase_{0}.ps1" -f $phase)
    & $script -ProjectRoot $resolvedProject -PhpExecutable $resolvedPhp
    if ($LASTEXITCODE -ne 0) {
        throw "Phase $phase returned a failing native command exit code: $LASTEXITCODE."
    }
}

if ($CheckHttp) {
    Write-Host ''
    Write-Host 'Checking six anonymous endpoint responses (no authenticated cookies or record IDs)...'
    foreach ($endpoint in @('log_action.php', 'log_print.php', 'audit_logs_export.php')) {
        foreach ($method in @('GET', 'POST')) {
            $expected = if ($method -eq 'GET') { '405' } else { '401' }
            $url = $httpBase.AbsoluteUri.TrimEnd('/') + '/api/' + $endpoint
            # -q disables local curl configuration; never attach a cookie jar,
            # credentials, request body, or follow redirects for these checks.
            $status = & $curlPath -q --silent --show-error --noproxy '*' `
                --connect-timeout 3 --max-time 10 --output NUL --write-out '%{http_code}' `
                --request $method --header 'Accept: application/json' $url
            if ($LASTEXITCODE -ne 0) {
                throw "Cannot reach local endpoint $endpoint. Check Apache and BaseUrl."
            }
            if ([string]$status -ne $expected) {
                throw "Unexpected $method response from ${endpoint}: expected $expected, received $status."
            }
            Write-Host "PASS: $method $endpoint -> $expected"
        }
    }
} else {
    Write-Warning 'HTTP checks were skipped. Re-run with -CheckHttp while Apache is running.'
}

Write-Host ''
Write-Host 'Phase 5M5D automated checks passed. No records or schema were modified by the CLI tests.'
Write-Host 'This is not full browser certification: authenticated filters, detail modal, export, and role checks still need UI testing.'
Write-Host 'The Audit Details raw-HTML rendering finding also needs the separate Phase 5M5E fix.'
