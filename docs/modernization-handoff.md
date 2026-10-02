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

Next: install the frozen pre-adoption baseline and frozen seed history through
the same ledger and run populated upgrades, interruption/retry and fresh replay.
