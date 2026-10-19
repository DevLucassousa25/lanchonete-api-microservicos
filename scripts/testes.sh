#!/usr/bin/env sh
# Roda os testes PHPUnit de todos os serviços dentro do Docker (Linux / macOS / WSL / Git Bash).
# Uso:  ./scripts/testes.sh            (todos)
#       ./scripts/testes.sh pedidos    (só um serviço)
set -u
SERVICOS="${*:-contas catalogo pedidos pagamentos}"
FALHOU=""
for s in $SERVICOS; do
  printf '\n=== %s ===\n' "$s"
  docker compose run --rm --build --no-deps -e RUN_MIGRATIONS=0 "$s" \
    sh -c 'composer install --no-interaction --quiet && env -i PATH="$PATH" php vendor/bin/phpunit --colors=always' \
    || FALHOU="$FALHOU $s"
  # env -i: o PHPUnit roda sem as variáveis do container (DB_HOST, CACHE_STORE...), só com as do phpunit.xml
done
[ -z "$FALHOU" ] && { printf '\nTodos os testes passaram.\n'; exit 0; }
printf '\nFALHOU:%s\n' "$FALHOU"; exit 1
