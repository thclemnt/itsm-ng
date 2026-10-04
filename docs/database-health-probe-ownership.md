# Database health transport ownership

The current76cd remote MariaDB 11.8 functional run reached both original
`StatusChecker::testStatusFormats` and `testDefaultStatus`, then raised
`NoActiveTransaction` in `DbTestCase::afterTestMethod`. The test began a
transaction before invoking public health. `getDBStatus` called
`DBConnection::establishDBConnection`, which cleared the global adapter and
installed a new master. Teardown then attempted to roll back that replacement
instead of the caller's transaction. This is an application ownership defect.

Database health now owns disposable configured adapters. It creates fresh
instances of the real `DB` and, when configured, `DBSlave` classes, and invokes
their public `connect` methods. Charset, schema, timezone, TLS and physical
command ownership stay with those existing adapters. Health reads use DBAL
directly; connection and inspection failures produce availability status without
publishing SQL or credentials. Health closes only its own transports. A supplied
factory with any retained Doctrine owner, even with `connected = false`, is
rejected before `connect` can close or replace that owner.

The existing public status structure, delay thresholds, replica positions and
default request memoization remain. An explicitly supplied `DatabaseHealthProbe`
bypasses only that default memoization, allowing callers to request a fresh
inspection with their configured transport factories. Missing configuration is
unavailable. Existing application connection switching and the other
`DBConnection` replication consumers remain unchanged. There is no new migration,
domain entity or schema registry.

The public CLI `itsmng:system:status` / `system:status` reaches `getFullStatus`;
the standalone `status.php` bootstrap also reaches public system status.
Neither health path establishes an application writer now. The normal startup
configuration still owns loading the configured `DB` class. Replica configuration
is loaded locally for the probe without changing the caller's global adapter.

The new portability contract prepares real outer and nested caller frames, a
private Computer row, and actual independent configured probes. It checks public
array/text health, retained adapter/DBAL/native identity and depth, private-row
invisibility to probes, ordinary healthy/refused-master/missing-config results,
borrowed and lazy-borrowed owner refusal, distinct read-routed replica positions,
first-error retention on owned cleanup failure, nested and outer rollback, and
complete protected rows, ledger and schema preservation. The configured replica
controls point independent read adapters at the same disposable database; they
do not constitute validation against a live standby or an asynchronous lag.

Validation is SOURCE ONLY. No PHP syntax/style, focused native contract,
functional suite, both-provider full suite, real standby, CLI/browser or remote
CI result is claimed for this candidate. ROOT must run those checks before
integrating it into the current source and operational evidence bindings.
