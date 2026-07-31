param(
    [string]$Output = "build\seri-update-heracons.zip",
    [switch]$SkipComposer
)

$ErrorActionPreference = "Stop"
$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$BuildRoot = Join-Path $Root "build"
$Stage = Join-Path $BuildRoot "seri-update"
$ZipPath = if ([System.IO.Path]::IsPathRooted($Output)) { $Output } else { Join-Path $Root $Output }

Write-Host "== Seri ERP · paquete UPDATE (servidor de prueba) ==" -ForegroundColor Cyan
Write-Host "Origen: $Root"

if (-not $SkipComposer) {
    Write-Host "Composer production..."
    Push-Location $Root
    try {
        if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
            throw "Composer no está en PATH. Instálalo o ejecuta con -SkipComposer si vendor/ ya está listo."
        }
        composer install --no-dev --optimize-autoloader --no-interaction
        if ($LASTEXITCODE -ne 0) {
            throw "Composer install falló."
        }
    }
    finally {
        Pop-Location
    }
} else {
    Write-Host "Omitiendo composer (-SkipComposer)." -ForegroundColor Yellow
    if (-not (Test-Path (Join-Path $Root "vendor\autoload.php"))) {
        throw "No existe vendor/autoload.php. Quita -SkipComposer o corre composer install."
    }
}

if (Test-Path $Stage) {
    Remove-Item $Stage -Recurse -Force
}
New-Item -ItemType Directory -Path $Stage -Force | Out-Null

Write-Host "Copiando archivos (sin config de producción ni uploads)..."

# Carpetas que no van al ZIP de update
$excludedDirs = @(
    ".git", ".cursor", ".idea", ".vscode",
    "build", "node_modules",
    "storage\backups", "storage\cache", "storage\logs", "storage\sessions",
    "public\uploads\avatars", "public\uploads\products", "public\uploads\suppliers",
    "public\uploads\branding", "public\uploads\purchases", "public\uploads\expenses"
)

# Archivos que NUNCA deben sobrescribir el servidor
$excludedFiles = @(
    ".env",
    "config\database.php",
    "config\app.php",
    "storage\installed.lock",
    "storage\app.key",
    "heracons_seri.sql",
    "error_log",
    "seri_diag.php",
    "public\seri_diag.php",
    "public\error_log",
    "database\error_log"
)

$roboArgs = @($Root, $Stage, "/E", "/NFL", "/NDL", "/NJH", "/NJS", "/NP")
foreach ($dir in $excludedDirs) {
    $roboArgs += @("/XD", (Join-Path $Root $dir))
}
foreach ($file in $excludedFiles) {
    $roboArgs += @("/XF", (Join-Path $Root $file))
}

& robocopy @roboArgs | Out-Null
if ($LASTEXITCODE -gt 7) {
    throw "Robocopy falló con código $LASTEXITCODE."
}

# Estructura writable vacía (no pisa uploads existentes si no se extraen encima a ciegas)
foreach ($writable in @(
    "storage\backups", "storage\cache", "storage\logs", "storage\sessions",
    "public\uploads", "public\uploads\avatars", "public\uploads\products",
    "public\uploads\suppliers", "public\uploads\branding", "public\uploads\purchases"
)) {
    $path = Join-Path $Stage $writable
    New-Item -ItemType Directory -Path $path -Force | Out-Null
    $keep = Join-Path $path ".gitkeep"
    if (-not (Test-Path $keep)) {
        New-Item -ItemType File -Path $keep -Force | Out-Null
    }
}

# Copiar .htaccess de uploads seguros si existen en el repo
foreach ($sec in @("avatars", "branding", "purchases")) {
    $srcHt = Join-Path $Root "public\uploads\$sec\.htaccess"
    $dstDir = Join-Path $Stage "public\uploads\$sec"
    if (Test-Path $srcHt) {
        New-Item -ItemType Directory -Path $dstDir -Force | Out-Null
        Copy-Item $srcHt (Join-Path $dstDir ".htaccess") -Force
    }
}

# Instrucciones dentro del ZIP
$readme = @"
================================================================================
SERI ERP — UPDATE a seri.heraconsultores.com
Paquete generado: $(Get-Date -Format 'yyyy-MM-dd HH:mm')
================================================================================

ESTE ZIP NO INCLUYE (a propósito):
  - config/database.php   ← NO LO TOQUES / NO LO SOBRESCRIBAS
  - config/app.php        ← conserva el del servidor
  - .env / storage/installed.lock / storage/app.key
  - contenido de public/uploads/ (fotos ya subidas)

PASOS (cPanel File Manager o FTP)
---------------------------------
1) Backup rápido en el servidor:
   - Descarga o copia config/database.php a database.php.bak
   - (Opcional) backup de todo el sitio + BD

2) Extrae este ZIP en la RAÍZ del dominio
   (misma carpeta donde ya están app/, config/, public/, vendor/).
   Confirma que al extraer NO aparece un config/database.php nuevo.
   Si el File Manager pregunta "sobrescribir", acepta el código
   pero verifica que database.php NO se reemplazó.

3) Tras extraer, abre config/ y comprueba que database.php
   sigue con usuario/clave de cPanel (NO root / password vacía).

4) Permisos: storage/ y public/uploads/ escribibles (755/775 típico).

5) Migrar esquema de tenants (SSH o Terminal cPanel):
     cd ~/seri.heraconsultores.com   # ajusta a tu ruta real
     php database/migrate_existing.php

   Si no hay SSH: sube temporalmente un one-click migrate vía
   ticket/soporte, o corre el PHP CLI desde cPanel Terminal.

6) Smoke test:
   - https://seri.heraconsultores.com/  (login)
   - Ventas / Contabilidad→Comisiones / Compras (foto) / Inventario→Trazabilidad

7) Si cae en 500:
   - Revisa config/database.php (credenciales cPanel)
   - Revisa permisos de vendor/
   - Pon temporalmente debug=true SOLO en config/app.php del servidor
     (no desde este ZIP) para ver el error, luego vuelve a false.

Plantillas de referencia (NO son las del servidor):
  config/database.TEMPLATE.php
  config/app.production.example.php

================================================================================
"@
Set-Content -Path (Join-Path $Stage "LEEME_SUBIR.txt") -Value $readme -Encoding UTF8

# Verificación anti-fuga
$forbidden = @(
    (Join-Path $Stage "config\database.php"),
    (Join-Path $Stage "config\app.php"),
    (Join-Path $Stage ".env"),
    (Join-Path $Stage "storage\installed.lock")
)
$leaked = @()
foreach ($f in $forbidden) {
    if (Test-Path $f) { $leaked += $f }
}
if ($leaked.Count -gt 0) {
    throw ("El paquete contiene archivos prohibidos: " + ($leaked -join ", "))
}

if (Test-Path $ZipPath) {
    Remove-Item $ZipPath -Force
}
$zipParent = Split-Path $ZipPath -Parent
New-Item -ItemType Directory -Path $zipParent -Force | Out-Null

Write-Host "Comprimiendo: $ZipPath"
Compress-Archive -Path (Join-Path $Stage "*") -DestinationPath $ZipPath -CompressionLevel Optimal

$sizeMb = [math]::Round((Get-Item $ZipPath).Length / 1MB, 2)
Write-Host ""
Write-Host "LISTO: $ZipPath ($sizeMb MB)" -ForegroundColor Green
Write-Host "Abre LEEME_SUBIR.txt dentro del ZIP antes de extraer en cPanel."
Write-Host "config/database.php NO va en el paquete."
