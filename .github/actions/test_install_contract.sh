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
unset TEST_DB_TYPE

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
grep -Fx -- '--db-type=mysql' "$INSTALL_CONTRACT_ARGUMENTS"
grep -Fx -- '--db-port=3306' "$INSTALL_CONTRACT_ARGUMENTS"
export TEST_DB_TYPE=pgsql
run_case 0 0 0 0 $'itsmng:database:install\nitsmng:database:update\nverified'
grep -Fx -- '--db-type=pgsql' "$INSTALL_CONTRACT_ARGUMENTS"
grep -Fx -- '--db-port=5432' "$INSTALL_CONTRACT_ARGUMENTS"
grep -Fx -- '--db-password=test' "$INSTALL_CONTRACT_ARGUMENTS"
echo 'Installation wrapper status, readiness ordering and native provider arguments: five cases passed.'
