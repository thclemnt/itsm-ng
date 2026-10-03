# Boolean domain enforcement: source draft

The reviewed source draft was committed as `b10cfaaeac` from
`e71fc4c8e456cab617798d94d14f6539dfe9431a`, then rebased onto the frozen combined
`4dbc9f7c2edeafb5e54c65d9c9a5144f9132246c` checkpoint and subsequently
`f3db6577c7324e04305c2a8cbbfbb4960ddf7c38`. Both feature patches were replayed
without conflicts or changes (`git range-diff` reports equality), now as
`6dbaa20241` and `6064e5d5bd`. Original head `e02f577d25` remains on
`th/exp/postgres-boolean-domains-before-f3-rebase`. The inherited managed Kanban
metadata, Document_Item preparation/refusal diagnostic controls and
Transfer/native source controls remain byte-identical to f3. The separate cache
bootstrap repair and Session authorization branch have not been composed into
this branch. It has **not** run against
a database, been integrated, or passed the complete portability/application
matrix. The current combined validation checkpoint remains the one in
[the durable modernization record](modernization-handoff.md).

## Existing model and remaining defect

The application already declares 402 boolean fields on their owning Doctrine
properties. Eleven User preferences deliberately permit NULL for inheritance.
PostgreSQL uses native boolean storage. MySQL/MariaDB retain historical integer
storage (the frozen baseline uses SMALLINT for 397 baseline flags; older adopted
installations can retain TINYINT). Category flags and Domain helpdesk visibility
already have native CHECK constraints. Those declarations and older migrations
remain unchanged.

Two gaps were still present: `RecordWriter` cast arbitrary values through
`(bool)(int)`, and most MySQL/MariaDB integer flags admitted raw values such as 2.
Neither ORM boolean hydration nor SQL strict mode enforces the zero/one domain.
The scoped Supplier defense is still necessary for diagnostic legacy data and
must retain its explicit malformed historical fixture.

## Input ownership and historical enforcement

`BooleanValue` accepts boolean values, integers 0/1 and strings "0"/"1". Native
persistence accepts actual NULL only when the mapped property is nullable. Public
application input retains the existing escaping boundary: the unescaped legacy
NULL sentinel is decoded once, whereas an escaped literal remains a string and
fails the boolean domain. Absent fields remain absent. Floats, other integers,
arbitrary strings and compound values are refused without coercion.

CommonDBTM normalizes mapped flags before model preparation, so rejected User
preferences cannot already change SESSION. Public callbacks/audit history retain
zero/one values; native ORM assignment uses booleans. Metadata-scoped lifecycle
comparison distinguishes supplied NULL from explicit false. User-owned
post-success preference handling reloads actual stored values through the
existing ORM-backed User read and effective preference policy. It refreshes only
supplied nullable boolean keys in the existing inherited preference policy,
including accepted no-change updates. A late
refusal cannot already change these SESSION preferences. Absent keys and
nonboolean/language/use_mode preference ordering retain their existing behavior.
The final base update boundary checks only fields selected for actual persistence
after model callbacks. Shared fixed-owner relations delegate to that finalizer;
typed endpoints retain their effective-view reconciliation and use inherited
boolean input normalization without replacing endpoint authorization. The
native RecordWriter independently validates all supplied flags before mutating
any managed property; it retains association normalization, routing and the
public application's lifecycle and history responsibility. No table-name switch
or manually maintained runtime flag registry is introduced.

The appended `20261008_boolean_domains` phase uses an immutable snapshot of the
402 flags, their nullability, constraint names and actual predecessor stages.
Current entity changes cannot rewrite those definitions. Before any of its DDL,
it audits every available historical flag, aggregates invalid counts once per
table, and reports ordered row samples. The canonical coordinator runs this
preflight before broader adoption DDL. A missing later field or a legacy integer
PostgreSQL field is allowed only when its frozen supplying/converting phase is
actually pending. A completed conversion receipt cannot excuse storage drift.
SLM's predecessor creates a missing flag but does not convert an existing integer
flag; that distinction is recorded explicitly.

MySQL/MariaDB add nullable/nonnullable zero/one CHECKs while preserving integer
storage, valid values, defaults and nullability. PostgreSQL verifies native
storage and records the appended receipt. MySQL retry re-inspects committed CHECK
DDL and leaves an incomplete entry in the existing ledger until convergence; no
second migration ledger is added. All invalid-data and conflicting-definition
checks precede that migration's nontransactional DDL.

Current CHECK generation and read-only inspection derive from entity metadata.
Native inspection uses call-local column and constraint snapshots, respects
PostgreSQL visible search-path tables, and distinguishes MariaDB constraints with
the same name on different tables. The bounded expression parser preserves
operator precedence and accepts only the generated exact domain grammar. A
permissive expression is rejected even if its constraint name matches. The
MySQL-family grammar accepts bare/backtick identifiers; double-quoted literal
lookalikes are refused without guessing SQL mode. Actual ANSI_QUOTES catalogue
canonicalization remains a required provider test. General
DBAL schema comparison remains intact.

Supported CHECK enforcement requires **MySQL 8.0.16+ or MariaDB 10.2.22+**; an
older engine refuses installation/upgrades early. CHECK enforcement alone arrived
in MariaDB 10.2.1, but native CHECK_CONSTRAINTS inspection became available in
10.2.22: the official [10.2.21 source](https://raw.githubusercontent.com/MariaDB/server/mariadb-10.2.21/sql/sql_show.cc)
lacks that catalogue and the [10.2.22 source](https://raw.githubusercontent.com/MariaDB/server/mariadb-10.2.22/sql/sql_show.cc)
registers it with TABLE_NAME. The latter capability is necessary to verify the
actual constraints instead of trusting their names. Disabled MariaDB session CHECK
enforcement also refuses migration and schema validation. There is no trigger
fallback or silent expansion of accepted flag values.

## Source checks and pending evidence

Executed source checks: all 19 changed PHP files pass syntax validation and the
repository formatter; `git diff --check` passes. The standalone pure contract
passes **72 assertions**, covering input domains, NULL/escaping, exact CHECK
expression grammar, engine minima, historical nullability and stage dependencies.
An offline frozen-baseline comparison found no baseline/current boolean
nullability differences and opened no database connection. Offline current DDL
generation emits each of the 402 property-derived CHECKs exactly once for MySQL
and MariaDB, and none for PostgreSQL native boolean storage; this also exercised
omitted metadata nullability as the nonnullable Doctrine default. These are source and
pure-unit results, not PostgreSQL/MySQL/MariaDB validation.

These source checks were rerun after the f3 rebase: PHP 8.2.33 lint passes for
all nineteen scoped PHP files, PHP CS Fixer 3.95.27 sequential dry-run reports
no changes, the 72 pure assertions pass, and the offline baseline/nullability
and 402/402/0 CHECK generation controls pass without a connection. The formatter's
initial parallel worker could not bind its local tool socket; its sequential
retry passed without changing source. New evidence is
`/workspace/itsm-env/evidence/boolean-domain-f3-pure.log`,
`boolean-domain-f3-shape-offline.log` and `boolean-domain-f3-source-manifest.json`
in the same directory. Prior source evidence is retained. No database/bootstrap,
dependency copy, asset build or HTTP/browser job ran during this preparation.

The prepared `boolean-domains.php` contract has not run. It exercises real
Supplier/User/Category/SLM/Domain rows through mapped persistence and native DML,
managed-state preservation on refusal, early public User SESSION behavior,
nullable inheritance, explicit historical raw-2 refusal before DDL, committed-DDL
interruption/retry, canonical replay, completed PostgreSQL converter drift,
missing completed Domain supplier flags and permissive CHECK detection. It restores the current schema after its isolated
historical mutations.

Source-only rebase retains final CommonDBTM completeLifecycleUpdate, required
ownership forwarding, model journals, PostgreSQL transaction guards and deferred
notification delivery. The User preference refresh remains in its post-success
hook, after accepted storage/forwarding and during accepted no-change updates;
refusals return before that hook. Supplier commercial scope and ORM flush hooks
remain unchanged. The original Supplier malformed public/native assertions now
have an explicit historical phase after canonical fixture rollback: its CHECK
and receipt changes occur outside data transactions, owned rows roll back, and
finally restores exact schema and receipt. Current native raw-2 and public-input
rejection are separate assertions; all other actual public/REST/native flows
remain on the current schema. No ordinary bootstrap readiness bypass is added.

Next validation must use owned disposable databases after the current resource
hold. Both the rebased implementation and Supplier historical fixture remain
unexecuted. Validate and integrate this enforcement before the pending Session
authorization persistence batch: its entity-declared boolean grant hydration
relies on the canonical zero/one invariant, not a separate Session flag registry.
Execute the prepared
producer-receipt drift cases, then extend nullable NULL
and multi-table invalid-data diagnostics, Oracle MySQL NOT ENFORCED and MariaDB
disabled-session diagnostics, catalogue visibility/duplicate-name cases, and
read-only preview/command status evidence. Then run fresh replay, populated
adoption and interrupted retry, focused original Config/User/ownership/Transfer
application checks, the dynamically discovered full portability suite on both
providers, and final native schema inspection. Existing historical suite fixtures
must retain their assertions and explicitly model their intended history rather
than bypass the new constraints.

## Additional adoption and retry controls (source only)

The follow-up based on `a0f91db45c` extends the existing contracts instead of
repeating a second frozen installation. `migration-history.php` now temporarily
removes the actual ledger table from its populated raw baseline, introduces
integer flags with invalid values in Computer and Supplier rows, and requires
combined per-property counts with ordered samples bounded to five. Both direct
Boolean preflight and actual History adoption must refuse without creating even
an empty ledger, changing the inspected schema/data or widening identifiers.
The fixture restores its prior raw state and receipts before all original
invalid-relationship controls. The existing populated public `db:update` then
starts with no ledger at all and must adopt the valid graph while preserving
the original account, audit, identifier, sentinel and subject-link assertions.
This is distinct from appending to twelve completed receipts.

`boolean-domains.php` additionally removes three CHECKs on different tables,
interrupts after the first committed MySQL-family DDL group, and interrupts
again after the final remaining DDL but before completion. Replanning must omit
already-correct groups; repeated actual History/stage execution must preserve
native definitions, every inspected row and serialized receipts. PostgreSQL
uses native booleans, so its separate completion failure proves outer-history
rollback and retry without pretending a CHECK DDL callback ran. All original
single-table drift, invalid-data, public-input and nullable assertions remain.

All eleven property-declared nullable User flags now receive public
absent/NULL/false/true storage controls. SESSION expectations follow the actual
`user_pref_field` policy instead of silently adding preference declarations in
the fixture. Exactly nine nullable flags belong to that inherited policy.
`compact_mode_ui` is read by `Html::useCompactMode()` from the current User and
its separate `itsm_compact_mode` cache; `access_shortcuts` is read directly from
the User by Ajax/hotkey/ITIL UI callers. Neither field is a Config-inherited
SESSION preference. The earlier contract incorrectly inferred inheritance from
nullability and expected `glpicompact_mode_ui` publication. Its same early/late
refusal, false-to-NULL, accepted no-change refresh and NULL-to-false semantics
now use the actually inherited `is_ids_visible` preference. Config's preference
form and User item listings consume that Config/SESSION policy. Compact/access
native and public NULL/zero/one storage assertions remain, and all eleven storage
transitions plus all nine inherited publication controls remain covered.
Production and the configured preference policy are unchanged.

The new controls have not executed against a provider. PHP syntax, scoped
formatter and whitespace checks are source checks only. Run both contracts on
owned PostgreSQL and MySQL/MariaDB fixtures under the unchanged 300-second
contract limit, inspect restored schema/receipts and retained diagnostics, then
run the full coherent history/application suites. Prior pure/offline results do
not validate the additional adoption, committed-DDL or preference behavior.
