# PDO value fixture Unicode ownership

The original e658 MariaDB PDO value contract reached the unchanged Japanese text INSERT and failed with native1366/22007. ROOT's original-source observer recorded the actual DBAL CREATE TABLE without a charset/collation clause, actual table Latin-1 collation and Latin-1 label column, database/server Latin-1 defaults, and UTF8mb3 client/connection/results. The failed original execution and private before capture remain retained; they are not corrected passing receipts.

The contract owns a newly created DBAL Table and tests literal Unicode text. It now declares utf8mb4/utf8mb4_unicode_ci table options on MySQL/MariaDB, rather than relying on unrelated server/database defaults. A read-only native catalogue assertion verifies the actual table collation and label charset/collation. This query runs before the original prepare-counter baseline, preserving the measured public bound INSERT interval. PostgreSQL DDL remains exact.

Every original value, bind type, numeric/binary/NULL/stream/result assertion, native protocol counter, ownership operation, DDL cleanup and failure handling remains unchanged. No database/server/session setting or production schema is changed. Table encoding is not a claim that the configured connection accepts every possible four-byte character; the original meaningful Unicode value is retained and current client-mode support remains a separate gate.

ROOT must compile the actual composition, run the entire corrected contract on both providers, inspect native schema/ledger and cleanup, then continue the coherent full discovered suites and fresh-install/upgrade validation. SOURCE author runtime remains UNRUN; the modernization goal remains OPEN.
