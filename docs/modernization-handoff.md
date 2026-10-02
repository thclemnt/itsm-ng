# PostgreSQL and Doctrine modernization goal

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

## Cloud setup checkpoint (2026-10-02)

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
