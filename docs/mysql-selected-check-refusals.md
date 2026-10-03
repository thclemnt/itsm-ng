# Selected native MySQL CHECK refusals

Official MySQL 8.4.11 reports a failed CHECK through DBAL's generic
`DriverException`, native code `3819`, SQLSTATE `HY000`, and an immediate
mysqli `StatementError` whose complete message is
`Check constraint '<constraint_name>' is violated.` ROOT observed this for both
INSERT and UPDATE against a new owned constraint on the integrated `ab424228`
checkpoint. The owned omitted-required-field control independently returned
`NotNullConstraintViolationException`, `1364/HY000`, and the expected field
message. Neither observation establishes that every application contract has
failed on official MySQL.

`NativeConstraintRefusal::matchesSelectedCheck()` recognizes that exact CHECK
cause only when the caller supplies the expected constraint name. It does not
add `HY000` to the integrity-state list or change the separate omitted-field
recognizer. Pure controls reject an unselected or different constraint, a
different converted or immediate driver class, other codes and states,
partial or different native messages, and matching text present only in query
parameters.

The source audit found 22 contracts with narrow CHECK gates. Their existing
invalid-input operations now select the corresponding historical CHECK; FK,
restrictive-delete, and UNIQUE operations do not select it. Invalid-input
matrices, lifecycle assertions, SQL operations and cleanup remain in place.

| Contract | Selected CHECK scope | Other refusal paths retained |
| --- | --- | --- |
| alert-subjects | Alert subject kind | Required field, FK, UNIQUE |
| appliance-assets-schema | Asset and nested subject kind | Required field, FK, UNIQUE |
| appliance-assets | Asset and nested discriminator updates | Mapped input validation, FK |
| appliance-check-retry | Exact CHECK restored by retry | Existing populated repair and DDL guards |
| change-problem-assets | Change and Problem asset kind | Required field, FK, UNIQUE |
| contract-assets | Contract asset kind | Required field, FK, UNIQUE |
| document-subjects | Document subject kind | Required field, FK, UNIQUE |
| domain-documents-schema | Document subject kind | FK, restrictive delete, UNIQUE |
| domain-integration-schema | Domain visibility boolean | Supplier FK, PostgreSQL boolean datatype refusal |
| infrastructure-assets | Certificate, Domain and Cluster subject kind | Required field, FK, UNIQUE |
| itil-project-subjects | ITIL project subject kind | FK, UNIQUE |
| itil-subjects | Followup and Solution subject kind | Existing ORM validation and scoped queries |
| object-lock-subjects | Lock subject kind | Required field, FK, UNIQUE |
| operating-system-subjects | Operating system subject kind | Required field, FK, UNIQUE |
| physical-placements | Enclosure and Rack placement kind | FK, UNIQUE |
| planning-recall-subjects | Planning subject kind | FK and restrictive delete |
| project-assets-schema | Project subject kind | Required field, FK, UNIQUE |
| project-assets | Project subject kind and cleared subject | Required field, FK, UNIQUE |
| project-team-members | Project and ProjectTask member kind | FK, UNIQUE |
| reservation-assets | Reservation asset kind | FK and restrictive delete |
| ticket-assets | Ticket subject kind | Required field, FK, UNIQUE |
| vobject-subjects | Calendar subject kind | FK, UNIQUE |

Two older mixed gates already accepted unrestricted `HY000`:
`notification-targets` and `user-authentication-sources`. Their native update
matrices now declare each row's CHECK scope explicitly. Missing Group/Profile
and LDAP/Mail targets have no selected CHECK; malformed recipient and
authentication branches select their existing migration CHECK constants.
The user login uniqueness control remains independent. Notification's direct
assignment to a generated column retains its original separate behavior gate
and persisted-value assertion; generated-column assignment is not a CHECK
refusal, and its native error signature is outside this batch.

The Domain populated-retry enforcement control previously accepted any driver
exception. It now requires an existing boolean datatype/integrity refusal or
the selected Domain visibility CHECK cause, preserving the actual invalid
UPDATE and rollback.

This batch changes test refusal classification only. Production entities,
schema, frozen migrations, installation, authorization and application
persistence are unchanged. Source inspection and diff checks are complete;
PHP lint, pure classification execution and application contracts remain
ROOT-owned pending validation. Next run the pure contract, then the selected
application contracts on official MySQL and both existing providers, followed
by the coherent full portability suites and final schema inspection.
