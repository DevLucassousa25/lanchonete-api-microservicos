# Roda os testes PHPUnit de todos os serviços dentro do Docker (Windows / PowerShell).
# Uso:  .\scripts\testes.ps1              (todos)
#       .\scripts\testes.ps1 pedidos      (só um serviço)
#
# Os testes usam SQLite em memória: não precisam de Postgres, Redis nem RabbitMQ rodando.
param([string[]]$Servicos = @('contas', 'catalogo', 'pedidos', 'pagamentos'))

$falhou = @()
foreach ($s in $Servicos) {
    Write-Host "`n=== $s ===" -ForegroundColor Cyan
    docker compose run --rm --build --no-deps -e RUN_MIGRATIONS=0 $s `
        sh -c 'composer install --no-interaction --quiet && env -i PATH="$PATH" php vendor/bin/phpunit --colors=always'
    # env -i: o PHPUnit roda sem as variáveis do container (DB_HOST, CACHE_STORE...), só com as do phpunit.xml
    if ($LASTEXITCODE -ne 0) { $falhou += $s }
}

if ($falhou.Count -gt 0) {
    Write-Host "`nFALHOU: $($falhou -join ', ')" -ForegroundColor Red
    exit 1
}
Write-Host "`nTodos os testes passaram." -ForegroundColor Green
