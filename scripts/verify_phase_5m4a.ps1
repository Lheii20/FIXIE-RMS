[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\xampp\htdocs\fixie_drms',
    [string]$PhpExecutable = 'C:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'

$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpExecutable).Path

$requiredFiles = @(
    (Join-Path $resolvedProject 'config\upload_policy.php'),
    (Join-Path $resolvedProject 'actions\document_handler.php'),
    (Join-Path $resolvedProject 'actions\version_handler.php'),
    (Join-Path $resolvedProject 'actions\settings_handler.php'),
    (Join-Path $resolvedProject 'api\upload_file.php'),
    (Join-Path $resolvedProject 'tests\upload_policy_test.php')
)

foreach ($file in $requiredFiles) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Missing required Phase 5M4A file: $file"
    }

    & $resolvedPhp -l $file
    if ($LASTEXITCODE -ne 0) {
        throw "PHP syntax check failed: $file"
    }
}

$integrationChecks = @(
    @{ Path = (Join-Path $resolvedProject 'actions\document_handler.php'); Pattern = 'drms_upload_validate' },
    @{ Path = (Join-Path $resolvedProject 'actions\version_handler.php'); Pattern = 'drms_upload_validate' },
    @{ Path = (Join-Path $resolvedProject 'api\upload_file.php'); Pattern = 'drms_upload_validate' },
    @{ Path = (Join-Path $resolvedProject 'actions\settings_handler.php'); Pattern = 'drms_upload_allowed_document_limits_mb' }
)

foreach ($check in $integrationChecks) {
    if (-not (Select-String -LiteralPath $check.Path -SimpleMatch $check.Pattern)) {
        throw "Central upload policy is not connected to $($check.Path)."
    }
}

$legacyChecks = @(
    @{ Path = (Join-Path $resolvedProject 'actions\document_handler.php'); Pattern = '50\s*\*\s*1024|50 MB' },
    @{ Path = (Join-Path $resolvedProject 'actions\version_handler.php'); Pattern = '50\s*\*\s*1024|50 MB' },
    @{ Path = (Join-Path $resolvedProject 'api\upload_file.php'); Pattern = '50\s*\*\s*1024|50 MB' }
)

foreach ($check in $legacyChecks) {
    if (Select-String -LiteralPath $check.Path -Pattern $check.Pattern -CaseSensitive:$false) {
        throw "A legacy 50 MB upload bypass is still present in $($check.Path)."
    }
}

Write-Host ''
Write-Host 'Running upload content and size validation tests...'
$previousRoot = $env:DRMS_PROJECT_ROOT
try {
    $env:DRMS_PROJECT_ROOT = $resolvedProject
    & $resolvedPhp (Join-Path $resolvedProject 'tests\upload_policy_test.php')
    if ($LASTEXITCODE -ne 0) {
        throw "Upload-policy tests failed with exit code $LASTEXITCODE."
    }
} finally {
    $env:DRMS_PROJECT_ROOT = $previousRoot
}

Write-Host ''
Write-Host 'Phase 5M4A verification passed.'

