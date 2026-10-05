# Energy component ownership source plan

This candidate starts at `53cf6297473bc31400a0777ff905bd79f1e65698` in
`/workspace/itsm-ng-remaining-relationship-family-source`. It has no executed PHP,
compiler, provider, fresh-install, historical-upgrade or application evidence.
The parent candidate remains twenty canonical versions, 357 mapped tables and
1,080 expected owning references. The modernization goal remains OPEN.

## Bounded domain

Convert `glpi_items_devicebatteries` and `glpi_items_devicepowersupplies` from
scalar polymorphic subjects to seven real owning associations. Current actual
`inc/define.php` affinities are Battery: Computer, Peripheral, Phone, Printer;
PowerSupply: Computer, NetworkEquipment, Enclosure. Preserve legitimate unattached
stock, where legacy blank/null itemtype and zero identity become canonical NULL
itemtype with all subject associations NULL and generated legacy items_id zero.
Each selected kind requires exactly one positive, existing subject and clears all
other subject associations. Exact kind spelling is mandatory on both engines.

The device-definition associations already own real required FKs. Add the
existing EntityScopeOwner property attribute to those associations: definitions
own cached entity/recursion forwarding, while asset subjects govern asset ACL
and scoped views. Keep all flags as actual entity-local booleans, nullable
location/state dropdown associations, nullable payloads and battery manufacturing
date. Multiple physical components may share definition and subject; serials may
be NULL or identical. No guessed uniqueness constraint or duplicate collapse.

## Actual caller trace

- `Item_Devices::itemAffinity` and `getConcernedItems` use entity-owned selections
  for converted families. `Plugin::registerClass` (`inc/plugin.class.php:1307`)
  enumerates existing `*_types` keys and can append a plugin class to either
  energy affinity key; plugin `init` and `post_init` may also change configuration.
  Those are real supported extension mechanisms before conversion. A valid
  existing plugin subject is not invalid data merely because this bounded core
  migration cannot represent it. Unsupported historical kinds refuse before
  earlier canonical DDL, keeping source rows and plugin schema for a separately
  reviewed owning plugin mapping/migration. The source diagnostic states that
  boundary. New core property-owned selections do not supply functional plugin
  convergence, and configured unmapped energy extensions remain unresolved.
- `Item_Devices::executePreparedAdd` calls `ComponentRepository::hasSelectedSubject`
  using the actual writer after ordinary preparation/hooks. `ConnexityInput` and
  the common lifecycle validate ordinary old/new endpoint authorization; stock
  uses the real device owner. The component add preparation retains location and
  state propagation. `addNeededInfoToInput` uses EntityScopeOwner for forwarding.
- `ComponentRepository::forDevice` joins the selected actual asset association and
  checks asset entity scope, independently of cached definition entity. Empty
  scope admits none; NULL scope retains the existing all-authorized convention.
- `Item_Devices::cleanItemDeviceDBOnItemDelete` calls `ComponentRepository::detach`
  when keep_devices is selected, clearing the selected owner and itemtype while
  retaining physical rows/payload. Ordinary purge instead uses child lifecycles.
  Actual Enclosure purge lacked this call despite its existing PowerSupply
  affinity and visible keep_devices checkbox. Add the same public cleanup route
  used by the other subject models; native parent RESTRICT cannot substitute
  for this lifecycle.
- `Item_Devices::cloneItem` uses entity `withReference` to remove source association
  columns. `affectItem_Device` uses public update. Transfer selects assigned rows
  and invokes ComponentRepository::canMoveDevice/rebind without inventing new
  hooks; existing deliberate direct rebind behavior remains visible.
- `CommonDevice::deleteFromDB/updateReplacementRelation` enters
  ComponentDefinitionReplacement only when ComponentDefinitionRepository derives
  a closed subject declaration from the actual entity. Conversion activates that
  real owner-delegated command for both families, preserving asset identities and
  payload, old-role restrictions, complete current row checks and atomic rollback.
- `InventoryLockRepository` retains deleted/dynamic link semantics and its mapped
  criteria compile selected subject identities; current stock and quantity callers
  remain in the existing component services.

## Frozen history and review boundary

Append BatterySubjects20261014 and PowerSupplySubjects20261014 as separate versions
21 and 22 in the existing History coordinator/ledger. Their immutable new JSON
snapshots declare their historical core affinities, subject columns, current
source provenance, stock policy, three boolean flags and four existing owner/FK
policies. Reuse the frozen ProcessorStagedTypedItemMigration20261012 producer and
ComponentData20261013 preflight rather than deriving historical targets from
current entities. Earlier snapshots, baseline, seeds and receipts stay frozen.
Both pending energy graphs must preflight before earlier nontransactional DDL.

Planning captures each family table once, preserving native incoming references,
comments, original allocator and PostgreSQL explicit NULL default handling.
Apply replans after each phase; PostgreSQL is transactional, MariaDB/MySQL keeps
its existing audited/copy/projection/constraint journal retry semantics. Current
required schema derives new targets, CHECK and generated projection from owning
properties through BaselineSchema. Expected total becomes 1,087, pending ROOT
compiler verification; no measured inventory increment is asserted here.

## Meaningful source contracts and ROOT execution request

Extend existing pure input and disconnected metadata contracts with both actual
families. Extend the real public component ownership and definition-replacement
contracts with all seven subjects, battery date and null payload, legitimate
physical duplicates, exact discriminator and positive/invalid targets, stock,
old/new ACL, insert/update/delete, entity-scoped reads, cloning/transfer, parent
purge/keep_devices and hook-veto rollback. Historical schema tests must admit both
new versions while retaining full original captured schema/receipt restoration.
The energy schema entrypoint supplies its two families to the existing schema
contract so the original three-family timeout gate remains distinct. Generalize
its joint pending-family check from its actual supplied family models and compare
date payload exactly; retain all original assertions and native cause matchers.
Add actual frozen-old energy adoption/refusal/retry cases and complete source
row/receipt/allocator controls; never mark old expected shapes complete by hand.

ROOT alone executes syntax/style, pure and disconnected compiler checks against
this worktree, inspects actual discovered contract count, and owns any disposable
native install/upgrade/provider runs. Preserve the next20 original gates and the
unresolved MariaDB 300-second component failure independently. This source
candidate needs review, compiler and its own native fresh/upgrade/retry/full gates
before integration can be a passing checkpoint.

## Prepared source status

The isolated source implements the seven associations, definition scope ownership,
additive frozen21/22 stages, real Enclosure cleanup and the contract extensions
above. The separate energy schema contract also registers a real plugin class via
Plugin::registerClass, loads its existing public subject, inserts its historical
kind and requires the precise core-mapping refusal with unchanged full rows,
raw receipts, schema, configuration and subject identity. Its fixture deliberately
uses the same persisted Computer table to prove a valid target alone cannot make
an unrepresented discriminator a canonical core owner. Separate plugin tables and
external plugin execution are not convergence claims.

Only plain source/Git review and `git diff --check` have been performed by this
agent. ROOT must execute every syntax/style, JSON, pure, metadata, provider and
application check. The original three-family schema contract still selects only
its original families; the separate energy entrypoint invokes the same source
assertions for Battery and PowerSupply. Full migration-history gains populated
raw energy rows and exact date preservation while retaining its original version
prefix, previous assertions, key/ledger ownership and timeout budget.
