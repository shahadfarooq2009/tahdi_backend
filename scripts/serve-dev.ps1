# Laravel dev: HTTP server + queue worker (required for textbook PDF processing).
$ErrorActionPreference = "Stop"
$port = 8000
$hostAddr = "127.0.0.1"
$backendRoot = Split-Path $PSScriptRoot -Parent
$pidFile = Join-Path $backendRoot "storage\app\queue-worker-dev.pid"
$workerLog = Join-Path $backendRoot "storage\logs\queue-worker-dev.log"

function Get-ListenersOnPort([int]$listenPort) {
  netstat -ano | Select-String ":$listenPort\s" | ForEach-Object {
    if ($_ -match "LISTENING\s+(\d+)\s*$") {
      [int]$Matches[1]
    }
  } | Sort-Object -Unique
}

function Test-WorkerProcessAlive([int]$processId) {
  if ($processId -le 0) { return $false }
  $proc = Get-Process -Id $processId -ErrorAction SilentlyContinue
  return $null -ne $proc -and -not $proc.HasExited
}

$listeners = Get-ListenersOnPort $port
foreach ($processId in $listeners) {
  if ($processId -le 0) { continue }
  $proc = Get-Process -Id $processId -ErrorAction SilentlyContinue
  if ($null -eq $proc) { continue }
  if ($proc.ProcessName -ne "php") { continue }
  Write-Host "Stopping existing PHP listener on ${hostAddr}:$port (PID $processId)..."
  Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue
  Start-Sleep -Milliseconds 300
}

$php = $env:PHP_BINARY
if (-not $php) {
  $candidates = @(
    "C:\xampp\php\php.exe",
    "php"
  )
  foreach ($candidate in $candidates) {
    if (Get-Command $candidate -ErrorAction SilentlyContinue) {
      $php = $candidate
      break
    }
  }
}

if (-not $php) {
  throw "PHP executable not found. Set PHP_BINARY or install XAMPP PHP."
}

$phpCliOverrides = @(
  "-d", "upload_max_filesize=1100M",
  "-d", "post_max_size=1200M",
  "-d", "memory_limit=1024M",
  "-d", "max_execution_time=0"
)

$publicDir = Join-Path $backendRoot "public"
$artisan = Join-Path $backendRoot "artisan"

if (-not (Test-Path $artisan)) {
  throw "Laravel artisan not found: $artisan"
}

if (-not (Test-Path (Join-Path $publicDir "index.php"))) {
  throw "Laravel public entry point not found: $(Join-Path $publicDir 'index.php')"
}

Write-Host ""
Write-Host "=== Tahdi backend (HTTP + queue worker) ==="
Write-Host "Command: npm run dev:backend"
Write-Host "Queues:  textbook-extraction,textbook-analysis,question-generation,default"
Write-Host "Worker timeout: 3600s"
Write-Host ""

$queueProcess = $null

Push-Location $backendRoot
try {
  if (Test-Path $pidFile) {
    $existingPid = [int](Get-Content $pidFile -ErrorAction SilentlyContinue)
    if (Test-WorkerProcessAlive $existingPid) {
      Write-Host "Reusing existing queue worker (PID $existingPid)"
      $queueProcess = Get-Process -Id $existingPid
    }
  }

  if ($null -eq $queueProcess) {
    Write-Host "Starting queue worker..."
    $queueProcess = Start-Process `
      -FilePath $php `
      -ArgumentList ($phpCliOverrides + @(
        "artisan", "queue:work", "database",
        "--queue=textbook-extraction,textbook-analysis,question-generation,default",
        "--tries=1", "--timeout=3600", "--max-time=3600"
      )) `
      -WorkingDirectory $backendRoot `
      -PassThru `
      -WindowStyle Hidden

    Set-Content -Path $pidFile -Value $queueProcess.Id
    Write-Host "Queue worker PID $($queueProcess.Id) (log: $workerLog)"
    Start-Sleep -Seconds 1
  }

  Write-Host "Starting Laravel HTTP server on http://${hostAddr}:$port ..."
  Write-Host ""

  & $php @($phpCliOverrides + @(
    $artisan, "serve", "--host=$hostAddr", "--port=$port"
  ))
}
finally {
  if ($null -ne $queueProcess -and -not $queueProcess.HasExited) {
    Write-Host "Stopping queue worker (PID $($queueProcess.Id))..."
    Stop-Process -Id $queueProcess.Id -Force -ErrorAction SilentlyContinue
    if (Test-Path $pidFile) {
      Remove-Item $pidFile -Force -ErrorAction SilentlyContinue
    }
  }
  Pop-Location
}
