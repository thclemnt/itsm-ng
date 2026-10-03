# Domain import fixture identities after rollback

Current checkpoint: `741fb2b1f0cc852404bd55080e4ed7bbb0bf8b7e`.
The latest complete local portability checkpoint is
`741fb2b1f0cc852404bd55080e4ed7bbb0bf8b7e`: PostgreSQL and MariaDB both pass
180/180 contracts, fresh installs and final native inspection. The modernization
goal remains OPEN; official-engine/remote CI, replicas/TLS and prepared next
batches are separate pending evidence.
[The handoff](modernization-handoff.md#current-741-complete-local-portability-checkpoint)
separates these results from the retained a736 full failures and the earlier complete
green b66 checkpoint. Later passages marked pending describe their earlier
preparation checkpoint unless superseded by the precise checkpoint results above.

## Later same-database native retry

Root's full Domain import at a736 passes PostgreSQL 57.331899s and MariaDB
199.725782554s. Repeating the complete contract on the SAME MariaDB database,
without resetting burned identifiers, passes in 211.68482s under the unchanged
300-second limit. This exercises the explicit disjoint fixture namespace after
actual allocator consumption and retains collision refusal/cleanup. The original
b05 collision and 7ae timeout remain failed evidence. Current complete suites
finish at a736 with failures: PostgreSQL 178/179 and MariaDB 151/179. Native
PostgreSQL converges; MariaDB retains two missing Boolean CHECKs. Later fixture
repairs were pending native validation at that capture; the focused import/repeat
passes alone did not supersede b66. The later complete 741 checkpoint now does.


Historical preparation and diagnosis follow; statements of unexecuted gates
below describe those earlier snapshots.

Root-owned MariaDB execution at
`b05dfcf808fa6d49d27016ad21ff399e11bae1a4` failed its first Domain import plan
with `Domains import ID collision: glpi_domaintypes.4294972001`. The unrelated
core type fixture used automatic allocation, while its pinned export already
reserved that identity for the second imported type.

Native diagnosis found no surviving core row at either exported type identity,
no import receipt and no leftover plugin source table. The core type allocator
was at `4294972002` after the failed fixture. A separate owned InnoDB control
demonstrated that explicit insert `4294972000` followed by rollback leaves no
row, but the next ordinary insert allocates `4294972001`; rolling that insert
back also leaves no row. The evidence is
`domain-import-sequence-probe-b05-mysql.json` in the cloud evidence directory.
Rollback restores transactional data without rewinding MariaDB identity
allocation. The earlier interrupted import can therefore affect a later
unrelated automatic fixture, even when all application data cleanup succeeds.

The unrelated core type, domain and asset link now use explicit fixture-owned
IDs immediately below the export range. Native absence guards prevent overwriting
existing records, and explicit disjointness checks retain the pinned export IDs
unchanged. This also protects the other two tables after their interrupted import
stages. No application counter is reset, schema altered or ownership preflight
relaxed.

The contract includes an owned InnoDB allocator control, created before its
caller transaction. It performs the assigned-ID rollback and ordinary-insert
rollback, checks exact allocation, native storage and zero surviving rows, then
verifies transaction ownership. The normal final cleanup removes the control
table. A separate nested core collision at the first real exported type ID
exercises actual `DomainPluginImport::import()` refusal, preserves the complete
occupied row, all core counts and the absent receipt, and rolls back only the
owned collision fixture. Existing unique-collision, scope, lifecycle, interrupted
import, retry, sequence and source preservation assertions remain intact.

Syntax, formatting and whitespace checks are source-only. The updated native
contract still requires full execution on both providers and repeat execution
against the same disposable MariaDB database, followed by the complete current
portability suites. The native diagnostic above establishes the cause; it is
not a passing result for this final source change.
