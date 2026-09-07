[CmdletBinding()]
param(
    [string]$ProjectRoot = '',
    [string]$ReleaseParent = '',
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [switch]$SkipZip
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$scriptFilePath = [string]$MyInvocation.MyCommand.Path
if ([string]::IsNullOrWhiteSpace($scriptFilePath)) {
    throw 'The builder must be executed from its saved .ps1 file.'
}
$scriptDirectory = Split-Path -Parent $scriptFilePath
if ([string]::IsNullOrWhiteSpace($ProjectRoot)) {
    $ProjectRoot = Split-Path -Parent $scriptDirectory
}

function Get-CanonicalDirectory([string]$Path, [string]$Label) {
    if (-not (Test-Path -LiteralPath $Path -PathType Container)) {
        throw "$Label does not exist: $Path"
    }
    return (Get-Item -LiteralPath $Path).FullName.TrimEnd('\', '/')
}

function Test-PathWithin([string]$Candidate, [string]$Parent) {
    $candidateFull = [System.IO.Path]::GetFullPath($Candidate).TrimEnd('\', '/')
    $parentFull = [System.IO.Path]::GetFullPath($Parent).TrimEnd('\', '/')
    return $candidateFull.Equals($parentFull, [System.StringComparison]::OrdinalIgnoreCase) -or
        $candidateFull.StartsWith(
            $parentFull + [System.IO.Path]::DirectorySeparatorChar,
            [System.StringComparison]::OrdinalIgnoreCase
        )
}

function Get-RelativeReleasePath([string]$Root, [string]$FullName) {
    $rootPrefix = $Root.TrimEnd('\', '/') + [System.IO.Path]::DirectorySeparatorChar
    if (-not $FullName.StartsWith($rootPrefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Source file is outside the project root: $FullName"
    }
    return $FullName.Substring($rootPrefix.Length).Replace('\', '/')
}

function Get-InfinityFreeFileLimit([System.IO.FileInfo]$File) {
    if ($File.Name -ieq '.htaccess') {
        return 10KB
    }
    if ($File.Extension -in @('.php', '.html', '.htm', '.js')) {
        return 1MB
    }
    return 10MB
}

$project = Get-CanonicalDirectory $ProjectRoot 'Project root'
if ([string]::IsNullOrWhiteSpace($ReleaseParent)) {
    $ReleaseParent = Join-Path (Split-Path -Parent $project) 'fixie_drms_releases'
}
$releaseParentFull = [System.IO.Path]::GetFullPath($ReleaseParent).TrimEnd('\', '/')

if (Test-PathWithin $releaseParentFull $project) {
    throw 'ReleaseParent must be outside the project root to prevent recursive packaging.'
}
if (-not (Test-Path -LiteralPath $releaseParentFull)) {
    New-Item -ItemType Directory -Path $releaseParentFull | Out-Null
}

$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$suffix = [System.Guid]::NewGuid().ToString('N').Substring(0, 8)
$releaseName = "fixie_drms_release_${stamp}_${suffix}"
$releasePath = Join-Path $releaseParentFull $releaseName
$manifestPath = Join-Path $releaseParentFull ($releaseName + '_manifest.json')
$zipPath = Join-Path $releaseParentFull ($releaseName + '.zip')

if ((Test-Path -LiteralPath $releasePath) -or
    (Test-Path -LiteralPath $manifestPath) -or
    (Test-Path -LiteralPath $zipPath)) {
    throw 'The generated release target already exists. Run the builder again for a new unique target.'
}

$sourceFiles = New-Object System.Collections.Generic.List[System.IO.FileInfo]

Get-ChildItem -LiteralPath $project -Force -File |
    Where-Object {
        ($_.Extension -ieq '.php' -or $_.Name -ieq '.htaccess') -and
        $_.Name -ine 'sync_cabinets.php'
    } |
    ForEach-Object { $sourceFiles.Add($_) }

foreach ($directoryName in @('actions', 'api', 'assets', 'config', 'includes', 'libs')) {
    $directoryPath = Join-Path $project $directoryName
    if (-not (Test-Path -LiteralPath $directoryPath -PathType Container)) {
        throw "Required production directory is missing: $directoryName"
    }
    Get-ChildItem -LiteralPath $directoryPath -Recurse -Force -File |
        Where-Object {
            $relative = Get-RelativeReleasePath $project $_.FullName
            $relative -notin @(
                'config/runtime.local.php',
                'config/last_purge_date.txt'
            )
        } |
        ForEach-Object { $sourceFiles.Add($_) }
}

foreach ($protectedRelativePath in @('uploads/.htaccess', 'storage/.htaccess')) {
    $protectedPath = Join-Path $project $protectedRelativePath.Replace('/', '\')
    if (-not (Test-Path -LiteralPath $protectedPath -PathType Leaf)) {
        throw "Required protected-directory file is missing: $protectedRelativePath"
    }
    $sourceFiles.Add((Get-Item -LiteralPath $protectedPath -Force))
}

$uniqueFiles = $sourceFiles |
    Sort-Object FullName -Unique

New-Item -ItemType Directory -Path $releasePath | Out-Null

$manifestFiles = New-Object System.Collections.Generic.List[object]
foreach ($sourceFile in $uniqueFiles) {
    $relativePath = Get-RelativeReleasePath $project $sourceFile.FullName
    if ($relativePath.Contains('../') -or $relativePath.Contains('..\')) {
        throw "Unsafe relative release path: $relativePath"
    }

    $limit = Get-InfinityFreeFileLimit $sourceFile
    if ($sourceFile.Length -gt $limit) {
        throw "Hosting file-size limit exceeded: $relativePath ($($sourceFile.Length) bytes; limit $limit bytes)"
    }

    $destination = Join-Path $releasePath $relativePath.Replace('/', '\')
    $destinationDirectory = Split-Path -Parent $destination
    if (-not (Test-Path -LiteralPath $destinationDirectory)) {
        New-Item -ItemType Directory -Path $destinationDirectory | Out-Null
    }
    Copy-Item -LiteralPath $sourceFile.FullName -Destination $destination

    $sourceHash = (Get-FileHash -LiteralPath $sourceFile.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    $destinationHash = (Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($sourceHash -ne $destinationHash) {
        throw "Copied file failed SHA-256 verification: $relativePath"
    }

    $manifestFiles.Add([ordered]@{
        path = $relativePath
        bytes = [int64]$sourceFile.Length
        sha256 = $destinationHash
    })
}

$releaseFiles = Get-ChildItem -LiteralPath $releasePath -Recurse -Force -File
$releaseBytes = ($releaseFiles | Measure-Object -Property Length -Sum).Sum
$manifest = [ordered]@{
    format = 'FIXIE_DRMS_PRODUCTION_RELEASE'
    format_version = 1
    created_at_utc = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    source_root = $project
    release_directory = $releasePath
    clean_state = [ordered]@{
        database_included = $false
        runtime_secrets_included = $false
        uploaded_records_included = $false
        backup_archives_included = $false
        logs_included = $false
        developer_scripts_included = $false
        tests_included = $false
    }
    file_count = $releaseFiles.Count
    total_bytes = [int64]$releaseBytes
    files = $manifestFiles
}

$manifestJson = $manifest | ConvertTo-Json -Depth 6
[System.IO.File]::WriteAllText(
    $manifestPath,
    $manifestJson,
    [System.Text.UTF8Encoding]::new($false)
)

$verifierPath = Join-Path $scriptDirectory 'verify_production_release.ps1'
if (-not (Test-Path -LiteralPath $verifierPath -PathType Leaf)) {
    throw "Release verifier is missing: $verifierPath"
}

& $verifierPath -ReleasePath $releasePath -PhpPath $PhpPath
if ($LASTEXITCODE -ne 0) {
    throw 'Production release verification failed. The release directory was retained for inspection.'
}

if (-not $SkipZip) {
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::CreateFromDirectory(
        $releasePath,
        $zipPath,
        [System.IO.Compression.CompressionLevel]::Optimal,
        $false
    )
}

Write-Host ''
Write-Host 'PRODUCTION RELEASE CREATED' -ForegroundColor Green
Write-Host "Release folder: $releasePath"
Write-Host "Manifest:       $manifestPath"
if (-not $SkipZip) {
    Write-Host "Archive copy:   $zipPath"
    Write-Host 'For InfinityFree, upload the release folder contents through FileZilla; do not upload the ZIP for server-side extraction.' -ForegroundColor Yellow
}
