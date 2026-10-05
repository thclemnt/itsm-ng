# PostgreSQL and Doctrine modernization: implementation plan

The goal is **OPEN**. Native persistent goals are unavailable in this environment;
this maintained plan and Git history retain the objective:

> Cover all table relationships with foreign keys; use ORM properly for application
> SQL requests; eliminate direct calls to DBmysql and similar classes that directly
> call mysqli.

Complete PostgreSQL support while retaining MySQL/MariaDB, keep immutable DBAL
baseline/seeds and full replay with validated populated adoption, and improve domain
ownership rather than expand compatibility workarounds.

## Current source and evidence

The executed application checkpoint is `ecaa1c11908daccd3abebe09d73626452931dc3b`.
Each result applies to its executed source; subsequent changes do not inherit it:

- Ordinary units at `ecaa1c1190`: PostgreSQL and MariaDB each passed 30 classes,
  216 methods and 8,821 assertions, with no skipped methods.
- Isolated units at that checkpoint passed 16 classes, 60 methods and 3,006
  assertions, with no skipped methods. Five purely unit-level CLI contracts were
  transferred to the ordinary framework; native integration controls remain.
- Fresh complete installation and read-only schema inspections at `0b21850674`
  found 358 tables (including the
  ledger), 1,087 enforced foreign keys, no pending history and no schema differences
  on PostgreSQL 15.19 and MariaDB 10.11.18.
- Separate clean public installations at `08b92212c5` completed on both engines,
  with 358 tables, 1,087 enforced foreign keys, no pending history and no schema
  differences. The existing schema-check contract also passed on each. These
  targets have not loaded the ordinary unit dataset. The supplied historical
  certificate-column failure remains unreproduced; its cause is unresolved.
- The full native suites at `ecaa1c1190` discover 230 contracts. MariaDB completed
  with 32 passing contracts: a component reconstruction exceeded the unchanged
  300-second limit and left history pending, so subsequent application contracts
  stopped at the admission guard. This is a failed run, not evidence that every
  downstream subsystem is defective. PostgreSQL completed with 212 passing
  contracts and 18 failures. Its ordered schema-check passed, and subsequent
  read-only inspection found 358 tables, 1,087 foreign keys, no pending release
  and no schema differences. Both providers
  also exposed independent timestamp, permission and offline-metadata failures.
  Source repairs require native reruns; neither full suite is green.
- Isolated units at `ac2976e68c` passed 18 classes, 63 methods and 30,052 assertions
  with no skips, including the transferred actor and timestamp metadata controls.
  This is pure metadata/framework evidence, not native integration evidence.
- Focused application tests exposed actual numbering-binding defects and a queue
  fixture that assumed public creation could retain a nonzero retry counter.
  After correcting that fixture, public acknowledgement passed on both providers.
  Atoum accepts a whole-method `*`, not a prefix wildcard; the initial filter
  skipped five new timeline methods. Explicit reruns covered them and exposed
  an incorrect privacy expectation: Ticket, Change and Problem tasks all declare
  `is_private`. The source correction checks exact visible/hidden identities;
  its successful native rerun is the focused candidate below. The preceding
  failed runs are retained.
- The reviewed corrective candidate `e3f96eda1d18519744f041716ea1c34e1d0afc7a`
  passed 9 application test classes, all 44 selected methods and 9,788 assertions
  on each provider, with no skips. Selection names every new timeline method
  explicitly. Coverage includes the complete DbUtils class, timeline visibility,
  documents/validations/DST/overrides, original rendering, recipient acknowledgement,
  cron selection/status, Calendar/asset relation permissions, new-item plugin
  vetoes and default/forced ITIL purge lifecycle controls. PHP syntax checks passed.
  This focused result does not establish passing full native/application suites.
  Two pure timestamp CLI scripts are now ordinary isolated tests: current source
  discovers 228 integration contracts, not the 230 executed by the first full run.

Native reconstruction contracts must use clean installation databases, separately
from ordinary unit datasets. The latter activate the tester plugin through their
own bootstrap plugin paths. A focused native run on that dataset instead used the
ordinary application plugin path, emitted missing-plugin warnings before login
headers, and failed strict Calendar/HTTP entrypoint controls. Preserve those
failures, rerun on clean targets, and do not suppress warnings or weaken login
assertions. The MariaDB missing-subject preflight also exceeded its unchanged
300-second budget and requires performance attribution independently of this
setup problem.
- Component functional suites at `60c0d8a809` passed all six methods and 2,956
  assertions on each provider. The original POST fixture now declares the actual
  parent entity; production ownership guards and original assertions are intact.
  The duplicate standalone component contract was subsequently removed.

Earlier executions, retained for their own source only:

- Fresh installation and final schema checks passed on PostgreSQL at `38d0b5949f`
  and MariaDB at `9cdfdf5413`; these precede the current application checkpoint.
- Isolated units at `bb18deffe9`: 8 classes, 34 methods, 1,989 assertions passed.
- Ordinary MariaDB units at `95d983a1f6`: 30 classes, 209 methods, 8,597 assertions passed.
- Focused statistics, Contract, Transfer and software functional coverage at
  `9cdfdf5413`: 26 methods passed, with 1,737 PostgreSQL and 1,741 MariaDB assertions.
- Dictionary functional coverage at `df5c1df33e`: 5 methods, 2,892 assertions passed
  per provider.

These outcomes do not establish a passing full application suite. Dynamically
discovered provider contracts, browser acceptance and public remote CI have no
completed passing result on the current combined candidate.

The genuine upstream 2.1.3 release (`5ecdf8e2a29`) and ORM checkpoint `297787177b`
were compared through authenticated local HTTP actions on matched MariaDB data:
107 tickets, 300 followups, 20 computers and 20 software records. Six warm alternating
rounds included each page's required table JSON. Median ticket list/search/detail/
history times were 785/779/317/487 ms for ORM versus 120/122/63/36 ms for 2.1.3.
Tracing identified full metadata discovery (357 entities and tens of thousands of
attribute conversions) repeated in each request. With the registry cache at
`593d34e90f`, the same medians became 587/566/234/317 ms; baseline controls were
slightly faster too. SQL statement counts stayed identical across all nine read
flows. Separate cold/warm traces confirm metadata discovery disappears on a cache
hit, while source hashing and deserialization still have a cost. Search CPU fell
from 657 to 454 ms and its peak memory from 24 to 14 MiB. Substantial slowdown
remains. Subsequent controlled pairs optimized DQL parsing, autoload lookup,
normal Twig compiled-template caching and user-label scalar projections. The
dataset stayed unchanged within each pair. On the latest
121-ticket/100-match/970-history fixture, adding only the user-label projection
(`64a7657534` to `a2b17786a6`) reduced median list/search requests from
410.32/419.27 ms to 272.63/265.94 ms. SQL counts and peak memory remained identical
across nine read flows. Other action changes were variable and are not attributed
to this optimization. This serial local comparison excludes browser rendering,
production concurrency and PostgreSQL throughput; shipped dependency versions
differ between releases.

Broader profiles found Ticket/Change/Problem tab counts constructing timelines
whose content is immediately discarded. The first controlled candidate added
three SQL statements and still rendered the full timeline: unsupported document
subqueries triggered a compatibility fallback. Detail/history medians changed
from 162.32/241.44 to 180.01/245.27 ms. Parity tests alone concealed this failed
optimization. The repair reuses the mapped document repository's existing access
selector and removes fallback for core query errors; custom overrides fall back
before issuing count queries. At `ca81467c1f`, all seven explicitly selected
ordinary timeline methods passed on each engine, with 691 assertions and no skips.
They exercise document DQL directly, visibility, duplicate/null/local DST keys,
validation events and custom selectors. The initial anonymous-selector fixture
failure and failed timing pair are retained. The isolated repaired candidate
`276d35e5bd` against unchanged `a2b17786a6` reduced detail/history medians to
144.92/217.74 ms, CPU to 89.47/130.56 ms and SQL counts from 93/104 to 66/77.
Detail peak memory fell from 14 to 12 MiB; history stayed at 12 MiB. All read/login
body, JSON and tab-count checks passed; 121 tickets, 100 matches and 970 history
rows stayed unchanged. Separate profiles confirmed no full timeline-render calls
in either count path. Genuine-release controls were slower in the repaired phase
(detail/history 60.15/34.51 to 66.59/39.71 ms); this local serial comparison is
bounded evidence, not a production throughput claim. The complete discovered
228-contract suites at `ca81467c1f` finished with PostgreSQL 226/228 and MariaDB
36/228 passing. PostgreSQL failed `migration-history.php` at its unchanged
300-second limit and `relation-endpoint-rights.php` on anonymous-email attachment
admission. MariaDB's component reconstruction exceeded the same limit after the
motherboard family completed at 276 seconds. The interrupted memory family left
the 2.2.0 history pending; subsequent application admission failures share that
cause and are not independent subsystem diagnoses. Its final read-only inspection
found 358 tables, 1,082 foreign keys and memory-subject schema differences.
The failed database, original assertions and execution evidence are retained.
Neither full suite is green. Migration cost attribution and the attachment
boundary repair must precede another complete run on clean disposable databases.

The wider profiling review also confirms full Software-row hydration in the
software tab: four choice calls hydrate 60 rows, with 60.85 ms inclusive profiled
cost. Configuration reads hydrate full Entity records for a few settings and
parent references. A separate successful Software AJAX profile returned the
20 expected software rows. Choice processing used 46.78 ms inclusive under
Xdebug: entity hydration was 9.16 ms, while initial query setup was 35.05 ms;
bootstrap dominated the full request. Projection alone cannot remove that setup
cost. An extra software-version form probe lacked the expected body marker and
is unverified. Non-tree Computer/Contact/Supplier/
Budget/Netpoint labels, Group actor links, Link outputs and notification name
lookups remain source-level candidates. Full reads used for permissions or hooks
are not automatically projection candidates. Profiled inclusive durations are
not request latency.

Broader source candidates at `0c348b7d68` project only requested inherited Entity
settings, their metadata-declared mode columns and the parent identifier. They
share the existing scalar serialization contract rather than introduce a second
type registry. A second change lazily loads the Ticket status catalogue once per
search formatting pass, after plugin hooks, preserving subclass dispatch and
observing writes on the next pass. Existing ordinary Entity and Search test
classes cover the new cases; native tests and isolated paired timings for these
candidates were run against both engines. Initial failures came from new fixtures
writing legacy sentinels without canonical reference modes and creating tickets
without explicit visible entity/requester ownership. Corrected fixtures retain
the original constraints, sentinel expectations and exact two-row search result.
At `16b2cd5134`, the ordinary unit suite passed 30 classes, 216 methods and 8,821
assertions on each provider, with no skips. At `0935573b5f`, the focused application
suite passed all 46 methods across five classes and 9,374 assertions on MariaDB.
PostgreSQL passed the new Entity, Search, Ticket and Notification cases but failed
the existing multi-selection ancestor-order assertion in DbUtils. The reviewed
repair restores caller-selected branch order after an unordered IN query and adds
reversed/duplicate/string-ID controls; native validation is pending. The original
relation-endpoint contract passed on clean PostgreSQL C at `0935573b5f`, including
anonymous NULL/zero email links and rejection of dangling nonzero attachments.
Native Entity/Status paired timings are underway independently of those repairs.
These changes do not resolve stable status identity or claim a Software dropdown
performance gain.

Read-only migration attribution at `ca81467c1f` measured PostgreSQL plan/verify/
schema at 1.16/7.46/0.99 seconds and MariaDB at 10.87/31.34/4.35 seconds. MariaDB
verification issued 7,494 SELECTs; repeated declaration inspection dominated its
profile, including 14.48 seconds of nullable-reference table inspection. The
reviewed changes share a fresh local DBAL Schema only within reference verification
and omit unused DDL/preservation planning during exact-policy verification. They
retain live data audits, native FK/CHECK/collation checks and authoritative policy
comparison; apply/retry paths still inspect afresh after DDL. At `57b49e3577`,
verification took 4.71 seconds on PostgreSQL and 19.33 on MariaDB, the latter with
4,806 SELECTs. Both runs preserved the ledger in read-only transactions, with
zero MariaDB DDL/DML. This focused comparison does not prove the 300-second
interruption contracts pass. PostgreSQL C's post-full inspection at `ca81467c1f`
found 358 tables, 1,087 FKs, no pending history and no schema differences.

A separate genuine 2.1.3 populated clone was verified against all 355 original
table row bags and its original encryption key before adoption. Public `db:update`
at `0b21850674` completed to 2.2.0 with 358 tables, 1,087 foreign keys, no pending
history and no schema differences. Selected original ticket/followup fields,
signed-maximum legacy identifiers, distinct certificate links, nullable stock
payload and allocator metadata were checked. Per-table counts are not exhaustive
field-by-field preservation proof. The Domain phase adds exactly one validated
domaintype right per profile; the original profile-right row bag is unchanged.
The genuine release's three orphan marketplace defaults are archived losslessly
in the existing ledger before removal, with strict whole-row and ownership
admission. A second public update at `ca04024d0a` left all 358 native row bags,
table definitions, triggers, allocator positions, release keys and the key hash
unchanged. This positive adoption/retry evidence does not replace adversarial
interruption and invalid-data contracts, whose full run remains pending.

Earlier published `53cf6297473bc31400a0777ff905bd79f1e65698` has independent fresh
installation and populated ORM-checkpoint upgrade results on both providers.
Those historical results remain evidence for that source only; they do not prove
an upgrade from the genuine upstream 2.1.3 release.

The release migration redesign exposes one
2.1.3-to-2.2.0 transition, with frozen conversion helpers under `Migration/V220`.
Fresh installation replays the frozen DBAL baseline and seeds through that same
transition. Internal phase keys support recovery of experimental installations;
they are not public release versions. The 2.2.0 completion receipt requires full
schema and sequence convergence. Fresh replay and genuine populated adoption have
the bounded native evidence above; the full interrupted/corrupted-history suite
has not passed. Historical install/update scripts, original-key
checks and provenance gates remain intact. Subsequent ORM schema changes must
append migrations after this transition. The old count of 22 internal versions
is not the release architecture or an acceptance criterion for this redesign.

The original handoff failure `Changed column: glpi_certificates_items.items_id`
remains unreproduced, not diagnosed away. Passing fresh checks and later full-order
observations do not prove its cause. Do not weaken comparison or original contracts.

Source declarations own historical native timestamp fields, actor/tree indexes
and generated keys, Calendar closure policy, VLAN intent and recipient-owned browser
inbox behavior. Legacy queries, unresolved polymorphic relationships, plugin paths
and stable Ticket status adoption remain. Forced status purge still shifts ordinal
interpretation, and reordering is not atomic. Current schema inspection still
skips existing ordinary mapped columns and excludes general subject CHECK/generated
definitions. Frozen release postconditions are being hardened separately; these
runtime inspection gaps remain open. Read-only status preflight covers bounded
owners and is not adoption readiness. Source-only cleanup and release migration
changes require validation on their combined source before inheriting any result.

## Next validated batches

1. Review the combined application state, fix demonstrated architecture, correctness
   and performance defects, and move pure portability checks to the existing unit
   framework while retaining meaningful provider integration gates.
2. Prepare isolated dependencies/configurations and disposable PostgreSQL and
   MySQL/MariaDB databases. Run syntax/units, fresh complete replay and populated
   supported upgrade with original key/data, invalid-data diagnostics, sequences,
   interruption/retry and idempotency.
3. Run dynamically discovered full portability suites and relevant original
   application suites on both providers. Inspect final native schemas and exercise
   public authorization, lifecycle, concurrency, plugin and browser flows before
   publishing a milestone. Official-engine CI and replica/TLS checks remain open
   until their own results exist.
4. Continue coherent domain conversions: resolve relationships, replace traced
   legacy persistence with entities/repositories/services, and complete owner-aware
   stable Ticket status adoption including policies/rules/templates/searches/API
   compatibility and audit interpretation. Retain scope, routing, hooks,
   notifications, history, clone and purge behavior.

Keep reviewable Conventional Commits. Record exact validated source/provider/outcome
here when a coherent milestone completes; replace superseded status prose rather
than append batch documents. Historical notes, failures and source reviews remain
in Git history and immutable execution evidence. Production readiness, all-plugin
portability and complete ORM adoption are not claimed.

## Maintained documentation

- [ORM and ownership](orm.md): current architecture and remaining domain boundaries.
- [Database operations](postgresql.md): installation, adoption, recovery and validation.
- [Search architecture](search.md): planning, compatibility and measurement.

Batch/fixture/composition notes were consolidated here. Existing application manuals
and external project documentation remain intact.
