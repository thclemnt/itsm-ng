# PostgreSQL and Doctrine modernization goal

Latest full integrated validation:
[canonical upgrades and owning dropdown choices](modernization-upgrade-dropdown-validation.md).
Both discovered portability suites pass 147/147 contracts at application source
`448699cfbb7d4847a73edc36eabf0f3da1042fb3`; sequential browser suites pass 12/12
tests each with zero skips. Earlier concurrent browser timeouts remain recorded.

Newer integrated application source is
`a6c4736df281b6d753b3cfb1ca0419af7a07966b`: atomic mapped deletion, six operating-system
subject owners, Domain plugin adoption and document ownership, identifier sequence
repair, authoritative empty session scope, Contract persistence and notification
queue admission, owning endpoint authorization before lifecycle writes, Domain
commercial Supplier coherence, PostgreSQL CLI initialization without mysqli,
and shared PostgreSQL transaction outcome/handle ownership.
Their focused evidence appears below and in the linked batch
notes. A full combined rerun has not yet run. Offline discovery at this source
finds 164 contracts; this count is not a passing result.

The integrated [owning endpoint repair](connexity-ownership.md) passes separate
fresh installs, its authorization contract, the unchanged ITIL migration contract,
12 adjacent contracts and schema comparison on both providers. Its original
application scope passes 48/48 methods and 2,299 assertions each, including 69
Transfer fixture types and inherited Link ownership. These results belong to that
isolated batch and do not replace the older full combined checkpoint.

Parallel review also identified a Transfer path that ignores a refused parent
update after child mutations. The integrated
[commercial Supplier repair](domain-commercial-supplier-ownership.md) passes its
focused contract, twelve adjacent contracts, 36 original application methods and
1,209 assertions on each provider. Native inspection passes on both populated and
fresh databases. The first MariaDB historical-adoption attempt exceeded the
unchanged 300-second limit; its actual interrupted journal resumed in 23.198s,
and a separate fresh complete contract passed in 234.233s. These are separate
results; the earlier timeout remains recorded.

The CLI correction reproduces the original initialization failure using an
actual PostgreSQL-only PHP process and passes five isolated capability scenarios.
Those use a nonconnecting adapter spy; a live PostgreSQL-only installation has
not been verified. The upcoming application matrix uses PHP with both drivers.

The [PostgreSQL transaction ownership repair](postgresql-transaction-ownership.md)
passes eleven selected contracts on both providers, including complete raw
migration-history replay in 127.888s/275.773s and final native schema inspection.
It rejects an aborted physical transaction before DBAL loses its rollback frame,
preserves savepoint recovery and fixes handle ownership after DBAL auto-close.
Two genuine native calls disappear; 22 remain. The separate, previously failing
Transfer lifecycle hook probe now passes 396 PostgreSQL / 371 MariaDB assertions
with the guard integrated. Transfer's broader validation is still running.

Transfer source review additionally found that full-tree import ignored a refused
intermediate node and ownership forwarding ignored refused child updates. The
current batch repairs those actual lifecycle boundaries and validates atomic
Transfer before freezing source for
fresh installs, populated/retry history, complete
portability and broader application/browser validation. PostgreSQL 18.6 and MySQL
8.4.11 are provisioned; their application matrix remains pending. Software
assignment ownership is prepared separately and remains unvalidated. Normal
authenticated Git fetch and push work; remote advances are checked before integration.
The overall goal remains open.

Further source review found that the mapped writer silently coerces invalid
boolean scalars and most MySQL/MariaDB boolean columns lack zero/one constraints.
Entity-local boolean declarations already exist; they must remain authoritative.
A separate unvalidated worktree is preparing strict metadata-derived input
validation, an append-only frozen boolean-domain migration and read-only native
enforcement checks. Older history, nullable preferences and existing Category/
Domain constraints must remain intact. Software ownership and native PostgreSQL
transport retirement remain separate open batches; the latter needs supported
result-type metadata rather than another application driver wrapper.

Continue the PHP application on `th/exp/postgres`; the verified starting commit is
`897c5c9bd636f65e5a2a596d350ef087635303f9`.

No native persistent-goal API is available in this cloud session. This committed
record retains the implementation objective and validation checkpoints.

## Objective

Cover all soundly representable table relationships with real foreign keys; use
meaningful ORM entities, repositories and domain services for application
persistence; eliminate direct native-driver calls, including DBmysql/mysqli.
Complete PostgreSQL support while retaining MySQL/MariaDB, replace runtime legacy
SQL-dump installation with a frozen DBAL baseline and canonical migration replay,
and improve ownership and architecture. Entity properties own current types,
booleans, associations, nullability and legacy input policies. Historical
definitions stay frozen. Preserve permissions, scope, lifecycle hooks,
notifications, history, cloning, purge behavior and read/write routing.

## Execution plan

1. Reproduce the reported `glpi_certificates_items.items_id` schema mismatch on
   isolated fresh databases and in full-suite execution order. Compare declarations,
   provider introspection, migration DDL and fixture cleanup. Fix the cause without
   relaxing schema checking.
2. Introduce an explicit historical DBAL baseline, reproducible frozen seeds, and
   empty-database replay through the existing `itsmng_migrations` ledger. Validate
   populated adoption, zero sentinels, booleans, sequence synchronization, invalid
   data diagnostics, retry after MySQL DDL and convergence.
3. Continue coherent relationship and application persistence conversions; trace
   actual callers and cover discriminator validity, duplicates, CRUD, projections,
   domain queries and lifecycle cleanup on both providers.
4. Run the discovered full portability suites and appropriate application suites
   before milestone claims. Record exact evidence, environment limits, remaining
   gaps and the next concrete step in each Conventional Commit batch.

## Initial cloud setup checkpoint (2026-10-02)

- Remote branch verified through normal Git; it has not advanced beyond the
  supplied commit. Checkout is clean before application edits.
- Network operations require `exec_command` with network access enabled. The
  inherited proxy works with this permission; no connector object reconstruction
  or proxy bypass is needed.
- `docs/orm-port.md` is absent. Read `docs/orm.md`, `docs/postgresql.md`, the
  portability runner and `.github/workflows/database_portability.yml` instead.
- The runner currently discovers 127 contracts; the supplied 126/127 result is
  handoff evidence, not a cloud test result.
- Docker Hub refused an unauthenticated PostgreSQL image pull due to its rate
  limit. Provisioned a user-local PostgreSQL 15 server from signed Debian packages
  instead, alongside the existing user-local MariaDB. Disposable `itsm_port_*`
  schemas and separate configuration directories are used; existing development
  application databases are preserved.
- PHP 8.2.33 now has PostgreSQL extensions; Composer dependencies and platform
  requirements pass. Local setup, logs and database files live outside the
  application repository in `/workspace/itsm-env`.

## Validation and next step

At the verified starting commit, isolated fresh schema checks pass on both
providers. Both complete suites also pass schema checking after the reconstruction
fixtures. Expected and actual certificate `items_id` are nullable signed BIGINT
with NULL default, the historical relationship comment, and the nine-branch
generated projection. Full schema comparison reports no differences.

The supplied commit already preserves this comment in `TypedItemMigration` and
in `infrastructure-assets.php`'s old-table reconstruction. The supplied failing
log is therefore not reproducible at this checkout. A new read-only schema-check
assertion verifies that losing a historical comment still fails comparison.

Initial cloud full-suite results were PostgreSQL 125/127 and MariaDB 121/127.
Both subprocess-based contracts failed because children could not execute the
user-local PHP installation with its libraries and extensions. Fixed the local
PHP executable's interpreter/library path and inherited PHP configuration;
both concurrency contracts subsequently passed on both providers. MariaDB also
exposed future TIMESTAMP fixtures beyond its supported 2038 boundary. The event
and ticket-action fixtures now use supported future dates and retain every
retention and selection assertion. Two additional MariaDB contracts exited
without diagnostics during simultaneous provider runs and passed individually;
the runner now reports failed process exit statuses.

After these fixes, coherent full reruns passed **127/127 contracts on PostgreSQL
15.19 and MariaDB 10.11.18**, using PHP 8.2.33. Schema comments, native timestamps,
invalid-reference upgrades, migration retries and final schema checks remain
enabled. These are cloud CLI results, not browser or remote CI evidence.

Recomputed inventory: 357 mapped tables, 1,003 enforced references, 23
discriminated identities, 41 polymorphic candidates, one pending candidate,
2,896 legacy adapter query sites and 23 native-driver sites.

## Canonical installation and adoption batch

Runtime installation no longer parses `install/mysql/glpi-empty.sql`. The explicit
`Baseline20261001` DBAL snapshot defines 355 historical tables; its provider-specific
DDL, comments, TIMESTAMP behavior and indexes remain independent of current ORM
metadata. A frozen seed snapshot retains stable identifiers and values; only root
localization and a new installation token vary. Fresh CLI and web installers replay
baseline, transactional seeds, the existing adoption migration and the historical
PostgreSQL integer-flag conversion in the same `itsmng_migrations` ledger.

Existing installations adopt baseline/seed records after validated upgrades and
schema convergence, preserving their data rather than importing installation seeds.
`db:migrate` previews this history without writing. MySQL table creation checkpoints
accept an interrupted CREATE only when the actual declaration matches history;
conflicting tables fail explicitly. PostgreSQL replay remains transactional. Forced
MySQL replacement refuses external foreign keys into core before dropping tables.

Cloud CLI fresh installs and coherent full suites passed **128/128 on each provider**
(PostgreSQL 15.19, MariaDB 10.11.18, PHP 8.2.33). The newly discovered migration-history
contract uses a separate disposable database and verifies immutable definitions,
CREATE and seed interruption/retry, conflicting retry schemas, populated near-32-bit
identifiers, zero sentinels, invalid references before DDL, invalid boolean samples,
nullable integer flags with NULL data/defaults, preservation of credentials/audit
text, typed certificate projections, complete/adopted ledgers, read-only preview,
idempotency, final schema comparison and post-import sequence allocation.

CI now provisions the separate history fixture database and a PostgreSQL lock budget
of 512 for the atomic identifier-widening migration. Cloud results do not establish
remote CI on PostgreSQL 14/18, MariaDB 11.8 or MySQL 8.4; those matrix runs remain
required before a supported-version release claim. After rebuilding assets, HTTP installation, login and core list checks also
passed on both providers. The focused history contract passed again after source
formatting. Legacy application and browser checks are recorded separately as
they complete.

The overall goal remains open: 2,896 adapter query sites and 23 native-driver sites
remain at this checkpoint, along with sound polymorphic conversions beyond the
already enforced relationships. Live replica routing has not been verified. Next: replace financial warranty expiration selection with an
association-backed Infocom repository operation, exercise alert/entity/date and
lifecycle behavior on both providers, and rerun coherent suites.

## Warranty domain batch and broader application evidence

`InfocomRepository::warrantiesExpiring()` now selects owned financial records and
joins the exact owning Infocom alert association with DQL. It retains inclusive
calendar-day boundaries, duration/alert bit semantics and exact entity scope on
the caller's connection. `Infocom::warrantyExpiresOn()` owns calendar-month
clamping: January 31 plus one month expires February 28/29, matching database
selection and notification rendering. Public cron execution retains inherited
configuration, asset loading, notification events, per-entity accounting and
public Alert creation. Financial asset purge still runs existing lifecycle hooks.
There are no remaining direct adapter query sites in `inc/infocom.class.php`.

The discovered full suites passed **129/129 contracts on each provider**. The
new warranty contract covers positive/zero/lifetime durations, bit flags, exact
prior alert kind/event, empty/foreign scopes, month ends/leap years, caller
connection, disabled notification side effects, inherited cron settings,
repeat-run deduplication, native duplicate rejection and public asset/alert purge.
No external notification transport was enabled or executed.

Assets rebuilt successfully. HTTP installer/login/core lists and ten report paths
pass on both providers. MariaDB legacy application checks pass all **154 methods
and 9,388 assertions** across DB/iterator, CommonDBTM, memberships, users, tickets,
calendars, contracts, profiles and financial amortization.

The first PostgreSQL browser run passed seven timeline tests but three observer
checks required the mailing-field setting disabled by fresh seeds. With mailing
fields enabled and actual notifications disabled, nine of ten browser tests pass.
The remaining test exposed real API ticket collection failures: MySQL LIMIT syntax
and integer comparisons against PostgreSQL boolean flags. Ticket creation and
multiple observers reached persistence; collection retrieval returned no JSON.
The browser also exposed a non-traversable native query in category selection.

Broader PostgreSQL legacy checks are **not green**: 108 methods ran with four
failures, 538 errors and ten exceptions. Evidence includes fixed CHAR locale
padding (`en_GB     `), native command-result versus boolean assertions, exact
MySQL quoting assertions, aggregate COUNT ordering, mixed-case `itemType` lookup,
and sequence collisions in old fixtures. These findings require source/fixture
triage; the passing portability suite does not prove PostgreSQL application
completion. Cloud logs and HTTP/browser captures are under
`/workspace/itsm-env/evidence`; they are local evidence, not remote CI.

Recomputed inventory: 357 mapped tables, 1,003 enforced references, 23 discriminated
identities, 41 polymorphic candidates plus one pending candidate, **2,895 adapter
query sites and 23 native-driver sites**. Next: replace the actual ticket API
collection path with an ownership- and authorization-aware repository; repair
category selection and the directly observed legacy application failures, then
repeat browser and broader provider validation. Keep the 20261001 baseline,
seed snapshot and adoption history immutable for new domain migrations.

## Ticket domain and populated-upgrade follow-up

Normal authenticated Git fetch and push work in the managed environment with
network-enabled command execution. The warranty commit has been pushed to
`th/exp/postgres`; later remote advances must still be fetched and preserved.

Ticket API collections now use `TicketCollectionRepository` and a session-derived
`TicketVisibility` snapshot. Owning actor, group and validator associations retain
the existing permission masks, with entity restrictions applied independently.
EXISTS predicates prevent repeated actor links from multiplying counts or pages.
Boolean filters, bound text patterns, stable pagination, parent read checks and
public API response formatting are exercised on both engines. Temporal filters
use explicit entity-type-based date/time projections rather than passing
PostgreSQL timestamps to LOWER. Other API collections still use the legacy path;
unrecognized parent relationships retain their historical fall-through and need
a separate explicit route/model decision.

Category choices use their mapped owning entity and recursive visibility through
`TicketCategoryRepository`. The actual AJAX caller intersects requested entities
with authorized active scope and retains incident/request/helpdesk flags and the
legacy empty option. Full-order failure evidence showed a rolled-back fixture's
filesystem ancestor cache `[0]` despite the new child having stored parent `1`.
The contract now keeps a real warm cache local to its transaction and restores
the previous cache afterwards. No category scope assertion was removed.

All entity-declared fixed CHAR types own padding semantics, including scalar
projections. Locale, preferences/reset tokens, rule conjunctions and document
hashes retain logical values; TEXT whitespace and NULL versus empty remain
distinct. No historical schema definition changed. Direct attachment selection
now follows the declared subject association and retains each binding ID;
forms still load Document models and run their hooks. Relation totals omit
irrelevant list ordering. PostgreSQL adapter commands return booleans, while
explicit RETURNING queries retain row sets and affected-row accounting.

Parallel review identified two populated-upgrade gaps beyond fresh installation:
canonical adoption did not synchronize imported identifiers, and generated-column
widening could lose comments/nullability through an explicit column declaration.
The fixes use one metadata-inspected DBAL sequence service without rewinding
advanced sequences, and preserve generated declarations through interrupted DDL
replay. Historical definitions and the existing ledger remain authoritative.
Generated-column comment loss is relevant to the handoff symptom, but does not
establish the unavailable original log's exact execution path. The original
current-install certificate mismatch remains unreproduced at the supplied commit.

The legacy dataset explicitly imports ticket IDs 100/101; it now synchronizes
sequences once before marking import complete. An isolated fresh PostgreSQL
installation passes **108 application methods / 5,327 assertions**. MariaDB
passes **154 methods / 9,388 assertions**. Both providers pass **ten browser tests**
covering observer changes, ticket collections, timeline followups/image uploads,
tasks and state changes. E2E preparation honors config/var environment paths,
enables required API/mailing fields and disables actual notification delivery.

Final coherent portability reruns pass **132/132 discovered contracts on both
PostgreSQL 15.19 and MariaDB 10.11.18**, followed by clean read-only schema
comparisons. Evidence is in `reviewed-final-suite-{pg,mysql}.log` and
`reviewed-final-schema-{pg,mysql}.log` under `/workspace/itsm-env/evidence`.
These runs include frozen history replay, populated adoption, interrupted DDL
recovery, generated-column declarations, real sequence allocation, public ticket
collections, category scope, fixed CHAR behavior and temporal filters.

The recomputed inventory is 357 mapped tables, 1,003 enforced references,
23 discriminated identities, 41 polymorphic candidates and one pending identity;
2,893 adapter query sites and 24 native PostgreSQL-driver sites remain. The extra
native site checks command status inside the existing PostgreSQL transport.
These figures are discovery checkpoints, not domain-conversion completion.

GitHub's registered workflow list does not contain `database_portability.yml`, and an
authenticated dispatch attempt returned HTTP 404. No remote matrix result is
claimed. PostgreSQL 14/18, MariaDB 11.8, MySQL 8.4 and live replica routing remain
unverified here.

The overall goal remains open. Next relationship batch: convert `ItemProject`'s
35 configured subjects with property-owned associations and a frozen appended
canonical migration. `projects_id` owns the project; a Project subject needs a
separate `subject_projects_id`. Trace visible lists and notifications, add
metadata-derived public cleanup for all subjects (only seven currently clean
Item_Project explicitly), and test owner/subject/self-link purge separately.
Preserve existing clone/transfer behavior. Legacy plugin appliance discriminator
imports need deliberate conversion or pre-DDL diagnostics. Category
`is_incident`, `is_request` and `is_problem` still need a separate frozen migration
to real boolean properties; `is_change` already uses boolean mapping.

## Project ownership and category checkpoint (2026-10-02)

The cloud Git environment uses normal authenticated fetch/push; the preceding
checkpoint is on origin. The integrated source checkpoint is `6636b268ed`, with
seven coherent commits covering clone ownership, picture cleanup, project subjects,
nested ticket routes, category booleans and their metadata inventory.

ItemProject now owns all 35 configured subjects. Its `projects_id` container and
`subject_projects_id` Project subject remain distinct, including self-links.
Property metadata derives the generated read-only compatibility identity,
discriminator constraints and runtime scope; appended `20261003_project_assets`
freezes only its historical upgrade targets. Public lists/counts, installed-device
labels, notification model hooks, binding IDs, recursive entity/template/rights
filters, all subject purges, asset transfer, explicit relation clones and parent
clone exclusions are covered. No 20261001 baseline, seed or adoption snapshot
changed.

Canonical history now stores an explicit installation-completion marker. A
completed installation with a later migration pending must use db:migrate; it
cannot bypass existing-schema installation safeguards. Interrupted fresh replay
after the former four-version checkpoint remains resumable. Actual CLI preview
shows every appended data/DDL phase without writing. Populated upgrades preserve
wide identifiers, independent project roles and generated-column comments.

Normal parent cloning retargets entity-declared subject columns together with
legacy identity, retaining contract limits, document timeline multiplicity, actor
attribution, NULL/empty data and audit history. A process-local reproduction of
the old Clonable trait failed actual Computer cloning with the original canonical
reference disagreement; the repaired public flow passes on both providers.
DCRoom purge no longer attempts to unlink a directory for a NULL picture name.

Nested ticket collections resolve direct/inverse owning associations and all 20
typed ItemTicket subjects. Unqualified User routes include recipient/updater
roles, paired ticket relations include both declared ends, and unsupported nested
routes return 400. Parent existence/read checks and ticket/entity visibility stay
conjunctive; counts and pages never gain actor fanout.

ITILCategory is_incident/is_request/is_problem are property-owned booleans.
Appended `20261004_category_boolean_flags` validates 0/1/NULL before DDL; PostgreSQL
uses BOOLEAN, MySQL retains historical signed integer storage with enforcing
CHECKs. Interrupted replay, unsigned normalization, comments, defaults,
nullability, conflicting checks and actual category endpoint scope are tested.
The MySQL 8.4 NOT ENFORCED branch has a dedicated fixture but remains unexecuted
here; MariaDB 10.11 and PostgreSQL 15 are the actual engines.

Fresh integrated installations and coherent full suites pass **137/137 discovered
contracts on both providers**, followed by clean final read-only schema checks.
All ten browser tests pass on each engine. Matched application classes pass
PostgreSQL 108 methods/5,327 assertions and MariaDB 154 methods/9,388 assertions,
with no skipped or void methods. Exact commands, class lists, source checkpoint
and results are in `/workspace/itsm-env/evidence/integrated-validation-commands.txt`
and `integrated-application-validation.md`; full logs are `integrated-suite-*.log`,
`integrated-schema-*.log`, `integrated-browser-*.log` and `integrated-legacy-*.log`.

Additional PostgreSQL Entity coverage **fails**: the 113-method run has one
exception, although the atoum process returned zero. Entity::testChangeEntityParent
passes `[true]` to the adapter, which renders WHERE(1); RSS administrative
visibility also supplies this literal. PostgreSQL rejects the integer predicate.
The failure is preserved in `integrated-legacy-extra-entity-pg.log` and assigned
to the next isolated batch. Passing matched suites must not erase this finding.

The current static inventory is 357 mapped tables, 1,038 enforced references,
24 discriminated identities, 40 polymorphic candidates and one pending identity;
401 booleans derive from entity properties. There are 2,892 adapter sites and 24
native PostgreSQL-driver sites. Native persistent goals remain unavailable; this
committed objective and handoff are the durable record. The overall goal remains
open. Next isolated batch: eight ApplianceItem subjects plus three nested
Location/Network/Domain recipients, preserving nested duplicates and cloning;
replace the destructive plugin importer with a planned atomic domain import and
existing-ledger provenance; fix real boolean predicates and validate Entity/RSS.
The current plugin importer is unsafe before/after adoption; until the replacement
is validated, legacy import needs a compatible historical application/schema
before switching to modernized source. Remote CI/release engines/live replica
validation remain unverified.

## Appliance ownership, imports and predicate repair (2026-10-02)

The application source checkpoint is `324b8109a7`. Eight ApplianceItem subjects
and three ApplianceItemRelation contexts now own typed associations. Container
ownership remains separate; multiple nested links to the same context remain
distinct. The read-only generated compatibility identity, real foreign keys,
discriminator and required-subject constraints derive from properties at runtime.
Appended `20261005_appliance_assets` and `20261005_appliance_recipients` freeze
their historical targets in the existing canonical history. Earlier baseline,
seeds and adoption snapshots remain unchanged.

ApplianceAssetRepository implements owned composition, counts, reverse lookups
and context queries. Public add/update, child and parent cloning, all subject
purges, transfer, permissions, recursive entity/template visibility, notification
model hooks and binding identities retain their application semantics. Native
invalid-target/discriminator/duplicate tests and populated four-phase migration
retry tests run on both providers.

The destructive appliances-plugin importer is replaced by a validated source
graph and domain import through public lifecycles. It preserves assigned wide
identifiers, nullable data, booleans, timestamps, profiles and relevant audit
roles; existing core records are never truncated or matched by content. Complete
preflight precedes writes, including provider-native unique collation behavior.
An optional provenance receipt uses the same `itsmng_migrations` ledger and a
frozen source fingerprint. Exact retries preserve subsequent user edits; changed
exports refuse and require explicit reconciliation. Database/session changes
roll back on failure; external hook effects, files and immediately delivered
notifications cannot be rolled back. Validation disables actual delivery.

This importer requires completed canonical history. It is not a pre-adoption
converter for constrained legacy plugin identities: those need the matching
historical application/schema import or a separately designed frozen DBAL remap.
Retired unmatched plugin audit subjects retain their original kind to prevent
unrelated core records with the same identifier from acquiring their history.

Two defects were reproduced before repair. Under a MyISAM default, a receipt
survived an application rollback; Ledger now explicitly creates InnoDB storage
and refuses existing nontransactional ledgers with reconciliation guidance.
Missing-ledger creation inside a MySQL application transaction is also refused.
A same-named `CHECK(1=1)` previously passed staged planning and allowed multiple
subjects; replay now reinstalls only its frozen owned checks after complete
preflight, with journaled drop/add recovery and preservation of unrelated checks.
The dedicated MySQL 8 NOT ENFORCED branch remains unexecuted here.

Bulk DBAL schema inspection replaces per-table identifier-width introspection.
The full 358-table snapshots match on both providers; measured inspection falls
from 2,867 queries to five on PostgreSQL and 2,866 to five on MariaDB. Unchanged
raw-history contracts pass in 141 s and 197 s respectively, within the existing
300 s limit. Custom/plugin identifiers, comments and retry assertions remain.

Literal boolean predicates now compile to portable SQL/DQL truth expressions,
including nested AND/OR/NOT. The previously recorded Entity::testChangeEntityParent
failure is repaired. Field boolean bindings and raw-predicate rejection remain.
The shared item selector honors caller-declared types and current rights/scope,
uses scoped tokens and unique controls, escapes plain option labels, rejects stale
responses and binds one handler after real AJAX tab remounts. Location, Network
and Domain contexts are selectable through actual appliance forms.

Application validation at this source uses an identical isolated worktree;
SHA256 comparison covers 2,259 tracked application/test files. Rebuilt assets and
explicit fixture configuration exercise all **11 browser tests on each provider,
with zero skips**. Selected PostgreSQL application coverage passes **125/125
methods, 5,933 assertions**, and MariaDB **171/171 methods, 9,994 assertions**,
with zero skipped or void methods. These sets retain all previous matched classes
and add Entity plus the three Appliance classes. The appliance browser case
exercises wide owning/reverse identities, nested add/delete, read-only pages,
stale responses, escaped labels and one POST after tab remount.

Fresh integrated installs and full portability runs pass **143/143 discovered
contracts on PostgreSQL 15.19 and MariaDB 10.11.18**, using PHP 8.2.33. This includes
the complete frozen baseline/seed/history replay, populated adoption, invalid-data
diagnostics, interrupted nontransactional DDL and retry/idempotency evidence.
The runner's contract assertions and 300 s per-contract limit remain unchanged.
Final read-only schema checks pass on both providers after their full suites;
all four owned browser/application database schema checks also pass.

Separate broader legacy runs remain failing: PostgreSQL 23/28 methods and MariaDB
24/28. Three Certificate fixtures reference nonexistent users; a Consumable
fixture reuses a purged group. PostgreSQL Dropdown::testGetDropdownValue also
exposes a real numeric-id ILIKE defect. Overlaying the prior dropdown source
preserves those failures. No assertion or foreign key was relaxed. They must not
be hidden by the passing selected application sets.

Evidence lives outside the repository in `/workspace/itsm-env/evidence`:
`appliance-integrated-suite-*.log`, `appliance-integrated-install-*.log`,
`appliance-integrated-application-validation.md`,
`appliance-application-commands.txt`, `appliance-browser-suite-*.log`,
`appliance-legacy-expanded-*.log`, `appliance-check-bulk-history-*.log` and
`item-selection-legacy[-baseline]-*.log`. Optional installer requirements and
the standalone lifecycle schema check's tester-plugin warning are preserved in
the logs, rather than treated as successful optional checks.

Recomputed static inventory: 357 mapped tables, 1,049 enforced references,
26 discriminated identities, 38 polymorphic candidates and one pending historical
event identity. There are still 2,892 adapter query sites and 24 native transport
sites; this batch does not claim all application SQL has moved to ORM.

Post-suite certificate column inspection on both providers again finds nullable
signed BIGINT, NULL default, the historical relationship comment and the native
nine-subject generated expression. No columns or index definitions differ; the
raw table comparator reports only an equivalent provider-named index rename,
which the existing schema checker already treats as harmless. Exact expected,
introspected and native projection data is retained in
`appliance-certificate-column-{pg,mysql}.json`. Schema comparison still explicitly
excludes expression/trigger/CHECK equivalence; native relationship contracts
remain necessary.

Next work is already isolated and parallel: unify real CLI/web upgrade entrypoints
and pending-history readiness around the existing History coordinator; replace
mapped dropdown query switches with typed repositories and authorized context
tokens at every actual caller; then design the Domains import before Racks.
Racks has destructive retries and MyISAM sources; Domains has entity-crossing
name matches. Source inspection of those plugins is not live upgrade validation.
Release-engine CI, PostgreSQL 14/18, MariaDB 11.8, MySQL 8.4 and live replica
validation remain unavailable or unexecuted. The modernization goal remains open.
## Unified upgrade and readiness batch (2026-10-02)

The existing canonical History/ledger now owns supported CLI, facade and web
upgrades through `Database\Upgrade`. This replaces old release-selected MySQL
script execution, plugin deactivation and permission/OIDC mutations. Supported
adoption is structural: all 355 frozen baseline tables and historical/current
column intersections, followed by full validated history; new migration columns
are not prerequisites. Earlier releases require their matching historical
application to reach the ITSM-NG 2.1.3 schema. Frozen migration definitions and
seed contents remain unchanged; their `legacy_2_2` identifier is not a verified
release-support matrix.

Pending ledger history blocks ordinary writes even when release strings already
match. CLI updater/check diagnostics remain available. Only an authenticated
session with Config UPDATE rights can obtain the web-upgrade capability, and POSTs
require CSRF. Anonymous/read-only requests receive CLI recovery, without apply
forms. Configuration bootstrap uses the supplied DBAL connection before current
Profile hydration; recovery headers avoid current User/Entity ORM queries.
Release publication retains Config lifecycle/audit hooks, verifies acceptance and
shares History's existing advisory lock. Missing or invalid key paths never cause
regeneration; supported adoption requires the inherited original glpicrypt.key.
Read-route preview remains read-only; apply requires the supplied write adapter.
MySQL configuration/audit publication requires InnoDB, without implicit conversion.

Isolated PostgreSQL 15.19 and MariaDB 10.11.18 evidence, PHP 8.2.33:

- Fresh installations completed on own disposable `itsm_port_upgrade_entrypoints`
  databases. These are separate from the previous checkpoint's databases.
- New actual CLI/HTTP `upgrade-entrypoints.php` passes on both providers: every
  alias, preview, current-release pending history, customized rights, passwords,
  active plugins/OIDC/audit preservation, sequential idempotent retry, original-key
  retention and lost-key/alias/directory cases, authenticated apply, anonymous and
  read-only denial, forged CSRF, malformed receipt recovery, interrupted installation,
  newer release refusal, unsupported Profile and historical configuration shapes,
  real Config lifecycle veto rollback and MySQL nontransactional publication refusal.
- Strengthened `migration-history.php` passes both providers under the unchanged
  300-second budget, including actual db:update against populated frozen schema
  before later columns exist. All prior invalid-data, nontransactional interruption,
  seed rollback, legacy-ID, sentinel, nullability, boolean, relationship/projection,
  audit/account, sequence and retry assertions remain. MariaDB finished in 242.2s;
  an exact PostgreSQL duration was not recorded.
- Unmodified Update and GLPIKey application classes pass on each provider:
  **2 classes, 5/5 methods, 52 assertions, 0 void and 0 skipped**. Checked-in bootstrap
  loaded dataset 4.7; success summaries were inspected, not merely process exits.
- Read-only schema-check contracts pass both providers after upgrade fixtures.
  PHP lint, scoped repository formatting and whitespace checks pass.

Logs are `/workspace/itsm-env/evidence/upgrade-entrypoints-{contract,history,application}-{pg,mysql}.log`
and `upgrade-entrypoints-install-{pg,mysql}.log`. These are local real CLI/HTTP
and database results; browser, remote CI and live replica behavior were not validated
by this batch. Parent integration must run the discovered full suites on both
providers and inspect final schemas. The broader persistent objective remains open:
continue remaining domain persistence/relationship conversions and validate supported
historical release matrices and cross-engine data transfer separately.

Existing `project-assets-schema.php` and `appliance-assets-schema.php` also pass on
both engines after this coordinator change; each provider's final schema-check
contract passes. Subsequent contract runs expose the checked-in `tester` plugin
fixture through an external auto-prepend definition because the normal atoum
bootstrap installs its database record. The initial post-dataset runs passed but
reported that fixture's missing source path; this is an environment fixture
configuration issue, not suppressed application diagnostics. Exact focused logs
are `upgrade-entrypoints-{project-assets-schema,appliance-assets-schema,schema-check}-{pg,mysql}.log`.

## Isolated OS ownership batch (2026-10-02)

Worktree `th/exp/postgres-os-ownership` starts at integrated upgrade checkpoint
`6bd9f9133151a474a6b695d5d98bfbaf650f448b`; vendor/assets and the ignored tester
fixture are copied, not shared. Own disposable databases are
`itsm_port_os_ownership` on both providers, with own config/key/variable directories
under `/workspace/itsm-env`. The complete existing eight-stage installations were
copied from the previous owned upgrade fixtures and actual `db:migrate --apply`
replayed the ninth OS stage, including final schema validation. These focused
adoptions do not replace a fresh-install/full-history checkpoint.

Eight focused contracts pass per engine: `operating-system-subjects`,
`operating-system-subjects-schema`, `operating-system-purge`, `inventory-metadata`,
`reporting`, `statistics`, `statistics-options`, `schema-check`. PostgreSQL's schema reconstruction also
interrupts the real DROP-items_id statement; MySQL replaces that projection in one
ALTER and has no separate missing-column statement boundary. Schema comparison
remains intact: the custom historical-comment fixture supplies that expected comment,
and cleanup then verifies the unmodified complete canonical schema. Invalid parent
fixtures in existing reports/metadata tests now create actual matching parents;
all original behavioral assertions remain.

Actual Item_OperatingSystem and Computer application checks pass **2 classes,
11/11 methods, 565 assertions, 0 void/0 skipped** on each provider; bootstrap
reports dataset 4.7 already loaded. The intentional new public duplicate behavior
returns false with a domain message and preserves existing license values. Native
constraints are independently tested. Existing literal translated root-entity lookup
was replaced by ID zero; scope assertions remain. Permission tests exposed and
fixed a local ownership defect: ignoring OS metadata rights previously let READ
on an asset authorize assignment writes. Owning asset UPDATE is now required.

The three new contracts cover all six property-declared subjects, wide overlapping
IDs, direct/native/public insert/update/delete, generated projections, invalid and
duplicate rejection, nullable component updates, core dropdown label import before
dynamic assignment creation, parent scope/recursion, authorized/denied retargets,
explicit and parent cloning, six subject purge paths, licensed component replacement
and preflight refusal without assignment/history loss. The independently reviewed
atomic-delete commit `fc70eeca520203c4395df0287dbe40bda1a2dfb9` was integrated only
after the frozen history runs. The final purge contract verifies a real plugin veto
after the first child update and a separately committed competing assignment.
PostgreSQL detects that race through the domain guard; MariaDB REPEATABLE READ
reaches the native uniqueness constraint. Both roll back prior child cleanup and
preserve the source, assignments, licenses and exact audit snapshot while retaining
the competing committed row. Removing the conflict permits the authorized retry.
This batch uses the shared atomic lifecycle rather than duplicating it.

Local exact evidence is `/workspace/itsm-env/evidence/os-ownership-*.log`, with the
static inventory in `os-ownership-coverage.json`. Current isolated source inventory:
357 mapped tables, 1,055 enforced references, 27 discriminated identities, 37
polymorphic candidates and one pending candidate; token categories report 2,863
legacy-adapter call sites and 24 native-driver sites (2,887 combined). These are
static checkpoints, not proof of completed ORM/domain conversion or live FK coverage.
PHP lint for 23 changed files, sequential repository formatting and whitespace
checks pass. No historical baseline/target snapshots changed.

The strengthened full `migration-history.php` source adds actual legacy OS/license
payload and invalid/zero/orphan preflight before any identifier DDL; its final
fresh-install interruption moves to the newly appended OS stage and retains/extends
previous completion assertions. Sequential real runs pass under the unchanged
300-second budget: PostgreSQL **125.1 seconds**, MariaDB **253.4 seconds**. They cover
populated frozen-history adoption through actual `db:update`, complete fresh replay,
seed and latest-stage interruption/retry, identifiers, sentinels, nullability,
booleans, projections, licenses, audit/account data and sequence synchronization.
History/schema source remained frozen during both runs; the shared atomic-delete
dependency was integrated afterward and the final eight focused contracts and
11 application methods were rerun on that resulting source. Timing evidence is
`os-ownership-history-{pg,mysql}-timing.txt` alongside the history logs.

Next concrete step: integrate the OS feature with the current shared dependencies,
discover and run the complete suites on both providers, and inspect final schemas
before publishing a combined checkpoint. Browser, remote CI, live replicas and
external inventory agents are not passing claims for this batch. The broader
modernization objective remains open.


## Identifier sequence storage and allocation checkpoint (2026-10-02)

This isolated batch starts at combined OS/atomic-delete commit `7071960385` in
`th/exp/postgres-sequence-width`. Completed adoption receipts concealed a real
PostgreSQL defect: an already-BIGINT column could retain an integer SERIAL or
IDENTITY generator. A controlled probe loaded the exact pre-fix helper from that
commit and returned zero operations; the new independent ownership planner
returned one for the same BIGINT/narrow fixture. A controlled run of the new
contract against the unchanged synchronizer then failed its descending reserved-ID
assertion. Neither failure was accepted as passing evidence.

Appended `20261007_identifier_sequence_widths` uses the existing canonical History
and transactional ledger. Frozen identifier scope plus actual same-schema FK
edges identifies owned SERIAL/SMALLSERIAL/IDENTITY generators; quoted catalog
components prevent treating periods or quotes as qualification. Native bigint
widening preserves custom bounds, start, cache, increment, cycle and called/unused
state, and expands old type-default bounds. Unowned, unrelated and out-of-schema
generators are excluded. Older incomplete adoption journals retain their captured
prefix and next index, append only omitted repairs, and expose the same pending
append in read-only preview. Strict convergence remains enforced.

SequenceSynchronizer now compares native signed next candidates with directional
MIN/MAX using numeric SQL. It preserves safe reserved values and handles unused
imported equality and signed BIGINT boundaries without PHP overflow. Advancement
uses the imported extremum and native increment; residue can change. Custom bounds
are enforced with a native invalid-data diagnostic. CYCLE retains wrapping, and
writers/other backends holding cached allocations must be drained for maintenance.
No ordinary synchronization DDL was added.

Actual validation, sequential providers with isolated `itsm_port_identifier_sequences`
databases cloned from owned populated OS checkpoints:

- Owned vendor copy, rebuilt optimized autoload and Reflection checks proved all
  exercised migration/synchronizer classes loaded from this worktree.
- Real `db:migrate --apply --no-interaction` completed the appended version on
  both providers before ordinary application bootstrap.
- `identifier-sequences.php`: PostgreSQL **74 assertions**, MariaDB **12**. The
  PostgreSQL contract exercises real History failure/retry after DDL, completed
  old adoption, old incomplete journal append/rollback/resume, quoted namespaces,
  actual FKs, wide ORM allocation, native/custom bounds, allocation parameters,
  descending/stepped/unused/boundary values and excluded generators. MariaDB
  preserves native AUTO_INCREMENT and records an idempotent no-op receipt.
- Unchanged `legacy-to-orm.php` passed both, retaining populated widening,
  nontransactional DDL replay, generated declarations, custom/plugin FKs, quoted
  ascending +1 sequence imports and 64-bit ORM audit assertions.
- Final `schema-check.php` passed both; focused cleanup also independently checks
  the full width planner for remaining owned-generator repairs.
- PHP syntax, whitespace and explicit six-file formatter checks passed; formatter
  changed zero files. Native widening/synchronization received independent source
  review, separate from live database evidence.

After the causal old-source failure, two development runs exposed the fixture's
combined type/increment/default-bound declaration: PostgreSQL retained the prior
ascending maximum. Establishing descending native defaults in a second statement
fixed the fixture without weakening its state assertion. The 72-assertion run then
passed; the final strengthened run passed 74 including a custom-bound refusal.
Commands, source Reflection, pre-fix probes and final logs are under
`/workspace/itsm-env/evidence/identifier-sequences-*`; the durable command/evidence
record is `identifier-sequences-validation.md` there.

Next concrete step: integrate this feature after the Domain history changes,
preserving their native-name auditor optimization, then discover and run the
complete contracts on both providers from fresh installs and populated replay,
with final schema/sequence inspection. This batch does not claim a fresh complete
replay, full integrated suite, new browser coverage, remote CI or live replicas.
The broader relationship and domain-persistence modernization remains open.
