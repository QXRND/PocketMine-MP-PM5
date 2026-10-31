param(
    [string]$TagName = "v5.44.6-qxrnd.0",
    [string]$ReleaseName = "QXRND - PocketMine-MP PM5 1.26.60 (v5.44.6-qxrnd.0)",
    [string]$ReleaseBody = "## QXRND - PocketMine-MP PM5 1.26.60 (v5.44.5-qxrnd.21)`n`n### Novedades en esta version`n- **Compatibilidad Bedrock 1.26.60 (Protocolo 2225):**`n  - Soporte multi-version simultaneo desde Bedrock 1.21.111 hasta 1.26.60 (Protocolos 844 a 2225).`n  - Mapeo de paquetes y esquemas de items actualizados.`n- **Integracion de SCON Licensing (Grado Elite Militar):**`n  - Validacion criptografica con doble firma (Ed25519 + ECDSA P-384 + SHA3-512 + clave de dispositivo HWID).`n  - Proteccion sincronica en onEnable: el software solo inicia tras validar con https://licences.scon.host.`n  - Re-verificacion periodica en segundo plano cada 30 minutos.`n- **Egg Oficial para Pterodactyl Panel (egg-pmmp-qxrnd.json):**`n  - Descarga e instalacion automatica del PHAR oficial desde GitHub Releases.`n  - Descarga del runtime de PHP ZTS oficial para PM5 con soporte para x86_64 y aarch64.`n  - Mapeo automatico de la variable LICENSE_KEY en Pterodactyl a plugin_data/PMMP-QXRND/config.yml.`n`n**Autor:** DevPapo | **Portal de Licencias:** https://licences.scon.host | **Contacto:** admin@scon.host"
)

$ErrorActionPreference = "Stop"

$tokenFile = "C:\Users\Jacob\Desktop\DevPapo\token-github.txt"
if (Test-Path $tokenFile) {
    $Token = (Get-Content $tokenFile -TotalCount 1) -replace "TOKEN CLASIC:\s*", ""
} else {
    $Token = $env:GH_TOKEN
}

if (-not $Token) {
    throw "GitHub token no encontrado."
}

$headers = @{
    "Authorization" = "token $Token"
    "User-Agent" = "DevPapo-PMMP-Release"
    "Accept" = "application/vnd.github.v3+json"
}

$repo = "QXRND/PocketMine-MP-PM5"
Write-Host "Consultando releases existentes en $repo..."
$releases = Invoke-RestMethod -Uri "https://api.github.com/repos/$repo/releases" -Headers $headers

$targetRelease = $releases | Where-Object { $_.tag_name -eq $TagName }
if (-not $targetRelease) {
    Write-Host "Creando nueva release '$ReleaseName' (Tag: $TagName)..."
    $createBody = @{
        tag_name = $TagName
        target_commitish = "main"
        name = $ReleaseName
        body = $ReleaseBody
        draft = $false
        prerelease = $false
    } | ConvertTo-Json

    $targetRelease = Invoke-RestMethod -Method Post -Uri "https://api.github.com/repos/$repo/releases" -Headers $headers -Body $createBody -ContentType "application/json"
    Write-Host "Release creada exitosamente! ID: $($targetRelease.id)"
} else {
    Write-Host "Release existente detectada: ID $($targetRelease.id)"
}

function Upload-Asset([string]$filePath, [string]$contentType) {
    $fileName = [System.IO.Path]::GetFileName($filePath)
    if (-not (Test-Path $filePath)) {
        throw "Archivo no encontrado: $filePath"
    }

    $existing = $targetRelease.assets | Where-Object { $_.name -eq $fileName }
    if ($existing) {
        Write-Host "Eliminando asset anterior $fileName (ID: $($existing.id))..."
        Invoke-RestMethod -Method Delete -Uri "https://api.github.com/repos/$repo/releases/assets/$($existing.id)" -Headers $headers
        Start-Sleep -Seconds 2
    }

    $bytes = [System.IO.File]::ReadAllBytes((Resolve-Path $filePath).Path)
    $uploadUrl = $targetRelease.upload_url -replace '\{\?name,label\}', "?name=$fileName"

    Write-Host "Subiendo $fileName ($($bytes.Length) bytes) a la release..."
    $uploadHeaders = @{
        "Authorization" = "token $Token"
        "User-Agent" = "DevPapo-PMMP-Release"
        "Content-Type" = $contentType
    }

    $res = Invoke-RestMethod -Method Post -Uri $uploadUrl -Headers $uploadHeaders -Body $bytes
    Write-Host "Subida completa de $fileName! (Asset ID: $($res.id))"
}

Upload-Asset -filePath "C:\Users\Jacob\Desktop\DevPapo\SoftwareMC\PMMP\PocketMine-MP.phar" -contentType "application/octet-stream"
Upload-Asset -filePath "C:\Users\Jacob\Desktop\DevPapo\SoftwareMC\PMMP\egg-pmmp-qxrnd.json" -contentType "application/json"

Write-Host "=========================================================="
Write-Host "Release $TagName publicada correctamente en GitHub!"
Write-Host "URL: $($targetRelease.html_url)"
Write-Host "=========================================================="
