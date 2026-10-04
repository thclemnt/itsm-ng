# PostgreSQL 18 RESTRICT exception ownership

The recorded PostgreSQL 18 CI failures on source 76cd are real FK refusals, rather than accepted writes. Run 37235444503, jobs 111533494531 (PHP 8.3) and 111533494776 (PHP 8.2), installed Doctrine DBAL 4.5.0. The first component-subjects-schema failure came through its selected parent-FK admission helper; dropdown-lifecycle failed at its parent deletion clause. Both reported PDO native code 7 and SQLSTATE 23001 as a generic DriverException. The synthetic PR merge 0582df6a has the same complete tracked tree as 76cd.

PostgreSQL's tagged 18.0 ri_ReportViolation distinguishes RESTRICT parent update/delete from NO ACTION: it emits ERRCODE_RESTRICT_VIOLATION (23001) and a primary diagnostic explicitly naming the RESTRICT setting, parent, child and constraint. Tagged 17.0 lacks that branch and emits the older 23503 FK diagnostic. The PostgreSQL 18 error-code table explicitly declares 23001 as restrict_violation. The 18 release document inspected does not separately describe this diagnostic change; the tagged implementation and recorded native failures provide the causal evidence.

The public PostgreSQL driver boundary now decorates the DBAL exception converter. It classifies exactly SQLSTATE 23001 as ForeignKeyConstraintViolationException, retaining the original native state, code, previous exception and optional query. Every other state delegates to the wrapped DBAL converter. There is no message-based production classification, server-version switch, vendor patch, dependency downgrade, FK/action rewrite or historical migration change. Existing ORM, ordinary DBAL and bound legacy operations share this same configured driver.

The component fixture retains its original assertion and full PDO provenance checks. Its parent-FK matcher admits only the exact older 23503 primary or the exact 23001 RESTRICT primary, with the selected parent, child and constraint intact. These pairs cannot be exchanged. Child-side missing-target admission remains 23503; CHECK, generated-projection and other-provider matchers remain unchanged.

Two new discovered source contracts are prepared:

- A nonconnecting public-driver policy contract preserves native/query identities and verifies exact delegation of unrelated states. It also rejects wrong named objects, absent names, query-text lookalikes, mismatched state/diagnostic pairs and a generic exception carrying the expected state.
- A native transport contract uses a separate complete-parameter PostgreSQL owner and caller-owned transaction to create only temporary FK fixtures after explicit name-absence checks. It tests actual DELETE and parent-key UPDATE through both ordinary DBAL and bound legacy execution, missing-target child INSERT/UPDATE, NO ACTION's unchanged 23503, and a valid child/parent deletion with savepoint rollback. All private rows, original connection idleness and raw ledger-state values are checked; cleanup preserves the first error and refuses uncertain frame ownership.

The native contract's server-version assertion describes the observed PostgreSQL generations; production classification depends only on the native SQLSTATE. Other providers retain their existing converter and connection, with an explicit boundary assertion. The temporary fixture owns no permanent application table and creates no synthetic migration receipt.

All candidate syntax, pure execution, native/provider results and remote CI are **pending**. ROOT must run the new policy and native contracts, the unchanged component-subjects-schema and dropdown-lifecycle contracts, the relevant broader suites and final schema inspection on PostgreSQL 14 and 18. It should also confirm the other-provider delegation controls on MySQL/MariaDB and the original official CI matrix. The original 300-second contract limits and assertions remain in force. Current ROOT validation and unrelated remote failures are not superseded.

Upstream source references:

- https://github.com/postgres/postgres/blob/REL_18_0/src/backend/utils/adt/ri_triggers.c
- https://github.com/postgres/postgres/blob/REL_17_0/src/backend/utils/adt/ri_triggers.c
- https://github.com/postgres/postgres/blob/REL_18_STABLE/src/backend/utils/errcodes.txt
- https://github.com/doctrine/dbal/blob/4.5.0/src/Driver/API/PostgreSQL/ExceptionConverter.php

The overall PostgreSQL/Doctrine modernization goal remains open.
