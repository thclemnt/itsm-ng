# Domain import fixture identities after rollback

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
