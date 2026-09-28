# PostgreSQL port (experimental)

This branch is a development port, **not a complete or production-ready PostgreSQL release**. MySQL/MariaDB remain the default. PostgreSQL fresh installation, the shared database API, authentication, basic asset/ticket lifecycle and composite searches are covered by integration tests. The remaining work below is necessary before changing that status.

## Architecture

- `DBAdapter` contains the existing shared CRUD, metadata-cache and quoting API. `DBmysql` preserves the mysqli transport and public class name; `DBpgsql` provides PostgreSQL transport. Existing generated `class DB extends DBmysql` configurations keep working.
- Doctrine DBAL 3.10 is an explicit dependency, compatible with PHP 8.1. `getDoctrineConnection()` uses the **same native connection** as the legacy API. Session state, transactions and savepoints are shared. New repositories should use DBAL bound parameters and its query builder. A query builder does not make arbitrary vendor SQL portable; use platform expressions for differences.
- `BaselineSchema` reads the checked-in baseline into Doctrine's `Schema`/`Table` objects, so PostgreSQL does not maintain an independent SQL dump. It handles 355 distinct tables, native PostgreSQL boolean flags, generated identifiers, explicit scalar defaults, prefix/full-text indexes, comments and timestamp update triggers. The legacy baseline contains two definitions of `glpi_queuednotifications`; the final definition wins, matching the original installer.
- MySQL installation still executes the existing baseline to preserve its exact native column behavior. Both engines install the foreign-key registry after seeding. PostgreSQL installs and seeds in one transaction and then synchronizes sequences, including tables whose seeds use explicit IDs.
- `LegacySql` is a lexical bridge for the application's pre-escaped strings and backtick identifiers. It is not an SQL dialect translator. Prefer raw bound values with DBAL in new code. PostgreSQL rejects NUL text rather than silently truncating it.
- `Expressions` delegates date arithmetic to Doctrine platforms. Search has separate input, options, provider, projection, criteria, joins, sorting and output classes behind the existing `Search` facade. The SQL-rewriting `SearchProjection` bridge is removed. See [search architecture](search.md) for the two-phase planner and its compatibility boundaries.

Doctrine ORM now maps all columns of all 355 core tables. Core record-by-ID reads use ORM, and sixteen tables use ORM persistence below the existing `CommonDBTM` lifecycle; asset counts and reservation reports use DQL repositories. Entity managers are scoped to one operation and share the adapter connection and transaction. See [mapped persistence and reporting](orm.md) for the ownership boundaries. The legacy baseline still owns installation and indexes; do not run ORM schema synchronization against an installation.

## Fresh PostgreSQL installation

Install PHP's `pgsql` extension and Composer dependencies. PostgreSQL 14+ is the target; locally tested with PostgreSQL 18.6. The configured CI matrix targets 14, plus MySQL 8.4 and MariaDB 11.8; remote runs are still pending.

Provision an empty database owned by the application role. The application does not require cluster-level `CREATEDB` privileges. Then run:

```sh
php bin/console db:install --db-type=pgsql \
  --db-host=127.0.0.1 --db-port=5432 \
  --db-name=itsmng --db-user=itsmng --db-password
```

The password option without a value prompts securely. Existing MySQL installation commands retain `mysql` as the default. A PostgreSQL installation refuses a schema that already contains `glpi_*` tables, even with `--force`. Failed PostgreSQL installations roll back schema and data; configuration and the encryption key remain available for retry.

The web installer also offers PostgreSQL (experimental). Enter an existing empty database, host (optionally `host:port`), user and password. It uses the same transactional install safeguards and refuses PostgreSQL upgrades and reinstallation over existing tables.

`DBpgsql` supports host/port, bracketed IPv6 with port, Unix socket directories, UTF-8, named timezones and libpq TLS settings. Optional configuration properties include `dbschema` (default `public`), `dbsslmode`, `dbssl`, `dbsslca`, `dbsslcert`, `dbsslkey`. `dbssl=true` selects `verify-full`. Replica configurations inherit the active provider; live replication is not validated.

## PostgreSQL booleans

`BooleanColumns` explicitly maps 390 flags across 170 tables to native PostgreSQL `boolean` columns. NULL defaults stay NULL. Tinyint display width is not treated as type information: `do_count`, weekdays, timeline positions, orientation, counters and several preferences needing further classification remain integers. MySQL keeps its original column types.

The legacy adapter returns `0`/`1`/`null` for boolean results so existing forms, strict comparisons and packed search cells retain their contract. Search converts a boolean to an integer only where numeric comparison or display encoding requires it. No SQL text replacement converts arbitrary integer predicates into booleans. New code using Doctrine can bind `Types::BOOLEAN` directly.

This schema change applies to **fresh PostgreSQL installations**. Earlier experimental PostgreSQL databases with smallint flags are not automatically migrated. A future versioned migration must validate existing values, preserve NULL/defaults and change column types transactionally. Recreate disposable installations when testing this branch.

## Foreign keys

Fifty-two declared relationships are enforced on both providers:

| Child | Referenced columns |
| --- | --- |
| `glpi_groups_users` | `users_id`, `groups_id` |
| `glpi_profiles_users` | `users_id`, `profiles_id`, `entities_id` |
| `glpi_profilerights` | `profiles_id` |
| `glpi_tickets_users` | `tickets_id` |
| `glpi_groups_tickets` | `tickets_id`, `groups_id` |
| `glpi_suppliers_tickets` | `tickets_id` |
| `glpi_tickettasks` | `tickets_id` |
| `glpi_ticketsatisfactions` | `tickets_id` |
| `glpi_documents_items` | `documents_id` |
| `glpi_useremails` | `users_id` |
| `glpi_contracts_items`, `glpi_contractcosts` | `contracts_id` |
| `glpi_contracts_suppliers` | `contracts_id`, `suppliers_id` |
| `glpi_contacts_suppliers` | `contacts_id`, `suppliers_id` |
| `glpi_reservations` | `reservationitems_id` |
| `glpi_changes_groups` | `changes_id`, `groups_id` |
| `glpi_changes_users`, `glpi_changes_suppliers`, `glpi_changetasks`, `glpi_changecosts`, `glpi_changevalidations`, `glpi_changes_items` | `changes_id` |
| `glpi_groups_problems` | `problems_id`, `groups_id` |
| `glpi_problems_users`, `glpi_problems_suppliers`, `glpi_problemtasks`, `glpi_problemcosts`, `glpi_items_problems` | `problems_id` |
| `glpi_changes_problems` | `changes_id`, `problems_id` |
| `glpi_changes_tickets` | `changes_id`, `tickets_id` |
| `glpi_problems_tickets` | `problems_id`, `tickets_id` |
| `glpi_ticketcosts`, `glpi_ticketvalidations`, `glpi_items_tickets` | `tickets_id` |
| `glpi_calendars_holidays` | `calendars_id`, `holidays_id` |
| `glpi_calendarsegments` | `calendars_id` |
| `glpi_ruleactions`, `glpi_rulecriterias` | `rules_id` |
| `glpi_networkports_networkports` | `networkports_id_1`, `networkports_id_2` |

These are mandatory, non-polymorphic associations. Update/delete actions are `RESTRICT`: the application must run its cleanup/history hooks before deleting the parent. Direct SQL that would orphan children fails. Root entity `0` remains a real row. Anonymous ticket actors can still use `users_id=0` or `suppliers_id=0` with an alternative email address, so those columns deliberately have no FK.

For an existing installation, audit and review the DDL first:

```sh
php bin/console db:foreign_keys
php bin/console db:foreign_keys --apply
```

Any orphan count stops the upgrade before DDL. The command never deletes or repairs user data. Applying is idempotent, and PostgreSQL applies transactionally. MySQL DDL commits implicitly; if execution fails partway, correct the reported problem and rerun. An installation must be quiescent while adding constraints; concurrent writes may make an ALTER fail, but cannot bypass the final constraint validation. Do not disable foreign-key checking to import invalid data.

Coverage is deliberately incomplete. Most optional references still use `0` or `-1`, and `items_id` often refers to multiple tables. Extending coverage requires classifying each relationship, migrating optional references to nullable columns, updating queries and purge behavior, and checking existing data. Inferring hundreds of constraints just from `_id` names would corrupt these semantics. The registry is the explicit place to add audited relationships.

## Validation

Use fresh, disposable databases named `itsm_port_*` and separate configuration directories. The test scripts refuse other database names.

```sh
php tests/database-portability/run.php /path/to/test-config
php tests/database-portability/application.php /path/to/test-config
php tests/database-portability/orm.php /path/to/test-config
php tests/database-portability/orm-records.php /path/to/test-config
php tests/database-portability/reporting.php /path/to/test-config
php tests/database-portability/search.php /path/to/test-config
php tests/database-portability/search-columns.php /path/to/test-config
```

For the PostgreSQL HTTP smoke test, start a separate web server with an empty
`GLPI_CONFIG_DIR` and pre-create a separate empty `itsm_port_*` database. Then run
`python3 tests/database-portability/web.py http://127.0.0.1:8059 --host=127.0.0.1:5432 --database=itsm_port_web --user=itsmng`.
Supply its password through `PORT_TEST_DB_PASSWORD`; use `--log-dir` when the web
server has a custom log directory. This script installs that database and checks
SQL logs as well as HTTP responses. After installation, run
`python3 tests/database-portability/web-reports.py http://127.0.0.1:8059` for report routes.

The contract checks schema metadata, seed integrity, escaping/injection-shaped values, UTF-8, prepared binding by reference, numeric result types, duplicate result columns, pagination/rewind, limited updates, deletes, shared DBAL/legacy transactions, locks, date arithmetic, each FK independently, and orphaned-data upgrade refusal/retry. The application workflow exercises seeded administrator login, asset creation/update/reload, ticket rules/calendar processing, case-insensitive ticket search, numeric/date filters, the unfiltered group list, anonymous user/supplier actors, valid membership and purge hooks under FK enforcement. Workflow writes roll back; login updates normal login metadata.

Local evidence for the search/boolean revision: fresh PostgreSQL 18.6 installation with 479 database-contract assertions, MariaDB with 80, application and composite-search contracts on both engines, and PostgreSQL HTTP installation/login/core lists without SQL errors. All 728 displayed/sorted columns across nine core types pass `EXPLAIN` on both engines; this validates SQL planning, not every column's behavior on populated production data. The behavior contract covers aggregate/scalar OR, negation, meta criteria, zero counts, entity scope, ordering, counts, pagination, display completeness and native booleans. The PHP 8.3 legacy search suite passes all 33 methods and 565,107 assertions on MariaDB. Remote CI and browser JavaScript/E2E runs are not claimed.

The PHP 8.3 legacy query suites passed on the search revision (46 methods, 4,057 assertions). The reporting/ORM revision passes the MySQL lifecycle suites (93 methods, 4,965 assertions) and contract/contact/profile/date-helper/user suites (60 methods, 6,859 assertions). PostgreSQL now passes 510 database-contract assertions and MariaDB 111; both pass the new ORM, purge and populated reporting contracts. PostgreSQL HTTP report routes pass without SQL or fatal PHP log errors.

## Remaining release blockers

- Complete search portability outside the tested planner: legacy union/map/all/view fallbacks, plugin projections and arbitrary custom computation SQL, uncommon item types and full-text functions. Validate every supported filter and display combination on populated data. Ordered DISTINCT aggregates and mixed aggregate/meta criteria are covered by the new planner, not by SQL rewriting.
- Complete portability of scheduled jobs, migrations, maintenance/schema-check commands and remaining raw MySQL expressions. Core report routes and twelve monthly statistics measures are now covered; uncommon report/plugin combinations still need broader validation. Historic `Update::doUpdates()` explicitly rejects PostgreSQL to avoid partially applying MySQL DDL.
- Add a verified migration of existing MySQL data, including zero dates, booleans, unsigned ranges, collations, sequences, orphans and rollback/reconciliation. This branch does not migrate an existing MySQL database to PostgreSQL.
- Replace the legacy baseline reader with a versioned provider-neutral schema/migration history. The reader is a bridge, not an arbitrary SQL parser; PostgreSQL-specific full-text/prefix indexes and triggers are separate platform additions.
- Preserve case/accent-sensitive behavior deliberately. PostgreSQL text equality and uniqueness are not equivalent to `utf8_unicode_ci`; iterator LIKE uses ILIKE, but that does not solve collation parity.
- Expand foreign-key coverage after optional sentinel references and polymorphic relations have an explicit design.
- Run full browser/API/E2E coverage, including JavaScript-driven dashboard widgets and AJAX paths. HTTP page smoke tests do not exercise those paths.
- Audit plugins and extensions that use raw mysqli results, MySQL DDL, SQL functions or vendor-specific migrations. No blanket plugin compatibility is claimed.

The subsequent full-mapping revision passes 517 PostgreSQL and 118 MariaDB database-contract assertions. ORM/native row parity is checked independently from metadata completeness. The full ORM/FK conversion remains active; see the coverage inventory and [ORM migration notes](orm.md).
