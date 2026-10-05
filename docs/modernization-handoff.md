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

Documentation cleanup starts at `6dfd14c73903791ac5c54e258aa0a71fe5c1459b`, containing
the independently source-reviewed architecture successor, actor schema ownership
and read-only Ticket status preflight. Source declares 357 mapped tables and 22
canonical versions, with an intended 1,087-reference schema. These are source
expectations, not measured native results. This candidate has no own completed
provider/full/application/browser acceptance. Original-head parser checks and
earlier native checkpoints do not validate it.

Published `53cf6297473bc31400a0777ff905bd79f1e65698` has actual independent fresh-install
and populated-upgrade proofs on PostgreSQL and MariaDB: both converged to 20 complete
versions and 1,080 foreign keys, without pending history or schema differences.
Populated proofs retain typed data, physical links, wide IDs, original keys and
idempotent replay. They do not replace interrupted-DDL retry or full-order acceptance.
Its latest full validation remains unfinished; remote CI is not green. Partial
running counts must not become a passing milestone.

The original handoff failure `Changed column: glpi_certificates_items.items_id`
remains unreproduced, not diagnosed away. Passing fresh checks and later full-order
observations do not prove its cause. Do not weaken comparison or original contracts.

Source declarations now own historical native timestamp fields, actor/tree indexes
and generated keys, Calendar closure policy, VLAN intent and recipient-owned browser
inbox behavior. Migration definitions remain frozen; energy subjects append history.
These changes require execution on their combined source. Legacy queries, unresolved
polymorphic relationships, plugin paths and stable Ticket status adoption remain.
Read-only status preflight covers bounded owners and is not adoption readiness.

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
