#!/usr/bin/env bash
set -euo pipefail

# Exercise the public upgrade from real pre-ORM code, not a reconstructed schema.
legacy_revision=bdfdcedf6fd039c984dc20374afa24f23decc537
current_source=$(cd "$(dirname "$0")/../.." && pwd)
export ITSM_UPGRADE_DB_HOST=${ITSM_UPGRADE_DB_HOST:-127.0.0.1}
export ITSM_UPGRADE_DB_PORT=${ITSM_UPGRADE_DB_PORT:-3306}
export ITSM_UPGRADE_DB_USER=${ITSM_UPGRADE_DB_USER:-test}
export ITSM_UPGRADE_DB_PASSWORD=${ITSM_UPGRADE_DB_PASSWORD-test}
export ITSM_UPGRADE_DB_NAME=${ITSM_UPGRADE_DB_NAME:-itsm_test_legacy_upgrade}
export ITSM_UPGRADE_WORK_DIR=${ITSM_UPGRADE_WORK_DIR:-$(mktemp -d)}
mkdir -p "$ITSM_UPGRADE_WORK_DIR"
ITSM_UPGRADE_WORK_DIR=$(cd "$ITSM_UPGRADE_WORK_DIR" && pwd)
export GLPI_CONFIG_DIR="$ITSM_UPGRADE_WORK_DIR/config"
export GLPI_VAR_DIR="$ITSM_UPGRADE_WORK_DIR/files"
proof="$current_source/tests/fixtures/legacy-upgrade.php"

# Requires a separately provisioned, empty database. Never reset an existing one.
php "$proof" empty
test ! -e "$GLPI_CONFIG_DIR/config_db.php"
mkdir -p "$GLPI_CONFIG_DIR" "$GLPI_VAR_DIR"
for directory in _cache _cron _dumps _graphs _locales _lock _log _pictures _plugins _rss _sessions _tmp _uploads; do
  mkdir -p "$GLPI_VAR_DIR/$directory"
done
printf '%s\n' '<?php define("GLPI_VAR_DIR", getenv("GLPI_VAR_DIR"));' > "$ITSM_UPGRADE_WORK_DIR/runtime.php"
php_options=()
if [[ -n "${ITSM_UPGRADE_DB_SOCKET:-}" ]]; then
  php_options=(-d "mysqli.default_socket=$ITSM_UPGRADE_DB_SOCKET" -d "pdo_mysql.default_socket=$ITSM_UPGRADE_DB_SOCKET")
fi

if [[ -z "${ITSM_LEGACY_SOURCE:-}" ]]; then
  ITSM_LEGACY_SOURCE="$ITSM_UPGRADE_WORK_DIR/legacy"
  git init -q "$ITSM_LEGACY_SOURCE"
  git -C "$ITSM_LEGACY_SOURCE" fetch --depth=1 https://github.com/thclemnt/itsm-ng.git "$legacy_revision"
  git -C "$ITSM_LEGACY_SOURCE" checkout -q --detach FETCH_HEAD
fi
export ITSM_LEGACY_SOURCE
test "$(git -C "$ITSM_LEGACY_SOURCE" rev-parse HEAD)" = "$legacy_revision"
git -C "$ITSM_LEGACY_SOURCE" diff --quiet HEAD --
test -f "$current_source/vendor/autoload.php"

# The legacy bootstrap checks frontend build markers even in CLI mode. These
# commands never render pages; satisfy only that guard, without downloading or
# building browser assets. Remove markers created here when the harness exits.
marker_files=()
marker_directories=()
cleanup_markers() {
  for marker in "${marker_files[@]}"; do rm -f "$marker"; done
  for directory in "${marker_directories[@]}"; do rmdir "$directory" 2>/dev/null || true; done
}
trap cleanup_markers EXIT
for source in "$ITSM_LEGACY_SOURCE" "$current_source"; do
  if [[ ! -d "$source/public/lib" ]]; then
    mkdir -p "$source/public/lib"
    marker_directories+=("$source/public/lib")
  fi
  if [[ -f "$source/package-lock.json" && ! -f "$source/.package.hash" ]]; then
    php -r 'echo sha1_file($argv[1]);' "$source/package-lock.json" > "$source/.package.hash"
    marker_files+=("$source/.package.hash")
  fi
done

# This historical revision has no lock file. Resolve its own manifest, including
# its original test dependencies; do not share the current application's vendor.
# Security blocking is disabled only for this disposable historical fixture.
(cd "$ITSM_LEGACY_SOURCE" && COMPOSER_NO_SECURITY_BLOCKING=1 composer install --no-interaction --prefer-dist) \
  2>&1 | tee "$ITSM_UPGRADE_WORK_DIR/legacy-dependencies.log"
cp "$ITSM_LEGACY_SOURCE/composer.lock" "$ITSM_UPGRADE_WORK_DIR/legacy-composer.lock"
database_host="$ITSM_UPGRADE_DB_HOST"
database_port=(--db-port="$ITSM_UPGRADE_DB_PORT")
if [[ -n "${ITSM_UPGRADE_DB_SOCKET:-}" ]]; then
  database_host=localhost
  database_port=()
fi

(cd "$ITSM_LEGACY_SOURCE" && php "${php_options[@]}" -d auto_prepend_file="$ITSM_UPGRADE_WORK_DIR/runtime.php" bin/console db:install \
  --config-dir="$GLPI_CONFIG_DIR" --db-host="$database_host" "${database_port[@]}" --db-name="$ITSM_UPGRADE_DB_NAME" \
  --db-user="$ITSM_UPGRADE_DB_USER" --db-password="$ITSM_UPGRADE_DB_PASSWORD" --no-interaction) \
  2>&1 | tee "$ITSM_UPGRADE_WORK_DIR/legacy-install.log"
(cd "$ITSM_LEGACY_SOURCE" && php "${php_options[@]}" -d memory_limit=512M "$proof" seed) \
  2>&1 | tee "$ITSM_UPGRADE_WORK_DIR/legacy-dataset.log"
php "$proof" before

cd "$current_source"
for pass in first repeated; do
  timeout --signal=TERM --kill-after=10s 900s php "${php_options[@]}" -d memory_limit=512M \
    -d auto_prepend_file="$ITSM_UPGRADE_WORK_DIR/runtime.php" bin/console db:update \
    --config-dir="$GLPI_CONFIG_DIR" --no-interaction 2>&1 | tee "$ITSM_UPGRADE_WORK_DIR/update-$pass.log"
  php "$proof" after
done
php "${php_options[@]}" -d auto_prepend_file="$ITSM_UPGRADE_WORK_DIR/runtime.php" bin/console db:check \
  --config-dir="$GLPI_CONFIG_DIR" --no-interaction 2>&1 | tee "$ITSM_UPGRADE_WORK_DIR/schema-check.log"
echo "Real-source upgrade and repeated update passed; diagnostics: $ITSM_UPGRADE_WORK_DIR"
