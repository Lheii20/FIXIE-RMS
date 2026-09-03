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
    (Join-Path $resolvedProject 'actions\quotation_handler.php'),
    (Join-Path $resolvedProject 'actions\pr_handler.php'),
    (Join-Path $resolvedProject 'actions\po_handler.php'),
    (Join-Path $resolvedProject 'actions\delivery_completion_handler.php'),
    (Join-Path $resolvedProject 'actions\collection_payment_handler.php'),
    (Join-Path $resolvedProject 'tests\upload_policy_test.php')
)

foreach ($file in $requiredFiles) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Missing required Phase 5M4B file: $file"
    }

    & $resolvedPhp -l $file
    if ($LASTEXITCODE -ne 0) {
        throw "PHP syntax check failed: $file"
    }
}

$handlerExpectations = @(
    @{ File = 'actions\quotation_handler.php'; Calls = 1 },
    @{ File = 'actions\pr_handler.php'; Calls = 1 },
    @{ File = 'actions\po_handler.php'; Calls = 3 },
    @{ File = 'actions\delivery_completion_handler.php'; Calls = 1 },
    @{ File = 'actions\collection_payment_handler.php'; Calls = 1 }
)

foreach ($expectation in $handlerExpectations) {
    $path = Join-Path $resolvedProject $expectation.File
    $contents = Get-Content -LiteralPath $path -Raw
    if ($contents -notmatch 'require_once\s+.+upload_policy\.php') {
        throw "Central upload helper is not loaded by $path."
    }

    $callCount = ([regex]::Matches($contents, 'drms_upload_validate\s*\(')).Count
    if ($callCount -ne $expectation.Calls) {
        throw "Unexpected central validation count in $path. Expected $($expectation.Calls), found $callCount."
    }

    if ($contents -match '10\s*\*\s*1024\s*\*\s*1024') {
        throw "A legacy inline 10 MB validator is still present in $path."
    }
}

$policyContents = Get-Content -LiteralPath (Join-Path $resolvedProject 'config\upload_policy.php') -Raw
if ($policyContents -notmatch "case\s+'workflow_document'" -or $policyContents -notmatch "case\s+'proof'") {
    throw 'The proof and workflow-document policies are incomplete.'
}

Write-Host ''
Write-Host 'Running central document and workflow-proof tests...'
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
Write-Host 'Phase 5M4B verification passed.'
