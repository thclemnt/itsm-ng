# Independent software permission proposals

This SOURCE-only correction is based on
`be258c8bd204dcfa2dfc5763e76e460ab80bb54f`. It gives the CREATE and UPDATE
denial checks in `software-assignment-subjects.php` independent copies of the
same unsupported-plugin proposal, with a separate assertion for each request
and each software relation. The actual plugin registration, both rights, new
item identifier, proposed parent/subject IDs, and every subsequent contract
remain in place. No production permission method or schema/history is changed.

ROOT's PostgreSQL focused execution at be258 failed at line 80 with
`CommonDBTM::can(): Argument #3 ($input) must be of type ?array, bool given`.
The immutable evidence is
`/workspace/itsm-env/evidence/owned-domains-be258-pg-focused-20261004T010619041080Z-b3409a9ee6/focused-0-software-assignment-subjects.log`.
This document records that earlier execution; it does not claim that the
corrected fixture has run. PHP syntax, focused execution on both providers,
broader application tests, full suites and remote CI remain pending ROOT.

The source trace explains why the two proposals must be independent:

- `CommonDBTM::can($ID, $right, ?array &$input)` initializes a new model, then
  assigns `normalizeLifecycleInput($input)` into the caller's reference. On
  rejection it returns false after changing that reference to false.
- `CommonDBConnexity::normalizeLifecycleInput()` delegates booleans to the
  shared normalizer and endpoints to `ConnexityInput::normalize()`. An endpoint
  exception becomes a denial.
- The software entities' `ItemReference::normalizeInput()` derives owning
  choices from property metadata and throws for the plugin discriminator,
  which has no owning association. Extending `CFG_GLPI['software_types']`
  through the real `Plugin::registerClass()` does not create an ORM mapping.
- The first denial is therefore expected. Reusing its changed input for the
  second typed call prevents that second permission decision from running.
  Independent arrays retain the original unsupported proposal for both calls.

The permission requests deliberately retain the original `can(-1, ...)`
calls. For a new ID, the shared method uses its create branch even when the
requested right is UPDATE. The assertions cover rejection of this proposed
unmapped endpoint; they do not establish existing-record UPDATE authorization.

The broader permission API defect remains OPEN. Its nullable-array input
signature and documented array contract do not describe the false output
written through the reference after normalization denial. Permission checks
also mix proposal normalization, model mutation and authorization. Widening
the parameter to accept false or merely resetting all callers would conceal
that mismatch. A separate production repair should trace overrides and callers,
separate normalization failure from the array output, retain successful
entity-forwarded normalized proposals, and verify repeat-denial behavior,
model state, scopes, hooks and public HTTP/API denial behavior.

Actual callers show why that repair cannot be made only for this fixture:
`front/item_softwareversion.form.php` and
`front/item_softwarelicense.form.php` use `check(-1, CREATE, ...)` before `add()`.
`check()` passes the same reference to `can()` and handles denial through the
existing error path. `API::createItems()` supplies a freshly converted object
for each item, calls `can(-1, CREATE, $object)`, and uses that object for storage
only on success. These successful normalization/lifecycle boundaries must
survive a caller-aware API repair. Source inspection did not find another
single-line double-can expression sharing a referenced payload in core
`inc/`, `front/` or portability tests; that bounded search is not a complete
application-wide proof that no multi-line caller reuses a denied payload.

The next step is ROOT syntax checking and focused execution of the unchanged
software-assignment contract on PostgreSQL and MariaDB, followed by the planned
composed full suites and native schema inspection. The future source binding
and the independent unbound validation packet remain separate from be258's
already recorded fresh-install results.
