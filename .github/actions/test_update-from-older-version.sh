#!/bin/bash
set -euo pipefail

ROOT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)
cd "$ROOT_DIR"
LOG_FILE="$ROOT_DIR/tests/files/_log/migration.log"
mkdir -p "$(dirname -- "$LOG_FILE")"

# The install suite provides the current parent and its real encryption key.
# The proper migration tests own a separate, initially empty target; never reuse
# an existing database or relabel an upgraded schema as an older release.
export GLPI_CONFIG_DIR="$ROOT_DIR/tests/config"
# Refuse a polluted standalone parent before provisioning any fixture target.
php tests/e2e/check_installed_history.php "$GLPI_CONFIG_DIR" --without-application-fixtures
export ITSM_TEST_MIGRATION_DB="${ITSM_TEST_MIGRATION_DB:-itsm_test_container_migration}"
if [[ ! "$ITSM_TEST_MIGRATION_DB" =~ ^itsm_test_[a-z0-9_]+_migration$ ]]; then
  echo "An explicitly named disposable migration database is required" >&2
  exit 1
fi
case "${TEST_DB_TYPE:-mysql}" in
  mysql)
    existing=$(mysql --host=db --user=root --batch --skip-column-names \
      -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$ITSM_TEST_MIGRATION_DB';")
    ;;
  pgsql)
    if (( ${#ITSM_TEST_MIGRATION_DB} > 63 )); then
      echo "Migration database name exceeds PostgreSQL's identifier limit" >&2
      exit 1
    fi
    existing=$(PGPASSWORD=test psql --no-psqlrc --host=db --username=root --dbname=postgres \
      --no-align --tuples-only --set=ON_ERROR_STOP=1 \
      --command="SELECT COUNT(*) FROM pg_database WHERE datname='$ITSM_TEST_MIGRATION_DB';")
    ;;
  *) echo "Unsupported test database provider: $TEST_DB_TYPE" >&2; exit 1 ;;
esac
if [[ "$existing" != "0" ]]; then
  echo "Migration database already exists; preserve it and choose a new target" >&2
  exit 1
fi
case "${TEST_DB_TYPE:-mysql}" in
  mysql)
    mysql --host=db --user=root \
      -e "CREATE DATABASE \`$ITSM_TEST_MIGRATION_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    ;;
  pgsql)
    PGPASSWORD=test psql --no-psqlrc --host=db --username=root --dbname=postgres \
      --set=ON_ERROR_STOP=1 --command="CREATE DATABASE \"$ITSM_TEST_MIGRATION_DB\";"
    ;;
esac

# Frozen input adoption is an integration fixture, not an official-release matrix.
# This exercises installation, interruption/retry, provenance refusal, populated
# adoption, original-key preservation and final schema through normal Atoum.
timeout --signal=TERM --kill-after=10s 300s composer test:migration | tee "$LOG_FILE"
