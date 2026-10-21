$ErrorActionPreference = 'Stop'

# Prepara .env, extensiones PHP y certificados antes de iniciar el servidor local.
$projectRoot = Split-Path -Parent $PSScriptRoot
$envPath = Join-Path $projectRoot '.env'
if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) {
    throw 'No se encontró .env en la raíz privada del proyecto.'
}

# Evita iniciar una segunda instancia sobre el puerto local de la aplicacion.
$listeners = @(Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue)
if ($listeners.Count -gt 0) {
    $processIds = ($listeners | Select-Object -ExpandProperty OwningProcess -Unique) -join ', '
    throw "El puerto 8000 ya está ocupado por PID $processIds. Detén el servidor anterior con Ctrl+C y vuelve a intentarlo."
}

# Exporta cada variable simple del archivo privado .env al proceso PHP.
foreach ($line in Get-Content -LiteralPath $envPath) {
    $trimmedLine = $line.Trim()
    if ($trimmedLine -eq '' -or $trimmedLine.StartsWith('#') -or $trimmedLine -notmatch '=') {
        continue
    }

    $separator = $trimmedLine.IndexOf('=')
    $name = $trimmedLine.Substring(0, $separator).Trim()
    $value = $trimmedLine.Substring($separator + 1).Trim()
    if ($name -match '^[A-Za-z_][A-Za-z0-9_]*$') {
        Set-Item -Path ('Env:' + $name) -Value $value
    }
}

$php = Get-Command php -ErrorAction Stop
$phpRoot = Split-Path -Parent $php.Source
$extensionDirectory = Join-Path $phpRoot 'ext'
$caBundle = Join-Path $phpRoot 'extras\ssl\cacert.pem'
if (-not (Test-Path -LiteralPath $caBundle -PathType Leaf)) {
    throw "No se encontró el bundle CA de PHP en $caBundle. Descárgalo desde https://curl.se/ca/cacert.pem y vuelve a iniciar el proyecto."
}

# Verifica dependencias antes de lanzar PHP para fallar con un mensaje claro.
$requiredExtensions = @('php_openssl.dll', 'php_curl.dll', 'php_pdo_mysql.dll', 'php_mbstring.dll', 'php_pdo_sqlite.dll')
foreach ($extensionFile in $requiredExtensions) {
    if (-not (Test-Path -LiteralPath (Join-Path $extensionDirectory $extensionFile) -PathType Leaf)) {
        throw "Falta la extensión PHP requerida: $extensionFile"
    }
}

Set-Location -LiteralPath $projectRoot
Write-Output 'TESA estará disponible en http://localhost:8000/ (Ctrl+C para detener).'
$phpArguments = @(
    '-d', "extension_dir=$extensionDirectory",
    '-d', "curl.cainfo=$caBundle",
    '-d', "openssl.cafile=$caBundle",
    '-d', 'extension=openssl',
    '-d', 'extension=curl',
    '-d', 'extension=pdo_mysql',
    '-d', 'extension=mbstring',
    '-d', 'extension=pdo_sqlite',
    '-S', 'localhost:8000',
    '-t', (Join-Path $projectRoot 'public')
)
& $php.Source @phpArguments
