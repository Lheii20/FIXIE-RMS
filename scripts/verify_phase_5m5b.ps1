[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\xampp\htdocs\fixie_drms',
    [string]$PhpExecutable = 'C:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'

$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpExecutable).Path

$requiredPhpFiles = @(
    'audit_logs.php',
    'config\audit_query.php',
    'config\audit_bootstrap.php',
    'api\audit_logs_export.php',
    'tests\audit_query_test.php'
)

foreach ($relativePath in $requiredPhpFiles) {
    $path = Join-Path $resolvedProject $relativePath
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Missing required Phase 5M5B file: $path"
    }

    & $resolvedPhp -l $path
    if ($LASTEXITCODE -ne 0) {
        throw "PHP syntax check failed: $path"
    }
}

$page = Get-Content -LiteralPath (Join-Path $resolvedProject 'audit_logs.php') -Raw
if ($page -match 'LIMIT\s+3000' -or $page -match '\.DataTable\s*\(') {
    throw 'audit_logs.php still contains the legacy 3,000-row or client-only DataTables implementation.'
}

$requiredPagePatterns = @(
    'config/audit_query\.php',
    'drms_audit_build_where\s*\(',
    'SELECT\s+COUNT\(\*\)',
    'LIMIT\s+\?\s+OFFSET\s+\?',
    'name="module"',
    'name="category"',
    'name="search"',
    'api/audit_logs_export\.php'
)
foreach ($pattern in $requiredPagePatterns) {
    if ($page -notmatch $pattern) {
        throw "The scalable Audit Trail page is missing: $pattern"
    }
}

$exportEndpoint = Get-Content -LiteralPath (Join-Path $resolvedProject 'api\audit_logs_export.php') -Raw
$requiredExportPatterns = @(
    'hash_equals\s*\(',
    'can_view_audit_logs',
    'drms_audit_build_where\s*\(',
    'log_audit_action\s*\(',
    '[''"]EXPORT_AUDIT_LOGS[''"]'
)
foreach ($pattern in $requiredExportPatterns) {
    if ($exportEndpoint -notmatch $pattern) {
        throw "The secured export endpoint is missing: $pattern"
    }
}
if ($exportEndpoint -match '\$_POST\s*\[\s*[''"](?:record_count|export_type|description)[''"]') {
    throw 'The export endpoint still trusts a client-provided count or audit description.'
}

$bootstrap = Get-Content -LiteralPath (Join-Path $resolvedProject 'config\audit_bootstrap.php') -Raw
if ($bootstrap -notmatch 'api/audit_logs_export\.php') {
    throw 'The explicit export endpoint is not excluded from generic request telemetry.'
}

$migrationPath = Join-Path $resolvedProject 'database\migrations\phase_5m5b_audit_indexes.sql'
if (-not (Test-Path -LiteralPath $migrationPath -PathType Leaf)) {
    throw "Missing Phase 5M5B index migration: $migrationPath"
}
$migration = Get-Content -LiteralPath $migrationPath -Raw
foreach ($indexName in @(
    'idx_audit_timestamp_log_id',
    'idx_audit_action_timestamp_log',
    'idx_audit_user_timestamp_log'
)) {
    if ($migration -notmatch [regex]::Escape($indexName)) {
        throw "The migration is missing index: $indexName"
    }
}
if ($migration -match '\b(?:DELETE|TRUNCATE|DROP)\b') {
    throw 'The Phase 5M5B migration must not delete or drop audit data.'
}

Write-Host ''
Write-Host 'Running read-only audit query and index tests...'
$previousRoot = $env:DRMS_PROJECT_ROOT
$previousIndexRequirement = $env:DRMS_REQUIRE_AUDIT_INDEXES
try {
    $env:DRMS_PROJECT_ROOT = $resolvedProject
    $env:DRMS_REQUIRE_AUDIT_INDEXES = '1'
    & $resolvedPhp (Join-Path $resolvedProject 'tests\audit_query_test.php')
    if ($LASTEXITCODE -ne 0) {
        throw "Audit query tests failed with exit code $LASTEXITCODE."
    }
} finally {
    $env:DRMS_PROJECT_ROOT = $previousRoot
    $env:DRMS_REQUIRE_AUDIT_INDEXES = $previousIndexRequirement
}

Write-Host ''
Write-Host 'Phase 5M5B verification passed. Audit counts, filters, pagination, export, and indexes are ready.'
