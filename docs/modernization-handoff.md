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

The application source checkpoint is `95d983a1f6b233893d64c8ca1cc5f1beee9b023d`.
The executor reported these results during the development checkpoints leading to
it. Each result applies to its executed source; subsequent fixture and test changes
have not been relabeled as fresh executions:

- Fresh installation and final schema checks passed on PostgreSQL at `38d0b5949f`
  and MariaDB at `9cdfdf5413`; these precede the current application checkpoint.
- Isolated units at `bb18deffe9`: 8 classes, 34 methods, 1,989 assertions passed.
- Ordinary MariaDB units at `95d983a1f6`: 30 classes, 209 methods, 8,597 assertions passed.
- Focused statistics, Contract, Transfer and software functional coverage at
  `9cdfdf5413`: 26 methods passed, with 1,737 PostgreSQL and 1,741 MariaDB assertions.
- Dictionary functional coverage at `df5c1df33e`: 5 methods, 2,892 assertions passed
  per provider.

Ordinary PostgreSQL units remain open: the recorded run has 35 failures, one error
and one exception. Provider formatting expectations and Migration::addRight's
explicit NULL identifier require correction and rerun. The identifier fix is
source-ready at `bf582478a8`; it has no native result yet. These outcomes do not
establish a passing full application suite. The latest 238 provider contracts,
browser acceptance, genuine 2.1.3 application performance comparison and public
remote CI have no completed passing result on the current combined candidate.

Earlier published `53cf6297473bc31400a0777ff905bd79f1e65698` has independent fresh
installation and populated ORM-checkpoint upgrade results on both providers.
Those historical results remain evidence for that source only; they do not prove
an upgrade from the genuine upstream 2.1.3 release.

The release migration redesign is source work pending execution. It exposes one
2.1.3-to-2.2.0 transition, with frozen conversion helpers under `Migration/V220`.
Fresh installation replays the frozen DBAL baseline and seeds through that same
transition. Internal phase keys support recovery of experimental installations;
they are not public release versions. The 2.2.0 completion receipt requires full
schema and sequence convergence. Historical install/update scripts, original-key
checks and provenance gates remain intact. Subsequent ORM schema changes must
append migrations after this transition. The old count of 22 internal versions
is not the release architecture or an acceptance criterion for this redesign.

The original handoff failure `Changed column: glpi_certificates_items.items_id`
remains unreproduced, not diagnosed away. Passing fresh checks and later full-order
observations do not prove its cause. Do not weaken comparison or original contracts.

Source declarations own historical native timestamp fields, actor/tree indexes
and generated keys, Calendar closure policy, VLAN intent and recipient-owned browser
inbox behavior. Legacy queries, unresolved polymorphic relationships, plugin paths
and stable Ticket status adoption remain. Read-only status preflight covers bounded
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
