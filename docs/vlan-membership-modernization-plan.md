# VLAN membership: next application persistence batch

SOURCE plan at `515232177fee500aa9382df32f3cc391881ce270`, 2026-10-04.
This proposes the next bounded application batch after the current full/fresh
validation and held notification/component/identifier work. No application
implementation or native validation is included. The modernization goal remains
open.

## Current inventory and its limits

The ROOT-produced `captured-document-root-compiler-515232177fee/coverage.log`
contains 3,760 calls discovered by `tools/database/SqlCallInventory.php`.
Independent filesystem-only reaggregation verified every recorded method byte
offset against this checkout and produced:

| Category | Sites |
| --- | ---: |
| Legacy adapter | 2,844 |
| Adapter construction | 8 |
| Adapter internal | 35 |
| Dynamic candidate | 28 |
| Method candidate | 843 |
| Owned native driver boundary | 2 |
| Unowned direct driver | 0 |

The legacy adapter split is `install` 2,465, `inc` 352, `front` 11,
`ajax` 14, `src` 2: 379 outside `install`. These are lexical sites, not
executed queries, and do not establish that all installation calls are inactive.
`Toolbox::createSchema()` invokes canonical `History::install()`;
`Upgrade::apply()` invokes canonical history. The supported modern entrypoints
do not replay the historical `install/update_*.php` chain. Individual obsolete
entrypoints and external/plugin callers still need review before removal.

Discovery scans PHP in `inc`, `src`, `front`, `ajax`, `install`, using the
scanner's finite method list, immediate receivers and native function prefixes.
Its native exception requires the actual same-source DBAL Driver Connection
declaration, PDO property and concrete method body. The two recorded calls are
Postgres driver `prepare` and `query`; zero unowned calls does not prove correct
driver behavior. Aliases, callbacks, external plugin code and dynamic dispatch
remain discovery limits. Method candidates include ORM and ordinary model CRUD.
This reaggregation reused the actual current ROOT scanner receipt; it did not
run PHP or substitute a regex count for that scanner.

Large remaining non-install files include Transfer (66), Migration (31),
DBUtils (14), CommonDBTM (13), API/CommonITILObject/SpecialStatus (12 each).
These are shared or broad workflows requiring smaller caller-led slices.
Budget's two and Supplier's one direct reads are existing unsupported-model
fallbacks behind core repositories. CartridgeItem's count/alarm reads (two)
and knowledge-base audience (one User audience read, wider visibility/search
work beyond it) are alternatives. VLAN membership has an existing concrete
association model, four identified direct reads and two count helpers, with a
finite public lifecycle and permission surface.

## Actual caller surface

| Caller | Behavior to preserve |
| --- | --- |
| `NetworkPort_Vlan::showForNetworkPort()` / `showForVlan()` | Three-way identity: relation `assocID`, owning port and VLAN. Parent READ/edit checks, tagged checkbox and massive-action IDs. Two direct reads. |
| `getVlansForNetworkPort()` | Third direct read; returns VLAN IDs keyed by VLAN ID. No in-repository caller found; retain its public compatibility contract. |
| `getTabNameForItem()` | Two `countElementsInTable` calls; parent-specific count and template exclusion. |
| `front/networkport_vlan.form.php` | Session central access, relation UPDATE check on submitted input, public `assignVlan`, audit Event; absent checkbox means false. |
| `CommonDBRelation::processMassiveActionsForOneItemtype()` | Public CREATE/UPDATE/DELETE admission, existing-link handling and parent relation history; `NetworkPort_Vlan` supplies tagged input. |
| API `createItems()` | Actual relation `can(-1, CREATE, input)` before public add. Generic update/delete API admission also remains authoritative. |
| `NetworkPort::cloneItem()` | Fourth direct membership read; deprecated public compatibility path preserves tagged values and adds each relation publicly. No in-repository caller found. |
| `NetworkPort::post_clone()` | Current generic clone clones the instantiation; it does not explicitly copy VLAN memberships. Do not silently change this behavior while replacing the deprecated reader. |
| `NetworkPort::cleanDBonPurge()` / `Vlan::cleanDBonPurge()` | Existing public child/relation deletion, vetoes and history. RESTRICT ownership must remain compatible with those ordered deletions. |
| `NetworkPort::getSearchOptionsToAdd()` / `NetworkPortInstantiation` | Search-option join descriptions and inherited relation cell rendering. They are separate query consumers, not the three screen reads. |
| `Transfer::transferNetworkLink()` | Existing keep/disconnect/delete/copy branches use public port lifecycle. No direct VLAN copy exists in the traced transfer method; distinguish this from deprecated clone behavior. |

No dedicated VLAN import producer was found in the searched core paths. Generic
API, massive actions and external plugin callers remain relevant. Do not remove
public methods or infer that those paths have no callers outside this repository.

## Model and implementation boundary

`NetworkPortVlan` already owns two non-null RESTRICT associations to
`NetworkPort` and `Vlan`, declares unique `(networkports_id, vlans_id)`, has a
BIGINT identity and a non-null real boolean `tagged`. `ApplicationManaged`
retains public lifecycle ownership. VLAN entity/recursive scope and nullable
name/comment are already property-local. NetworkPort's polymorphic asset
identity is a separate unfinished model; this batch must not invent an
unconstrained asset foreign key or a type catalogue.

First implement `NetworkPortVlanRepository` through those associations: selected
membership by natural pair, membership projections for each endpoint, counts,
and VLAN-ID selection. Preserve relation identities and null/text/boolean types;
never deduplicate links by displayed name. Use an operation-local manager from
the exact supplied adapter, retaining its actual DBAL connection. Avoid filtered
managed collections cached across calls after legacy writers. Screen row adapters
may derive from ORM projections without becoming another schema declaration.

Then implement a meaningful membership command service owning the selected
port/VLAN pair, tagged intent and assign/remove operation. Keep relation identity
stable for tagged edits and require current endpoint existence before persistence.
Public relation add/update/delete must still execute preparation, plugin hooks,
final normalization, audit and cancellation. Repositories must not bulk-delete
memberships behind purge hooks or directly flush around the application lifecycle.
Use the existing owned mutation/lifecycle facilities and typed private operation
context where needed, with supplied writer/frame and final protected-pair checks;
do not add injectable POST flags or another transaction coordinator.

The service must preserve the declared port SAME-right / VLAN VIEW-right policy,
the existing one-write-plus-visible role combination, entity coherence and
restrictive `item_can` hooks. The form's UPDATE and API's CREATE boundary differ
legitimately. Trusted purge/clone helpers must not acquire invented actor rights.
Screen queries currently have a parent READ gate rather than a new endpoint
filter; establish and test that policy explicitly before changing result scope.
Read repositories retain the caller-selected read adapter. Commands use the
actual supplied writer and refuse lost/rebound/unsupported transaction ownership.

No DDL is expected if only inverse collections/inversedBy and domain APIs change.
If actual native inspection discovers a missing/wrong constraint, add a reviewed
canonical migration; do not change the frozen baseline or historical snapshots.

## Required evidence before integration

1. Both-provider focused contracts: actual public assign, tagged true/false edit,
   remove, invalid/missing/zero endpoints, duplicate pair, stable link identity,
   multiple memberships and FK/unique refusal with exact native causes.
2. Characterize actual static and loaded role combinations through real
   form/API/massive-action admission before prescribing denial or changing rights.
   A dynamic port parent plus VLAN VIEW can supply the existing one-write role
   result while the port contributes visibility; do not replace this with a raw
   endpoint UPDATE requirement. Cover READ-only and update-capable combinations,
   hidden/sibling/recursive scope, restrictive hooks, late pair/tag mutations and
   ordinary callback cancellation. Preserve existing caller-owned frame/session/
   model/queue state and exact primary errors.
3. Public purge and deprecated clone: veto and successful deletion on both
   endpoints, tagged value retention, parent history and hook calls; characterize
   current modern clone and Transfer branches without inventing VLAN copying.
4. Read contracts: endpoint and association IDs, nullable names/comments, true
   boolean conversion, tab counts, repeated reads after a legacy write, supplied
   adapter/connection identity and explicit empty entity scope behavior.
5. Canonical fresh installation and populated upgrade remain converged; inspect
   actual constraints/schema, run the discovered coherent full suites on both
   providers. HTTP/browser and live replica routing need separate infrastructure
   evidence. This SOURCE plan proves none of those native/application gates.

Next concrete step: build the repository projections plus one owned membership
command/lifecycle seam and genuine caller contracts in an isolated source batch,
then obtain independent source review before ROOT compiler/native scheduling.
