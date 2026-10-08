[CmdletBinding()]
param(
    [string]$ProjectRoot = '',
    [string]$PhpExe = ''
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $scriptDirectory = $PSScriptRoot
    if ([string]::IsNullOrWhiteSpace($scriptDirectory) -and $MyInvocation.MyCommand.Path) {
        $scriptDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
    }
    if ([string]::IsNullOrWhiteSpace($scriptDirectory)) {
        throw 'Could not determine the script folder. Run this script with -File or pass -ProjectRoot.'
    }
    $ProjectRoot = Split-Path -Parent $scriptDirectory
}

$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$phpVerifier = Join-Path $ProjectRoot 'scripts\verify_gm_disposition_update.php'
$requiredFiles = @(
    'documents.php',
    'actions\disposition_handler.php',
    'config\rbac_policy.php',
    'config\maintenance_engine.php',
    'database\migrations\phase_gm_disposition_review.sql',
    'scripts\verify_gm_disposition_update.php'
)

Write-Host 'Fixie DRMS GM disposition update verification'
Write-Host "Project: $ProjectRoot"

$missing = @()
foreach ($relativePath in $requiredFiles) {
    $fullPath = Join-Path $ProjectRoot $relativePath
    if (Test-Path -LiteralPath $fullPath -PathType Leaf) {
        Write-Host "PASS  $relativePath"
    } else {
        Write-Host "FAIL  Missing: $relativePath"
        $missing += $relativePath
    }
}

if ($missing.Count -gt 0) {
    throw "Required update files are missing ($($missing.Count)). Copy the complete package into the project first."
}

if ([string]::IsNullOrWhiteSpace($PhpExe)) {
    $xamppPhp = Join-Path $env:SystemDrive 'xampp\php\php.exe'
    if (Test-Path -LiteralPath $xamppPhp -PathType Leaf) {
        $PhpExe = $xamppPhp
    } else {
        $phpCommand = Get-Command 'php.exe' -ErrorAction SilentlyContinue
        if (-not $phpCommand) {
            throw 'PHP was not found. Pass -PhpExe with the full path to php.exe (for example C:\xampp\php\php.exe).'
        }
        $PhpExe = $phpCommand.Source
    }
}

if (-not (Test-Path -LiteralPath $PhpExe -PathType Leaf)) {
    throw "PHP executable not found: $PhpExe"
}

Write-Host "PHP: $PhpExe"
& $PhpExe $phpVerifier
if ($LASTEXITCODE -ne 0) {
    throw 'GM disposition update verification failed.'
}

Write-Host 'PASS  GM disposition update static verification'
Write-Host 'Note: This checks files and PHP syntax; it does not run database/browser integration tests.'
