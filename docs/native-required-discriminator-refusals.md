# Required discriminator refusal contracts

The latest complete local portability checkpoint is
`741fb2b1f0cc852404bd55080e4ed7bbb0bf8b7e`: PostgreSQL and MariaDB both pass
180/180 contracts, fresh installs and final native inspection. The modernization
goal remains OPEN; official-engine/remote CI, replicas/TLS and prepared next
batches are separate pending evidence.
See [the handoff](modernization-handoff.md#current-741-complete-local-portability-checkpoint)
for exact timings, retained failures and remaining work.

The PostgreSQL portability run at `a736d72e03a41deee9723d39d50d372940ed1335`
completed 178/179 contracts with a separate release-snapshot ordering failure.
The completed a736 MariaDB run passed 151/179 and reported required-subject rejection failures in Alert,
Appliance, Change/Problem, Contract, Document, infrastructure assets, ObjectLock
and Ticket asset contracts. These observations remain failed evidence.

Each affected raw-insert matrix includes an input that omits `itemtype`. Its
owning entity's `RequiredItemReference` declares a non-null discriminator without
a database default. Installed DBAL 4.5 converts MySQL/MariaDB native error 1364
to `NotNullConstraintViolationException`, while preserving SQLSTATE `HY000`.
The original test gate accepted only integrity SQLSTATEs and therefore could
fail even when that particular missing-field insert was correctly refused.

The test-only recognizer retains every original integrity-state branch. Its
additional branch requires an explicitly omitted expected column, the converted
NOT NULL exception class, native code 1364, SQLSTATE HY000 and an exact native
cause message naming that column. It reads the chained driver cause rather than
searching SQL/query/value text. Other variants do not select this branch. The
original invalid inputs, rejection assertions, savepoints, ORM/public operations
and production schema remain unchanged.

The additional connection-free contract checks selection, wrong columns,
exception classes, error codes, states and embedded-message negative controls.
It does not replace actual native tests.

Initial preparation had source syntax/whitespace checks only. Root's actual
rollback diagnostic subsequently passes all 83 native cases on each provider,
recording converted exception and exact chained-driver cause with preserved
source/configuration, ledger, modes, row counts and idle cleanup. The affected
native matrices, fresh installation, complete history and coherent full suites
now pass at exact 741 on both providers; both post-full native schemas converge.
No generic HY000 acceptance, mode changes or production behavior changes were
introduced. Remote/official-engine and broader application-flow gates remain
separate from this local proof.
