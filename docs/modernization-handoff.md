# PostgreSQL and Doctrine modernization: implementation plan

The goal is **OPEN**. Native persistent goals are unavailable here; this plan and
Git history retain the objective:

> Cover all table relationships with foreign keys; use ORM properly for application
> SQL requests; eliminate direct calls to DBmysql and similar classes that directly
> call mysqli.

Complete PostgreSQL support while retaining MySQL/MariaDB, replay an immutable DBAL
baseline and seeds through canonical history, validate populated adoption, and
improve domain ownership rather than expand compatibility workarounds.

## Current source and validation

The current runtime is `9567cdf8a9`. At `f55d492e14`, PendingSubjectPreflight
passed 50 assertions in 58.985 seconds on PostgreSQL and 157.295 seconds on
MariaDB. Private boolean-only probing isolated the remaining Software fixture
failure to tracking properties absent before/after refusal; graph, audit, queue
and persisted fields were unchanged. At `9567cdf8a9`, Software current reads passed
in 15.429 seconds on PostgreSQL and 17.638 seconds on MariaDB. The revised compound
checks exact pre-call lifecycle state including property presence; stored graph,
audit, queue, caller frame and retry assertions remain. The complete ordered suites at this head passed **228/228 on PostgreSQL 15.19
and MariaDB 10.11.18**. Both read-only final inspections found 358 tables/1,087
FKs, no pending/installing release and no schema differences. The history control
passed within the unchanged 300-second budget in both full execution orders.
The review branch at `5afd9769aa` differs only in documentation and test cleanup;
application source matches the frozen runtime. Two disconnected component metadata
CLIs were folded into the existing ItemDeviceProcessor Atoum class: seven methods,
1,702 assertions and no skips passed through the normal runtime autoload. Existing
behavior methods and every native contract remain; review discovery is 226
contracts, while the executed runtime listed 228 (a superset of the retained list).
The complete disconnected review unit group at `bf415b4986` passed 18 classes,
65 methods and 30,540 assertions with no skips through the normal runtime
autoload; exercised application bodies match the frozen runtime.
The runtime includes software lifecycle admission,
typed VLAN projections, reviewed positive fixture repairs, native-policy-first
verification and operation-local reference preflight inspection reuse. Disconnected metadata
inspection at `9b5b30d1aa` found 357 mapped tables, 1,087 owning join columns, 35
discriminated identity fields and 402 boolean fields. These counts do not establish
complete ORM/domain adoption. Historical `install/update_*.php` files are unchanged.
Native Git fetch works, and the checked remote branch remains
`01aee6a7bbd5a3f0301a6f27b329381a12ea8470`.

Results apply to the exact executed source, not subsequent changes:

- At `e75c9098fb`, five focused application classes passed all 46 methods and
  9,398 assertions on each provider, with no skips. Coverage includes inherited
  Entity settings, Search status presentation, DbUtils ancestor ordering,
  anonymous Ticket attachments and optional notification attachments.
- At `16b2cd5134`, the ordinary unit suite passed 30 classes, 216 methods and
  8,821 assertions on each provider, with no skips. Isolated metadata/framework
  tests at `ac2976e68c` passed 18 classes, 63 methods and 30,052 assertions.
- Public fresh installations at `4fba97caa2` passed on PostgreSQL 15.19 and an
  isolated MariaDB 10.11.18 server. Read-only inspection found 358 tables including
  the ledger, 1,087 enforced foreign keys, no pending release or installation
  marker, and no schema differences. Certificate projection storage is nullable
  BIGINT without a scalar default on both engines; native generated expressions
  were retained in execution evidence.
- Financial-reference, incoming-projection and discriminator/upgrade controls
  passed on both engines at that source. Component reconstruction passed in
  69.1 seconds on PostgreSQL and 167.6 seconds on isolated MariaDB, within the
  unchanged 300-second limit. Financial controls prove shared verification
  snapshots remain unchanged, audit current invalid data, and refresh after DDL.
- At `7a68639fb7`, four complete Software application classes passed 35 methods
  with no skips: PostgreSQL 542 assertions; MariaDB 658. At `702ac1a818`, the full
  NetworkPort class passed eight methods and 289 assertions on each provider,
  with no skips. These include the new four-owner Software admission and typed
  VLAN projection cases; older positive fixture repairs preserved assertions.
- At `702ac1a818`, financial-reference and both discriminator controls passed on
  both engines. Snapshot reuse retains live orphan audits, immutable inspection
  input and fresh inspection after DDL. The complete history control passed in
  **273.164 seconds on PostgreSQL and 250.015 seconds on MariaDB**, within the
  unchanged 300-second limit, retaining all 158 native assertion sites.
- Complete suites at `702ac1a818` finished PostgreSQL **226/228** and MariaDB
  **227/228**. Pending-subject diagnostics failed on both; PostgreSQL also failed
  the Software fixture. Both post-run inspections found 358 tables/1,087 FKs,
  no pending history or installation, and no schema differences. Focused repairs
  passed at the heads above; complete current results are recorded above.
- The isolated History class at `976df615ac` passed three methods and 46 assertions
  with no skips through the normal runtime autoload. Its eight new assertions
  check disconnected MySQL/PostgreSQL metadata and frozen DDL independence.

Earlier failed fixtures and reconstruction cascades remain in private evidence.

The supplied historical `Changed column: glpi_certificates_items.items_id` failure
remains **unreproduced, not diagnosed away**. Fresh checks at `4fba97caa2` and ordered schema-check contracts at `9b5b30d1aa`
passed on both engines; both complete suites and final inspections at
`9567cdf8a9` passed as recorded above.
Do not weaken the comparison or assume a stale fixture.
Source review at `897c5c9bd6` found both expected schema and migration specify
signed nullable BIGINT, no scalar default and the preserved relation comment.
Related MySQL-only commit `62d5feb74f` cannot explain the PostgreSQL report; the
SchemaCheck changed-column comparison is unchanged. Historical reproduction still
needs exact-source/locked-dependency expected and actual Column properties, native
DDL/comments/generated expression, executed projection SQL and database binding
before/after the infrastructure fixture. Current convergence is not that diagnosis.

## Controlled performance evidence

Normal, unprofiled authenticated serial local MariaDB comparisons used unchanged
fixtures of 121 Tickets, 100 search matches and 970 history rows, with
response/JSON/count checks.
They exclude browser rendering, concurrency and PostgreSQL throughput. Genuine
2.1.3 controls use shipped dependencies, which differ from the ORM branch.

- User-label scalar projection (`64a7657534` to `a2b17786a6`): list/search medians
  410.3/419.3 to 272.6/265.9 ms; SQL counts and heap unchanged.
- Timeline counts (`a2b17786a6` to `276d35e5bd`): detail/history medians
  162.3/241.4 to 144.9/217.7 ms; SQL 93/104 to 66/77.
  All seven direct timeline methods passed both providers at `ca81467c1f`.
- Entity configuration (`276d35e5bd` to `5c9560c315`): full-entity hydration was
  removed; profiled software-tab lookup fell 22.4 to 3.3 ms. Whole-page results
  were mixed and SQL counts unchanged. No general Entity speedup is claimed.
- Status catalogue (`5c9560c315` to `83df7b32bf`): list/search medians
  305.8/302.2 to 261.2/264.2 ms, with 118 fewer SELECTs per complete flow. Catalogue
  calls fell 61 to two in profiles. Other read flows and login SQL were unchanged.

Wider traces still show 40 user-label calls costing 76.6 ms under profiling. A
caller-owned batch must preserve anonymization, tooltip data, rights and plugin
ordering. Under profiling, Software AJAX hydration costs 9.2 ms for 20 rows, versus 35.1 ms query
setup and 141.7 ms bootstrap: projection alone cannot fix its dominant cost.
Outside Search, Entity unique-identifier rules hydrate up to two entire entities
to return one ID; the SoftwareVersion selector consumes three fields from fully
hydrated records; anonymous notification recipients need only an email from actor
links. These and Link output/non-tree labels are confirmed source-level candidates,
not measured gains. Existing tree labels already project their required fields. The retained Software AJAX trace
attributes most startup cost to configuration: status/registry work, timezone
setup and duplicate table discovery. Unrestricted adapter-cache reuse is unsafe
across reconnects/direct DDL; any optimization needs a bounded ownership window.
Other raw flag projections currently use truthiness and have no proven behavior
failure; preserve each consumer's integer-flag/date-string boundary. Retain full models for permissions,
lifecycle hooks and rich item links. The extra SoftwareVersion form probe lacked
its expected body marker and remains unverified.

Migration verification and read-only reference preflight reuse inspection only
within one operation; apply, DDL, callbacks and retries inspect afresh. Invalid
rows and native policies are still audited. At `57b49e3577`, verification took
4.71 seconds on PostgreSQL and 19.33 on MariaDB, with no writes and unchanged
ledgers. At `4fba97caa2`, the same 4,806 SELECTs took 17.34 seconds on the old
MariaDB server versus 8.97 on the isolated server. With compared settings equal,
incoming-catalogue lookup took 1.90 versus 0.012 seconds. This establishes a local
environment effect, not production latency. The per-target lookup candidate
remains held because it showed little gain and can regress multi-target plans.

## Supported release architecture and adoption

`Migration/History` exposes one supported 2.1.3-to-2.2.0 transition. Frozen baseline,
seeds and conversion phases live under `Migration/V220`; internal phase keys
support recovery and are not public release versions. Fresh installation replays
that history. Existing installations must first complete their matching historical
application's upgrade to genuine 2.1.3, then adopt it. Subsequent ORM releases append
migrations; current entity changes must never rewrite frozen replay.

A genuine upstream 2.1.3 clone (`5ecdf8e2a29`) was verified against all 355 original
row bags and its original encryption key before public adoption at `0b21850674`.
It converged to 358 tables/1,087 FKs with no pending history or schema differences.
Selected original account/audit fields, signed-maximum identifiers, distinct
certificate links, nullable stock and allocator metadata survived. The original
profile-right bag was unchanged; adoption adds one validated Domain right per
profile. Three original orphan marketplace defaults were archived losslessly
with strict whole-row and native side-effect admission, without invented parents.
Public retry at `ca04024d0a` left all 358 row bags, native definitions, triggers,
allocator positions, release keys and encryption-key hash unchanged. These bounded
results do not replace current invalid-data/interruption controls or exhaustive
field-preservation evidence.

## Remaining work and next validated batches

1. Preserve the current passing two-provider ordered results and final schema
   inspections. Reproduce the unresolved historical certificate mismatch at its
   exact handoff source with bound expected/actual native definitions. Keep the
   unchanged history budget and all adoption, invalid-data, retry, sequence and
   native-policy assertions. Consolidate suitable coverage into proper framework
   owners without hiding failures or sharing mutable fixtures.
2. Continue measured caller-owned projections/batching for remaining display-only
   hot paths, retaining full models where authorization or lifecycle needs them.
   Test actual values, scope, plugin/subclass behavior and fresh reads between
   passes, then perform isolated normal A/B comparisons.
3. Resolve stable Ticket status identity, ordinal purge/reorder and every mutable
   rule/template/search/API/audit owner before claiming safe status adoption.
4. Repair current schema authority separately from frozen history. Existing
   ordinary columns/indexes still inherit old definitions: CronTask length/default
   edits can be invisible, deleted properties leave old expected columns, and the
   frozen identifier pass can override current types. Start with a supplied-metadata
   projector and in-memory mutation/immutability tests; resolve property-local
   defaults/comments and provider storage before replacing existing columns.
   Close unresolved polymorphic/plugin relationships and legacy query/domain
   boundaries while retaining routing, callbacks, audit, cloning and purge.
5. Validate current genuine populated upgrades and retries, broader original
   application suites, browser flows, official-engine CI, TLS and live replicas.
   No passing browser, remote CI or live read-replica result is claimed.

Ordinary unit datasets activate their tester plugin through special bootstrap
paths. Native reconstruction/HTTP contracts must use separately installed clean
targets, not that dataset. Keep coherent Conventional Commits and update this
checkpoint with exact source/provider/outcomes. The full modernization goal and
production readiness remain open.

## Maintained documentation

- [ORM and ownership](orm.md)
- [Database operations](postgresql.md)
- [Search architecture](search.md)

Superseded batch narratives remain in Git history and private execution evidence;
new per-batch documentation is unnecessary.
