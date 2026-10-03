# MariaDB native CHECK catalogue reads

This batch starts from `9dcd407d53bddffc47ff4b6fc4a0de6f5dda7af3` and changes
only native inspection in BooleanDomainSchema::catalog and the frozen Category
migration's plan. MariaDB exposes CHECK ownership directly through
information_schema.CHECK_CONSTRAINTS.TABLE_NAME, so querying that catalogue avoids
a costly TABLE_CONSTRAINTS join. Schema inspection remains scoped to the supplied
connection's DATABASE(); the category snapshot additionally binds its actual table.

The current Boolean domain catalogue still derives expected types/nullability and
constraints from owning properties. Its complete native table/name/clause maps,
column snapshot, per-call refresh and sorted return shape are unchanged. Category
version, fixed columns, data validation, diagnostics, target DDL, retry and ledger
behavior remain frozen. No runtime field registry, global cache, migration ledger,
DDL rewrite or metadata-generated replacement history is introduced.

Oracle MySQL keeps its original TABLE_CONSTRAINTS join and actual ENFORCED field:
its native CHECK_CONSTRAINTS lacks MariaDB's TABLE_NAME extension, and Oracle can
disable individual CHECKs. PostgreSQL inspection is unchanged. The existing
supported minimums remain MariaDB 10.2.22 and MySQL 8.0.16.

BooleanDomainSchema continues to assert version/strict-mode support and MariaDB's
session check_constraint_checks before inspecting rows. MariaDB's reported YES
means enforcement under that already-validated session, not a substitute for the
session guard. Canonical History entrypoints retain the same guard. Standalone
CategoryFlags::plan does not independently assert that capability today; this
query-only change preserves that existing boundary.

Controlled native evidence was collected by the parent worker at exact source
9dcd407d53 on its owned MariaDB handle. Complete old/new native tuples match in both
ordinary and ANSI_QUOTES modes: 440 schema tuples and six category tuples per mode.
Two owned tables with the same CHECK name and different clauses remain distinct.
Disabled session checks are rejected by the canonical Boolean inspector; original
session modes/check flag, ledger and owned fixture cleanup all verify restored.
The original and proposed queries use the same physical handle and native session
formatting; no CHECK grammar or comparison assertion is relaxed.

Measured query reads in this controlled probe:

| Mode | Schema join / direct | Category join / direct |
| --- | --- | --- |
| ordinary | 2.489378s / 0.012551s | 1.199071s / 0.000456s |
| ANSI_QUOTES | 2.729645s / 0.012935s | 1.219177s / 0.000433s |

These are individual native read observations, not a benchmark of full migration
replay, application behavior or a demonstrated full-history budget improvement.
The separate KEY_COLUMN_USAGE hotspot is outside this change.

An earlier external helper failed while restoring the session flag with default
string parameter binding. Its exact two empty owned probe tables and creation
metadata were verified and cleaned; the helper then used INTEGER binding, eager
ownership recording and an all-owned-table cleanup check. The corrected probe
passed. This was test-helper failure/recovery, not an application production
failure; its original log and recovery record remain preserved.

Cloud evidence under /workspace/itsm-env/evidence:

- mariadb-check-catalog-live-parity-9dcd.json and its log record the corrected native
  equality, timings, disabled-check rejection and cleanup.
- mariadb-check-catalog-live-parity-9dcd-failed-binding.log and
  mariadb-check-parity-failed-helper-owned-recovery.json preserve the earlier helper
  failure and verified recovery.
- mariadb-check-catalog-proposal-source.json records the exact old/new native SQL,
  category parameter, source hashes and the isolated candidate.

Both changed files lint clean, scoped formatting/whitespace checks pass, and the
unchanged pure boolean value/grammar/capability/history contract passes 92 assertions.
That pure check does not prove native inspection on another engine. Oracle MySQL
native execution remains unrun, as do the integrated category/boolean migration,
retry, preview and full 300-second history checks on this candidate. Those actual
contracts and final schema evidence are still required before claiming a coherent
migration milestone. This inspection optimization preserves all prior failures
and does not increase timeouts or omit native snapshots.
