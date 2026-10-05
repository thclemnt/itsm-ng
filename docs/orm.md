# Doctrine persistence and schema ownership

This PHP application uses Doctrine ORM 3.5 and DBAL 4.4 or later on PHP 8.2.
PostgreSQL support remains experimental; MySQL/MariaDB remain supported.
[Database operations](postgresql.md), [search architecture](search.md), and the
[current implementation plan](modernization-handoff.md) describe the other boundaries.

## Entity declarations

Current types, boolean semantics, nullability, owning associations and reference
policies belong beside properties in [Database/Entity](../src/Database/Entity).
`EntityRegistry` derives compatibility lookups from Doctrine metadata. Do not add
parallel table/column registries or infer relationship semantics from field names.
Mappings cover 357 core tables; that does not mean all application persistence
has been converted to meaningful ORM domain commands.

Use an owning association when a reference has a real target. Optional legacy
zero selections normalize to NULL only where the property declares that policy;
root entity ID zero is a real identity. Restrictive foreign keys require application
cleanup, authorization and history before deletion. Avoid cascade removal that
bypasses those operations.

Polymorphic subjects require explicit typed branches, exact discriminators,
foreign keys and consistency constraints. A generated read-only legacy `items_id`
projection provides compatibility; it is not an independent writable owner.
Preserve individual link IDs and duplicates permitted by the domain. Domain
category links and asset links have different roles; several physical links to
one Domain must not collapse into one result.

`SchemaIndex` declares physical indexes on their entity, including provider-specific
names. `ReferenceKey` declares generated nullable-reference keys on properties.
Actor entities own their generated keys and indexes: nullable anonymous actors and
alternative email identities retain their distinct uniqueness semantics. Current
schema inspection derives these policies from declarations.

`NativeTimestamp` marks native instant storage beside the owning property. MySQL
TIMESTAMP is not interchangeable with DATETIME: timezone conversion, range,
defaults and automatic touch matter. ORM fields still own hydration, nullability,
defaults and comments. Explicit automatic-clock declarations own native touch and
successful readback; ordinary nullable timestamps do not acquire that behavior.
Calendar dates and local times need their own domain policy.

## Application persistence

Repositories express mapped queries; domain services own business intent and
invariants. Moving arbitrary legacy SQL into a helper or using DBAL alone does
not complete a domain conversion. DBAL remains appropriate for DDL, inspection,
frozen migrations and unmapped historical exports.

`DBmysql` and `DBpgsql` retain their public compatibility APIs while DBAL owns
core transport. New repositories accept raw bound values. `MappedStorage` decodes
pre-escaped legacy input once; `LegacySql` is a lexical bridge, not a dialect
translator. Supported `RecordCriteria` compiles to DQL; unsupported SQL expressions
use an explicit legacy route, never a fallback after a database error. Legacy
scalar rows retain integer flags, nullable values and date strings; decimals remain
strings to avoid floating-point loss.

`CommonDBTM` retains authorization, validation, plugin hooks, audit, notifications,
clone and purge behavior. Supported core reads use `RecordRepository`;
`MappedStorage` and `RecordWriter` persist below the lifecycle. Direct ORM flush
is not an alternative public application API. Bulk SQL, plugin tables and
unsupported query expressions still require deliberate caller-aware conversions.

Pass the selected application adapter to `Orm::create()` and retain its DBAL
connection in repositories, including supplied audit-history read connections.
Entity managers live for one operation: legacy writes do not invalidate Doctrine's
identity map. Mapping configuration and serialized metadata may be cached; managed
objects and mutable metadata must not leak between operations. Source tracing of
read routing is not live replica validation.
An empty authorized entity scope grants no rows; unrestricted scope must be
explicit and retain the caller's authorization.

Commands must preserve the selected writer across preparation and callbacks, refuse
slaves, and respect physical transaction ownership. This is not yet universal: public
callback paths, including Contract alerts and dictionary operations, need an explicit
physical-frame audit and correction. Shared DBAL/legacy frames use
savepoints; logical nesting alone does not prove ownership of a caller transaction.
Managed MySQL/MariaDB sessions require strict SQL modes and, where available,
traditional current locking reads. Relevant locking projections check admission
again. An incompatible caller session refuses without silently changing isolation,
repairing its state or replaying hooks. Rollback cannot undo external delivery or
filesystem effects.

Browser inbox selection belongs to the recipient and declared canonical type/mode;
acknowledgement uses the supplied writer and locks the actual queue row. Software
allocation and VLAN membership preserve endpoint permissions, entity ancestry and
lifecycle behavior. Calendar closures retain individual links and delegate inclusive
and annual date semantics to Holiday. These boundaries need provider and public-flow
validation beyond metadata checks.

Domain's direct commercial Supplier is distinct from `Infocom`'s financial Supplier.
It must share Domain's owner entity, or be recursive with an owner that is an
ancestor of Domain's owner. Domain recursion does not authorize sibling, descendant
or nonrecursive ancestor Suppliers. Entity callbacks and public preparation share
this predicate; raw DBAL writes and later Supplier/ancestor changes require their
own validation. No global concurrency guarantee follows from it.

Registered plugin component subjects can be valid extensions that this bounded
core representation cannot own. Energy-subject adoption refuses unsupported kinds
before canonical DDL and preserves source/plugin data for a reviewed plugin mapping;
it must not relabel those rows as invalid or claim all-plugin convergence.

## Frozen installation and upgrades

[Migration/History](../src/Database/Migration/History.php) exposes one release
transition, [Version220](../src/Database/Migration/Version220.php), from the genuine
2.1.3 historical data format to 2.2.0. Earlier releases must first complete their
historical application's upgrade to that format. Subsequent releases add ORM
migrations; the old MySQL `install/update_*.php` scripts remain historical inputs
and never run against the ORM schema.

Fresh 2.2.0 installation replays the explicit DBAL
[baseline](../src/Database/Migration/V220/Baseline.php), frozen raw seeds and the
same transition. It never installs today's ORM metadata schema and marks history
complete. The 355-table installation input is independent of current entities and
runtime parsing of the old MySQL dump. Helpers and snapshots under `Migration/V220`
belong to this one transition; they are not a sequence of application releases.
Shared typed/reference producers retain their explicit frozen domain policies.

The existing `itsmng_migrations` ledger stores the release receipt and internal
checkpoints. Experimental installations retain their original checkpoint keys and
captured DDL without copying or rewriting journals. They earn the single 2.2.0
receipt only after the pending conversion, final schema inspection and identifier
synchronization succeed. Completion and release publication commit together.
Do not infer genuine historical provenance from mutable rights or version labels.

Frozen definitions and seeds remain independent of future mappings. `BaselineSchema`
projects current requirements for read-only inspection, not installation. Its current
ordinary-column projection still skips existing frozen-baseline columns, and
DisplayPreference, Kanban and operating-system indexes still use frozen builders.
Sole entity-derived authority for every current column/index is unfinished;
schema-check success does not prove it. Comparison includes boolean CHECK
enforcement and declared timestamp touch; other platform expressions, triggers
and CHECKs have comparison limits. Do not weaken comparison to hide drift.

Seed writes and completion commit together; populated adoption never replays seeds.
PostgreSQL DDL is transactional. MySQL journals nontransactional stages, validates
interrupted tables against frozen declarations and resumes after explicit correction.
Sequence synchronization preserves valid reservations and advances beyond imported
identifiers. Stop writers and drain cached sequence users during maintenance.
See [operator recovery](postgresql.md).

## Remaining architecture work

Ticket status remains a legacy ordinal: sorted weight/ID positions, including
inactive gaps, are stored in tickets, not SpecialStatus row IDs. Mutable names
also influence roles. `TicketStatusPreflight` and its scalar ORM repository inspect
bounded actual owners without flush, writes or global adapter replacement. Its
diagnostics do not establish readiness for identity adoption. Stable identity
requires every mutable profile/rule/template/search/integration owner, explicit
roles, permissions, notifications/dates/history and clone/purge behavior. Problem
and Change are separate status domains. Preserve historical audit interpretation.
The preflight intentionally inspects all Ticket owners, including deleted and
cross-entity records, as a private trusted adoption tool, not a public scoped list.
Any future exposure requires explicit global configuration authority. Matching
before/after configuration fingerprints establish observed consistency only: they
do not exclude ABA changes or concurrent Ticket writes and grant no write/adoption
authority.

The existing SpecialStatus front/AJAX management paths still lack explicit
mutation-right admission. Their nontransactional ordinal remapper bypasses Ticket
hooks and history. These concrete ownership defects remain to be repaired alongside
stable identity, rather than hidden behind a repository wrapper.

Legacy queries, plugin interfaces and unresolved relationships remain open. Pure
behavior belongs in the existing unit framework; real provider integration remains
necessary for SQL, constraints, replay and concurrency. Measure query counts,
inspection costs and populated workloads before claiming performance improvements.
[Search architecture](search.md) documents its benchmark and fallback limits.
