# Transfer lifecycle ownership

This batch is prepared in `th/exp/postgres-transfer-lifecycle`, based on
`b3ed706ad69996aca8c3e07f83ede98329696b25`. Focused provider validation has
started; the actual final-hook PostgreSQL probe is failing until its separate
shared-connection commit repair is integrated. The application modernization
goal remains open.

`TransferCoordinator` owns the supplied active writer's transaction or DBAL
savepoint. A batch includes simulation cleanup, selected items, recursive
dependencies and final software cleanup. Direct `transferItem` also owns a
frame; recursive calls join the current operation and propagate refusal. A
successful savepoint release leaves the enclosing transaction under its caller's
control. Hooks must preserve transaction ownership; an unexpected change is
reported as failure rather than silently committed as a successful transfer.

Before simulation cleanup, every selected item receives a pure model-owned
`validateEntityTransfer(int $destination)` call. Recursive subjects receive the
same check before their auxiliary mutations. The base hook has no actor/session
policy. Domain's separate commercial supplier extension reuses its owning
association rule: a local or recursive ancestor Supplier must be coherent with
the proposed Domain owner. It neither copies nor clears that commercial role.
The existing financial `Infocom.suppliers` transfer behavior remains separate.
Selected models must own a physical `entities_id`, matching the existing UI
action's capability boundary. A child such as Link_Itemtype inherits its parent
Link's effective scope; direct child transfer now refuses before simulation or
auxiliary work instead of returning a no-change success. Existing owning-Link
transfer remains supported and its child's effective scope follows it normally.

Required public updates/deletes, creations/imports and existing selected raw
mutations now propagate their actual outcomes. Explicit public CRUD success is
boolean true or legacy integer one; positive identifiers must be genuine PHP
integers or valid integer strings. Legacy `transferItem` overrides that return
void retain their existing caller contract, while explicit false propagates.
Selected Item_Disk cleanup calls and checks each public purge with the existing
no-history/notification flags; it does not invent a deletion-unit context to
make a void helper appear to succeed. The smaller persistence fix also makes
`CommonDBTM.update` return false before completed-action hooks, queue delivery
and feedback when its writer returns false. It restores captured stored fields
without reloading the object and retains attempted form input for diagnostics.
The unmapped per-field adapter path likewise propagates a failed write; outside
an owning transaction it still cannot promise atomicity across several fields.

Actual ownership writes that require configured child forwarding now open a
model-owned `OwnershipUpdateUnit` after the final effective write-set and
identity checks, before parent persistence. Parent row/history, completion
hooks and required child updates share the supplied writer transaction or
savepoint. Refused/throwing children roll that frame back. The owning parent's
stored fields and empty pending writes are restored while its attempted input
remains available for diagnostics. Preparatory hooks before this narrow unit
remain outside its standalone rollback boundary; an enclosing Transfer frame
also covers their mutations on the same connection. This does not change every
ordinary application update's transaction semantics.

The common `LifecycleModelJournal` observes actual public updates/deletes on
the participating connection as well as Transfer's explicit copy snapshots.
It restores forwarded children retained by hooks, including a successful
forwarding unit followed by a later Transfer sibling refusal. The single
`LifecycleNotifications` delivery barrier now serves both ownership and
deletion units; failed frames discard pending delivery, successful nested
frames merge, and only a physical commit permits transport dispatch. Caller
savepoint releases keep actual queued rows unsent for cron. Later standalone
caller rollback restores database rows without promising automatic restoration
of already returned model instances. Known configured MySQL parent/child tables
receive native InnoDB checks before parent persistence. Arbitrary indirect
plugin tables and external effects remain outside that storage guarantee.

Full-tree dropdown imports now propagate each refused intermediate node at
the model's import boundary. A rejected Location ancestor cannot silently turn
its intended descendant into a root and produce a successful final identifier.
Prepared contracts cover actual multilevel Location transfer vetoes with owned
and caller transactions, accepted ancestry, and direct Location/TaskCategory
imports. Standalone full-tree import retains its per-node persistence semantics:
accepted ancestors can remain when a later node refuses; its caller owns atomicity.

Transfer restores its previous maps/options/destination/model state after
failure. An operation-scoped journal captures the actual public model instances
before selected mutations; it restores their fields/input/pending write state,
including an earlier successfully updated sibling retained by a plugin hook.
New/copied model fields preserve their original presence as well as values.
It is a checkpoint of participating objects, not a second relationship catalog.
Loaded auxiliary sources are captured before legacy copy preparation clears an
identifier or fields. Actual successful creation results identify fresh rows
within the operation; newly loaded instances of those rows restore their
unloaded state on failure, while a reused source-copy instance restores its
loaded original source. The checkpoint is limited to the four core lifecycle
arrays and their property presence. It is shallow and does not restore opaque
plugin/private derived caches or unrelated models created indirectly by hooks.
Request feedback returns to its checkpoint, retaining new
warnings/errors and discarding rolled-back success messages. The web action
reports `Transfer failed` and retains the selected list on failure; only true
success clears it and reports `Operation successful`.

Temporary notification disabling derives enable flags from the authoritative
registered modes. Ancillary browser settings and the mode catalog are not
enable flags. Scoped disabling restores exact prior enable-flag presence, type
and value, including nested scopes and exceptions. The existing `getModes`
cache enrichment of missing core registrations remains intentional. The old
functional custom-mode fixture now registers its mode through `registerMode`
before testing the same disable assertions.

An empty Contract exclusion list selects all eligible links. Keeping contracts
no longer unlinks a sole local Contract merely because no recursive parent was
excluded. The prepared successful Domain flow retains its original Contract
and Document binding IDs, transfers the original parents, and exercises a
64-bit Domain and binding IDs with distinct commercial/financial Suppliers.

Prepared contracts are `update-writer-refusal.php`,
`notification-disable-scope.php` and `transfer-atomicity.php`. They cover actual
public false/zero/throwing lifecycle outcomes, audit/queued work rollback,
earlier batch items, direct entry, caller transaction markers, disk purge,
required financial Supplier creation, incompatible commercial Supplier
preflight, successful recursive ancestry and no-op behavior, native bindings,
read-only routing and selected MyISAM refusal. Independent source review also
added retained-sibling model restoration, explicit recursive false/throw
outcomes, real-work void overrides, and successful savepoint release followed
by caller rollback. The Domain extension must be
integrated before the transfer contract runs; it asserts that prerequisite.
Additional prepared cases cover standalone/owned/caller DomainRecord refusal,
successful forwarded-child retention followed by later failure, actual queued
transport timing, nested deletion/ownership delivery merge and cancellation,
and standalone native nontransactional parent/registered-child diagnostics.

The initial source evidence was PHP lint, formatter and whitespace validation
(15 PHP files including the commercial Supplier prerequisite), plus 75
source-only notification scope assertions using the real registration/scope
helpers with inert base classes and translators. That probe used no application
bootstrap or database driver. Its script/log are under
`/workspace/itsm-env/evidence/notification-disable-source-probe.*`.
That initial probe was not provider, installation, HTTP, browser or CI validation.
The final combined source also passed lint/formatter/whitespace for 37 PHP files.
Actual focused provider results are recorded below. Required next validation
includes the shared PostgreSQL commit repair, adjacent Domain/import/transfer,
software/clone/purge/notification/schema contracts and original Transfer,
Computer, Domain and notification-setting classes on both providers, followed
by coherent full suites and real transfer HTTP/browser validation.

On 2026-10-03, new owned PostgreSQL/MariaDB databases were cloned from the
validated populated Supplier checkpoint with native template/full-dump methods.
Both actual `db:migrate --apply` checks reported no pending canonical migration.
At source `6e12f89fae59f14a0af3a2a3b52d7f8b9e9e6baf`, the public writer-refusal
contract passed on both engines, notification scope passed 75 assertions each,
and expanded Transfer atomicity passed 362 assertions on PostgreSQL (8.538s)
and 371 on MariaDB (7.132s). These include actual multilevel tree refusals,
standalone/owned/caller DomainRecord forwarding, retained models, physical
notification dispatch, nested deletion scopes, and native MyISAM parent/child
diagnostics with the same-model InnoDB control. These are focused contracts,
not fresh installation, full-suite, original application or browser results.

Two earlier failures are retained as evidence: initially absent dynamic
input/updates/oldvalues exposed unsafe direct Transfer checkpoint reads, repaired
by using the property-preserving model journal for Transfer itself; whole-CFG
comparison differed only in metadata-derived `glpitablesitemtype` and
`glpiitemtypetables` caches populated by fixtures. The corrected Transfer check
preserves exact notification-setting presence/types/catalog/ancillary values;
the dedicated notification scope contract retains its whole-CFG assertions.

The added actual final `post_updateItem` probe at source
`8c2a7202ba25e644fed9886a42b0a709b42e70fb` catches native PostgreSQL division by
zero (22012) and performs no later query. It exposed a real defect: standalone
ownership update reported success when stock DBAL committed an aborted physical
transaction. The contract fails; the separate shared PostgreSQL connection guard
must refuse that commit while preserving a usable caller frame. Its integration
and retest remain pending. The existing generic driver is not modified by this
Transfer batch. Logs, exact source hashes, commands and durations are retained
under `/workspace/itsm-env/evidence/transfer-lifecycle-*`.

Limits: database rollback covers transactional mutations on the participating
connection. Core MySQL tables use InnoDB, and known selected non-InnoDB parent
tables are diagnosed before mutation. Arbitrary plugin child tables, separate
connections, filesystem deletes, attempted logs and external plugin effects are
not restored by a database savepoint. A plugin that commits/releases somebody
else's frame violates the hook ownership contract. The pending software
assignment repair must propagate its own deeper validity-indicator vetoes;
checking the outer assignment result alone does not prove those callbacks
succeeded. Existing Transfer raw queries and other domain families still need
their separate ORM/domain migrations. The separate proposed Domain-owner actor
authorization gap is not silently solved inside this trusted coherence hook.
