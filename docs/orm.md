# Mapped persistence and reporting

Doctrine ORM 3 is an explicit dependency alongside DBAL 4.4+ (PHP 8.2+). The attributes in
`src/Database/Entity` now map all 3,561 columns of all 355 baseline tables,
including the dashboard's composite primary key and explicitly assigned IDs.
`EntityRegistry` lists each table and mapped class. These are persistence records;
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
for all 355 registered core tables through `RecordWriter`. `CommonDBTM` calls it
below lifecycle processing; direct bulk SQL elsewhere is still pending migration.
The writer supports assigned IDs, generated IDs, the dashboard's alternate key,
JSON, native booleans, UUID values and clock boundaries. Bulk legacy SQL can still
write these tables and the same foreign keys remain authoritative.
Calling `EntityManager::flush()` directly is not an alternative application API:
it would bypass those lifecycle services.

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

## Schema ownership

The mappings can generate a scoped schema model with Doctrine `SchemaTool`, and
tests check mapping validity and exact column coverage against the baseline.
The existing installer still owns the complete 355-table schema, including
legacy indexes, provider-specific indexes/triggers and seeding. The entity metadata
does not yet replace that schema or introduce a second installation path. Never apply
`SchemaTool::updateSchema()` or `schema:update --force` to an installation: plugin
tables, legacy indexes and provider-specific definitions are not represented by
these entity mappings. Moving schema ownership requires reviewed, versioned
migrations, including handling of optional zero references.

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
contains 825 candidate reference columns: 130 enforced, 632 pending, 62 polymorphic
and one ambiguous (`users.auths_id`, whose target depends on authentication type).

`tools/database/generate-mappings.php` is a development scaffold for explicit
attributes, not a runtime mapping driver. Review regenerated mappings before use.
`orm-records.php` compares typed ORM records with native rows from each core table
(up to 25 seeded rows per table); empty tables only have metadata/query coverage.
The full conversion is not complete: direct SQL, the native adapters, optional
sentinel references and polymorphic schemas still need migration. Complete entity
mappings are necessary infrastructure, not evidence that every query uses ORM.

Current regression evidence: the full-mapping stage passes the PHP 8.3 Calendar
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
