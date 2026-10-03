# Identifier migration index inspection

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

## Later native planning parity

Root's exact old/new planner probes pass both engines at 614. Ordered operations
are identical (plain PostgreSQL 5/MariaDB 4; mixed 13 both), while native index
inspection calls fall 2→0 for plain tables and 3→1 for mixed tables. Generated
native index definitions remain authoritative. The original helper failures are
retained, including the correction that verifies each PostgreSQL
CURRENT_DATABASE query paired with its removed native index call; unrelated
ordered reads remain identical. These are controlled planning observations, not
isolated elapsed-time causation. Complete 913 historical replay passes both
under the 300-second limit. Full a736 portability later finishes with failures:
PostgreSQL 178/179 and MariaDB 151/179. Native PostgreSQL converges at 13 canonical
versions; MariaDB has two missing Boolean CHECKs in its final inspection. Those
failures are not isolated performance evidence. The original source-only planning
gates below document the earlier preparation checkpoint; current repairs remain
PENDING native validation.


Historical preparation and diagnosis follow; statements of unexecuted gates
below describe those earlier snapshots.

`WideIdentifiers::plan()` already loads one DBAL schema snapshot containing columns, indexes, foreign keys and table options. It now avoids a second native index query for each widening table without generated columns: those tables cannot contribute any generated-column index drop or restore operation.

Generated-column tables still use `listTableIndexes()`. Replacing that call with `Table::getIndexes()` would be unsafe: DBAL's `Table` constructor can add an implicit FK-support index that does not exist in PostgreSQL. A generated-column foreign key without a native supporting index must not produce a drop for this synthetic index. The existing native inspection, index flags/options, quoted identifiers, primary-key rejection and statement order remain intact for these tables. Post-DDL index inspection in `execute()` also remains intact for retry decisions.

Source review used the installed Doctrine DBAL implementation: `AbstractSchemaManager::listTables()` and `listTableIndexes()` share the portable index conversion, including MySQL prefix lengths/FULLTEXT/SPATIAL flags and PostgreSQL predicates/quoted names, but `Table::_addForeignKeyConstraint()` can supplement that result. This change avoids depending on or filtering those internal synthetic indexes. Historical migration definitions, data audits and journals are unchanged; no catalogue cache survives a planning call.

This is source-only validation. PHP syntax, configured formatting and whitespace checks pass. No artificial connection mock or implementation-mirroring assertion was added: the meaningful regression needs native catalogue observations during the actual plan. Remaining live gates on PostgreSQL and MySQL/MariaDB are:

- Compare planning SQL and catalogue-query counts for populated narrow identifiers, including storage-only changes and tables without generated columns.
- Preserve generated-column indexes, quoted names, composite/unique indexes and applicable flags/options. Include a PostgreSQL generated-column FK with no native supporting index and verify no synthetic index is dropped.
- Run populated adoption, interrupted DDL retry/idempotency and fresh installation, then the complete portability suite and final native schema inspection.
- Measure the unchanged full-history contract under its 300-second budget. The previous MariaDB result of 295.178 seconds is a planning risk; no runtime improvement or Boolean-stage timing has been established by this source change.
