# scripts/dev-start.ps1 — démarrage de l'environnement de dev TTM
# Usage : .\scripts\dev-start.ps1
#
# Ouvre une session PowerShell avec PHP/Composer/MySQL dans le PATH,
# démarre MySQL si pas déjà lancé, et configure OPENSSL_CONF.

$ErrorActionPreference = 'Stop'

$LARAGON = "D:\laragon"
$PHP_DIR = "$LARAGON\bin\php\php-8.3.30-Win32-vs16-x64"
$COMPOSER = "$LARAGON\bin\composer"
$MYSQL_BASE = "$LARAGON\bin\mysql\mysql-8.4.3-winx64"
$MYSQL_DATA = "$LARAGON\data\mysql"

# 1) PATH for this session
$env:PATH = "$PHP_DIR;$COMPOSER;$MYSQL_BASE\bin;$env:PATH"

# 2) OpenSSL config (needed for lexik:jwt:generate-keypair on Windows)
$env:OPENSSL_CONF = "$PHP_DIR\extras\ssl\openssl.cnf"

# 3) Initialize MySQL data dir on first run
if (-not (Test-Path "$MYSQL_DATA\mysql")) {
    Write-Host "Initialisation de MySQL (premier lancement)..." -ForegroundColor Yellow
    & "$MYSQL_BASE\bin\mysqld.exe" --initialize-insecure "--basedir=$MYSQL_BASE" "--datadir=$MYSQL_DATA"
}

# 4) Start MySQL if not already listening on 3306
$listening = (Test-NetConnection -ComputerName 127.0.0.1 -Port 3306 -InformationLevel Quiet -WarningAction SilentlyContinue)
if (-not $listening) {
    Write-Host "Demarrage de MySQL..." -ForegroundColor Yellow
    Start-Process -FilePath "$MYSQL_BASE\bin\mysqld.exe" `
        -ArgumentList "--basedir=$MYSQL_BASE","--datadir=$MYSQL_DATA","--port=3306" `
        -WindowStyle Hidden
    Start-Sleep -Seconds 3
}

# 5) Make sure bundle assets (EasyAdmin CSS/icons) are published
$BACKEND = "$PSScriptRoot\..\backend"
if (-not (Test-Path "$BACKEND\public\bundles\easyadmin")) {
    Write-Host "Publication des assets bundles..." -ForegroundColor Yellow
    Push-Location $BACKEND
    & php bin/console assets:install
    Pop-Location
}

Write-Host ""
Write-Host "=== TTM dev environment ===" -ForegroundColor Green
Write-Host "PHP        : $(& php --version | Select-Object -First 1)"
Write-Host "Composer   : $(& php $COMPOSER\composer.phar --version)"
Write-Host "MySQL 3306 : up"
Write-Host ""
Write-Host "Backend    : cd $PSScriptRoot\..\backend"
Write-Host "Mobile     : cd $PSScriptRoot\..\mobile"
Write-Host ""
Write-Host "Pour servir l'API : php -S 127.0.0.1:8000 -t public  (depuis backend/)"
