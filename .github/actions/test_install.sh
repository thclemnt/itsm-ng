#!/bin/bash
set -euo pipefail

LOG_FILE="./tests/files/_log/install.log"
mkdir -p "$(dirname "$LOG_FILE")"

# Execute install
case "${TEST_DB_TYPE:-mysql}" in
  mysql) database_options=(--db-type=mysql --db-port=3306) ;;
  pgsql) database_options=(--db-type=pgsql --db-port=5432 --db-password=test) ;;
  *) echo "Unsupported test database provider: $TEST_DB_TYPE" >&2; exit 1 ;;
esac
bin/console itsmng:database:install \
  --config-dir=./tests/config --ansi --no-interaction \
  --reconfigure --db-name="${TEST_DB_NAME:-glpi}" --db-host=db --db-user=root --force "${database_options[@]}"

# Execute update
## Must succeed, including an already-complete canonical history.
if bin/console itsmng:database:update --config-dir=./tests/config --ansi --no-interaction 2>&1 | tee "$LOG_FILE"; then
  php tests/e2e/check_installed_history.php ./tests/config
else
  update_status=$?
  echo "itsmng:database:update command FAILED (status $update_status)" >&2
  exit "$update_status"
fi
