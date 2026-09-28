# Mapped persistence and reporting

Doctrine ORM 3 is an explicit dependency alongside DBAL 4.4+ (PHP 8.2+). The attributes in
`src/Database/Entity` now map all 3,561 columns of all 355 baseline tables,
including the dashboard's composite primary key and explicitly assigned IDs.
`EntityRegistry` lists each table and mapped class. These are persistence records;
application permissions, validation, hooks, history and notifications remain in
`CommonDBTM` and its subclasses.

Core `CommonDBTM::getFromDB()` reads now use `RecordRepository` and ORM hydration.
The repository preserves the legacy scalar row interface, including integer flags,
representable bigint values, date strings and JSON values. Plugin tables still use
the legacy path until their mappings are registered.

Audited required relationships use `ManyToOne` associations without cascading
removal. Optional legacy references using zero and polymorphic item references
remain scalar columns. Booleans, dates, decimals and JSON have explicit Doctrine
types; decimals remain strings to avoid rounding through floating point.

`MappedStorage` now handles insert/update/delete, soft deletion and restoration
for all 355 registered core tables through `RecordWriter`. `CommonDBTM` calls it
below lifecycle processing; direct bulk SQL elsewhere is still pending migration.
The writer supports assigned IDs, generated IDs, the dashboard's alternate key,
JSON, native booleans, UUID values and clock boundaries. Bulk legacy SQL can still
write these tables and the same foreign keys remain authoritative.
Calling `EntityManager::flush()` directly is not an alternative application API:
it would bypass those lifecycle services.

Every operation gets a short-lived entity manager on the adapter's existing
DBAL connection. It shares the legacy transaction and uses nested savepoints.
It never retains managed objects across legacy writes. Pre-escaped legacy values
are decoded once by `MappedStorage` and then bound with Doctrine types; new
repositories accept raw values. SQL expressions are not accepted as mapped values.
Explicit IDs remain supported for imports. MariaDB timezone catalog reads use a
separate, short-lived DBAL connection: its system tables can use Aria, whose reads
inside the application transaction prevent subsequent savepoints.

`AssetRepository` counts the eight mapped asset types with DQL, and
`ReservationRepository` reads reservations through their mapped item association.
The callers supply the active entity scope: `null` means all authorized entities,
whereas an empty list returns nothing. Report entry points still perform their
existing rights checks. Plugin asset counts retain the query-iterator path.

## Schema ownership

The mappings can generate a scoped schema model with Doctrine `SchemaTool`, and
tests check mapping validity and exact column coverage against the baseline.
The existing installer still owns the complete 355-table schema, including
legacy indexes, provider-specific indexes/triggers and seeding. The entity metadata
does not yet replace that schema or introduce a second installation path. Never apply
`SchemaTool::updateSchema()` or `schema:update --force` to an installation: plugin
tables, legacy indexes and provider-specific definitions are not represented by
these entity mappings. Moving schema ownership requires reviewed, versioned
migrations, including handling of optional zero references.

## Reporting behavior

- Default asset counts do not multiply assets linked to several computers. OS
  counts include only active, non-template computers in the active entities.
- Network reports aggregate each endpoint's addresses independently, ordered and
  deduplicated. Both the selected port and its opposite endpoint obey entity scope;
  a hidden or deleted opposite endpoint is omitted.
- Year filters use date ranges. Financial filters require either purchase or
  startup date to fall inside the entire requested interval. Consumable entity
  restrictions use the parent item table.
- Reservation reports use typed DQL and entity scope for both past and future rows.
- Both financial reports use `FinancialRepository` for core types. DQL joins mapped
  assets and entities, applies typed inclusive date bounds, and scopes consumables
  and cartridges through their parent. Plugin types retain the iterator path.
- Calendar segment queries now use `CalendarRepository` and DQL. Interval
  calculations operate on clock boundaries in PHP, avoiding MySQL `TIMEDIFF`.
  The `itsm_clock_time` Doctrine type preserves `24:00:00` instead of normalizing
  it to midnight. Holiday purge removes calendar associations unless a replacement
  holiday was requested.
- Monthly ticket statistics use platform date formatting and group-compatible
  ordering. The common date-range helper delegates arithmetic to the platform.
- The reservation user selector filters by matching IDs before fetching user
  records. This avoids comparing PostgreSQL JSON values and counts each user once
  even when several emails or profiles match.

Run `tests/database-portability/orm.php` and `reporting.php` against a disposable
`itsm_port_*` installation. They use transactions and cover populated results,
typed writes, JSON, shared transactions, all new parent purge paths, entity scope,
financial boundaries, address aggregation and twelve monthly statistics measures.
`web-reports.py` checks the actual report routes with login and CSRF tokens, and
checks SQL and fatal PHP logs. It requires an isolated, installed test web server
with the seeded `itsm` account; it does not install a database.

## Full conversion remains in progress

Run `php tools/database/audit-coverage.php` for the remaining table writes,
relationship candidates, polymorphic references and legacy SQL/driver call sites.
This is an intentionally incomplete static inventory: it cannot prove discovery of
serialized references, dynamic SQL or alternate connection variables. Each
candidate needs semantic review before installing its FK. The current inventory
contains 825 candidate reference columns: 68 enforced, 694 pending, 62 polymorphic
and one ambiguous (`users.auths_id`, whose target depends on authentication type).

`tools/database/generate-mappings.php` is a development scaffold for explicit
attributes, not a runtime mapping driver. Review regenerated mappings before use.
`orm-records.php` compares typed ORM records with native rows from each core table
(up to 25 seeded rows per table); empty tables only have metadata/query coverage.
The full conversion is not complete: direct SQL, the native adapters, optional
sentinel references and polymorphic schemas still need migration. Complete entity
mappings are necessary infrastructure, not evidence that every query uses ORM.

Current regression evidence: the full-mapping stage passes the PHP 8.3 Calendar
and CommonDBTM suites (26 methods, 775 assertions). After enabling the additional
calendar/rule/network writes, Calendar, Rule, RuleTicket, RuleCriteria and
NetworkPort pass 2,520 assertions (83 executed methods and one pre-existing void
method). Both database providers pass the new FK, mapped lifecycle, end-of-day
boundary, record parity, application and reporting contracts. This does not prove
compatibility of every legacy dataset or every remaining SQL call site.

The expanded write stage adds `orm-writes.php` to the provider CI matrix. It
inserts and reads every mapped table, updates a scalar field on the 344 tables
that have one, deletes records in dependency order, and rolls back the fixtures.
This is persistence coverage, not a substitute for application lifecycle tests.
The 16 additional constraints cover network/VLAN/IP associations, cartridge
compatibility and stock, consumable stock, task/ticket links, linked tickets,
notification targets and template translations. Their parent purge hooks are
exercised on both databases with the actual constraints enabled.

With all 68 constraints enabled, the PHP 8.3 CommonDBTM, User, Ticket, Calendar,
Contract and Profile suites pass 103 methods and 5,206 assertions. Both database
providers pass explicit-ID/import sequencing and raw-value preservation checks.
