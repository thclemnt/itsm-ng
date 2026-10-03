# Public association endpoint ownership

This corrective batch starts from `525075411163869601c2dbfb762d74352761e021`.
It changes public persistence and authorization boundaries, without changing
entity relationships, schema definitions, historical migration SQL or the ledger.

Previously, `CommonDBConnexity::checkAttachedItemChangesAllowed()` compared only
the model's legacy endpoint fields. A request changing an owning column such as
`Domain_Item.computers_id` could pass that check, then the ORM writer resolved it
to a different generated `items_id`. Database existence and discriminator
constraints cannot enforce a user's permission to establish that relationship.
Preparation overrides could also omit the inherited guard.

`ConnexityInput` matches the actual declared relation/child roles to the entity's
property-derived discriminated references. It normalizes only matching endpoint
groups and retains their generated legacy identity for authorization, preparation,
history and notifications. Notification recipients and other discriminations do
not acquire an unrelated endpoint permission rule. Unmapped and nonmatching
models retain their inherited policy.

The lifecycle normalizes CREATE input before the caller's `can(CREATE)` check.
Updates normalize and authorize the proposed endpoints before preparation, after
preparation/business rules, and after the final `pre_updateInDB` callback. The
actual model's CREATE and original relationship DELETE/PURGE rules remain
mandatory, including its declared member-right policy. ProjectTeam's
`DONT_CHECK_ITEM_RIGHTS` still operates with the existing relation's separate
READ prerequisite; it does not grant arbitrary access to members.

The final update boundary reconstructs the effective persisted fields from the
stored row and final write set. Cancelled, input-only and net-zero callback edits
cannot leave stale fields, history or notification context. Explicit NULL differs
from zero, including DocumentItem's nullable Entity-root subject branch. The
physical operation ID and public lookup index remain bound to the loaded row;
callbacks cannot redirect either, including Entity root ID zero becoming NULL.

Read-only model hooks own necessary endpoint policy and context: Rack/Enclosure
placement, Cluster membership, OS assignment uniqueness and entity cache,
Appliance relationships, and ITIL `_job` binding. Rich-text/upload preparation is
not rerun. OS insertion mode remains distinct from update mode, including adds
through a reused object. ITILSolution refreshes its cached parent after updates
without history. Its CREATE/update/edit policy now requires parent READ access
in addition to the existing `canSolve`/`maySolve` rules, matching the form's
existing prerequisite; specialized assigned-actor solving rights are retained.

Link's `getEmpty()` now returns the parent initialization result while retaining
its default `open_window`. This repairs inherited child entity detection. The
Transfer application fixture creates required real parents through public APIs,
deriving fixed roles and mandatory associations from model/Doctrine declarations.
It retains full enumeration and the original physical entity assertion; an
inherited-only child is covered through its supported owning-parent transfer,
with its direct transfer action absent and its binding preserved.

Trusted direct `add()` and assigned-ID imports remain responsible for their own
complete authorization/preflight. A trusted hook changing a parent after an
interactive CREATE permission check is not given a separate permission-proof
mechanism by this batch. This boundary does not promise to undo external effects
already performed inside arbitrary plugin hooks. The independent atomic Transfer
and refused-writer work must be preserved when integrating CommonDBTM changes.

## Validation checkpoint

Validation uses isolated PostgreSQL 15.19 and MariaDB 10.11.18 databases, PHP
8.2.33, separate file stores and the existing 300-second contract budget. The
application checkpoint was upgraded through the actual canonical History before
contracts; it retains populated dataset 4.7. Main, browser and other workers'
databases were not modified.

The focused contract calls the actual PHP `APIRest::updateItems()` controller and
public CREATE/update methods; it is not network HTTP or browser verification.
It covers 13 owning-subject families, legacy/physical payloads, changed kinds,
hidden/empty scopes, native-valid but unauthorized relationships, NULL/root-zero
branches, skipped-parent preparation and final callback retarget/cancellation,
ITIL history-zero caches and actor rights, Rack geometry, OS derived context and
uniqueness, operation identity, notification recipient and member policy.

The final source passed the following checks on both providers:

| Check | PostgreSQL | MariaDB |
| --- | --- | --- |
| Actual empty `db:install`, canonical history replay | exit 0, 35.997 s | exit 0, 110.455 s |
| Unchanged `itil-subjects.php`, including frozen DDL upgrade/retry | pass, 3.508 s | pass, 7.695 s |
| New `connexity-ownership.php` after the ITIL DDL fixture | pass, 9.098 s | pass, 11.526 s |
| Schema comparison after those contracts | no differences, 3.238 s | no differences, 16.261 s |
| Original nine affected application classes | 48/48 methods, 2,299 assertions, 45.716 s | 48/48 methods, 2,299 assertions, 44.744 s |

The original application run has zero skipped, void, incomplete, failed or errored
methods; its generic Transfer method now tests 69 item types. Classes are
CommonDBTM, ITILSolution, ITILFollowup, Item_Rack, Item_OperatingSystem,
Appliance_Item, Appliance_Item_Relation, ProjectTeam and Transfer. Sixteen PHP
files pass lint and the project formatter with zero changes; `git diff --check`
is clean. Dynamic discovery includes the new contract (161 on this branch);
this is discovery, not a full-suite passing claim. Empty installation emits the
existing pre-DDL absent `glpi_configs` warnings and optional-requirements note;
its subsequent contracts and application tests are warning-free.

The selected adjacent checks are relationship-lifecycle, itil-project-subjects,
itil-tasks, itil-classification, operating-system-subjects, operating-system-purge,
appliance-assets, project-assets, contract-assets, transfer-bindings,
domain-application and schema-check. All 12 passed on the final source for each
provider (summed contract times 39.483 s / 66.181 s). Final provider results are
recorded in local `connexity-adjacent-complete-{pg,mysql}.json` evidence.

Earlier failed attempts remain in local evidence:
expected API rejection handling and missing actor removal rights were fixture
errors; hidden Solution acceptance and reused OS add uniqueness were real defects
fixed here. The Rack callback initially used an invented column; its corrected
metadata-derived owning column exercises the real placement policy.

The first application run's missing content-addressed PNG files were repaired
only in the isolated store from matching checkpoint assets. The unchanged base
Transfer test also fails when its generic inputs omit mandatory Reminder parents;
the fixture now creates real parents and supplies a local RSS fixture URL, without
skipping classes or weakening constraints. A later inherited Link child assertion
exposed the real `getEmpty()` return defect fixed here.

The historical ITIL DDL contract was first attempted against the populated app
checkpoint and failed: it removes owning columns and reconstructs `items_id`
with default zero, destroying those existing fixture projections before its
incoming-FK assertion. This is an empty-core DDL fixture requirement, not a claim
that populated upgrades pass. Original identifiers were restored from the
unchanged checkpoint and ITILSubjects replay restored the owned schema. The
unchanged contract subsequently passed against separately installed empty
disposable databases on both providers, as recorded above.

Next: integrate the reviewed CommonDBTM refusal/atomic Transfer and Supplier
changes, run the dynamically discovered full portability suites on both supported
provider families, and rerun broader original application and actual browser/API
flows on the combined frozen source. Remote CI and the overall modernization goal
remain outstanding.
