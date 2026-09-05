# Benchmark GET /api/admin/categories (direct + via Vite proxy).
param(
  [int]$Count = 10,
  [string]$DirectUrl = "http://127.0.0.1:8000/api/admin/categories",
  [string]$ProxyUrl = "http://localhost:3000/api/admin/categories",
  [string]$AuthHeader = ""
)

function Invoke-TimedRequest([string]$label, [string]$url, [string]$auth = "") {
  $times = @()
  for ($i = 1; $i -le $Count; $i++) {
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
  $args = @("-s", "-o", "NUL", "-w", "%{http_code}", "--max-time", "25", $url)
    if ($auth) {
      $args = @("-s", "-o", "NUL", "-w", "%{http_code}", "--max-time", "25", "-H", "Authorization: Bearer $auth") + $args[4..($args.Length - 1)]
    }
    $code = & curl.exe @args
    $sw.Stop()
    $ms = $sw.ElapsedMilliseconds
    $times += $ms
    Write-Host ("{0} req {1}: HTTP {2} in {3}ms" -f $label, $i, $code, $ms)
  }

  $sorted = $times | Sort-Object
  $avg = [math]::Round(($times | Measure-Object -Average).Average, 0)
  $p50 = $sorted[[int][math]::Floor(($sorted.Count - 1) * 0.5)]
  $p90 = $sorted[[int][math]::Floor(($sorted.Count - 1) * 0.9)]
  $max = ($times | Measure-Object -Maximum).Maximum

  Write-Host ("{0} summary: avg={1}ms p50={2}ms p90={3}ms max={4}ms" -f $label, $avg, $p50, $p90, $max)
  Write-Host ""
}

Write-Host "=== Direct Laravel ($DirectUrl) ==="
Invoke-TimedRequest "direct" $DirectUrl $AuthHeader

Write-Host "=== Via Vite proxy ($ProxyUrl) ==="
Invoke-TimedRequest "proxy" $ProxyUrl $AuthHeader
