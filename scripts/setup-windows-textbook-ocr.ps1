#Requires -Version 5.1
<#
.SYNOPSIS
  Detect Tesseract + Poppler for Laravel textbook OCR and optionally update backend/.env.

.DESCRIPTION
  - Does NOT download or install software silently.
  - Prints manual Windows installation instructions when tools are missing.
  - Updates only these keys in backend/.env:
      TEXTBOOK_OCR_ENABLED
      TESSERACT_PATH
      PDFTOPPM_PATH
      PDFTOTEXT_PATH

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File backend\scripts\setup-windows-textbook-ocr.ps1
#>

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$BackendDir = Resolve-Path (Join-Path $ScriptDir '..')
$EnvFile = Join-Path $BackendDir '.env'
$EnvExample = Join-Path $BackendDir '.env.example'

function Write-Section([string]$Title) {
    Write-Host ''
    Write-Host $Title
    Write-Host ('=' * $Title.Length)
}

function Test-ExecutablePath([string]$Path) {
    return [bool]($Path -and (Test-Path -LiteralPath $Path -PathType Leaf))
}

function Find-Executable([string[]]$Names, [string[]]$CandidatePaths) {
    foreach ($name in $Names) {
        $command = Get-Command $name -ErrorAction SilentlyContinue
        if ($command -and (Test-Path -LiteralPath $command.Source -PathType Leaf)) {
            return $command.Source
        }
    }

    foreach ($path in $CandidatePaths) {
        if (Test-ExecutablePath $path) {
            return $path
        }
    }

    return $null
}

function Get-TesseractLanguages([string]$TesseractPath) {
    if (-not (Test-ExecutablePath $TesseractPath)) {
        return @()
    }

    $output = & $TesseractPath --list-langs 2>&1
    $languages = @()

    foreach ($line in $output) {
        $trimmed = [string]$line
        if ($trimmed -match '^(List of available languages|tesseract\s|Error|Leptonica)') {
            continue
        }
        $trimmed = $trimmed.Trim()
        if ($trimmed -ne '') {
            $languages += $trimmed
        }
    }

    return $languages
}

function Update-EnvKeys([string]$Path, [hashtable]$Updates) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        if (Test-Path -LiteralPath $EnvExample -PathType Leaf) {
            Copy-Item -LiteralPath $EnvExample -Destination $Path
            Write-Host "Created .env from .env.example"
        } else {
            New-Item -ItemType File -Path $Path -Force | Out-Null
            Write-Host "Created empty .env"
        }
    }

    $lines = Get-Content -LiteralPath $Path -Encoding UTF8
    $keys = @($Updates.Keys)
    $remaining = New-Object System.Collections.Generic.HashSet[string] ([string[]]$keys)
    $result = New-Object System.Collections.Generic.List[string]

    foreach ($line in $lines) {
        $matched = $false

        foreach ($key in $keys) {
            if ($line -match "^\s*$([regex]::Escape($key))\s*=") {
                $result.Add("$key=$($Updates[$key])")
                [void]$remaining.Remove($key)
                $matched = $true
                break
            }
        }

        if (-not $matched) {
            [void]$result.Add($line)
        }
    }

    foreach ($key in $remaining) {
        $result.Add("$key=$($Updates[$key])")
    }

    Set-Content -LiteralPath $Path -Value $result -Encoding UTF8
}

Write-Section 'Tahdi Altalabeh - Windows OCR / Poppler setup (detect only)'

$TesseractCandidates = @(
    'C:\Program Files\Tesseract-OCR\tesseract.exe',
    'C:\Program Files (x86)\Tesseract-OCR\tesseract.exe'
)

$PopplerBinDirs = @(
    'C:\poppler\Library\bin',
    'C:\Program Files\poppler\Library\bin',
    'C:\Program Files\poppler-24.08.0\Library\bin',
    'C:\xampp\poppler\Library\bin'
)

$PdftoppmCandidates = foreach ($dir in $PopplerBinDirs) { Join-Path $dir 'pdftoppm.exe' }
$PdftotextCandidates = foreach ($dir in $PopplerBinDirs) { Join-Path $dir 'pdftotext.exe' }

$TesseractPath = Find-Executable @('tesseract') $TesseractCandidates
$PdftoppmPath = Find-Executable @('pdftoppm') $PdftoppmCandidates
$PdftotextPath = Find-Executable @('pdftotext') $PdftotextCandidates

$TesseractFound = Test-ExecutablePath $TesseractPath
$PdftoppmFound = Test-ExecutablePath $PdftoppmPath
$PdftotextFound = Test-ExecutablePath $PdftotextPath

$ArabicFound = $false
$Languages = @()

if ($TesseractFound) {
    $Languages = Get-TesseractLanguages $TesseractPath
    $ArabicFound = $Languages | Where-Object { $_ -eq 'ara' -or $_.StartsWith('ara') } | Select-Object -First 1
}

Write-Section 'Detection results'
Write-Host "Tesseract found: $(if ($TesseractFound) { 'yes' } else { 'no' })"
if ($TesseractFound) { Write-Host "  path: $TesseractPath" }
Write-Host "Arabic trained data (ara): $(if ($ArabicFound) { 'yes' } else { 'no' })"
Write-Host "pdftoppm found: $(if ($PdftoppmFound) { 'yes' } else { 'no' })"
if ($PdftoppmFound) { Write-Host "  path: $PdftoppmPath" }
Write-Host "pdftotext found: $(if ($PdftotextFound) { 'yes' } else { 'no' })"
if ($PdftotextFound) { Write-Host "  path: $PdftotextPath" }

$RuntimeReady = $TesseractFound -and $ArabicFound -and $PdftoppmFound
Write-Host "OCR runtime ready: $(if ($RuntimeReady) { 'yes' } else { 'no' })"

if (-not $TesseractFound -or -not $ArabicFound -or -not $PdftoppmFound -or -not $PdftotextFound) {
    Write-Section 'Manual installation (nothing is installed by this script)'

    if (-not $TesseractFound -or -not $ArabicFound) {
        Write-Host @'
Tesseract OCR (Arabic required)
--------------------------------
1. Download the Windows installer from:
   https://github.com/UB-Mannheim/tesseract/wiki
2. Run the installer manually.
3. Expected default path:
   C:\Program Files\Tesseract-OCR\tesseract.exe
4. During setup, select the "Arabic" language pack.
   If Arabic was skipped, download ara.traineddata from:
   https://github.com/tesseract-ocr/tessdata
   and place it in:
   C:\Program Files\Tesseract-OCR\tessdata\ara.traineddata
5. Verify:
   "C:\Program Files\Tesseract-OCR\tesseract.exe" --list-langs
   The output must include: ara
'@
    }

    if (-not $PdftoppmFound -or -not $PdftotextFound) {
        Write-Host @'
Poppler for Windows (pdftoppm + pdftotext)
------------------------------------------
1. Download a release ZIP from:
   https://github.com/oschwartz10612/poppler-windows/releases
2. Extract manually, for example to:
   C:\poppler\
3. Expected binaries:
   C:\poppler\Library\bin\pdftoppm.exe
   C:\poppler\Library\bin\pdftotext.exe
4. Optional: add C:\poppler\Library\bin to your PATH.
5. Verify:
   pdftoppm -h
   pdftotext -h
'@
    }

    Write-Host ''
    Write-Host 'Re-run this script after installing the tools.'
}

if ($RuntimeReady) {
    Write-Section '.env update'
    Write-Host "Target file: $EnvFile"
    Write-Host 'Only these keys will be created or updated:'
    Write-Host '  TEXTBOOK_OCR_ENABLED'
    Write-Host '  TESSERACT_PATH'
    Write-Host '  PDFTOPPM_PATH'
    Write-Host '  PDFTOTEXT_PATH'
    Write-Host ''
    Write-Host 'Proposed values:'
    Write-Host '  TEXTBOOK_OCR_ENABLED=true'
    Write-Host "  TESSERACT_PATH=$TesseractPath"
    Write-Host "  PDFTOPPM_PATH=$PdftoppmPath"
    Write-Host "  PDFTOTEXT_PATH=$PdftotextPath"

    $answer = Read-Host 'Write these values to backend/.env now? [y/N]'
    if ($answer -match '^(y|yes)$') {
        Update-EnvKeys $EnvFile @{
            TEXTBOOK_OCR_ENABLED = 'true'
            TESSERACT_PATH       = $TesseractPath
            PDFTOPPM_PATH        = $PdftoppmPath
            PDFTOTEXT_PATH       = $PdftotextPath
        }
        Write-Host '.env updated.'
    } else {
        Write-Host 'Skipped .env update.'
    }
}

Write-Section 'Laravel diagnostic command'
$Php = $null
foreach ($candidate in @('php', 'C:\xampp\php\php.exe')) {
    $command = Get-Command $candidate -ErrorAction SilentlyContinue
    if ($command) {
        $Php = $command.Source
        break
    }
}

if ($Php) {
    Push-Location $BackendDir
    try {
        & $Php artisan textbook:ocr-diagnose
    } finally {
        Pop-Location
    }
} else {
    Write-Host 'PHP not found on PATH. After installing tools, run:'
    Write-Host '  cd backend'
    Write-Host '  php artisan textbook:ocr-diagnose'
}

Write-Section 'Reprocess an existing textbook (stored PDF)'
Write-Host 'Replace {textbook_id} with your textbook UUID.'
Write-Host ''
Write-Host '  cd backend'
Write-Host '  php artisan textbook:reprocess-extraction {textbook_id}'
Write-Host '  php artisan queue:work --queue=textbook-extraction --tries=1'
Write-Host ''
Write-Host 'Example:'
Write-Host '  php artisan textbook:reprocess-extraction 01a04ebe-3f42-7125-a1d8-2662dd1d0dad'
Write-Host ''
Write-Host 'OCR policy: front-matter pages only when extraction quality is below threshold.'
Write-Host 'The full 131-page book is NOT OCR-scanned by default.'

if (-not $RuntimeReady) {
    exit 1
}

exit 0
