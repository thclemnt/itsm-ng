# Mapped persistence and reporting

Doctrine ORM 3 is an explicit dependency alongside DBAL 4.4+ (PHP 8.2+). The attributes in
`src/Database/Entity` now map every column of all 357 current core tables,
including the dashboard's generated numeric primary key and explicitly assigned IDs elsewhere.
`EntityRegistry` discovers tables, mapped classes, boolean columns and ordinary
FK targets from Doctrine metadata. It contains no hand-maintained declarations.
These are persistence records;
application permissions, validation, hooks, history and notifications remain in
`CommonDBTM` and its subclasses.

Core `CommonDBTM::getFromDB()` reads now use `RecordRepository` and ORM hydration.
Structured `find()`, `getFromDBByCrit()` and simple `getFromDBByRequest()` reads
also compile to DQL through `RecordCriteria`. Parameters use mapped types,
relationship filters use association identities, and ordering, limits and offsets
execute in the database. Nested AND/OR/NOT, comparisons, lists, NULL, bitmasks and
case-insensitive PostgreSQL LIKE filters are covered. Date parameters accept both
mutable and immutable PHP dates. Raw SQL expressions, joins, subqueries, JSON
comparisons and regex predicates retain the explicit legacy path until their
callers have dedicated mapped queries. Database errors never trigger that fallback.
The repository preserves the legacy scalar row interface, including integer flags,
representable bigint values, date strings and JSON values. Plugin tables still use
the legacy path until their mappings are registered.

Audited required relationships use `ManyToOne` associations without cascading
removal. Audited optional references use nullable associations after explicit zero-to-NULL
normalization; remaining optional and polymorphic references remain scalar columns. Booleans, dates, decimals and JSON have explicit Doctrine
types; decimals remain strings to avoid rounding through floating point.

`MappedStorage` now handles insert/update/delete, soft deletion and restoration
for all 357 registered core tables through `RecordWriter`. `CommonDBTM` calls it
below lifecycle processing; direct bulk SQL elsewhere is still pending migration.
The writer supports assigned IDs, generated IDs, the dashboard's alternate key,
JSON, native booleans, UUID values and clock boundaries. Bulk legacy SQL can still
write these tables and the same foreign keys remain authoritative.
Calling `EntityManager::flush()` directly is not an alternative application API:
it would bypass those lifecycle services.

Account maintenance now uses `UserRepository` for duplicate-login checks,
token collisions, partial entity-grant removal, authentication-source changes,
password clearing and synchronization snapshots. `LdapRepository` binds directory
DNs and attribute values through mapped groups; stored LDAP values remain the LIKE
patterns. Group/email additions and removals keep their existing model hooks.
`UserPasswordRepository` handles reset-token ambiguity and expiry, notification
eligibility and bulk account locking through DQL. Date arithmetic uses the database
clock and Doctrine's platform functions; locking also clears persistent-login
tokens atomically. The account contract exercises both providers and caller
savepoints without contacting a directory or delivering external notifications.
`UserSelectionRepository` now handles the remaining email and dropdown queries.
Explicit mapped joins compile the existing nested permission predicates into
DQL, including recursive entity grants and rights masks. User identities are
selected before complete rows, so multiple grants/emails cannot duplicate JSON
records. Search, lifecycle dates and pagination run in the database; count keeps
the existing pre-search eligibility contract. `RowIterator` preserves dropdown
callers' first `next()`, rewind, key and count behavior without a driver result.
`inc/user.class.php` now contains no direct query-adapter calls.

`Auth` and `AuthMail` now read credentials and authentication sources through
mapped repositories as well. Local password lookup selects only the local source
with code zero, binds the decoded login, and computes expiry/lock dates with DQL
date functions. Email existence uses the mapped user-email association without
duplicating account selection. Active LDAP/mail menus, directory synchronization
eligibility and the full configuration used for external authentication all use
mapped reads. Password verification, account locking, rehashing and login rules
remain in their existing application lifecycle. `authentication.php` covers source
isolation, quoted/literal logins, email joins, NULL dates, DST, strict lock boundaries
and adapter-free queries on both providers; the functional `Auth` tests cover the
complete local login and account-lock flow.

Empty group/right lists fail closed. Explicit empty entity arrays no longer
activate the show-all shortcut in `getEntitiesRestrictCriteria`, and change
validation creation uses the declared global `CREATE` permission instead of an
undefined class constant. `user-selection.php` covers these authorization paths,
mixed central/helpdesk grants, name/email search, ambiguity, exclusions, JSON
fan-out and stable pages on both providers.

Mapping configuration and serialized metadata are cached by provider in the
process. Each manager receives isolated metadata objects and a configuration
copy: assigned-ID imports or other mapping changes cannot leak to later managers.
Symfony Cache supplies the PSR-6 pool; its compatible 5.4 line is used because
the existing Laminas Cache dependency requires PSR Cache 1.
Every operation gets a short-lived entity manager on the adapter's existing
DBAL connection. It shares the legacy transaction and uses nested savepoints.
It never retains managed objects across legacy writes. Pre-escaped legacy values
are decoded once by `MappedStorage` and then bound with Doctrine types; new
repositories accept raw values. SQL expressions are not accepted as mapped values.
Explicit IDs remain supported for imports. MariaDB timezone catalog reads use a
separate, short-lived DBAL connection: its system tables can use Aria, whose reads
inside the application transaction prevent subsequent savepoints.

`AssetRepository` counts the eight mapped asset types with DQL, and
`ReservationRepository` reads reservations through their mapped item association.
The callers supply the active entity scope: `null` means all authorized entities,
whereas an empty list returns nothing. Report entry points still perform their
existing rights checks. Plugin asset counts retain the query-iterator path.

## Canonical installation and adoption history

Fresh installations replay four frozen phases in order through the existing
`itsmng_migrations` ledger:

1. `20261001_baseline_legacy_2_2`: explicit DBAL declarations for the 355-table
   historical schema, independent of current entity metadata.
2. `20261001_baseline_seed`: frozen raw seed rows, before reference normalization.
   Root names may be localized and each installation gets a random dashboard
   token; all other seed definitions are frozen. Seed DML and completion are atomic.
3. `20261001_legacy_to_orm_bigint`: the existing adoption migration, including
   identifier widening, all ordered domain conversions and foreign keys.
4. `20261002_postgres_boolean_flags`: validated adoption of early PostgreSQL
   integer flags; a recorded no-op for MySQL/MariaDB native flag storage.

The dashboard baseline retains the SQL dump's inline unique ID key, which the
former reader discarded. That key makes the old MySQL AUTO_INCREMENT declaration
valid before dashboard ownership is upgraded. Duplicate queued-notification
definitions are already resolved in the frozen snapshot. Provider-specific
expressions, indexes and triggers are explicit historical definitions too.

CLI and web installation run this complete history from an empty database, rather
than creating today's entity schema and marking upgrades complete. MySQL journals
each table creation and verifies an already-created table after a process dies
between DDL and its checkpoint. Conflicting definitions refuse retry. PostgreSQL
rolls back installation schema, data and ledger together. Sequences synchronize
before and after adoption. New schema changes need a new historical migration.

Existing legacy 2.2 installations and partially converted ORM databases use
`db:migrate` (the `db:legacy_to_orm` alias remains) to preview or adopt the same
history without reinserting default accounts or seed data:

```sh
php bin/console db:migrate --config-dir=/path/to/config
php bin/console db:migrate --config-dir=/path/to/config --apply
```

The first invocation audits and previews without writing. Apply during maintenance
with application writers stopped. All previous conversion steps, uniqueness rules,
CHECK constraints and audited foreign keys run in their existing dependency order.
The former individual conversion and foreign-key commands have been removed;
their domain helpers remain internal implementation details of the master.
CLI and web fresh installation replay the frozen raw seeds before calling the same master. Validated existing installations record baseline/seed phases as adopted with preserved data; they do not claim those default rows were inserted. Canonical completion also checks the resulting required schema.

Primary IDs, owning association columns, scalar and polymorphic reference IDs use
`BIGINT` in both the installer and ORM metadata. Counters, enum codes, rights masks,
positions and unrelated numeric values are not promoted to `BIGINT`. MySQL's
legacy tinyint/text/float storage is adopted to the frozen installer's wider
SMALLINT/LONGTEXT/DOUBLE declarations, preserving values. Column Unicode overrides
and quoted comments are preserved in the baseline as well. The frozen
scope covers 1,228 identity/reference columns, including removed legacy columns
needed during conversion. Generated identity columns also use `BIGINT`, and
PostgreSQL sequences are widened without resetting their next values. PHP must
use 64-bit integers. MySQL retains each column's existing signedness and sentinels
until its established normalization step; root entity zero remains a real ID.

Widening preserves existing indexes and foreign-key definitions, including actual
FK references from plugin/custom tables. These constrained plugin references are
widened together with their core targets; unconstrained plugin fields require the
plugin's own upgrade. No relationship is invented from an ID-like column name.
The master uses frozen ID and FK definitions in
`src/Database/Migration/history/20261001-legacy-to-orm.json` and the frozen internal
step order in `20261001-stages.json`.

`itsmng_migrations` stores each canonical phase and retains the existing master
completion/journal record in its original format. There is no second ledger. PostgreSQL runs the whole upgrade
transactionally. MySQL stores the widening operations before removing constraints,
checkpoints each successful DDL operation, and resumes that journal after an
interruption. Existing idempotent conversion steps can then be replayed. Completion
is recorded only after the final orphan audit, FK enforcement and ID type check.
The atomic PostgreSQL upgrade locks many tables, indexes and sequences. A default
relation-lock budget can be insufficient; the populated upgrade validation uses
`max_locks_per_transaction=512`. Size this server setting for the installation
before applying; changing it requires a PostgreSQL restart. Exhaustion rolls back
the migration and reports the setting to adjust.
A completed rerun does no work; use `db:check` to diagnose later schema drift.

Validation on 2026-10-01: MariaDB passes all 116 portability contracts. The
PostgreSQL suite and corrected fixture/schema/master reruns also pass. Populated
raw legacy upgrades preserve audit data on both engines and converge to the
current core schema. Dedicated contracts cover interrupted DDL replay, generated
keys and FK supporting indexes, plugin references, sequence widening, required
orphan rejection and ORM log IDs above the unsigned 32-bit limit. These are local
PHP 8.5 checks, not remote CI or production-sized log-table benchmarks.

Back up the database before applying a production upgrade: `BIGINT` increases
storage for each widened column and index and ALTER operations can rebuild large
tables such as logs. This migration has no narrowing downgrade.

## Schema ownership

The mappings can generate a scoped schema model with Doctrine `SchemaTool`, and
tests check mapping validity and exact column coverage against the baseline.
Canonical frozen history owns the complete 357-table schema, including
historical indexes, provider-specific indexes/triggers and seeding.
`BaselineSchema` is a current read-only inspection projection, not the installation
entry point. Current entity metadata supplies application types and ownership;
immutable historical declarations remain legitimate snapshots. Never apply
`SchemaTool::updateSchema()` or `schema:update --force` to an installation: plugin
tables, legacy indexes and provider-specific definitions are not represented by
these entity mappings. Moving schema ownership requires reviewed, versioned
migrations, including handling of optional zero references.

`php bin/console db:check` now compares the required core DBAL model against the
connected database on either provider. It reports drift and exits nonzero without
executing repair SQL. Extra tables and indexes are allowed for plugins and local
tuning; equivalent index names are accepted. Native MySQL `TIMESTAMP` is checked
separately because DBAL introspects it as the same type as `DATETIME`. Custom
expressions, triggers and CHECK constraints are outside this command's comparison.

## Next architecture work

Table/class, boolean and owning-association target discovery now use entity-local
Doctrine declarations. The former `BooleanColumns`, table/class constant and
FK-target catalogue have been removed. Nullable associations retain their explicit
Doctrine join-column metadata; this does not infer legacy sentinel semantics.
`ReferencePolicy` attributes now live beside owning associations. Optional empty
selections, real root entity zero, unrestricted audiences, global scopes and
inherited entity settings are explicit. Targets come only from Doctrine's
association metadata. User history reassignment is declared on the affected
properties. The detached optional, ownership and scope catalogues and inherited
field definitions have been removed. Legacy conversion and criteria compilation
read these declarations; nullable columns alone never imply sentinel semantics.

Historical upgrade inputs are frozen in
`src/Database/Migration/history/20260930-reference-upgrades.json`; migrations
and migration fixtures read this snapshot independently of current entities.
This preserves existing upgrade behavior when the runtime model evolves.

Mapping configuration and serialized metadata are shared across short-lived
managers. The remaining architecture work is:

- Organize entities and repositories by domain as they are converted.
  Keep database transport, schema operations and mapping infrastructure separate.

The master migration and completion ledger replace `Toolbox::createSchema()`'s
list of independently invoked helpers. A future baseline change should replace
`BaselineSchema`'s runtime SQL reader.
Define a frozen baseline using DBAL `Schema`/`Table` APIs, then run the complete
migration history for every new CLI or web installation. Freeze migration data
and relationship definitions within history; historical migrations must not import
current entities or mutable registries. Required seed rows should use frozen DBAL
data operations, independent of the current ORM model.

Preserve indexes, native timestamp behavior, generated columns, constraints,
full-text/prefix indexes and triggers explicitly. Current entity metadata omits
many baseline secondary indexes, so generating a baseline from ORM metadata alone
would lose them. Existing installations need a validated adoption/upgrade path;
they must not replay CREATE statements over their data. Verify empty-to-latest and
supported-old-to-latest paths converge in schema and data, then test no-op reruns,
interrupted migration retries and invalid-data rejection on every supported engine.

The portability workflow discovers all CLI contracts with
`python3 tests/database-portability/suite.py tests/config`. It runs contracts
sequentially because some temporarily change the schema. Keep web fixtures,
benchmarks and the independently invoked SQL inventory test outside that runner.

## Reporting behavior

- Default asset counts do not multiply assets linked to several computers. OS
  counts include only active, non-template computers in the active entities.
- Network reports aggregate each endpoint's addresses independently, ordered and
  deduplicated. Both the selected port and its opposite endpoint obey entity scope;
  a hidden or deleted opposite endpoint is omitted.
- Year filters use date ranges. Financial filters require either purchase or
  startup date to fall inside the entire requested interval. Consumable entity
  restrictions use the parent item table.
- Reservation reports use typed DQL and entity scope for both past and future rows.
- Both financial reports use `FinancialRepository` for core types. DQL joins mapped
  assets and entities, applies typed inclusive date bounds, and scopes consumables
  and cartridges through their parent. Plugin types retain the iterator path.
- Calendar segment queries now use `CalendarRepository` and DQL. Interval
  calculations operate on clock boundaries in PHP, avoiding MySQL `TIMEDIFF`.
  The `itsm_clock_time` Doctrine type preserves `24:00:00` instead of normalizing
  it to midnight. Holiday purge removes calendar associations unless a replacement
  holiday was requested.
- Monthly ticket statistics use platform date formatting and group-compatible
  ordering. The common date-range helper delegates arithmetic to the platform.
- The reservation user selector filters by matching IDs before fetching user
  records. This avoids comparing PostgreSQL JSON values and counts each user once
  even when several emails or profiles match.

Run `tests/database-portability/orm.php` and `reporting.php` against a disposable
`itsm_port_*` installation. They use transactions and cover populated results,
typed writes, JSON, shared transactions, all new parent purge paths, entity scope,
financial boundaries, address aggregation and twelve monthly statistics measures.
`web-reports.py` checks the actual report routes with login and CSRF tokens, and
checks SQL and fatal PHP logs. It requires an isolated, installed test web server
with the seeded `itsm` account; it does not install a database.

## Full conversion remains in progress

Run `php tools/database/audit-coverage.php` for the remaining table writes,
relationship candidates, polymorphic references and legacy SQL/driver call sites.
This is an intentionally incomplete static inventory: it cannot prove discovery of
serialized references, dynamic SQL or alternate connection variables. Each
candidate needs semantic review before installing its FK. The current inventory
contains 1,068 candidate reference columns: 1,003 enforced, 23 discriminated
identities with FK-backed branches, 41 polymorphic and one pending
(`events.items_id`). All 357 core tables have mapped lifecycle persistence.
Token discovery finds 2,896 legacy adapter calls, including 2,491 in installation
and historical upgrade scripts, and 23 native driver calls in `DBpgsql`.
These counts describe static coverage, not end-to-end conversion completeness.

The former dump-to-entity scaffold has been removed: rebuilding entities from
the old SQL dump would overwrite local types, enums and domain policies.
`orm-records.php` compares typed ORM records with native rows from each core table
(up to 25 seeded rows per table); empty tables only have metadata/query coverage.
The full conversion is not complete: direct SQL, the native adapters, optional
sentinel references and polymorphic schemas still need migration. Complete entity
mappings are necessary infrastructure, not evidence that every query uses ORM.

## Consolidated revision validation (2026-10-02)

Fresh PostgreSQL and MariaDB installations pass all 127 portability contracts,
including the final schema-check contract. The infrastructure upgrade fixture now
retains and checks the legacy identity-column comment when rebuilding its tables.

Final lifecycle validation exposed and corrected two application regressions:
unqualified filters belonging to a uniquely mapped joined table now retain their
scope (ambiguous joined columns are rejected), and ticket merges retarget both
follow-up and document owning associations when copying their legacy rows.
Fixtures now resolve supplier IDs, identify the correct template parent type and
distinguish internal ORM tables from standalone legacy models.

After these final repairs, ten affected portability contracts pass again on each
provider, including public ticket merges, membership authorization and schema
comparison. The PHP 8.3 MariaDB lifecycle/API run passes all 178 methods with
10,528 assertions. The full portability matrices were not restarted after these
scoped repairs. Syntax, formatting, Composer, migration JSON and SQL inventory
checks pass. These are local CLI/database results; remote CI, browser/E2E and
replicated deployments are not verified by this batch.

The following checkpoints are historical validation, not current scope or a
claim that conversion is complete.

## Historical checkpoints

The individual commands mentioned below were used at earlier implementation
checkpoints and have been superseded by `db:legacy_to_orm`. Their validation
results describe those revisions.

Historical regression evidence: the full-mapping stage passes the PHP 8.3 Calendar
and CommonDBTM suites (26 methods, 775 assertions). After enabling the additional
calendar/rule/network writes, Calendar, Rule, RuleTicket, RuleCriteria and
NetworkPort pass 2,520 assertions (83 executed methods and one pre-existing void
method). Both database providers pass the new FK, mapped lifecycle, end-of-day
boundary, record parity, application and reporting contracts. This does not prove
compatibility of every legacy dataset or every remaining SQL call site.

The expanded write stage adds `orm-writes.php` to the provider CI matrix. It
inserts and reads every mapped table, updates a scalar field on the 344 tables
that have one, deletes records in dependency order, and rolls back the fixtures.
This is persistence coverage, not a substitute for application lifecycle tests.
The 16 additional constraints cover network/VLAN/IP associations, cartridge
compatibility and stock, consumable stock, task/ticket links, linked tickets,
notification targets and template translations. Their parent purge hooks are
exercised on both databases with the actual constraints enabled.

With all 68 constraints enabled, the PHP 8.3 CommonDBTM, User, Ticket, Calendar,
Contract and Profile suites pass 103 methods and 5,206 assertions. Both database
providers pass explicit-ID/import sequencing and raw-value preservation checks.

The criteria-read stage adds 28 constraints for knowledge-base, reminder and RSS
sharing, saved-search user preferences, and knowledge-base/reminder translations.
These are mapped associations with restrictive foreign keys; actual parent purge
hooks are exercised for content and recipients on both engines. `orm-criteria.php`
compares structured predicate results with the existing query API and verifies
that supported model reads bypass the legacy SQL adapter. The all-table write
contract now exercises 341 tables with mutable scalar fields, with the remaining
tables covered by insert/read/delete and association lifecycle tests.

Knowledge-base comments, revisions and linked-item records now have article
foreign keys. The nullable parent-comment reference is a mapped self association
with its own FK. Deleting a comment moves its direct replies to that comment's
parent (or the root), preserving other authors' replies without leaving orphans.
`KnowledgeBaseRepository` reads a comment tree in one query and assembles it in
memory, detects reachable ancestry cycles, and applies revision pagination in the
database. Revision numbers, tab counts, translation-language lists, FAQ publication
and atomic view increments also use mapped queries. Existing access checks remain
in the model. Article search/visibility SQL is still pending conversion.

`knowledgebase.php` checks these behaviors on both engines. The FK contract builds
valid dependency graphs before attempting each invalid reference, and catches
only errors from the intended mutation; fixture failures cannot count as evidence
of FK enforcement. All 100 FK checks pass, alongside all-table ORM writes and
parent purge contracts (572 PostgreSQL / 174 MariaDB database assertions).
Fresh installs pass on both providers, and the PHP 8.3 knowledge-base model suites
pass 20 methods with 404 assertions under FK enforcement.

Shared lifecycle cleanup now selects mapped IDs before invoking each model's
update/delete hooks. This covers criterion-based deletion, child/relation purges,
reference reassignment and entity forwarding without hydrating full rows just to
read their IDs. It deliberately snapshots the selection before hooks mutate it.
History cleanup uses a scoped DQL delete; ordinary model deletion still invokes
its lifecycle. Simple single-table `countElementsInTable()` calls also use typed
ORM predicates; joins, aggregate options, raw expressions and unmapped plugin
tables retain their existing path.

Eleven more constraints cover the nine ticket/change/problem template field
relationships and both endpoints of notification-template links. Template purge
now removes hidden, mandatory and predefined fields through their model hooks.
Removing an item-type field removes all paired item-ID fields for that template,
using the correct parent key and search-option table. Template field lists and
rendering reads use ORM. `cleanup.php` checks paired-field removal, template
isolation, history-owner isolation and execution through ORM on both engines.
The full FK contracts pass 583 PostgreSQL / 185 MariaDB assertions at this stage.
The PHP 8.3 DbUtils, CommonDBTM, ITILTemplate, ChangeTemplatePredefinedField,
Notification, NotificationTemplate, Notification_NotificationTemplate, User,
Ticket, Calendar and Log suites pass 153 methods with 12,430 assertions.

Nineteen optional model references (component models, enclosure, rack, PDU and
passive equipment models) now use nullable mapped associations and real FKs.
`OptionalReferences` explicitly lists where zero means an empty dropdown choice;
it never includes root entity zero or infers optionality from column names.
`MappedStorage` converts legacy empty choices to NULL before binding. Structured
legacy equality, inequality and list predicates retain their empty-selection
meaning; raw ORM queries use NULL directly. Direct SQL writers must also use NULL.
Model purge clears these references through the existing model update hooks, or
assigns the requested replacement model.

For existing installations, `db:optional_references` audits the named, idempotent
`20260928_optional_model_references` migration without changing data. During
maintenance, run it with `--apply` before `db:foreign_keys --apply`. It validates
every audited column, refuses nonzero orphans and a real model with ID zero,
then transactionally normalizes only empty references. It changes no schema and
never deletes records. Fresh installation normalizes its seed data before adding
FKs. `optional-references.php` checks all 19 model lifecycles, empty-selection
filters, replacement and purge, real Rack searches, and migration refusal/retry.
The full contracts pass 602 PostgreSQL / 204 MariaDB assertions and fresh installs
pass on both engines. Component, rack, CommonDBTM and DbUtils suites pass 52 methods
with 6,899 assertions on PHP 8.3. `CommonDevice` now uses mapped reads throughout,
including an asset-scope check that examines every linked ID instead of comparing
against a comma-separated SQL aggregate.

Seventeen component-to-device endpoints now use required mapped associations and
restrictive foreign keys (147 constraints total). Purging a device removes its
assigned, deleted and stock component links through their model hooks; replacing
a device reassigns those links through the existing replacement lifecycle.
The polymorphic asset endpoint remains scalar because returning a component to
stock deliberately clears its item type and sets its asset ID to zero.

`ComponentRepository` handles device listings and atomic stock detachment with
DQL. Listings join the concrete mapped asset type when applying active-entity
scope, exclude deleted links, and distinguish empty scope from all-entity scope.
Asset listings, component lookup, cloning and document lookup use mapped model
reads. Custom plugin listing criteria and unmapped plugin tables retain their
extension path. `components.php` exercises all 17 associations, entity filtering,
replacement, purge, cloning and stock handling on both providers, and checks that
core reads and stock detachment bypass legacy query execution. CI runs this suite
for both databases.
The full FK contracts pass 619 PostgreSQL / 221 MariaDB assertions. All-table
mapped writes, application flows, reporting and search regressions also pass.
The existing SIM-card tests now load their named device fixture instead of
constructing an empty device object with an invalid ID.

Project costs, project teams, task teams, asset links and ITIL links now have
required parent associations. Project ancestry, task ancestry and a task's direct
project are nullable mapped associations. Subtasks can still belong only to a
parent task, without a direct project; clearing a parent uses NULL at storage.
Together these add eight FKs, bringing the audited total to 155.

For an existing installation, run `db:project_hierarchy` to inspect the
`20260928_nullable_project_hierarchy` plan. Stop writers during maintenance, apply
it with `--apply`, then run `db:foreign_keys --apply`. The migration checks all
three relationships for nonzero orphans and real parent records with ID zero
before changing any column. DBAL changes only those columns' nullability and
defaults, then normalizes zero references. PostgreSQL applies schema and data in
one transaction; MySQL schema statements commit separately, and rerunning the
migration resumes from the actual schema. Fresh installers use the same nullable
definitions and normalization. The earlier optional-model migration is unchanged.

`ProjectRepository` uses DQL for task/project durations, linked ticket IDs and
progress calculations. Project duration uses two aggregates regardless of task
count. Each task's own duration is counted once, while a ticket linked to several
tasks contributes once per association, preserving existing behavior. Progress
weights every direct task and active subproject equally and rounds consistently
on both engines; empty aggregates produce zero. The model still performs updates
so progress propagation, history and notifications retain their hooks.

Project/task lists, Gantt root/subtask selection, team lists, group-member planning
checks, cost lists/latest cost and clone reads now use ORM. Project cost and both
team classes contain no direct database requests. `projects.php` verifies these
queries, optional ancestry, purge behavior and old-schema migration refusal and
idempotence. Existing PHP 8.3 project suites pass 11 methods with 379 assertions;
fresh installations pass on PostgreSQL and MariaDB. Project planning, Kanban and
some ITIL-link rendering queries still need dedicated mapped repositories.

Shared descendant traversal now selects mapped IDs, including nullable project
roots, while retaining entity zero's real-root meaning and existing tree caches.
The DbUtils/project regression run passes 29 methods with 6,055 assertions.
Full FK contracts pass 627 PostgreSQL / 229 MariaDB assertions, with all-table
ORM writes, optional-reference migrations, reporting, search and application
workflows passing on fresh installations. The current static inventory has
607 pending relationship candidates, 62 polymorphic candidates, one ambiguous
candidate and 1,537 legacy call sites; complete conversion remains unfinished.

Kanban project selection now uses `ProjectRepository::visibleProjects()` with
mapped entity predicates and correlated team-membership checks. Matching both a
user and a group produces one project card. Active and inactive selectors share
the same visibility rules; retaining the current inactive selection does not
bypass entity or actor restrictions. Direct board access applies those same
rules, and an empty global selection never falls back to unassigned tasks.
Team-member projections select only the display fields. Checklist rows are loaded
in a batch across the displayed cards instead of once per card.

`ProjectTaskRepository` supplies translated task listings with mapped state, type
and parent joins. Joined-column ordering is explicit, missing translations retain
the original label fallback, and an empty listing shows the existing empty-state
message. Effective durations are aggregated in one query for the displayed tasks.
Calendar export selects the mapped tasks through their team associations and
preserves the task identifier when relation identifiers differ.

`project-views.php` covers user/group ownership and membership, recursive entity
scope, inactive/current selections, direct and global boards, empty visibility,
checklist ownership, restricted team projections, translation fallback, joined
sorting, duration aggregation and calendar export. Both database jobs run it in
CI. The Project model no longer executes direct SELECT requests; its search-option
SQL expression and the task planning query remain pending conversion.

Project planning now uses mapped task/team/profile queries and Doctrine date
arithmetic for both planned overlap and creation-date/duration windows. Explicit
groups retain precedence over a user selector; an empty “mine” group selection
returns no events. The default selector includes central-profile users in the
active entity or a recursive ancestor profile assignment. Membership filtering
uses EXISTS, so several matching teams cannot duplicate an event.

Unplanned tasks now retain actor and completion filters instead of replacing them
with date predicates. The event formatter still clips bounds to the requested
window and evaluates editability through the existing model. Group event keys
contain their IDs rather than PHP's array-to-string result. `ProjectTask` no longer
contains direct adapter SQL requests or native date SQL expressions.

Group membership hooks update saved calendar subscriptions through
`PlanningRepository`. It locks subscriber rows inside the shared transaction,
checks the actual subscription key, preserves unrelated settings, and binds the
resulting JSON through DQL. A failure rolls back the whole subscription batch;
only the current subscriber's session is updated after that operation succeeds.
PostgreSQL's adapter now reports DBAL's transaction state, avoiding a transient
native-driver status after savepoint rollback. The transport contract explicitly
checks nested rollback and preservation of outer writes on both providers.

`project-planning.php` covers inclusive bounds, clipping, unplanned windows,
completion filters, user/group/profile scopes, duplicate memberships, subscription
add/remove hooks, malformed/lookalike settings and rollback. CI runs the suite on
both engines. The existing PHP 8.3 ProjectTask, Project, Group_User and Planning
suites pass 10 methods with 436 assertions.

### Software inventory associations and merge

Software versions and licenses now own required `ManyToOne` software references;
installation and license-assignment records own required version/license
references. These four audited, restrictive foreign keys bring enforcement to
159 relationships. Existing installations use `db:foreign_keys --apply` after
reviewing its orphan audit. No missing parent or zero sentinel is silently fixed.
Software purge now forces license purge, including already trashed licenses, so
assignment cleanup hooks run before the parent disappears.

`SoftwareRepository` supplies version/status listings, license quantities and
asset-flag synchronization. License totals preserve entity/recursive scope,
template exclusion and unlimited-license precedence in one aggregate query.
Boolean asset flags use Doctrine boolean parameters on both providers. Financial
license reports join the mapped software association.

Software merge locks the selected software rows in ID order and moves versions,
installations and licenses within the shared transaction. A deterministic first
matching destination version is used. Existing destination installations retain
their metadata when the unique installation key overlaps; non-overlapping links
retain their own metadata as they move. NULL and literal `NULL` version names are
distinct. Source trash hooks run after reassignment, and a failed hook rolls back
the relationship changes and earlier lifecycle writes. Self-selection and empty
merges are harmless. Database rollback does not undo external plugin side effects.

`software.php` exercises quantities and scope, mapped/public version lists,
boolean flags, collision handling, metadata preservation, empty/self merges,
rollback inside a caller transaction and parent purge on both engines. The
existing PHP 8.3 software and dictionary suites pass 45 methods / 584 assertions.
Full mapped writes (355 tables), reporting, search and application contracts also
pass on PostgreSQL and MariaDB. The coverage inventory still reports 603 pending
relationship candidates, 62 polymorphic references, one ambiguous reference and
1,513 legacy SQL call sites; these counts are a migration backlog, not a claim of
complete ORM conversion.

### Software installation counts and entity reports

`SoftwareInstallationRepository` resolves installation types through mapped
version/license associations, then joins each concrete asset mapping. Counts use
the asset's current deleted/template flags rather than cached installation flags,
exclude removed links and missing polymorphic targets, and retain the existing
entity/recursive scope rules. Multiple versions or licenses assigned to one asset
remain separate installations. The explicit unrestricted license-count mode
still applies deleted/template filtering. Unmapped plugin asset types retain the
existing count query until those plugins supply mappings; this is not complete
elimination of the compatibility path.

The license assignment report now groups by the asset's actual entity. Previously
it selected report rows by the license's entity, omitting assignments in other
entities. Core grouped counts require one query per asset type and are filtered
by the active entity scope. Software license selectors, merge candidates and
dictionary restoration lookups also use ORM queries; `Software` no longer has
direct adapter queries. Dictionary names retain the pre-escaped input contract,
while merge candidate names bind stored values without manual escaping.

`software-installations.php` covers all six core asset types, entity scopes,
removed links, real versus cached asset flags, multiple installations, missing
polymorphic targets, selectors, escaped dictionary names and cross-entity report
rendering. The suite, software lifecycle and reporting contracts pass on both
providers. The static inventory is now 1,503 remaining legacy SQL call sites;
foreign-key coverage remains 159 enforced relationships.

### Infrastructure relationships

Twelve additional required associations now have restrictive database FKs and
explicit `ManyToOne` mappings: appliance membership and its nested relations,
certificate assignments, domain records and assignments, cluster/enclosure/rack
contents, and both parent columns on PDU-plug and PDU-rack relations. Parent
cleanup remains hook-driven, including nested appliance relations and the
`_linked_purge` marker required when deleting a domain's records. Unrelated
assignments survive parent purge. Constraint installation audits existing data
and does not invent parents or silently discard orphan rows.

Assignment lists for appliances, clusters, enclosures, racks and PDU plugs now
use mapped reads. Enclosure occupancy, nested appliance labels and PDU side/used
queries also use ORM; the public PDU methods retain a countable iterator result.
`DomainRepository` joins record types for the domain record view, ordering by
type/name/ID with explicit NULL placement so the two providers agree. Domain
purge snapshots mapped record IDs before invoking lifecycle hooks.

`infrastructure.php` exercises all twelve parent purge paths, nested cleanup,
unrelated-row preservation, record ordering, public assignment views, enclosure
occupancy and single/multiple PDU side selection on both providers. All 355-table
ORM write checks, reporting, search and application contracts pass after upgrade.
The existing PHP 8.3 infrastructure suites pass 21 methods / 606 assertions.
Fresh PostgreSQL and MariaDB installations also succeed. Coverage is now 171
foreign keys, with 591 pending relationship candidates, 62 polymorphic references,
one ambiguous reference and 1,492 remaining legacy SQL call sites. Optional
infrastructure dropdowns and polymorphic asset targets still need further work.

### Physical placement queries

`PlacementRepository` provides rack, enclosure, cluster and PDU exclusion sets
through scalar ORM projections. Physical exclusions remain global across entities;
otherwise an asset already placed elsewhere could become selectable again. Rack
selection reads its assignments once, including typed reservation flags, then
combines enclosure and side-PDU IDs. Enclosure selection retains its exception
for reserved rack positions. Cluster membership stays independent of physical
placement. Repeated IDs are deduplicated without merging different asset types.

Room rack lists, rack occupancy/statistics and PDU summaries now use mapped
records. `Rack`, `Item_Rack`, `Item_Enclosure`, `Item_Cluster` and `PDU_Rack` no
longer execute direct adapter queries. Geometry, weight/power calculations and
parent access checks retain their existing behavior.

The rack edit form now passes a class name and the rack's entity criteria to its
asset selector, fixing an object-versus-string error and a missing assignment
entity field. Its reservation checkbox has an explicit change handler and updates
the actual exclusion input; reserved edits initialize the reserved exclusion set.

`placements.php` covers global exclusions, free assets, reservation differences,
both PDU placement modes, two-unit/full-depth and half-width/rear geometry,
current-asset exclusion, weight/power totals, scoped room/PDU rendering and the
normal/reserved edit form's rendered inputs. These checks and the infrastructure
suite pass on PostgreSQL and MariaDB. PHP 8.3 rack/cluster suites pass 204 assertions.
The form checks inspect rendered HTML; they are not a live browser interaction
test. The inventory now reports 1,478 remaining legacy SQL call sites, with FK
coverage unchanged at 171 relationships.

### Optional infrastructure references

Eleven additional relationships now use nullable `ManyToOne` mappings and
restrictive foreign keys: appliance type/environment, certificate type, cluster
type, domain type, record type, domain assignment relation, rack type/room,
room datacenter and PDU type. Legacy empty selections become NULL at the mapped
write boundary. Empty-selection predicates and search criteria retain their
existing meaning. Parent replacement reassigns children; parent purge clears
optional references without deleting the children.

Existing installations must run `php bin/console db:infrastructure_references`
to review the migration, stop application writers, then run it with `--apply`
before `php bin/console db:foreign_keys --apply`. The migration audits every
reference before changing any schema, refusing nonzero orphans and real parents
with ID zero. It changes nullability/defaults and normalizes zero references.
PostgreSQL applies it transactionally; MySQL DDL commits separately and retries
are idempotent. Fresh installations perform this normalization before FK creation.
Project ancestry and infrastructure migrations share the same audited migration
implementation.

The legacy relation registry now includes appliance environments and domain
assignment relations so parent lifecycle hooks clear those references. Domain
relation purge correctly protects the two built-in relations. Rack room-only
updates retain the existing position for collision checks. Datacenter room lists
and room occupancy now use ORM reads, and domain records join their mapped type
association. `DCRoom` no longer executes direct adapter queries.

`infrastructure-optional.php` covers all eleven references, empty selections,
replacement/purge, built-in relation protection, room search/rendering, migration
refusal before DDL, legacy normalization and idempotent retries. ORM metadata
checks also compare every association with the FK registry and verify that
merging optional-reference groups preserves existing relationships.

Fresh installs and upgrades pass on PostgreSQL and MariaDB. Both providers pass
the portability contract, mapped writes across all 355 tables, infrastructure,
placement, project migration, reporting, search and application checks. The
existing PHP 8.3 infrastructure suites pass 21 methods / 612 assertions; their
certificate fixtures now create a real type instead of an invalid random ID.
Rendered view checks are not live browser interaction tests. Coverage is now
182 foreign keys, with 580 pending relationship candidates, 62 polymorphic
references, one ambiguous reference and 1,476 remaining legacy SQL call sites.

### Asset classification and computer connections

Computer, monitor, printer, phone, peripheral and network equipment model/type
columns now have twelve nullable `ManyToOne` associations and restrictive foreign
keys. Their empty dropdown selections normalize to NULL, while replacements and
parent purge retain the assets. Existing installations can review
`php bin/console db:asset_classification`, stop application writers, apply it with
`--apply`, then run `php bin/console db:foreign_keys --apply`. It uses the shared
audited nullable-reference migration and refuses nonzero orphans before any DDL.
The installer applies this normalization for fresh databases as well.

The computer parent of `glpi_computers_items` now has a required association and
foreign key. Computer purge removes its connections through lifecycle hooks,
preserving attached assets and other computers' connections. The polymorphic
target remains a discriminator/ID pair pending a separate schema redesign.
`AssetRepository::linkedItems()` replaces the five duplicated adapter queries
with scalar ORM projections. It retains deleted/locked connections, distinguishes
target types with overlapping IDs and deduplicates repeated connections.

Existing asset tests exposed thirty nullable entity fields whose initial values
incorrectly ignored non-null schema defaults. Their typed defaults and the mapping
generator now preserve those defaults, including asset cost totals, user
preferences, LDAP login fields and network metadata. Explicit NULL inserts and
updates still store NULL. Existing stored NULL values are not rewritten. Mapping
tests compare defaults against the baseline; all-table write tests check stored
defaults and explicit NULL updates. The generator was also exercised in an
isolated directory and reproduced all thirty defaults.

`asset-classification.php` tests all twelve replacement/purge paths, empty
criteria, both connection directions, inherited computer lookup, duplicate and
deleted connections, parent purge, orphan refusal and migration retries. Fresh
installs and upgrades succeed on PostgreSQL and MariaDB. Both providers pass the
195-FK contract, complete mapping and all-table write checks, reporting, search,
application and placement suites. The PHP 8.3 asset suites pass 17 methods / 509
assertions. Coverage now stands at 195 foreign keys, 567 pending relationship
candidates, 62 polymorphic references, one ambiguous reference and 1,471 remaining
legacy SQL call sites; complete conversion is still in progress.

### Asset propagation and connection visibility

Computer update propagation now snapshots connected IDs through ORM, deduplicates
them and skips missing polymorphic targets. It still excludes global assets and
deleted connections. Device associations receive only state/location changes;
the original change set remains intact for lifecycle messages and cannot retain
another item's ID. Core device selectors use mapped reads, with the shared
explicit fallback retained for unmapped plugin tables.

`NetworkConnectionRepository` joins both mapped cable endpoints and returns
distinct peer IDs by asset type in both orientations. Printer and network
equipment recursion checks use these IDs to verify entity visibility. Shared
computer/peripheral recursion checks reuse `AssetRepository::linkedItems()`.
This removes MySQL-only aggregation and fixes checks that treated comma-separated
IDs as one value or selected an arbitrary connected computer. Existing deleted
connections and deleted peers still participate in these visibility checks.

Printer import/restore lookups now use bounded ORM reads, retaining pre-escaped
input handling. The ancestor lookup calls `getTable()` correctly and preserves
entity/recursive visibility, allowing reuse of a recursive ancestor while
creating a separate printer when the ancestor is private.

`asset-workflows.php` covers both cable orientations, repeated peers, multiple
IDs of one type, both direct-connection directions, entity restrictions,
propagation flags, global/deleted exclusions, device updates, missing targets,
escaped import names, restoration and recursive/private ancestor imports.
These workflows and asset classification, application and reporting contracts
pass on PostgreSQL and MariaDB. Existing PHP 8.3 asset suites pass 17 methods /
509 assertions. `Computer` and `NetworkEquipment` no longer execute direct
adapter queries. Eight more legacy SQL sites are removed, leaving 1,463 in the
static inventory; FK coverage remains 195 relationships.

### Cartridge stock and printer history

Cartridge printer assignments and cartridge/consumable model types now use three
nullable associations with restrictive FKs. Empty stock assignments and type
selections become NULL. Existing installations can review
`php bin/console db:stock_references`, stop application writers, apply with
`--apply`, then enforce constraints with `php bin/console db:foreign_keys --apply`.
The migration audits all references before DDL and supports idempotent retries.
Fresh installations normalize these references automatically.

`CartridgeRepository` handles installation, end-of-life, printer detachment and
the model/printer lists through typed ORM queries. Installation chooses the
lowest unused cartridge ID, then conditionally updates it only while it remains
unused. A competing request cannot overwrite a successful claim. The application
still writes its explicit printer history after successful operations. Repeated
unchanged end-of-life and back-to-stock operations return false on both engines.
Printer purge clears the assignment while retaining usage dates and counters;
unrelated printers' assignments remain intact.

All seven cartridge counts and entity notification-setting reads use ORM.
Cartridge lists join their mapped printer/model/type associations and specify
NULL ordering explicitly. The compatible-stock selector also uses ORM grouping,
counts unused cartridges and retains entity/recursive scope. `Cartridge` and
`Printer` no longer execute direct adapter queries.

`stock.php` covers type replacement/purge, installation and exhaustion,
end-of-life/return no-ops, counters, legacy empty criteria, populated lists and
selectors, entity scope, printer purge and old-schema migration/refusal/retry.
`stock-concurrency.php` starts two independent PHP processes against one available
cartridge, requires exactly one successful claim and removes its committed
fixtures. Both suites pass on PostgreSQL and MariaDB, including fresh installs.
The 198-FK contract, full ORM mappings/writes, reporting, search and application
checks also pass. PHP 8.3 cartridge, consumable and printer suites pass 10 methods /
355 assertions. Rendered-list checks are not browser interaction tests.

Coverage is now 198 enforced relationships, 564 pending relationship candidates,
62 polymorphic references, one ambiguous reference and 1,447 legacy SQL sites.

### Consumable assignments, summaries and alerts

`ConsumableRepository` now handles give/return operations, paginated stock lists,
usage summaries and alert candidates. Returning an item keeps the last recipient
as history, and repeated returns retain the existing success behavior. Recipient
type/ID pairs remain polymorphic and are not yet covered by a database FK.

Stock pages apply limits and offsets in the database, with explicit NULL ordering
and stable date/ID ordering. Used stock now retains its entry-date tiebreaker,
which the former PHP array union discarded. Rendering uses the selected date to
display status, avoiding additional state-count queries for every row. Missing
recipients keep their table cell, and removed plugin classes no longer make the
summary fail.

Usage summaries join the mapped consumable model and apply its entity scope,
independently of cached child entity IDs. Counts distinguish users and groups
with the same numeric ID and include empty visible models in the rendered table.
Alert selection uses a typed timestamp cutoff, preserves the strict repeat-delay
boundary, filters deleted/disabled/foreign-entity models and distinguishes other
alert item types. PostgreSQL timestamp output is normalized to the existing
notification date-string format. Notification delivery remains in the existing
application lifecycle.

`consumables.php` covers these behaviors on PostgreSQL and MariaDB, including
rendered lists/summaries and alert selection without sending notifications.
Stock, reporting and application contracts also pass on both engines, and the
PHP 8.3 consumable/cartridge suites pass 6 methods / 249 assertions. `Consumable`
and `ConsumableItem` no longer execute direct adapter queries. The inventory now
reports 1,435 legacy SQL sites; FK coverage remains 198 enforced relationships.

### Domain and certificate expiration

Domain expiration selection and dropdowns now use mapped reads. Calendar-day
boundaries replace MySQL `DATEDIFF` expressions, preserving strict delays and
excluding today from domain reminders. `CertificateRepository` uses typed dates
and excludes certificates with an existing end-of-life alert. Notification
sending remains in the application lifecycle.

`expiration.php` passes on PostgreSQL and MariaDB: midnight/delay boundaries,
NULL dates, entity scope, deleted/template records, alert deduplication and
selectors. It tests selection without sending notifications. The PHP 8.3 domain
and certificate suites pass 10 methods / 208 assertions. The audit now reports
1,431 legacy SQL sites; FK coverage remains 198 enforced relationships.

### Financial relationships and default report aggregates

Nine more references are nullable ORM associations with restrictive foreign keys:
contract/budget/license types, and budget assignments on infocoms, contract costs,
ticket costs, problem costs, change costs and project costs. Empty dropdown values
normalize to NULL. Budget purge retains financial records and clears their budget;
type replacement/purge retains the affected records. Project-cost partial updates
now preserve dates when only the budget changes.

Existing installations must stop writers during maintenance, inspect
`php bin/console db:financial_references`, apply it with `--apply`, then run
`php bin/console db:foreign_keys --apply`. The migration audits all references
before changing any column, refuses real zero parents and nonzero orphans, and
supports retry after MySQL's separately committed DDL. Fresh installs normalize
and enforce these references automatically.

The core default report's OS and asset-type aggregates now run through
`AssetRepository`. Counts retain unclassified assets, merge identical type names,
and filter deleted/template assets and deleted OS installations. Authorization
uses the parent computer's entity rather than the installation's cached entity.
Plugin types retain their existing query path. Regression checks also assert that
the core report uses no legacy SQL execution.

Fresh PostgreSQL and MariaDB installs pass the 207-FK enforcement contract,
financial lifecycle/migration, reporting, search and application suites. All 355
tables pass ORM insert/read/delete checks and 341 pass update checks. PHP 8.3
contract, contract-cost, project-cost, license and infocom suites pass 14 methods /
288 assertions. Report rendering was exercised in PHP, not a browser.

The audit now records 207 enforced relationships, 555 pending candidates,
62 polymorphic references, one ambiguous reference and 1,430 legacy SQL sites.

### Budget reports and cost history

`BudgetRepository` now selects financial item details and groups spending by
entity through mapped infocom/budget and cost/parent associations. It supports
all five cost types, asset records, cartridges, consumables and device components.
The existing rules remain explicit: historical spending survives soft deletion;
contract templates are excluded from the item list but retained in contract
spending totals. Type discovery for totals retains its infocom entity scope,
while projected amounts use the actual parent entity. Empty scopes match nothing.
Cost types discovered through infocoms are deduplicated before adding their costs.
Only entities represented in the totals are loaded for rendering.

`CostRepository` handles core cost history and action-time sums. Lists order NULL
begin dates first, while latest-cost selection orders NULL end dates last and
breaks ties by ID. Latest selection uses a database limit. Contract/project and
ITIL cost views, defaults and summaries use these mapped reads. Contract cloning
uses mapped `find()` before invoking the existing application add lifecycle.
Unmapped plugin budget types and cost models retain their compatibility path.

`budgets.php` checks grouped decimal values, fractional labour, entity isolation,
empty scopes, deleted/template rules, duplicate type discovery, date ordering,
application latest-cost/summary methods and populated budget/cost HTML. It also
checks that the repositories bypass legacy SQL execution. Budget, reporting,
financial migration and application contracts pass on PostgreSQL and MariaDB.
The PHP 8.3 contract and cost suites pass 7 methods / 132 assertions. These are
PHP rendering checks, not browser interactions. The inventory now records 1,423
legacy SQL sites; FK coverage remains 207 enforced relationships.

### Supplier purchases and financial metadata

Five additional nullable associations now have restrictive FKs: supplier type,
infocom supplier and business criticity, budget location, and contract state.
Contract states are also registered with the application's replacement/purge
cleanup, so deleting a state clears affected contracts instead of leaving orphans.
Supplier purge retains financial records and clears their supplier reference.

Existing installations use `php bin/console db:financial_metadata` to inspect
changes, then `--apply` with application writers stopped, followed by
`php bin/console db:foreign_keys --apply`. The migration audits before DDL,
normalizes legacy zeros to NULL and supports idempotent retries. Fresh installs
perform this normalization automatically.

`InfocomRepository` supplies distinct financial item types and supplier purchase
projections. Stock and component purchases use their mapped model association
for names, links and entity scope. This fixes the consumable query's incorrect
cartridge-column join and extends component handling beyond controllers. Deleted
and template records retain their existing display behavior. Oversized groups
execute a count without hydrating item rows, then display the existing search
link; smaller groups fetch at most the configured list limit.

Supplier email lookup and financial modal-link counts use mapped reads.
`Infocom::getTypes()` and `Supplier::getSuppliersByEmail()` now return row arrays;
core consumers use foreach instead of database-iterator methods. Unmapped plugin
financial lists retain their compatibility path.

The new `financial-metadata.php` and `suppliers.php` suites cover replacement,
purge, legacy empty selections, migration refusal/retry, model entity isolation,
email identities, list bounds and populated HTML. Fresh PostgreSQL and MariaDB
installs pass the 212-FK enforcement contract, ORM mapping/writes, budget/reporting,
search and application tests. PHP 8.3 supplier, supplier-type, location, contract
and infocom tests pass 18 methods / 402 assertions. Rendering checks are in PHP,
not a browser. The location uniqueness test now expects Doctrine's portable
unique-constraint exception and still verifies the rejected update changes no data.

Coverage is now 212 enforced relationships, 550 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,420 legacy SQL sites.

### Manufacturers and antivirus ownership

All 34 declared manufacturer references now use nullable `ManyToOne` mappings and
restrictive FKs. This covers asset and infrastructure records, stock models,
software/licenses, antivirus records and device definitions. Manufacturer
replacement reassigns every dependent record; purge keeps the records and clears
the reference. Legacy empty selections continue to map to SQL NULL. Optional
reference groups are merged explicitly so manufacturer mappings do not replace
existing model/type associations. Required associations now include the complete
merged optional-reference set for their table.

Antivirus records also have a required computer association and restrictive FK.
Computer purge removes antivirus children through the existing application
lifecycle, while preserving other computers' records. The antivirus view and
legacy clone API use mapped reads; the clone keeps flags, manufacturer, quoted
names and deleted history. The view continues to exclude deleted antivirus rows.

For an existing installation, inspect `php bin/console db:manufacturer_references`,
apply it with `--apply` during maintenance with writers stopped, then run
`php bin/console db:foreign_keys --apply`. The latter also audits and installs the
required antivirus-computer constraint. Nonzero orphaned references are refused;
the migration does not invent parents or discard records. Fresh installations
normalize the manufacturer references automatically.

`manufacturers.php` checks replacement/purge across all 34 tables, legacy empty
selection reads/writes, unrelated records, antivirus rendering/cloning/computer
purge, and migration refusal/retry. Its migration test puts an orphan in the last
table and verifies that the first table's schema remains unchanged.

Fresh PostgreSQL and MariaDB installs pass the 247-FK contract, complete ORM
mapping/writes, manufacturer migration/lifecycle, component, software, stock,
reporting, search and application checks. The existing PHP 8.3 computer, software,
license, cartridge and consumable suites pass 32 methods / 829 assertions.
Antivirus rendering was exercised in PHP, not a browser. The audit now records
247 enforced relationships, 515 pending candidates, 62 polymorphic references,
one ambiguous reference and 1,418 legacy SQL sites.

### State assignments and summaries

Thirty-five additional state assignments now use nullable `ManyToOne` mappings
and restrictive FKs, covering assets, infrastructure, software versions/licenses,
device definitions and component assignments. State replacement updates every
dependent table; purge preserves those records and clears their state. Appliance
state cleanup is now registered too. The contract-state association was already
enforced. The state hierarchy's own parent reference remains pending: its zero
root and sibling-name uniqueness require a separate tree-schema migration.

Existing installations inspect `php bin/console db:state_references`, then run
it with `--apply` during maintenance with writers stopped, followed by
`php bin/console db:foreign_keys --apply`. The migration refuses nonzero orphans
before DDL and supports idempotent retries. Fresh installs normalize empty state
references automatically.

`StateRepository` provides grouped counts for core state summaries, with entity
scope, deleted/template exclusions and the existing no-state bucket. Parameters
use the mapped field type, including the remaining numeric flags. State selectors,
summary labels and uniqueness checks use mapped reads; software version lists
join their state association. Unmapped plugin summary types retain their adapter
path. Partial state updates check the complete name/parent key. Renaming a tree
dropdown retains its parent; adding through a reused object still creates a root
when no parent is supplied.

The shared update persistence now retains explicit NULL values. Clearing a
computer's state therefore clears active linked assets and component assignments
as well. The regression suite exercises this path alongside state replacement,
purge, empty-selection reads/writes, recursive visibility, tree moves and migration
refusal/retry. Existing computer and certificate fixtures now create real states
instead of random IDs; certificate fixtures also create their manufacturer.

The audit records 282 enforced relationships, 480 pending candidates, 62
polymorphic references, one ambiguous reference and 1,415 legacy SQL sites.

Fresh PostgreSQL and MariaDB installations pass the FK contract, all 355 ORM
mappings and CRUD checks, state migration/lifecycle, asset propagation, components,
software, reporting, search and application workflows. PHP 8.3 tree, asset,
software and certificate CRUD/clone tests pass 31 methods / 875 assertions.
Certificate notification delivery was excluded. Rendering checks execute PHP;
they do not establish browser behavior.

### Location assignments and item listings

Forty-two more location references now use nullable `ManyToOne` associations
and restrictive foreign keys. These cover assets, stock, infrastructure,
components, network outlets, tickets, users and queued chat records. Appliance
and queued-chat location links are also registered for replacement and purge;
removing a location preserves those records and clears their reference. Budget
locations were already enforced. The location hierarchy's parent link still
requires a separate migration for zero-root and sibling-name uniqueness.

Existing databases use `php bin/console db:location_references` to inspect the
plan, then `--apply` during maintenance with writers stopped, followed by
`php bin/console db:foreign_keys --apply`. Every reference is audited for nonzero
orphans before DDL. Empty selections become NULL and retries are idempotent.
Fresh installation includes this normalization.

`LocationRepository` replaces the core location listing's SQL union and per-item
fetches with ORM queries that load item rows and entity labels together. It keeps
entity scope, deleted-item exclusion, template inclusion, translated entity names,
type filtering and the existing table renderer. Rows have deterministic ID order
within each item type. Plugin types use their model's `find()` compatibility path.
Cartridge stock selectors now join their mapped location association. The shared
record loader accepts an absent NULL parent without PHP deprecation and still
loads the real root entity ID zero.

`locations.php` covers all 42 replacement/purge paths, legacy empty reads/writes,
scoped listings across configured types, entity translations, rendered rows,
migration preflight refusal and retries. Asset workflow tests also verify that
clearing a computer's location propagates NULL to active attached assets and
components. Computer and certificate fixtures use real locations; ticket-rule
tests expect NULL for an unassigned location.

Coverage is 324 enforced relationships, 438 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,414 legacy SQL sites.

Fresh PostgreSQL and MariaDB installs pass the 324-FK contract, all 355 ORM
mappings and CRUD checks, location migrations/lifecycle, asset propagation,
components, stock, software, reporting, search and application workflows. PHP 8.3
location, computer, datacenter, license, certificate CRUD/clone and ticket location
rule tests pass 30 methods / 978 assertions. No notification cron was run; listing
rendering was verified in PHP rather than through browser interactions.

### Group assignments, hierarchy and paginated items

Forty-one additional group references across 31 tables now use nullable
`ManyToOne` associations with restrictive FKs. This includes asset ownership and
technical groups, tasks/templates, project ownership, user defaults, categories,
planning events, queued chat and the group hierarchy itself. Missing cleanup
registrations for appliances, SIM assignments, planning events and queued chat
are included. Group roots use NULL; group trees have no zero-dependent sibling
uniqueness constraint. Explicit NULL moves now update tree names and levels,
including descendant names.

Use `php bin/console db:group_references` to inspect an existing database, apply
with `--apply` during maintenance with writers stopped, then run
`php bin/console db:foreign_keys --apply`. The migration audits all nonzero
references before DDL, normalizes empty selections and supports idempotent retries.
Fresh installs apply it automatically.

`GroupItemRepository` provides counts and bounded pages for core group item
listings. It preserves entity scope, deleted/template exclusions, descendant
groups and member fallback only when the asset has no assigned group. Membership
uses EXISTS so overlapping memberships do not duplicate assets. Pages cross type
boundaries and use explicit NULL/name/ID ordering on both engines; types without
a name column order by ID. Consumable pages return actual stock IDs, correcting
the former model-ID projection, while applying the model's entity scope. Plugin
types without registered entities retain a bounded adapter path.

Consumable group replacement/purge uses ORM updates. Replacement keeps usage
dates; purge clears recipient type/ID and returns stock. Other recipient types
with the same numeric ID remain untouched. Project visibility uses its mapped
group association. User defaults move to a replacement only if the user already
belongs to it; otherwise they clear. Clearing an optional default group is now
accepted by user validation, so group deletion cannot leave an orphaned default.

`groups.php` verifies all 41 replacement/purge paths, hierarchy roots and moves,
member fallback, cross-type pagination, consumable identities and lifecycle,
default-group membership rules, migration refusal and retries. Computer and
certificate fixtures now create real groups. Coverage is 365 enforced
relationships, 397 pending candidates, 62 polymorphic references, one ambiguous
reference and 1,413 legacy SQL sites.

Fresh PostgreSQL and MariaDB installs pass the 365-FK contract and complete ORM
mapping/writes, group migration/lifecycle, asset propagation, location/state
regressions, project hierarchy/visibility/planning, stock, consumables, reporting,
search and application workflows. The parent-purge suite also passes after fixing
an unnamed-tree-node warning. PHP 8.3 tree, asset, membership, planning,
certificate CRUD/clone and default-group ticket-rule tests pass 33 methods /
1,029 assertions. Notification cron and browser interaction were not exercised.

### Entity ownership and portable network matching

136 ordinary `entities_id` columns now use required `ManyToOne` associations
and restrictive foreign keys. Zero continues to identify the real root entity;
it is neither converted to NULL nor treated as an absent relationship. Mapped
inserts apply the association's root default when ownership is omitted. Explicit
NULL ownership remains invalid. Financial, budget, reservation, software, asset,
component, state and location repositories now query the association identity or
join the entity mapping directly, including aggregate grouping.

Existing installations use `php bin/console db:foreign_keys --apply` during
maintenance with writers stopped. The command audits the entire graph before
DDL and refuses orphaned data; no ownership values are guessed or repaired.
Fresh installs include these constraints. Special entity selectors using -1,
software-entity inheritance and the entity tree's root-parent sentinel still need
separate migrations and are excluded from this ownership registry.

Entity replacement/purge retains model hooks for ordinary relationships and
uses ORM updates for remaining cached ownership columns. Inaccessible user
defaults fall back to root without granting profile membership. Missing ownership
cleanup registrations for change/problem templates, domain relations and external
planning events/templates are included.

`IPNetworkRepository` replaces the network-matching SQL with bound DQL predicates
and a small platform-aware bit-count function. IPv6 comparisons now retain all
four address words; previously each iteration overwrote the preceding predicate.
Nearest-network order, exclusions and ancestor/descendant entity scope are
preserved. Entity transfers compute network ancestry using the destination
entity. Additional filters use structured field criteria; nonempty raw SQL
strings are rejected. Core callers do not supply raw filters. Software dictionary
restoration also uses NULL when matching an absent manufacturer.

`entity-ownership.php` exercises all 136 ownership replacement/purge paths,
unrelated records, root defaults, explicit NULL rejection and user membership.
`network-search.php` checks IPv4/IPv6 containment, equality, ordering, projections,
exclusions and scope. Both run in the database portability CI matrix.

Coverage is 501 enforced relationships, 261 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,412 legacy SQL sites. This remains an
incremental ORM migration; full relationship and query coverage is not complete.

Fresh PostgreSQL 18 and MariaDB installs pass the 501-FK enforcement contract,
complete ORM mappings and all-table writes, scoped reporting/budget/supplier and
software tests, location/state/group lifecycles, stock/components, project
planning/visibility, asset propagation, search and application workflows.
Ownership and network regressions pass on both providers. PHP 8.3 entity,
networking and software functional tests pass 28 methods / 693 assertions.
Verification did not exercise browser interactions or notification cron.


### Inventory metadata and operating-system uniqueness

31 further relationships use nullable ORM associations and restrictive FKs:
device classifications/interfaces, update systems, network classifications,
phone power supplies, VM classifications, filesystems, OS metadata and kernel
versions. Legacy empty selections become NULL. Run
`php bin/console db:inventory_metadata --apply` during maintenance, followed by
`php bin/console db:foreign_keys --apply`; fresh installs include both changes.
The migration rejects nonzero orphans before any schema change.

The OS assignment's existing uniqueness rule still treats an absent OS or
architecture as a single value. Two generated, read-only ORM columns supply the
normalized unique key on PostgreSQL and MariaDB. References remain nullable.
Duplicate assignments stop the migration before DDL; retries also restore an
index missing after an interrupted migration. No duplicates are silently deleted.

`InventoryRepository` joins OS and filesystem associations for item listings.
OS sorting accepts known fields and UI column numbers, uses deterministic NULL
ordering, and retains deleted assignment history. OS and disk `getFromItem()` now
return row arrays; callers relying on `DBmysqlIterator::next()` must switch to
iteration. Their legacy clone entry points read through ORM and retain model
hooks. VM UUID matching uses a bound, case-insensitive query limited to two rows,
retaining format/byte-order variants and refusing ambiguous matches. The default
OS report also joins the mapped association.

Shared relation history skips unsaved placeholder records and retains explicitly
NULL previous associations. This avoids attempting to write a history row with
an empty OS ID when cloning an assignment without a selected OS. Tests cover
all new replacement/purge paths, uniqueness with NULL, migration preflight and
retry, item-type isolation, views, cloning, UUID matching and ORM execution.

Coverage is 532 enforced relationships, 230 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,407 legacy SQL sites. Fresh PostgreSQL
and MariaDB installs pass FK enforcement (1,007 / 609 assertions), complete ORM
mapping and all-table writes, reporting, components, software, asset propagation,
entity ownership, search and application tests. Browser interaction and
notification cron were not exercised.
PHP 8.3 inventory, software-association and group-membership tests pass 32 methods
and 598 assertions, including duplicate OS rejection through Doctrine exceptions.

### Project and planning metadata

Twelve more references use nullable Doctrine `ManyToOne` associations and
`RESTRICT` foreign keys: project states/types, task states/types/templates,
task-template project/task/state/type references, and external-event
categories/templates. Legacy zero selections normalize to SQL NULL. Project and
calendar purge hooks clear or replace these references without deleting unrelated
records. Task-template AJAX loading preserves NULL and numeric values.

For existing databases, stop application writers and run `db:planning_metadata`
to inspect the plan, then `db:planning_metadata --apply` and
`db:foreign_keys --apply`. Nonzero orphan checks precede every schema change;
retries are idempotent. Fresh installs apply the same relationship definitions.

Project repositories now join mapped state/type associations. External-event
calendar and iCalendar queries use `PlanningRepository`, with bound dates and
actor predicates and a left category join. Uncategorized events remain visible;
recurrence expansion and access checks are retained. Empty group subscriptions
cannot become queries for unassigned events. Group planning selectors use mapped
reads and preserve entity and membership scope. Other planning item types still
use the shared legacy query path and remain part of the outstanding migration.

Coverage is now 544 enforced relationships, 218 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,404 legacy SQL call sites. The branch
has not completed the application-wide ORM migration.

Validation: PostgreSQL and MariaDB fresh-install contracts pass 1,019 and 621
assertions respectively, plus all 355 mapped-table CRUD tests. Planning metadata
lifecycle/migration, reporting, project views/planning/hierarchy, search and the
application workflow pass on both engines. PHP 8.3 project/planning tests pass
18 methods and 519 assertions; a final shared-trait run including reminders
passes 11 methods and 247 assertions. These are database and PHP rendering checks;
no live-browser or notification-cron validation is claimed.

### ITIL classification and request sources

Twenty-three additional references use nullable Doctrine associations and
`RESTRICT` foreign keys. They cover categories on tickets, changes, problems and
queued chats; task categories and templates; category-to-template and knowledge
category links; request sources on tickets, followups, templates and user
preferences; and solution types on solutions and templates. Model replacement
and purge hooks normalize missing selections to NULL while preserving dependent
records. Unclassified task/followup labels and template AJAX values retain their
meaning when references are NULL.

Existing databases require maintenance mode: inspect `db:itil_classification`,
then run `db:itil_classification --apply` and `db:foreign_keys --apply`.
The migration rejects nonzero orphans before DDL, converts legacy zeros, and is
idempotent. Installation uses the same registry.

`ITILClassificationRepository` selects template categories using the actual
Ticket/Change/Problem template association. Equal IDs in different template
tables no longer select or mark unrelated categories. The template tab applies
entity scope. Category-code lookup binds values and fetches at most two rows to
preserve ambiguity detection. Request-source defaults use a bounded ORM query;
add/update hooks clear competing defaults with a typed DQL update.

Coverage is 567 enforced relationships, 195 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,399 legacy SQL call sites. This remains
an incremental migration, with all-table relationship and query coverage still
unfinished.

Validation: fresh PostgreSQL/MariaDB installs pass 1,042/644 FK and portability
assertions, all 355 mapped-table CRUD checks, classification lifecycle/migration,
reporting, search, application and project-planning tests. A shared-category test
also verifies template-family indicators when different template tables have the
same ID. PHP 8.3 ITIL category/template/followup/solution tests pass 25 methods and
1,258 assertions; ticket/change/problem/task/followup tests pass 79 methods and
4,241 assertions. Rendering assertions execute PHP; browser interactions and
notification delivery were not exercised.

### Tree parents and derived caches

Nine tree dropdowns now map their parent as a nullable Doctrine association:
business criticalities, document categories, ITIL categories, knowledgebase
categories, locations, software categories, software license types, states and
task categories. Roots use NULL, while the separate Entity hierarchy keeps its
real ID-zero root and existing sentinel convention.

Five existing sibling-name unique indexes include the parent. Their replacement
uses a read-only generated `parent_key` that coalesces a missing parent to zero, preserving
root-level uniqueness when parents become NULL. Entity ownership has an
independent supporting index before an old unique index is replaced, which
avoids breaking MariaDB's FK index dependency. Names under different parents
remain valid; uniqueness rules are not added to trees that previously lacked them.

During maintenance, inspect `db:tree_parents`, run `db:tree_parents --apply`, then
`db:foreign_keys --apply`. Preflight checks all parent references, cycles and
sibling duplicates before any DDL. A retry repairs missing unique indexes after
an interrupted migration. PostgreSQL applies the migration transactionally;
MySQL/MariaDB DDL remains separately committed.

`TreeRepository` supplies scalar projections and typed updates for derived
complete names, levels and ancestor/descendant caches. `CommonTreeDropdown` keeps
model hooks for child reparenting, uses mapped reads for import lookup and child
lists, and updates derived fields without recursively invoking those hooks.
`DbUtils` tree cache access uses the same ORM repository. Unmapped plugin tables
retain explicit legacy paths. ITIL category updates now propagate a rejected
cyclic move instead of passing `false` to `array_key_exists()`.

Coverage is 576 enforced relationships, 186 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,390 legacy SQL call sites. Complete
relationship/query coverage remains outstanding.

Validation: fresh PostgreSQL/MariaDB contracts pass 1,051/653 assertions and all
355 mapped-table CRUD checks. Both engines pass tree lifecycle/migration,
location/state/group assignments, entity ownership, knowledgebase, software, ITIL
classification, reporting, application and search suites. Tests explicitly cover
cold and warm caches, quoted names, subtree moves, cycle rejection, purge and
replacement, root uniqueness, duplicate/orphan preflight and interrupted-index
retry. PHP 8.3 hierarchy/database-helper/software tests pass 72 methods and 7,145
assertions. Live browser interaction and notification delivery were not tested.

### Asset owners and technicians

Thirty-one user assignments across 21 asset tables now use nullable Doctrine
associations with RESTRICT foreign keys. This includes owners and technicians
for inventory, software, certificates and appliances, plus SIM-card users.
Missing appliance and SIM-card entries in the user relation registry are now
explicit so replacement and purge hooks process those assignments too.

For existing databases, inspect `db:asset_users`, apply it during maintenance
with `db:asset_users --apply`, then run `db:foreign_keys --apply`. The migration
normalizes zero assignments to NULL and rejects nonzero orphans before DDL.
Fresh installs include the nullable columns and constraints automatically.

`UserItemRepository` supplies typed ORM membership and inventory queries, streams
results with entity/status labels and applies entity, deletion and template
filters in the query. The user inventory tab checks item access before rendering
each row. Public saved-search ownership and consumable returns during user purge
now use ORM updates; private searches still use their deletion hooks. Consumables
assigned to a group with the same numeric ID are preserved.

Coverage is 607 enforced relationships, 155 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,385 legacy SQL call sites. Full
relationship and query conversion remains unfinished.

Validation: fresh PostgreSQL/MariaDB installs pass 1,082/684 contract assertions,
all 355 mapped-table CRUD checks, assignment replacement/purge and migration,
asset propagation, group assignments, entity ownership, software, consumables,
inventory metadata, reporting, application and search suites. PHP 8.3 functional
tests pass across eight affected classes (62 methods); the computer suite alone
passes 349 assertions after replacing arbitrary user IDs with real fixtures.
All 30 changed PHP files pass syntax checks and formatting checks pass. Rendering
tests execute PHP; browser interaction and notification delivery were not tested.

### Reservations and booking availability

Reservation ownership is now a nullable `User` association with a RESTRICT FK.
User replacement reassigns bookings; user purge preserves them with no owner.
Existing installations should inspect `db:reservation_users`, apply it during
maintenance, then apply `db:foreign_keys`. Nonzero orphans stop the migration
before DDL; zero owners normalize to NULL. Fresh schemas include this mapping.

`ReservationRepository` now handles conflict checks, recurrence-group lookup,
calendar item selection, current/past lists and expiry-alert candidates with
Doctrine queries. Group deletion snapshots IDs and retains model hooks. Calendar
and item lists fetch user names in the booking query, including missing owners.
Daily selection includes bookings starting in the final second of the day.
Schedule validation only runs for date or reserved-item changes, so legacy
bookings with missing dates do not block owner cleanup. Rejected edits restore
the model fields; moving a booking also checks conflicts on its destination item.

`ReservationItemRepository` resolves core asset types through the entity registry
for availability, location labels and peripheral categories. Availability uses
an anti-existence query and the same half-open overlap rule as booking validation:
an item booked until 11:00 can be booked again starting at 11:00. Active/deleted
flags, entity recursion and type selection remain query filters. Unmapped plugin
asset listings retain their existing legacy query path until plugin mappings are
available. Expiry selection binds timestamps instead of using MySQL epoch
arithmetic; notification delivery remains in existing hooks and cron code.

Coverage is 608 enforced relationships, 154 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,375 legacy SQL call sites. Complete
relationship and query conversion remains outstanding.

Validation: both engines pass the 1,083/685-assertion database contract, complete
mapping and parent-purge checks, CRUD across all 355 mapped tables, and reservation,
reporting, placement, application and search suites. Fresh PostgreSQL and MariaDB
installs pass the reservation contract; the MariaDB fresh-install contract also
passes on PHP 8.3. Tests cover adjacent intervals, occupied destination moves,
rejected-edit state restoration, user replacement/purge (including legacy NULL
dates), group isolation, entity/type filters, daily boundaries, alert suppression,
and migration orphan refusal/idempotence. Alert selection and PHP rendering were
tested; notification delivery and browser interactions were not exercised.

### Content authors and document views

Eight user relationships now use nullable Doctrine associations on documents,
document attachments, knowledge-base articles/comments/revisions/translations,
and note authors/editors. Document categories add a ninth nullable association.
Deleting a user or category retains the content; replacement updates its audited
references. Existing installations should inspect `db:content_metadata`, apply
it during maintenance, then run `db:foreign_keys --apply`. Nonzero orphans stop
preflight before DDL, and empty legacy references become NULL.

`ContentRepository` supplies notes with editor pictures, attachment IDs, document
counts/headings and scoped document lists. Document relationships resolve the
opposite endpoint in either direction and apply entity restrictions to that
endpoint. Each timeline association retains its own row. The shared user-name
helper now loads mapped user records; its string/link/tooltip outputs remain the
same. Document and note view/clone queries no longer call the legacy DB adapter.

Author cleanup does not generate new content revisions or overwrite note editor
history. Actual knowledge-base text edits still snapshot revisions, and note
content edits still record the editor. Missing comment/revision authors render
as unknown users. The knowledge-base tree formatter converts nullable parent
IDs to its existing synthetic root ID, preserving the jsTree data contract.

Coverage is 617 enforced relationships, 145 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,368 legacy SQL call sites. The complete
relationship and query migration remains unfinished.

Validation: PostgreSQL/MariaDB pass 1,092/694 database-contract assertions,
metadata and parent-purge checks, CRUD across all 355 tables, and content,
knowledge-base, reporting, application and search suites. Fresh installs pass the
content contract on both engines, including PHP 8.3 on MariaDB. Seven affected
PHP 8.3 functional classes pass across 63 methods; the four knowledge-base classes
pass 17 methods and 348 assertions after the jsTree fix. Tests cover author and
category replacement/purge, revision preservation, missing authors, both document
link directions, entity filtering, timeline duplicates, explicit NULL ordering,
and orphan preflight/idempotence. Rendering checks execute PHP; browser
interactions and notification delivery were not exercised.

### Article categories and knowledge-base visibility

An article's category is now a nullable `KnowbaseItemCategory` association with
a RESTRICT FK. Category replacement reassigns articles; category purge preserves
them as uncategorized. Existing installations should inspect
`db:article_categories`, apply it during maintenance, then apply
`db:foreign_keys`. Empty category IDs become NULL; nonzero orphans stop the
migration before DDL. Fresh schemas include this relationship.

The category tree obtains its counts through `KnowledgeBaseRepository`, using
mapped associations and bound Boolean values. `KnowledgeBaseAccess` captures the
user, groups, profile and entity scope separately from query generation. Grant
checks use `EXISTS`, so overlapping grants cannot multiply article counts.
Public FAQ, recursive entity grants, global group/profile grants and administrator
visibility are covered. One grouped count includes uncategorized articles; a
second query loads category metadata. Empty branches are pruned in one pass from
children to parents, retaining ancestors of visible articles.

Category article IDs also use the mapped repository, retaining the model's
per-article access checks. Ownership now requires a logged-in user: a NULL author
cannot compare equal to an anonymous session and grant access. The remaining
legacy denied-access predicate uses a Boolean comparison accepted by both engines.
Full-text article search and other legacy visibility consumers still require
conversion.

Coverage is 618 enforced relationships, 144 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,365 legacy SQL call sites. The complete
relationship and query migration remains unfinished.

Validation: both engines pass the 1,093/695-assertion database contract, mapping
and parent-purge checks, CRUD across all 355 tables, and knowledge-base, content,
reporting, application and search suites. Fresh PostgreSQL and MariaDB installs
pass the category contract, including PHP 8.3 on MariaDB. Six affected PHP 8.3
functional classes pass 46 methods and 6,454 assertions. The new contract covers
all grant types, overlapping grants, public FAQ scope, anonymous ownership,
ancestor retention, category replacement/purge, NULL lookup and migration
orphan refusal/idempotence. PHP syntax, formatting and diff checks pass. Browser
interaction and notification delivery were not exercised.

### Software metadata and license queries

Five more relationships now have nullable Doctrine associations and RESTRICT
foreign keys: software category, the software being updated, license parent,
purchased version and used version. Replacement reassigns dependents; purging an
optional target preserves dependents with NULL references. License deletion keeps
its tree reparenting hooks. Trees without stored ancestry caches now skip writes
to those absent cache columns.

Existing installations should inspect `db:software_metadata`, apply it during
maintenance, then run `db:foreign_keys --apply`. The migration audits nonzero
orphans and license-parent cycles before changing any table. Legacy zero values
become NULL, rerunning is safe, and fresh schemas include all five relationships.
Software update links are associations, not category-tree parents.

`SoftwareRepository` supplies paginated license lists through mapped version,
type, state and entity associations. Sorting uses a column whitelist, explicit
NULL ordering and an ID tie-breaker. Expiry selection binds a calendar-day cutoff
instead of using MySQL date arithmetic; software entity, template/deletion and
existing-alert exclusions remain query predicates. The license child list also
uses ORM, and software merging now changes the mapped version associations
inside its existing transaction. The license model has no direct query/request
calls left; its notification dispatch and lifecycle hooks remain intact.

Coverage is 623 enforced relationships, 139 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,362 legacy SQL call sites. Complete
relationship and query conversion remains outstanding.

Validation: PostgreSQL/MariaDB pass 1,098/700 database-contract assertions,
mapping and parent-purge checks, CRUD across all 355 tables, and shared tree,
software installation, reporting, application and search suites. Software merge
checks pass on both engines, including transaction rollback. Fresh installs pass
the new contract on PostgreSQL and PHP 8.3/MariaDB. Four affected PHP 8.3
functional classes pass 46 methods and 6,276 assertions. Tests cover all five
relationship lifecycles, license child rendering and promotion, cycle rejection,
association labels, scope, pagination, NULL ordering, expiry boundaries and alert
suppression, migration orphan/cycle refusal and idempotence. Ten changed PHP
files pass syntax checks; formatting and diff checks pass. Views were exercised
through PHP rendering; browser interaction and notification delivery were not
exercised.

### Contacts, phone lines and SIM assignments

Five optional relationships now use nullable Doctrine associations and RESTRICT
foreign keys: contact type/title, line operator/type and a SIM assignment's line.
Replacement updates dependent records; purge preserves them without the optional
reference. Existing installations should inspect `db:contact_lines`, apply it
during maintenance, then run `db:foreign_keys --apply`. Nonzero orphans stop
preflight before DDL; legacy zero values become NULL. Fresh installs include the
new mappings.

`ContactRepository` handles supplier address/website selection, both directions
of the contact–supplier list, scoped counts and the contact picker. Address and
website resolve the same supplier deterministically when several companies are
linked. Relationship views scope the opposite endpoint, including recursive
ancestor grants, and retain the association ID. Cron callers can explicitly omit
interactive entity scope. The inherited legacy relation query API remains for
other consumers pending its wider migration.

The contact picker no longer constructs MySQL-only `IFNULL` expressions. Its ORM
query applies entity restrictions, exclusions, search and pagination; formatting
combines last/first names after hydration. Numeric `LIKE` criteria now explicitly
convert numbers to text while preserving NULL on both providers, including ID
search and optional associations.

Coverage is 628 enforced relationships, 134 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,360 legacy SQL call sites. Complete
relationship and query conversion remains outstanding.

The supplier view passes already-linked contact IDs to the picker query, so
exclusion also works inside entity groups rather than only for flat options.

Validation: both engines pass the 1,103/705-assertion database contract, mapping
and parent-purge checks, CRUD across all 355 tables, and application, reporting
and search suites. Fresh installations pass the new contact/line contract and
shared ORM criteria checks, including PHP 8.3/MariaDB. Six affected PHP 8.3
functional classes pass 13 methods and 187 assertions; the additional dropdown
and contact–supplier run passes 15 methods and 528 assertions. Tests cover all
five reference lifecycles, both scoped relation directions, recursive ancestors,
company-detail consistency, rendered views, picker pagination/exclusions/ID
search, grouped option exclusions, NULL matching and orphan refusal/idempotence.
Fourteen changed PHP files pass syntax checks; formatting and diff checks pass.
Rendering tests exercise PHP output; browser interaction and notification
delivery were not tested.

### Scheduled-task logs and statistics

Cron logs now have a required task association and a nullable parent-log
association, both enforced by RESTRICT foreign keys. Existing installations
should inspect `db:cron_logs`, apply it during maintenance, then run
`db:foreign_keys --apply`. Preflight refuses missing tasks, nonzero missing
parents and cyclic log hierarchies before changing the schema. Legacy root
parent values of zero become NULL; fresh installs include these mappings.

`CronLogRepository` handles task claims/completion, aggregate statistics,
paginated history, scoped run details and retention. A claim and its start log
commit together; completion and its final log also share a transaction. Two
concurrent claims produce only one successful transition and one start log.
Failed logging rolls back the state transition. Details require the owning task,
so another task's log ID cannot retrieve its history.

Retention deletes expired leaves in batches and keeps parents needed by newer
children. It locks the task while cleaning and preserves the active run's start
record. Removing an individual message reparents descendants; removing a task
cleans its entire log graph through model hooks. Completion records whose root
was manually removed still link to their own details. Scheduler selection,
plugin unregistration and watcher queries remain pending ORM migration.

Coverage is 630 enforced relationships, 132 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,353 legacy SQL call sites. Complete
relationship and query conversion remains outstanding.

Validation: PostgreSQL/MariaDB pass 1,105/707 database-contract assertions,
complete mapping and parent-purge checks, CRUD across all 355 tables, and
reporting, application and search contracts. Fresh installations pass the cron
contract on both engines; PHP 8.3/MariaDB also passes it, and the existing CronTask
functional class passes three methods and 57 assertions. Regression checks cover
concurrent claims, failed-log rollback, scoped details, statistics, pagination,
rootless history links, multi-batch retention, active-run preservation, task/log
purges and migration orphan/cycle refusal and idempotence. All 12 changed PHP
files pass syntax checks; formatting and diff checks pass. Rendering checks use
PHP output. No real scheduled task bodies or notification deliveries ran.

### Scheduled-task selection and alert decisions

`CronTaskRepository` now selects the next task, lists plugin tasks for lifecycle
unregistration, lists used item types, finds overdue runs and evaluates error
notification eligibility. `CronTask` and `CronTaskLog` have no remaining direct
query/request/update/delete calls. Notification dispatch remains in the model.

Selection applies active-plugin prefixes, local-hour windows (including overnight
windows), elapsed frequency, modes and per-task locks in the mapped query. It
returns one row, prioritizing core tasks, then never-run tasks and due time, with
ID as a stable tie-breaker. Forced runs retain their allowed-mode check and skip
locks, windows and frequency; they still cannot claim an already-running task.
Namespaced plugin matching requires the namespace separator; plugin names are
literal prefixes rather than SQL wildcard patterns.

The shared `EPOCH_SECONDS` DQL function compiles the instant conversion for each
provider. Frequency and watcher checks use elapsed seconds so daylight-saving
changes cannot shorten or extend an interval. Allowed hours use the application
session's local timezone. The watcher preserves the existing two-frequency OR
two-hour threshold. Error decisions count five failures among the last ten
completed runs and suppress repeats when an alert exists within the previous
calendar day; progress logs do not displace completed runs.

The audit now lists 1,347 legacy SQL call sites. Relationship coverage remains
630 enforced, 132 pending, 62 polymorphic and one ambiguous; full conversion is
still unfinished.

Validation: the scheduler contract passes on PostgreSQL and MariaDB, including
spring/fall DST, exact time boundaries, overnight windows, forced modes, literal
plugin matching, stable ordering, file locks, overdue detection, alert scope and
suppression, and plugin lifecycle cleanup. The same contract passes under PHP
8.3/MariaDB; existing CronTask functional tests pass three methods and 57
assertions. Both engines also pass cron-log lifecycle/concurrency, shared ORM
criteria, reporting, application and search regression suites. Five changed PHP
files pass syntax checks; formatting and diff checks pass. No real task body or
notification delivery was executed, and remote CI has not run.

### User metadata and profile preferences

Users now have nullable mapped associations for default profile, title, category
and supervisor, enforced by four RESTRICT foreign keys. Existing installations
should inspect `db:user_metadata`, apply it during maintenance, then run
`db:foreign_keys --apply`. Preflight rejects nonzero orphans before DDL; legacy
zero selections become NULL. Fresh installations include the mappings.

Supervisor replacement and purge now update dependent users through model hooks.
Profile deletion clears each affected default preference, or replaces it only
when the user already has the replacement profile assigned. This does not grant
new permissions. Clearing a default profile is valid user input; selecting an
unassigned profile remains rejected.

`UserRepository` supplies profile labels, scoped email lists, preferred account
selection by email and unique identity lookup. Profile labels deduplicate grants
across entities. Email lookup preserves active/nondeleted priority, then uses ID
to break ties. Identity lookup fetches at most two IDs and rejects ambiguity;
quoted and preescaped caller values retain their existing boundary contracts.
The preference form now lists profiles belonging to the account being edited.

The form's profile-permission check also uses ORM through `ProfileRepository`.
It preserves the registered-rights comparison and central/helpdesk rules, denies
unknown profiles and requires an active session profile outside cron. The legacy
SQL criteria generator remains for other callers awaiting migration.

Coverage is 634 enforced relationships, 128 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,341 legacy SQL call sites. Complete
relationship and query conversion remains outstanding.

Validation: PostgreSQL/MariaDB pass 1,109/711 database-contract assertions,
complete mapping and parent-purge checks, CRUD across all 355 tables, shared ORM
criteria, reporting, application and search suites. Fresh installations pass the
user-metadata contract on both engines, including PHP 8.3/MariaDB. The affected
PHP 8.3 user/profile functional cases pass 13 methods and 449 assertions. Tests
cover all four relationship lifecycles, self-supervisor purge, authorized and
unassigned profile replacement, nullable preference clearing, email priorities,
ambiguous identities, scoped profile labels, rendered preferences, permission
bitmasks/interface restrictions and migration orphan refusal/idempotence. Final
input-guard checks reject negative/unassigned profile IDs on both engines. All
12 changed PHP files pass syntax checks; formatting and diff checks pass.
Rendering tests use PHP output; LDAP import, browser interaction and notification
delivery were not exercised.

### Historical ITIL user references

Twenty-four user references now use nullable Doctrine associations and RESTRICT
foreign keys: ticket/problem/change recipients and last updaters, task authors,
editors and technicians, validation authors and validators, followup authors and
editors, and solution authors, editors and approvers. Existing installations
should inspect `db:itil_users`, apply it during maintenance, then run
`db:foreign_keys --apply`. The migration refuses nonzero orphans before DDL and
normalizes legacy zero values to NULL. Fresh installs include these mappings.

User purge/replacement maintains these historical associations through mapped
bulk updates in `ITILUserRepository`. This operation does not replay the ordinary
ITIL workflow update hooks: content, status, dates and approval metadata stay
unchanged when only a user reference changes. Ordinary content editing still
records its editor and runs the normal lifecycle. Actor-link cleanup remains in
its existing lifecycle path.

The followup summary and support-agent profile check now use ORM. Followup
visibility is scoped by item type and ID, public/private rights and a positive
viewer ID. An anonymous viewer cannot acquire ownership of an authorless private
followup. Date ordering has an ID tie-breaker. The support-agent decision retains
its assigned/observer/requester rules and checks central-profile membership with
a bounded mapped join.

Coverage is 658 enforced relationships, 104 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,339 legacy SQL call sites. Full
relationship and query conversion remains unfinished.

Validation: both engines pass fresh installation and the ITIL user contract,
including replacement/purge of all 24 references, unchanged historical fields,
ordinary editor updates, private visibility, support-agent decisions and migration
orphan refusal/idempotence. PostgreSQL/MariaDB pass 1,133/735 database-contract
assertions, complete mappings and parent purges, CRUD across all 355 tables,
shared ORM criteria, reporting, application and search suites. PHP 8.3/MariaDB
passes the new contract; six affected functional classes pass 24 methods and 829
assertions. All 19 changed PHP files pass syntax checks; formatting and diff
checks pass. The legacy summary rendering test emits its expected deprecation
notice. Browser interaction and notification delivery were not exercised.

### Named and anonymous ITIL actors

Ticket, problem and change user/supplier actors now use six nullable Doctrine
associations and RESTRICT foreign keys. Email-only actors store NULL instead of
zero. Parent updates, notification editors and parent/endpoint purge hooks retain
anonymous actors correctly. Actor lists and alternate-email checks use the shared
mapped criteria path; unregistered plugin tables retain its compatibility path.

Each actor table has two read-only generated identity columns. A unique index
allows one named actor per parent and role, regardless of alternate email, and
one anonymous actor per parent, role and email. Unlike a unique index containing
a nullable actor ID, it also rejects duplicate anonymous assignments at the
database boundary. Distinct anonymous supplier emails can coexist.

Existing installations should inspect `db:itil_actors`, stop application writers,
apply it during maintenance, then run `db:foreign_keys --apply`. The migration
rejects nonzero orphans and duplicate normalized identities before DDL; it never
chooses which duplicate to delete. PostgreSQL changes are transactional. MySQL
DDL commits separately, refuses an active application transaction and supports
idempotent retries. Fresh installations include the new identity keys.

Coverage is 664 enforced relationships, 98 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,337 legacy SQL call sites. Complete
relationship and query conversion remains outstanding.

Validation: fresh PostgreSQL and MariaDB installations pass. Actor contracts on
both engines cover named and anonymous identities, database duplicate rejection,
additional actors through parent updates, notification-form rendering, parent and
endpoint purges, orphan/duplicate upgrade refusal and migration idempotence.
PHP 8.3/MariaDB also verifies the active-transaction guard. Both providers pass
complete mappings, CRUD across all 355 tables, parent purges, ORM criteria,
reporting, application and search suites, with 1,139/741 database-contract
assertions. Affected PHP 8.3 functional tests pass six methods and 205 assertions.
All 16 changed PHP files pass syntax checks; formatting and diff checks pass.
Rendering was verified through PHP output, not browser interaction. Notification
delivery was not exercised.

### Network reports and port subtype relationships

The equipment, outlet and location report routes now call
`Report::showNetworkReport()` with an explicit report kind and selected IDs.
`NetworkReportRepository` joins mapped ports, wires, Ethernet subtypes, outlets
and locations through DQL. Endpoint visibility applies to both cable orientations;
deleted or foreign-entity peers remain absent. Address hydration uses a separate
mapped query in batches of 500 port IDs, avoiding endpoint fan-out and vendor
aggregate functions. Addresses are distinct and sorted. Unwired ports and outlets
without locations remain reportable; both endpoint port numbers are projected.
The old SQL-shaped `reportForNetworkInformations()` API and address SQL helper
have been removed; custom callers should use the typed report entry point.

Fifteen more references use Doctrine associations and RESTRICT foreign keys:
seven required subtype-to-port links, the optional alias origin, Ethernet/fibre
card and outlet metadata, and Wi-Fi card, network and upstream Wi-Fi references.
Port purge removes all owned subtype rows even with a stale discriminator.
Reference cleanup distinguishes a model's public lookup key from its physical
row ID, including Wi-Fi replacement and purge.

Existing installations should inspect `db:network_ports`, apply it with writers
stopped, then run `db:foreign_keys --apply`. Optional legacy zeros become NULL;
nonzero orphans cause refusal before nullable-column DDL. Foreign-key installation
also audits required parents. MySQL DDL commits separately and migration retries
are idempotent. Fresh installs include all fifteen associations. Serialized
aggregate membership remains pending structural migration.

Coverage is 679 enforced relationships, 83 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,336 legacy SQL call sites. Full
relationship and query conversion remains unfinished.

Validation: both engines pass fresh installation, all new relationship
replacement/purge paths, stale subtype cleanup, Wi-Fi physical/public identity
checks and migration orphan refusal/idempotence. Reporting covers all three
selectors, both cable orientations, distinct addresses, deleted/foreign peers,
unwired ports, nullable locations and execution without legacy SQL calls.
PHP 8.3/MariaDB passes the network and reporting contracts and seven functional
methods with 141 assertions, including the corrected subtype-clone check.
PostgreSQL/MariaDB pass 1,154/756 database-contract assertions, complete mapping
and parent-purge checks, CRUD across all 355 tables, ORM criteria, application and
search suites. Authenticated HTTP checks with CSRF and SQL/fatal-log assertions
pass for all report routes on both engines. All 22 changed PHP files pass syntax
checks; formatting and diff checks pass. No interactive browser test or remote
CI run was performed.

### Domain names and aliases

Network names and aliases now hold nullable FQDN associations; aliases also have
a required network-name association. All three are protected by RESTRICT foreign
keys. Domain replacement/purge retains labels and changes only their association;
network-name purge removes its aliases through existing lifecycle hooks.
Inspect `db:network_names`, apply it with writers stopped, then run
`db:foreign_keys --apply` on existing installations. Nonzero domain orphans are
rejected before nullable-column DDL; legacy zero domains become NULL. Required
alias parents are audited by foreign-key installation. Fresh installs include
the new mappings.

`FQDN`, `FQDNLabel`, `NetworkName` and `NetworkAlias` no longer issue direct legacy
database queries. Lookup, detach and child reads use mapped criteria; the new
`NetworkNameRepository` owns scoped domain/equipment listings, alias projections
and their matching counts. Domain views exclude foreign-entity labels and aliases.
Equipment counts and lists consistently exclude deleted ports.

IP and alias sorting select one name per page slot. IP order uses the first
complete binary address tuple, with a physical-ID tie-breaker; independent
column minima would produce incorrect composite addresses. Alias order uses the
first visible non-NULL alias, with missing values last. Bound pagination and a
name-ID tie-breaker prevent duplicate names and unstable pages. Alias views also
support ordering by their target's real name. Network-name table options now use
explicit `limit` and `offset` instead of accepting arbitrary `SQL_options`.

Coverage is 682 enforced relationships, 80 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,326 legacy SQL call sites. Complete
relationship and query conversion remains outstanding.

Validation: fresh installs and network-name contracts pass on PostgreSQL and
MariaDB, including PHP 8.3/MariaDB. Tests cover domain replacement/purge, alias
cleanup, scoped counts and pages, composite IP ordering, multiple aliases,
missing sort values, deleted/foreign names and ports, name attachment/detachment,
exact/wildcard lookup, populated view rendering and migration refusal/idempotence.
PostgreSQL/MariaDB pass 1,157/759 database-contract assertions, full mappings,
parent purges, ORM CRUD for all 355 tables, criteria, reporting, application and
search suites. The affected PHP 8.3 functional class passes seven methods and
141 assertions. All 14 changed PHP files pass syntax checks; formatting and diff
checks pass. View verification used PHP rendering, not an interactive browser;
remote CI was not run.

### ITIL defaults and recurring tickets

Seven more default references use nullable Doctrine associations and RESTRICT
foreign keys: profile ticket/change/problem templates, recurring-ticket templates
and calendars, category default users and task-template technicians. Replacement
and purge preserve the owning record, reassign a requested replacement or clear
the reference. Change/problem template profile links are now registered in the
lifecycle relation inventory; previously those profile defaults could dangle.
Entity template inheritance sentinels are separate, pending explicit modeling.

Existing installations should inspect `db:itil_defaults`, apply it with writers
stopped, then run `db:foreign_keys --apply`. The migration audits nonzero orphans
before DDL, converts zero defaults to NULL and supports idempotent retries. MySQL
DDL commits separately. Fresh installations include all seven associations.

`TicketRecurrentRepository::due()` replaces the scheduler's direct query with
bound, typed DQL. A single clock value governs both date predicates. Selection
retains strict due/end boundaries, active flags and existing handling of schedules
without a template; results are ordered by due date and ID. Its legacy row
projection retains formatted dates and numeric flags. The caller retains ticket
creation, history and rescheduling hooks; selection itself has no side effects.

Coverage is 689 enforced relationships, 73 pending candidates, 62 polymorphic
references, one ambiguous reference and 1,325 legacy SQL call sites. Full
relationship and query conversion remains unfinished.

Validation: fresh installation and the defaults contract pass on both engines,
including PHP 8.3/MariaDB. Tests cover all seven replacement/purge paths, unrelated
defaults, zero/NULL compatibility, strict due/end boundaries, inactive schedules,
missing dates, deterministic ordering, row types and migration refusal/idempotence.
Sparse-profile template cleanup passes with PHP warnings promoted to failures;
the profile rights guard now reads existing rights only when rights are updated.
PostgreSQL/MariaDB pass 1,164/766 database-contract assertions, full mapping and
parent-purge checks, ORM CRUD across all 355 tables, criteria, reporting,
application and search suites. Three affected PHP 8.3 functional classes pass
eight methods and 330 assertions; the four profile methods pass again after the
guard repair. All 14 changed PHP files pass syntax checks; formatting and diff
checks pass. Scheduler selection and pure date calculation were tested without
executing cron tasks or delivering notifications. Browser interaction and remote
CI were not exercised.

### Saved searches, defaults and alerts

Saved-search owners are nullable Doctrine associations to users; alert parents
are required associations to saved searches. Both have RESTRICT foreign keys.
User purge still deletes private searches and their alerts/default memberships
through model hooks, while public searches retain a NULL owner. A missing owner
cannot grant anonymous ownership or establish a user context for alert execution.
The entity field's legacy `-1` sentinel remains pending explicit modeling; root
entity zero is unchanged.

Existing installations should inspect `db:saved_searches`, apply it with writers
stopped, then run `db:foreign_keys --apply`. The owner migration rejects nonzero
orphans before DDL, converts zero to NULL and supports idempotent retries. The
foreign-key command audits required alert parents before installing constraints.
Fresh installs include the new mappings and constraints.

`SavedSearchRepository` supplies scoped public/private lists, default membership
projection, distinct itemtypes, stale-search and active-alert selection, typed
bulk settings and atomic usage counters. Public-access denial now produces no
public results instead of an unfiltered query. NULL execution dates and strict
date boundaries retain their existing meaning; scheduled statistics refresh does
not increment usage. Failed refreshes use transaction savepoints so PostgreSQL
can continue with other searches. Default selection and alert views use mapped
model reads; default mutations retain lifecycle hooks. The SQL visibility-string
compatibility API remains for existing callers, but does not execute queries.

Coverage is 691 enforced relationships, 71 pending candidates, 62 polymorphic
references and one ambiguous reference. Full ORM/query conversion is unfinished.

The saved-search contract passes on upgraded and fresh PostgreSQL/MariaDB schemas,
including PHP 8.3/MariaDB. It covers entity scoping, public-access denial, private
ownership, default replacement/removal, NULL ownership, user/search purge,
active-alert selection, statistics, enums/booleans, strict date boundaries,
rendered lists/alerts and migration refusal/idempotence. Existing functional
saved-search tests pass under PHP 8.3. Scheduler selection was tested without
executing cron tasks or delivering notifications; rendering is not browser proof.

Broader PostgreSQL/MariaDB validation passes 1,166/768 portability assertions,
complete mapping and parent-purge checks, ORM CRUD for all 355 tables, criteria,
reporting, application and search contracts. The two PHP 8.3 functional classes
pass two methods and 26 assertions; the final default-lookup conversion also
passes its 12-assertion functional method. All 14 changed PHP files pass syntax
and style checks. The audit counts 1,311 remaining legacy SQL call sites.

### Service-level agreements and escalation queues

Eighteen more relationships have mapped Doctrine associations and RESTRICT
foreign keys: SLA/OLA parents, escalation-level parents, level actions and
criteria, both scheduled-ticket relations, and six optional ticket agreement/level
references. Ticket assignments normalize zero to NULL. Required parents reject
invalid references; existing lifecycle hooks delete dependent levels, actions,
criteria and scheduled entries before parents, and clear ticket assignments.
Calendar inheritance (`-1`) remains pending an explicit representation.

For existing installations, inspect `db:service_levels`, apply it with writers
stopped, then run `db:foreign_keys --apply`. The nullable-ticket migration audits
nonzero orphans before DDL and supports idempotent retries; the foreign-key
installer audits all required parents. MySQL DDL commits separately. Fresh
installations include the associations and constraints.

`ServiceLevelRepository` shares typed SLA/OLA queries for first/next levels,
execution times, scheduled entries, ticket agreement lookup and distinct rule
IDs. Active flags and dates have bound types. Next-level lookup requires the
current level to belong to the requested agreement, retains strictly increasing
execution delays, and resolves equal-delay candidates by ID. Scheduled entries
sort by date and ID with NULL dates last on both engines. Due selection uses a
bound clock and excludes NULL and exact-boundary timestamps. Escalation execution
remains in the existing model workflow. Agreement lookup loads the complete model, including its inherited calendar,
and returns boolean success instead of an unconsumed iterator generator.

Agreement/level views, queue lookup/deletion, duplicate-schedule checks and purge
selection use mapped reads. The shared agreement and level classes and both
SLA/OLA level and queue classes contain no direct adapter queries. Coverage is
709 enforced relationships, 53 pending candidates, 62 polymorphic references,
one ambiguous reference and 1,289 legacy SQL call sites. Full conversion remains
unfinished.

The focused service-level contract passes on PostgreSQL and MariaDB, covering
active/inactive levels, strict progression, ties, wrong-parent rejection, TTO/TTR
separation, NULL and boundary dates, row types, mapped views, no-calendar date
calculation, deduplicated rules, agreement/ticket/SLM purges and migration
refusal/idempotence. Scheduler selection is tested without invoking cron bodies
or delivering notifications; PHP rendering is not browser proof.

Fresh installs and the focused contract also pass on both providers, including
PHP 8.3/MariaDB. Five selected PHP 8.3 functional methods pass 418 assertions for
service-level lifecycle, manual assignments, calendar-free calculations, waiting
time and internal TTR. Notifications were disabled and cron tests excluded.
All 24 changed PHP files pass syntax and style checks.

Broader PostgreSQL/MariaDB checks pass 1,184/786 portability assertions, complete
mapping and parent-purge coverage, ORM CRUD across all 355 tables, mapped criteria,
reporting, application workflows and search. Remote CI and browser interaction
were not exercised.

### Explicit service-level calendar policy

SLM calendar choice is now a nullable Calendar association plus the native boolean
`use_ticket_calendar`. NULL with the flag off means 24/7; NULL with the flag on
means use the ticket's entity calendar; a positive calendar reference means use
that fixed calendar. A CHECK constraint rejects conflicting combinations and
negative identifiers, and a RESTRICT FK protects the selected calendar.

SLA and OLA have inherited their effective calendar from SLM since the earlier
service-level split. Their two redundant `calendars_id` columns and obsolete
relation declarations are removed. Mapped agreements follow their required SLM
parent; model reads expose the effective calendar for duration calculations.
Calendar replacement/purge updates the parent once, and every agreement resolves
the result through that parent. Purging a fixed calendar selects 24/7, preserving
the previous zero behavior. Ticket inheritance remains explicit even after an
execution has resolved the calendar for a particular ticket.

The form retains the three existing choices through `calendar_selection`.
Legacy model/API writes using `calendars_id = -1` are translated at input; negative
values are never stored. Canonical API clients can send `use_ticket_calendar`
and a nullable `calendars_id`. Queries should use the explicit flag for inherited
policies; SLM search option 5 exposes it as a boolean. Calendar ownership belongs
to SLM, not to the redundant agreement columns. The agreement form's end-of-day
checkbox now follows the selected duration unit.

Existing installations should inspect and apply `db:service_level_calendars`
with writers stopped before other optional-reference normalization, then apply
`db:foreign_keys --apply`. The migration audits calendar targets and agreement
parents before DDL, converts -1/0 policies, removes redundant columns, and installs
the policy CHECK. PostgreSQL DDL is transactional; MySQL refuses an active
application transaction and supports retries after partially committed DDL.
Fresh installs build the same schema. Tests prove rollback, preflight refusal,
recovery after the first DDL statement, preserved effective parent policy and
idempotence.

`CalendarRepository::isHoliday()` replaces the remaining calendar adapter query
with scoped, typed ORM selection. Recurring dates are compared as month/day in
PHP, including periods crossing New Year; nonrecurring intervals use bound dates.
Both boundaries include the full calendar day. Existing segment methods and the
calendar's per-date result cache remain in place. Calendar has no direct adapter
queries.

The audit now contains 823 relationship candidates after removal of the two
redundant columns: 710 enforced, 50 pending, 62 polymorphic and one ambiguous.
There are 1,288 legacy SQL call sites; the full conversion remains unfinished.

Validation passes on upgraded and fresh PostgreSQL/MariaDB, including the focused
PHP 8.3/MariaDB contract. Coverage includes all three policies, canonical and
legacy input, boolean search, repeated resolution, entity inheritance, calendar
replacement/purge, constrained invalid writes, forms, holiday boundaries and
migration recovery. Twelve PHP 8.3 functional methods pass 577 assertions across
Calendar and selected SLM methods, with notifications disabled and cron methods
excluded. Broader checks pass 1,186 PostgreSQL / 787 MariaDB portability assertions,
full mapping and parent-purge coverage, ORM CRUD for all 355 tables, criteria,
reporting, application workflows, search and service-level contracts. Rendering
was verified in PHP; browser interaction and remote CI were not exercised.

### Project and planning owners and recall scheduling

Projects, project tasks, task templates and external planning events now map their
optional owner as a nullable `User` association. Empty legacy owners become NULL;
nonzero orphan owners stop the migration before DDL. Planning recalls have a
required recipient association. Invalid legacy recall recipients must be repaired
before `db:foreign_keys --apply`; the migration never deletes them automatically.
The schema now enforces five additional user relationships.

For existing installations, run `db:planning_owners --apply` followed by
`db:foreign_keys --apply` during maintenance. Fresh installations and the normal
upgrade sequence include these mappings. The nullable-owner migration is
idempotent and uses the shared audited migration implementation.

User purge clears or explicitly replaces project and event owners through mapped
DQL without replaying workflow hooks. In particular, removing an event owner must
not transfer the event to the operator or reschedule it. Personal recalls are
removed through their model lifecycle, including delivery alerts; they are not
transferred to a replacement user. Ownership access checks require a positive
current-user ID. Ownerless events retain their owner search option, and new events
still default to the logged-in user.

`PlanningRepository` selects due recalls through typed date predicates and a
correlated `NOT EXISTS` delivery-marker query. Rescheduling loads only recalls for
the specified item and changes their mapped timestamp values in one transaction.
Each timestamp uses that recipient's signed seconds offset; repeated rescheduling
always starts from the supplied event start. This removes MySQL date arithmetic
from application code and does not add a provider-specific SQL rewrite. Notification
dispatch remains in the existing model lifecycle.

`tests/database-portability/planning-owners.php` covers owner and group visibility,
per-recipient offsets, repeated rescheduling, discriminator scoping, strict due-date
boundaries, delivery markers, user purge and replacement, search options, and
legacy migration preflight/retry behavior. Tests never execute the recall cron or
send notifications.

Validation passed on PostgreSQL and MariaDB: fresh installation, ownership upgrade,
focused planning contracts, complete mapping and parent-purge checks, ORM CRUD for
all 355 tables, criteria, reporting, application and search contracts. The base
portability suite passed 1191 PostgreSQL and 792 MariaDB assertions. PHP 8.3 also
passed the focused planning contract and four project/planning functional classes
(10 methods, 296 assertions). No browser or notification-dispatch test was run.
The inventory now records 715 enforced references, 45 pending ordinary references,
62 polymorphic references, one ambiguous reference and 1286 legacy SQL call sites.

### OIDC profile persistence and refresh-state ownership

`OidcRepository` now handles local configuration, claim mapping, eligible-user
lookup, profile synchronization, group membership and refresh states through
Doctrine. The OIDC bootstrap check selects only the current user's pending state.
Configuration and mapping address their singleton ID directly. The refresh CLI
uses a typed bulk update, and login/logout configuration paths use the repository.
Authentication with the provider and the existing account-linking policy remain
in the OIDC controller.

The refresh-state entity has a required user association and unique `user_id`.
User purge deletes only that user's state before the FK is enforced. Profile
synchronization finds state by its user association; it cannot overwrite the last
unrelated row returned by the old table scan. User fields and groups are persisted
without manual SQL escaping or `INSERT IGNORE`. Existing membership flags survive
repeat synchronization. The mapping row serializes OIDC group creation, and a user
row lock serializes each user's state changes. Profile and group writes share a
transaction; invalid claims roll back instead of leaving partially updated data.
Email creation still uses `UserEmail` validation and default-address lifecycle.

The `is_activate`, `is_forced`, `sso_link_users` and refresh `update` flags are now
boolean ORM fields and native PostgreSQL booleans. Run
`db:oidc_references --apply` then `db:foreign_keys --apply` during maintenance on an
existing database. The migration audits orphan states, duplicate users, unexpected
singleton IDs, and nonbinary flags before any DDL. MariaDB flags are audited even
when schema introspection identifies the integer storage as boolean. PostgreSQL
DDL is transactional; MySQL DDL is refused inside an application transaction.
Fresh installation and normal upgrades include the schema changes.

`tests/database-portability/oidc.php` checks configuration rendering, mapping,
linking policy, quoted claims and group names, repeated synchronization, unrelated
user-state isolation, email lifecycle, pending refreshes, user purge, invalid-claim
rollback, migration rejection, native booleans and retry behavior. It exercises
only local persistence and never contacts an identity provider or initiates login.

Validation passed on fresh and upgraded PostgreSQL/MariaDB databases. The full
mapping and parent-purge suites, ORM CRUD across all 355 tables, criteria,
reporting, application and search contracts passed. Base portability assertions:
1196 PostgreSQL, 793 MariaDB. PHP 8.3 passed the focused OIDC contract and the Auth
functional suite (3 methods, 117 assertions). Configuration forms were rendered
in PHP; browser interaction and an external provider exchange were not tested.
Group membership deduplication uses resolved IDs so MariaDB's case-insensitive
name matching does not cause duplicate pending inserts.

The inventory now reports 716 enforced references, 44 pending ordinary references,
62 polymorphic references, one ambiguous reference and 1273 legacy SQL call sites.
Full relationship and query conversion remains unfinished.

### Email lifecycle and object-lock ownership

All `UserEmail` address queries and default-address maintenance now use
`UserEmailRepository`. Lookups filter through the mapped user association;
preferred addresses sort by default status and then ID. When deleting a default,
selection preserves an existing default or promotes the oldest surviving address.
Default changes validate the selected address's owner, serialize against the user
row and set both the selected and other addresses explicitly in one transaction.
The public model still performs email validation, history and permission checks.

`ObjectLock.users` is now a required association with a user FK. Existing user
purge removes owned locks through the model before deleting their owner. Existing
installations use `db:foreign_keys --apply`; orphan locks stop its audit and are
not silently reassigned or removed. Fresh installs include the constraint.
`ObjectLockRepository::expired()` selects stale locks with a typed timestamp and
stable ordering. Unlocking and its history remain in the model's cron action.

`tests/database-portability/user-emails-locks.php` covers empty and missing-default
lookups, first-address defaults, quoted email values, owner isolation, model
updates and deletion, legacy default ties, transaction rollback, strict lock-expiry
boundaries, lock-status hydration and user-purge cleanup. It does not execute the
unlock cron or send notifications.

Fresh installs and focused contracts passed on PostgreSQL and MariaDB. Full
mapping/parent-purge, ORM CRUD for all 355 tables, criteria, reporting, application,
search and OIDC contracts also passed. Base portability assertions: 1197 PostgreSQL
and 794 MariaDB. PHP 8.3 passed the focused contract and five selected user lifecycle
functional methods (279 assertions), with notifications disabled. No browser or
cron execution was tested. The inventory is now 717 enforced references, 43 pending
ordinary references, 62 polymorphic references, one ambiguous reference and 1267
legacy SQL call sites. Full conversion remains unfinished.


### Personal content ownership and shared listings

Reminders, reminder translations and RSS feeds now have nullable user associations
and enforced owner FKs. Existing installations use
`db:personal_content_owners --apply` followed by `db:foreign_keys --apply` during
maintenance. The migration audits orphan owners before DDL, converts the legacy
zero sentinel to NULL and supports retries. Fresh installs and normal upgrades
include these changes. User purge still deletes personal reminders through their
model lifecycle; RSS feeds and translation authors are cleared or reassigned
without changing content timestamps or replaying feed-fetch hooks.

`SharedContentRepository` handles personal and public listings, reminder calendar
selection, expiry selection and translation languages through ORM queries. Sharing
uses correlated EXISTS predicates for users, groups, profiles and entities, so
multiple audience rows do not multiply results. Public access requires the public
READ right. Entity recursion, global group/profile audiences and ownerless shared
content are preserved. Duplicate translations select the earliest matching ID.
Anonymous viewers cannot gain ownership through a NULL/false comparison.

Reminder calendar export hydrates sharing data before checking item permissions.
The expiry predicate retains its actual cutoff: the former PHP array repeated the
`end_view_date` key and discarded the age comparison. RSS search also checks public
READ permission explicitly and scopes profile shares through the profile-share
entity column.

`tests/database-portability/personal-content.php` covers audience combinations,
entity boundaries, duplicate shares/translations, date boundaries, actual search,
reminder rendering, user/group calendar export, owner purge and replacement, and
migration preflight/retry behavior. It never executes cleanup cron or contacts an
external feed. Fresh and upgraded PostgreSQL/MariaDB contracts passed, as did the
broader mapping, parent-purge, all-table ORM CRUD, criteria, reporting, application,
search, knowledge-base and planning suites. Base portability assertions: 1200
PostgreSQL and 797 MariaDB. PHP 8.3 also passed the focused contract and the
functional Reminder, ReminderTranslation and RSSFeed tests (7 methods, 100 assertions) using a local RSS fixture server.
No browser interaction or real cron execution was tested.

The inventory records 720 enforced references, 40 pending ordinary references,
62 polymorphic references, one ambiguous reference and 1262 legacy SQL call sites.
Full relationship and query conversion remains unfinished.

### Mail collection state and rejected-email references

Rejected emails now map their collector and requester as nullable associations,
with two additional FKs. A deleted requester or collector leaves a historical log
with a NULL reference; explicit replacement preserves the existing reassignment
policy. Logs without a collector are excluded from retry selection. The upgrade
command is `db:rejected_email_references --apply`, followed by
`db:foreign_keys --apply`. Orphaned nonzero references stop the migration before
DDL; absent zero references become NULL. Fresh installs and normal upgrades
include the change.

`MailCollectorRepository` now supplies collector lists/counts, active and failing
collector selection, selected rejected emails and blacklisted content. Queries use
mapped entities and typed booleans. Empty retry selections return no rows, and
results have deterministic collector/ID ordering. `NotImportedEmail::deleteLog()`
uses an ORM bulk delete, which participates in the caller's transaction instead of
TRUNCATE's implicit commit and identity reset. Collection, mailbox operations,
permissions and notifications remain in their existing lifecycle methods.
The collector's subject still passes through the legacy pre-escaped model input
boundary; removing that encoding requires a separate raw-input lifecycle change.

The focused `tests/database-portability/mail-collection.php` contract covers
selection/counts, content filtering, quoted subjects, empty retry selections,
actual search joins, transactional log deletion, parent purge/replacement, and
migration audit/retry behavior. Fresh and upgraded PostgreSQL and MariaDB runs
passed. The full mapping/parent-purge, all-table ORM CRUD, criteria, reporting,
application, search and personal-content suites passed on both engines. Base
portability assertions: 1202 PostgreSQL and 799 MariaDB. PHP 8.3 passed the focused
contract and three existing local collector functional methods (49 assertions).
Tests did not connect to a mailbox, execute collection cron or send notifications.

The inventory now has 722 enforced references, 38 pending ordinary references,
62 polymorphic references, one ambiguous reference and 1255 legacy SQL call sites.
Full relationship and query conversion remains unfinished.

### LDAP configuration and required replica ownership

`AuthLdapReplicate.authldaps` is now a required association with an enforced FK.
Directory purge deletes its replicas through their model lifecycle; an explicit
replacement reassigns them. Existing installations run `db:foreign_keys --apply`;
invalid existing parent references stop the audit, including zero-valued parents.
The migration does not silently delete or repair those records. Fresh installs
include the constraint.

`LdapRepository` handles directory lists/counts, default lookup and maintenance,
email-import source selection, replica endpoints, known authentication sources,
local synchronization candidates and group identifiers. Flags use typed booleans;
login and DN lookups bind raw values. Import still compares all existing logins,
while synchronization applies the existing source/authentication filter. Candidate
rows are streamed and detached, preserving bounded ORM identity-map usage.

Deleting or replacing a directory now updates users' source IDs through a scoped
ORM update. The former generic user update replayed photo synchronization against
the directory being deleted and also matched unrelated mail/local accounts with
the same numeric source ID. Maintenance now covers LDAP, unauthenticated and
alternate-authentication accounts while leaving local and mail accounts alone.
The shared `users.auths_id` column remains an ambiguous LDAP/mail reference and
still requires a schema redesign; this change does not claim to enforce its FK.

`tests/database-portability/ldap-configuration.php` covers directory/default and
email-import selection, replica forms/endpoints, quoted login and DN values,
entity-scoped groups, import/synchronization candidates, transaction rollback,
replica purge/replacement and authentication-type scoping. Fresh and upgraded
PostgreSQL and MariaDB focused contracts passed. Both engines also passed the
complete mapping/parent-purge, all-table ORM CRUD, criteria, reporting, application,
search and mail-collection suites. Base portability assertions: 1203 PostgreSQL
and 800 MariaDB. PHP 8.3 passed the focused contract and seven existing local LDAP
and replica functional methods (89 assertions). External LDAP authentication and
synchronization were not validated.

The inventory now reports 723 enforced references, 37 pending ordinary references,
62 polymorphic references, one ambiguous reference and 1244 legacy SQL call sites.
Full relationship and query conversion remains unfinished.

### Notification queue queries and template ownership

Email and chat queue entries now have nullable notification-template associations
and two additional FKs. Existing installations use
`db:queue_template_references --apply` followed by `db:foreign_keys --apply` during
maintenance. Both queues are audited for orphaned references before any DDL;
legacy zero references become NULL. Fresh installs and normal upgrades include
the migration.

Template purge retains the existing cancellation lifecycle and clears the template
association on retained queue history. Pending entries become deleted, their
rendered payload stays available, and unrelated templates' deliveries are untouched.
The mailing enqueue functional test now expects NULL for a missing template.
The chat history form displays its stored ticket title as escaped text, removing
a copied email-body field that does not exist in the chat schema.

`NotificationQueueRepository` replaces duplicate selection, pending selection and
expiry deletion for both queues. Due-date comparisons use typed timestamps and
boolean flags, with stable send-time/ID ordering. The public API still applies
channel enablement, cron eligibility, per-channel limits and additional filters.
Those filters cannot override the base pending/mode/date predicates. Nonpositive
limits preserve the legacy unlimited behavior. Expiry deletes only already-deleted
entries older than the cutoff and participates in the caller's transaction.
Deduplication retains the existing item/entity/template and recipient policy.

The focused `tests/database-portability/notification-queues.php` contract covers
both queue types, cutoff/NULL boundaries, stable limits, channel policy, extra
filters, deduplication, cleanup rollback, template purge, retained-history forms,
and migration audit/retry across both tables. Fresh and upgraded PostgreSQL and
MariaDB contracts passed. Both engines passed full mapping/parent-purge,
all-table ORM CRUD, criteria, reporting, application, search and LDAP contracts.
Base portability assertions: 1205 PostgreSQL and 802 MariaDB. PHP 8.3 passed the
focused contract, template cloning and enqueue-only functional methods
(18 functional assertions). No notification dispatch or cron body was run;
form rendering was checked in PHP, without browser interaction.

The inventory records 725 enforced references, 35 pending ordinary references,
62 polymorphic references, one ambiguous reference and 1238 legacy SQL call sites.
Full relationship and query conversion remains unfinished.

### External-link queries and required definition ownership

Link/item-type bindings now map their definition as a required Doctrine association
with a restrictive FK. Existing installations apply it through
`db:foreign_keys --apply`; orphaned bindings must be repaired before that audited
operation proceeds. Fresh installs include the constraint. Definition purge still
runs the existing child lifecycle, removing only its own bindings.

`LinkRepository` supplies visible definitions and counts, associated types, plugin
binding cleanup, domain tags and IP/MAC inventory projections. Definition lists and
tab counts share the same entity/recursive visibility predicate. Domain selection
is deterministic by ID. Network joins retain both polymorphic discriminators, and
MAC grouping selects a deterministic minimum port ID instead of relying on MySQL's
permissive grouping. Equipment-level IP tags no longer substitute the missing
legacy equipment MAC field; MAC-only links use the equipment's network ports.
`Link::getLinksDataForItem()` now returns rows as an array; core consumers use
`foreach` and `count`.

The focused `tests/database-portability/external-links.php` contract covers entity
visibility, recursive links, counts, association and item rendering, domain and
network tags, duplicate/unnamed ports, plugin binding cleanup and parent purge.
It passed on fresh and upgraded PostgreSQL and MariaDB installations. PHP 8.3 also
passed the focused contract and the existing Link functional method (95 assertions).
Both engines also passed complete mapping/parent-purge, all-table ORM CRUD,
criteria, reporting, application, search and network-name contracts. Base
portability assertions: 1206 PostgreSQL and 803 MariaDB. Generated external URLs
were not opened; rendering was checked in PHP.

The inventory now records 726 enforced references, 34 pending ordinary references,
62 polymorphic references, one ambiguous reference and 1231 legacy SQL call sites.
Full relationship and query conversion remains unfinished.

### Year and contract asset reports

Core year and contract reports now execute mapped queries through
`AssetContractReportRepository`. The shared repository joins each concrete asset
mapping to contract bindings, contract type, entity, location and optional
financial data. It uses typed date ranges and booleans, deterministic ordering,
and scalar report rows instead of hydrating complete asset graphs. Empty entity
scope grants no rows; unrestricted scope must be supplied explicitly.

The existing report distinctions remain: year reports retain uncontracted assets,
contract reports require a binding, and multiple contracts produce multiple rows.
Projects and software licenses keep their historical financial, template and
deleted-flag policies. Report input validation rejects unknown/non-string item
types and invalid years, deduplicates selections, and retains the all-types and
all-years controls. Unknown selections no longer reuse the previous item's query.
Unmapped plugin types still use their existing compatibility queries in these
entry points; their conversion remains outstanding.

`tests/database-portability/asset-contract-reports.php` covers every configured
core contract type, every core year-report type, multi-contract rows, date
boundaries, mixed years, entity scope, NULL projections and special project/license
behavior. It passed on PostgreSQL and MariaDB, as did the broader reporting
contract. The focused test also passed under PHP 8.3.

For HTTP validation, seed a fresh disposable `itsm_port_*` installation once with
`php tests/database-portability/seed-report-web.php /path/to/test-config`, run its
local test server, then use `python3 tests/database-portability/web-reports.py URL
--log-dir /path/to/test-files/_log --asset-fixtures`. The seed intentionally
persists in that disposable database and refuses a duplicate seed. Both engines
passed the full report HTTP smoke test and the added fixture checks: visible and
deleted rows present, templates and other entities excluded, uncontracted rows
present only in the year report. This checks HTTP output, not browser layout. Login still emits the existing
`glpiextauth` session-key warning in `User`; no report SQL errors or fatal errors
were recorded.

FK inventory remains 726 enforced, 34 pending ordinary, 62 polymorphic and one
ambiguous reference. The static legacy-call inventory remains 1231 because it
also counts the retained unmapped-plugin branches; core execution of these two
reports now uses ORM queries.

### Virtual-machine host ownership and inventory views

Virtual-machine inventory entries now map their computer as a required Doctrine
association with a restrictive FK. Existing installations apply the audited
`db:foreign_keys --apply` operation; missing or zero host references must be
repaired first. Fresh installs include the constraint. Computer purge retains the
existing VM child lifecycle and removes both active and deleted child records;
reassigned and unrelated VM entries remain intact.

`InventoryRepository` now supplies the active VM list/count and UUID host lookup.
Host selection joins the mapped computer, applies the current entity scope,
excludes deleted VM records and deleted/template hosts, and returns each host once.
The existing UUID normalization rules remain in use. Both directions of the
inventory view enforce computer read permission before rendering a matched
computer's name, so a UUID match cannot reveal an inaccessible host or guest.

`tests/database-portability/virtual-machines.php` covers case-insensitive matching,
duplicate UUID records, entity/flag exclusions, both rendered views, required-host
creation, reassignment, soft delete/restore and parent purge. The focused contract
passed on fresh and upgraded PostgreSQL and MariaDB installations. PHP 8.3 passed
the focused contract and the existing VM functional method (19 assertions).
Both engines also passed complete mapping/parent-purge, all-table ORM CRUD,
criteria, reporting, application, search, inventory-metadata migration and asset
year/contract report suites. Base portability assertions: 1207 PostgreSQL and
804 MariaDB. Rendering checks execute PHP; no browser interaction or external
hypervisor is involved.

The inventory records 727 enforced references, 33 pending ordinary references,
62 polymorphic references, one ambiguous reference and 1231 direct legacy SQL call
sites. The static SQL count does not include the legacy table-helper calls removed
from these VM views. Full relationship and query conversion remains unfinished.

### Legacy PCI network-card model metadata

PCI devices contain two separate model fields: the active `devicepcimodels_id`
association and the older `devicenetworkcardmodels_id` column added by the 9.2.3
upgrade. The latter is now a nullable Doctrine association to network-card models
with a restrictive FK. Its values are preserved independently of the active PCI
model; the migration does not rename, reinterpret or discard legacy identifiers.

Existing installations use `db:legacy_component_models --apply`, followed by
`db:foreign_keys --apply`. The migration audits nonzero references before DDL,
normalizes zero to NULL, and supports idempotent retry. It is also included in the
normal upgrade path; fresh schemas include the nullable mapping and constraint.
Model replacement/purge now maintains this previously missing lifecycle relation,
leaving the PCI device, its actual model and installed-component associations
intact.

`tests/database-portability/legacy-component-models.php` verifies independent model
identities, replacement/purge scoping, installed-component preservation, legacy
zero writes/criteria, actual PCI-model search, migration planning, orphan refusal,
valid-value preservation and retry. The focused contract passed on fresh and
upgraded PostgreSQL and MariaDB databases, and also under PHP 8.3. The PHP 8.3
network-card functional method passed with 12 assertions. Both engines passed
complete mapping/parent-purge, all-table ORM CRUD, criteria, reporting, application,
search, all 17 component-association types and optional-model migration contracts.
Base portability assertions: 1208 PostgreSQL and 805 MariaDB.

The inventory records 728 enforced references, 32 pending ordinary references,
62 polymorphic references, one ambiguous reference and 1231 legacy SQL call sites.
This change expands mapped relationship coverage; full relationship and runtime
query conversion remains unfinished.


### Document queries and optional ticket ownership

Document content lookup, extension and icon selection, category selection,
attachment permissions and orphan selection now use Doctrine repositories.
Reminder and knowledge-base attachments reuse their respective audience queries;
ITIL attachments retain parent read checks, child-type discrimination and private
followup/task author rights. File-retention counts also use mapped reads. The
cleanup selector is separate from the existing document purge lifecycle.

`Document.tickets` is now a nullable association with a restrictive foreign key.
Run `db:document_ticket_references --apply`, then `db:foreign_keys --apply` on
existing installations during maintenance. The normal upgrade path includes the
migration, and fresh installs include the mapping and constraint. Migration
planning is read-only; nonzero orphans block changes before DDL, zero becomes
NULL, valid ticket IDs survive, and retries are idempotent. Ticket replacement
reassigns the origin; ticket purge clears it while retaining the document.

`tests/database-portability/documents.php` covers entity scope, content hashes,
case-insensitive extensions, configured regex extensions, upload permission,
category exclusions, ITIL child attachment permissions, reminder ownership,
public FAQ scope, ticket lifecycle and the legacy-data migration. It tests orphan
selection without executing cleanup. Fresh and upgraded PostgreSQL and MariaDB
passed this contract. The seven selected PHP 8.3 Document functional methods
passed with 414 assertions; the cron test was excluded. PHP 8.3 also passed the
focused MariaDB contract and syntax checks. The PHP 8.3 test container has no
PostgreSQL driver; PostgreSQL contracts ran on host PHP 8.5. Both engines passed
complete mapping/parent-purge, all-table ORM CRUD, criteria, reporting, application,
search, personal-content, knowledge-base and content-metadata contracts. Base
portability assertions: 1209 PostgreSQL and 806 MariaDB.

The static inventory records 729 enforced references, 31 pending ordinary
references, 62 polymorphic references, one ambiguous reference and 1221 legacy
SQL call sites. All 355 core tables remain mapped. This inventory is not proof
that every relationship or runtime SQL path has been converted.


### ITIL origins, task lists and planning

Followup merge sources, promoted tickets, task merge sources and solution followups
now have nullable Doctrine associations with restrictive foreign keys. These are
historical references, separate from each child's actual parent. Replacing a
source ticket reassigns the origin; purging it clears the origin without deleting
the copied followup or task. Purging a referenced followup retains the solution.
The ticket lifecycle declarations now cover the previously missing origin fields.
`ITILOriginRepository` maintains those historical associations without replaying
content-update hooks or modifying task/followup timestamps.

Existing installations run `db:itil_origin_references --apply`, followed by
`db:foreign_keys --apply`, during maintenance. The normal upgrade path runs the
migration; fresh schemas include all four constraints. Nonzero orphans block the
migration before DDL. Zero becomes NULL, valid identifiers are preserved, and
retries are idempotent.

`ITILTaskRepository` supplies Ticket, Problem and Change task lists, calendar
selection and planning. Queries use mapped parent/technician/group associations,
entity and central-profile scope, typed dates and booleans, and Doctrine's portable
date subtraction for unplanned task intervals. Stable sorting makes equal-date
pagination deterministic. Existing per-item read checks still control generated
planning events and VCalendars. Group arrays produce scalar planning event keys.
`getTaskList()` now returns rows as an array; its homepage consumer uses those rows
without a database-specific iterator. Plugin callers using `next()` must switch
to array iteration. Solution counts, ticket status lookup and
followup promotion lookup also use mapped queries.

`tests/database-portability/itil-tasks.php` covers all three task types, actor and
entity selection, pagination, empty groups, deleted parents, planned/unplanned
intervals, open states, central-profile membership, solution counts, historical
origin replacement/purge and all four migration columns. The existing ticket
merge fixture now creates its supplier instead of assuming ID 2 exists.

Fresh and upgraded PostgreSQL and MariaDB installations passed the focused contract, including
actual planning-event generation. PHP 8.3 passed the focused MariaDB contract,
syntax checks and seven existing functional methods (225 assertions), covering
homepage tasks, planning conflicts, solutions and ticket merge. PostgreSQL tests
ran on host PHP 8.5; the PHP 8.3 container has no PostgreSQL driver. These tests
exercise local model/rendering behavior, not browser interaction or external
calendar services. Both engines passed complete mapping/parent-purge, all-table
ORM CRUD, criteria, reporting, application, search, ITIL-user, ITIL-classification
and document suites. Base portability assertions: 1213 PostgreSQL and 810 MariaDB.

The static inventory records 733 enforced references, 27 pending ordinary
references, 62 polymorphic references, one ambiguous reference and 1216 legacy SQL
call sites. Full relationship and runtime query conversion remains unfinished.


### Impact graph associations, queries and native flags

Impact items now map their compound group (`parent_id`) and saved context
(`impactcontexts_id`) as nullable associations with restrictive foreign keys.
Direct context/compound replacement or purge maintains their children. Existing
installations run `db:impact_graph_references --apply`, then
`db:foreign_keys --apply`, during maintenance; the normal upgrade path includes
the migration and fresh schemas include the constraints.

The same migration audits `is_slave`, `show_depends` and `show_impact` and converts
them to native PostgreSQL booleans. MySQL retains its binary tinyint representation.
Both providers reject non-binary values and nonzero orphan references before DDL;
zero references become NULL, valid values survive, and retries are idempotent.
PostgreSQL applies reference and flag changes transactionally. Legacy model rows
still expose flags as 0/1; graph JSON represents absent parent/context IDs as 0.

`ImpactRepository` supplies node/edge lookup, relation counts, graph traversal
queries, asset search and transactional cleanup. Search uses bounded pages with
stable ordering, entity/flag exclusions, configured user-name ordering and a
shared project audience predicate. Multiple project-team memberships do not
multiply results. The AJAX filter now uses input without SQL pre-escaping before
binding; quotes, literal backslashes and Unicode round-trip. Core impact queries
no longer call the legacy SQL adapter.

Cleanup fixes an inverted owner/slave condition: deleting a slave preserves the
owner's shared context; deleting the owner clears other nodes' references before
removing the context. Undersized compound groups are dissolved while surviving
nodes remain. The polymorphic asset identities on nodes and edges still require
separate relationship work.

`tests/database-portability/impact-graph.php` covers every configured core asset
mapping, permissions, pagination, name matching, project audiences, duplicate
edges, owner/slave cleanup, group dissolution, direct compound purge, context replacement/purge,
native flag values and upgrade refusal/preservation/retry. Fresh and upgraded
PostgreSQL and MariaDB passed the focused contract. The existing impact functional
classes passed under PHP 8.3: 20 methods, 247 assertions. PHP 8.3 also passed
the focused MariaDB contract and syntax checks; PostgreSQL tests ran on host
PHP 8.5 because the PHP 8.3 container has no PostgreSQL driver. Both providers
passed complete mapping/parent-purge, all-table ORM CRUD, criteria, reporting,
application, search and project-planning contracts. Base portability assertions:
1218 PostgreSQL and 812 MariaDB. Tests execute local models and graph data, not
browser visualization or external services.

The inventory records 735 enforced references, 25 pending ordinary references,
62 polymorphic references, one ambiguous reference and 1204 legacy SQL call sites.
Full relationship and runtime query conversion remains unfinished.


### Kanban state ownership and mapped persistence

Kanban state now maps its optional user owner as a Doctrine association with a
restrictive foreign key. NULL identifies shared state; the generated `owner_key`
normalizes it to zero only inside the unique board/owner index. This preserves
one shared row per board on both providers without a synthetic user record.
Deleting or replacing a user removes their private Kanban state instead of
promoting it to shared state or overwriting the replacement user's preferences.
Project purge still removes its board state.

Existing installations run `db:kanban_ownership --apply`, followed by
`db:foreign_keys --apply`, during maintenance. The standard upgrade path includes
the migration; fresh installs include the generated column and constraint.
Migration audits reject nonzero orphan owners and duplicate normalized identities
before DDL. Zero owners become NULL and retries are idempotent. PostgreSQL applies
DDL and normalization transactionally; MySQL DDL requires stopped writers and
runs outside application transactions.

`KanbanRepository` loads and saves mapped entities, retaining state preparation
hooks, shared/private selection, creation dates and timestamp-based polling.
Concurrent initial saves recover from a uniqueness conflict using a mapped update
on the winning row, including when both requests contain identical state. The
AJAX boundary retains HTML sanitization but no longer SQL-escapes values before
ORM binding. Non-array states are rejected. Moving the first column now works;
the old truthiness check treated its zero-based position as absent.

The polymorphic board identity remains a separate relationship conversion.

Validation: `tests/database-portability/kanban.php` passes on fresh and upgraded
PostgreSQL and MariaDB, covering state operations, owner lifecycle, uniqueness,
concurrent first saves with two physical connections, and migration
refusal/preservation/retry. `web-kanban.py` passes authenticated save/load,
column and card actions on both providers, including literal quotes, backslashes,
Unicode and HTML sanitization. This is HTTP proof, not browser interaction.
PHP 8.3 passes the MariaDB contract, syntax checks and the existing Project
functional class (three methods, 146 assertions). PostgreSQL tests use host PHP
8.5; the PHP 8.3 container has no PostgreSQL driver. Both providers also pass
mapping/parent-purge, CRUD for all 355 tables, criteria, reporting, application,
search and project-planning contracts (1219 PostgreSQL / 813 MariaDB base
portability assertions).

The static inventory now records 736 enforced references, 24 pending ordinary
references, 62 polymorphic references, one ambiguous reference and 1201 legacy
SQL call sites. Full relationship and runtime query conversion remains unfinished.


### Display preference ownership and ORM queries

Display preferences now map their user owner with a restrictive foreign key.
Default columns have a NULL owner; the generated `owner_key` preserves unique
(owner, item type, column) identities for defaults as well as personal lists.
The new `SharedOwnerUniqueness` schema helper serves both display preferences and
Kanban state. User purge continues to delete private preferences, including when
replacing a user, without changing defaults or the replacement user's settings.

Existing installations run `db:display_preference_ownership --apply`, followed
by `db:foreign_keys --apply`, during maintenance. The normal upgrade path and
fresh schemas include this change. The migration audits orphan owners and
normalized duplicate identities before DDL, converts zero owners to NULL, and
supports idempotent retries. PostgreSQL applies the migration transactionally;
MySQL DDL requires stopped writers and runs outside application transactions.

`DisplayPreferenceRepository` supplies column selection, ranks, per-owner lists,
activation, ordering and grouped counts. Personal columns replace the entire
default list; the absence of a personal list falls back to defaults. Tied ranks
sort deterministically by ID. Reordering locks the selected owner/type rows,
renumbers them and flushes within one transaction. Invalid directions, missing
rows and moves beyond either end leave the list unchanged. Activation locks the
user, copies defaults atomically, and preserves an existing personal list.
Fallback activation selects a displayable numeric search field instead of
coercing a search-option group heading to column zero.

The form controller verifies owner rights and the selected row's owner and item
type before changing it. Personal rights apply to the signed-in user's list;
default-list changes require the general display-preference right. A forged ID
cannot reorder or purge another user's preferences or a different item type.
All display-preference runtime queries and rank updates now use ORM mappings.

The newer modal endpoint (`ajax/v2/displaypreferences.php`) uses the same mapped
repository. Mutating operations lock the user for personal settings, or the real
root entity for application-wide defaults, before reading the list; this also
serializes saves for an initially empty default list. Its complete-list save updates retained records in place, removes
unselected columns and inserts new ones in one transaction. Locked columns are
included and existing `noremove` columns survive. Personal saves still require
explicit activation; deleting the personal list restores default-column lookup.
Injected failures verify that partial inserts and rank changes are rolled back.
Legacy upgrade helpers in `inc/migration.class.php` remain separate conversion
work; the application preference editors no longer execute adapter SQL.

`tests/database-portability/display-preferences.php` covers default/personal
selection, escaped input, activation/fallback, rights and scope, stable ordering,
bulk replacement, protected columns, mid-operation rollback, owner purge, form
rendering and migration refusal/preservation/retry. Fresh and upgraded PostgreSQL
and MariaDB pass this contract. The shared Kanban migration and concurrency
contract also passes on both providers. Authenticated HTTP tests exercise both
the legacy form and modal endpoint, including forged owner/type requests,
bulk ordering, activation, deletion and fallback. This does not constitute
browser interaction proof.

PHP 8.3 passes the MariaDB contract, changed-file syntax checks and four existing
Search functional methods (330 assertions). PostgreSQL checks use host PHP 8.5;
the PHP 8.3 container lacks a PostgreSQL driver. Both engines pass mapping and
parent-purge checks, ORM CRUD across all 355 tables, criteria, reporting,
application, search and project-planning contracts. Base portability assertions:
1220 PostgreSQL and 814 MariaDB.

The inventory records 737 enforced references, 23 pending ordinary references,
62 polymorphic references, one ambiguous reference and 1188 legacy SQL call sites.
Full relationship coverage and conversion of all runtime queries remain unfinished.

### Dashboard ownership and ORM selection

Dashboards now use their generated numeric ID as the ORM and database primary
key. Nullable user and profile associations replace zero owner sentinels and have
restrictive foreign keys. Two generated keys preserve uniqueness for every
combination of personal/shared and profile/global scope. Deleting a user or
profile removes its dashboards, including during replacement, without promoting
personal content to shared content or overwriting a replacement owner's layout.

`DashboardRepository` selects the active user's dashboard through mapped DQL.
Selection prefers a personal dashboard for the active profile, an unscoped
personal dashboard, the active profile's shared dashboard, then the global
default. Other profiles' dashboards no longer become arbitrary fallbacks.
Anonymous lookup returns no dashboard. The application still uses numeric IDs
and scalar owner fields at its legacy model boundary.

Existing installations run `db:dashboard_ownership --apply`, then
`db:foreign_keys --apply`, with application writers stopped. The migration is
also part of the normal upgrade path; fresh installs include the new schema.
Orphan and normalized-duplicate audits precede schema changes. The migration
preserves IDs and content, normalizes zero owners to NULL, and restores MySQL
AUTO_INCREMENT after replacing the composite primary key. PostgreSQL applies
the migration transactionally; MySQL DDL runs outside application transactions.
Retries support both a completed migration and a missing owner uniqueness index.

The mapping driver's `ReferenceKey` attribute generates expressions using the
active provider's identifier quoting. This supports the legacy mixed-case owner
columns in ORM-generated DDL. Foreign-key generation and nullable-reference
normalization also quote these identifiers consistently.

`tests/database-portability/dashboards.php` covers scope precedence, mapped
selection and writes, all four uniqueness combinations, orphan rejection,
user/profile purge with replacement, executable ORM schema DDL, generated IDs,
and migration audit/preservation/retry. It passes on fresh and upgraded
PostgreSQL and MariaDB. PHP 8.3 passes the fresh MariaDB contract and changed-file
syntax checks; the existing Profile functional suite passes four methods and
94 assertions. PostgreSQL uses host PHP 8.5 because the PHP 8.3 container lacks
its driver. These checks exercise database and model behavior, not browser flows.

Both providers also pass mapping and parent-purge checks, ORM CRUD across all
355 tables, criteria, reporting, application, search, project-planning, display
preference and Kanban contracts. The base portability suite passes 1222 assertions
on PostgreSQL and 816 on MariaDB, including orphan rejection for every registered
foreign key.

The static inventory records 739 enforced references, 21 pending ordinary
references, 62 polymorphic references, one ambiguous reference and 1186 legacy
SQL call sites. Full relationship coverage and runtime query conversion remain
unfinished.

### History queries and retention through ORM

`HistoryRepository` now handles history insertion, scoped counts and pages,
distinct filter values, item cleanup and retention deletion. The `Log` and
`PurgeLogs` runtime classes no longer issue adapter SQL. History writes retain
the existing escaped-change boundary and Unicode truncation, then bind raw
values through the mapping. Actor names and literal `NULL` strings are preserved;
the generated history ID still updates `glpi_maxhistory`.

History pages bind item type and ID independently of optional filters. Sorting
uses an ID tie breaker and explicit NULL ordering for consistent pages across
providers. Facets group their selected fields and order by the latest matching
ID, replacing nonportable DISTINCT/GROUP BY queries ordered by an unselected ID.
Counts and pages use the same structured filters. The HTTP endpoint decodes the
original JSON request rather than its legacy SQL-escaped copy, fixing silently
ignored filters containing JSON syntax and preserving names with backslashes.
History query filters now accept unescaped values.

Retention runs mapped bulk deletes without loading every log into memory. A typed
cutoff replaces MySQL date expressions and clamps calendar-month subtraction at
month ends, including leap years. All existing per-category retention settings
remain in effect. KEEP_ALL and invalid settings delete nothing; DELETE_ALL also
removes undated rows; dated policies retain them. `getDateModRestriction()` now
returns typed criteria, an empty array for DELETE_ALL, or false when disabled;
callers must compare explicitly with false.

The history contract passes on PostgreSQL and MariaDB, including fresh schemas,
literal values, scoped and tied pagination, NULL ordering, compound filters,
facets, application rendering, calendar boundaries and every retention setting.
Retention tests protect neighboring rows that differ in each individual scope
field. They call retention helpers inside rolled-back fixture transactions,
not cron bodies. Authenticated HTTP checks pass on both providers for pagination,
filtering and escaped HTML output; this is not browser interaction proof.
PHP 8.3 passes the MariaDB history contract and changed-file syntax checks.
The existing Log functional suite passes seven methods and 600 assertions.
Both providers also pass mappings and parent purges, application workflows,
reporting, search, project planning and cron-log contracts.

The static inventory records 1167 remaining legacy SQL call sites, down by 19.
Foreign-key coverage is unchanged: 739 enforced references, 21 pending ordinary
references, 62 polymorphic references and one ambiguous reference. History's
polymorphic subject still needs a design that accounts for historical records;
this query conversion does not claim to enforce that relationship.

### Content audience entity scopes

The six group/profile audience tables for knowledge-base articles, reminders and
RSS feeds now map their entity scopes as nullable Entity associations with
RESTRICT foreign keys. NULL represents an unrestricted audience. Entity ID zero
remains the real root entity, including its existing recursive scope semantics.
Legacy model inputs and record criteria accept negative unrestricted values and
normalize them at the compatibility boundary; raw ORM writers use NULL.

Audience lookups use mapped repositories. Both mapped content listings and legacy
search visibility predicates recognize NULL scopes. Entity purge keeps a specific
scope by moving it to the replacement entity or root, rather than broadening its
audience to unrestricted access.

Fresh schemas include these associations. Existing installations use
`db:content_audience_scopes --apply`, followed by `db:foreign_keys --apply`, with
application writers stopped. The normal application upgrade invokes the same
migration. It audits every table before changing any schema, preserves root zero,
normalizes all negative scopes and supports idempotent retries. MySQL DDL commits
separately. A nonnegative orphan blocks migration and requires explicit repair.

The dedicated contract passes on fresh and upgraded PostgreSQL and MariaDB
schemas, including ORM lookups, model and repository visibility, root recursion,
entity replacement/purge, database rejection of dangling scopes, late-table
orphan detection and migration retries. MariaDB also passes the contract under
PHP 8.3. Knowledge-base purge and Reminder functional tests pass 75 assertions.
Both providers pass the existing reporting, search, application, ORM CRUD,
criteria, project planning, personal-content and knowledge-base contracts.
Display-preference and Kanban migration regressions also pass on both providers.
These checks provide database and application-model proof, without browser proof.

The inventory now records 745 enforced references, 15 pending ordinary references,
62 polymorphic references, one ambiguous reference and 1161 remaining legacy SQL
call sites. This batch adds six entity foreign keys and removes six legacy query
calls; it does not complete the database-wide conversion.

### Mapped monthly ITIL statistics

`Stat::constructEntryValues()` delegates to `ITILStatisticsRepository` for Ticket,
Problem and Change. Twelve metrics use mapped parent, actor, task, solution,
satisfaction, computer, operating-system and component records. `YEAR_MONTH` is a
small platform-aware DQL function; aggregate queries remain in the database.
The previous 600-line SQL assembly no longer runs through the legacy adapter.

Relationship filters use EXISTS, so several matching actors, groups, solutions or
assets cannot multiply a parent's contribution to an average. Non-task metrics
count each parent once, including filters on task authors. The technician-task
action-time metric deliberately averages individual matching tasks with positive
durations. Existing closed/solved status rules and deletion/entity filters remain
in effect. NULL selections retain legacy zero inputs for optional associations;
root entity zero remains a real reference. Solution and asset filters include
their item-type discriminator, and computer classifications exclude templates.
Component statistics now use the actual mapped item/component association.

Date bounds are validated and bound as datetimes. A date-only end includes its
whole day and excludes the following midnight; a timestamp end is exact and
inclusive. This corrects the former extra-day inclusion. Fully bounded series
fill missing calendar months with zero; open bounds return populated months.
An empty entity scope matches nothing, and NULL explicitly requests an
unrestricted entity scope. Invalid dates throw rather than silently removing a
filter. Reversed dates and unknown metrics return an empty series.

The optional extension argument accepts mapped `WHERE` criteria. Arbitrary
SELECT/JOIN/SQL extensions require a dedicated mapped repository query and now
fail explicitly. No core caller supplies those extensions.

The numeric statistics contract passes on fresh and upgraded PostgreSQL and
MariaDB schemas and on PHP 8.3 with MariaDB. It covers all metrics, supported
dimensions for all three parent types, relationship fan-out, NULL selections,
root scope, tree selection, satisfaction, templates, date boundaries, empty
months and export data. Existing reporting, ORM mappings, criteria and search
regressions pass on both providers. These checks do not provide browser proof.
CI runs the new statistics and content-audience migration contracts on both
database providers; remote CI has not been run for these local commits.

The inventory remains at 745 enforced relationships, 15 pending ordinary
relationships, 62 polymorphic relationships and one ambiguous relationship.
It records 1160 remaining legacy SQL call sites. Monthly reporting removes one
adapter execution site and its large query construction path; statistics option
lists and several other report paths still need conversion.

### Implicit IP-network hierarchy and membership

IPNetwork now maps its parent as a nullable self association with a RESTRICT
foreign key. A root has SQL NULL; legacy zero model inputs and record criteria
retain their empty-parent meaning. Fresh schemas include the relationship.
Existing installations use `db:ipnetwork_parents --apply`, then
`db:foreign_keys --apply`, with application writers stopped. Normal upgrades run
the same migration. Orphans, parent cycles and self cycles block the migration
before DDL; valid parents survive, roots normalize to NULL and retries are empty.
The existing explicit-tree migration shares the same cycle audit.

Implicit tree adoption and reparenting use mapped repository queries. Subnet
changes detach former children even when the new range contains no child, using
the original parent from the change set. Purging a network promotes children to
the surviving parent through structural model updates that keep tree/history
hooks and do not revalidate unchanged CIDR input. This also lets malformed legacy
rows shed their deleted parent reference. Children become roots if no ancestor
survives. Descendant caches are invalidated for whole affected
ancestor paths; removing only the moved node left stale descendant memberships.
The CIDR form field is parsed into mapped address/mask fields and no longer
reaches physical persistence as an unmapped column.

`IPNetwork::recreateTree()` resets and rebuilds the hierarchy in one transaction.
An invalid node aborts and rolls back every parent and derived-field update;
both successful and failed rebuilds discard affected external caches. Run this
maintenance operation with network writers stopped. IPv4/IPv6 address membership
uses mapped bitwise predicates, retains its existing independence from visibility
scope and preserves relation model hooks when links are rebuilt.

Shared configuration lookups encountered during this lifecycle now use
`ConfigurationRepository`. Contexts, names and values are literal strings,
including the string NULL, while nullable values remain NULL. Results retain the
existing associative-array boundary and are ordered by record ID.

The network contract passes on fresh and upgraded PostgreSQL and MariaDB schemas,
including PHP 8.3 with MariaDB. It covers adoption, nearest parent, both address
families, entity boundaries, memberships, subnet moves, internal/root purge,
warm caches, malformed legacy subtree promotion, FK rejection, rebuild
repair/rollback and migration audits/retries.
Runtime domain operations bypass the adapter after catalogue discovery. Both
providers pass explicit-tree lifecycle/migration, network-search, entity purge,
all-table ORM write, complete mapping/parent-purge, application and reporting
regressions. The existing configuration
getter/setter functional tests pass two methods and 20 assertions. CI includes
the new contract; remote CI and browser interaction have not been verified.

Fresh installation also completes with the final code on both providers. The
installer emitted legacy encryption-key migration queries against tables
before schema creation at this checkpoint. The subsequent installation batch
below removes that CLI path; existing-key rotation still needs conversion.

The inventory records 746 enforced references, 14 pending ordinary references,
62 polymorphic references, one ambiguous reference and 1151 legacy SQL call
sites. This batch adds the network-parent FK and removes nine adapter call sites.

### Hardware statistics pagination

`Stat::showItems()` uses `TicketAssetStatisticsRepository` to aggregate mapped
ticket/item associations. Both the group count and requested page run through
Doctrine ORM. Its grouped-count output walker preserves the provider's grouping
and collation semantics. Rows sort by ticket count, item type and item ID for
stable ties; SQL applies the requested offset and limit. This fixes the old
iterator path, which repeated the first page for every offset. Export-all resets
the offset and removes the limit. Entity labels are fetched through ORM only
for entities represented in the returned page, instead of loading every entity.

The report retains its ticket-entity scope and inclusion of deleted tickets.
Root entity zero is real, an empty scope matches nothing and NULL explicitly
means unrestricted scope. Item type and ID jointly identify each group, so a
computer and printer with the same ID remain distinct. Dates are validated and
bound; date-only ends include the entire day, while timestamp bounds are exact.
Invalid dates fail explicitly and reversed intervals return no rows. Unresolved
polymorphic item references still contribute to the total and cannot render an
item row; replacing those references remains part of the broader schema work.

The hardware contract verifies numeric counts, scopes, boundaries, stable ties,
offsets, grouped-count collation behavior, rendered page two, entity labels and
page/all-row CSV exports. It passes on fresh and upgraded PostgreSQL and MariaDB
schemas, including PHP 8.3 with MariaDB; reporting and monthly-statistics
regressions pass on both engines. It verifies zero legacy adapter execution
after catalogue discovery for report rendering. CI includes the contract. Local
database/HTML checks do
not establish live browser or remote CI proof.

This removes one direct adapter call site, leaving 1150 in the inventory. FK
coverage remains at 746 enforced references and 14 pending ordinary references,
plus 62 polymorphic references and one ambiguous reference.

### Shared installation schema and ORM initial data

The CLI database configurator and MySQL/MariaDB installer now use DBAL server
connections and schema catalogue APIs instead of raw mysqli connections and
queries. Database creation quotes the database name, and an existing database
does not require global CREATE privileges. Catalogue checks use a literal
`glpi_` prefix instead of SQL wildcard interpolation. TCP and Unix socket server
endpoints remain supported. PostgreSQL databases remain provisioned separately;
their installation still requires an empty schema, including with `--force`.

Both providers now create the shared DBAL baseline schema. MariaDB/MySQL no
longer execute the old dump directly, which created obsolete non-null columns
incompatible with current ORM associations. Its forced reset still replaces
core tables, preserves unrelated tables and restores the original
FOREIGN_KEY_CHECKS session value. MySQL DDL commits independently, so a failed
installation can leave schema changes requiring a controlled retry.

`InitialData` loads translated installation values through typed ORM entities
in one transaction. It preserves literal NULL strings, nulls, backslashes,
explicit IDs and temporary legacy reference sentinels. Each row clears the
identity map so references to parents seeded later cannot collide with their
eventual entity instance. Relationship migrations and FK enforcement run after
seeding, and sequences are synchronized as before. The web installer retains
its progress callback. System-cron defaults use a mapped bitwise update that
only changes eligible tasks and excludes watcher; it does not execute tasks.

CLI installation calls `GLPIKey::generate(false)` only after its database
guards. Creating the initial key does not query nonexistent tables or migrate
values from a previously loaded database connection. Default `generate()` still
migrates stored values for existing-key rotation, whose legacy query and failure
recovery behavior remains pending. Fresh web installation now uses DBAL server
connections and generates its initial key without attempting to migrate stored
credentials; see the web installation stage below.

Fresh PostgreSQL and MariaDB installations complete without the earlier
missing-table key-migration errors. The ORM initial-data contract covers raw
values, cross-table rollback, unmapped-table rejection, progress, cron mode
selection and administrative endpoint/catalogue guards. Both engines pass the
FK portability, complete mapping/parent purge, every-table ORM write,
application workflow and hardware report contracts; MariaDB also passes with
PHP 8.3. A full PHP 8.3 installation passed with a role limited to a
pre-provisioned MariaDB database. CLI checks confirm rejected reinstall and
connection failure preserve/omit key and configuration files as appropriate.
Quoted database names and Unix socket catalogue access pass locally. CI now
includes the initial-data contract. Forced reinstall with the limited role
preserves an unrelated table and passes initial-data/FK contracts again. DDL
permission failures restore both initially enabled and disabled FK session
settings without creating tables. Remote CI and web installation have not
been rerun for this batch.

The static inventory remains at 1150 legacy call sites and 746 enforced FKs.
Its current regex excludes namespaced mysqli constructors, prepared-statement
wrappers, adapter-internal calls and `updateOrDie`; those removed installation
paths were not counted. The inventory needs broader call discovery before it
can support any claim that all direct SQL has been eliminated.

### Token-based SQL-call discovery

The coverage audit now tokenizes PHP source rather than matching individual
lines. It ignores comments and literal strings, locates multiline calls and
retains separate byte offsets for several calls on the same line. It discovers
prepared statements, SQL builders, `queryOrDie`/write wrappers, nullable calls,
dynamic adapter methods, static adapter calls and namespaced driver APIs.
The JSON includes each call's source location, method, receiver and category;
it never emits SQL arguments or credentials.

At this checkpoint it finds 3151 calls on known legacy adapters and 30 direct
driver calls, for 3181 detected legacy call sites. Of these, 2442 are in
historical upgrade scripts and 739 are elsewhere in the scanned core. These
counts replace the narrower 1150 regex-matched lines; the increase is newly
discovered existing work. FK coverage is unchanged at 746 enforced references.
Adapter construction (eight sites) and internal adapter calls (34) have separate
categories. There are also 750 method candidates and 28 dynamic candidates.
Those include alternate connection variables, Doctrine and ordinary model CRUD;
they require type/runtime review before any conversion or removal.

This remains a static discovery tool, not completion proof. It does not resolve
import aliases, callbacks or generated code, and it scans the core inc/src/front/
ajax/install roots rather than independent plugin repositories. Relationship
discriminators and serialized references also still need semantic review.
The contract passes on host PHP 8.5 and PHP 8.3, covering lexical false positives,
source locations, wrappers, candidate categories and evidence from the current
web installer and historical upgrades. CI runs it before installation; remote
CI has not been verified for this batch.

### Mapped ITIL reporting selectors

All thirteen `CommonITILObject::getUsed*Between()` selectors now use
`ITILStatisticsOptionsRepository` scalar DQL projections. Ticket, Problem and
Change share the type/relationship definitions with monthly statistics. The
application model still formats user links, supplier links and severity names;
translated classification labels are selected in the same ORM query rather
than fetched once per option. Explicit NULL ordering makes unassigned options
consistent across providers. Request types explicitly require Ticket.

Parent entity scope, deleted-record exclusion, distinct actor/group choices,
all-role user title/category selectors and the historic ticket-OWN profile rule
for task authors are retained. Task eligibility uses EXISTS so duplicate profile
memberships do not multiply choices. Opening or closing must itself fall inside
the interval; spanning the whole interval alone does not qualify. The former
end-date-plus-one-day predicate included an extra day for timestamp bounds and
the following midnight for date-only bounds. These now follow monthly reports:
date-only bounds include their whole day, timestamps are exact and inclusive,
invalid calendar dates are rejected and reversed intervals match nothing.

The selectors contract passes on PostgreSQL and PHP 8.3 MariaDB, including the
`Stat::getItems()` path with zero legacy adapter executions, entity root/global/
empty scopes, relationship fan-out, NULL choices, translations, profile rights,
cross-type solution ID collisions and date boundaries. Monthly statistics pass
again on both engines and PostgreSQL reporting regressions pass. CI includes the
new contract; remote CI has not been rerun. The remaining tree/classification
selectors in `Stat::getItems()` still use the adapter and need a separate mapped
projection.

### Global configuration entity associations

Field-unicity rules and saved searches now map their entity scope as nullable
ManyToOne associations with RESTRICT foreign keys. SQL NULL denotes a global
scope; zero still references the real root entity. Legacy negative scope inputs
normalize at the model boundary, and mapped criteria distinguish global NULL
from root zero. `db:global_entity_scopes` audits both tables before any DDL,
normalizes negative sentinels and retains root references. Fresh schemas and the
installer include this migration; `db:foreign_keys --apply` enforces upgraded
constraints after migration. MySQL DDL commits separately as before.

Entity visibility helpers and model permission checks recognize these audited
global scopes without bypassing ordinary global rights or saved-search private
ownership. Saved-search bulk entity changes use the mapped association, and the
form exposes an explicit All entities choice. Empty entity selections produce
valid false predicates for specific scopes while retaining permitted global
rows. Search and mapped personal listings exercise both cases.

`FieldUnicityRepository` selects the nearest eligible configuration: direct
entity, closest recursive ancestor, then global fallback. All rules in the
chosen scope are returned. This fixes ordering by entity ID and root-zero being
mistaken for an uninitialized selection. Its duplicate report groups mapped
fields using DQL, excludes empty text/NULL references and templates, and keeps
root entity zero as a real grouping value. Global duplicate reports obey the
current visible entity scope. Plugin rule deletion also uses DQL; unmapped
plugin duplicate targets require an explicit mapping. Entity replacement now
retains uniqueness fields on partial updates rather than requiring `_fields`
form data. Purge with no replacement reassigns specific scopes to root rather
than broadening them to global.

FK coverage increases from 746 to 748 enforced references; 12 ordinary pending,
62 polymorphic and one ambiguous relationship candidates remain. Fresh installs
on PostgreSQL and MariaDB pass 1231 and 825 portability assertions respectively.
Both fresh and upgraded databases pass the scope, privacy, search, precedence,
duplicate grouping, entity purge, orphan preflight and retry contracts. Complete
ORM mapping and parent-purge validation pass on both fresh installations;
upgraded installations also pass every-table ORM writes, saved searches,
application workflows, search and reporting contracts. MariaDB runs these checks
under PHP 8.3. CI includes the new scope contract; remote CI is unverified.

### Mapped reporting classification and tree selectors

`Stat::getItems()` now uses scalar ORM projections for group trees, categories,
locations, component catalogues and other mapped asset classifications. There
are no adapter request calls left in `Stat` or in the thirteen ITIL selectors.
Label/path selection, requester/assigned group flags and component catalogue
scope are retained; stable ID ordering breaks label ties. Unknown item types
return no choices and plugin classifications require explicit mappings.

Tree selection and recursive entity visibility are combined with AND. The old
associative-array union or assignment could replace the entity OR predicate
with a tree OR predicate, exposing choices from other entities. The contract
now covers hidden root-scoped rows, recursive root visibility, role flags,
parent/direct-child selection, full path labels, unscoped catalogues and empty
entity scopes. All selector execution paths pass with zero legacy SQL requests
after catalogue warm-up on PostgreSQL and PHP 8.3 MariaDB. Reporting, monthly
statistics, hardware pagination and search regressions pass again on both.

The refreshed token audit finds 3160 legacy call sites: 3130 known adapter calls
and 30 direct-driver calls, 21 fewer than the previous checkpoint. Historical
upgrade scripts still account for 2442 sites, leaving 718 elsewhere. Candidate
method/dynamic calls and polymorphic/serialized relationships still require
semantic review; this audit is not proof that SQL conversion is complete.

### Typed entity configuration associations

Entity LDAP, calendar, ticket/change/problem template and software-owner settings
now use six nullable ManyToOne associations with RESTRICT foreign keys. Typed
`ReferenceMode` enums separate explicit selection, parent inheritance and the
software-only leave-unchanged policy. Database CHECK constraints reject a mode
that contradicts its selected ID. Explicit NULL means no LDAP/template or a 24/7
calendar; software entity zero remains a real association with the root entity.
Inherited and unchanged selections have NULL associations instead of negative
IDs. Assigned-ID software self-references use the new ORM record directly.

The public model and form APIs retain their sentinel dropdown values at the
boundary. Mapped criteria compile those logical values as DQL policy expressions;
canonical repository reads expose nullable IDs and enum values separately. Mode
changes report the corresponding logical field to lifecycle/history hooks, and
mode fields require the same permissions as their original setting. Blank forms
use the policy defaults. Template, calendar, LDAP and software-owner purges retain
explicit replacement/none/root behavior; missing change/problem-template entity
relations have been added to the lifecycle registry.

`EntityConfigurationRepository` replaces adapter SQL for entity identifiers,
notification configuration and inherited setting lookup. Inheritance detects
cycles, retains numeric/string selection rules and NULL alternate values, and
preserves the fallback for legacy callers requesting a missing setting. Its
application read paths execute zero legacy adapter queries after catalogue
warm-up. Entity ID allocation still uses the existing MAX-plus-one behavior;
this batch does not provide a concurrent identifier allocator.

`db:entity_configuration_references` audits all six references before DDL,
splits legacy sentinel values into modes and nullable associations, and installs
the policy checks. It preserves explicit software root and leave-unchanged
selections, refuses orphans/unknown negative values, and supports retries after
MySQL commits the mode-column DDL before data normalization. Run with writers
stopped, then use `db:foreign_keys --apply`. The shared fresh-install schema,
ORM seed importer and installer include these changes.

Coverage is now 754 enforced references, with six ordinary pending, 62 polymorphic
and one ambiguous candidate. Fresh PostgreSQL/MariaDB installs pass 1237/831
portability assertions. Fresh and upgraded installations pass typed settings,
permissions, inheritance, root/self selection, replacement/purge and migration
preflight/retry contracts. Complete mapping and parent-purge checks pass on both
fresh databases. Upgraded databases pass ORM writes across all 355 tables,
native-row parity, ownership, global scopes, calendars, reporting, statistics,
search and application workflows. MariaDB validation uses PHP 8.3; CI includes
the new contract, but remote CI has not been rerun. Statistics collision fixtures
now assign all parent IDs explicitly so a PostgreSQL sequence advanced by earlier
rolled-back tests cannot collide with the fixture's manually assigned ID.

The token audit finds 3135 legacy call sites: 3105 known adapter calls and 30
direct-driver calls. Historical update scripts account for 2442 sites, leaving
693 elsewhere. The six remaining ordinary candidates at this stage include the entity parent,
historical event and notification discriminators, serialized network-port/guest
lists and the obsolete project-template reference. These and the polymorphic
relationships still need explicit domain designs; the overall conversion is
ongoing.

### Entity-owned relationship metadata

The detached `OptionalReferences`, `EntityOwnership`, `ContentAudienceScopes`,
`GlobalEntityScopes` and `BooleanColumns` runtime catalogues are removed.
`EntityConfigurationReferences` contains compatibility operations rather than
field/relationship constants. Doctrine attributes on the owning properties define
targets and nullability; `ReferencePolicy` on those same properties declares
empty-selection, real-root, audience, global and inherited semantics.
`EntityRegistry` derives immutable lookup projections from Doctrine metadata,
including all 355 table mappings, 398 boolean columns and the FK inventory.
Normalization, mapped criteria, lifecycle operations and schema tooling consume
those projections. The old dump-to-mapping generator is removed.

Versioned upgrade inputs remain frozen under `Migration/history`.
They keep historical migrations reproducible when current entities change, and
support the corresponding installation compatibility steps. Runtime CRUD and
relationship discovery never read that snapshot.

The entity hierarchy now maps its parent as a nullable self association. Root
ID zero has no parent; every other entity has a real parent, including zero.
Public model reads retain `-1` for the root while canonical storage uses NULL.
`db:entity_parents --apply` audits existing parents and cycles before DDL,
normalizes the root, and installs a self FK and root/parent CHECK constraint.
Run it with application writers stopped. Fresh installs include the same mapping.
The CHECK rejects self-parenting; multi-node cycles are checked by the migration
and model lifecycle rather than the FK itself.

Current coverage is 755 enforced references, five pending ordinary candidates,
62 polymorphic references and one ambiguous reference. The overall Doctrine
conversion remains ongoing.

Local validation passes all 90 PostgreSQL portability contracts. MariaDB/PHP 8.3
passes 89 contracts in the full run and the remaining all-table write contract
after its fixture was corrected to honor declared association defaults. Fresh
installations pass metadata, root-parent and inherited-setting checks on both
providers, with 1238/832 baseline portability assertions. Legacy Entity, User,
Group_User and Ticket tests pass 83 methods and 4805 assertions. Syntax checks
pass all 333 changed/new PHP files; formatting, SQL inventory and diff checks
also pass. These are local results; remote CI and production upgrades have not
been run.

### Notification recipient projections

The common ITIL and base notification targets now select recipients through
`NotificationRecipientRepository`, using mapped user grants, group membership,
actors, child authors and notification associations. The existing profile-join
hook is validated against the actual associations before compiling its criteria.
Recursive entity scope, private-followup rights, anonymous and alternative-email
handling, group managers, account lifecycle exclusions and recipient hooks retain
their existing behavior. Template attachments share the document access predicates
used by the ORM document repository. `Profile_User::getUserProfiles` also uses its
mapped projection.

This removes 25 direct adapter queries. The current inventory has 3110 legacy
call sites: 3080 adapter calls and 30 driver calls. Historical update scripts account
for 2442, leaving 668 elsewhere; the complete ORM conversion remains ongoing.
Relationship coverage is unchanged at 755 enforced references, five ordinary
pending candidates, 62 polymorphic references and one ambiguous reference.

Local validation passes all 91 portability contracts on both PostgreSQL and
MariaDB/PHP 8.3. Legacy notification target/event, User and Group_User tests pass
42 methods and 1027 assertions. PHP syntax passes all 339 changed/new PHP files;
formatting, SQL inventory and diff checks pass. No remote CI or production upgrade
was run.

### Typed notification target recipients

`NotificationTarget` owns its group/profile associations and their recipient-kind
attributes. Types 3, 5 and 6 select a group; type 2 selects a profile. Other types
retain an integer recipient code, including plugin-specific kinds. User-recipient
constants are payloads and do not acquire a guessed user foreign key.
`items_id` is a generated, read-only compatibility projection of the selected
association or code. Native Doctrine persists the associations and refreshes that
projection; legacy form/plugin input is converted by the entity at the write
boundary. Canonical changes still expose the logical `items_id` field to history.

Fresh installations include two real foreign keys and a CHECK requiring exactly
the branch selected by the recipient kind. Group/profile purge hooks delete or
replace their targets without changing colliding constant payloads. The author
mailing existence query also uses the mapped recipient and template associations.
The versioned `db:notification_recipients` command audits legacy recipients before
DDL; `--apply` requires stopped application writers. PostgreSQL upgrades are
transactional. MariaDB converts the compatibility column without dropping its
custom indexes; additions and retries are idempotent, and DDL is refused inside an
application transaction. Historical upgrade definitions are frozen separately
from entity-owned runtime discovery.

Coverage is 757 enforced references, one discriminator backed by typed
associations, four ordinary pending candidates, 62 polymorphic references and one
ambiguous reference. The SQL inventory records 3109 remaining legacy call sites
(3079 adapter calls and 30 driver calls); the complete conversion is ongoing.

All 92 portability contracts pass on both PostgreSQL and MariaDB/PHP 8.3.
Focused recipient contracts pass on both providers, including native persistence
of a new group and recipient in one flush, generated-key refresh, raw FK/CHECK
rejection, model replacement/purge, preflight conflicts and migration retry.
Fresh installations pass 1240 PostgreSQL and 834 MariaDB portability assertions.
The legacy notification, User and Group_User run passes 42 methods and 1031
assertions. Syntax checks pass all 351 changed/new PHP files. These are local
results; no remote CI or production upgrade was run.

### Profile permission queries

`ProfileRightRepository` now discovers, registers, completes, deletes and copies
permission definitions through Doctrine. Definition installation is atomic across
profiles. Migration grants combine integer masks and select their source through
structured criteria; all eleven historical callers use those criteria. The public
grant helper now requires an array predicate, so external callers supplying raw SQL
must convert it to structured criteria as well.

`ProfileRepository` shares the permission-containment query used by profile
selection and management checks, including explicit zero masks and missing-right
rejection. Scoped user checks join mapped profile grants and retain any-bit masks
and recursive entity scope. Default-profile selection and clearing also use ORM
queries. Application permission updates retain model callbacks for active-session
rights, menu invalidation and profile history, while exact-name lookups use bound
values without SQL unescaping.

This removes all 18 direct adapter calls from `Profile` and `ProfileRight`.
The inventory now records 3091 remaining legacy call sites: 3061 adapter calls and
30 driver calls. Historical updates account for 2442 sites, leaving 649 elsewhere.
Relationship coverage remains 757 enforced references, one typed discriminator,
four ordinary pending candidates, 62 polymorphic and one ambiguous reference.
The complete conversion remains ongoing.

All 93 portability contracts pass on PostgreSQL and MariaDB/PHP 8.3. The new
permission contract covers literal names with quotes/backslashes, combined masks,
recursive and nonrecursive grants, missing rights, atomic duplicate failure,
idempotent completion, default-profile changes and active-session/history hooks.
The legacy Profile, notification, User and Group_User run passes 46 methods and
1125 assertions. PHP syntax passes all 356 changed/new files; formatting, SQL
inventory and diff checks also pass. These are local results; remote CI and
production upgrades have not been run.

### Profile grants and group membership queries

`ProfileUserRepository` selects authorization scopes, permission-filtered scopes,
users associated with an entity, scoped profile users and their tab counts through
mapped grants. Recursive entity expansion remains in the entity tree service;
root ID zero remains a real authorization. Separate grants retain their link IDs
and flags in application views even when they concern the same user.

`GroupMembershipRepository` selects users/groups through their owning
associations and compiles structured filters against the joined metadata. Member
visibility uses grant existence predicates, retaining users with no authorization
while avoiding duplicate rows from multiple grants. Counts and positive page
limits apply in the database. Name ordering preserves MySQL's NULL ordering on
PostgreSQL and uses membership IDs to make tied page boundaries stable. Manager,
delegate and dynamic flags, direct-member exclusions and rendered table rows
retain their application contracts.

All 14 remaining direct adapter calls are removed from `Profile_User` and
`Group_User`. The inventory records 3077 remaining sites: 3047 adapter calls and
30 direct-driver calls. Historical update scripts account for 2442 sites,
leaving 635 elsewhere. Coverage remains 757 enforced references, one typed
discriminator, four ordinary pending candidates, 62 polymorphic and one ambiguous
reference. The full conversion remains ongoing.

All 94 portability contracts pass on PostgreSQL and MariaDB/PHP 8.3. The
membership contract covers recursive scopes, literal permission names, separate
grant IDs, manager/delegate filters, grant deduplication, stable page boundaries
and rendered application views. Its entity-tree cache is isolated because rolled
back fixture IDs can be reused by later contracts. The legacy Group_User, Profile
and User run passes 30 methods and 872 assertions; the PostgreSQL application
smoke also passes. PHP syntax passes all 360 changed/new files, and formatting,
SQL inventory and diff checks pass. These are local results; remote CI and
production upgrades have not been run.

### Rule collections and rule payloads

`RuleRepository` selects and counts collections, orders inherited rules through
their owning entity association, applies condition masks, discovers distinct
criteria through their parent rules and computes collection ranks. Page limits
apply before hydration; tied ranks/names use rule IDs for stable boundaries.
Rule/action variants resolve their parent association from Doctrine metadata.
Action lookups return each parent once instead of multiplying rule objects when
several matching actions exist. Dropdown replacement binds the mapped scalar
pattern/value types, while rule disabling and reordering retain model updates.
Rank changes write only the ID and rank, preserving unrelated text payloads.

`Rule`, `RuleCollection`, `RuleAction` and `RuleCriteria` execute no direct adapter
SQL or embedded SQL expressions. Criteria/action loaders use mapped records;
entity tab counts join actions to rules. Validation group selection uses mapped
membership IDs. XML entity/criterion/action names are bound literally rather
than escaped and decoded as SQL values. `getRuleListCriteria()` now returns
structured filters, ordering and page bounds, with no SQL FROM/SELECT/JOIN
declarations; external consumers must use the mapped collection path.

This batch removes 19 inventory call sites. The remaining 3058 sites comprise
3028 adapter calls and 30 direct-driver calls; 2442 are historical update scripts
and 616 are elsewhere. Relationship coverage remains 757 enforced references,
one typed discriminator, four ordinary pending candidates, 62 polymorphic and
one ambiguous reference. The full conversion remains ongoing.

All 95 portability contracts pass on PostgreSQL and MariaDB/PHP 8.3. The rule
contract covers recursive/direct/child scope, condition masks, ordered child
payloads, distinct parent lookups, SLA/OLA variants, database-side pages, NULL/tied
name ordering, rank changes across excluded neighbors, dropdown replacement and
disabling, literal XML names and absence of legacy adapter queries on rule reads.
The legacy Rule, RuleCriteria, RuleTicket and collection run passes 2328 assertions
across 76 nonvoid methods, with one existing void method. Its empty-category
assertion now expects the canonical NULL FK; the old zero assertion also failed
with the pre-batch rule files. Syntax passes all 367 changed/new PHP files;
formatting, SQL inventory and diff checks pass.

The first MariaDB sequence passed 94/95 contracts: group purge encountered error
1020 (record changed since last read). That contract passed in isolation with both
current and pre-batch rule files; the subsequent complete isolated sequence passed
95/95 without reproducing the error. These are local results; remote CI and
production upgrades have not been run.

### User authentication sources

`User::$authldap` and `User::$authmail` now declare the authentication-source
relationships directly. The shared authentication-kind enum also supplies the
existing `Auth` constants; the mapped kind remains an integer to support custom
authentication kinds. Pending, LDAP, external, CAS and X509 accounts select an LDAP
association; mail accounts select a mail association. A missing association is
valid and retains the existing no-server fallback behavior. Non-server and custom
authentication kinds keep their opaque source code, including signed values.

`auths_id` is a generated, read-only compatibility selection. Its zero value for
an absent server preserves the existing login uniqueness key; nullable foreign
keys alone would allow duplicate no-server logins. Native Doctrine persistence,
mapped legacy writes and the bulk authentication updater maintain the selected
branch. FK and CHECK constraints reject orphaned servers and inconsistent kinds.
The source-code column defaults to NULL so an insert that omits authentication
fields produces a valid pending account; native and mapped opaque kinds supply
their explicit code.
Server purge/replacement uses owning associations without replaying remote user
synchronization hooks. Generic relation cleanup also filters the owning branch,
so colliding IDs in unrelated kinds or opaque payloads are preserved.

The frozen `UserAuthenticationSources` upgrade is wired into fresh installation
and available through `itsmng:database:user_authentication_sources`. Dry-run is
the default; stop application writers before `--apply`. Preflight rejects orphan
servers, conflicting partial canonical data, and login-key collisions caused by
normalizing negative no-server sentinels to zero. Existing indexes are retained.
PostgreSQL DDL is transactional; MySQL/MariaDB application transactions are
refused when DDL remains. A retry can finish already added canonical columns.
Historical SQL is frozen migration code rather than a runtime relation catalogue.

The focused contract exercises native source/user persistence in one flush,
serverless accounts, kind transitions, bulk maintenance, FK/CHECK enforcement,
uniqueness, mail purge/replacement, and reconstructed legacy upgrade retries on
both providers. Fresh installation and the reconstructed legacy upgrades pass
on both providers. The local legacy User, Auth, AuthLdapReplicate,
NotificationTarget and RuleTicket tests pass: 51 methods, 1919 assertions.

The final full runs initially passed 93/96 PostgreSQL and 94/96 MariaDB contracts.
Both exposed an invalid default for raw pending-account inserts. PostgreSQL also
hit the selector fixture's memory-cache limit, which counted the whole PHP
process. The source-code default is now NULL; that isolated test cache no longer
applies an implicit process-memory cap. Fresh fixtures on both providers pass all
ten affected/schema contracts, including the failed contracts, complete-table
ORM writes, metadata/schema checks, account maintenance, and authentication
upgrade retries. The full 96-contract runs were not repeated after these narrow
corrections.

The current inventory has 759 enforced relationships and two typed discriminator
selections across 355 mapped tables. Four ordinary candidates and 62 polymorphic
references remain; the ambiguous authentication-source candidate is now typed.
The SQL-call inventory is unchanged at 3058 legacy sites (2442 in historical
`install/update_*` scripts and 616 elsewhere). This batch adds FK coverage;
the full ORM/SQL migration remains active.

### Dictionary replay

Software and dropdown dictionary replay now query mapped entities and owning
associations. Scalar streams have stable ID ordering and offsets; software groups
retain their distinct name/manufacturer/entity criteria. Model replay resolves
the model and manufacturer properties from Doctrine metadata. It does not carry
another relationship catalogue or guess join-column names.

Software version moves share the ordinary merge's reference-transfer primitive.
Buy/use license references and installations move together; installation
collisions preserve destination metadata and distinguish asset types. Version
names remain literal, including quotes, backslashes, NULL, empty strings and the
text `NULL`. Failed deletion rolls back the reference transfers. Public software
deletion/trash and plain dropdown replacement still invoke their existing model
lifecycle behavior.

Model replay remaps each source model's manufacturer partitions transactionally.
Printer cartridge compatibility is copied to the distinct destination models;
the source links are removed before deleting an unused model, satisfying the
restrictive foreign keys. A partially used source retains its compatibility.
Compatibility insertion uses native mapped associations and is idempotent for
an existing pair. The former direct model deletion bypassed hooks; this path
keeps that boundary while deleting through Doctrine after dependent links.

The software dictionary, ordinary software merge, dropdown dictionary, stock and
asset-classification contracts pass on PostgreSQL and MariaDB. The new dictionary
contracts cover public replay, manufacturer/NULL partitions, quoted names,
restrictive cleanup, collision handling and rollback. Their repository-operation
checks execute no legacy adapter SQL; generic public import/lifecycle code still
contains legacy paths outside this batch. The existing software/dropdown
dictionary tests pass 16 methods and 200 assertions. These focused checks do not
constitute a new complete portability-suite run or remote CI validation.

This batch removes 21 inventory call sites. The refreshed inventory contains
3037 legacy sites: 3007 adapter calls and 30 direct-driver calls, with 2442 in
historical `install/update_*` scripts and 595 elsewhere. Both dictionary collection
classes now contain no direct adapter calls. Coverage remains 759 enforced
relationships and two typed discriminator selections across 355 mapped tables;
four ordinary candidates and 62 polymorphic references remain. The full goal is
still in progress.

### Owning lifecycle relationships

`inc/relation.constant.php` is now a compatibility entry point for
`EntityRegistry::lifecycleRelations()`. Its manually maintained core relationship
catalogue is removed. Ordinary targets and columns come from Doctrine owning
associations. `ApplicationManaged` on the owning property identifies links handled
by the model's purge/replace hooks rather than generic replacement. Discriminated
associations project their existing logical field for older callers while retaining
the branch's own handling policy.

The old catalogue's 725 child entries compare equal after normalizing singleton
arrays and column order. The derived view also includes five model-managed typed
links absent from the old catalogue: appliance-item relations, domain owners,
notification group/profile recipients and reminder translations. No foreign-key
target is declared a second time for this compatibility view.

Existing polymorphic lifecycle targets now sit on their ID properties as
`PolymorphicReference` attributes. `VirtualAssetLink` marks the four generic asset
links used by recursion checks. These describe application behavior; they do not
turn scalar item IDs into foreign keys. The remaining polymorphic migration still
requires explicit domain storage designs.

`CommonDropdown` ownership lookup, usage checks and import-name lookup use
`DropdownLifecycleRepository`. Usage tests query owning associations and respect
model-managed links; polymorphic usage binds both ID and item type. Authentication
usage follows its selected association, excluding opaque source codes. Import
lookups apply entity scope before their database limit, use stable ID ordering,
and treat quoted/backslash names and literal `NULL`/`null` as strings. Plugin usage
declarations pass through mapped reads and require registered entities.

The focused lifecycle contract passes on PostgreSQL and MariaDB, including root
and recursive ownership, empty scope, typed source branches, colliding polymorphic
IDs, idempotent import and public calendar replacement/purge. The legacy Calendar,
Dropdown and dropdown dictionary tests pass 22 methods and 695 assertions. Syntax
checks pass 165 PHP files; formatting and diff checks pass.

The complete 99-contract portability suite passes on both PostgreSQL and
MariaDB/PHP 8.3, including ORM/native parity for all 355 tables and 3669 field
values, 728 planned search columns, concurrent stock allocation, and the earlier
dictionary conversions. Every contract's completion output was checked alongside
the runner's terminal result. After strengthening the polymorphic collision
fixture to reference a real task, the lifecycle contract was repeated on both
providers. These are local checks; production upgrades and remote CI were not run.

This batch removes four direct adapter call sites from `CommonDropdown`. The
refreshed inventory has 3033 legacy sites: 3003 adapter calls and 30 direct-driver
calls, with 2442 in historical `install/update_*` scripts and 591 elsewhere.
Relationship coverage is unchanged at 759 enforced references, two typed
discriminator selections, four ordinary candidates and 62 polymorphic references
across 355 mapped tables. The full conversion remains active.

### Printer dictionary replay

`RuleDictionnaryPrinterCollection` now selects replay inputs and explicit printers
through `PrinterDictionaryRepository`. Queries use the mapped manufacturer and
entity associations, bound literal names, stable group ordering and database
offsets. Complete inputs group by name, manufacturer and comment; initial replay
excludes trash and templates, while explicit replay retains its existing trash
selection and excludes templates. Empty ID lists and past-end offsets terminate.

Replay previously read `entities_id` but used a separate `entity` parameter that
defaulted to root. It now uses the actual owning entity to create or restore each
destination. Escaped rule output is decoded for logical name comparisons while
public import/update boundaries keep their existing escaped input contract.

Connection movement queries `ComputerItem` with both the printer ID and item type.
Duplicate destination connections retain their metadata. Source duplicates,
including dynamic locks, are purged through the public relation lifecycle without
the asset-field cleanup used for disconnection. Nonduplicates move through public
updates. A failed connection lifecycle rolls back earlier moves; explicit replay
also wraps destination restoration, connection movement and source trash together.
Invalid destination printers are rejected before changing any link. This does not
claim an FK for the still-polymorphic `ComputerItem.items_id` column.

The new printer dictionary contract passes on PostgreSQL and MariaDB, covering
literal quotes/backslashes/Unicode, nullable manufacturers, real root/child
ownership, creation/restoration, type collisions, dynamic duplicates, public rule
processing and rollback. The six adjacent asset-workflow, dropdown-dictionary,
dropdown-lifecycle, software-dictionary, stock and rule-collection contracts also
pass on both providers. Existing printer tests pass four methods and 106
assertions. Syntax, formatting, diff and SQL-inventory checks pass. The suite now
discovers 100 contracts; this batch ran the new contract and the six affected
neighbors, not another complete suite or remote CI run.

Three direct adapter requests are removed; this collection contains no direct
adapter SQL calls or legacy query helpers. Repository selection checks execute no
adapter SQL. Other public model lifecycle paths still need conversion. The refreshed
inventory contains 3030 legacy sites: 3000 adapter calls and 30 direct-driver calls,
with 2442 in historical `install/update_*` scripts and 588 elsewhere. Relationship
coverage remains 759 enforced references, two typed discriminator selections,
four ordinary candidates and 62 polymorphic references across 355 mapped tables.
The full goal remains active.

### Generic relationship lifecycle

`CommonDBTM::cleanRelationData()` selects core replacements through
`RelationshipLifecycleRepository`. The owning Doctrine property supplies the
target and column; `ApplicationManaged` excludes links handled by specialized
hooks. Public child keys are snapshotted before those hooks run. Ordinary
associations use physical IDs, while polymorphic links bind the logical ID and
item type together. Discriminated authentication sources select their canonical
association and project the existing logical update field, so another source
branch or an opaque source code cannot be replaced accidentally.

`CommonDBTM::canUnrecurs()` now queries mapped ownership policies, peer
associations and virtual asset links. Dynamic item types resolve through registered
models before entering DQL. Self-parent tree restrictions and specialized computer
and device checks remain. Reverse document checks use the document's owner even
when the link's cached entity differs. Plugin declarations require registered
entities and pass through mapped criteria rather than a raw SQL fallback.

These selections retain public model update hooks and their existing behavior;
this does not make all generic replacements atomic or guarantee that every hook
accepts a replacement. In particular, shared Kanban rows still need a dedicated
domain lifecycle for their nullable owner and uniqueness rules. The covered
personal Kanban replacement preserves the item type and leaves a colliding task
ID untouched. Neither that scalar reference nor virtual asset IDs gain foreign
keys from this conversion.

The focused contract passes on PostgreSQL and MariaDB, covering paired
polymorphic replacement, authentication source branches, managed child
exclusions, tree recursion, cross-entity assets,
deleted assets, reverse document ownership and registered plugin boundaries.
The existing CommonDBTM, Calendar and Dropdown tests pass 40 methods and 1279
assertions. Syntax, formatting, diff and SQL-inventory checks pass.

The complete 101-contract portability suite passes on both PostgreSQL and
MariaDB/PHP 8.3. Every contract's completion output was checked alongside the
runner's terminal result, including the strengthened authentication replacement
fixture. The suites also cover ORM/native parity for all 355 tables, 3669 field
values, 728 planned search columns, concurrent stock allocation and both earlier
dictionary conversions. Production upgrades and remote CI were not run.

One direct adapter request is removed. The refreshed inventory contains 3029
legacy sites: 2999 adapter calls and 30 direct-driver calls, with 2442 in historical
`install/update_*` scripts and 587 elsewhere. Relationship coverage remains 759
enforced references, two typed discriminator selections, four ordinary candidates
and 62 polymorphic references across 355 mapped tables. The full goal remains
active.

### Network port aggregate origins

Aggregate origin ports now use the ordered `NetworkPortAggregateOrigin` entity.
Its two owning associations declare the aggregate and port targets locally, with
unique constraints for membership and position. The serialized
`glpi_networkportaggregates.networkports_id_list` storage column is removed.
The public model still projects that field for existing forms and plugin inputs;
it does not persist another copy of the membership list.

`NetworkPortAggregateRepository` selects and edits origins through ORM. Aggregate
updates lock the aggregate, validate every selected port before replacing its
memberships, and share the public model's transaction so an invalid origin also
rolls back scalar updates or creation. Partial updates preserve omitted origins.
Port replacement preserves ordering and deduplicates a destination already in
the set; purge removes memberships before deleting either parent. Reverse lookup
and virtual peers use exact associations, replacing serialized-list LIKE searches.
Port selectors bind the owner and instantiation type through mapped queries. The
aggregate form also preserves actual numeric port IDs when combining its options.

The frozen `20261001_networkport_aggregate_origins` upgrade accepts the existing
JSON and old key/value list encodings, preserving the first occurrence and order.
It rejects malformed IDs, orphan targets, incompatible canonical constraints and
conflicting copied data before dropping the old column. Fresh installation also
creates the membership table and both foreign keys. Review the plan with
`php bin/console db:aggregate_origins`; stop application writers before applying
it with `--apply`. PostgreSQL DDL and data copy share a transaction. MySQL DDL must
run outside an application transaction; interrupted upgrades can be retried.

Fresh installations succeed on PostgreSQL and MariaDB. The new contract covers
raw foreign-key rejection at both ends, ordering, the form's real IDs, public
replacement and purge, exact peers, rollback, legacy upgrade refusal and retry.
The 11 aggregate and adjacent portability contracts pass on both providers,
including ORM column parity and insert/read/delete across all 356 tables.
Existing CommonDBTM and NetworkPort tests pass 26 methods and 757 assertions.
Syntax, formatting, diff and SQL-inventory checks pass. This batch did not rerun
the complete 102-contract suite or remote CI, and did not upgrade production.

Coverage now contains 761 enforced references, two typed discriminator selections,
three ordinary candidates and 62 polymorphic references across 356 mapped tables.
Three direct adapter requests are removed. The refreshed inventory contains 3026
legacy sites: 2996 adapter calls and 30 direct-driver calls, with 2442 in historical
`install/update_*` scripts and 584 elsewhere. The full goal remains active.

### Planning event guests

`PlanningExternalEventGuest` now owns an event association and a user association,
with unique membership and position constraints. The serialized
`glpi_planningexternalevents.users_id_guests` column is removed. The public event
model projects an ordered array for forms, recurrence clones, reminder recipient
selection and plugin inputs. `PlanningGuestRepository` validates positive existing
users, serializes edits with an event lock and preserves first-occurrence order.
Partial updates preserve omitted guests; explicit empty arrays clear them.

Public event add, update and deletion share a transaction with their memberships.
Memberships are saved before scalar history reload and update hooks. Guest-only
updates retain history and the update hook. Invalid guests roll back scalar changes
or event creation; a failure after purge cleanup restores both the parent and its
guests. User replacement deduplicates a destination already invited, while user
purge removes the corresponding memberships. Both relationship ends use
`ApplicationManaged` beside their owning associations so generic replacement does
not bypass these domain rules.

Calendar and iCalendar selection now join typed guest memberships instead of
matching user IDs inside JSON text. Event projections use DISTINCT to prevent
membership fan-out from duplicating owned or group events. The Guests search
option joins users through the membership table and searches/displays users.
Existing reminder recipient selection reads the projected canonical array.

The frozen `20261001_planning_event_guests` migration audits legacy JSON and old
key/value lists, rejects invalid IDs and orphan users, preserves order and removes
duplicates. Canonical constraint validation and copied-data agreement protect
upgrade retries. `php bin/console db:planning_guests` prints the audit/DDL plan;
stop writers before `--apply`. PostgreSQL applies DDL and copied data atomically.
MySQL DDL runs outside an application transaction and permits interrupted retries.

Fresh installations pass on PostgreSQL and MariaDB. The 16 planning and adjacent
contracts pass on both providers, including all 357 tables' ORM parity and writes,
calendar recurrence and visibility, users, notification targets, search semantics
and 728 planned search columns. The final strengthened guest contract also passes
on both providers, covering raw FK rejection, guest-only history, reminder targets,
recurrence cloning, public user/event lifecycle and failed purge rollback.
Existing planning, template and reminder tests pass 11 methods and 247 assertions.
Syntax, formatting, diff and SQL-inventory checks pass. This batch did not run the
complete 103-contract suite, remote CI, external notification dispatch or a
production upgrade.

Coverage now contains 763 enforced references, two typed discriminator selections,
two ordinary candidates and 62 polymorphic references across 357 mapped tables.
The legacy inventory remains 3026 sites: 2996 adapter calls and 30 direct-driver
calls, with 2442 in historical `install/update_*` scripts and 584 elsewhere.
Removing the serialized guest storage improves FK coverage without completing
the remaining SQL conversion. The full goal remains active.

### Event queries and unused project-template cleanup

`EventRepository` now owns event insertion from `Event::log`, user-prefix reads,
ordered pages/counts and retention. The public logging lifecycle still applies the
configured level gate and runs file/plugin hooks. Its raw input boundary binds
literal `NULL`, quotes and backslashes without the old escape/decode round trip;
public `Event::add()` retains its legacy pre-escaped input contract. File output
uses the hydrated raw message without stripping a second layer of backslashes.

User-prefix queries bind literal usernames, including `%`, `_`, `!` and backslashes,
and preserve case-insensitive matching. Pages use the six existing sort fields,
explicit portable NULL ordering and the event ID as a stable tie breaker. Both
the page and its total use the application's explicitly selected read connection.
Retention is a mapped bulk delete on the supplied write connection, returns the
affected count and preserves NULL dates, transaction rollback and the strict
whole-second database-clock boundary. `CURRENT_EPOCH_SECONDS()` handles that clock
precision without changing elapsed-duration `EPOCH_SECONDS()` calculations.

Core never implemented a project-template target table or a consumer for
`glpi_projects.projecttemplates_id`. Its obsolete scalar mapping is removed.
Frozen `20261001_unused_project_template_reference` drops the unused column and
single-column index only after checking existing values. Nonzero selections,
custom composite/unique indexes and outgoing or incoming foreign keys refuse the
upgrade before DDL. Fresh installation uses the same frozen cleanup.

For existing installations, stop application writers and inspect the plan before
applying `php bin/console db:project_template_reference --apply`. PostgreSQL DDL
runs transactionally; MySQL DDL must run outside an application transaction, and
retries are idempotent. Unknown plugin-owned selections require their own explicit
migration instead of silent deletion.

Dedicated PostgreSQL and MariaDB fresh installations and 18 affected contracts
passed, including project upgrade refusal/retry, raw event logging, level filtering,
literal user scope, tied/NULL sorting, HTML rendering, retention and rollback.
The existing Project and CronTask tests passed 203 assertions. Warm event operations
produced no adapter SQL calls. This verifies the supplied connection locally;
it does not establish live replica freshness or remote CI results.

The current inventory is 357 mapped tables, 763 enforced references, two typed
discriminator selections, one ordinary candidate and 62 polymorphic references.
`glpi_events.items_id` still stores historical logical IDs, including deleted
subjects and special legacy type codes; moving its queries to ORM does not supply
a foreign key. Its target design remains outstanding. The static SQL inventory
contains 3,024 legacy call sites (2,442 historical update scripts and 582 elsewhere).
The broader goal remains active.

### Typed ITIL subjects and association-local reporting roles

Followups and solutions now own explicit Ticket, Problem and Change associations.
The shared `ITILSubject` mapping declares each target, join column and discriminator
beside its property. Six real foreign keys and a CHECK constraint require exactly
one matching parent. The legacy `items_id` is a read-only generated projection;
forms and legacy criteria can still use it, while new followup, document and
statistics queries use the selected owning association directly.

The frozen `20261001_itil_subjects` upgrade audits both child tables before DDL.
Unknown types, missing parents, conflicting canonical values and custom incoming
or outgoing legacy-key dependencies require an explicit migration. Existing
positive selections are copied without inventing targets. Stop writers before
applying `php bin/console db:itil_subjects --apply`; PostgreSQL applies transactionally,
while MySQL DDL runs outside application transactions and supports retry.

Native ORM persistence validates the selected subject. Partial legacy updates keep
the existing parent. Retargeting clears the other branches, and duplicating a
Ticket solution explicitly assigns the copied solution to its new parent. Parent
purge removes only its own timeline, including when different parent types have
overlapping numeric IDs.

The detached ITIL statistics, task and cost relationship arrays are removed.
Reporting roles are attributes on the actual owning associations; repositories
derive their parent properties and related classes from those declarations.
Frozen versioned migration descriptions remain separate from runtime metadata.

Fresh installations passed on PostgreSQL and MariaDB. The full 106-contract suite
ran on each provider; failed fixtures were repaired and all affected contracts
rerun successfully, including the final PostgreSQL migration/schema checks.
Coverage includes native ORM writes, FK/CHECK rejection, overlapping parent IDs,
public parent purge, solution copying, upgrade refusal/retry, typed repository
queries and installed-schema comparison. The existing followup and solution tests
passed 492 assertions with no skipped methods. PHP syntax, formatting and SQL
inventory checks also passed. This is local disposable-database proof, not remote
CI, production upgrade or external notification-dispatch proof.

The inventory contains 357 mapped tables, 769 enforced references, four typed
logical discriminator selections, one ordinary candidate and 60 polymorphic
references. There are still 3,024 legacy SQL call sites, including 2,442 historical
update-script sites. These changes do not complete the broader conversion.

### Typed project links to ITIL subjects

`ItilProject` now uses the entity-local `ITILSubject` mapping for its Ticket,
Problem and Change owners. Three additional foreign keys and an exactly-one
CHECK replace the unchecked type/ID pair. The generated legacy `items_id`
preserves existing criteria and the unique `(itemtype, items_id, projects_id)`
link key. Each versioned migration retains its own frozen table scope; the shared
DDL mechanism preserves existing followup/solution upgrade behavior.

`ItilProjectRepository` reads both project and ITIL tabs through owning
associations, and `ITILTaskRepository::parentTasks()` supplies their planned tasks.
These replace all three direct adapter SQL requests in `Itil_Project`. Rows keep
their relationship IDs for link actions, with portable name ordering and stable
ties. Rendering also keeps planned task IDs separate from subject rows and uses
HTML line breaks without the undefined output-mode variable.

Frozen `20261001_itil_project_subjects` preserves existing links, generated-key
uniqueness and idempotent retry. Unknown types, missing parents, conflicting
branches and custom legacy-key dependencies refuse the upgrade before DDL.
Stop writers before applying `php bin/console db:itil_project_subjects --apply`.
PostgreSQL is transactional; MySQL DDL runs outside application transactions.
Project cloning retains the subject, while public Project and ITIL purge remove
only their own links, including across overlapping numeric IDs.

The budget repository's duplicate cost relationship array is removed. Budget
and cost projections now share the reporting parent declared on each cost's
owning association.

Fresh installations and 21 affected contracts passed on PostgreSQL and MariaDB,
including generic ORM CRUD across all 357 tables, native/public project links,
database constraint rejection, rendered tabs, upgrade refusal/retry and schema
comparison. The final focused runs also exercise command preview and idempotent
application. Existing Project, project-link, followup and solution tests passed
692 assertions across 18 methods with none skipped. PHP syntax, formatting and
SQL inventory checks passed. This batch did not rerun the full portability suite;
the evidence is local, with no remote CI, production upgrade or external
notification-dispatch validation.

Current coverage is 357 mapped tables, 772 enforced references, five typed logical
discriminator selections, one ordinary candidate and 59 polymorphic references.
The legacy SQL inventory has 3,021 sites: 2,991 adapter calls and 30 direct-driver
calls. The full ORM/FK conversion remains active.

### Project notification projections

Project and project-task notifications now read their scoped team recipient IDs
through `ProjectRepository` and their attached documents through the owning
`DocumentItem.documents` association. These replace ten direct adapter queries.
Eight remaining template queries now use mapped record criteria for teams,
tasks, costs, ITIL links, assets and ticket bindings. User languages, group roles,
external recipient types, duplicate document bindings and deleted-document
metadata retain their existing behavior. Team and document bindings use stable
relationship-ID ordering.

Project asset criteria no longer reuse the Ticket filter left by the ITIL loop,
so linked assets appear in notification templates. The document repository also
derives each ITIL task's parent from the association's reporting attribute rather
than maintaining another task/parent relationship list.

Eight affected contracts passed on PostgreSQL and MariaDB, including public
template data, recipient roles, overlapping owner/member IDs, document access,
project visibility/planning, task queries and project-link migration. The tests
capture notification data without dispatching mail. Six changed PHP files passed
syntax and formatting checks, and SQL inventory checks passed. This batch did
not rerun the full suite or remote CI and introduces no schema change.

Coverage remains 357 mapped tables and 772 enforced references, with 59
polymorphic references and one ordinary candidate still unresolved. The current
SQL inventory has 3,011 legacy sites: 2,981 adapter calls and 30 direct-driver
calls. The complete ORM/FK goal remains active.

### Typed project and task team members

`ProjectTeam` and `ProjectTaskTeam` now declare User, Group, Supplier and Contact
owning associations through `ProjectTeamMember`. Each property carries its target,
join column, discriminator and lifecycle policy. Runtime normalization derives
the selected association from these declarations. The common
`RequiredItemReference` behavior also serves ITIL subjects, without a separate
runtime table/target catalogue.

Eight additional foreign keys and two exactly-one CHECK constraints enforce the
member selection. `itemtype` is required; the legacy `items_id` remains a
read-only generated projection and the existing member uniqueness/indexes remain
intact. Project visibility and notification member projections query the selected
owning association. Public cloning, retargeting and member/owner purge preserve
type boundaries even when different member tables share numeric IDs.

The frozen `20261001_project_team_members` upgrade audits both tables before DDL,
copies valid selections, refuses unknown types, missing targets, conflicting
branches and custom legacy-key dependencies, and supports idempotent retry.
Its targets are frozen migration history; runtime mappings are authoritative for
application queries and persistence. The shared typed-item DDL mechanism also
requires non-NULL discriminator values for existing ITIL subject tables. Stop
writers before applying `php bin/console db:project_team_members --apply`.
PostgreSQL applies transactionally; MySQL DDL runs outside application transactions.

Fresh installation passed on PostgreSQL and MariaDB. Twenty-four affected
contracts passed on each provider, with the MariaDB schema check rerun after
repairing an index lost by an earlier failed test reconstruction. Final focused
contracts explicitly verify index preservation and frozen upgrade refusal/retry.
Coverage includes ORM CRUD across all 357 tables, native/public memberships,
FK/CHECK/uniqueness rejection, clone/purge, overlapping IDs and installed-schema
comparison. Existing Project, ProjectTeam, ProjectTaskTeam, followup and solution
tests passed 682 assertions across 19 methods with none skipped. Seventeen PHP
files passed syntax and formatting checks; the SQL inventory contract passed.
This is local disposable-database evidence, without a full-suite rerun, remote CI,
production upgrade or external notification dispatch.

Current coverage is 357 mapped tables, 780 enforced references, seven typed
logical discriminator selections, one ordinary candidate and 57 polymorphic
references. The legacy SQL inventory remains 3,011 sites: 2,981 adapter calls and
30 direct-driver calls. The complete ORM/FK conversion remains active.

### Dropdown translation reads

`DropdownTranslationRepository` now supplies translation rows, distinct available
fields and literal dropdown-name lookup through ORM. The public translation
model no longer issues its nine direct adapter requests. Translation selection
binds the kind, identifier, field and language together; overlapping IDs in
different dropdown tables cannot share a translation. Canonical predicates keep
literal `NULL` names/keys, apostrophes, backslashes, Unicode and nullable values
distinct from SQL NULL. Missing languages retain the original dropdown fallback.

Tree translation regeneration snapshots only child identifiers through their
mapped parent association before invoking the existing recursive model hooks.
Changing or deleting an ancestor's translation regenerates descendant complete
names while preserving each child's own translation. Unmapped plugin dropdowns
retain their existing model extension path; no new runtime relationship catalogue
is introduced. Translation lists and used-field selectors preserve their public
interfaces and use stable ordering.

Twelve affected contracts passed on PostgreSQL and MariaDB, followed by focused
checks of the final child-ID projection and rendered selector. These cover scoped
translation reads, literal values, fallback, three-level tree update/purge,
DISTINCT field discovery, rendered lists, used-field filtering and zero adapter
queries for warmed public reads. Existing Location and five operating-system
dropdown suites passed 766 assertions across 61 methods with none skipped. Three
PHP files passed syntax and formatting checks; SQL inventory and diff checks
passed. This batch did not rerun the full suite, remote CI, production upgrades or
live browser workflows.

There is no schema change in this batch. Coverage remains 357 mapped tables and
780 enforced references, with one ordinary candidate and 57 polymorphic
references unresolved. In particular, translation `items_id` still needs a
polymorphic schema design before it can have real foreign keys. The SQL inventory
now has 3,002 legacy sites: 2,972 adapter calls and 30 direct-driver calls. The full
ORM/FK goal remains active.

### Web installation through Doctrine

The MySQL web installer uses the shared DBAL installation service for server
version checks, visible database metadata, database creation and database
selection. Database names are quoted only for DDL and remain literal connection
parameters; names containing backticks can be created and selected correctly.
Connections close on successful and failed requests, and failed creation and
selection retain their respective error pages. Selecting an existing database
does not attempt CREATE. Fresh key generation does not migrate credentials from
an old connection or query application tables before they exist.

Installation completion writes URL settings through the mapped configuration
API. The historical 0.68 OCS connection helper also owns its connection through
the DBAL transport; its obsolete configuration table remains a frozen migration
query rather than acquiring an artificial current ORM mapping.

Local HTTP checks pass for PostgreSQL installation/reinstall refusal and MariaDB
database creation with a backtick name, rejected credentials, missing selection,
overlong-name creation failure, existing selection and update preflight. Both
engines pass login and four core list pages. Web-installed databases pass their
FK portability, initial-data and schema-check contracts; the OCS helper contract
covers disabled/missing configuration and execution through DBAL. Syntax,
formatting, workflow YAML and SQL-inventory checks pass. CI now runs the MySQL web
flow on its MariaDB/MySQL matrix; remote CI and real historical OCS servers are
not claimed.

The token inventory now reports 2,993 legacy sites: 2,970 direct adapter calls
and 23 direct-driver calls, all in `DBpgsql`. It finds no native mysqli calls in
the scanned `inc`, `src`, `front`, `ajax` or `install` PHP trees. This does not
establish coverage of arbitrary plugins, callbacks or generated code. FK coverage
remains 780 enforced references, seven discriminated references, 57 polymorphic
references and one ordinary candidate across 357 mapped tables. The full ORM/FK
goal remains active.

### Inventory lock selection

`Lock` uses `InventoryLockRepository` for both its form and bulk unlock. Component
labels follow the owning Doctrine device association, software labels follow the
mapped installation/license associations, and network descendants bind the item
kind at each ancestry hop. Only the selected assignment must be dynamic and
deleted; live network ancestors remain eligible. No separate table/foreign-key
catalogue is introduced. Component extensions require registered ORM entities.

Bulk unlock now uses the source asset kind for disks and software installations,
the computer association for virtual machines, and column joins for IP ancestry.
Rendering, source rights, inventory hooks and per-model restore remain in the
application. The old `Lock::getLocksQueryInfosByItemType()` SQL-description factory
is removed; consumers can obtain selected rows from the repository instead.

The inventory-lock contract passes on PostgreSQL and MariaDB, including all 17
component associations, live network ancestors, differently typed IDs, software
labels, source rights, form HTML and actual bulk restore. The related component,
software-installation, network-name and network-port contracts pass on both
providers. These are local database and rendered-HTML checks, not live browser
or remote CI checks. Syntax, formatting and SQL-inventory checks pass.

Ten direct adapter call sites are removed from `Lock`; the current token inventory
reports 2,976 legacy/native call sites (2,953 adapter calls and 23 direct-driver
calls). FK coverage is unchanged at 780 enforced references, seven discriminated
references, 57 polymorphic references and one pending ordinary candidate across
357 mapped tables. The full ORM/FK goal remains active.

### Owning reservable asset relationships

`ReservationItem` owns exactly one computer, monitor, network equipment,
peripheral, phone, printer or software association. Each branch has a real foreign
key and the database CHECK requires the selected asset kind, a positive target
and no second branch. Native Doctrine lifecycle callbacks enforce the same
selection. The legacy `items_id` is a read-only generated projection; forms and
model callers still exchange `itemtype/items_id`, while ORM writes select and
clear the corresponding owning association. Existing booking IDs remain stable.

Availability and peripheral-category queries join the owning associations. The
reservation view no longer assembles an adapter SQL fallback. Unmapped plugin
asset kinds require an explicit owning association and schema upgrade; they are
not accepted as unenforced scalar references.

`bin/console itsmng:database:reservation_assets` audits and previews the frozen
upgrade. With application writers stopped, `--apply` preserves asset identity,
availability flags, descriptions, existing bookings and selection indexes.
Unsupported asset kinds, missing targets, conflicting canonical columns and
custom dependencies on the legacy identity refuse before DDL. The migration is
idempotent; its versioned scope is independent of future mapping changes. Fresh
installation includes the seven foreign keys and exact-selection CHECK.

### Bidirectional content audience mappings

Reminder and RSS feed audience collections are genuine Doctrine `OneToMany`
associations. Each audience link declares its owning `ManyToOne`, inverse, target and
join column on its own entity. `SharedContentRepository` resolves those mapped
collections rather than maintaining a parallel list of link classes and parent
properties. Its visibility predicates retain separate `EXISTS` queries so an
item shared with several audiences appears once. SLA/OLA level targets likewise
come from their queue entity's owning association.

Flat legacy rows and fixtures expose owning join columns only; inverse
collections remain lazy and are not converted into physical columns. Native
audience collection hydration, scoped visibility, ownership, translations,
expiry, purge and document permissions pass on PostgreSQL and MariaDB. Doctrine
validates all 357 mappings, and ORM insert/read/delete across all tables plus
340 table updates pass on both providers. Historical migration inputs remain
frozen to preserve upgrades; they are not the live relationship model.

The reservable-asset contract passes on both providers, including transfer
copy/discard, overlapping asset IDs, native and public writes, physical orphan
rejection, exact selection, booking preservation, purge, migration retry and
refusal of incoming dependencies before DDL. Transfer creates a new model for
the copied entry instead of unsetting the source model's required fields.

The complete local CLI portability suites pass 113/113 contracts on PostgreSQL
and 113/113 on MariaDB/PHP 8.3. Each contract's completion output was checked;
formatting, syntax and SQL-inventory checks also pass. These results do not
include live-browser or remote-CI validation. Current static coverage remains
357 mapped tables, 787 enforced references, eight discriminated references,
56 polymorphic references and one pending ordinary candidate. The 2,975
remaining legacy/native SQL call sites mean the broader ORM/FK conversion is
still ongoing.

### Consumable recipient associations

`Consumable` owns its user or group recipient through two nullable `ManyToOne`
associations, each protected by a real foreign key. The exact-selection CHECK
requires the selected recipient kind and only its corresponding positive target.
Unassigned stock has no recipient and no usage date. Returning assigned stock
preserves its last recipient as history; deleting that recipient returns the stock
and clears the association. User and group IDs may overlap without affecting each
other's stock. Assignment, replacement, purge and group queries use the owning
associations. The read-only generated `items_id` preserves the forms' legacy
projection, including zero for unassigned stock.

Native Doctrine callbacks enforce recipient selection and stock state before
flush. Required asset and project-team selections retain their existing rules
through the shared attribute-driven item-reference trait. No separate runtime
recipient catalogue is introduced. Additional recipient kinds require an owning
association and a schema upgrade; unsupported scalar kinds are rejected.

Fresh installations include both recipient foreign keys and the stock CHECK.
`bin/console itsmng:database:legacy_to_orm` audits and previews the frozen
upgrade, including the consumable-recipient stage. With application writers stopped, `--apply` preserves assignment,
returned history, stock dates and selection indexes, and normalizes empty legacy
kinds to NULL. Unsupported kinds, orphaned targets, conflicting canonical columns,
issued stock with no recipient and custom dependencies on the legacy identity
refuse before DDL. Repeating the upgrade makes no further changes.

Local regression validation covers all 114 CLI contracts on PostgreSQL and
MariaDB/PHP 8.3. Each full run passed 113 contracts and exposed an ownership
fixture that incorrectly selected a computer as a consumable recipient. The
fixture now selects a declared owning target, and the corrected ownership
contract passes on both providers. The recipient contract also passes with
execution checks confirming that assignment, return, retarget and reads issue
no legacy adapter SQL. Each contract's completion output was checked; syntax,
formatting and SQL-inventory checks pass. Live-browser and remote-CI validation
are not included.

Current static coverage is 357 mapped tables, 789 enforced references, nine
discriminated references, 55 polymorphic references and one pending ordinary
candidate. The inventory still reports 2,975 legacy/native SQL call sites
(2,952 adapter calls and 23 direct-driver calls). These counts are discovery
evidence, not completion proof; the broader ORM/FK conversion remains active.

### Owning physical placement assets

Rack and enclosure placements own exactly one computer, monitor, network
equipment, peripheral, enclosure, PDU or passive DC equipment association.
Each table has seven real asset foreign keys and an exact-selection CHECK.
The shared mapping trait declares those associations next to their discriminator
and generated identity; no runtime relationship catalogue is maintained.
Asset columns use an `asset_` prefix so a placed enclosure remains distinct from
its enclosure container. Native lifecycle callbacks validate the same selection
before persist or update. Existing forms retain `itemtype/items_id`, with the
identity generated from the owning association.

Rack reservations retain their separate uniqueness flag. Placement positions,
orientation, half-width positions, colors, global selector exclusions, rack
geometry and statistics retain their existing behavior. The PDU selector follows
the owning PDU association. Asset purge removes both installed and reserved
placements while preserving other asset kinds with overlapping IDs; container
purge removes placements without deleting their assets.

Fresh installation includes all fourteen asset foreign keys.
`bin/console itsmng:database:legacy_to_orm` audits and previews the frozen
upgrade, including the physical-placement stage. With application writers stopped, `--apply` preserves container IDs,
selected asset IDs, geometry, reservations and unique selection indexes. Both
tables are audited before either changes. Unsupported kinds, orphaned assets,
conflicting canonical references and incoming dependencies on the old identity
refuse before DDL. Repeating the upgrade makes no further changes.

### Software transfer queries

Software transfer discovers licenses and versions through their owning software
associations. License IDs are selected before callbacks run; version IDs are
selected afterward so changes made by those callbacks are visible. Templates,
trashed records and records in other entities remain part of this internal
transfer selection. Version cleanup checks both license version associations
and installation ownership. Software cleanup checks license and version
ownership before invoking the existing keep, trash or purge behavior.

Installation transfer selects a typed asset's relationships through ORM queries,
excluding the caller's retained version IDs. Retargeting changes only the owning
version association and preserves installation dates and flags. Discard removes
the selected installation and license-assignment records, preserving their
targets and relationships belonging to another asset type with the same numeric
ID. License/version copy callbacks and application deletion hooks retain their
existing responsibilities. These converted queries and mutations execute no
legacy adapter SQL; other transfer paths remain to be converted.

Before identifier widening and command consolidation, local validation verified
all 115 CLI contracts on PostgreSQL 17.5 and
MariaDB 12.2.2. Each full suite passed 114 contracts. The remaining infrastructure
fixture combined an asset branch with an unrelated legacy identity; it now
selects container relationships from the owning association metadata, while the
physical-placement contract tests every typed asset branch. Its corrected rerun
passes on both providers, as does the expanded software transfer contract.
Every contract's completion output was checked. Syntax, formatting,
SQL-inventory discovery and whitespace checks pass. PostgreSQL contracts ran on
host PHP 8.5; MariaDB contracts ran on PHP 8.3. Live-browser and remote-CI
validation are not included.

Current static coverage is 357 mapped tables, 803 enforced references, eleven
discriminated references, 53 polymorphic references and one pending ordinary
candidate. The inventory reports 2,968 legacy/native SQL call sites, including
2,945 adapter calls and 23 direct-driver calls. The transfer changes remove seven
explicit adapter call sites and replace five generic count queries with owning
association checks. These counts are discovery evidence; the full ORM/FK
conversion remains active.

### Validation after consolidated identifier widening

All 116 CLI contracts have completion evidence on PostgreSQL 17.5 and MariaDB
12.2.2 through the full suites and focused reruns. These were not uninterrupted
clean full runs: PostgreSQL passed 115/116 and its all-foreign-key contract passed
on rerun after a concurrent migration released relation locks. MariaDB passed
113/116; the installation-catalogue check passed on rerun, and the two reconstructed
typed migration fixtures now use widened target IDs, matching the master's stage
order. Both corrected fixtures pass on both providers.

The consolidated command also upgrades populated, previously 32-bit schemas on
both providers, preserves its journal during preview, converges on repetition,
and enforces native placement references above 32 bits. The MariaDB check resumed
the actual partially applied journal after fixing inline JSON CHECK preservation;
the focused regression verifies that malformed JSON still fails afterward.
The PostgreSQL whole-schema upgrade used a dedicated server configured with
`max_locks_per_transaction=2048` after the default-capacity test server exhausted
its relation-lock pool. PostgreSQL contracts ran with PHP 8.5.10 and MariaDB
contracts with PHP 8.3.33. No browser or remote-CI validation is claimed.

### Software copy and core field discovery

Software, version and license destination lookups use owning Doctrine
associations with bound literal names and serials. Reuse retains the existing
entity and selected-manufacturer rules, including unrestricted manufacturer
reuse when the source has none. Templates and trashed records remain eligible
for this internal selection. Public creation, quantity updates, version copying
and deletion hooks retain their responsibilities. Software validity checks use
the owning license association and retain their global scope.

Mapped core objects discover their scalar and owning join columns from Doctrine
metadata for empty forms and input filtering. Associated-item discovery uses
mapped criteria with the existing item discriminator. Custom plugin queries
retain the existing fallback. Cold-cache software/version/license copying,
associated-item loading and validity updates issue no legacy adapter SQL.

All 116 CLI contracts have passing completion evidence on both providers through
full suites and focused reruns. Each full suite passed 113/116. The metadata
test's DBAL column-list comparison was corrected and passes on both providers.
The migration planner changed concurrently in this checkout; its completion and
schema checks pass against fresh installations from the current source, while
the earlier fixtures predate those storage changes. These are not uninterrupted
clean full-suite runs. Syntax, formatting, SQL-inventory and whitespace checks
pass. No browser or remote-CI validation is claimed.

The static inventory now reports 2,965 legacy/native SQL call sites: 2,942
adapter calls and 23 direct-driver calls. Relationship coverage remains 803
enforced references, eleven discriminated references, 53 polymorphic references
and one pending ordinary candidate across 357 mapped tables. The full ORM and
foreign-key conversion remains active.

### Contract and document transfer bindings

Contract/document transfer snapshots the selected links through their owning
parent association. Other asset kinds with the same numeric ID remain outside
the selection. Destination reuse compares bound literal names in the target
entity, includes templates/trash where applicable, and selects a stable ID.
Contract reuse now reads the destination result rather than consuming the next
source link. Parent moves and copies keep the existing public callbacks;
retargeting, link copies and scoped unlinking use ORM persistence. Cleanup only
trashes or purges an old parent after checking its owning references.

The shared distinct-item-kind helper compiles mapped criteria through ORM while
retaining subclass filters, reverse document links and the iterator contract.
Custom plugin queries retain their existing extension path.

Public dropdown labels, including the tree labels used by history, read mapped
records through ORM. Translation joins bind the item kind, field and language.
Tree projections select their display columns without loading the complete
entity settings row. Existing fallback names, root entity zero, contact and
location tooltips, stored comments and encoded tree separators are retained.
Unmapped plugin dropdowns keep their existing lookup path.

All 117 CLI contracts pass in uninterrupted full runs on PostgreSQL 17.5 with
PHP 8.5.10 and MariaDB 12.2.2 with PHP 8.3.33. The new transfer contract exercises
IDs above 32 bits, literal names, destination reuse, owning link mutation,
shared-parent retention and public parent copy/trash/purge with a cold schema
cache and zero legacy adapter queries. It isolates parent transfer callbacks
from the other transfer stages. The expanded translation contract covers plain
and tree labels, language/type isolation, missing values, root zero and tooltips.
Syntax, formatting, SQL-inventory and whitespace checks also pass. No browser
or remote-CI validation is claimed.

The inventory reports 2,949 legacy/native SQL call sites, including 2,926 adapter
calls and 23 direct-driver calls. This batch removes sixteen explicit adapter
call sites; the shared kind/label helpers also bypass adapter execution for
mapped core tables. FK coverage remains 803 enforced references, eleven
discriminated references, 53 polymorphic references and one pending candidate
across 357 mapped tables. The full conversion remains active.

### Fresh inherited settings and asset classification discovery

Fresh inherited setting columns, enum defaults and selection constraints now
come from the owning associations and their mapped mode fields. The installer
no longer reads the historical inherited-field snapshot. The versioned upgrade
retains its frozen inputs and still validates existing installations independently.

The default inventory report discovers mapped record classes through
`EntityRegistry`. `AssetClassification` declares the report's classification
role on the owning property, so grouping queries use that association directly.
The repository's duplicate asset type catalogue and association-name convention
have been removed. An Appliance added to the configured report types exercises
the same ORM queries, entity visibility and trash filters as existing assets;
unmapped plugin types retain their existing extension path.

Both fresh installations pass all 117 CLI contracts in full runs: PostgreSQL
17.5 with PHP 8.5.10 and MariaDB 12.2.2 with PHP 8.3.33. Inherited settings retain
their native FK/CHECK enforcement, sentinel compatibility, permissions, public
lifecycle behavior and upgrade retry checks. Reporting verifies scoped counts,
unclassified groups, additional mapped assets and zero adapter query execution.
Syntax, scoped formatting and the SQL inventory contract also pass. These are
local CLI/database checks; browser behavior and remote CI are not validated here.

### Planning recall subjects

Planning recalls select one of six owning Doctrine associations declared on the
record: ChangeTask, ProblemTask, Reminder, TicketTask, ProjectTask or
PlanningExternalEvent. Native foreign keys protect those subjects, and a CHECK
requires exactly the branch selected by the item discriminator. The legacy
`items_id` is generated from the selected association. Rescheduling queries the
owning association directly with a BIGINT parameter, preserving isolation when
different item kinds share the same numeric ID.

Fresh required-subject columns, generated identity and selection constraint are
derived from the owning mapping. The versioned upgrade keeps its frozen scope,
refuses unsupported, missing or conflicting legacy subjects before DDL, and
supports retry after partial or complete conversion. Existing plugin recall
kinds require an explicit mapped extension before this upgrade can accept them.

ProjectTask and PlanningExternalEvent public purges now remove their recall
children through the existing lifecycle, including delivery-marker cleanup.
The other four subject types already perform this cleanup. Direct native parent
deletion remains restricted while a recall references it.

The recall contract verifies all six branches, IDs above 32 bits, overlapping
identities, canonical retargeting, same-unit-of-work parent creation, invalid
native selections, public recall edits, rescheduling, delivery markers and
parent/user purges. Upgrade checks retain the scheduling date and offset,
exercise invalid-input refusal and verify idempotent retry. The mapped query
paths execute no legacy adapter queries.

The placement upgrade fixture also uses a bounded legacy identifier, independent
of sequences advanced by wide-ID contracts, and restores its schema after a
rejected upgrade. This keeps repeated MariaDB matrix runs from overflowing the
old INT key or leaving subsequent contracts with partial placement tables.

Both providers complete clean 118/118 full runs: PostgreSQL 17.5 with PHP 8.5.10
and MariaDB 12.2.2 with PHP 8.3.33. PostgreSQL's full run precedes the placement
fixture correction, which also passes a subsequent focused PostgreSQL run;
production source remains unchanged. MariaDB's final full run uses a fresh
installation and includes both fixture corrections. Syntax, scoped formatting,
SQL inventory and whitespace checks pass. These are local CLI/database checks;
browser behavior, remote CI and publication are not validated here.

The static inventory reports 809 enforced references, twelve discriminated
references, 52 remaining polymorphic references and one pending candidate across
357 mapped tables. It still reports 2,949 legacy/native SQL call sites, including
2,926 adapter calls and 23 direct-driver calls. The full conversion remains
active; this batch does not reduce the explicit adapter-call inventory.


## Calendar object subjects and fresh required-subject discovery

Calendar data now declares six owning subject associations on `Entity\VObject`.
Foreign keys and a selection CHECK require the discriminator's matching subject;
the compatibility `items_id` is generated from that association. Raw iCalendar
data, custom properties, timestamps and the existing unique subject key remain
preserved. The frozen `VObjectSubjects` upgrade audits unsupported, missing and
conflicting subjects before DDL and supports retry after partial conversion.

Fresh required string-discriminator subjects are discovered from Doctrine
metadata. Their owning columns, generated identities and selection CHECKs no
longer depend on historical ITIL, project-team, reservation, physical-placement
or planning-recall target lists. Optional and fallback identities retain their
separate schema handling; the legacy baseline importer still exists. ITIL owning
records also declare their stable subject CHECK name. This prevents fresh
installation and the frozen upgrade from adding duplicate checks, which would
otherwise interfere with MariaDB legacy-table reconstruction.

CalDAV UID lookup uses bounded, parameterized Doctrine queries against the
mapped subject classes, preserving ambiguity detection and BIGINT identities.
Unmapped plugin calendar kinds retain their public model lookup extension.
Reminder's user and entity audience loaders use the existing record repository.
CalDAV deletion invokes the public purge lifecycle so stored calendar data,
planning recalls and delivery markers are removed before the subject.

The calendar contract exercises all six subjects, overlapping IDs above 32 bits,
native invalid selections, same-unit-of-work persistence, public CalDAV CRUD,
custom iCalendar properties, literal and ambiguous UIDs, parent purges, and
upgrade data preservation/refusal/retry. The public core UID lookup and calendar
conversion execute no legacy adapter SQL.


After retaining the stable ITIL CHECK names on their owning records, both fresh
installations complete clean 119/119 full contract runs: PostgreSQL 17.5 with
PHP 8.5.10 and MariaDB 12.2.2 with PHP 8.3.33. The first MariaDB run exposed the
duplicate-CHECK reconstruction problem; the corrected run includes the exact
failure path and final schema check. Scoped source hashes remain unchanged
during both clean suites. Syntax, formatting, SQL inventory and whitespace
checks pass. Browser behavior, remote CI and publication remain unverified.

The current inventory reports 815 enforced references, thirteen discriminated
references, 51 polymorphic references and one pending candidate across 357
mapped tables. Legacy/native call sites total 2,946: 2,923 adapter calls and 23
direct-driver calls. This batch removes three explicit adapter calls; broader
relationship and SQL conversion remains active.


## Alert subjects and association-based deduplication

Alerts declare ten owning subject associations on `Entity\Alert`, covering the
core stock, certificate, contract, financial, reservation, software-license,
planning-recall, cron-task and user producers. A generated compatibility identity
and selection CHECK require one matching positive subject. Native foreign keys
restrict parent deletion while its alerts remain, and the existing unique key
continues to separate event types for each subject.

Certificate, CronTask and Reservation public purge hooks now remove their alert
children before deleting the parent. Other producers already perform this
cleanup. Native ORM creation also initializes the required delivery date without
relying on a provider's treatment of a NULL timestamp.

Alert existence, delivery-date and latest-alert display use bounded ORM record
queries. Stock threshold, certificate/license expiry, password notice,
reservation expiry and planning recall deduplication query the owning alert
association directly; cron throttling uses its canonical cron-task reference.
These retain the existing event-type, date and strict-boundary rules.

Cross-type notification tests now use real supported subjects with overlapping
IDs. Their old fake subjects would become orphans under native foreign keys;
replacing them preserves the query-isolation test while honoring the new schema.
The alert contract checks all ten producers, IDs above 32 bits, event uniqueness,
invalid native selections, retargeting, same-unit-of-work creation, public reads,
clear/purge behavior and parent cleanup. A reconstructed legacy fixture checks
unsupported/orphaned/conflicting input refusal, event/date preservation and
idempotent upgrade retry through the frozen `AlertSubjects` stage.


The consumable upgrade fixture uses an explicit recipient ID within the legacy
INT range and restores its schema in cleanup. Wide producer tests advance
MariaDB's auto-increment sequence even after rollback; relying on the next user
ID would overflow the reconstructed legacy key and obscure the intended
conflicting-recipient audit. The bounded fixture preserves that audit and avoids
leaving later contracts with partial recipient columns.

The project-team legacy fixture likewise selects a bounded user ID and restores
both team schemas during cleanup. Notification recipient collision controls use
an ID within the opaque integer code's range; separate group and profile cases
explicitly verify identifiers above 32 bits through their owning associations.
These controls keep legacy preflight tests independent of MariaDB sequences
advanced by earlier wide-ID contracts.

Dedicated fresh installations pass the full 120/120 contract matrix on
PostgreSQL 17.5 with PHP 8.5.10 and MariaDB 12.2.2 with PHP 8.3.33. The final
MariaDB suite includes all fixture corrections. PostgreSQL's full suite preceded
those test-only corrections; all three corrected contracts subsequently pass
there individually. Scoped production fingerprints stayed unchanged. PHP
syntax, scoped formatting, SQL inventory and whitespace checks pass. These are
local database/CLI results; browser behavior, remote CI and publication remain
unverified.

The current inventory reports 825 enforced references, fourteen discriminated
references, 50 polymorphic references and one pending candidate across 357
mapped tables. It still lists 2,943 legacy/native SQL sites: 2,920 adapter calls
and 23 direct-driver calls. This batch removes three explicit adapter calls;
the wider migration remains active.


## Object lock subjects

`Entity\ObjectLock` now owns thirty explicit subject associations for the core
lockable objects. Each association has a native foreign key; a generated legacy
identity and a selection CHECK require exactly one matching positive subject.
The locking user remains a separate owning association. Runtime subject discovery,
normalization and fresh schema generation read these entity mappings.

Public parent purges clear their own object locks through the shared model
lifecycle before deleting the parent. The cleanup derives supported subjects from
Doctrine metadata. It preserves locks on other kinds with overlapping IDs. Native
creation initializes the lock timestamp, and the existing unique key continues
to prevent two locks on the same subject.

The frozen `ObjectLockSubjects` upgrade audits unsupported, orphaned and conflicting
selections before DDL. It preserves the owner and original modification date when
copying the subject, so conversion does not renew an expiring lock. The shared
typed upgrade uses numbered join aliases to avoid reserved target names and
preserves the legacy identity column comment when recreating its generated key.
Historical upgrade scopes remain frozen; current runtime relationships belong to
the owning entities.

The new contract exercises all thirty subject foreign keys, native CHECK and
uniqueness rejection, identifiers above 32 bits, public lock status and strict
expiry boundaries, native persistence, canonical retargeting, explicit unlock,
all thirty public parent purges and idempotent legacy upgrade. Reconstructed
legacy ITIL and reservation fixtures use bounded parent IDs independently of
MariaDB sequences advanced by wide-ID tests. Generic foreign-key rejection
fixtures also distinguish real root ownership from positive lock subjects.

The static inventory now reports 855 enforced references, fifteen discriminated
references, 49 polymorphic references and one pending candidate across 357 mapped
tables. It lists 2,943 legacy/native SQL sites: 2,920 adapter calls and 23 direct
PostgreSQL-driver calls. Of the adapter calls, 2,491 are under `install/` and 429
are elsewhere. This path grouping does not establish which calls still need ORM
conversion. The broader relationship and runtime SQL migration remains active.

The 121-contract fresh-install matrices completed with 120/121 passes on
PostgreSQL and 119/121 on MariaDB before the final corrections. Both exposed the
generic parent-purge fixture's root-ID assumption; MariaDB additionally detected
the generated identity's missing comment. DBAL omits automatic inline comments
for custom column definitions, so both fresh declarations and typed upgrades now
include the platform's comment declaration explicitly.

After these corrections, the thirty-subject lock contract, generic FK rejection
and parent-purge contracts pass on both providers. New untouched installations
also pass schema comparison. Rechecking schema after the matrix exposed a
calendar upgrade fixture that recreated a nonunique lookup index as unique; the
fixture now preserves its original uniqueness. Schema, entity metadata, master
upgrade/retry and calendar upgrade/cleanup pass together, including a final
schema check after calendar cleanup. These targeted reruns verify the affected
contracts; a full 121/121 matrix was not repeated after the last corrections.

Validation used PostgreSQL 17.5 with PHP 8.5.10 and MariaDB 12.2.2 with PHP 8.3.33.
Scoped production/test fingerprints remained unchanged during final checks;
syntax, scoped formatting, SQL inventory and whitespace checks pass. This is
local database/CLI evidence. Browser behavior, remote CI, publication and a live
replicated deployment remain unverified.


## Ticket automatic actions

`TicketAutomaticActionRepository` selects candidates for automatic closure,
closed-ticket purge, overdue alerts and satisfaction surveys through mapped
Doctrine queries. Entity enumeration also uses ORM. The callbacks retain their
public ticket/entity/satisfaction model operations, history and notification
hooks, per-entity accounting, working-calendar calculations and inherited
configuration resolution. The supplied application connection and transaction
remain authoritative.

Closure and purge keep strict elapsed-day comparisons; working-calendar closure
and survey delay/duration cutoffs remain inclusive. Zero closure/purge delays
keep undated rows eligible. Purge still includes soft-deleted closed tickets.
Survey selection distinguishes the inherited selection watermark from the
ticket entity's own stored duration gate, excludes existing surveys through a
mapped association, and retains the existing parent-watermark routing and
sampling behavior. Identifier snapshots allow public hooks to change/delete
the selected rows without mutating the active query cursor.

The new automatic-action contract checks entity isolation and identifiers above
32 bits, exact date boundaries across the spring clock change, null dates,
working and empty calendars, public status/date hooks, inherited settings,
purge cleanup of constrained locks and surveys, repeat runs and sampled-out
watermark advancement. Candidate repository operations issue no legacy adapter
SQL. Overdue event/accounting runs with delivery modes disabled; external
notification delivery was not exercised.

The automatic-action, entity configuration, scheduler, application CRUD, ITIL
task/user, notification target/queue and schema contracts pass together: 9/9 on
PostgreSQL 17.5 with PHP 8.5.10 and 9/9 on MariaDB 12.2.2 with PHP 8.3.33.
The suite now contains 122 contracts; the full matrix was not repeated for this
runtime-only change. Syntax, scoped formatting, SQL inventory, source fingerprints
and whitespace checks pass. These are local database/CLI results; browser,
remote CI and replicated-deployment behavior remain unverified.

This batch removes seven explicit adapter calls. The static inventory remains
at 855 enforced references, fifteen discriminated references, 49 polymorphic
references and one pending candidate across 357 mapped tables. It now lists
2,936 legacy/native SQL sites: 2,913 adapter calls and 23 direct-driver calls.
These counts include historical installation SQL and require semantic review;
the broader migration remains active.

## Ticket asset associations and queries

`ItemTicket` owns the twenty core ticket asset associations. Each uses a real
foreign key, with a database check requiring exactly the association selected
by `itemtype`. `items_id` is a generated compatibility identity. Fresh schema
uses the owning entity metadata; the versioned `TicketAssets` upgrade retains
its frozen scope and rejects unsupported, orphaned or conflicting legacy
references before DDL. Existing lookup indexes and relation IDs are preserved.

`TicketAssetRepository` follows the selected owning association for active and
recent ticket lookups, counters, the incident/demand picker, cost rows and
transfer snapshots. Public methods retain their row iterator, currency/rounding
behavior and model hooks. Transfer keeps the selected relation identifier for
retargeting and fixes the keep-ticket branch to delete that actual identifier.
This removes six explicit legacy adapter calls.

The ticket asset contract checks all twenty kinds with overlapping IDs above
32 bits, native FK/check/uniqueness rejection, native ORM persistence, partial
updates and retargeting, strict solved-date boundaries across the spring clock
change, null dates, deleted tickets, ticket type filters, mixed-sign cost rows,
public transfer and purge behavior, legacy upgrade preflight and retry. Lookup,
cost and snapshot queries issue no legacy adapter SQL.

Both fresh-install matrices passed 122/123 contracts. The sole failure was a
hardware statistics fixture that still created empty/zero asset links; it now
uses an unlinked ticket to verify exclusion and rejects a noncanonical asset
type. That corrected contract passes on both providers, and final post-matrix
schema comparisons pass: all 123 current contracts have passing evidence on
PostgreSQL 17.5/PHP 8.5.10 and MariaDB 12.2.2/PHP 8.3.33. The complete matrix was
not restarted after this test-only correction. Scoped source fingerprints, PHP
syntax, formatting, migration JSON, SQL inventory and whitespace checks pass.
These are local database/CLI results; browser, remote CI and replicated
deployment behavior remain unverified.

The current audit records 357 mapped tables, 875 enforced references, sixteen
discriminated references, 48 polymorphic references and one pending candidate.
The SQL inventory records 2,930 legacy/native sites: 2,907 legacy adapter calls
and 23 direct-driver calls, including historical installation code. The broader
migration remains active.

## Change and problem asset associations

`ChangeItem` and `ItemProblem` now own all twenty core helpdesk asset kinds.
Together they add forty foreign keys. Required checks allow exactly one
association matching `itemtype`; `items_id` remains a generated compatibility
identity. Ticket, change and problem links share the actual owning properties
in `ITILAssetAssociations`, while each keeps its own parent, uniqueness and
legacy discriminator width. Runtime relationships are still derived from
Doctrine declarations, with no separate schema catalogue.

`ITILAssetRepository` follows the asset and parent owning associations for active
change/problem pickers. It obtains the parent/link metadata from the existing
ITIL statistics declarations, preserves exclusion of solved/closed/deleted
objects, returns stable ID/name/priority projections and keeps the public row
iterator interface. User/group/supplier tab counts also execute mapped ORM
counts. These paths remove four direct adapter calls.

Cold entity-tree cache misses in public uniqueness validation previously issued
adapter schema probes. Tree helpers now obtain cache-column availability from
the mapped entity metadata, retaining schema discovery for unmapped plugin
tables. The transfer contract disables schema caching and clears its fixture
tree-cache keys before the public move, preventing warm caches from masking
those calls. It separately checks zero adapter SQL and the resulting entity.

The dedicated contract exercises every asset kind with overlapping wide IDs,
native FK/check/uniqueness rejection, native ORM graph persistence, retargeting
and partial updates, active/finished/deleted filters, both public picker APIs,
actor tab counts, parent and all twenty asset purges. It reconstructs both legacy
relationship tables, verifies refusal of unsupported/orphaned/conflicting
references before DDL, preserves IDs and index uniqueness, and checks retry
idempotence. A temporary fixture index keeps MariaDB parent foreign keys
enforceable while reconstructing an old composite index. The production upgrade
remains frozen in `ChangeProblemAssets`.

The full first matrices passed 123/124 contracts each. PostgreSQL exhausted
the unchanged 128 MiB memory limit while purging the expanded relationship
graph; collecting discarded Doctrine managers every eight operations resolves
it. The measured standalone ORM rerun peaks at 126,353,408 bytes. MariaDB
exposed the cold-tree schema probes above; the strengthened cold-cache transfer
contract now passes on both providers.

The final source set passes a full 124/124 matrix on PostgreSQL 17.5/PHP
8.5.10. MariaDB 12.2.2/PHP 8.3.33 passed 123/124; its sole failure was a missing
`glpi_changes_items.item` index in the older disposable fixture, left behind
by the initial failed test reconstruction. Comparing against the independent
fresh fixture showed that as the only index difference. After restoring the
exact declared index, the asset upgrade contract preserves it and the schema
contract passes. All 124 current contracts therefore have passing evidence on
both providers; the full MariaDB matrix was not restarted after this fixture
correction. Both final post-matrix schema comparisons pass.

All seventeen scoped source fingerprints stayed unchanged during the final
matrices. PHP syntax, scoped formatting, frozen migration JSON, SQL inventory
and whitespace checks pass. These are local database/CLI results; browser,
remote CI and replicated-deployment behavior remain unverified.

The audit now records 357 mapped tables, 915 enforced references, eighteen
discriminated references, 46 polymorphic references and one pending candidate.
The SQL inventory lists 2,926 legacy/native sites: 2,903 adapter calls and 23
direct-driver calls, including historical installation SQL. The broader goal
remains active.

## Contract asset associations and queries

Contract links now declare thirty-five nullable owning Doctrine associations:
eighteen configured assets and seventeen installed-component kinds. Exactly one
association must match `itemtype`; the compatibility `items_id` is generated from
that association. Native foreign keys, the selection CHECK and the existing unique
key reject orphans, missing subjects, unsupported kinds and duplicate links.
Twelve common asset properties are shared with ITIL links through
`AssetAssociations`; component properties live in `DeviceItemAssociations`.
Neither trait contains a separate relationship catalogue. The versioned
`ContractAssets` migration freezes its upgrade targets and audits legacy rows
before DDL, preserving identifiers and indexes on a retry.

Contract cloning and scoped asset lists use ORM queries. Lists count first and
load rows only within the existing display limit; installed-component names join
their owning definition association. Transfer operations retarget and query the
selected owning asset, retaining parent callbacks and overlapping-kind isolation.
`Contract_Item` contains no direct adapter query calls. Document bindings were
converted in the subsequent document-subject stage described below.

Local PostgreSQL and MariaDB runs each passed 123 of 125 contracts initially.
The remaining two tests were corrected and rerun successfully: the curated ORM
fixture now selects one contract subject instead of populating all branches, and
the ticket test compares supported kinds without depending on reflection order.
All 125 contracts therefore have passing evidence on both providers; the full
suite was not restarted after those test-only corrections. Production sources
remained unchanged during validation. The new contract covers all thirty-five
native/public branches, wide overlapping IDs, FK/CHECK/uniqueness rejection,
component names, bounded scoped lists, template exclusion, transfer, cloning,
purge and legacy upgrade refusal/index preservation/retry.

The current audit records 357 mapped tables, 950 enforced references, nineteen
discriminated identities, forty-five polymorphic candidates and one pending
candidate. Static discovery still finds 2,901 legacy adapter sites and 23 direct
driver sites. The detached runtime schema catalogues are removed; the overall ORM
conversion remains incomplete.

## Document subject associations and queries

Document links now declare thirty-three owning Doctrine subject associations.
The selected subject must match `itemtype`; the compatibility `items_id` is a
generated, read-only projection. Native foreign keys, a selection CHECK and the
existing composite unique key enforce the graph. Twelve common asset properties
come from `AssetAssociations`; the remaining subject properties are declared on
`DocumentItem`. Runtime targets and policies come from those owning properties.
The versioned `DocumentSubjects` migration freezes only the historical upgrade
scope and audits legacy rows before changing the schema.

The subject document, entity and user have separate columns from the attachment's
parent document, ownership entity and author. The entity subject permits the real
root identifier zero through its property-local discriminator policy; NULL still
means that no subject is selected. Every other subject requires a positive ID.
The shared native lifecycle, input normalization and current schema CHECK honor
that policy; historical migration stages keep their own frozen policy.

Attachment lookup and listing query the selected owning association, including
both directions of document-to-document links. Transfer lookup, copy, retarget
and unlink also use owning associations, with no remaining scalar fallback.
The focused contract covers every native/public subject kind, overlapping IDs
beyond 32 bits, root zero, distinct ownership/author roles, missing and invalid
subjects, FK and uniqueness rejection, lookup ordering, transfer, public purge,
legacy upgrade refusal, partial conflicts, index preservation and idempotent
retry. The generic ORM purge fixture now selects one document subject rather
than appending a conflicting legacy identity.

The complete local matrices passed 124/126 contracts on PostgreSQL 17.5/PHP
8.5.10 and 125/126 on MariaDB 12.2.2/PHP 8.3.33. The corrected dropdown lifecycle
contract passes on both providers. The generic parent-purge contract now gives
each attachment graph a distinct parent, avoiding duplicate subjects retained
after author cleanup; that correction passes on both fresh fixtures and within
the MariaDB matrix. All 126 distinct contracts therefore have passing evidence
on both providers; the matrices were not restarted after these test-only
corrections. Production source fingerprints remained unchanged during validation.

Independent fresh installations on both providers pass the initial portability,
initial-data, entity-metadata and document-subject contracts. The first fresh
attempts used missing cache directories and exhausted the memory-cache limit;
preparing the cache and using new disposable databases resolved the fixture
setup failure without changing production code. These results cover local
database/CLI behavior; browser, remote CI and replicated deployment are not
verified by this batch.

Both final post-matrix schema comparisons pass against the current Doctrine
mapping, after all table-reconstruction contracts. Fourteen scoped source
fingerprints match the final verified files; syntax, formatting and whitespace
checks pass.

The audit now records 357 mapped tables, 983 enforced references, twenty
discriminated identities, forty-four polymorphic candidates and one pending
candidate. Static discovery still finds 2,901 legacy adapter sites and 23 direct
driver sites. The broader relationship and SQL conversion remains active.

## Infrastructure asset associations

Certificate, domain and cluster links now declare twenty owning subject
associations: nine certificate kinds, nine domain kinds and two cluster kinds.
Exactly one association must match `itemtype`; `items_id` is its generated,
read-only compatibility projection. Native foreign keys, selection CHECKs and
the existing composite unique keys reject orphans and invalid or duplicate
subjects. Entity properties declare runtime targets and discriminator policies;
the three versioned upgrade stages freeze only their historical target scopes.

Domain asset and associated-domain lists query owning associations and retain
the original entity, recursion and template criteria. Relation-category tabs
select `domainrelations` independently of the selected asset. The associated
domain query hydrates each link as its root, preserving several associations
that point at the same domain rather than collapsing them into one result.
External domain-name lookup and global cluster selection also use owning
identities, including identifiers beyond 32 bits. `Domain_Item` has no direct
adapter query calls; its exhausted second rendering loop and empty wrapper were
removed with the duplicate query code.

Restrictive subject foreign keys exposed missing certificate cleanup when
purging a phone. The shared purge path now derives certificate subject support
from Doctrine metadata and invokes the existing relation lifecycle. It covers
all nine certificate subject kinds without adding a detached relationship list.
The focused contract exercises all twenty native/public graphs, selected branch
retargeting, overlapping wide identifiers, FK/CHECK/uniqueness rejection, scoped
queries, category tabs, public rendering, parent/subject purges and legacy
upgrade refusal, partial conflicts, index preservation and idempotent retry.

The focused infrastructure contract passes on PostgreSQL and MariaDB. Fresh
installation on both providers also passes the portability, initial-data,
entity-metadata and infrastructure contracts (four on each). Scoped syntax, JSON,
formatting and whitespace checks pass.

The current audit records 357 mapped tables and 1,003 enforced references, with
twenty-three discriminated identities, forty-one polymorphic candidates and one
pending candidate. The domain conversion removes five legacy query sites; static
discovery now finds 2,896 adapter sites and 23 native driver sites. The wider
relationship and SQL conversion remains active.

## Cloud frozen history and warranty checkpoint (2026-10-02)

Installation now replays the explicit 355-table DBAL baseline, frozen seeds and
canonical upgrades through the existing ledger. Current entity metadata cannot
rewrite historical installation definitions. Both provider full suites pass
129/129, including fresh replay, populated adoption, interruption/retry and
final schema comparison. See [the durable handoff](modernization-handoff.md) for
exact environment versions, validation coverage and remaining release blockers.

Warranty expiration now uses the existing Infocom repository and its owning
Alert association. The Infocom entity calculates calendar-month expiry with
month-end clamping; notification rendering agrees with database selection.
Inherited entity configuration, notification/accounting, alert uniqueness and
asset-purge hooks are exercised on both providers. Infocom has no direct adapter
query sites. The static inventory now records 2,895 adapter sites and 23 native
driver sites; polymorphic and application persistence conversion remains active.

HTTP installation/login and report checks pass on both providers after rebuilding
assets. MariaDB legacy checks pass 154 methods/9,388 assertions. PostgreSQL browser
and broader legacy checks reveal remaining API collection, category selection,
locale and legacy query/fixture gaps; the port remains experimental. Passing CLI
contracts must not be described as complete application or remote CI validation.

## Ticket, document and upgrade checkpoint (2026-10-02)

Ticket API collections now query owning actor/group/validation associations with
a session-derived authorization snapshot; entity scope remains an independent
restriction. Ticket category choices use owning entities and recursive scope.
Document attachment reads preserve binding identities and public model hooks.
Fixed CHAR properties own padding semantics, and temporal filtering derives its
SQL projection from the mapped field type.

Canonical adoption preserves generated-column comments/nullability across
interrupted widening and synchronizes PostgreSQL sequences without rewinding
advanced allocation. Frozen baseline, seed and adoption snapshots remain unchanged.
Both providers pass all 132 discovered portability contracts and final read-only
schema checks. PostgreSQL application checks pass 108 methods/5,327 assertions;
MariaDB passes 154 methods/9,388 assertions. All ten browser tests pass on each
provider after rebuilding assets. These results supersede the earlier API, CHAR
and broader PostgreSQL failures recorded above; remote CI, release-version
matrices and live replica validation remain unverified.

The durable goal remains open: 2,893 adapter query sites, 41 polymorphic candidates
and one unresolved historical identity still require architectural work. See
[the handoff](modernization-handoff.md) for exact evidence and the next project-link
ownership migration, including clone, purge and plugin-import boundaries.

## Project subjects, nested ticket routes and category flags (2026-10-02)

All 35 project subjects now own typed associations, with a separate Project
subject role from the containing project. Domain queries preserve binding IDs,
public model hooks, entity/template/rights scope, notifications and purge/clone
behavior. Historical target declarations are frozen in an appended canonical
migration; current entity metadata owns runtime declarations. Parent asset
cloning also replaces every old subject association when copying typed relations.

Ticket parent collections resolve actual direct/inverse ownership or typed
ItemTicket subjects; unsupported routes return a client error. Category incident,
request and problem flags are real boolean properties with a separate frozen
conversion. Current read-only PostgreSQL schema inspection derives flag types
from properties rather than another runtime registry.

Full integrated suites pass 137/137 contracts and clean final schemas on both
providers; all ten browser tests pass each. An extra PostgreSQL Entity run still
exposes WHERE(1) from boolean literal criteria, which is being repaired in the
next isolated appliance/import batch. See the durable handoff for exact coverage,
remaining 2,892 adapter sites and release/replica limits.
