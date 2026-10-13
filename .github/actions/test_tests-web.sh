#!/bin/bash
# SPDX-License-Identifier: GPL-2.0-or-later
set -euo pipefail

log_dir=${RUNNER_TEMP:-$(mktemp -d)}
log="$log_dir/web-api-server.log"
server=''
cleanup() {
  result=$?
  trap - EXIT INT TERM
  set +e
  server_status=0
  if [[ -n "$server" ]]; then
    if kill -0 "$server" 2>/dev/null; then
      kill -TERM "$server"
      # Bound shutdown of this owned child; never search for other PHP servers.
      for attempt in {1..50}; do
        kill -0 "$server" 2>/dev/null || break
        sleep 0.1
      done
      if kill -0 "$server" 2>/dev/null; then kill -KILL "$server"; fi
      wait "$server"
      server_status=$?
    else
      wait "$server"
      server_status=$?
      # A server that stopped before cleanup is an error even after test success.
      [[ "$result" -ne 0 ]] || result=1
    fi
    [[ "$server_status" -eq 143 ]] || { [[ "$result" -ne 0 ]] || result=1; }
  fi
  sed -E 's/((GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS) )[^[:space:]]+/\1[request URI omitted]/g' "$log" > "$log_dir/web-api-server-diagnostics.log"
  redact_status=$?
  [[ "$redact_status" -eq 0 ]] || { [[ "$result" -ne 0 ]] || result=1; }
  rm -f "$log"
  echo "API tests exit=$result; owned server exit=$server_status"
  exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Keep functional API tests independent of runner-provided JIT defaults.
php -d opcache.jit=disable -d opcache.jit_buffer_size=0 \
  -S localhost:8088 tests/router.php > "$log" 2>&1 &
server=$!

# Admit tests only after this child's fresh log confirms it bound the port.
ready=false
for attempt in {1..50}; do
  kill -0 "$server" 2>/dev/null || break
  if grep -Fq 'Development Server (http://localhost:8088) started' "$log"; then
    ready=true
    break
  fi
  sleep 0.1
done
if [[ "$ready" != true ]] || ! kill -0 "$server" 2>/dev/null; then exit 1; fi

test_files=(tests/web/APIRest.php tests/web/Telemetry.php)
if php -r 'exit(extension_loaded("xmlrpc") ? 0 : 1);'; then
  test_files+=(tests/web/APIXmlrpc.php)
else
  echo "::notice title=Optional XML-RPC tests not run::The native xmlrpc extension is absent; this job covers REST API and web telemetry only."
fi

vendor/bin/atoum \
  -p 'php -d memory_limit=512M' \
  --debug \
  --force-terminal \
  --use-dot-report \
  --bootstrap-file tests/bootstrap.php \
  --fail-if-skipped-methods \
  --fail-if-void-methods \
  --no-code-coverage \
  --max-children-number 1 \
  -f "${test_files[@]}"
