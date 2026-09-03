[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\xampp\htdocs\fixie_drms',
    [string]$PhpExecutable = 'C:\xampp\php\php.exe',
    [string]$TaskName = 'Fixie DRMS Daily Maintenance'
)

$ErrorActionPreference = 'Stop'

$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpExecutable).Path

$files = @(
    (Join-Path $resolvedProject 'general_docs.php'),
    (Join-Path $resolvedProject 'sidebar.php'),
    (Join-Path $resolvedProject 'config\db_connect.php'),
    (Join-Path $resolvedProject 'cron_maintenance.php'),
    (Join-Path $resolvedProject 'config\maintenance_engine.php'),
    (Join-Path $resolvedProject 'config\maintenance_db.php')
)

foreach ($file in $files) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Missing required file: $file"
    }

    & $resolvedPhp -l $file
    if ($LASTEXITCODE -ne 0) {
        throw "PHP syntax check failed: $file"
    }
}

$forbidden = @(
    @{ Path = (Join-Path $resolvedProject 'general_docs.php'); Pattern = 'last_purge_date|SYSTEM_AUTO_PURGE' },
    @{ Path = (Join-Path $resolvedProject 'sidebar.php'); Pattern = 'phase5c_collection_sync_date|phase5c_sync_collection_reminders' },
    @{ Path = (Join-Path $resolvedProject 'config\db_connect.php'); Pattern = 'DELETE\s+FROM\s+login_attempts' }
)

foreach ($check in $forbidden) {
    $match = Select-String -LiteralPath $check.Path -Pattern $check.Pattern -CaseSensitive:$false
    if ($match) {
        throw "Old page-triggered maintenance code is still present in $($check.Path)."
    }
}

$scheduledTask = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($null -eq $scheduledTask) {
    throw "Required scheduled task is missing: $TaskName. Install Phase 5M3A before completing Phase 5M3B."
}
if ($scheduledTask.State -eq 'Disabled') {
    throw "Required scheduled task is disabled: $TaskName"
}

Write-Host ''
Write-Host 'Running maintenance in read-only dry-run mode...'
& $resolvedPhp (Join-Path $resolvedProject 'cron_maintenance.php') --dry-run
if ($LASTEXITCODE -ne 0) {
    throw "Maintenance dry-run failed with exit code $LASTEXITCODE."
}

$taskInfo = Get-ScheduledTaskInfo -TaskName $TaskName

Write-Host ''
Write-Host 'Phase 5M3B verification passed.'
Write-Host "Scheduled task state: $($scheduledTask.State)"
Write-Host "Last scheduled result: $($taskInfo.LastTaskResult)"
Write-Host "Next scheduled run: $($taskInfo.NextRunTime)"

    