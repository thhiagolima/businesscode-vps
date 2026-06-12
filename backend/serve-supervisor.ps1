# Supervisor do backend Laravel na porta 8000.
# Motivo: `php artisan serve` (dev server) crasha no Windows sob carga por bug do
# ServeCommand::getRequestPortFromLine (linha "NNN Closing"). Sem supervisor, a porta
# 8000 fica fora e o Apache devolve 503 em /api/. Este laço reinicia em ~2s se cair.
$ErrorActionPreference = 'Continue'
$backend = 'C:\xampp\htdocs\new_saas\backend'
$php = 'C:\xampp\php\php.exe'
Set-Location $backend
while ($true) {
    $ts = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -Path "$backend\storage\logs\serve-supervisor.log" -Value "[$ts] iniciando artisan serve :8000"
    & $php artisan serve --host=127.0.0.1 --port=8000 *>> "$backend\storage\logs\serve-supervisor.log"
    $ts = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    Add-Content -Path "$backend\storage\logs\serve-supervisor.log" -Value "[$ts] serve saiu (exit). Reiniciando em 2s..."
    Start-Sleep -Seconds 2
}
