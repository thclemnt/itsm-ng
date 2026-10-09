#!/bin/bash
set -euo pipefail

ROOT_DIR=$(readlink -f "$(dirname "$0")/../..")
COMPOSE_CMD="$ROOT_DIR/.github/actions/docker-compose.sh"

"$COMPOSE_CMD" exec -T app php --version
"$COMPOSE_CMD" exec -T app php -r 'echo(sprintf("PHP extensions: %s\n", implode(", ", get_loaded_extensions())));'
"$COMPOSE_CMD" exec -T app composer --version
"$COMPOSE_CMD" exec -T app sh -c 'echo "node $(node --version)"'
"$COMPOSE_CMD" exec -T app sh -c 'echo "npm $(npm --version)"'

if [[ -n $("$COMPOSE_CMD" ps --all --services | grep '^db$') ]]; then
  if [[ "${TEST_DB_TYPE:-mysql}" == pgsql ]]; then
    "$COMPOSE_CMD" exec -T db psql --version
  else
    "$COMPOSE_CMD" exec -T db sh -c '
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
