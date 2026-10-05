# Frozen Domain adoption date-range fixture

The current CI matrix exposed a fixture assumption: `domain-plugin-adoption.php`
required every MySQL-family provider to refuse a 2040 source DATE. MariaDB 11.5
and later extend native TIMESTAMP storage through 2106. The frozen adoption
preflight already accounts for that range; the historical target still uses
nullable native TIMESTAMP columns. PostgreSQL supports both tested calendar
dates. This change corrects the fixture without editing production date policy,
the frozen baseline, seed data, adoption definitions or canonical version list.

The fixture discovers support by writing to the actual existing frozen
`glpi_domains` target row inside a transaction and rolling back. It does not
select expectations from a MariaDB version string. Only native date-range errors
classify a narrow target; unrelated errors and successful coercions fail the
contract. Native MySQL and older MariaDB must still refuse the 2040 source.
Supported engines must plan it and retain the exact calendar midnight, original
second-precision modification timestamps and source NULLs through actual target
column writes and reads. A 2107 DATE is beyond the unsigned TIMESTAMP limit and
must be refused on every MySQL-family engine; PostgreSQL must accept it.

Trials update an existing row rather than insert identifiers, so rolled-back
checks do not advance nontransactional identity allocators. Every stage compares
complete duplicate-preserving source/core row bags, native columns, indexes,
constraints and table facts, and verifies that the migration ledger remains
absent. Refused planning must leave those native definitions and all application
data exact. Restoring the temporary source DATE must reproduce the entire
original fixture snapshot before the existing populated-adoption and canonical
DDL interruption/retry contracts continue.

This commit has source review only. PHP syntax/style, live PostgreSQL,
MySQL 8.4, MariaDB 10.11 and MariaDB 11.8 execution, original full-suite ordering,
native schema convergence and original 300-second limits remain ROOT validation
gates. Cloud MariaDB 10.11 alone cannot establish the extended-range behavior.
