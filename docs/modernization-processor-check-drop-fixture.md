# Processor partial-state CHECK removal

The unchanged MariaDB phase observer reached the fixture’s post-loop partial-generated-state setup and failed native SQLSTATE42000/error1064 at `DROP CHECK`. The retained `$interruption` value was `constraints`, its final loop value; the bad statement is original line293 after the completed interruption/retry loops, not the injection callback.

The fixture now uses its actual DBAL platform: official MySQL drops a CHECK with `DROP CHECK`, while MariaDB and PostgreSQL use `DROP CONSTRAINT`. Both table and owned constraint identifiers are quoted. The same selected native constraint is deliberately removed; all invalid partial-state operations, native data/receipt predicates, retry and restoration assertions remain unchanged. No production SQL, historical migration definitions, enforcement or phase names change.

SOURCE-only correction, separate from the missing-column setup correction. Corrected native runs and official MySQL CI remain ROOT gates.
