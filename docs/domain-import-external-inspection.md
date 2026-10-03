# Domains import reuses its live schema inspection

Root's MariaDB `domain-plugin-import.php` run at
`7ae05e10577220d892a4e47467be3a5c510089f9` exceeded the unchanged 300-second
contract limit at 300.0111 seconds. That is a failed run. A previous different
source reached its first interruption control around 213 seconds; neither result
establishes completion of the later retry/preservation controls. The actual
profiler, provider parity and final full-contract timing remain runtime gates.

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

The runtime owner must compare old/new external column names/types and actual
unsupported-row diagnostics on both providers, including external textual and
generated columns with native indexes/foreign keys. Verify unchanged core schema
drift and nontransactional-storage refusal, source-spelling guards, exact retry
and later-edit retention. Then rerun the entire unchanged Domain import contract
within its original 300-second limit and inspect its final native schema. Native
SQL timing must establish any performance effect; the source change alone does
not explain the timeout or prove a full-suite milestone.
