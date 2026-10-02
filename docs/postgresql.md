# PostgreSQL port (experimental)

This branch is a development port, **not a complete or production-ready PostgreSQL release**. MySQL/MariaDB remain the default. PostgreSQL fresh installation, the shared database API, authentication, basic asset/ticket lifecycle and composite searches are covered by integration tests. The remaining work below is necessary before changing that status.

## Architecture

See [the latest integrated checkpoint](modernization-upgrade-dropdown-validation.md)
for exact source, provider versions, test evidence and remaining work. Dated earlier
validation figures are historical checkpoints. Fresh installs replay all twelve
current canonical versions, including subsequent frozen ownership/flag migrations;
supported upgrades use the same History coordinator and readiness checks.

- `DBAdapter` contains the existing shared CRUD, metadata-cache and quoting API. `DBmysql` retains its public compatibility name but delegates connection ownership, SQL execution, escaping and prepared statements to DBAL. It no longer calls the native MySQL driver. `DBpgsql` still provides the native PostgreSQL transport pending its migration. Existing generated `class DB extends DBmysql` configurations keep working.
- Doctrine DBAL 4.4+ is an explicit dependency and this branch requires PHP 8.2+. The installed development version is DBAL 4.5. `getDoctrineConnection()` uses the **same connection** as the legacy API. Session state, transactions and savepoints are shared. New application repositories should use ORM mappings and DQL; DBAL provides platform/schema operations. A query builder does not make arbitrary vendor SQL portable; use platform expressions for differences.
- `Migration/Baseline20261001.php` declares the frozen 355-table pre-adoption baseline with explicit DBAL Schema/Table APIs. It preserves provider types, defaults, comments, indexes, PostgreSQL expression indexes and timestamp triggers. The former runtime MySQL dump parser is removed. `BaselineSchema` now projects the current required schema for read-only inspection and compatibility checks; it never creates installation tables.
- CLI and web installers replay `Migration\History`: frozen baseline, frozen raw seed rows, the existing legacy-to-ORM adoption migration, and PostgreSQL integer-flag conversion. All phases use `itsmng_migrations`; current entities cannot rewrite historical DDL or seed rows. MySQL native `TIMESTAMP` semantics remain explicit. PostgreSQL installs transactionally; MySQL journals table creation and adopts through the existing resumable widening journal. Seeds and their completion record commit together on both engines. Sequences synchronize around adoption.
- `LegacySql` is a lexical bridge for the application's pre-escaped strings and backtick identifiers. It is not an SQL dialect translator. Prefer raw bound values with DBAL in new code. PostgreSQL rejects NUL text rather than silently truncating it.
- `Expressions` delegates date arithmetic to Doctrine platforms. Search has separate input, options, provider, projection, criteria, joins, sorting and output classes behind the existing `Search` facade. The SQL-rewriting `SearchProjection` bridge is removed. See [search architecture](search.md) for the two-phase planner and its compatibility boundaries.

Doctrine ORM now maps all columns of all 357 core tables. Core record-by-ID and supported structured criteria reads use ORM, and all 357 tables use ORM persistence below the existing `CommonDBTM` lifecycle; asset counts, reservations, calendars and financial reports use DQL repositories. Entity managers are scoped to one operation and share the adapter connection and transaction. See [mapped persistence and reporting](orm.md) for the ownership boundaries. Canonical migration history owns installation and indexes; do not run ORM schema synchronization against an installation.

## Fresh PostgreSQL installation

Install PHP's `pgsql` extension and Composer dependencies. PostgreSQL 14+ is the target. The configured CI matrix targets PostgreSQL 14 and 18, MySQL 8.4 and MariaDB 11.8 on PHP 8.2 and 8.3. Local checks are separate evidence from remote matrix results.

Provision an empty database owned by the application role. The application does not require cluster-level `CREATEDB` privileges. Then run:

```sh
php bin/console db:install --db-type=pgsql \
  --db-host=127.0.0.1 --db-port=5432 \
  --db-name=itsmng --db-user=itsmng --db-password
```

An unfinished, journaled MySQL installation can be retried with the same configuration and `db:install` (omit connection options unless also using `--reconfigure`). Retry preserves committed baseline DDL and resumes seed/adoption work; it does not replace completed tables. `--force` replaces a completed MySQL core installation; custom tables with foreign keys into core cause a preflight refusal before any table is dropped.

The password option without a value prompts securely. Existing MySQL installation commands retain `mysql` as the default. A PostgreSQL installation refuses a schema that already contains `glpi_*` tables, even with `--force`. Failed PostgreSQL installations roll back schema and data; configuration and the encryption key remain available for retry.

The web installer also offers PostgreSQL (experimental). Enter an existing empty database, host (optionally `host:port`), user and password. It uses the same transactional install safeguards and refuses PostgreSQL upgrades and reinstallation over existing tables.

`DBpgsql` supports host/port, bracketed IPv6 with port, Unix socket directories, UTF-8, named timezones and libpq TLS settings. Optional configuration properties include `dbschema` (default `public`), `dbsslmode`, `dbssl`, `dbsslca`, `dbsslcert`, `dbsslkey`. `dbssl=true` selects `verify-full`. Replica configurations inherit the active provider; live replication is not validated.

## PostgreSQL booleans

The 398 entity-local Doctrine boolean declarations drive native PostgreSQL
`boolean` columns and search result conversion. The duplicate `BooleanColumns`
catalogue has been removed. NULL defaults stay NULL. Tinyint display width is not
treated as type information: `do_count`, weekdays, timeline positions, orientation,
counters and several preferences needing further classification remain integers.
MySQL keeps its original column types.

The legacy adapter returns `0`/`1`/`null` for boolean results so existing forms, strict comparisons and packed search cells retain their contract. Search converts a boolean to an integer only where numeric comparison or display encoding requires it. No SQL text replacement converts arbitrary integer predicates into booleans. New code using Doctrine can bind `Types::BOOLEAN` directly.

The frozen `20261002_postgres_boolean_flags` migration also adopts early PostgreSQL smallint/integer flags. It validates all values and defaults before adoption DDL, reports offending fields and sample row IDs, and converts only 0/1/NULL. Nullability, NULL defaults and NULL values remain intact. Run `db:migrate` to preview and `db:migrate --apply` during maintenance. MySQL/MariaDB retain their native flag storage. This does not transfer a MySQL database to PostgreSQL.

## Foreign keys

Doctrine owning associations currently supply 1,003 enforced relationships on both
providers. One ordinary candidate and 41 polymorphic references remain to be
resolved; 23 logical discriminator selections have canonical FK-backed branches.
Run `php tools/database/audit-coverage.php` for the current inventory;
[mapped persistence and reporting](orm.md) documents each migration stage.

The initial set of 68 relationships included:

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
| `glpi_networkports_vlans` | `networkports_id`, `vlans_id` |
| `glpi_ipnetworks_vlans` | `ipnetworks_id`, `vlans_id` |
| `glpi_ipaddresses_ipnetworks` | `ipaddresses_id`, `ipnetworks_id` |
| `glpi_cartridgeitems_printermodels` | `cartridgeitems_id`, `printermodels_id` |
| `glpi_cartridges` | `cartridgeitems_id` |
| `glpi_consumables` | `consumableitems_id` |
| `glpi_projecttasks_tickets` | `projecttasks_id`, `tickets_id` |
| `glpi_tickets_tickets` | `tickets_id_1`, `tickets_id_2` |
| `glpi_notificationtargets` | `notifications_id` |
| `glpi_notificationtemplatetranslations` | `notificationtemplates_id` |

Update/delete actions are `RESTRICT`: the application must run its cleanup/history hooks before deleting the parent. Direct SQL that would orphan children fails. Root entity `0` remains a real row. Subsequent migrations also enforce nullable associations, including anonymous ticket actors: legacy zero inputs normalize to NULL while their alternative email address remains available.

For an existing installation, audit and review the DDL first:

```sh
php bin/console db:legacy_to_orm
php bin/console db:legacy_to_orm --apply
```

The master audits the legacy conversions before applying them and preserves their established sentinel normalization. Nonzero orphans require correction before applying. Applying is idempotent, and PostgreSQL applies transactionally. MySQL DDL commits implicitly; if execution fails partway, correct the reported problem and rerun. An installation must be quiescent while adding constraints; concurrent writes may make an ALTER fail, but cannot bypass the final constraint validation. Do not disable foreign-key checking to import invalid data.

Coverage is deliberately incomplete. Most audited optional references now use NULL, but unresolved and polymorphic identifiers still require domain-specific handling. Extending coverage requires classifying each relationship, updating queries and purge behavior, and checking existing data. Inferring constraints just from `_id` names would corrupt these semantics. New work should move declarations beside their entity relationships and derive shared lookups; see [the next architecture work](orm.md#next-architecture-work).

## Validation

The 2026-09-30 regression review passed all 86 CLI portability contracts after
fresh installs on PostgreSQL 16 with PHP 8.5.10 and MariaDB 11.8 with PHP 8.3.33.
The selected nine legacy suites passed all 153 methods (9,343 assertions) on a
separate fresh MariaDB installation. This includes the empty component scope,
nullable ITIL clone updater and timestamp timezone regressions. The new schema
check tests verify drift detection and non-mutating command behavior on both
engines. Remote matrix results, browser JavaScript and API coverage are not
implied by these local results. Earlier revision results below are historical.

Use fresh, disposable databases named `itsm_port_*` and separate configuration directories. The test scripts refuse other database names.

Provision a second empty database named `itsm_port_history` with the same test role for the discovered `migration-history.php` contract. It resets only that dedicated fixture and exercises baseline/seed interruptions, conflicting DDL, populated legacy adoption, invalid data, PostgreSQL nullable flags, generated projections, sequences and idempotency. `PORT_HISTORY_DB` may select another separate `itsm_port_*_history` fixture. CI provisions this database for every provider. It is never the application or primary portability database.

```sh
python3 tests/database-portability/suite.py /path/to/test-config
```

The runner discovers every CLI contract, including the contracts formerly missing
from CI. Individual scripts still accept the configuration directory as their first
argument. Run migration contracts sequentially against each database.

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
- Complete portability of scheduled jobs, migrations, maintenance/schema-check commands and remaining raw MySQL expressions. Core report routes and twelve monthly statistics measures are now covered; uncommon report/plugin combinations still need broader validation. The supported `Update::doUpdates()` facade now uses canonical history on both providers; older application-specific MySQL scripts are never selected by current release strings.
- Add a verified migration of existing MySQL data, including zero dates, booleans, unsigned ranges, collations, sequences, orphans and rollback/reconciliation. This branch does not migrate an existing MySQL database to PostgreSQL.
- Extend canonical history for subsequent schema changes; keep frozen baseline, seeds and old upgrade inputs immutable. Adoption targets the frozen `Baseline20261001` schema and partially converted ORM installations. Older schemas must first use their matching historical application to reach the ITSM-NG 2.1.3 schema; the current application must not replay historical MySQL scripts. Cross-engine MySQL-to-PostgreSQL data transfer remains separate work.
- Preserve case/accent-sensitive behavior deliberately. PostgreSQL text equality and uniqueness are not equivalent to `utf8_unicode_ci`; iterator LIKE uses ILIKE, but that does not solve collation parity.
- Expand foreign-key coverage after optional sentinel references and polymorphic relations have an explicit design.
- Run full browser/API/E2E coverage, including JavaScript-driven dashboard widgets and AJAX paths. HTTP page smoke tests do not exercise those paths.
- Audit plugins and extensions that use raw mysqli results, MySQL DDL, SQL functions or vendor-specific migrations. No blanket plugin compatibility is claimed.

The subsequent full-mapping revision passes 517 PostgreSQL and 118 MariaDB database-contract assertions. ORM/native row parity is checked independently from metadata completeness. The full ORM/FK conversion remains active; see the coverage inventory and [ORM migration notes](orm.md).

The expanded ORM lifecycle and 68-FK stage passes 533 PostgreSQL and 134 MariaDB
database-contract assertions, all-table ORM write checks, soft-delete/restore and
populated financial report scope/boundary checks. The full conversion still has
694 pending candidate references, 62 polymorphic references and one ambiguous
authentication reference, plus legacy query paths outside the shared lifecycle.

The MySQL transport migration uses DBAL's public result metadata, including empty
results, through `LegacyResult`. This seekable compatibility result buffers rows;
new repositories should use Doctrine results directly. `LegacyStatement` reuses
one prepared DBAL statement and reads bound variables at execution time. Legacy
result consumers no longer receive `mysqli_result`/`mysqli_stmt` instances.
Existing generated DB configuration classes still work. This removes native MySQL
calls from the adapter, but it does not convert remaining SQL query builders into
ORM repositories, nor remove the native PostgreSQL adapter yet.

The web installer and historical OCS upgrade helper now also use DBAL-owned
MySQL connections. Web installation selects/creates literal database names and
writes completion settings through the mapped configuration API. Fresh HTTP
installation and login pass on PostgreSQL and MariaDB, including MySQL selection
and creation failures and names containing backticks. The source token inventory
now finds 23 direct-driver sites, all in the PostgreSQL adapter; legacy application
queries and polymorphic FK work remain pending.

DBAL 4 transport-stage validation: fresh installation passes on both databases;
PostgreSQL passes 540 database-contract assertions and MariaDB 142. Every mapped
table passes ORM write checks; populated reporting and search contracts pass on
both engines. The PHP 8.3 DB, DBmysqlIterator, CommonDBTM, User and Ticket suites
pass 135 methods and 8,942 assertions. Both engines pass HTTP report checks.
The CI matrix now includes PHP 8.2 and 8.3; remote CI results are not yet available.

The subsequent criteria-read and sharing-relationship stage enables 96 foreign
keys. PostgreSQL passes 568 database-contract assertions and MariaDB 170; both
pass all-table mapped writes, parent purges, populated reporting, application
workflows and the new criteria-read contract. Knowledge-base, reminder, RSS,
saved-search, profile and entity suites pass 29 methods and 813 assertions on
PHP 8.3 with all 96 constraints enabled. The inventory still lists 666 pending
relationship candidates, 62 polymorphic references and one ambiguous reference;
full query and relationship conversion remains unfinished.
The shared CommonDBTM suite also passes 19 methods and 616 assertions with these
constraints enabled. HTTP report routes pass on both engines after the read change.

The knowledge-base stage raises FK coverage to 100, including a nullable
parent-comment self association. Its mapped repository replaces comment-tree,
revision, translation/count, FAQ-flag and view-counter queries. Replies remain
visible when a parent comment is purged. Both providers pass fresh installation,
the stricter per-constraint rejection contract (572 PostgreSQL / 174 MariaDB
assertions), all-table persistence and knowledge-base workflow checks. The PHP 8.3
knowledge-base suites pass 20 methods and 404 assertions. The remaining inventory
contains 662 pending relationship candidates; article search and visibility SQL
are among the queries still awaiting migration.

The shared-cleanup stage enables 111 foreign keys. ID selection for core child
purges, reference reassignment, entity forwarding and criterion-based deletion now
uses ORM while preserving model hooks. History cleanup and simple single-table
counts use mapped queries too. Ticket/change/problem template-field ownership and
notification-template links are enforced, including paired-field purge cleanup.
Both providers pass 583/185 database-contract assertions respectively, all-table
ORM writes, template and history isolation checks, parent purges and application
workflows. HTTP reporting checks pass on both engines. There are still 651 pending
relationship candidates, 62 polymorphic references and one ambiguous reference.
The shared-cleanup regression run passes 153 methods and 12,430 assertions across
database utilities, models, templates, notifications, users, tickets, calendars
and history on PHP 8.3.

Optional model references now bring FK coverage to 130. Existing installations
must review the zero-to-NULL migration before adding these constraints:

```sh
php bin/console db:legacy_to_orm --config-dir=/path/to/config
php bin/console db:legacy_to_orm --config-dir=/path/to/config --apply
```

Run the apply command during maintenance. The optional-model conversion remains one internal step of the master; it refuses nonzero orphans and real
model rows with ID zero rather than discarding references. New installations run
the seed normalization automatically. Both providers pass fresh installation,
optional-model lifecycle/search/migration tests, and the full database contracts
(602 PostgreSQL / 204 MariaDB assertions). The current inventory has 632 pending
relationship candidates, 62 polymorphic references and one ambiguous reference.

## Supported upgrade commands

Use `db:migrate` or `db:update --dry-run` to inspect canonical history, then
`db:migrate --apply` or `db:update` with application writers stopped. Both providers
share the same history, release publication and original-key policy. `--force`
retries the canonical journal; it does not run historical MySQL scripts. Web upgrade
requires a pre-existing authenticated administrator session with Config UPDATE
rights and CSRF validation. Other pending-history requests show CLI recovery only.
See [the entrypoint and bootstrap contract](orm.md#canonical-upgrade-entrypoints-and-readiness-2026-10-02)
for the frozen adoption boundary and failure statuses. Older unsupported schemas
must reach that boundary using their matching historical application first;
current entities cannot redefine old migration history.

Operating-system assignments now have six owning asset associations and a generated
legacy identity. The frozen `20261006_operating_system_subjects` migration is appended
to the same canonical history; preview/apply uses the commands above. Invalid or
missing subjects and duplicate OS/architecture assignments must be resolved in the
source installation before adoption. Component purge/replacement refuses merges of
distinct licensed inventory rows, including deleted history. See
[OS ownership and validation](orm.md#operating-system-assignment-ownership-2026-10-02)
for the actual scope and checkpoints; standalone focused success does not establish
full integrated PostgreSQL support.


## Independent identifier sequence repair (2026-10-02)

The appended `20261007_identifier_sequence_widths` phase repairs narrow SERIAL,
SMALLSERIAL and IDENTITY generators even when their columns are already BIGINT
and the original adoption receipt is complete. Run the canonical preview/apply
commands above; application readiness requires its receipt too. Scope comes from
the frozen identifier history and real same-schema FK edges, with actual sequence
ownership. It does not adopt unowned defaults or cross-schema plugin generators.

Native widening retains custom allocation parameters and reservations, while
expanding bounds equal to the previous type defaults. Ordinary synchronization
performs no DDL: native signed increments select imported MIN/MAX and numeric
next-candidate comparisons preserve safe allocations. Advancing to the imported
extremum can shift the progression residue. Custom bounds remain enforced, and
CYCLE retains PostgreSQL wrapping behavior. Stop writers and drain other backends
with cached sequence values before maintenance. See
[the canonical sequence policy](orm.md#canonical-installation-and-adoption-history).

The focused sequence contract passes on PostgreSQL 15.19 (74 assertions) and
MariaDB 10.11 (12 assertions), followed by the unchanged adoption and schema-check
contracts on both providers. These use populated disposable checkpoint clones
and actual canonical apply. Fresh replay, the complete integrated suite, browser,
remote CI and live replicas are separate validation scopes.
