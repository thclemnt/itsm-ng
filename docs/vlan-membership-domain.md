# VLAN membership ownership source batch

This batch is based on `515232177fee500aa9382df32f3cc391881ce270` and the
accepted [caller roadmap](vlan-membership-modernization-plan.md). It is a
SOURCE implementation for independent review. PHP syntax, autoload, formatter,
focused PostgreSQL/MariaDB contracts, full suites and HTTP/browser validation
have **not** been run for this candidate. It is held outside ROOT's current
validation source. The broader modernization goal remains open.

`NetworkPortVlanRepository` follows the existing `networkports` and `vlans`
owning associations. Each display projection retains `assocID` separately from
its endpoint's `id`; typed boolean `tagged` becomes the legacy screen's 0/1.
Nullable endpoint text and join columns derive from entity metadata. Counts and
natural-pair lookups have their own domain methods. Operation-local read managers
retain the exact adapter supplied to `VlanMembershipService`, so repeating a read
never retains a managed collection or a stale entity after a legacy writer.
Uncommitted writer visibility is a contract; live replica routing is a separate
pending infrastructure check.

Public `NetworkPort_Vlan` add/update/delete continue through the actual standard
preparation, final normalization, hooks, history and purge pipeline. Their private
command owns one immutable port/VLAN/tagged selection, identity and the captured
managed writer frame. Scalar ORM locking reads refresh actual endpoints and the
membership without nullable fetch joins. Entity coherency follows the existing
relation rule: same entity or an actual recursive ancestor in either direction.
The ancestor walk uses `Entity::parent`; it refuses cyclic data. No extra actor or
PURGE permission is imposed on trusted clone or required-child cleanup. Ordinary
SAME/VIEW/dynamic-parent admission, restrictive `item_can`, proposed CREATE and
stored DELETE/PURGE checks remain in the framework.

The existing `OwnershipUpdateUnit` owns rollback, model/session journal and
notification barrier. It alone decides when rewind is proven. This batch adds
no transaction protocol, SQL tracker, global manager, lookup cache or POST bypass.
A private context is cleared on ordinary permission-probe clones and restored in
`finally` across nested invocations. Model, prepared input, writer and actual
persisted tuple must agree through callbacks. A callback cannot turn an accepted
result into a different persisted membership. PostgreSQL strong transaction
isolation is refused using the actual session setting before the mutation; the
caller isolation is never changed. MySQL/MariaDB structural reads use current
locks. Ancillary framework/plugin reads can still obey the caller snapshot; this
is not a claim that every audit/actor/plugin read is current.

`unassignVlan()` now selects its natural key inside the owned frame. A missing
pair returns false rather than deleting a different previously loaded relation.
Duplicate assignment retains the native unique refusal; it is not an upsert and
does not silently change tagged intent. Existing ApplicationManaged ownership,
RESTRICT foreign keys, unique pair, boolean declaration and all frozen migration
history remain unchanged.

Four manually verified direct read sites move to the repository: the two screens,
the public VLAN-ID map and the deprecated NetworkPort clone membership reader.
Two tab count helpers also move. This is not a fresh whole-application scanner
result; the original 2,844 checkpoint remains unchanged evidence. The deprecated
clone continues each public relation add and preserves tagged values. Modern
`NetworkPort::post_clone` and Transfer's unsupported VLAN-copy semantics remain
unchanged. NetworkPort's polymorphic asset identity, search-join consumers,
unmapped plugin dispatch and wider import/Transfer persistence remain separate
work.

Two new contracts are discoverable by the unchanged portability runner:

- `vlan-membership-ownership.php`: real assignment/update/remove and strict boolean
  input, native unique refusal, current typed target existence, missing-pair safety,
  endpoint reassignment, nullable/literal screen payload, repeated uncommitted
  reads, actual history for both parents, hook-created queue/session rollback,
  post-callback identity/input refusal, current reload, entity ancestry, legacy and
  modern clone characterization, and required-child purge veto/acceptance.
- `vlan-membership-callers.php`: the actual SAME/VIEW/dynamic-parent combination,
  force-both, form guard/helper pipeline, real inherited API creation via ordinary
  active client/app token/authenticated session admission, genuine massive-action
  discovery/specialization/process, restrictive permission hook, foreign and
  empty entity scopes. The API response seam only captures the genuine response;
  it does not replace authorization. The form exits through `Html::back`, so its
  equivalent guard/helper pipeline is not reported as an HTTP test.

All contract assertions are unexecuted SOURCE expectations, not behavior evidence.
Before integration, run compiler/autoload/style and both new focused contracts
on both disposable engines, inspect native FK/unique/boolean/schema state, then
run the coherent full suite in its actual discovery order. Characterize important
before/after role cases on the original source; do not tighten a permission merely
to make a proposed test pass. Add native concurrency evidence for parent transfer,
reassignment, purge and caller snapshots; native rollback/deadlock diagnostics are
not a deadlock-free claim. Verify raw BEGIN refusal and supplied owner generation
using the existing real managed-owner contracts. Raw SQL COMMIT followed by BEGIN
bypassing that owner API remains the explicitly open transport limitation.

Next step: independent source review and ROOT-controlled focused validation;
resolve actual failures without weakening rights, native uniqueness, current tuple
checks, history or rollback assertions. Physical notification sends, browser/E2E
and live replica tests require separate ROOT infrastructure gates.
