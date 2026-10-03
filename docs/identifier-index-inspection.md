# Identifier migration index inspection

`WideIdentifiers::plan()` already loads one DBAL schema snapshot containing columns, indexes, foreign keys and table options. It now avoids a second native index query for each widening table without generated columns: those tables cannot contribute any generated-column index drop or restore operation.

Generated-column tables still use `listTableIndexes()`. Replacing that call with `Table::getIndexes()` would be unsafe: DBAL's `Table` constructor can add an implicit FK-support index that does not exist in PostgreSQL. A generated-column foreign key without a native supporting index must not produce a drop for this synthetic index. The existing native inspection, index flags/options, quoted identifiers, primary-key rejection and statement order remain intact for these tables. Post-DDL index inspection in `execute()` also remains intact for retry decisions.

Source review used the installed Doctrine DBAL implementation: `AbstractSchemaManager::listTables()` and `listTableIndexes()` share the portable index conversion, including MySQL prefix lengths/FULLTEXT/SPATIAL flags and PostgreSQL predicates/quoted names, but `Table::_addForeignKeyConstraint()` can supplement that result. This change avoids depending on or filtering those internal synthetic indexes. Historical migration definitions, data audits and journals are unchanged; no catalogue cache survives a planning call.

This is source-only validation. PHP syntax, configured formatting and whitespace checks pass. No artificial connection mock or implementation-mirroring assertion was added: the meaningful regression needs native catalogue observations during the actual plan. Remaining live gates on PostgreSQL and MySQL/MariaDB are:

- Compare planning SQL and catalogue-query counts for populated narrow identifiers, including storage-only changes and tables without generated columns.
- Preserve generated-column indexes, quoted names, composite/unique indexes and applicable flags/options. Include a PostgreSQL generated-column FK with no native supporting index and verify no synthetic index is dropped.
- Run populated adoption, interrupted DDL retry/idempotency and fresh installation, then the complete portability suite and final native schema inspection.
- Measure the unchanged full-history contract under its 300-second budget. The previous MariaDB result of 295.178 seconds is a planning risk; no runtime improvement or Boolean-stage timing has been established by this source change.
