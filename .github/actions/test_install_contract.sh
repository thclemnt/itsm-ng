#!/bin/bash
# SPDX-License-Identifier: GPL-2.0-or-later
# Pure shell process-boundary test: the console and PHP commands are stubs.
set -euo pipefail

source_script=$(realpath "$(dirname "$0")/test_install.sh")
fixture_directory=$(mktemp -d)
trap 'rm -rf "$fixture_directory"' EXIT
mkdir -p "$fixture_directory/bin" "$fixture_directory/commands"
cp "$source_script" "$fixture_directory/install.sh"
cat > "$fixture_directory/bin/console" <<'STUB'
#!/bin/bash
printf '%s\n' "$1" >> "$INSTALL_CONTRACT_CALLS"
if [[ $1 == itsmng:database:install ]]; then printf '%s\n' "$@" > "$INSTALL_CONTRACT_ARGUMENTS"; fi
if [[ $1 == itsmng:database:install ]]; then
  exit "$INSTALL_CONTRACT_INSTALL_STATUS"
fi
# Success-looking output must never hide a nonzero CLI result.
echo 'Canonical database history complete; release metadata updated.'
exit "$INSTALL_CONTRACT_UPDATE_STATUS"
STUB
cat > "$fixture_directory/commands/php" <<'STUB'
#!/bin/bash
[[ $1 == tests/e2e/check_installed_history.php && $2 == ./tests/config ]] || exit 99
echo verified >> "$INSTALL_CONTRACT_CALLS"
exit "$INSTALL_CONTRACT_VERIFY_STATUS"
STUB
chmod +x "$fixture_directory/bin/console" "$fixture_directory/commands/php"
export PATH="$fixture_directory/commands:$PATH"
export INSTALL_CONTRACT_CALLS="$fixture_directory/calls"
export INSTALL_CONTRACT_ARGUMENTS="$fixture_directory/install-arguments"
unset TEST_DB_TYPE TEST_DB_NAME

run_case() {
  export INSTALL_CONTRACT_INSTALL_STATUS=$1 INSTALL_CONTRACT_UPDATE_STATUS=$2 INSTALL_CONTRACT_VERIFY_STATUS=$3
  local expected_status=$4 expected_calls=$5 actual_status=0
  : > "$INSTALL_CONTRACT_CALLS"
  (cd "$fixture_directory" && bash -e ./install.sh) > "$fixture_directory/output" 2>&1 || actual_status=$?
  [[ $actual_status == "$expected_status" ]] || { echo 'Unexpected installation wrapper exit' >&2; exit 1; }
  [[ $(cat "$INSTALL_CONTRACT_CALLS") == "$expected_calls" ]] || { echo 'Unexpected readiness command ordering' >&2; exit 1; }
}

run_case 0 0 0 0 $'itsmng:database:install\nitsmng:database:update\nverified'
run_case 0 42 0 42 $'itsmng:database:install\nitsmng:database:update'
run_case 0 0 43 43 $'itsmng:database:install\nitsmng:database:update\nverified'
run_case 44 0 0 44 'itsmng:database:install'
grep -Fx -- '--db-name=glpi' "$INSTALL_CONTRACT_ARGUMENTS"
grep -Fx -- '--db-type=mysql' "$INSTALL_CONTRACT_ARGUMENTS"
grep -Fx -- '--db-port=3306' "$INSTALL_CONTRACT_ARGUMENTS"
export TEST_DB_TYPE=pgsql
run_case 0 0 0 0 $'itsmng:database:install\nitsmng:database:update\nverified'
grep -Fx -- '--db-type=pgsql' "$INSTALL_CONTRACT_ARGUMENTS"
grep -Fx -- '--db-port=5432' "$INSTALL_CONTRACT_ARGUMENTS"
grep -Fx -- '--db-password=test' "$INSTALL_CONTRACT_ARGUMENTS"
export TEST_DB_NAME=itsm_port_e2e
run_case 0 0 0 0 $'itsmng:database:install\nitsmng:database:update\nverified'
grep -Fx -- '--db-name=itsm_port_e2e' "$INSTALL_CONTRACT_ARGUMENTS"
echo 'Installation wrapper status, readiness ordering and native provider arguments: six cases passed.'

# Exercise the real outer harness without containers, application bootstrap or PHP.
# Keep these cases alongside the installer process-boundary contract above.
harness_source=$(realpath "$(dirname "$source_script")/../../tests/run_tests.sh")
harness="$fixture_directory/harness"
mkdir -p "$harness/tests/config" "$harness/.github/actions" "$harness/commands" "$harness/tmp"
cp "$harness_source" "$harness/tests/run_tests.sh"
real_mv=$(command -v mv)
export HARNESS_REAL_MV="$real_mv" HARNESS_CALLS="$harness/calls"
cat > "$harness/commands/docker" <<'STUB'
#!/bin/bash
# Only the outer dependency check is permitted to invoke this stub.
[[ "$*" == 'compose version' ]]
STUB
cat > "$harness/commands/mv" <<'STUB'
#!/bin/bash
source_path="${@: -2:1}"
if [[ "$HARNESS_CASE" == restore-failure || "$HARNESS_CASE" == primary-and-restore ]]; then
  [[ "$source_path" != */glpi-tests-backup-*/* ]] || exit 57
fi
if [[ "$HARNESS_CASE" == partial-backup && "${source_path##*/}" == z-last.php && "$source_path" == */tests/config/* ]]; then exit 58; fi
exec "$HARNESS_REAL_MV" "$@"
STUB
cat > "$harness/.github/actions/init_containers-start.sh" <<'STUB'
#!/bin/bash
printf 'start\n' >> "$HARNESS_CALLS"
case "$HARNESS_CASE" in
  startup-failure|primary-and-restore) exit 41 ;;
  term) kill -TERM "$PPID" ;;
  interrupt) kill -INT "$PPID" ;;
esac
STUB
cat > "$harness/.github/actions/init_show-versions.sh" <<'STUB'
#!/bin/bash
printf 'versions\n' >> "$HARNESS_CALLS"
[[ "$HARNESS_CASE" != versions-failure ]] || exit 42
STUB
cat > "$harness/.github/actions/docker-compose.sh" <<'STUB'
#!/bin/bash
printf '%s\n' "${*: -1}" >> "$HARNESS_CALLS"
case "${*: -1}" in
  .github/actions/init_install-dependencies.sh)
    [[ "$HARNESS_CASE" != dependencies-failure ]] || exit 43 ;;
  .github/actions/test_install.sh)
    printf 'generated\n' > "$APPLICATION_ROOT/tests/config/config_db.php"
    [[ "$HARNESS_CASE" != test-failure ]] || exit 44 ;;
  *) exit 99 ;;
esac
STUB
cat > "$harness/.github/actions/teardown_containers-cleanup.sh" <<'STUB'
#!/bin/bash
printf 'teardown\n' >> "$HARNESS_CALLS"
[[ "$HARNESS_CASE" != teardown-failure ]] || exit 45
STUB
chmod +x "$harness/commands/"* "$harness/.github/actions/"*

run_harness_case() {
  local expected=$2 status=0
  export HARNESS_CASE=$1
  rm -rf -- "$harness/tests/config" "$harness/tmp"
  mkdir -p "$harness/tests/config/nested" "$harness/tmp"
  printf 'original\n' > "$harness/tests/config/config_db.php"
  printf 'hidden\n' > "$harness/tests/config/.secret"
  printf 'last\n' > "$harness/tests/config/z-last.php"
  printf 'nested\n' > "$harness/tests/config/nested/value"
  printf 'ignore\n' > "$harness/tests/config/.gitignore"
  : > "$HARNESS_CALLS"
  env -u BUILD -u ALL -u HELP -u TEST_DB_NAME -u COMPOSE_FILE \
    PATH="$harness/commands:$PATH" TMPDIR="$harness/tmp" \
    APP_CONTAINER_HOME="$harness/home" TEST_DB_TYPE=mysql \
    bash -e "$harness/tests/run_tests.sh" --build install > "$harness/output" 2>&1 || status=$?
  [[ "$status" == "$expected" ]] || { cat "$harness/output" >&2; echo "Harness $1: expected $expected, got $status" >&2; exit 1; }
  if [[ "$1" == partial-backup ]]; then
    [[ ! -s "$HARNESS_CALLS" ]]
  else
    [[ $(grep -c '^teardown$' "$HARNESS_CALLS") == 1 ]]
  fi
  [[ $(cat "$harness/tests/config/.gitignore") == ignore ]]
  if [[ "$1" == restore-failure || "$1" == primary-and-restore ]]; then
    local saved=("$harness/tmp/"glpi-tests-backup-*)
    [[ ${#saved[@]} == 1 && $(cat "${saved[0]}/config_db.php") == original ]]
    [[ $(cat "${saved[0]}/nested/value") == nested ]]
  else
    [[ $(cat "$harness/tests/config/config_db.php") == original ]]
    [[ $(cat "$harness/tests/config/.secret") == hidden ]]
    [[ $(cat "$harness/tests/config/nested/value") == nested ]]
    [[ $(cat "$harness/tests/config/z-last.php") == last ]]
    [[ -z $(find "$harness/tmp" -mindepth 1 -print -quit) ]]
  fi
}
run_harness_case success 0
run_harness_case startup-failure 41
run_harness_case versions-failure 42
run_harness_case dependencies-failure 43
run_harness_case test-failure 44
run_harness_case partial-backup 58
run_harness_case restore-failure 1
run_harness_case primary-and-restore 41
run_harness_case teardown-failure 1
run_harness_case term 143
run_harness_case interrupt 130
echo 'Outer harness configuration restoration, teardown and failure/signal status: eleven stubbed cases passed.'
