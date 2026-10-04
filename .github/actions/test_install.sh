#!/bin/bash -e
set -o pipefail

LOG_FILE="./tests/files/_log/install.log"
mkdir -p $(dirname "$LOG_FILE")

# Execute install
bin/console itsmng:database:install \
  --config-dir=./tests/config --ansi --no-interaction \
  --reconfigure --db-name=glpi --db-host=db --db-user=root --force

# Execute update
## Must succeed, including an already-complete canonical history.
if bin/console itsmng:database:update --config-dir=./tests/config --ansi --no-interaction 2>&1 | tee "$LOG_FILE"; then
  php tests/e2e/check_installed_history.php ./tests/config
else
  update_status=$?
  echo "itsmng:database:update command FAILED (status $update_status)" >&2
  exit "$update_status"
fi
