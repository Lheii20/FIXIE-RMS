[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ReleasePath,
    [string]$PhpPath = 'C:\xampp\php\php.exe'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$failures = New-Object System.Collections.Generic.List[string]
$passes = New-Object System.Collections.Generic.List[string]

function Add-Check([bool]$Condition, [string]$Message) {
    if ($Condition) {
        $script:passes.Add($Message)
        Write-Host "[PASS] $Message" -ForegroundColor Green
    } else {
        $script:failures.Add($Message)
        Write-Host "[FAIL] $Message" -ForegroundColor Red
    }
}

function Get-RelativePath([string]$Root, [string]$FullName) {
    $prefix = $Root.TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar
    return $FullName.Substring($prefix.Length).Replace('\', '/')
}

if (-not (Test-Path -LiteralPath $ReleasePath -PathType Container)) {
    throw "Release directory does not exist: $ReleasePath"
}
$release = (Get-Item -LiteralPath $ReleasePath).FullName.TrimEnd('\', '/')

$requiredFiles = @(
    '.htaccess',
    'index.php',
    'dashboard.php',
    'documents.php',
    'general_docs.php',
    'virtual_cabinet.php',
    'download.php',
    'actions/document_handler.php',
    'actions/po_handler.php',
    'api/upload_file.php',
    'assets/css/style.css',
    'assets/js/dashboard.js',
    'config/.htaccess',
    'config/runtime.php',
    'config/runtime.local.php.example',
    'config/db_connect.php',
    'config/storage_security.php',
    'config/upload_policy.php',
    'includes/storage_location_manager.php',
    'libs/src/PHPMailer.php',
    'uploads/.htaccess',
    'storage/.htaccess'
)

foreach ($relativePath in $requiredFiles) {
    Add-Check (
        Test-Path -LiteralPath (Join-Path $release $relativePath.Replace('/', '\')) -PathType Leaf
    ) "Required production file exists: $relativePath"
}

$forbiddenTopLevel = @('.git', 'database', 'scripts', 'tests')
foreach ($name in $forbiddenTopLevel) {
    Add-Check (-not (Test-Path -LiteralPath (Join-Path $release $name))) "Development directory is excluded: $name"
}

$allFiles = @(Get-ChildItem -LiteralPath $release -Recurse -Force -File)
Add-Check ($allFiles.Count -gt 0) 'Release contains application files.'

$forbiddenNames = @(
    'runtime.local.php',
    '.env',
    'last_purge_date.txt',
    'sync_cabinets.php'
)
$forbiddenExtensions = @('.sql', '.zip', '.log', '.ps1', '.bak', '.old', '.pem', '.pfx', '.key')
$forbiddenFiles = @($allFiles | Where-Object {
    $_.Name -in $forbiddenNames -or $_.Extension.ToLowerInvariant() -in $forbiddenExtensions
})
Add-Check ($forbiddenFiles.Count -eq 0) 'Secrets, SQL, archives, logs, keys, and developer scripts are excluded.'
if ($forbiddenFiles.Count -gt 0) {
    $forbiddenFiles | ForEach-Object { Write-Host ('       ' + (Get-RelativePath $release $_.FullName)) }
}

$uploadFiles = @(Get-ChildItem -LiteralPath (Join-Path $release 'uploads') -Recurse -Force -File)
Add-Check (
    $uploadFiles.Count -eq 1 -and $uploadFiles[0].Name -ieq '.htaccess'
) 'Sample uploads are excluded while upload protection is retained.'

$storageFiles = @(Get-ChildItem -LiteralPath (Join-Path $release 'storage') -Recurse -Force -File)
Add-Check (
    $storageFiles.Count -eq 1 -and $storageFiles[0].Name -ieq '.htaccess'
) 'Backup archives, locks, and logs are excluded while storage protection is retained.'

$oversizedFiles = New-Object System.Collections.Generic.List[object]
foreach ($file in $allFiles) {
    if ($file.Name -ieq '.htaccess') {
        $limit = 10KB
    } elseif ($file.Extension.ToLowerInvariant() -in @('.php', '.html', '.htm', '.js')) {
        $limit = 1MB
    } else {
        $limit = 10MB
    }
    if ($file.Length -gt $limit) {
        $oversizedFiles.Add([pscustomobject]@{
            Path = Get-RelativePath $release $file.FullName
            Bytes = $file.Length
            Limit = $limit
        })
    }
}
Add-Check ($oversizedFiles.Count -eq 0) 'Every file fits the InfinityFree per-file size limits.'
if ($oversizedFiles.Count -gt 0) {
    $oversizedFiles | Format-Table -AutoSize | Out-Host
}

$localPathPattern = '(?i)(C:\\xampp|C:/xampp|Users\\tamay|Users/tamay|localhost/fixie_drms)'
$localPathHits = New-Object System.Collections.Generic.List[string]
foreach ($file in $allFiles | Where-Object { $_.Extension.ToLowerInvariant() -in @('.php', '.js', '.css', '.json', '.html') }) {
    $content = [System.IO.File]::ReadAllText($file.FullName)
    if ($content -match $localPathPattern) {
        $localPathHits.Add((Get-RelativePath $release $file.FullName))
    }
}
Add-Check ($localPathHits.Count -eq 0) 'No local XAMPP or user-specific absolute path is embedded in production files.'

$rawUploadMoves = New-Object System.Collections.Generic.List[string]
$phpFiles = @($allFiles | Where-Object { $_.Extension -ieq '.php' })
foreach ($file in $phpFiles) {
    if ((Get-RelativePath $release $file.FullName) -eq 'config/storage_security.php') {
        continue
    }
    $content = [System.IO.File]::ReadAllText($file.FullName)
    if ($content -match '(?<!drms_storage_)move_uploaded_file\s*\(') {
        $rawUploadMoves.Add((Get-RelativePath $release $file.FullName))
    }
}
Add-Check ($rawUploadMoves.Count -eq 0) 'Every production upload writer uses the protected storage mover.'

if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $phpCommand) {
        throw "PHP executable was not found: $PhpPath"
    }
    $PhpPath = $phpCommand.Source
}

$syntaxFailures = New-Object System.Collections.Generic.List[string]
foreach ($file in $phpFiles) {
    $output = & $PhpPath -l $file.FullName 2>&1
    if ($LASTEXITCODE -ne 0) {
        $syntaxFailures.Add((Get-RelativePath $release $file.FullName) + ': ' + ($output -join ' '))
    }
}
Add-Check ($syntaxFailures.Count -eq 0) "All $($phpFiles.Count) production PHP files pass syntax validation."
if ($syntaxFailures.Count -gt 0) {
    $syntaxFailures | ForEach-Object { Write-Host ('       ' + $_) }
}

$rootHtaccess = [System.IO.File]::ReadAllText((Join-Path $release '.htaccess'))
$configHtaccess = [System.IO.File]::ReadAllText((Join-Path $release 'config\.htaccess'))
$uploadsHtaccess = [System.IO.File]::ReadAllText((Join-Path $release 'uploads\.htaccess'))
$storageHtaccess = [System.IO.File]::ReadAllText((Join-Path $release 'storage\.htaccess'))
Add-Check ($rootHtaccess -match 'Options\s+-Indexes') 'Root directory listing is disabled.'
Add-Check (
    $configHtaccess -match 'Require\s+all\s+denied' -and
    $uploadsHtaccess -match 'Require\s+all\s+denied' -and
    $storageHtaccess -match 'Require\s+all\s+denied'
) 'Configuration, uploads, and protected storage deny direct web access.'

$runtimeExample = [System.IO.File]::ReadAllText((Join-Path $release 'config\runtime.local.php.example'))
Add-Check (
    $runtimeExample -match "'hosting_profile'\s*=>\s*'infinityfree'" -and
    $runtimeExample -match 'REPLACE_WITH_DATABASE_PASSWORD' -and
    $runtimeExample -match 'REPLACE_WITH_SMTP_APP_PASSWORD'
) 'Release contains a safe InfinityFree configuration template without real credentials.'

$totalBytes = ($allFiles | Measure-Object -Property Length -Sum).Sum
Add-Check ($allFiles.Count -lt 5000) 'Release file count remains below a single-directory FTP listing concern.'

Write-Host ''
if ($failures.Count -gt 0) {
    Write-Host "PRODUCTION RELEASE VERIFICATION FAILED: $($failures.Count) check(s) need attention." -ForegroundColor Red
    exit 1
}

Write-Host "PRODUCTION RELEASE VERIFIED: $($passes.Count) checks passed." -ForegroundColor Green
Write-Host "Files: $($allFiles.Count)"
Write-Host ('Size:  {0:N2} MB' -f ($totalBytes / 1MB))
exit 0
