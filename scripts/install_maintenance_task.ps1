[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\xampp\htdocs\fixie_drms',
    [string]$PhpExecutable = 'C:\xampp\php\php.exe',
    [string]$TaskName = 'Fixie DRMS Daily Maintenance',
    [datetime]$DailyAt = '02:00'
)

$ErrorActionPreference = 'Stop'

$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpExecutable).Path
$maintenanceScript = Join-Path $resolvedProject 'cron_maintenance.php'
$engineScript = Join-Path $resolvedProject 'config\maintenance_engine.php'
$databaseBootstrap = Join-Path $resolvedProject 'config\maintenance_db.php'
$functionsFile = Join-Path $resolvedProject 'config\functions.php'
$collectionHelper = Join-Path $resolvedProject 'config\collection_reminders.php'

if (-not (Test-Path -LiteralPath $maintenanceScript -PathType Leaf)) {
    throw "Missing maintenance runner: $maintenanceScript"
}
if (-not (Test-Path -LiteralPath $engineScript -PathType Leaf)) {
    throw "Missing maintenance engine: $engineScript"
}
if (-not (Test-Path -LiteralPath $databaseBootstrap -PathType Leaf)) {
    throw "Missing maintenance database bootstrap: $databaseBootstrap"
}
if (-not (Test-Path -LiteralPath $functionsFile -PathType Leaf)) {
    throw "Missing required system helper: $functionsFile"
}
if (-not (Test-Path -LiteralPath $collectionHelper -PathType Leaf)) {
    throw "Missing required collection helper: $collectionHelper"
}

$lintRunner = & $resolvedPhp -l $maintenanceScript 2>&1
if ($LASTEXITCODE -ne 0) { throw ($lintRunner -join [Environment]::NewLine) }
$lintEngine = & $resolvedPhp -l $engineScript 2>&1
if ($LASTEXITCODE -ne 0) { throw ($lintEngine -join [Environment]::NewLine) }
$lintDatabase = & $resolvedPhp -l $databaseBootstrap 2>&1
if ($LASTEXITCODE -ne 0) { throw ($lintDatabase -join [Environment]::NewLine) }

$action = New-ScheduledTaskAction `
    -Execute $resolvedPhp `
    -Argument ('"{0}" --quiet' -f $maintenanceScript) `
    -WorkingDirectory $resolvedProject
$trigger = New-ScheduledTaskTrigger -Daily -At $DailyAt
$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30) `
    -MultipleInstances IgnoreNew
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

$task = New-ScheduledTask -Action $action -Trigger $trigger -Settings $settings -Principal $principal `
    -Description 'Runs Fixie DRMS retention, reminder, recycle-bin, and security maintenance once per day.'
Register-ScheduledTask -TaskName $TaskName -InputObject $task -Force | Out-Null

$installed = Get-ScheduledTask -TaskName $TaskName
Write-Host "Installed scheduled task: $($installed.TaskName)"
Write-Host "Daily schedule: $($DailyAt.ToString('HH:mm'))"
Write-Host "Runner: $resolvedPhp $maintenanceScript --quiet"
