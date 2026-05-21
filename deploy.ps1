#
# deploy.ps1 - Sube archivos al server Luxom CRM con backup previo.
#
# Uso: doble-click en deploy.bat (que llama a este script)
#      o desde PowerShell: .\deploy.ps1
#
# Configuracion: editar las variables abajo si cambia el server.
# Lista de archivos a subir: to-deploy.txt
#

$ErrorActionPreference = "Stop"

# ===== Config server =====
$ServerHost = "89.117.7.38"
$ServerPort = 65002
$ServerUser = "u695160153"
$RemoteBase = "/home/u695160153/domains/luxom.com.ar/public_html/crm"
$SshKey     = "$env:USERPROFILE\.ssh\luxom_deploy"
$DeployList = Join-Path $PSScriptRoot "to-deploy.txt"
$BackupTag  = "bak_" + (Get-Date -Format "yyyyMMdd_HHmmss")

# ===== Archivos que NUNCA se suben (lista de seguridad) =====
$Forbidden = @(
    ".env",
    ".htaccess",
    "error_log"
)
$ForbiddenPrefixes = @(
    "assets/uploads/",
    "vendor/",
    "frontend/node_modules/",
    "frontend/dist/"
)

# ===== Sanity checks =====
if (-not (Test-Path $DeployList)) {
    Write-Host "ERROR: $DeployList no existe." -ForegroundColor Red
    exit 1
}
if (-not (Test-Path $SshKey)) {
    Write-Host "ERROR: SSH key no encontrada en $SshKey" -ForegroundColor Red
    Write-Host "Si todavia no la registramos en el server, pediselo a openclaw." -ForegroundColor Yellow
    exit 1
}

# ===== Cargar lista de archivos =====
$Files = @(Get-Content $DeployList | Where-Object {
    $_ -and ($_ -notmatch '^\s*#') -and ($_.Trim() -ne '')
} | ForEach-Object { $_.Trim() -replace '\\','/' })

if ($Files.Count -eq 0) {
    Write-Host "to-deploy.txt esta vacio. Nada para subir." -ForegroundColor Yellow
    exit 0
}

# ===== Chequeo de seguridad =====
foreach ($f in $Files) {
    if ($Forbidden -contains $f) {
        Write-Host "ERROR: '$f' esta en la lista de archivos prohibidos. Abortando." -ForegroundColor Red
        exit 1
    }
    foreach ($p in $ForbiddenPrefixes) {
        if ($f.StartsWith($p)) {
            Write-Host "ERROR: '$f' esta bajo '$p' (prohibido). Abortando." -ForegroundColor Red
            exit 1
        }
    }
    if (-not (Test-Path $f)) {
        Write-Host "ERROR: archivo local '$f' no existe." -ForegroundColor Red
        exit 1
    }
}

Write-Host ""
Write-Host "==============================================" -ForegroundColor Cyan
Write-Host " Deploy Luxom CRM" -ForegroundColor Cyan
Write-Host "==============================================" -ForegroundColor Cyan
Write-Host " Server:   $ServerUser@${ServerHost}:$ServerPort" -ForegroundColor Cyan
Write-Host " Path:     $RemoteBase" -ForegroundColor Cyan
Write-Host " Backup:   $BackupTag" -ForegroundColor Cyan
Write-Host " Archivos: $($Files.Count)" -ForegroundColor Cyan
Write-Host "==============================================" -ForegroundColor Cyan
Write-Host ""

$idx = 0
foreach ($file in $Files) {
    $idx++
    $remotePath = "$RemoteBase/$file"
    $remoteDir  = ($remotePath -replace '/[^/]+$','')

    Write-Host "[$idx/$($Files.Count)] $file" -ForegroundColor White

    # Asegurar que el directorio remoto exista
    & ssh -i "$SshKey" -p $ServerPort -o StrictHostKeyChecking=no -o BatchMode=yes "$ServerUser@$ServerHost" "mkdir -p '$remoteDir'" 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "  ERROR: no se pudo crear el directorio remoto." -ForegroundColor Red
        exit 1
    }

    # Backup remoto (si el archivo ya existe)
    Write-Host "  Backup remoto.... " -NoNewline
    $bkCmd = "if [ -f '$remotePath' ]; then cp '$remotePath' '$remotePath.$BackupTag'; echo backed; else echo skipped; fi"
    $bkOut = & ssh -i "$SshKey" -p $ServerPort -o StrictHostKeyChecking=no -o BatchMode=yes "$ServerUser@$ServerHost" $bkCmd 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "FALLA" -ForegroundColor Red
        exit 1
    }
    if ($bkOut -match 'skipped') {
        Write-Host "no existia (nuevo)" -ForegroundColor DarkGray
    } else {
        Write-Host "OK" -ForegroundColor Green
    }

    # Subir archivo
    Write-Host "  Subiendo......... " -NoNewline
    & scp -i "$SshKey" -P $ServerPort -o StrictHostKeyChecking=no -o BatchMode=yes "$file" "${ServerUser}@${ServerHost}:$remotePath" 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "FALLA" -ForegroundColor Red
        exit 1
    }
    Write-Host "OK" -ForegroundColor Green
}

Write-Host ""
Write-Host "==============================================" -ForegroundColor Green
Write-Host " Deploy completo: $($Files.Count) archivos subidos." -ForegroundColor Green
Write-Host " Backups remotos con sufijo: .$BackupTag" -ForegroundColor Gray
Write-Host "==============================================" -ForegroundColor Green
Write-Host ""
