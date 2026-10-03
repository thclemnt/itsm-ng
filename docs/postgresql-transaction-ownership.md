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

Server version lookup belongs to DBAL. The PDO transport now creates the
physical connection through DBAL, with the legacy facade, ORM and DBAL sharing
that owner. The manually borrowed native handle and transfer ledger are removed.
Compatibility prepared commands are registered to the actual connection and
invalidated on direct DBAL close, adapter close and automatic connection-loss
close. [Driver ownership](postgresql-driver-ownership.md) records this source
change and its still-unrun native gates. It does not replace the remaining
application SQL with domain persistence.

## Earlier executed transaction checkpoint

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

The earlier borrowing connection could not automatically reconnect after its
original handle closed. The PDO owner initializes every new physical session;
its timeout, TLS, raw result metadata, binary resources and loss/reconnect
contracts still require fresh native validation. The historical results above
are not evidence for this transport implementation. No current transport full
suite, live replica, TLS/mTLS, remote CI or release-engine application matrix
has been executed. The next step is focused physical driver validation, then
fresh/populated/retry histories and a coherent full suite on both providers.
