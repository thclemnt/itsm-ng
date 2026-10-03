# Configuration cache bootstrap

`Config::getCache()` supplies cache defaults when no connected database or configuration table is available. It checks the table before asking `fieldExists()` about its modern `context` column. This boundary belongs to optional cache configuration; `DBAdapter::fieldExists()` continues to warn about an absent table for other callers. Present configuration still uses `ConfigurationRepository` on the adapter supplied by the caller, and database query failures propagate.

Source inspection at `f3db6577c7324e04305c2a8cbbfbb4960ddf7c38` found two console calls before installation: `Application::__construct()` initializes `cache_db`, then `computeAndLoadOutputLang()` invokes `Session::loadLanguage()`, which initializes `cache_trans`. Both could ask for a column in the not-yet-created `glpi_configs` table. The PostgreSQL fresh-install log reported two warnings before the command's optional requirement notice. This is source evidence consistent with the log, not a captured live stack. `History::install()` already creates the baseline before seed translation and insertion; migration history is unchanged.

The portability contract `cache-bootstrap.php` requires an additional empty disposable database named `itsm_port_cache_bootstrap`, or the `PORT_CACHE_BOOTSTRAP_DB` override ending in `_cache_bootstrap`, accessible through the supplied test credentials. It refuses existing tables. Under strict warning handling it exercises both cache families with absent/disconnected adapters and an empty database, then creates the configuration table from its authoritative entity and seeds it. The same adapter must discover configured cache options without a schema-cache reset. A deliberately missing mapped column must still produce its real database exception. The fixture removes only its own configuration table and never changes the installed parent database.

PHP 8.2.33 lint passes for both changed/new PHP files; PHP CS Fixer 3.95.27 reports no formatting changes, the workflow YAML parses, and the staged whitespace check passes. Source discovery lists 168 contracts.

The parent validation runner subsequently tested the exact source `36259f85479d070746e2ee7659279f8889815b68` on both providers. The following focused checks passed without warnings:

| Check | PostgreSQL | MariaDB |
| --- | --- | --- |
| Cache bootstrap, 37 assertions | 0.484 s | 0.424 s |
| Dropdown lifecycle | 0.955 s | 0.975 s |
| Kanban | 1.647 s | 2.519 s |
| Read-only schema check | 3.176 s | 17.678 s |
| Native schema inventory | 1.412 s | 8.415 s |

Both native inventories report 12 canonical versions, 357 mapped tables, 358 actual tables and 1,057 enforced foreign keys, with no pending versions or schema differences. These counts describe the validated checkpoint, not completion of the broader relationship or application migration.

Causal reproduction used the same external cache contract against original source `f3db6577c7324e04305c2a8cbbfbb4960ddf7c38`, changing only its `GLPI_ROOT` to select that source. Both providers failed as expected with exit 1 and `ErrorException: Table glpi_configs does not exists` at `DBAdapter::fieldExists()` (PostgreSQL 0.371 s; MariaDB 0.341 s). This is a captured contract stack establishing the original cache defect; the earlier fresh-install console call ordering remains source evidence rather than a captured installer stack.

Cloud evidence is recorded in `/workspace/itsm-env/evidence/cache-bootstrap-validation.jsonl` and the adjacent `cache-bootstrap-cache-bootstrap-{pg,mysql}.log`, `cache-bootstrap-original-{pg,mysql}.log`, `cache-bootstrap-{dropdown-lifecycle,kanban,schema-check,native-schema}-{pg,mysql}.log` files. The note update only reads that evidence; it does not rerun the tests.

Actual fresh CLI installation with this cache repair has not yet been run. Fresh installations on both providers, the complete portability and application suites, browser verification, official-engine checks and remote CI remain pending for the combined source. The overall ORM and PostgreSQL modernization goal remains open.
