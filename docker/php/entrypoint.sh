#!/bin/sh
set -e

# RUN_MIGRATIONS=1  -> espera o banco e roda as migrations
# RUN_SEED=1        -> roda o seeder (só na primeira vez que a tabela estiver vazia)
if [ "$RUN_MIGRATIONS" = "1" ]; then
  echo "[entrypoint] aguardando banco $DB_HOST..."
  until php artisan migrate --force --no-interaction >/tmp/migrate.log 2>&1; do
    sleep 2
  done
  cat /tmp/migrate.log

  if [ "$RUN_SEED" = "1" ]; then
    php artisan db:seed --force --no-interaction --class="${SEED_CLASS:-DatabaseSeeder}" || true
  fi
fi

exec "$@"
