# Current software allocation ownership

This batch is a source candidate based on `be258c8bd204dcfa2dfc5763e76e460ab80bb54f`. Its PHP, database contracts, migration replay, application flows and concurrency controls have not been executed. The PostgreSQL failure that motivated it is retained; this document does not claim a corrected runtime result.

The original `software-assignment-current-reads.php` contract failed on PostgreSQL at its unchanged hidden-subject assertion: an independent writer moved a Monitor into entity 2, while its licence and owning Software remained nonrecursive in entity 1. The actual public scalar update returned true. An independently reviewed observer confirmed those locked current scopes and ordinary UPDATE admission false. The unchanged MariaDB contract passed 169 assertions: its earlier repeatable-read snapshot and later locking read detected the change. Equality between two reads is therefore insufficient to establish ownership.

The six Doctrine subject entities declare their allocation scope using their owning entity association and actual boolean recursion property. `SoftwareLicense::allocationEntityScope()` declares effective licence recursion using both its own flag and its actual owning Software flag. The command accepts a final pair when both endpoints belong to the same entity, or when a recursive endpoint is an actual ancestor of the other endpoint. Parent edges come from the supplied ORM writer and current locked Entity rows, independently of legacy cached ancestor strings.

Actual allocation add, update and restore validate this pair before persistence and after public callbacks and required aggregate work. Scalar and unchanged updates use the same final check. The shared insertion producer captures its actual scalar identity before read callbacks, and the outer add lifecycle retains that returned identity before add callbacks. The final row must retain the immutable selected allocation identifier and prepared owner tuple; a callback cannot substitute another row or acquire a different owning aggregate. Delete and purge retain their ability to remove a historically incompatible link. Transfer can move the subject first and then replace its temporarily incompatible old licence with a coherent destination licence.

## Selected hierarchy and command boundaries

A command discovers only its selected graph and its ancestor chains, then locks those Entity rows in identifier order before taking Software, licence and subject locks. An outer transfer reserves its source and destination ancestry before its first recursive public operation. Software merge, dictionary version movement and dictionary licence movement reserve their selected owning graphs before their first aggregate lock. Installation commands also reserve their version's owning Software and subjects needed by nested validity work. Discovery resolves licence owners before collecting sibling licences: a real validity threshold change cannot unexpectedly require another entity during the owning Software callback. Installation dependents are discovered for the bulk, purge and transfer roles that actually invoke those lifecycles, rather than for unrelated scalar validity updates.

Nested operations reuse the outer reservation. They refuse a newly required entity instead of acquiring hierarchy locks behind already held aggregate locks. The command verifies the same parent edges after real callbacks; changing an edge on the same writer cannot silently invalidate the proof. Missing ancestors and cycles are diagnosed without trusting legacy caches.

These boundaries do not establish a global order for arbitrary callers that arrive with independently acquired native row locks. Custom callbacks, other caller-owned transactions and concurrent hierarchy movement remain explicit review and native validation gates. No claim is made that all entity mutation paths follow this order. The new selected-pair invariant is separate from interactive actor authority: caller `can()` checks, authorization changes during a command, and a complete typed distinction between interactive and trusted transfer commands remain open architecture work.

## Required composed ownership dependency

This source deliberately targets the existing managed transaction ownership capability from commit `d211884be16e4fd385f4d3d46566af04282cafd3`; it does not copy that transport or create another transaction registry. ROOT must compose and validate that dependency before executing this batch:

- `itsmng\Database\ManagedTransactionConnection`
- `itsmng\Database\ManagedTransactionScope`
- `itsmng\Database\TransactionOwnership`
- `itsmng\Database\TransactionOwnershipMismatch`
- The actual PostgreSQL and MySQL managed connections and their shared `PdoTransactionOwnership` implementation.

`SoftwareHierarchyUnit` retains an operation-local hierarchy alongside the authoritative captured managed scope. `OwnedMutationFrame` commits and rolls back only that captured frame. If a callback ends or replaces the frame, `MutationRollbackFailure` preserves the actual primary and cleanup exceptions, and the application does not roll back the replacement frame or rewind model/session state as though persisted work reverted. Notification, model-journal and session cleanup are independently guarded; `MutationCleanupFailure` retains the first exception and secondary cleanup, including whether rollback is unproven. Known refusal before a frame is requested restores preparation checkpoints without claiming a database rollback. No hierarchy-specific savepoint, epoch counter or secondary ownership protocol exists.

The dependency detects managed DBAL frame replacement and physical transaction/depth disagreement. It does not prove a native COMMIT followed by native BEGIN that bypasses the managed API while recreating matching physical state. That native epoch problem remains open. This batch makes no claim of authentication, mutation or transfer safety across that unsupported frame replacement.

The separate accepted-update finalization correction must also be composed before the unchanged-input lifecycle controls are validated. It moves the existing finalization check outside the initial nonempty-write branch; this batch does not duplicate that shared lifecycle change.

## Required validation

The new contract `software-allocation-ownership.php` uses actual public lifecycles, real persisted rows and callbacks. It covers visible sibling refusal, actual aggregate validity threshold changes with sibling licences in different entities, scalar and unchanged input, effective licence and reverse subject recursion, stale ancestor cache independence, restore/purge, real relation clone and transfer, late subject/parent-edge changes, late selected-identity substitution, real insertion/read-hook identity substitution against identical-owner existing rows, same-model nested insertion, malformed ancestry, and actual managed DBAL frame replacement without application DML. Failed owned rollback must retain the first Throwable and leave the replacement frame to its creator. A genuine out-of-order notification-scope control checks independent cleanup failure after actual owned rollback; it retains real scope objects for later strictly ordered cleanup and never rewrites private scope state.

The original 169 current-read controls and its PostgreSQL assertion remain unchanged. Native concurrent hierarchy movement and caller lock-order acceptance must be exercised in the composed branch, followed by relevant software, clone, transfer, authorization, history and notification contracts and coherent full suites on both providers. Failed callback commit cannot be reported as reversible; any such experiment must use separately owned disposable state and record actual cleanup.

All 120 migration source files and the additional `BaselineSchema.php` from the base are frozen. This batch introduces no migration, schema rewrite, ledger or historical definition change. The overall application modernization goal remains open.
