# Architecture successor: source composition and execution gates

This source composition starts at accepted inbox/current-read commit
`8ae2459b3585d4e0cd5d6e896bf4e911cb52a2d2`. It retains that entire tree,
including the application/provider corrections and the actual published
`53cf6297473bc31400a0777ff905bd79f1e65698` checkpoint recorded in
[modernization-handoff.md](modernization-handoff.md). Existing results belong
to their pinned producer checkout. They do not validate this successor.

## Included source and ownership

The first 18 original commits are retained through `cherry-pick -x`, with exact
changed file bodies and modes at every original before/after boundary. Three
additional reviewed timestamp commits are retained with explicit whole-file
projections for three overlapping entity method additions:

- Domain adoption date-range fixture `17b6a08c62c5f19e7cd6ea6de2087e616924d522`:
  probe the supplied engine's actual native TIMESTAMP acceptance in an owned
  rollback frame, then require the corresponding 2040/2107 plan behavior and
  complete data/receipt restoration. No provider/version guess replaces it.
- Seven property stages ending `12cf7a79129e02cc0d2afde301468bed393d3814`:
  five tree entities own their reference keys and named indexes; seven mapped
  clock properties own native timestamp declarations. The final shared
  AttributeDriver/BaselineSchema union retains both declarations and generated
  writable clock readback. Intermediate replacement stages are provenance,
  not independently executable milestones.
- Both Calendar stages ending `528eebee378d00e1ae21dba445bf50aeaa874e93`:
  CalendarHoliday joins its owning Holiday, keeps individual link identities,
  and delegates inclusive/annual date semantics to Holiday. Public lifecycle,
  clone, purge and selected connection behavior remain explicit.
- All five VLAN stages ending `1b8450399215bfe69fe0f3e35576c4de61f25596`:
  meaningful owning reads and membership intent preserve endpoint permissions,
  entity ancestry, physical link identity, boolean input, parent validation,
  prepared command consistency and ordinary public lifecycle hooks. The
  independently accepted sixth stage
  `aebe787bec10b2d30e16dec1035bed66d9dc745b` admits each pessimistic read through
  the existing MySQLConnection policy, including after callbacks. Tests toggle
  only an actually available session capability and require refusal, rollback,
  caller-owned reset and retry. They also require a real native FK error.
- Selected-writer stage `cf24515695dbbd747ea926714d4d813f34e9935e`:
  CommonDBTM exposes a protected, empty default capture hook after slave refusal
  and before add/update preparation and plugin callbacks. It neither selects
  another connection nor begins a transaction or grants permission.
- Energy stage `2d71268ec64768b20c4fd21b3bf91050e61cd587`:
  ItemDeviceBattery owns four asset associations and ItemDevicePowerSupply
  owns three. Entity properties own targets, nullable stock semantics, exact
  discriminators and the read-only compatibility projection. Definition scope
  differs from asset scope. Multiple physical components and nullable payloads
  remain valid. Enclosure purge enters the existing keep_devices lifecycle.

The VLAN followup is composed after the disjoint writer/energy changes; its
whole original before/after bodies remain exact. The prerequisite current-read
policy from the accepted base is present. The older isolated VLAN checkout alone
is not a runnable dependency closure for that followup.

## Connection and callback boundaries inspected in source

The only core declaration of captureLifecycleWriter is CommonDBTM's empty
protected method. No core model in this composition overrides it. Therefore it
does not activate a new connection policy for Calendar, VLAN or browser inbox.
VlanMembershipService already receives the selected DBAdapter before callbacks,
captures its physical managed frame and supplies that adapter to Orm::create.
VlanMembershipCommand checks the exact global adapter, physical DBAL connection
and active captured frame around prepared operations. Each of its five owning
pessimistic query entrypoints now checks current-read admission immediately
before SQL; nonlocking reads keep the supplied connection and do not alter its
session state. The provider guard returns directly on PostgreSQL and inspects
MySQL/MariaDB capability without silently repairing a caller transaction.

BrowserNotificationInbox.pending uses the supplied adapter for ordinary reads.
Acknowledgement refuses a slave and creates its manager within the supplied
writer transaction; NotificationQueueRepository admits its actual locking read.
CalendarRepository receives the caller's EntityManager, and Calendar::isHoliday
passes the application's selected DBAdapter. Neither path is changed by the
empty writer-capture hook. This is source tracing, not live replica verification.
VLAN public persistence still enters the existing CommonDBTM lifecycle; this
bounded conversion is not a claim that all application writes now use ORM.

## Frozen installation and historical boundary

BatterySubjects20261014 and PowerSupplySubjects20261014 append versions 21 and
22 to the same canonical History coordinator and its existing migration ledger.
They add two frozen producers and two JSON snapshots. Every pre-existing
producer/snapshot, baseline, frozen seeds and initial seed input remains
byte-identical to the accepted base. Current entity metadata does not rewrite
historical stages. The existing current ComponentData inspection/producers,
including the newer populated-upgrade work, are retained byte-for-byte.
History.php changes are exactly the accepted energy registration, joint
preflight, plan and application wiring. There is no competing ledger.

The intended schema has 357 mapped tables and 1,087 foreign keys after these
seven additional subjects, with 22 canonical versions. These are source
expectations awaiting the disconnected compiler and native inspection. No
measured successor schema count or successful replay is claimed.

## Exact static discovery and validation required

The unchanged suite.py discovery rules yield 234 CLI contracts at the accepted
base, 243 at the first architecture freeze, and 245 after the complete timestamp
ownership extension. This was determined from tracked
filenames without importing or running the runner. FixtureRecords.php,
sql-inventory.php, search-benchmark.php, seed-report-web.php and
*-web-fixture.php stay excluded; run.php and initial-data.php remain first.
The first nine new CLI contracts are calendar-closures, energy-component-subjects-schema,
lifecycle-writer-capture, native-timestamps-metadata, native-timestamps,
tree-schema-ownership, vlan-current-read-admission, vlan-membership-callers and
vlan-membership-ownership. The timestamp extension adds
native-timestamps-coverage-metadata and native-timestamps-coverage. Existing
contracts and the 300-second limit remain.

No PHP parsing, style, compiler, database, native driver, application, browser
or remote CI job has run against this combined source checkout. Independent
source review and inverse proofs are distinct gates. ROOT owns execution on
isolated disposable databases after freezing this composition:

1. Parse/style and disconnected metadata/schema comparison on both providers.
   Compare complete schemas, named indexes, nullability/defaults/comments,
   compatibility expressions, FKs/CHECKs and native touch definitions; exercise
   provider metadata order in one process. Confirm all prior historical outputs.
2. Run the eleven new contracts and existing clock/UOW, locks, tree cache/parents,
   lifecycle callbacks, inbox, component definition/ownership, scoped reads,
   clone/purge/keep_devices, schema-check and upgrade-entrypoint controls. Use
   actual native invalid targets, exact discriminator/duplicate behavior and
   public callback refusal/reset/rollback/retry. Preserve supplied read routing.
3. Replay the complete 22-version frozen baseline/seeds/history from empty
   PostgreSQL and MySQL/MariaDB databases through the ordinary installer. Test
   populated frozen-old upgrades, zero/null sentinels, real booleans, duplicate
   payloads, wide identifiers, sequences/public allocation and complete data,
   old receipt and allocator preservation. Exercise real interruption/retry
   after nontransactional DDL, joint preflight diagnostics and idempotency.
4. Run the complete discovered suite in its normal order on both providers,
   retain final schema/native inspection and parent-database invariance, then
   required application/API and browser flows. Restore dependencies and build
   assets before browser claims. Keep original provider/CI failures separately
   visible until their actual reruns establish a result.

Fresh success, static acceptance, or one focused contract cannot close the goal.
The original handed-off certificates_items.items_id schema-check failure still
requires an actual isolated/full-order causal reproduction. Published ROOT53
fresh/upgrade and historical CI facts remain in the handoff; an active full run
or an incomplete environment-failed run is not a passing successor suite.

## Open architecture work and durable objective

Native persistent goals are unavailable. The durable objective remains OPEN:
cover sound table relationships with real FKs; use meaningful ORM entities,
repositories and domain services for application persistence; eliminate direct
DBmysql/mysqli application calls; retain PostgreSQL and MySQL/MariaDB behavior;
replay frozen DBAL baseline/seeds/full history for installations and upgrades;
preserve authorization, hooks, notifications, history, cloning, purge and routing.

The frozen-baseline source inventory has 472 TIMESTAMP properties across 208
mapped tables. After the initial seven-property composition, the independently
reviewed three-stage extension ending
`35afde6be0de869f67764a34b1dc4f54a09eb6b9` supplies the other 465 entity-local
markers. CronTask.lastrun additionally owns its frozen missing comment; the two
nonnullable CURRENT_TIMESTAMP fields retain their existing default options.
Current source declaration coverage is complete for that historical cohort;
provider type, range, timezone, hydration and schema behavior still need actual
validation. No migration version, runtime catalogue or inference is added.
Other runtime schema catalogues, plugin owning affinities and
plugin installation/upgrade convergence, remaining polymorphic relationships,
legacy query/write paths and broader application boundaries remain open. Valid
registered plugin data must be preserved with an explicit unsupported ownership
boundary, not reclassified as an orphan to obtain a clean migration.

Next: validate this coherent combined ownership/history batch, diagnose actual
failures without weakening contracts, then continue finite relationship/domain conversions
and remove the remaining runtime schema catalogues and legacy persistence paths. SOURCE proofs and original accepted evidence remain immutable.

## Additive timestamp composition proof

The first architecture freeze is immutable at
`5b1dfc933e375d2cb84fcf5814ec558aa078f98d`. Its source inventory of seven owned
clocks and 243 contracts is an earlier checkpoint. The three later timestamp
stages preserve the original commit subjects/authors and provenance, all prior
clock/index drivers and outcomes, and all old installation/migration inputs.
Holiday.containsDay, User.prepareAuthenticationInput and both QueuedNotification
browser methods remain complete, byte-exact accepted additions. Removing those
method blocks reproduces the entire corresponding original timestamp file body.
Reversing the whole accepted pre-timestamp scope patch from the composed temporal
tree reproduces the complete original 35afde6 Git tree; reversing the whole new
extension reproduces the complete actual prior architecture tree.

The initial three-context private-index projection refused Holiday's legitimately
changed marker context and did not alter source. The complete zero-context scope
patch retains exact removed bytes and requires final whole-tree identity; its
result is separately pinned. This is not a suppressed database assertion.
The original seven-clock metadata contract retains its literal seven-count verify
call and every original assertion; its disclosed test seam selects its existing
seven-property class cohort. The new exhaustive contract independently requires
all 472 declarations with no extras against the frozen historical oracle.
Original native runtime clock/probe controls remain exact. The extension's
independent source acceptance and ROOT's isolated parser-only receipt are
references for their own producer, not execution results for this final checkout.
