[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\xampp\htdocs\fixie_drms',
    [string]$PhpExecutable = 'C:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'

$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpExecutable).Path

$requiredFiles = @(
    'api\log_action.php',
    'api\log_print.php',
    'view_po.php'
)

foreach ($relativePath in $requiredFiles) {
    $path = Join-Path $resolvedProject $relativePath
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Missing required Phase 5M5A file: $path"
    }

    & $resolvedPhp -l $path
    if ($LASTEXITCODE -ne 0) {
        throw "PHP syntax check failed: $path"
    }
}

$legacyEndpoint = Get-Content -LiteralPath (Join-Path $resolvedProject 'api\log_action.php') -Raw
if ($legacyEndpoint -match 'log_audit_action\s*\(' -or $legacyEndpoint -match 'log_document_action\s*\(') {
    throw 'The legacy client-authored endpoint can still write audit records.'
}
if ($legacyEndpoint -match '\$_POST\s*\[\s*[''"](?:action|desc|description)[''"]\s*\]') {
    throw 'The disabled legacy endpoint still consumes a client-authored audit action or description.'
}
if ($legacyEndpoint -notmatch 'http_response_code\s*\(\s*410\s*\)') {
    throw 'The legacy client-authored endpoint is not explicitly retired.'
}

$printEndpoint = Get-Content -LiteralPath (Join-Path $resolvedProject 'api\log_print.php') -Raw
$requiredPrintPatterns = @(
    'REQUEST_METHOD',
    'hash_equals\s*\(',
    '\$_POST\s*\[\s*[''"]record_type[''"]',
    '!==\s*[''"]purchase_order[''"]',
    'SELECT\s+po_number\s+FROM\s+purchase_orders',
    '[''"]PRINT_DOC[''"]',
    'log_audit_action\s*\('
)

foreach ($pattern in $requiredPrintPatterns) {
    if ($printEndpoint -notmatch $pattern) {
        throw "The secured print endpoint is missing required control: $pattern"
    }
}

if ($printEndpoint -match '\$_POST\s*\[\s*[''"](?:doc_name|desc|description|action_type)[''"]\s*\]') {
    throw 'The print endpoint still accepts a client-authored audit name, description, or action type.'
}

$viewPo = Get-Content -LiteralPath (Join-Path $resolvedProject 'view_po.php') -Raw
if ($viewPo -notmatch 'fetch\s*\(\s*[''"]api/log_print\.php[''"]') {
    throw 'view_po.php is not connected to the secured print endpoint.'
}
foreach ($field in @('csrf_token', 'record_type', 'record_id')) {
    if ($viewPo -notmatch [regex]::Escape($field)) {
        throw "The PO print request is missing $field."
    }
}
if ($viewPo -match 'doc_name\s*=|doc_name\s*:|logAndPrint\s*\(\s*documentName') {
    throw 'view_po.php still sends a client-authored document name to the audit endpoint.'
}

$sourceFiles = Get-ChildItem -LiteralPath $resolvedProject -Recurse -File |
    Where-Object { $_.Extension -in @('.php', '.js') }

$legacyCallers = @()
foreach ($file in $sourceFiles) {
    $relativePath = $file.FullName.Substring($resolvedProject.Length + 1)
    # Fixture URLs in CLI-only regression tests are not application callers.
    # Keep all application PHP/JS files in the scan, including api/actions.
    if ($relativePath -match '^tests[\\/]') {
        continue
    }
    if ($relativePath -in @('api\log_action.php', 'config\audit_bootstrap.php')) {
        continue
    }
    $contents = Get-Content -LiteralPath $file.FullName -Raw
    if ($contents -match '(?:api/)?log_action\.php') {
        $legacyCallers += $relativePath
    }
}

if ($legacyCallers.Count -gt 0) {
    throw ('A caller still uses the retired client-authored endpoint: ' + ($legacyCallers -join ', '))
}

Write-Host ''
Write-Host 'Phase 5M5A verification passed. Client-authored audit writes are disabled and PO print logs are server-resolved.'
