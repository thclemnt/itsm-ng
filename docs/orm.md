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

Commands preserve the selected writer across preparation and callbacks, refuse
slaves, and respect physical transaction ownership. Shared DBAL/legacy frames use
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

[Migration/History](../src/Database/Migration/History.php) owns ordered replay and
the existing `itsmng_migrations` ledger. Fresh installation replays the explicit
DBAL [Baseline20261001](../src/Database/Migration/Baseline20261001.php), frozen raw
seeds, adoption and all later migrations. It never installs today's ORM schema
and marks history complete. The 355-table historical baseline is independent of
current entities and runtime parsing of the old MySQL dump.

Historical producers, snapshots and seeds are immutable. Their repeated definitions
preserve replay semantics rather than form runtime registries. Append migrations
when current ownership changes. `BaselineSchema` projects current requirements
for read-only inspection, not installation. Comparison includes boolean CHECK
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

The existing SpecialStatus front/AJAX management paths still lack explicit
mutation-right admission. Their nontransactional ordinal remapper bypasses Ticket
hooks and history. These concrete ownership defects remain to be repaired alongside
stable identity, rather than hidden behind a repository wrapper.

Legacy queries, plugin interfaces and unresolved relationships remain open. Pure
behavior belongs in the existing unit framework; real provider integration remains
necessary for SQL, constraints, replay and concurrency. Measure query counts,
inspection costs and populated workloads before claiming performance improvements.
[Search architecture](search.md) documents its benchmark and fallback limits.
