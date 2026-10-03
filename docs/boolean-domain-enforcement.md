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

### Explicit native CHECK quotation follow-up (source-only)

The isolated follow-up starts at `2e15d498db`. Root's actual MariaDB diagnostic
found no schema differences under STRICT_ALL_TABLES alone, but under configured
ANSI_QUOTES the native catalogue serialized all402 flag CHECKs with doublequoted
identifiers. The previous mode-free parser refused those valid clauses. One
additional DBAL default difference is separately under investigation; this parser
change does not suppress or alter general structural schema comparison.

Current native inspection now snapshots the observed SESSION ANSI_QUOTES state
without changing it. Both current schema diagnostics and the appended domain
migration pass that context to the bounded expression parser. The optional
context defaults false: doublequoted string lookalikes remain rejected without
explicit identifier context. Singlequoted literals remain rejected in either
mode, and exact AST/precedence, column matching, nullability and token/length
limits are unchanged. The snapshot reflects the mode that formats the native
catalogue now; it makes no assumption about the mode when the CHECK was created.
Frozen definitions and prior SQL are unchanged.

The pure contract retains its original72 assertions and adds18 quotation,
nullability, escaping and permissive-precedence controls, passing90 assertions.
All4 changed PHP files pass syntax/style checks. An offline parser check also
accepts the4 Supplier/User clauses recorded in root's actual diagnostic, with
their corresponding observed mode. These nonconnecting results are source
evidence only. Native
mode-schema/migration/app-suite reruns remain pending, including the separately
identified general default difference. The subsequent source-only Category inspector follow-up below addresses the
identified incomplete-receipt/ANSI retry inspection boundary.


### Category historical retry inspection follow-up (source-only)

CategoryFlags20261004 retains its original three frozen columns, type/default/
nullability conversion SQL, CHECK declarations, receipt and apply behavior. Only
its read-only existing-CHECK inspection changes: one current SESSION quotation
context and one bounded native snapshot owned by glpi_itilcategories replace
per-column catalogue reads and parenthesis stripping. MariaDB joins include
CHECK_CONSTRAINTS.TABLE_NAME, so same-named checks on another table cannot supply
a clause. The shared exact parser handles ANSI identifiers while refusing a
permissive expression with the same stripped tokens. PostgreSQL inspection and
all frozen historical definitions remain unchanged.

The original category-booleans.php assertions remain. A prepared native phase
captures valid CHECKs, values, receipt and mode outside a data transaction, marks
only the Category receipt incomplete, previews existing ANSI CHECKs without
mutating them, resumes and proves idempotency. It then replaces one captured
CHECK with `(is_incident IS NOT NULL AND is_incident) IN (0,1)`: this actual native
lookalike admits2, yet both preview/apply must refuse after valid data is restored,
without changing the incomplete receipt or CHECK. Finally restores exact original
values, CHECK, mode and receipt. No constraints or assertions are suppressed.
The separately committed User fixture now declares its internal auth SESSION
context inside its existing saved-session/data frame; production auth policy and
rights are untouched.

Pure parser controls now pass92 assertions (all original72 retained). Native
Category retry, rejection and restoration evidence remains pending root's
exclusive provider window. Frozen baseline/history files have no diff, and the
historical SQL literal declarations are retained. Source syntax/style results
are not populated upgrade/retry or configured-mode application validation.

### Historical fixture follow-up at a736

The combined MariaDB suite at `a736d72e03` exposed current CHECKs preempting
historical bad-data tests: `impact-graph.php:192` injects native flag2 into
`glpi_impactitems.is_slave`, and `oidc.php:165` injects flag2 into
`glpi_oidc_users.update`. These are historical audit inputs, whereas the installed
schema now correctly refuses them immediately. The source-only fixture follow-up
retains every prior assertion and adds proof that the invalid scalar is actually
stored before invoking each old migration's audit.

`HistoricalBooleanChecks` is a test-only snapshot helper. Its scope comes from
the tested historical migration's existing frozen FLAGS declaration. It requires
an idle owned disposable connection, a transactional ledger and a completed
current Boolean receipt. It removes only the scoped MySQL-family CHECKs and that
receipt outside data transactions; PostgreSQL retains its native conversion
tests. Finally restores captured native clauses/enforcement and the exact original
receipt bytes, with whole-CHECK-catalogue and receipt equality assertions. Partial
setup also reaches restoration. No session/global SQL mode or CHECK-enforcement
setting is disabled, and no production/frozen definition changes.

Category and Supplier tests already explicitly reconstruct old CHECK/history;
the main migration-history invalid flags use the raw frozen baseline, and plugin
source tables have their own export definitions. Those inputs remain distinct
from current native rejection controls. Separately, the raw Domain adoption
fixture's unrelated plugin omitted its required `version`; strict MySQL refused
that row before adoption. It now supplies and verifies its own `1.0.0` version,
retaining the supported Domains plugin's pinned2.1.0 export unchanged.

Provider execution, configured-mode CHECK round-trip equality, populated Domain
adoption timing and full-suite convergence remain pending for this test-only
follow-up. The evolving root suite logs are failure evidence, not a final passing
checkpoint; unrelated schema/subject and PostgreSQL key failures are separate.
