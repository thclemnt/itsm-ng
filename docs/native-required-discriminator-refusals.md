# Required discriminator refusal contracts

The PostgreSQL portability run at `a736d72e03a41deee9723d39d50d372940ed1335`
completed 178/179 contracts with a separate release-snapshot ordering failure.
The ongoing MariaDB run reported required-subject rejection failures in Alert,
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

This isolated draft has source syntax/whitespace checks only. The root-owned
rollback diagnostic and all eight unchanged native matrices must run on both
providers before integration is validated. Fresh installation, complete history,
full suites and final native schema checks remain separate requirements. No
generic HY000 acceptance, mode changes or production behavior changes are made.
