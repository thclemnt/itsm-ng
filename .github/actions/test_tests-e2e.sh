#!/bin/bash
set -euo pipefail

for required_file in tests/config/config_db.php tests/config/glpicrypt.key; do
  if [[ ! -f "$required_file" ]]; then
    echo "Missing required test install file: $required_file"
    echo
    echo "run: bin/console itsmng:database:install --config-dir=tests/config ..."
    echo
    exit 1
  fi
done

export PLAYWRIGHT_BASE_URL="${PLAYWRIGHT_BASE_URL:-http://app-web:8088}"
export PLAYWRIGHT_HTML_OPEN=never
export PLAYWRIGHT_APP_TOKEN_FILE="${PLAYWRIGHT_APP_TOKEN_FILE:-tests/files/_playwright/app-token}"

mkdir -p tests/files/_playwright

SERVER_READY=false
for _ in $(seq 1 30); do
  if curl --fail --silent --show-error --location --max-time 2 "$PLAYWRIGHT_BASE_URL/index.php" > /dev/null; then
    SERVER_READY=true
    break
  fi
  sleep 1
done

if [[ "$SERVER_READY" != "true" ]]; then
  echo "PHP test server did not become ready at $PLAYWRIGHT_BASE_URL"
  exit 1
fi

if [[ ! -f "$PLAYWRIGHT_APP_TOKEN_FILE" ]]; then
  echo "Missing E2E API client token file: $PLAYWRIGHT_APP_TOKEN_FILE"
  exit 1
fi

export PLAYWRIGHT_APP_TOKEN=$(cat "$PLAYWRIGHT_APP_TOKEN_FILE")
if [[ -z "$PLAYWRIGHT_APP_TOKEN" ]]; then
  echo "E2E API client token file is empty: $PLAYWRIGHT_APP_TOKEN_FILE"
  exit 1
fi

test_status=0
npx playwright test -c tests/e2e/playwright.config.mts || test_status=$?
node - <<'JAVASCRIPT'
const fs = require('node:fs');
const report = JSON.parse(fs.readFileSync('tests/files/_playwright/results.json', 'utf8'));
const cases = [];
function discover(suites) {
  for (const suite of suites) {
    for (const spec of suite.specs || []) cases.push(...spec.tests);
    discover(suite.suites || []);
  }
}
discover(report.suites);
const { expected, skipped, unexpected, flaky } = report.stats;
console.log(`Browser cases: discovered=${cases.length}, expected=${expected}, skipped=${skipped}, failed=${unexpected}, flaky=${flaky}`);
if (cases.length === 0 || report.errors.length !== 0
    || ![expected, skipped, unexpected, flaky].every(value => Number.isInteger(value) && value >= 0)
    || skipped !== 0 || unexpected !== 0 || expected + flaky !== cases.length
    || cases.some(test => !['passed', 'failed', 'timedOut'].includes(test.expectedStatus)
      || !['expected', 'flaky'].includes(test.status)
      || test.results.length === 0 || test.results.at(-1).status !== test.expectedStatus
      || test.results.some(result => ['skipped', 'interrupted'].includes(result.status)))) {
  throw new Error('Every discovered browser case must complete without skips, interruptions or unexpected failures.');
}
JAVASCRIPT
exit "$test_status"
