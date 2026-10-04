# Exact subject populated fixture: lock timestamp restoration

The unchanged populated Exact14 upgrade contract at
`be258c8bd204dcfa2dfc5763e76e460ab80bb54f` fails its full-row restoration
assertion after explicitly repairing the fixture's lowercase discriminators.
ROOT reproduced this on both PostgreSQL and MariaDB. A separately reviewed
instrumented copy preserves every original assertion and captures native rows
before corruption, after corruption, after invalid-data audits and after repair.

The actual snapshots identify exactly one changed cell: the owned
`glpi_objectlocks.date_mod`. Every other native cell and row order is unchanged;
the invalid-data audits preserve the corrupted rows, and the prerequisite
control restores the raw ledger. The frozen baseline declares MySQL
`TIMESTAMP ... ON UPDATE CURRENT_TIMESTAMP` and the PostgreSQL equivalent
trigger. Updating only `itemtype` correctly touches this timestamp. These native
semantics must remain intact.

The contract now creates its owned ObjectLock with a fixed historical UTC
timestamp, captures the actual stored native value, and verifies that corrupting
the discriminator exercises automatic timestamp touch. Repair explicitly
restores that captured timestamp together with the owned exact discriminator.
The historical value differs from the automatic timestamp even when setup and
repair occur within one second; there is no sleep, timestamp exclusion, trigger
change or full-record overwrite. All original complete-row hashes and native
schema/ledger assertions remain unchanged.

The evidence is retained externally in
`exact-upgrade-be258-pg-row-vector-actual-20261004T003605-331f1acd86` and
`exact-upgrade-be258-mysql-row-vector-actual-20261004T003708-c94531973f`.
This repair is separate from the Infocom fixture correction. Its source worker
performed no PHP, compiler, bootstrap or native database jobs. PHP syntax and
complete populated upgrade/retry runs of the correction remain pending on both
providers, followed by full suites and final native inspection.

The MariaDB observer took 177.704670 seconds before the original assertion and
cleanup completed. Source tracing finds 225 explicit table introspections before
that assertion: five 25-table native snapshots, the 25-table historical fixture
capture and three 25-table migration audits. DBAL's table inspection reads
columns, indexes, foreign keys and table options; the fixture also reads native
preservation facts and CHECK catalogues. This is a source call count, not query
timing or proof of which operation dominates. Keep the existing 300-second
contract limit. Further profiling is required before proposing an optimization;
no checks, retry controls or fresh snapshot boundaries have been removed here.
