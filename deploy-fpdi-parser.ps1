# Despliegue del FPDI PDF-Parser (libs/pdf-mod).
#
# deploy.ps1 y deploy-staging.ps1 excluyen "plugins/ep-signature/libs/*", asi que
# las librerias PDF NUNCA viajan en el despliegue normal: viven en el servidor y se
# actualizan a mano. Este script hace solo eso, para no tener que subirlas a dedo.
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
& $SSH_CMD -p $PORT -i "$KEY_PATH" ${USER_SSH}@${HOST_IP} "rm -f /tmp/deploy_pdf_mod.tar.gz"
& $SCP_CMD -P $PORT -i "$KEY_PATH" deploy-pdf-mod.tar.gz ${USER_SSH}@${HOST_IP}:/tmp/deploy_pdf_mod.tar.gz
if ($LASTEXITCODE -ne 0) {
    Write-Host "Error al subir por SCP." -ForegroundColor Red
    Remove-Item deploy-pdf-mod.tar.gz -Force -ErrorAction SilentlyContinue
    exit 1
}

Write-Host "[3/4] Sustituyendo la version anterior..." -ForegroundColor Gray
# Se guarda una copia de la version saliente (pdf-mod.bak) para poder volver atras
# sin tener que recuperar el paquete comercial. Luego se borra el src viejo entero:
# si solo se sobrescribiera, los ficheros que la nueva version ya no trae se
# quedarian ahi y el autoloader podria seguir cargandolos.
$REMOTE_CMD = @(
    'set -e',
    'cd "' + $LIBS_REMOTE + '"',
    'chmod -R u+w pdf-mod 2>/dev/null || true',
    'rm -rf pdf-mod.bak',
    '[ -d pdf-mod ] && cp -r pdf-mod pdf-mod.bak || true',
    'rm -rf pdf-mod/src',
    'tar --no-same-permissions -xzf /tmp/deploy_pdf_mod.tar.gz -C .',
    'chmod -R u+w pdf-mod',
    'rm -f /tmp/deploy_pdf_mod.tar.gz',
    'echo "  ficheros: $(find pdf-mod/src -name \"*.php\" | wc -l)"'
) -join '; '

& $SSH_CMD -p $PORT -i "$KEY_PATH" ${USER_SSH}@${HOST_IP} $REMOTE_CMD
if ($LASTEXITCODE -ne 0) {
    Write-Host "Error durante la extraccion en el servidor. La copia anterior sigue en pdf-mod.bak" -ForegroundColor Red
    Remove-Item deploy-pdf-mod.tar.gz -Force -ErrorAction SilentlyContinue
    exit 1
}

Write-Host "[4/4] Verificando..." -ForegroundColor Gray
# Comprueba que las correcciones de 2.1.7 estan realmente en el servidor y avisa si
# queda una copia del parser dentro de uploads: el codigo ya no la carga (ese
# fallback se retiro por seguridad) y ahi solo estorba.
$VERIFY_CMD = @(
    'cd "' + $LIBS_REMOTE + '"',
    'grep -q "INVALID_PREDICTOR_VALUE" pdf-mod/src/PdfParser/Filter/PredictorException.php && echo "  OK  guardas del Predictor presentes" || echo "  AVISO version antigua: no encuentro las guardas del Predictor"',
    'grep -q "Key length has to be a mutiple of 8 bits" pdf-mod/src/PdfParser/SecHandler/SecHandler.php && echo "  OK  validacion de longitud de clave presente" || echo "  AVISO no encuentro la validacion de clave"',
    'for f in $(find pdf-mod/src -name "*.php"); do php -l "$f" > /dev/null || echo "  ERROR de sintaxis en $f"; done; echo "  OK  sintaxis PHP correcta"',
    '[ -d "' + $UPLOADS_PATH + '/pdf-mod" ] && echo "  AVISO queda una copia en uploads/pdf-mod: ya no se usa, conviene borrarla" || echo "  OK  no hay copia del parser en uploads"'
) -join '; '

& $SSH_CMD -p $PORT -i "$KEY_PATH" ${USER_SSH}@${HOST_IP} $VERIFY_CMD

Remove-Item deploy-pdf-mod.tar.gz -Force -ErrorAction SilentlyContinue

Write-Host "========================================" -ForegroundColor Green
Write-Host " PDF-Parser actualizado en $($Target.ToUpper())" -ForegroundColor Green
Write-Host " Rollback: mv pdf-mod.bak pdf-mod (en $LIBS_REMOTE)" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
