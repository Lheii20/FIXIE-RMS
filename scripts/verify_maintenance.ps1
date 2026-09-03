[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\xampp\htdocs\fixie_drms',
    [string]$PhpExecutable = 'C:\xampp\php\php.exe',
    [string]$TaskName = 'Fixie DRMS Daily Maintenance'
)

$ErrorActionPreference = 'Stop'
$resolvedProject = (Resolve-Path -LiteralPath $ProjectRoot).Path
$resolvedPhp = (Resolve-Path -LiteralPath $PhpExecutable).Path
$runner = Join-Path $resolvedProject 'cron_maintenance.php'
$engine = Join-Path $resolvedProject 'config\maintenance_engine.php'
$databaseBootstrap = Join-Path $resolvedProject 'config\maintenance_db.php'
$functionsFile = Join-Path $resolvedProject 'config\functions.php'
$collectionHelper = Join-Path $resolvedProject 'config\collection_reminders.php'

foreach ($file in @($runner, $engine, $databaseBootstrap, $functionsFile, $collectionHelper)) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "Missing required file: $file"
    }
    & $resolvedPhp -l $file
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax check failed: $file" }
}

Write-Host ''
Write-Host 'Running read-only maintenance evaluation...'
& $resolvedPhp $runner --dry-run
if ($LASTEXITCODE -ne 0) {
    throw "Maintenance dry-run failed with exit code $LASTEXITCODE."
}

Write-Host ''
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($null -eq $task) {
    Write-Warning "Scheduled task is not installed yet: $TaskName"
} else {
    $info = Get-ScheduledTaskInfo -TaskName $TaskName
    Write-Host "Scheduled task: $($task.State)"
    Write-Host "Last result: $($info.LastTaskResult)"
    Write-Host "Next run: $($info.NextRunTime)"
}

Write-Host ''
Write-Host 'Maintenance verification completed.'
