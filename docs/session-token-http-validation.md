# Personal-token and remembered-cookie HTTP validation draft

This source-only batch adds five Chromium scenarios for the admission and cookie
changes at `2a8c277133` and `f3777170fd`. It was prepared in an isolated worktree
at the latter commit. It does not change production routes, authentication policy,
Playwright timeouts, retries, or the frozen combined-validation source.

The scenarios use actual login forms, `front/planning.php` calendar exports,
`apirest.php/getFullSession`, the Location choice endpoint, and Computer updates.
The original rendered CSRF form and captured widget-issued IDOR request are reused
after calendar refusals. Session observation uses the browser's current effective
PHP cookie as the authorized API session token. Accepted calendar scope is checked
through exact TicketTask event identities because the planning export destroys its
temporary authenticated session on success.

## Scenarios

- Inactive, deleted, future, expired, and grantless personal-token accounts refuse
  export. Direct grants refuse descendants and requested recursion; recursive
  grants refuse foreign entities; a root-only grant refuses an unrelated child.
  The next real request retains the prior browser account, profile, entity scope,
  groups, cookie, CSRF and IDOR state. The original capabilities remain usable.
- Direct, root-zero, explicit descendant, and descendant-subtree calendars expose
  exactly their permitted tasks. `gID=mine` respects the selected scope. A real
  `init_session` plugin hook provisions the initially absent grant before normal
  grant initialization, and its resulting calendar is accepted.
- A real remember-me login delivers an HTTP-only expiring cookie whose plaintext
  verifies against the stored hash. A fresh browser containing only that delivered
  cookie authenticates through the real auto-login route.
- A real public User update hook refuses cookie rotation. Password admission still
  succeeds, the previous cookie remains delivered and persisted, and the hook runs
  exactly once.
- Authorized API writes set the owned remembered credential's date to NULL and an
  expired timestamp. Native readback verifies each setup before a fresh cookie-only
  browser rejects it, receives cookie deletion, and the old plaintext ceases to
  match the persisted hash.

## Fixture boundaries

The CLI companion requires an existing disposable `itsm_port_` installation,
ordinary fixture administrator `itsm`, and a private `GLPI_VAR_DIR` outside the
server document root. A private mode-0600 manifest binds the exact database,
random capability, owned records and narrowly permitted plugin effects. Capability
and cookie values travel to the CLI on stdin, not command arguments or diagnostic
output. Browser storage's URL-encoded cookie value is decoded once for native
readback; the browser receives the original delivered cookie unchanged.

Entity creation uses actual public tree hooks and an authorized root-scope refresh.
The other deterministic test records use the existing mapped FixtureRecords API.
Cleanup purges the owned graph through the actual authorized administrator REST
API. The CLI finalizer refuses cleanup while any owned record remains; it restores
remember-me settings and removes only its owned plugin registration and manifest.
Retain a failed manifest and disposable database for diagnosis rather than silently
removing unpurged records.

The fixture-only plugin is activated exclusively for this private installation.
Its hooks are guarded by the manifest, exact owned identities, `itsm_port_`, the
PHP built-in server and loopback hostname. It provisions one owned grant, vetoes
one owned User's cookie write, or closes the prior PHP session before the actual
planning controller. The closed-session case is therefore explicit lifecycle
instrumentation, not evidence that an uninstrumented route normally closes its
prior session. The private router only selects fixture plugin directories and
returns control to real application routes; it adds no HTTP/debug endpoint.

This tests HTTP cookie/session persistence, actual calendar visibility and reuse
of issued capabilities. It does not prove rollback of external plugin side
effects, arbitrary locale/cache behavior, non-PHP-server deployment behavior,
live replica routing, REST external-user-token policy, HTTP IP restrictions beyond
the configured authorized client, or third-party iCal client compatibility. Treat
browser traces and fixture manifests as private credential-bearing artifacts.

## Required execution gate

Compose these tests with the reviewed Boolean-first and Session source before
running them independently on PostgreSQL and MySQL/MariaDB. Prepare dependencies,
assets and an installed disposable database using the normal project procedure;
create private writable variable directories and configure an authorized API
client. The web server and CLI must use the same database configuration and
private variable directory. Do not run against the frozen matrix's databases.

Example after that preparation, from the exact composed worktree:

```sh
GLPI_CONFIG_DIR=/absolute/private/config GLPI_VAR_DIR=/absolute/private/files \
  php -S 127.0.0.1:PORT -t . tests/database-portability/fixtures/session-token-web-router.php
```

In another terminal, with the same `GLPI_VAR_DIR`, the normal PHP runtime and an
authorized `PLAYWRIGHT_APP_TOKEN` supplied privately:

```sh
PLAYWRIGHT_SESSION_CONFIG=/absolute/private/config \
PLAYWRIGHT_BASE_URL=http://127.0.0.1:PORT \
  npx playwright test --config=tests/e2e/playwright.config.mts session-tokens.spec.mts
```

Require five executed scenarios and zero skips on each provider, complete API
cleanup, and inspection of PHP/server/application logs for warnings and errors.
Preserve exact source hashes, browser results and logs separately for both
providers. No database, application bootstrap, server, HTTP, browser, dependency
copy, asset build or remote CI was run while preparing this draft. Only PHP lint,
format checks, TypeScript no-emit compilation and independent source review can
be claimed at this checkpoint. The overall modernization goal remains open.
