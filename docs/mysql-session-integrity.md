# MySQL session integrity: source draft

Current checkpoint: `741fb2b1f0cc852404bd55080e4ed7bbb0bf8b7e`.
The latest complete local portability checkpoint is
`741fb2b1f0cc852404bd55080e4ed7bbb0bf8b7e`: PostgreSQL and MariaDB both pass
180/180 contracts, fresh installs and final native inspection. The modernization
goal remains OPEN; official-engine/remote CI, replicas/TLS and prepared next
batches are separate pending evidence.
[The handoff](modernization-handoff.md#current-741-complete-local-portability-checkpoint)
separates these results from the retained a736 full failures and the earlier complete
green b66 checkpoint. Later passages marked pending describe their earlier
preparation checkpoint unless superseded by the precise checkpoint results above.

## Later integrated validation at a736

The source preparation below is historical. Strict physical-session
initialization is integrated into private a736; root's actual MariaDB mode
contract passes at 1edcd19 in 20.270s, including native rejection and
reconnect/configured-mode controls. PostgreSQL's separate unchanged-provider
control passes at 9d4949. The old required NULL → 0/warning 1048 and configured
ANSI inspection failures remain recorded; preserving configured modes exposed
real defects that received bounded repairs. No replica/TLS validation is
inferred. At exact a736, original applications and ten queue assertions pass
both providers, but the complete portability runs fail PostgreSQL 178/179 and
MariaDB 151/179. Final native PostgreSQL schema converges; MariaDB has two missing
Boolean CHECKs. Strict required-field and historical-fixture repairs remain
PENDING native execution; [the handoff](modernization-handoff.md) separates these
results from browser, official-engine and replica gates.


Historical preparation and diagnosis follow; statements of unexecuted gates
below describe those earlier snapshots.

This isolated batch starts at `677bf5e7da`. It has not run against a database,
been integrated or passed the portability/application matrix. Root's native
causal probe found that the application's empty SQL mode rejected Supplier flags
2 and -1 through CHECK, but accepted required NULL as stored 0 with warning 1048.
The same native statement under STRICT_TRANS_TABLES refused NULL with error1048.
The native domain assertion is retained.

Application connection initialization formerly cleared every configured mode
because GLPI_FORCE_EMPTY_SQL_MODE defaulted to1 for MySQL5.7 compatibility. The
supported MySQL/MariaDB engines already enforce CHECKs; discarding their native
strict writes undermines required field semantics before a CHECK sees the value.
The obsolete constant and empty-mode branch are removed.

`MySQLConnection::create()` is one factory using DBAL's public Middleware API.
Its driver wrapper reads the newly connected session's configured mode, preserves
each mode and adds STRICT_ALL_TABLES before returning the physical connection.
This protects direct DBAL close/reopen as well as adapter reconnect. Existing
STRICT_TRANS_TABLES remains present. The ALL policy also avoids the transactional
mode's possible later-row coercion for nontransactional tables; this batch does
not change any storage engine or imply support for nontransactional core history.

DBmysql's writer/read/configured endpoint initialization and its auxiliary
read-only timezone catalogue connection use the factory. InstallationConnection's
server and selected-database factories use it too; parameter extraction no longer
constructs an unused server connection. Charset, TLS, endpoint selection,
credentials, native numeric options and ORM's supplied read connection remain
with their existing owners. PostgreSQL initialization is untouched. No custom
native driver, trigger or migration ledger is introduced.

Read-only CHECK capability/schema diagnostics accept an external strict ALL or
TRANS session and diagnose a permissive one, without changing that session. This
is not a per-write SQL mode query. Arbitrary callers disabling SQL mode or CHECK
checks explicitly can still defeat the server's chosen enforcement; the
application no longer does that during connection initialization.

Configured ANSI_QUOTES, ONLY_FULL_GROUP_BY, NO_ZERO_DATE, NO_ZERO_IN_DATE and
other modes are preserved, rather than erased to hide incompatible requests or
data. The full suite must expose any real query/data incompatibility. Existing
timestamp/timezone, search/grouping and historical seed/adoption contracts remain
unchanged and must pass before integration is treated as validated.

The prepared mysql-session-modes.php contract uses one owned CREATE/DROP probe
and existing disposable credentials, refuses an existing probe name and cleans up
only after its own CREATE succeeds. It never changes GLOBAL modes, and covers
native flags2/-1/required NULL and string truncation, adapter reconnect, direct DBAL
reconnect, the supplied read adapter, installation factories and a controlled
pre-initialization middleware that supplies ANSI/group/date modes on each owned
physical connection. It also proves externally supplied permissive diagnosis
without mutation and acceptance of unchanged external strictTRANS. The read
adapter shares the owned test endpoint; it is not live replica validation.

Executed source checks pass14 pure policy assertions, syntax/style of all7
changed PHP files and diff whitespace checks. Loading DBAL interfaces and a lazy
factory with an explicit serverVersion also opens no physical connection. These
are nonconnecting checks, not native provider results.

Only nonconnecting checks can run during the current resource hold.
The native contract, boolean-domains.php, original Supplier/Category contracts,
installation/upgrade paths, timestamps/search/reports, original application tests
and both complete dynamically discovered suites remain pending. No historical
migration definition or native domain assertion has been weakened.
