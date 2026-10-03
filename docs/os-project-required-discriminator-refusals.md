# Operating-system and Project native refusal matrices

The latest complete local portability checkpoint is
`741fb2b1f0cc852404bd55080e4ed7bbb0bf8b7e`: PostgreSQL and MariaDB both pass
180/180 contracts, fresh installs and final native inspection. The modernization
goal remains OPEN; official-engine/remote CI, replicas/TLS and prepared next
batches are separate pending evidence.
See [the handoff](modernization-handoff.md#current-741-complete-local-portability-checkpoint)
for exact timings, retained failures and remaining work.

The affected-contract run on `883b23413c` completed 30 PostgreSQL contracts and its native schema inspection. MariaDB passed 22 contracts, then `operating-system-subjects.php` failed its unchanged native-invalid-subject assertion at line 145. This is a failed run, not a complete portability pass.

The OperatingSystem matrix and the two Project matrices each include an omitted `itemtype` insert. Their original recognizers accept six integrity SQLSTATEs. The shared `NativeConstraintRefusal` fixture also recognizes an explicitly selected missing required column when DBAL converts native code 1364 / SQLSTATE HY000 to `NotNullConstraintViolationException` and the previous driver message names that exact column. The new contracts select that branch only when `itemtype` is absent. Explicit NULL, unknown kinds, zero IDs, wrong or multiple owning columns, FK/duplicate/update/delete checks and all original assertions retain their existing behavior. The OperatingSystem and public Project exception-unwrapping chains and every savepoint remain intact.

Source review reverses only the fixture require, optional recognizer parameter, recognizer call and omitted-column selector to reconstruct all three original files byte for byte. No application, schema or historical migration definition changes. The existing connection-free recognizer contract covers wrong class/code/state/column/message and unselected cases.

Root's actual exact-883b BEFORE diagnostic passes all 21 invalid inputs across
three matrices and two tables, plus two accepted canonical controls, on each
provider. Only the three omitted-itemtype MariaDB cases are rejected by the old
test gate despite native 1364/HY000 and the correct converted NOT NULL exception.
All ownership/source/configuration/ledger/mode/count/idle rollback guards pass,
with no DDL; native ID allocation may advance despite rollback. The diagnostic
reports identities and cause booleans without exposing SQL, values or credentials.

Candidate e919 is integrated as exact 741. All three complete AFTER contracts
pass both providers, including all four Project schema migration phases and
journal retry. Subsequent coherent full suites pass 180/180 each; fresh installs
and post-full native inspections pass with thirteen complete receipts and no
schema differences. The earlier original assertion failures remain archived.
This validates the bounded test repair locally rather than attributing a schema
or production-policy change to it.

The overall PostgreSQL/Doctrine modernization remains OPEN. Further architectural batches and official-engine/remote/browser/replica/TLS evidence remain separate; the local 741 portability checkpoint is complete.
