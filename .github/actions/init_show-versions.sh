#!/bin/bash
set -euo pipefail

docker compose exec -T app php --version
docker compose exec -T app php -r 'echo(sprintf("PHP extensions: %s\n", implode(", ", get_loaded_extensions())));'
docker compose exec -T app composer --version
docker compose exec -T app sh -c 'echo "node $(node --version)"'
docker compose exec -T app sh -c 'echo "npm $(npm --version)"'

if [[ -n $(docker compose ps --all --services | grep '^db$') ]]; then
  if [[ "${TEST_DB_TYPE:-mysql}" == pgsql ]]; then
    docker compose exec -T db psql --version
  else
    docker compose exec -T db sh -c '
      if command -v mariadb >/dev/null 2>&1; then
        exec mariadb --version
      elif command -v mysql >/dev/null 2>&1; then
        exec mysql --version
      else
        echo "No MariaDB or MySQL client available in the database container" >&2
        exit 127
      fi
    '
  fi
fi
