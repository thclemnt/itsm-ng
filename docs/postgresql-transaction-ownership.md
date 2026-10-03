# PostgreSQL transaction ownership

PostgreSQL accepts `COMMIT` in an aborted transaction as a successful `ROLLBACK`.
A lifecycle hook that catches a database error could therefore make a public
update report success although the server discarded its writes. The shared
`PostgresConnection` now checks the physical transaction before DBAL commits,
preserving its nesting frame when PostgreSQL reports SQLSTATE `25P02`. Explicit
rollback remains possible; rollback to a savepoint restores normal use.

The legacy adapter uses the same connection and maps only that aborted-state
failure to `false`, including a raw `BEGIN` outside DBAL nesting. Other failures
propagate. Direct DBAL calls without an active transaction retain DBAL's existing
exception ordering. Healthy active legacy commits perform both guards.

Server version lookup now belongs to DBAL. The existing borrowing driver records
when its stock PostgreSQL driver takes ownership of the native handle; adapter
close respects that ownership after DBAL automatically closes a lost connection.
Explicit adapter reconnect creates a new handle and bridge. These changes remove
two genuine application native-driver calls, leaving 22 inventoried native sites.
They do not retire the native transport or replace application SQL with ORM.

## Executed validation

The isolated implementation at `8a1affab3727d99e97737f40da34ef64aa2d44f1`
is integrated as `a6c4736df281b6d753b3cfb1ca0419af7a07966b`. PostgreSQL 15.19
and MariaDB 10.11.18 each pass eleven selected contracts with the unchanged
300-second budget: PostgreSQL transactions, original database portability,
ORM records, ORM writes, ORM, ORM criteria, history, event log, upgrade
entrypoints, schema check and migration history.

The new contract passes 66 PostgreSQL assertions and four unchanged-provider
boundary assertions on MariaDB. It exercises real DBAL/ORM failures, a swallowed
transactional callback, raw legacy transactions, nested recovery, supplied
secondary connections, physical session identity, version lookup and actual
backend termination followed by DBAL auto-close and explicit adapter reconnect.

Raw migration-history replay passes in 127.888s on PostgreSQL and 275.773s on
MariaDB. It covers frozen baseline/seeds, populated adoption and `db:update`,
invalid data before DDL, interrupted fresh installation, actual retry and
idempotency. Final native inspection finds twelve completed versions, no pending
migration or schema difference, 357 mapped tables and 1,057 actual foreign keys
on each provider. PostgreSQL's 354 scoped owned sequences have their expected
widths. Native generated-column definitions and historical comments are retained.

The separate Transfer worker reproduced the swallowed final `post_updateItem`
failure before this fix. Its unchanged causal probe passes after integration:
396 assertions on PostgreSQL and 371 on MariaDB, covering standalone, owned and
caller-owned transaction outcomes. Broader Transfer validation remains separate.

These are focused results, not a complete combined suite or browser milestone.
Exact commands, source hashes, timings and retained failures are recorded outside
the repository in `postgresql-transactions-validation.jsonl` and the transaction
ownership handoff under the cloud evidence directory. Two initial attempts failed
at the dependency gate before touching databases; matching private dependencies
were installed without weakening the gate.

## Remaining boundaries

An old retained Doctrine borrowing connection cannot automatically reconnect
after its original handle closes; explicit adapter reconnect is validated.
Full transport retirement still needs supported ordinal result-type metadata
and TLS/timeout option parity. No live replica, TLS deployment, PostgreSQL-only
installation, remote CI, browser run or official release-engine application
matrix was exercised in this batch. The next integration step is to complete
Transfer validation, freeze the combined source, and run fresh installations,
complete portability and application/browser suites on both providers.
