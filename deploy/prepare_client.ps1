param(
    [string]$Output = "build\seri-cliente-standalone.zip",
    [switch]$SkipComposer
)

$ErrorActionPreference = "Stop"
$Root = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$BuildRoot = Join-Path $Root "build"
$Stage = Join-Path $BuildRoot "seri-cliente"
$ZipPath = if ([System.IO.Path]::IsPathRooted($Output)) { $Output } else { Join-Path $Root $Output }

Write-Host "== Seri ERP · paquete CLIENTE STANDALONE ==" -ForegroundColor Cyan

if (-not $SkipComposer) {
    Push-Location $Root
    try {
        composer install --no-dev --optimize-autoloader --no-interaction
        if ($LASTEXITCODE -ne 0) { throw "Composer falló" }
    } finally { Pop-Location }
}

if (Test-Path $Stage) { Remove-Item $Stage -Recurse -Force }
New-Item -ItemType Directory -Path $Stage -Force | Out-Null

$excludedDirs = @(
    ".git", ".cursor", ".idea", ".vscode", "build", "node_modules",
    "agent-transcripts", "assets",
    "storage\backups", "storage\cache", "storage\logs", "storage\sessions",
    "public\uploads\avatars", "public\uploads\products", "public\uploads\suppliers",
    "public\uploads\branding", "public\uploads\purchases", "public\uploads\expenses"
)
$excludedFiles = @(
    ".env", "config\database.php", "config\app.php",
    "storage\installed.lock", "storage\app.key",
    "heracons_seri.sql", "error_log", "public\error_log",
    "seri_diag.php", "public\seri_diag.php",
    "public\_diag_caja.php", "public\_migrate_db.php", "public\_repair_caja_admin.php",
    "database\wipe_operational_bellezat.sql",
    "database\limpiar_bd_solo_admin.sql",
    "database\reset_admin_password.sql"
)

$roboArgs = @($Root, $Stage, "/E", "/NFL", "/NDL", "/NJH", "/NJS", "/NP")
foreach ($d in $excludedDirs) { $roboArgs += @("/XD", (Join-Path $Root $d)) }
foreach ($f in $excludedFiles) { $roboArgs += @("/XF", (Join-Path $Root $f)) }
& robocopy @roboArgs | Out-Null
if ($LASTEXITCODE -gt 7) { throw "Robocopy $LASTEXITCODE" }

foreach ($w in @(
    "storage\backups","storage\cache","storage\logs","storage\sessions",
    "public\uploads","public\uploads\avatars","public\uploads\products",
    "public\uploads\suppliers","public\uploads\branding","public\uploads\purchases"
)) {
    $p = Join-Path $Stage $w
    New-Item -ItemType Directory -Path $p -Force | Out-Null
    if (-not (Test-Path (Join-Path $p ".gitkeep"))) {
        New-Item -ItemType File -Path (Join-Path $p ".gitkeep") -Force | Out-Null
    }
}

$guide = @"
================================================================================
SERI ERP — INSTALACIÓN CLIENTE (SIN SUPERADMIN)
================================================================================

1) En cPanel deja lista la BD:
   - Base: heracons_seri
   - Usuario: heracons_maestro
   - Contraseña: (la de cPanel)
   - Usuario con ALL PRIVILEGES sobre esa BD

2) Borra el contenido anterior del dominio (si vas a montar limpio).
   Conserva solo si quieres: nada crítico — es instalación nueva.

3) Extrae este ZIP en la raíz del dominio
   (debe quedar app/, config/, public/, vendor/, install.php, etc.)

4) Abre en el navegador:
   https://seri.heraconsultores.com/install.php
   (o .../public/install.php según document root)

5) Completa el formulario:
   - BD: heracons_seri
   - User: heracons_maestro
   - Password: la real
   - URL: https://seri.heraconsultores.com
   - Crea admin del ERP (correo + clave)

6) El instalador escribe config/database.php y config/app.php
   con deployment=standalone (sin panel admin SaaS).

7) Elimina install.php del servidor (raíz y public/install.php).

8) NO subas ni ejecutes scripts wipe/limpiar/reset de database/.

9) Verifica DocumentRoot = public/ y que /config /database /.env den 403/404.

10) Entra en /app/login con el admin creado (clave fuerte, nunca Admin123!).

11) Prueba: login, caja, venta, compra/factura, inventario, trazabilidad, PWA caja.

Si luego quieres restaurar datos de tu SQL exportado:
   - phpMyAdmin → heracons_seri → Importar tu dump
   - O elige en el instalador "Ya importé mi SQL" si importaste antes.

================================================================================
"@
Set-Content -Path (Join-Path $Stage "LEEME_INSTALAR_CLIENTE.txt") -Value $guide -Encoding UTF8

# Segunda pasada: quitar artefactos peligrosos si robocopy los copió (ZIPs, logs, diag).
$purgePatterns = @(
    "update_*.zip", "*.zip", "error_log", "public\error_log",
    "public\_diag_*.php", "public\_migrate_*.php", "public\_repair_*.php",
    "database\wipe_*.sql", "database\limpiar_*.sql", "database\reset_admin_password.sql"
)
foreach ($pat in $purgePatterns) {
    Get-ChildItem -Path $Stage -Recurse -Force -ErrorAction SilentlyContinue -Include ($pat.Split("\")[-1]) |
        Where-Object {
            $rel = $_.FullName.Substring($Stage.Length).TrimStart("\")
            $rel -like ($pat -replace "/", "\") -or $_.Name -like ($pat.Split("\")[-1])
        } |
        ForEach-Object {
            # Solo borrar ZIPs en raíz del stage y scripts conocidos
            $rel = $_.FullName.Substring($Stage.Length).TrimStart("\")
            if ($_.Extension -eq ".zip" -and $_.DirectoryName -eq $Stage) {
                Remove-Item $_.FullName -Force -ErrorAction SilentlyContinue
            } elseif ($rel -match '^(public\\_(diag|migrate|repair)|database\\(wipe_|limpiar_|reset_admin)|error_log|public\\error_log)') {
                Remove-Item $_.FullName -Force -ErrorAction SilentlyContinue
            }
        }
}

foreach ($bad in @(
    (Join-Path $Stage "config\database.php"),
    (Join-Path $Stage "config\app.php"),
    (Join-Path $Stage ".env"),
    (Join-Path $Stage "storage\installed.lock"),
    (Join-Path $Stage "public\_diag_caja.php"),
    (Join-Path $Stage "public\_migrate_db.php"),
    (Join-Path $Stage "public\_repair_caja_admin.php"),
    (Join-Path $Stage "database\limpiar_bd_solo_admin.sql"),
    (Join-Path $Stage "database\reset_admin_password.sql"),
    (Join-Path $Stage "database\wipe_operational_bellezat.sql")
)) {
    if (Test-Path $bad) { throw "Archivo prohibido en paquete: $bad" }
}

if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }
New-Item -ItemType Directory -Path (Split-Path $ZipPath -Parent) -Force | Out-Null
Compress-Archive -Path (Join-Path $Stage "*") -DestinationPath $ZipPath -CompressionLevel Optimal
$mb = [math]::Round((Get-Item $ZipPath).Length / 1MB, 2)
Write-Host "LISTO: $ZipPath ($mb MB)" -ForegroundColor Green
