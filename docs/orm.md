# Mapped persistence and reporting

Doctrine ORM 3 is an explicit dependency alongside DBAL 3. The attributes in
`src/Database/Entity` map all columns of 23 tables. These are persistence records;
application permissions, validation, hooks, history and notifications remain in
`CommonDBTM` and its subclasses.

| Domain | Mapped records |
| --- | --- |
| Identity | User, UserEmail, Group, GroupMembership, Profile, ProfileRight |
| Contracts | Contract, ContractCost, ContractItem, ContractSupplier |
| Contacts | Contact, ContactSupplier, Supplier |
| Reservations | Reservation, ReservationItem |
| Assets | Computer, Monitor, NetworkEquipment, Peripheral, Phone, Printer, SoftwareLicense, Certificate |

Audited required relationships use `ManyToOne` associations without cascading
removal. Optional legacy references using zero and polymorphic item references
remain scalar columns. Booleans, dates, decimals and JSON have explicit Doctrine
types; decimals remain strings to avoid rounding through floating point.

`MappedStorage` handles insert/update/delete for GroupMembership, UserEmail,
ProfileRight, ContractCost, ContractItem, ContractSupplier, ContactSupplier and
Reservation. `CommonDBTM` calls it below lifecycle processing. Bulk legacy SQL
can still write these tables and the same foreign keys remain authoritative.
Calling `EntityManager::flush()` directly is not an alternative application API:
it would bypass those lifecycle services.

Every operation gets a short-lived entity manager on the adapter's existing
DBAL connection. It shares the legacy transaction and uses nested savepoints.
It never retains managed objects across legacy writes. Pre-escaped legacy values
are decoded once by `MappedStorage` and then bound with Doctrine types; new
repositories accept raw values. SQL expressions are not accepted as mapped values.
Explicit IDs remain supported for imports.

`AssetRepository` counts the eight mapped asset types with DQL, and
`ReservationRepository` reads reservations through their mapped item association.
The callers supply the active entity scope: `null` means all authorized entities,
whereas an empty list returns nothing. Report entry points still perform their
existing rights checks. Plugin asset counts retain the query-iterator path.

## Schema ownership

The mappings can generate a scoped schema model with Doctrine `SchemaTool`, and
tests check mapping validity and exact column coverage against the baseline.
The existing installer still owns the complete 355-table schema, including
legacy indexes, provider-specific indexes/triggers and seeding. The mapped subset
does not replace that schema or introduce a second installation path. Never apply
`SchemaTool::updateSchema()` or `schema:update --force` to an installation: unmapped
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
