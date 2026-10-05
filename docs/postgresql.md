# Database installation and upgrades

PostgreSQL support is experimental. This branch retains the PHP application and
MySQL/MariaDB; it does not transfer an existing MySQL database to PostgreSQL.
[Doctrine ownership](orm.md) explains the architecture, and the
[implementation plan](modernization-handoff.md) records validation limits.

## Requirements and configuration

Install Composer dependencies and PHP 8.2 with PDO plus `pdo_pgsql` for PostgreSQL
or `pdo_mysql` for MySQL/MariaDB. Native `mysqli` remains relevant to legacy plugins
and tooling, not core DBAL transport. Enforced CHECK constraints and native
inspection require MySQL 8.0.16+ or MariaDB 10.2.22+; permissive SQL modes and
disabled MariaDB CHECK enforcement are refused. The CI target matrix is PostgreSQL
14/18, MySQL 8.4 and MariaDB 11.8 on PHP 8.2/8.3. Targets are not passing results.

Provision an empty database owned by the application role; PostgreSQL installation
does not require cluster-level CREATEDB. Keep configuration, keys and application
files separate from disposable test installations.

```sh
php bin/console db:install --db-type=pgsql \
  --db-host=127.0.0.1 --db-port=5432 \
  --db-name=itsmng --db-user=itsmng --db-password
```

A password option without a value prompts securely. MySQL remains the default.
`--config-dir` selects a configuration directory. Reusing configured connection
options requires `--reconfigure`; avoid replacing a configured database accidentally.

PostgreSQL supports host/port, bracketed IPv6, Unix sockets and named timezones.
Optional DB properties include `dbschema` (default `public`), `dbsslmode`, `dbssl`,
`dbsslca`, `dbsslcert` and `dbsslkey`. `dbssl=true` requests `verify-full`. Replicas
inherit the provider; live replica and TLS validation remain separate gates.

The web installer offers PostgreSQL with the same empty-database safeguards.
PostgreSQL refuses existing core tables even with `--force`; failed installation
rolls back database changes while retaining configuration/key material for retry.
On MySQL, retry unfinished journaled installation with the same configuration and
`db:install`, preserving completed tables. `--force` on completed MySQL installation
is destructive; custom incoming foreign keys cause refusal before dropping core.

## Supported adoption and maintenance

Back up database, files, configuration and the original `glpicrypt.key`. Stop all
application, cron, import and plugin writers. Advisory locks coordinate cooperating
migration processes, not arbitrary writes or cached sequence allocations.

```sh
php bin/console db:migrate --config-dir=/path/to/config
php bin/console db:update --config-dir=/path/to/config --dry-run
php bin/console db:migrate --config-dir=/path/to/config --apply
php bin/console db:check --config-dir=/path/to/config
```

Preview is read-only. `db:update` without `--dry-run` also applies canonical history
and publishes release metadata through the lifecycle. Its `--force` retries history,
never old MySQL scripts. `db:legacy_to_orm` aliases the same migration entrypoint.

The supported upgrade is genuine ITSM-NG 2.1.3 historical data/schema format to
2.2.0 in one ORM migration. Older releases must complete their matching historical
application's upgrade to 2.1.3 before switching application files. Later upgrades
use only ORM migrations.
Changing version labels alone does not convert data or profile rights. Experimental
ORM installations, including interrupted ones, retain their original
internal phase checkpoints in `itsmng_migrations`; these are not public release
versions. `db:migrate --apply` validates and completes the transition before adding
the 2.2.0 release receipt. Bare experimental completion flags without retained
post-DDL CHECK/projection policy cannot establish native definitions and refuse
explicitly; restore the genuine 2.1.3 source for the supported transition. No journal
or data is replaced to manufacture provenance. Fresh-install language/timezone and
release aliases publish inside the owned final transaction before its completion
markers, so a failed configuration callback leaves installation retryable.
Original keys are required;
missing or invalid key paths never authorize regeneration. Customized accounts,
rights, plugins, notifications and audit must survive adoption. Current inspection
does not repair unsupported historical layouts.

Preflight diagnoses nonzero orphans, unsupported kinds/spellings, invalid booleans,
incompatible ownership, constrained duplicates and overflow. Correct source data
explicitly and rerun. Zero-to-NULL normalization follows frozen policy; real entity
zero and valid nullable payloads remain. PostgreSQL converts declared flags to
native boolean without accepting arbitrary truthy values. MySQL/MariaDB retain
native storage and enforced domains. Do not disable constraints for invalid data.

The released 2.1.3 source at `5ecdf8e2a29` contains a known inconsistent
marketplace seed. Historical commit `ef30493fad` removed notification `71` and
notification template `28`, but left these three child rows in `install/empty_data.php`:

| Source row | Missing owner |
| --- | --- |
| `glpi_notifications_notificationtemplates.id = 71` | `notifications_id = 71` and `notificationtemplates_id = 28` |
| `glpi_notificationtargets.id = 139` | `notifications_id = 71` |
| `glpi_notificationtemplatetranslations.id = 28` | `notificationtemplates_id = 28` |

The released installer checks SQL execution errors, but these tables have no
historical foreign keys, so inserting those rows succeeds. A successful genuine
2.1.3 installation therefore does not prove referential validity. Migration
2.2.0 archives the complete original rows in the existing `itsmng_migrations`
receipt `2.2.0_retired_marketplace_defaults` and retires their invalid live links
in the same transaction. This applies only when all three complete rows exactly
match the released literal defaults, no additional child targets either retired
ID, and both parents are absent. The literal template text is not translated by
the released installer. Unknown columns, customized text, partial defaults or a
remaining parent refuse with source and missing-owner samples before archival.
Incoming foreign keys, custom table triggers and PostgreSQL rewrite rules also
refuse before ledger creation: their deletion effects are outside the three-row
archive, even when the three defaults themselves are unchanged.
No parent is invented and no historical release file is changed. Later development
commit `01aeb375df` independently removed the same three obsolete seed children.

Preview lists the three archive/retirement actions without writing. Interrupted
archival rolls back both the receipt and all deletions; retry verifies retained
original rows against the frozen defaults. Subsequent migration failures on MySQL
may leave this completed archive in place while canonical DDL remains pending.
Keep the original database backup and encryption key. Native upgrade fixtures must
retain and compare all three archived originals; a fixture with synthetic parents
is not evidence of an unmodified released-source upgrade.

PostgreSQL migration DDL is transactional. MySQL DDL can commit before error;
retry resumes the existing journal and validates captured state. Never fabricate
completion receipts, reinstall over populated data or run ORM schema synchronization.
Completed history does not restore snapshots over later edits. For interrupted
installation use `db:install`, not upgrade. Schema checking remains read-only.

PostgreSQL sequence repair preserves native allocation parameters and reservations,
widens owned narrow generators and advances beyond imported IDs. Custom bounds
remain enforced; CYCLE retains native behavior. Stop writers and drain backends
holding cached values before maintenance. Imported advancement can change progression
residue; this is not cross-engine export tooling.

Web upgrade requires an existing authenticated session with Config UPDATE rights
and valid CSRF. Other pending-history requests show CLI recovery; release strings
alone do not make the application ready. Rollback cannot reverse external delivery
or files changed by hooks.

## Historical Domains plugin

`itsmng:migration:domains_plugin_to_core --dry-run` previews optional import;
execution requires complete canonical history, valid schema, transactional storage
and the configured writer. `--skip-errors` is unsupported. The supported source is
a completed InfotelGLPI/domains 2.1.0 export pinned at
`e628ee87b84a87867365dbc77d06738e9e74a246`, with its declared four source tables.
Partial exports, unknown layouts, external roles and noncanonical identities refuse.

Deactivate the source `domains` plugin through its compatible historical application
before maintenance, and keep all writers stopped. Reconcile expiry policy, grants,
cron and overlapping notification scopes explicitly; conflicting permissions are
not silently unioned. Source IDs and physical links remain distinct; names do not
establish ownership. Source tables and registration remain intact. Native timestamp
range/timezone rules apply to source dates; queued old URLs are not rewritten.

The existing ledger owns fingerprinted elective import and frozen pre-adoption
remapping. The prerequisite validates pending canonical audits before DDL;
complete installations use the ORM importer with normal hooks. Exact retries
preserve later edits and purges; changed exports/bindings require reconciliation.
This is not general support for every historical plugin version.

## Validation

Use separate disposable `itsm_port_*` databases and independent configurations and
keys. The runner refuses ordinary application database names and discovers contracts:

```sh
python3 tests/database-portability/suite.py /path/to/test-config
```

Run migration contracts sequentially per database. Provision a separate
`itsm_port_*_history` fixture for history replay; `PORT_HISTORY_DB` selects it.
Never use application or primary portability databases for that fixture.
Existing Composer application/unit suites use a configured test installation.
Pure migration provenance and seed-input contracts run in the existing isolated
Atoum suite (`composer test:units:isolated`), without a database bootstrap.
The populated frozen-input contract is not a substitute for an independent genuine
released-2.1.3 MySQL/MariaDB upgrade fixture.

Acceptance requires fresh replay, populated supported adoption, legacy IDs/sentinels,
nullability/booleans, original-key retention, sequence synchronization, interrupted
nontransactional DDL/retry and idempotency, then full suites on both providers and
final native schema inspection. Exercise authorization, clone/purge, hooks/history,
notifications, concurrency and plugins for changed domains. Build assets before
browser/E2E checks. Source review, units, focused provider checks, full suites,
browser behavior, remote CI and replicas are distinct claims. Read the
[current handoff](modernization-handoff.md) before borrowing old validation.
