param([switch]$SinNavegador)
$ErrorActionPreference='Stop'
$projectRoot=$PSScriptRoot
$localRuntime=[IO.Path]::GetFullPath((Join-Path $projectRoot '..\..\work'))
$phpCommand=Get-Command php.exe -ErrorAction SilentlyContinue
$phpPath=if($phpCommand){$phpCommand.Source}else{Join-Path $localRuntime 'php\php.exe'}
if(!(Test-Path -LiteralPath $phpPath)){throw 'Instala PHP 8.4 con la extensión MongoDB. Consulta README.md.'}
$nodeCommand=Get-Command node.exe -ErrorAction SilentlyContinue
if(!$nodeCommand){throw 'Instala Node.js 22.18 o superior y ejecuta npm ci en frontend.'}
$backend=Join-Path $projectRoot 'backend'
$frontend=Join-Path $projectRoot 'frontend'
if(!(Test-Path -LiteralPath (Join-Path $backend 'vendor\autoload.php'))){throw 'Ejecuta composer install dentro de backend.'}
if(!(Test-Path -LiteralPath (Join-Path $backend '.env'))){throw 'Configura backend/.env siguiendo README.md.'}
if(!(Test-Path -LiteralPath (Join-Path $frontend 'node_modules\vite\bin\vite.js'))){throw 'Ejecuta npm ci dentro de frontend.'}
$logs=Join-Path $projectRoot '.local'
New-Item -ItemType Directory -Force -Path $logs | Out-Null
function Ready([string]$url){try{$r=Invoke-WebRequest -Uri $url -TimeoutSec 2 -UseBasicParsing;return $r.StatusCode -eq 200}catch{return $false}}
# La base portátil pertenece exclusivamente a este espacio de trabajo.
$envFile=Get-Content -LiteralPath (Join-Path $backend '.env') -Raw
if($envFile.Contains('127.0.0.1:27028') -and (Test-Path -LiteralPath (Join-Path $localRuntime 'mongod.exe'))){
    $socket=New-Object Net.Sockets.TcpClient
    try {$socket.Connect('127.0.0.1',27028);$mongoReady=$true}catch{$mongoReady=$false}finally{$socket.Dispose()}
    if(!$mongoReady){
        $database=Join-Path $localRuntime 'mongo-data'
        New-Item -ItemType Directory -Force -Path $database | Out-Null
        $mongoArgs=@('--dbpath',('"'+$database+'"'),'--port','27028','--bind_ip','127.0.0.1','--replSet','rs0','--logpath',('"'+(Join-Path $logs 'mongo.log')+'"'),'--wiredTigerCacheSizeGB','0.25')
        Start-Process -FilePath (Join-Path $localRuntime 'mongod.exe') -ArgumentList $mongoArgs -WindowStyle Hidden | Out-Null
    }
}
if(!(Ready 'http://127.0.0.1:8001/api/v1/health')){
    $serverArgs=@('-S','127.0.0.1:8001','-t',('"'+(Join-Path $backend 'public')+'"'),('"'+(Join-Path $backend 'server.php')+'"'))
    Start-Process -FilePath $phpPath -ArgumentList $serverArgs -WorkingDirectory $backend -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs 'api.log') -RedirectStandardError (Join-Path $logs 'api-errors.log') | Out-Null
}
if(!(Ready 'http://127.0.0.1:5174')){
    if(!(Test-Path -LiteralPath (Join-Path $frontend 'dist\index.html'))){
        Push-Location $frontend
        try {& npm.cmd run build;if($LASTEXITCODE -ne 0){throw 'No se pudo compilar el panel.'}}finally{Pop-Location}
    }
    Start-Process -FilePath $nodeCommand.Source -ArgumentList @('node_modules/vite/bin/vite.js','preview','--host','127.0.0.1','--port','5174','--configLoader','native') -WorkingDirectory $frontend -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs 'portal.log') -RedirectStandardError (Join-Path $logs 'portal-errors.log') | Out-Null
}
Write-Host 'CP Gestiones: http://127.0.0.1:5174'
Write-Host 'Consulta ACCESO-LOCAL.txt para las credenciales de esta demostración.'
Write-Host 'Procesos programados: ejecuta php artisan schedule:work en backend para activar planillas, eventos y cierres.'
if(!$SinNavegador){Start-Process 'http://127.0.0.1:5174'}
