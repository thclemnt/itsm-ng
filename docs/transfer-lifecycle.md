# Transfer lifecycle ownership

This batch is prepared in `th/exp/postgres-transfer-lifecycle`, based on
`b3ed706ad69996aca8c3e07f83ede98329696b25`. Database validation is pending an
exclusive validation window. The application modernization goal remains open.

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

Transfer restores its previous maps/options/destination/model state after
failure. A failed source model restores captured fields when its update or a
later hook throws. Request feedback returns to its checkpoint, retaining new
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
read-only routing and selected MyISAM refusal. The Domain extension must be
integrated before the transfer contract runs; it asserts that prerequisite.

Actual evidence so far is PHP lint and whitespace validation only, plus 75
source-only notification scope assertions using the real registration/scope
helpers with inert base classes and translators. That probe used no application
bootstrap or database driver. Its script/log are under
`/workspace/itsm-env/evidence/notification-disable-source-probe.*`.
These are not provider, installation, HTTP, browser or CI results. Required next
validation includes all three new contracts, adjacent Domain/import/transfer,
software/clone/purge/notification/schema contracts and original Transfer,
Computer, Domain and notification-setting classes on both providers, followed
by coherent full suites and real transfer HTTP/browser validation.

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
