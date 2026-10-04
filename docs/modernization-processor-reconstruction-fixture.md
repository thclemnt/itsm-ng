# Processor reconstruction owns its incoming fixture graph

This SOURCE-only fixture correction is based on
`be258c8bd204dcfa2dfc5763e76e460ab80bb54f`. Production mappings, application
code, all 120 canonical migration files and the original 300-second contract
limit remain unchanged. Corrected PHP syntax, focused contracts, full suites
and final native inspection remain pending ROOT execution on both providers.

ROOT's actual PostgreSQL focused run failed on the first processor assignment
table DROP and repeated that failure during cleanup. Native inspection on
PostgreSQL and MariaDB subsequently found exactly two canonical incoming ID
foreign keys, both using RESTRICT: contract and project assignments own
`items_deviceprocessors_id -> glpi_items_deviceprocessors.id`. Their ownership
is declared in `DeviceItemAssociations`, used by `ContractItem` and
`ItemProject`. These links must survive each table reconstruction. ROOT's
inspection found an unchanged, empty assignment table and schema, with only
append17's receipt missing after the old fixture deleted it before the DROP.
That evidence describes the old fixture; ROOT owns recovery through the real
frozen migration and its native/data proof, without directly marking a receipt.

The new fixture helper derives the permitted incoming ID ownership from the
existing metadata-derived `BaselineSchema`. It captures the actual incoming
native definitions from every visible referencing schema/database and refuses
missing, custom or incompatible references before DDL. PostgreSQL references
must be validated, nondeferrable, MATCH SIMPLE and RESTRICT; MySQL references
must have one ordered column, MATCH NONE and RESTRICT, with native foreign-key
enforcement active. All referenced consumer cells must be NULL for this
empty-table fixture. All consumer rows, including their other fields and
duplicates, are captured and must remain exact before detach/restoration.

Around each target DROP/CREATE, only the matched canonical incoming ID
constraints are detached. The consumer tables and rows remain in place. The
helper reattaches PostgreSQL's captured native clauses, or MySQL clauses built
from its captured ordered native cells and actions, then compares the complete
native definition vector and all consumer rows. It never disables enforcement,
uses CASCADE, drops consumers, clears references or adopts an unknown constraint.
Native catalogue visibility depends on the supplied application's permissions;
an otherwise invisible dependency can still cause a normal safe DROP refusal.

Each detach attempt is recorded before nontransactional DDL so cleanup can
discover whether an error left the exact owned constraint present or absent.
Only attempted missing constraints can be restored; altered or unknown native
definitions and unrelated consumer changes cause refusal. Restoration attempts
each missing owned constraint independently and reports the first actual error.
The supplied schema/database and idle connection must remain the same.

The processor receipt is now invalidated only after the target DROP succeeds.
A refused DROP restores any owned detach without recreating the existing target
or changing the original receipt. If the transport reports an error after DDL,
cleanup inspects actual target existence before deciding whether reconstruction
is needed; failed inspection leaves a failed result rather than an assumed state.
After actual destruction, cleanup recreates the current target and restores
every captured native CHECK, all incoming ID constraints and unchanged consumer
rows. Full schema proof precedes restoring the exact original raw processor
receipt. Unrelated raw ledger rows must match before that write, and the entire
ledger must match afterward. Original first-error reporting and independent
owner-row cleanup remain in place; restoration failures never produce success.

The genuine separately owned composite projection consumer remains in the
contract. Its `(binding_id, subject_id) -> (id, items_id)` FK still proves that
the frozen migration refuses destructive legacy-projection replacement before
changing data or receipts. Added controls also invoke the new fixture detach
guard while that consumer exists and verify that it refuses without changing
any constraints, processor rows or receipts. The migration's original incoming
reference assertions, invalid-data diagnostics, populated backfill, stock
normalization, phase interruptions, retry/idempotency, generated-column writes,
selected CHECK/FK causes and current-schema convergence assertions remain.

Actual old-run evidence and native inspection are retained separately:

- `owned-domains-be258-pg-focused-20261004T012015443707Z-25d16db94e/focused-3-processor-subjects-schema.log`
- `processor-be258-pg-post-failed-reconstruction-native-inspection-v2.json`
- `processor-be258-mysql-post-failed-reconstruction-native-inspection-v3.json`

These files are under `/workspace/itsm-env/evidence`. They establish ROOT's old
failure/inspection, not corrected runtime success. Next: independent SOURCE
review, ROOT syntax, actual PostgreSQL/MariaDB corrected reconstruction including
its guard/retry/restoration controls, then composed full suites and final native
schema/ledger/data inspection. Broader modernization remains OPEN.
