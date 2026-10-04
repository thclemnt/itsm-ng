# Motherboard, Memory and HardDrive subject ownership

This batch is based on `e658117283f872269c55782be33592519f988399` and is
SOURCE preparation only. PHP compilation, fresh replay, populated adoption,
interruption/retry, full PostgreSQL/MariaDB portability and original application
execution are pending. Earlier seventeen-version/1,070-FK results do not validate
this twenty-version/1,080-FK schema. The modernization goal remains OPEN.

Motherboard declares its Computer association; Memory declares Computer,
NetworkEquipment, Peripheral and Printer; HardDrive declares Computer,
Peripheral, NetworkEquipment, Printer and Phone. Each owning property contains
its actual Doctrine target, nullable unselected column, RESTRICT FK and exact
legacy discriminator. Stock has a null discriminator, no selected subject and a
read-only generated `items_id=0` projection. Assigned identities must be positive.
The three former configuration affinity lists are removed: actual device-screen,
search discovery, API component discovery, repositories, cloning and transfer
consumers read the property-derived metadata. The existing API `with_disks`
filesystem branch retains its historical five-kind eligibility through the
metadata-derived HardDrive affinity view. This compatibility predicate does not
declare Disk ownership; its separate scalar model/query boundary remains OPEN. Definition ownership, binding Entity and
subject Entity remain distinct roles; there is no new same-entity restriction.

The three appended historical stages share `History::VERSIONS` and the existing
`itsmng_migrations` ledger. Explicit frozen snapshots and the unchanged 20261012
staged producer define replay; current entity changes cannot change old migrations.
The old baseline, seeds and historical producers are preserved. Every pending
new family is audited before earlier canonical DDL. Source kinds, target IDs,
boolean zero/one/null semantics and definition/entity/location/state references
are validated before DDL or an append receipt. Historical optional location/state
zero selections converge to NULL. Completed old adoption with missing/changed
core nullability, FK ownership/actions/validation or disabled enforcement refuses
instead of earning a new completed receipt. MySQL FK targets/actions are checked
in the native catalogue because DBAL normalizes RESTRICT and omits referenced
database ownership. PostgreSQL target OIDs/visibility and validated nondeferrable
constraints are inspected directly.

A pending genuinely legacy installation still runs the frozen older adoption and
boolean stages in their canonical order. The family-local converter validates
integer zero/one flags before converting them to native PostgreSQL booleans.
Existing CHECKs that depend on an integer flag require explicit adoption first:
these snapshots own no legacy PostgreSQL integer CHECK rewrite, and preserve both
familiar-name and custom constraints by refusing before DDL. On MySQL/MariaDB,
completed older boolean-domain receipts require their exact frozen, enforced
CHECKs. Already-complete older PostgreSQL boolean history with reconstructed
integer storage can be repaired by the audited family stage itself; the general
canonical preflight retains its older strict storage refusal until that repair.
This is not a blanket drift repair mechanism.

New source contracts cover property input/direct ORM semantics, both platforms'
frozen/current generated declarations, all ten real FKs, actual PDO-native CHECK,
FK and generated-column causes, populated duplicates and wide identities, stock,
invalid source diagnostics, damaged completed-core declarations, composite
incoming projection references, and real interruption/retry of columns, stock
normalization, owning backfill, projection and constraints. The existing full
migration-history contract also gains populated fixtures for all three families
and retains the prior seventeen-version order and assertions; exact current
inventory assertions advance from 1,070 to 1,080 owning references.

The application matrix uses actual public model CRUD and permissions, device
stock commands and AJAX, subject-scoped repository reads including explicit empty
scope, kind-qualified graphs with colliding numerical IDs, Device-ID exclusions,
concrete and deprecated cloning, all supported asset purge callers, financial and
contract/project cleanup, and actual mixed-kind Transfer copy/move/veto flows.
These are unexecuted contracts, not browser or native passing evidence. The
existing managed transaction owner, lifecycle finalizer, immutable insertion
identity and supplied ORM read connection are retained.

Whole-parent cloning's separately recorded historical SQL failure remains OPEN.
Memory/HardDrive legacy aggregate search SQL, wider interactive actor ownership,
concurrent hierarchy movement, unmanaged native transaction epochs, replicas,
official MySQL execution and remote CI remain separate gaps. The concrete next
step is independent source review, ROOT compilation, a separate twenty-stage
fresh install on both providers, focused populated/retry/application controls,
and a complete discovered portability suite on each engine with final native
schema/ledger inspection. Do not mark this batch complete from one focused test.
