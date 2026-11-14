# Siapkan file yang diunggah ke Hostinger (dijalankan di laptop, PowerShell).
#
#   powershell -ExecutionPolicy Bypass -File deploy\siapkan-hostinger.ps1
#   powershell -ExecutionPolicy Bypass -File deploy\siapkan-hostinger.ps1 -TanpaDatabase
#
# Hasil di deploy\keluaran\ (tidak masuk Git):
#   build.tar.gz     aset frontend hasil "npm run build" (server Hostinger tidak punya Node)
#   database.sqlite  salinan database laptop (data pasien, histori, tindak lanjut, akun)
param(
    [string]$Php = 'C:\laragon\bin\php\php-8.5.12-Win32-vs17-x64\php.exe',
    [switch]$TanpaDatabase
)
$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$keluaran = Join-Path $PSScriptRoot 'keluaran'
Set-Location $root
New-Item -ItemType Directory -Force $keluaran | Out-Null

Write-Host '==> 1/3 Build aset frontend'
npm run build
if ($LASTEXITCODE -ne 0) { throw 'npm run build gagal' }

Write-Host '==> 2/3 Kemas public\build'
$arsip = Join-Path $keluaran 'build.tar.gz'
if (Test-Path $arsip) { Remove-Item $arsip }
tar -czf $arsip -C public build
if ($LASTEXITCODE -ne 0) { throw 'Gagal membuat build.tar.gz' }

if ($TanpaDatabase) {
    Write-Host '==> 3/3 Database dilewati (-TanpaDatabase): data di server tidak diubah'
} else {
    Write-Host '==> 3/3 Salin database'
    & $Php artisan prb:salin-database (Join-Path $keluaran 'database.sqlite') --force
    if ($LASTEXITCODE -ne 0) { throw 'Gagal menyalin database' }
}

Write-Host ''
Write-Host "Selesai. Unggah isi folder ini ke deploy/keluaran/ di server Hostinger:"
Write-Host "  $keluaran"
Write-Host 'Contoh (ganti user, IP, dan domain sesuai hPanel -> SSH Access):'
$berkas = if ($TanpaDatabase) { 'deploy\keluaran\build.tar.gz' } else { 'deploy\keluaran\build.tar.gz deploy\keluaran\database.sqlite' }
Write-Host "  scp -P 65002 $berkas u123456789@IP-SERVER:domains/DOMAIN/smart-prb/deploy/keluaran/"
