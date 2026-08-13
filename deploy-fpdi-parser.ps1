# Despliegue del FPDI PDF-Parser (libs/pdf-mod).
#
# deploy.ps1 y deploy-staging.ps1 excluyen "plugins/ep-signature/libs/*", asi que
# las librerias PDF NUNCA viajan en el despliegue normal: viven en el servidor y se
# actualizan a mano porque abultan. Este script hace solo eso.
#
#   .\deploy-fpdi-parser.ps1              -> staging (por defecto)
#   .\deploy-fpdi-parser.ps1 -Target prod -> produccion

param(
    [ValidateSet("staging", "prod")]
    [string]$Target = "staging"
)

$USER_SSH = "lnzryrkp"
$HOST_IP  = "178.211.133.52"
$PORT     = "11022"
$KEY_PATH = "$env:USERPROFILE\.ssh\id_rsa_auto_deploy"
$SSH_CMD  = "C:\Windows\Sysnative\OpenSSH\ssh.exe"
$SCP_CMD  = "C:\Windows\Sysnative\OpenSSH\scp.exe"

if ($Target -eq "prod") {
    $REMOTE_PATH  = "portal.camaracaceres.com/wp-content/plugins/Portal-empleado-1"
    $UPLOADS_PATH = "portal.camaracaceres.com/wp-content/uploads"
    $COLOR = "Cyan"
} else {
    $REMOTE_PATH  = "portal.camaracaceres.com/devpruebas/wp-content/plugins/portal del empleado"
    $UPLOADS_PATH = "portal.camaracaceres.com/devpruebas/wp-content/uploads"
    $COLOR = "Yellow"
}

$LIBS_REMOTE = "$REMOTE_PATH/plugins/ep-signature/libs"

# El script remoto viaja codificado en base64. La ruta de staging lleva espacios y,
# mandando el comando en claro, PowerShell y ssh se reparten el entrecomillado y el
# shell del servidor acaba recibiendo la orden partida por la mitad.
function Invoke-Remote([string]$Script) {
    $bytes = [Text.Encoding]::UTF8.GetBytes($Script)
    $b64 = [Convert]::ToBase64String($bytes)
    & $SSH_CMD -p $PORT -i "$KEY_PATH" -o BatchMode=yes ${USER_SSH}@${HOST_IP} "echo $b64 | base64 -d | bash"
}

Write-Host "========================================" -ForegroundColor $COLOR
Write-Host " FPDI PDF-Parser -> $($Target.ToUpper())" -ForegroundColor $COLOR
Write-Host " Destino: $LIBS_REMOTE/pdf-mod" -ForegroundColor $COLOR
Write-Host "========================================" -ForegroundColor $COLOR

if (-not (Test-Path "plugins\ep-signature\libs\pdf-mod\src\autoload.php")) {
    Write-Host "No encuentro plugins\ep-signature\libs\pdf-mod. Ejecuta desde la raiz del proyecto." -ForegroundColor Red
    exit 1
}

Write-Host "[1/4] Comprimiendo pdf-mod..." -ForegroundColor Gray
tar.exe -czf deploy-pdf-mod.tar.gz --format=ustar -C "plugins\ep-signature\libs" "pdf-mod"
if ($LASTEXITCODE -ne 0) { Write-Host "Error al comprimir." -ForegroundColor Red; exit 1 }

Write-Host "[2/4] Subiendo paquete..." -ForegroundColor Gray
Invoke-Remote "rm -f /tmp/deploy_pdf_mod.tar.gz"
& $SCP_CMD -P $PORT -i "$KEY_PATH" deploy-pdf-mod.tar.gz ${USER_SSH}@${HOST_IP}:/tmp/deploy_pdf_mod.tar.gz
if ($LASTEXITCODE -ne 0) {
    Write-Host "Error al subir por SCP." -ForegroundColor Red
    Remove-Item deploy-pdf-mod.tar.gz -Force -ErrorAction SilentlyContinue
    exit 1
}

Write-Host "[3/4] Sustituyendo la version anterior..." -ForegroundColor Gray
# Se guarda una copia de la version saliente (pdf-mod.bak) para poder volver atras
# sin tener que recuperar el paquete comercial. Y se borra el src viejo entero: si
# solo se sobrescribiera, los ficheros que la version nueva ya no trae seguirian
# ahi y el autoloader podria cargarlos.
$replaceScript = @"
set -e
cd "$LIBS_REMOTE"
chmod -R u+w pdf-mod 2>/dev/null || true
rm -rf pdf-mod.bak
if [ -d pdf-mod ]; then cp -r pdf-mod pdf-mod.bak; fi
rm -rf pdf-mod/src
tar --no-same-permissions -xzf /tmp/deploy_pdf_mod.tar.gz -C .
chmod -R u+w pdf-mod
rm -f /tmp/deploy_pdf_mod.tar.gz
printf '  ficheros PHP instalados: '
find pdf-mod/src -name '*.php' | wc -l
"@

Invoke-Remote $replaceScript
if ($LASTEXITCODE -ne 0) {
    Write-Host "Error durante la extraccion. La copia anterior sigue en pdf-mod.bak" -ForegroundColor Red
    Remove-Item deploy-pdf-mod.tar.gz -Force -ErrorAction SilentlyContinue
    exit 1
}

Write-Host "[4/4] Verificando..." -ForegroundColor Gray
# Comprueba que las correcciones de 2.1.7 estan de verdad en el servidor y avisa si
# queda una copia del parser dentro de uploads: el codigo ya no la carga (ese
# fallback se retiro por seguridad) y ahi solo estorba.
$verifyScript = @"
# El chequeo de uploads va ANTES del cd: la ruta es relativa al home y, una vez
# dentro de libs/, no resolveria.
if [ -d "$UPLOADS_PATH/pdf-mod" ]
then echo "  AVISO queda una copia en uploads/pdf-mod: ya no se carga, conviene borrarla"
else echo "  OK    no hay copia del parser en uploads"
fi
cd "$LIBS_REMOTE"
if grep -q INVALID_PREDICTOR_VALUE pdf-mod/src/PdfParser/Filter/PredictorException.php
then echo "  OK    guardas del Predictor presentes"
else echo "  AVISO version antigua: no encuentro las guardas del Predictor"
fi
if grep -q 'Key length has to be a mutiple of 8 bits' pdf-mod/src/PdfParser/SecHandler/SecHandler.php
then echo "  OK    validacion de longitud de clave presente"
else echo "  AVISO no encuentro la validacion de clave"
fi
if find pdf-mod/src -name '*.php' -exec php -l {} \; | grep -v 'No syntax errors'
then echo "  ERROR hay ficheros con sintaxis invalida"
else echo "  OK    sintaxis PHP correcta"
fi
"@

Invoke-Remote $verifyScript

Remove-Item deploy-pdf-mod.tar.gz -Force -ErrorAction SilentlyContinue

Write-Host "========================================" -ForegroundColor Green
Write-Host " PDF-Parser actualizado en $($Target.ToUpper())" -ForegroundColor Green
Write-Host " Rollback: en $LIBS_REMOTE -> rm -rf pdf-mod && mv pdf-mod.bak pdf-mod" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
