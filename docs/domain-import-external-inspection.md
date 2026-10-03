# Domains import reuses its live schema inspection

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

Root's MariaDB `domain-plugin-import.php` run at
`7ae05e10577220d892a4e47467be3a5c510089f9` exceeded the unchanged 300-second
contract limit at 300.0111 seconds. That is a failed run. A previous different
source reached its first interruption control around 213 seconds; neither result
establishes completion of the later retry/preservation controls. Controlled provider parity is now recorded below. The unchanged complete import
contract, final schema and full coherent matrix remain separate runtime gates.

Source inspection found duplicate full-schema inspection within a single import
plan. The mandatory `SchemaCheck::differences()` calls DBAL's `introspectSchema()`,
which calls `listTables()`. Later `unknownExternalBindings()` called `listTables()`
again, then discarded every mapped table, the four supported source tables and
the migration ledger. DBAL4.5's second call loads bulk column, index, foreign-key
and table-option definitions even though this guard uses only external columns.
This is four bulk inspection families, not a per-table introspection loop.

`SchemaCheck::inspect()` now returns a readonly `SchemaInspection` containing
that call's actual DBAL Schema and complete differences. Its existing
`differences()` API delegates to that live inspection with the same optional
expected schema. The original comparator, native TIMESTAMP checks and
Boolean-domain checks run unchanged before the result returns. No assertion is
skipped, and other callers continue to receive the same list of differences.

Each Domain plan owns one fresh local result and passes its actual Schema to the
external guard in both first-import and completed-receipt branches. The guard
consumes the same native Table/Column objects, exclusions, String/Text selection,
identifier representation, column order, namespace query and diagnostic as
before. This removes its duplicate bulk columns/indexes/FKs/options inspection.
A separate provider table-name enumeration is unsuitable: PostgreSQL's name list
excludes some ordinary user tables and returns quoted names in a different
representation. Reusing the already inspected Schema preserves the original
complete provider coverage.

There is no global or object-field schema cache, no snapshot reuse across plans
or writes, and no change to history, input admission, import locking, ownership,
receipts or retries. Every public `plan()` and locked `import()` performs a fresh
live inspection. Current external identity cases remain unchanged, including
wrong case and whitespace. No mock or source-shape assertion substitutes for
those native controls.

An offline source inventory at the failed source loaded 357 mapped entities and
compiled 142 namespace/key query shapes with physical connections forbidden. It
found 71 identity-field roles and 67 primary adoption roles. A complete accepted
first-time plan therefore issues those 71 namespace queries, 67 primary adoption
queries, one linked-log query, one Impact query and 71 identity-key queries, in
addition to graph/policy/profile inspection. A completed-receipt plan retains its
71 namespace and 71 identity-key queries. These are counts from the actual loops;
no elapsed time or native query execution is attributed to them. Changing these
domain queries is outside this bounded correction.

This source-only batch starts at exactly
`0c36bd25bcea5cf6239222eb96abe3e9375e98d3`. Syntax, formatter and whitespace checks
pass for all three changed PHP files. Production changes are limited to the
inspection result/boundary and its local use by the Domain plan. All frozen
schema/history and existing contract assertions remain unchanged; no application
bootstrap, provider connection, dependency copy, assets, HTTP or browser was run
while preparing it.

## Controlled native parity and retained probe failures

The final source commit `c162abc853f5e5c68ddf8b1f41dc2927b6b00b9a`
starts directly from 0c; all three production files match the independently
reviewed candidate. Root integrated it as
`a736d72e03a41deee9723d39d50d372940ed1335`. The earlier name-list candidate is
excluded from that commit chain: its PostgreSQL quoted-name and omitted ordinary
geometry-table regressions remain external rejected-candidate evidence.

Root-owned controlled native probes compare original 0c and exact renamed final
c162 classes on independently owned supplied-provider connections. Both final
probes pass ordered all-table-name and all-column tuple equality and eleven
actual diagnostic cases. These include plain text, stored generated text with an
index/FK, a mixed-case table containing a space, ordinary geometry_columns and
spatial_ref_sys, numeric nontext values, and fresh discovery after owned ADD/DROP
on the same inspector object. All five created tables are absent afterward;
both connections finish idle and the canonical ledger is unchanged. The measured
phases exercise the public schema inspector and actual private external guard,
not the complete importer or migration history.

The original PostgreSQL helper passes raw strict tuple equality. The original
MariaDB helper fails raw PHP array identity because four separately introspected
CURRENT_TIMESTAMP defaults are distinct DBAL CurrentTimestamp objects. Native
names, scalar values and expression class/SQL are equal. That failed artifact is
retained; the production comparator and native schema were not changed to make
the probe pass. The corrected helper compares only known DBAL DefaultExpression
objects by exact class and platform SQL, keeps all scalars type-strict and leaves
unknown objects identity-sensitive. Negative controls reject different SQL from
the same expression class. Both final probes pass this bounded semantic comparison;
MariaDB's four original raw identity differences remain in their evidence. The
earlier external helper FK API defect and its correction are also preserved.

For the clear complete read phase, the controlled MariaDB probe records the old
phase at 27 queries / 5.463311 seconds and the candidate at 20 queries / 2.850412
seconds. PostgreSQL records 22 / 0.922510 seconds versus 16 / 0.705913 seconds.
These are individual same-fixture inspection observations; they establish neither
a full-import speedup nor that this change alone resolves the 300-second timeout.
No isolation policy, invalid-data query, schema guard or contract budget changed.

Evidence under `/workspace/itsm-env/evidence`:

- `domain-schema-inspection-after-c162-{pg,mysql}-expression-defaults.json`
  records the final equality, negative default controls, eleven cases, SQL-only
  observations and cleanup.
- `domain-schema-inspection-after-c162-mysql.json` retains the original MariaDB
  helper failure; the original PostgreSQL artifact also remains.
- `domain-schema-inspection-final-source-manifest.json` and
  `domain-local-schema-inspection-independent-source-review.*` pin source/checks.

At integrated a736, three PHP files lint clean, scoped formatting changes zero
files, the incoming-projection pure contract passes 63 assertions, and discovery
finds 179 actual contracts. Initial formatter configuration errors are retained;
the corrected override run changes no tracked source. Discovery and pure controls
are not native suite completion.

Root's complete MariaDB `domain-plugin-import.php` at exact a736 now passes in
**199.725782554 seconds**, within its unchanged 300-second limit. The final
success output and idle/control-cleanup assertions are reached. The earlier 7ae
300.0111-second timeout remains retained; this is the first complete passing
MariaDB run of the corrected source, not a proven isolated elapsed-time effect
of one change. The same disposable MariaDB database repeat also passes in
**211.68482 seconds**, without resetting consumed identity allocations.
PostgreSQL complete Domain import passes in **57.331899 seconds**. The broader
original PostgreSQL selection passes 45 classes/283 methods/11,338 assertions in
284.275600 seconds, with zero void/skipped methods. The broader MariaDB selection also passes 47 classes, 329 methods and
15,399 assertions in 301.7060797s, zero void/skips. PostgreSQL's original queue
class passes one method and ten assertions in 0.98648s, zero void/skips. Both queue selections pass one method and ten assertions with zero void/skips.
The completed a736 full suites pass PostgreSQL 178/179 in 780.832497778 seconds
and MariaDB 151/179 in 1086.705168415 seconds. PostgreSQL's native inspection
passes all 13 canonical versions and schema comparison; MariaDB's inspection
fails on missing DocumentsItems.is_recursive and SLM.use_ticket_calendar Boolean
CHECKs. [The handoff](modernization-handoff.md) records the exact failures and the
new controlled release-map ordering reproduction. Proposed repairs were pending native validation at that capture. The latest
complete portability result is now 741 (180/180 both), superseding the earlier
b66 (168/168 both) local milestone. No new browser/official-engine result is
claimed by these inspection observations.
