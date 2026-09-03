[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\xampp\htdocs\fixie_drms',
    [string]$PhpExecutable = 'C:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'

$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpExecutable).Path

$requiredFiles = @(
    'config\upload_policy.php',
    'actions\document_handler.php',
    'actions\version_handler.php',
    'api\upload_file.php',
    'actions\quotation_handler.php',
    'actions\pr_handler.php',
    'actions\po_handler.php',
    'actions\delivery_completion_handler.php',
    'actions\collection_payment_handler.php',
    'actions\user_handler.php',
    'tests\upload_policy_test.php'
)

foreach ($relativePath in $requiredFiles) {
    $file = Join-Path $resolvedProject $relativePath
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Missing required upload-policy file: $file"
    }

    & $resolvedPhp -l $file
    if ($LASTEXITCODE -ne 0) {
        throw "PHP syntax check failed: $file"
    }
}

$handlerExpectations = @(
    @{ File = 'actions\document_handler.php'; Calls = 2; Moves = 1 },
    @{ File = 'actions\version_handler.php'; Calls = 1; Moves = 1 },
    @{ File = 'api\upload_file.php'; Calls = 1; Moves = 1 },
    @{ File = 'actions\quotation_handler.php'; Calls = 1; Moves = 1 },
    @{ File = 'actions\pr_handler.php'; Calls = 1; Moves = 1 },
    @{ File = 'actions\po_handler.php'; Calls = 3; Moves = 3 },
    @{ File = 'actions\delivery_completion_handler.php'; Calls = 1; Moves = 1 },
    @{ File = 'actions\collection_payment_handler.php'; Calls = 1; Moves = 1 },
    @{ File = 'actions\user_handler.php'; Calls = 1; Moves = 1 }
)

foreach ($expectation in $handlerExpectations) {
    $path = Join-Path $resolvedProject $expectation.File
    $contents = Get-Content -LiteralPath $path -Raw

    if ($contents -notmatch 'require_once\s+.+upload_policy\.php') {
        throw "Central upload helper is not loaded by $path."
    }

    $validationCalls = ([regex]::Matches($contents, 'drms_upload_validate\s*\(')).Count
    if ($validationCalls -ne $expectation.Calls) {
        throw "Unexpected central validation count in $path. Expected $($expectation.Calls), found $validationCalls."
    }

    $moveCalls = ([regex]::Matches($contents, 'move_uploaded_file\s*\(')).Count
    if ($moveCalls -ne $expectation.Moves) {
        throw "Unexpected upload move count in $path. Expected $($expectation.Moves), found $moveCalls. Review this new upload path before release."
    }

    if ($contents -match '(?:2|5|10|25|50)\s*\*\s*1024\s*\*\s*1024') {
        throw "A legacy inline upload-size limit is still present in $path."
    }
}

$userHandler = Get-Content -LiteralPath (Join-Path $resolvedProject 'actions\user_handler.php') -Raw
if ($userHandler -notmatch "drms_upload_validate\s*\([\s\S]*?'profile'\s*\)") {
    throw 'Profile photos are not connected to the fixed profile upload policy.'
}
if ($userHandler -match 'getimagesize\s*\(' -or $userHandler -match "'image/jpeg'\s*=>") {
    throw 'Legacy inline profile content validation is still present in user_handler.php.'
}

$policy = Get-Content -LiteralPath (Join-Path $resolvedProject 'config\upload_policy.php') -Raw
if ($policy -notmatch "case\s+'profile'\s*:\s*return\s+5\s*;") {
    throw 'The profile policy is not fixed at 5 MB.'
}
if ($policy -notmatch "case\s+'profile'[\s\S]*?'webp'") {
    throw 'The profile policy does not include the expected JPG, PNG, and WebP image formats.'
}

$uploadFiles = Get-ChildItem -LiteralPath $resolvedProject -Recurse -File -Filter '*.php' |
    Where-Object { (Get-Content -LiteralPath $_.FullName -Raw) -match 'move_uploaded_file\s*\(' } |
    ForEach-Object { $_.FullName.Substring($resolvedProject.Length + 1) } |
    Sort-Object

$expectedUploadFiles = $handlerExpectations.File | Sort-Object
$unexpectedUploadFiles = @($uploadFiles | Where-Object { $_ -notin $expectedUploadFiles })
$missingUploadFiles = @($expectedUploadFiles | Where-Object { $_ -notin $uploadFiles })

if ($unexpectedUploadFiles.Count -gt 0) {
    throw ('Unreviewed upload entry point detected: ' + ($unexpectedUploadFiles -join ', '))
}
if ($missingUploadFiles.Count -gt 0) {
    throw ('Expected upload entry point is missing: ' + ($missingUploadFiles -join ', '))
}

Write-Host ''
Write-Host 'Running final central upload-policy tests...'
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
Write-Host 'Phase 5M4C verification passed. All current upload entry points use the central policy.'
