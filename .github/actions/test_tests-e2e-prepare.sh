#!/bin/bash -e
set -e

export PLAYWRIGHT_BASE_URL="${PLAYWRIGHT_BASE_URL:-http://127.0.0.1:8088}"
export PLAYWRIGHT_APP_TOKEN_FILE="${PLAYWRIGHT_APP_TOKEN_FILE:-tests/files/_playwright/app-token}"

runtime_dir="${GLPI_VAR_DIR:-tests/files}"
mkdir -p \
  "$runtime_dir"/_cache/cache_db \
  "$runtime_dir"/_cache/cache_trans \
  "$runtime_dir"/_cron \
  "$runtime_dir"/_dumps \
  "$runtime_dir"/_graphs \
  "$runtime_dir"/_locales \
  "$runtime_dir"/_lock \
  "$runtime_dir"/_log \
  "$runtime_dir"/_pictures \
  tests/files/_playwright \
  "$runtime_dir"/_plugins \
  "$runtime_dir"/_rss \
  "$runtime_dir"/_sessions \
  "$runtime_dir"/_tmp \
  "$runtime_dir"/_uploads

bin/console itsmng:config:set --config-dir=./tests/config enable_api 1
bin/console itsmng:config:set --config-dir=./tests/config enable_api_login_credentials 1
bin/console itsmng:config:set --config-dir=./tests/config use_notifications 1
bin/console itsmng:config:set --config-dir=./tests/config notifications_mailing 1
bin/console itsmng:config:set --config-dir=./tests/config url_base_api "$PLAYWRIGHT_BASE_URL/apirest.php"

php tests/e2e/prepare_api_client.php "$PLAYWRIGHT_APP_TOKEN_FILE"
