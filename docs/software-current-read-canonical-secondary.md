# Canonical secondary writer for current-read validation

ROOT reported a current `5019fd7b0376fb0405cd171ed542c6cf2e799f54` full MariaDB
failure in `software-assignment-current-reads.php` at the distinct physical-writer
check (original line 156): `TransactionOwnershipMismatch`, requiring the
canonical command-owning DBAL transport. This is an actual reported failure,
not a passing result for the original MariaDB two-writer scenarios.

The MySQL fixture constructed its secondary connection using
`DriverManager::getConnection($connection->getParams())`. Parameters carry the
managed wrapper class, but do not carry the owning driver/middleware built by
the canonical MySQL factory. The production ownership guard correctly refused
that unmanaged transport. The PostgreSQL branch already used a fresh configured
application adapter and its public `connect()` method.

Both providers now use that configured-adapter pattern. The fixture creates an
independent instance of the actual configured DB subclass without cloning the
primary's live handle, then calls normal public `connect()`. Actual provider,
database, writer routing and complete connection parameters must match before
fixture writes. Parameters, credentials and TLS material remain private and are
never printed by these checks. Existing PostgreSQL search-path/timezone and
physical backend identity assertions remain. MySQL also compares actual session
timezone, SQL modes and FK enforcement. The original PostgreSQL setup predicate
now checks connected state after the common positive public-connect assertion;
this intentionally differs from its former branch-local connect predicate.

The actual current-read scenario block is unchanged: B physically commits
insertions/deletions/owner or scope changes, while A retains its caller snapshot,
aggregate locks, public mutation boundary and supported/refused isolation cases.
Finite quantity/validity, changed-source no-effects, caller markers, outer
rollback and standalone retry assertions remain byte-exact. The production
managed connection and all ownership guards are unchanged.

Publication rollback now retains the actual primary error if B cleanup fails.
Outer cleanup independently attempts the original A/B rollbacks, temporary
cleanup isolation, every owned public purge, adapter-only close and restoration
of original isolation/session/configuration, in the original successful order.
It retains the first scenario Throwable and reports secondary failures by class
under guarded reporting. If the scenario succeeds but cleanup fails, the first
cleanup error remains a failure. No competing frame, native handle clone or
replacement writer is adopted. The adapter owns its close; the same handle is
not closed again through its DBAL facade.

Source inspection found this one raw secondary factory in the affected Software
workflow. `software-merge-source-lifecycle.php` already constructs an independent
canonical `new DB()` and exercises public writer substitution; it is unchanged.
The PostgreSQL transaction contract already uses configured reflected adapters.
The MySQL session-mode contract's deliberate direct DriverManager construction
supplies its explicit middleware configuration, and its separate read-adapter
and reconnect controls remain unchanged. No general replacement was performed.

This batch is SOURCE ONLY. No PHP compilation, bootstrap, native, browser,
service or vendor job ran in the author worktree. ROOT must compile/style-check
the exact composed source, run the entire original current-read contract on both
providers and inspect its final cleanup/schema, then run the coherent complete
suite on both. The original current `5019` full-suite failure remains recorded
until fresh actual evidence supersedes it. The held next ten component subject
associations and their twenty-stage validation remain a separate future batch.
