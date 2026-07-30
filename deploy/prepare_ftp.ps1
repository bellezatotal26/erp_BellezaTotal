param(
    [string]$Output = "build\seri-erp-ftp.zip"
)

$ErrorActionPreference = "Stop"
$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$BuildRoot = Join-Path $Root "build"
$Stage = Join-Path $BuildRoot "seri-erp"
$ZipPath = Join-Path $Root $Output

Write-Host "Preparando dependencias de producción..."
Push-Location $Root
try {
    if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
        throw "Composer no está disponible. Instálelo o ejecute composer install manualmente."
    }
    composer install --no-dev --optimize-autoloader --no-interaction
    if ($LASTEXITCODE -ne 0) {
        throw "Composer install falló."
    }
}
finally {
    Pop-Location
}

if (Test-Path $Stage) {
    Remove-Item $Stage -Recurse -Force
}
New-Item -ItemType Directory -Path $Stage -Force | Out-Null

Write-Host "Copiando archivos para FTP..."
$excludedDirs = @(
    ".git", ".cursor", ".idea", ".vscode", "build", "node_modules",
    "storage\backups", "storage\cache", "storage\logs", "storage\sessions"
)
$excludedFiles = @(".env", "config\database.php", "config\app.php")

$args = @($Root, $Stage, "/E", "/NFL", "/NDL", "/NJH", "/NJS", "/NP")
foreach ($dir in $excludedDirs) {
    $args += @("/XD", (Join-Path $Root $dir))
}
foreach ($file in $excludedFiles) {
    $args += @("/XF", (Join-Path $Root $file))
}

& robocopy @args | Out-Null
if ($LASTEXITCODE -gt 7) {
    throw "Robocopy falló con código $LASTEXITCODE."
}

foreach ($writable in @(
    "storage\backups", "storage\cache", "storage\logs", "storage\sessions",
    "public\uploads\avatars"
)) {
    $path = Join-Path $Stage $writable
    New-Item -ItemType Directory -Path $path -Force | Out-Null
    New-Item -ItemType File -Path (Join-Path $path ".gitkeep") -Force | Out-Null
}

if (Test-Path $ZipPath) {
    Remove-Item $ZipPath -Force
}
$zipParent = Split-Path $ZipPath -Parent
New-Item -ItemType Directory -Path $zipParent -Force | Out-Null

Write-Host "Comprimiendo paquete..."
Compress-Archive -Path (Join-Path $Stage "*") -DestinationPath $ZipPath -CompressionLevel Optimal

Write-Host ""
Write-Host "Paquete listo: $ZipPath" -ForegroundColor Green
Write-Host "No contiene .env ni la configuración local de base de datos."
Write-Host "Suba su contenido por FTP y visite /install.php."
