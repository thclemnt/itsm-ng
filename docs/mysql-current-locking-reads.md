# Physical MySQL/MariaDB current locking-read policy

This is a source candidate based on `76cd0896d3f77891396cd7de3b4bc862c6c15140`. No PHP, database, application or official-engine validation has been performed for this candidate. The unchanged `relationship-lifecycle.php` and `software-assignment-current-reads.php` assertions remain authoritative and must pass alongside the new contracts. No installed-schema or inventory milestone is claimed.

The application's software and Kanban lifecycle contracts require current committed row identities under InnoDB repeatable read, even after an ordinary SELECT establishes an older caller snapshot. MariaDB's newer snapshot-isolation mode can abort a locking read of a record outside that view with error1020 and roll back the caller transaction. This cannot be safely repaired by adding lock hints, changing an active caller's isolation or replaying its hooks.

[MariaDB's introduction commit](https://github.com/MariaDB/server/commit/b8a671988954870b7db22e20d1a1409fd40f8e3d) describes that behavior and its transaction rollback. [MDEV-35124](https://github.com/MariaDB/server/commit/4e1e9ea6f322dd8b7b7f4f15fa5f0d743f73ea74) changes the default to ON; fixed upstream11.8.1 source declares TRUE, while10.11.8 declares FALSE. Retained CI excerpts show the Kanban and software licence lock failures. They do not establish the CI session/global variable values, exact server digest or actual native rollback. Those remain separate native proof requirements.

## One physical-session declaration

The existing `MySQLConnection` middleware deliberately retains **traditional InnoDB current locking reads** for application-owned sessions. This opts out of MariaDB's newer snapshot write/write-conflict detection for these sessions. Domain ownership checks, row locks, native foreign keys, CHECKs and unique constraints remain necessary; this policy does not promise serializable isolation or make an unbounded concurrency claim.

On a newly connected physical driver, after existing TLS and strict SQL-mode admission, it reads the actual named SESSION capability. Exactly one lowercase `innodb_snapshot_isolation` row with native ON/OFF is accepted; malformed, duplicated or unexpected identities/values refuse. An absent variable permits MySQL and older MariaDB without issuing a MariaDB-only SET. An already OFF capability needs no SET. An ON capability receives bound `SET SESSION innodb_snapshot_isolation = 0`, followed by mandatory OFF readback. Failure or disappearance refuses admission rather than returning an unproven session. No server-version naming heuristic, global/admin query or shared GLOBAL change is involved.

Every actual physical reconnect follows the same factory policy. Existing configured SQL modes remain preserved with STRICT_ALL_TABLES; TLS options, nonpersistent PDO transport, charset/timezone and supplied endpoint remain unchanged. The initializer refuses an already active PDO transaction. It is a new-session factory operation, not a domain callback or a diagnostic repair.

There is no preexisting caller session to restore when this newly created handle is admitted. The policy belongs to that application handle until close; reconnect admits a new physical session. A caller that later enables incompatible mode gets a read-only refusal. No public command resets the caller's variable, changes isolation, reconnects behind it or retries1020.

## Caller and domain ownership

`MySQLManagedConnection::beginTransaction()` checks actual physical ownership and current-read capability before incrementing DBAL nesting or opening any physical/nested frame. This protects generic public deletion admission before its lifecycle writes and hooks, not just Kanban's late cleanup callback. `SoftwareMutation::assertSupportedIsolation()` translates a capability refusal into its existing software cancellation/feedback before requesting a frame; PostgreSQL strong-snapshot refusal is unchanged.

Four actual repository paths receive read-only checks immediately before current locking projections:

| Family | Actual application callers | Preserved behavior |
| --- | --- | --- |
| KanbanRepository | `Item_Kanban::cleanForParent`, called by `CommonDBTM::cleanRelationTable` inside public deletion | Source state identities, destination collision checks, public relation update/delete and persisted postconditions retain their writer. Ordinary load/save behavior keeps its supplied connection. |
| SoftwareAssignmentRepository | `SoftwareAssignmentService` public licence/allocation/installation mutation, transfer, selected-owner postconditions; software merge removal callbacks | Required aggregates, subject scope, selected canonical rows and locked membership remain typed ORM projections. Empty aggregate lists still return without a policy query. |
| SoftwareInstallationRepository | SoftwareAssignmentService's current transfer membership/count; ordinary Item_SoftwareVersion/Item_SoftwareLicense inventory and API readers | Only explicitly requested current reads require admission. Ordinary counts/scopes retain existing authorization and routing. |
| SoftwareRepository | SoftwareAssignmentService current destination licence reuse, version reuse and invalid-licence validity | Ordinary software inventory, dictionary discovery, transfer candidate selection and audit reads remain unchanged. |

`Orm::create()` does not impose a blanket session policy on every read or replace a supplied read endpoint. An active ordinary DBAL connection without the managed physical-owner capability cannot acquire a domain locking-read authority from logical depth alone. The checks refuse that unknown owner; they do not adopt or abort it. Compatible idle ordinary diagnostic connections can still inspect the native capability. Schema/migration definitions and ledger versions remain unchanged.

## Prepared validation

`mysql-current-read-policy-unit.php` exercises the actual middleware with deterministic driver/PDO-state doubles: capability absence/OFF/ON, exact returned identity/value, duplicate rows, refused SET, failed/disappearing readback, configured modes, freed results, existing native transaction refusal and changed caller mode before managed BEGIN. It opens no physical connection. It is source prepared, not executed here.

`mysql-current-read-session.php` uses two complete configured application adapters, preserving TLS/endpoint parameters. It checks new-session admission, same-DBAL and adapter reconnects, then a real repeatable-read snapshot followed by another writer's committed Kanban identity and an ORM locking selection. On providers exposing the capability, it deliberately changes only its owned caller session and verifies refusal of a nested frame, actual public licence update, actual public Project purge and each of the four direct repository families. Complete owned rows, history, queue and raw ledger must remain equal; the original variable, actual PDO frame, opaque scope, loaded licence and caller marker must remain intact. A separate ordinary DBAL owner proves unknown active-frame refusal and retains authority for its real rollback. Cleanup preserves the primary error and child-first owned records; GLOBAL and original supplied adapter remain untouched.

ROOT must run syntax/style checks and pure tests, the new native contract, unchanged current-read contracts, managed ownership/deletion/software/transfer suites and full discovered portability/application suites on official MariaDB11.8, MySQL8.4 and PostgreSQL. Capture exact safe provider/session/global facts and native transaction state before claiming the CI cause or fixed behavior. Compare unchanged pre-policy11.8 failure with factory-policy admission only on separately owned handles; never rewrite an original authority snapshot. Local MariaDB10.11 success alone is insufficient. No timeout change, swallowed error, hidden transaction-depth reset or automatic retry is included.
