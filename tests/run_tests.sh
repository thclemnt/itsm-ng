#!/bin/bash -e
# /**
#  * ---------------------------------------------------------------------
#  * GLPI - Gestionnaire Libre de Parc Informatique
#  * Copyright (C) 2015-2022 Teclib' and contributors.
#  *
#  * http://glpi-project.org
#  *
#  * based on GLPI - Gestionnaire Libre de Parc Informatique
#  * Copyright (C) 2003-2014 by the INDEPNET Development Team.
#  *
#  * ---------------------------------------------------------------------
#  *
#  * LICENSE
#  *
#  * This file is part of GLPI.
#  *
#  * GLPI is free software; you can redistribute it and/or modify
#  * it under the terms of the GNU General Public License as published by
#  * the Free Software Foundation; either version 2 of the License, or
#  * (at your option) any later version.
#  *
#  * GLPI is distributed in the hope that it will be useful,
#  * but WITHOUT ANY WARRANTY; without even the implied warranty of
#  * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
#  * GNU General Public License for more details.
#  *
#  * You should have received a copy of the GNU General Public License
#  * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
#  * ---------------------------------------------------------------------
# */

WORKING_DIR=$(readlink -f "$(dirname $0)")

# Declaration order in $TESTS_SUITES corresponds to the execution order
TESTS_SUITES=(
  "install"
  "update"
  "units"
  "e2e"
  "functional"
  "ldap"
  "imap"
  "web"
)

# Extract named options
while [[ $# -gt 0 ]]; do
  if [[ $1 == "--"* ]]; then
    ## Remove -- prefix, replace - by _ and uppercase all
    declare $(echo $1 | sed -e 's/^--//g' | sed -e 's/-/_/g' -e 's/\(.*\)/\U\1/')=true
    shift
  else
    break
  fi
done

# Extract list of tests suites to run
TESTS_TO_RUN=()
if [[ $# -gt 0 ]]; then
  ARGS=("$@")

  for KEY in "${ARGS[@]}"; do
    INDEX=0
    for VALID_KEY in "${TESTS_SUITES[@]}"; do
      if [[ "$VALID_KEY" == "$KEY" ]]; then
        TESTS_TO_RUN[$INDEX]=$KEY
        continue 2 # Go to next arg
      fi
      INDEX+=1
    done
    echo -e "\e[1;30;43m/!\ Invalid \"$KEY\" test suite \e[0m"
  done

  # Ensure installation precedes every selected application test suite
  # This is mandatory as database is initialized by this test suite
  if [[ !${#TESTS_TO_RUN[@]} -eq 0 && ! "${TESTS_TO_RUN[@]}" =~ "install" ]]; then
    TESTS_TO_RUN=("install" "${TESTS_TO_RUN[@]}")
  fi
elif [[ "$ALL" = true ]]; then
  TESTS_TO_RUN=("${TESTS_SUITES[@]}")
fi

# Display help if user asks for it, or if it does not provide which test suite has to be executed
if [[ "$HELP" = true || ${#TESTS_TO_RUN[@]} -eq 0 ]]; then
  cat << EOF
This command runs the tests in an environment similar to what is done by CI.

Usage: run_tests.sh [options] [tests-suites]

Examples:
 - run_tests.sh --all
 - run_tests.sh --build ldap imap
 - run_tests.sh units

Available options:
 --all      run all tests suites
 --build    build dependencies and translation files before running test suites

Available tests suites:
 - install
 - update
 - units
 - e2e
 - functional
 - ldap
 - imap
 - web
EOF

  exit 0
fi

# Check for system dependencies
if [[ ! -x "$(command -v docker)" ]]; then
  echo "This script requires the \"docker\" utility to be installed"
  exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
  echo "This script requires the Docker Compose v2 plugin"
  exit 1
fi

# Import variables from .env file this file exists
if [[ -f "$WORKING_DIR/.env" ]]; then
  source $WORKING_DIR/.env
fi

# Define variables (some may be defined in .env file)
APPLICATION_ROOT=$(readlink -f "$WORKING_DIR/..")
[[ ! -z "$APP_CONTAINER_HOME" ]] || APP_CONTAINER_HOME=$(mktemp -d -t glpi-tests-home-XXXXXXXXXX)
[[ ! -z "$TEST_DB_TYPE" ]] || TEST_DB_TYPE=mysql
case "$TEST_DB_TYPE" in
  mysql) [[ ! -z "$DB_IMAGE" ]] || DB_IMAGE=public.ecr.aws/docker/library/mariadb:10.11 ;;
  pgsql) [[ ! -z "$DB_IMAGE" ]] || DB_IMAGE=public.ecr.aws/docker/library/postgres:18 ;;
  *) echo "Unsupported test database provider: $TEST_DB_TYPE" >&2; exit 1 ;;
esac
[[ ! -z "$PHP_IMAGE" ]] || PHP_IMAGE=itsm-tests-app:local
if [[ " ${TESTS_TO_RUN[*]} " == *" e2e "* ]]; then
  TEST_DB_NAME="${TEST_DB_NAME:-itsm_port_e2e}"
  PLAYWRIGHT_VAR_DIR=/home/itsm/e2e-var
  mkdir -p "$APP_CONTAINER_HOME/e2e-var"/{_cache/cache_db,_cache/cache_trans,_cron,_dumps,_graphs,_locales,_lock,_log,_pictures,_plugins,_rss,_sessions,_tmp,_uploads}
  export TEST_DB_NAME PLAYWRIGHT_VAR_DIR
fi

# Restore only this invocation's backup, including after partial setup failure.
BACKUP_DIR=$(mktemp -d -t glpi-tests-backup-XXXXXXXXXX)
BACKUP_COMPLETE=false
CONTAINERS_STARTED=false
cleanup() {
  local result=$? cleanup_status=0 path
  trap - EXIT
  trap '' INT TERM
  set +e
  if [[ "$BACKUP_COMPLETE" == true ]]; then
    # Preserve the established cleanup of generated, non-hidden config files.
    rm -f -- "$APPLICATION_ROOT/tests/config/"* || cleanup_status=1
  fi
  for path in "$BACKUP_DIR/"* "$BACKUP_DIR/".[!.]* "$BACKUP_DIR/"..?*; do
    [[ -e "$path" || -L "$path" ]] || continue
    # Never nest a saved directory inside a conflicting generated directory.
    mv -fT -- "$path" "$APPLICATION_ROOT/tests/config/${path##*/}" || cleanup_status=1
  done
  rmdir -- "$BACKUP_DIR" || cleanup_status=1
  if [[ "$CONTAINERS_STARTED" == true ]]; then
    "$APPLICATION_ROOT/.github/actions/teardown_containers-cleanup.sh" || cleanup_status=1
  fi
  if [[ "$cleanup_status" -ne 0 ]]; then
    echo "Test harness cleanup failed; any unrestored configuration remains in $BACKUP_DIR" >&2
    [[ "$result" -ne 0 ]] || result=1
  fi
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
for path in "$APPLICATION_ROOT/tests/config/"* "$APPLICATION_ROOT/tests/config/".[!.]* "$APPLICATION_ROOT/tests/config/"..?*; do
  [[ -e "$path" || -L "$path" ]] || continue
  [[ "${path##*/}" =~ ^\.[gG][iI][tT][iI][gG][nN][oO][rR][eE]$ ]] && continue
  mv -- "$path" "$BACKUP_DIR/"
done
BACKUP_COMPLETE=true

# Start mail and directory services only for their selected suites.
COMPOSE_PROFILES=""
for suite in "${TESTS_TO_RUN[@]}"; do
  case "$suite" in
    ldap|imap) COMPOSE_PROFILES="${COMPOSE_PROFILES:+$COMPOSE_PROFILES,}$suite" ;;
  esac
done
export COMPOSE_PROFILES

# Export variables to env (required for compose) and start containers
export COMPOSE_FILE="$APPLICATION_ROOT/.github/actions/docker-compose-app.yml"
export COMPOSE_FILE="$COMPOSE_FILE:$APPLICATION_ROOT/.github/actions/docker-compose-services.yml"
if [[ "$TEST_DB_TYPE" == pgsql ]]; then
  export COMPOSE_FILE="$COMPOSE_FILE:$APPLICATION_ROOT/.github/actions/docker-compose-postgres.yml"
fi
if [[ " ${TESTS_TO_RUN[*]} " == *" e2e "* ]]; then
  export COMPOSE_FILE="$COMPOSE_FILE:$APPLICATION_ROOT/.github/actions/docker-compose-e2e.yml"
fi
export APPLICATION_ROOT
export APP_CONTAINER_HOME
export TEST_DB_TYPE
export DB_IMAGE
export PHP_IMAGE
cd $WORKING_DIR # Ensure compose will look for .env in current directory
CONTAINERS_STARTED=true
"$APPLICATION_ROOT/.github/actions/init_containers-start.sh"
$APPLICATION_ROOT/.github/actions/init_show-versions.sh

# Install dependencies if required
[[ -z "$BUILD" ]] || docker compose exec -T app .github/actions/init_install-dependencies.sh

# Run tests
for TEST_SUITE in "${TESTS_TO_RUN[@]}";
do
  echo -e "\n\e[1;30;43m Running \"$TEST_SUITE\" test suite \e[0m"
  LAST_EXIT_CODE=0
  case $TEST_SUITE in
    "install")
         docker compose exec -T app .github/actions/test_install.sh \
      || LAST_EXIT_CODE=$?
      ;;
    "update")
         docker compose exec -T app .github/actions/test_update-from-older-version.sh \
      || LAST_EXIT_CODE=$?
      ;;
    "units")
         docker compose exec -T app .github/actions/test_tests-units.sh \
      || LAST_EXIT_CODE=$?
      ;;
    "functional")
         docker compose exec -T app .github/actions/test_tests-functional.sh \
      || LAST_EXIT_CODE=$?
      ;;
    "e2e")
         docker compose exec -T app bash .github/actions/test_tests-e2e-prepare.sh \
      && docker compose exec -T e2e bash .github/actions/test_tests-e2e.sh \
      || LAST_EXIT_CODE=$?
      ;;
    "ldap")
         $APPLICATION_ROOT/.github/actions/init_initialize-ldap-fixtures.sh \
      && docker compose exec -T app .github/actions/test_tests-ldap.sh \
      || LAST_EXIT_CODE=$?
      ;;
    "imap")
         $APPLICATION_ROOT/.github/actions/init_initialize-imap-fixtures.sh \
      && docker compose exec -T app .github/actions/test_tests-imap.sh \
      || LAST_EXIT_CODE=$?
      ;;
    "web")
         docker compose exec -T app .github/actions/test_tests-web.sh \
      || LAST_EXIT_CODE=$?
      ;;
  esac

  if [[ $LAST_EXIT_CODE -ne 0 ]]; then
    echo -e "\e[1;39;41m Tests \"$TEST_SUITE\" failed \e[0m\n"
    break
  else
    echo -e "\e[1;30;42m Tests \"$TEST_SUITE\" passed \e[0m\n"
  fi
done

exit $LAST_EXIT_CODE
