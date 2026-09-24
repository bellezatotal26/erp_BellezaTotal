param(
    [string]$Output = "build\seri-update-seguro.zip",
    [switch]$SkipComposer
)

$ErrorActionPreference = "Stop"
$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$BuildRoot = Join-Path $Root "build"
$Stage = Join-Path $BuildRoot "seri-update"
$ZipPath = if ([System.IO.Path]::IsPathRooted($Output)) { $Output } else { Join-Path $Root $Output }

Write-Host "== Seri ERP · paquete UPDATE SEGURO (conserva datos) ==" -ForegroundColor Cyan
Write-Host "Origen: $Root"

if (-not $SkipComposer) {
    Write-Host "Composer production..."
    Push-Location $Root
    try {
        if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
            throw "Composer no esta en PATH. Usa -SkipComposer si vendor/ ya esta listo."
        }
        composer install --no-dev --optimize-autoloader --no-interaction
        if ($LASTEXITCODE -ne 0) {
            throw "Composer install fallo."
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

Write-Host "Copiando archivos (sin config de produccion ni uploads)..."

$excludedDirs = @(
    ".git", ".cursor", ".idea", ".vscode",
    "build", "node_modules", "agent-transcripts", "assets",
    "storage\backups", "storage\cache", "storage\logs", "storage\sessions",
    "public\uploads\avatars", "public\uploads\products", "public\uploads\suppliers",
    "public\uploads\branding", "public\uploads\purchases", "public\uploads\expenses"
)

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
    "database\error_log",
    "public\_diag_caja.php",
    "public\_migrate_db.php",
    "public\_repair_caja_admin.php",
    "database\wipe_operational_bellezat.sql",
    "database\limpiar_bd_solo_admin.sql",
    "database\reset_admin_password.sql",
    "install.php",
    "public\install.php"
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
    throw "Robocopy fallo con codigo $LASTEXITCODE."
}

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

foreach ($sec in @("avatars", "branding", "purchases")) {
    $srcHt = Join-Path $Root "public\uploads\$sec\.htaccess"
    $dstDir = Join-Path $Stage "public\uploads\$sec"
    if (Test-Path $srcHt) {
        New-Item -ItemType Directory -Path $dstDir -Force | Out-Null
        Copy-Item $srcHt (Join-Path $dstDir ".htaccess") -Force
    }
}

$srcUploadsHt = Join-Path $Root "public\uploads\.htaccess"
if (Test-Path $srcUploadsHt) {
    Copy-Item $srcUploadsHt (Join-Path $Stage "public\uploads\.htaccess") -Force
}

$when = Get-Date -Format "yyyy-MM-dd HH:mm"
$readme = @"
================================================================================
SERI ERP - UPDATE SEGURO (sitio YA en uso / con datos)
Paquete generado: $when
================================================================================

IMPORTANTE - NO PIERDAS EL PROGRESO DEL CLIENTE
----------------------------------------------
Este ZIP actualiza CODIGO. NO incluye ni debe tocar:
  - config/database.php / config/app.php / .env
  - storage/installed.lock / storage/app.key
  - public/uploads/ (fotos, facturas, branding)
  - scripts wipe/limpiar/reset ni install.php
  - La base de datos (productos, ventas, OC, caja, etc.)

NO uses el ZIP de instalacion limpia sobre un sitio con datos.

PASOS (cPanel File Manager o FTP)
---------------------------------
1) Backup rapido en el servidor (obligatorio):
   - Copia config/database.php -> database.php.bak
   - Copia config/app.php -> app.php.bak
   - (Recomendado) backup de BD en phpMyAdmin / cPanel

2) Extrae este ZIP en la RAIZ del dominio
   (misma carpeta donde ya estan app/, config/, public/, vendor/).
   Confirma que al extraer NO aparece un config/database.php nuevo.
   Si el File Manager pregunta "sobrescribir", acepta el codigo
   pero verifica que database.php y app.php NO se reemplazaron.

3) Tras extraer, abre config/ y comprueba:
   - database.php sigue con las credenciales reales
   - app.php sigue con debug=false y la URL del cliente

4) Permisos: storage/ y public/uploads/ escribibles (755/775 tipico).

5) Migrar esquema SOLO si hace falta (no borra datos):
     cd ~/ruta-del-sitio
     php database/migrate.php
   Si no hay SSH, omite este paso si el sitio ya venia migrado;
   el ERP tambien auto-completa columnas faltantes al usar modulos.

6) Smoke test (sin borrar nada):
   - Login
   - Caja / una venta
   - Compras -> Facturas (Revisar)
   - Inventario -> Trazabilidad
   - Logout (limpia PWA) y volver a entrar

7) Si cae en 500:
   - Restaura database.php.bak / app.php.bak
   - Revisa permisos de vendor/ y storage/
   - NO ejecutes wipe ni limpiar_bd

================================================================================
"@
Set-Content -Path (Join-Path $Stage "LEEME_SUBIR.txt") -Value $readme -Encoding UTF8

foreach ($extra in @(
    (Join-Path $Stage "install.php"),
    (Join-Path $Stage "public\install.php"),
    (Join-Path $Stage "public\_diag_caja.php"),
    (Join-Path $Stage "public\_migrate_db.php"),
    (Join-Path $Stage "public\_repair_caja_admin.php"),
    (Join-Path $Stage "database\wipe_operational_bellezat.sql"),
    (Join-Path $Stage "database\limpiar_bd_solo_admin.sql"),
    (Join-Path $Stage "database\reset_admin_password.sql")
)) {
    if (Test-Path $extra) { Remove-Item $extra -Force -ErrorAction SilentlyContinue }
}
Get-ChildItem -Path $Stage -File -Filter "update_*.zip" -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue

$forbidden = @(
    (Join-Path $Stage "config\database.php"),
    (Join-Path $Stage "config\app.php"),
    (Join-Path $Stage ".env"),
    (Join-Path $Stage "storage\installed.lock"),
    (Join-Path $Stage "install.php"),
    (Join-Path $Stage "public\install.php"),
    (Join-Path $Stage "database\limpiar_bd_solo_admin.sql"),
    (Join-Path $Stage "database\wipe_operational_bellezat.sql"),
    (Join-Path $Stage "database\reset_admin_password.sql")
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
Write-Host "Abre LEEME_SUBIR.txt dentro del ZIP antes de extraer."
Write-Host "config/database.php NO va en el paquete. Datos del cliente intactos."
