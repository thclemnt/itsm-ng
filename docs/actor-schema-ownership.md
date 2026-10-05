# Entity-owned actor identity schema

SOURCE-only implementation based on clean
`5611b65a340e46e2c2d3fd8216846c5376ef06e0`, in isolated branch
`th/cloud/postgres-actor-schema-ownership-source`. No PHP/interpreter, compiler,
autoload, dependency, native database, application request, assets, browser or
network validation ran in this worktree. `git diff --check` passes. Integration
requires the execution gates below; this is not a claim that the portability
suite or application is green.

TicketUser, ProblemUser, ChangeUser, SupplierTicket, ProblemSupplier and
ChangeSupplier now declare their exact physical actor identity indexes with
SchemaIndex. MySQL/MariaDB retain `unicity`; PostgreSQL retains each explicit
`glpi_*_unicity` name. Each entity also declares the existing one-column
`glpi_*_actor_parent` supporting index. The old ORM-only constraint names are
replaced, not retained as additional constraints. The ordered unique tuple remains
parent, type, actor_key, actor_email_key.

All twelve generated property definitions remain byte-for-byte unchanged:
nullable BIGINT actor_key, nullable VARCHAR(255) actor_email_key, generated ALWAYS,
excluded from inserts and updates. The actor key coalesces the explicit owning
actor FK to zero. The email key coalesces alternative email to empty only for
anonymous actors and ignores it for named actors. Existing parent/actor typed
associations, EmptySelection/nullability, RESTRICT actions, ApplicationManaged
ownership, role/defaults and different notification defaults remain unchanged.
No new boolean conversion, email normalization, lifecycle service or domain
persistence claim is introduced by this schema ownership batch.

The current required-schema builder projects native column declarations from an
explicitly SchemaIndex-owned read-only ALWAYS-generated property. Its declaration
is authoritative beside the property, including expressions not representable by
ReferenceKey. This generic metadata condition replaces the special requirement
for a ReferenceKey attribute. The five existing tree keys remain admitted;
unowned compatibility subjects retain their own builders. ReferenceKey,
SchemaIndex, AttributeDriver, Dashboard and discriminated subject declarations
are unchanged. There is no actor table-name switch or new expression catalogue.

Only BaselineSchema's current ActorUniqueness::TABLES/addToTable invocation is
removed. ActorUniqueness and ActorReferences remain immutable historical adoption
definitions, used by canonical replay and the unchanged legacy reconstruction
oracle. No migration version, ledger, seed, installer or historical schema is
changed. This is intended to preserve the physical installed schema and current
Upgrade prerequisite behavior; source inspection alone does not prove it.

The existing actors.php contract now adds checks before its original public-flow
and migration body. All original assertions/body bytes remain intact after
removing the added imports and prefix. The new checks alternate offline MySQL,
MariaDB, PostgreSQL and MySQL metadata factories, require exactly the twelve actor
keys plus five original tree keys to own generated index columns, compare
SchemaTool/current key storage and expressions with the frozen adoption oracle,
and require exact unique/supporting index names, tuples, flags and options.
The supplied live provider is inspected for physical names/tuples, key native
type/length/nullability/default and stored generation. Native email expression
equivalence is not claimed from a mere stored-generation check; the original
named/anonymous generated-value assertions still exercise both branches.

The historical oracle remains deliberately independent of current entity
metadata. The original contract covers public actor duplicate prechecks, real
database duplicate refusal, zero/NULL compatibility, alternate email queries,
notification editor availability, additional actors, parent and named-endpoint
purge, invalid targets, retry after rejected data preflights and completed-adoption
idempotency. It does not deliberately interrupt MariaDB DDL or prove journal
resume. No assertion is suppressed or weakened. This batch adds no fixture DDL
beyond the original contract's historical reconstruction and cleanup.

Actor front forms, parent flows, generic API and dynamic/plugin link classes keep
their existing permission/scoping, hook, history, notification, status propagation,
clone/purge and supplied-connection behavior. That is a source preservation fact,
not live application or replica validation. Remaining adapter queries and missing
meaningful actor domain services remain separate modernization work.

Acceptance gates, all UNRUN here:

1. Lint/style the changed PHP files using this worktree's prepared dependencies.
   Compare complete pre/post expected schemas on all three offline platforms:
   every column/type/default/null/comment/generation option, index/name/ordered
   tuple/options, FK/check/extra SQL and unrelated mapping. Confirm exactly twelve
   newly owned generated columns and unchanged tree/Dashboard/subject behavior.
2. Run the extended actors contract and unchanged tree-schema-ownership,
   tree-parents, tree-transaction-cache, ORM/entity metadata and schema-check
   contracts on disposable PostgreSQL and MariaDB databases. Inspect native
   stored expressions and physical index names, which DBAL comparison alone
   does not fully enforce. Require generated readback and original hook/purge
   assertions to pass without additional fixture repairs.
3. Prove canonical fresh installation and populated upgrades/repeated replay
   converge with all historical file hashes unchanged. Exercise interrupted
   nontransactional DDL/journal retry independently of refused-preflight retries.
   Verify current Upgrade prerequisites and read-only checker drift behavior.
4. Run coherent full portability/application suites on the composed source for
   both engines and inspect final schemas. Retain failed attempt evidence. API,
   browser/assets, plugin hooks, live replica and official MySQL are separately
   reported gates; this source result does not establish any of them.

MySQL/Maria email collation and padding can differ from PostgreSQL. This batch
preserves existing expressions and does not establish a new cross-provider email
identity policy. Further runtime migration configurators and identifier-width
projection remain open; removing this one runtime catalogue does not complete
the modernization objective.
