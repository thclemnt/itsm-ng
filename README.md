![logo](https://static.wixstatic.com/media/e5b7d4_f67ff8c629844818a6e3e43550cb1e17~mv2.png/v1/fill/w_348,h_122,al_c,q_85,usm_0.66_1.00_0.01,enc_auto/Original%20on%20Transparent.png)

## About ITSM-NG

[![Run tests](https://github.com/itsmng/itsm-ng/actions/workflows/database.yml/badge.svg)](https://github.com/itsmng/itsm-ng/actions/workflows/database.yml)
[![Translation](https://hosted.weblate.org/widget/itsm-ng/itsm-ng/svg-badge.svg)](https://hosted.weblate.org/projects/itsm-ng/itsm-ng)
[![Translation](https://img.shields.io/github/v/release/itsmng/itsm-ng)](https://github.com/itsmng/itsm-ng/releases)

ITSM-NG is a GLPI fork with the objective of offering a strong community component and relevant technological choices.

## Prerequisites

Here is the list of the different libraries and modules and their versions useful for ITSM-NG.

* Apache, Nginx, etc
* MariaDB >= 10.2.22 or MySQL >= 8.0.16 (enforced CHECK constraints and native inspection)
* PostgreSQL 14+ is an experimental target.
* PHP 8.2.27 or newer
* Required PHP extensions :
  * ctype
  * curl
  * fileinfo
  * gd (picture generation)
  * iconv
  * intl
  * json
  * mbstring
  * PDO with pdo_mysql for MySQL/MariaDB or pdo_pgsql for PostgreSQL
  * session
  * simplexml
  * zlib

* Recommanded PHP extensions :
  * mysqli (legacy plugin and tooling integrations)
  * APCU (cache)
  * exif (security enhancement on image validation)
  * imap (mail collector and users authentication)
  * ldap (users authentication)
  * openssl (encrypted communication)
  * sodium (performances enhancement on sensitive data encryption/decryption)
  * zip and bz2 (installation of zip and bz2 packages to install plugin)

* Supported browsers :
  * Edge
  * Firefox (including 2 latest ESR versions)
  * Chrome

## Download

You will find all ITSM-NG releases [here](https://github.com/itsmng/itsm-ng/releases).

## Documentation

ITSM-NG documentation is avalaible at the following link : [Wiki](https://wiki.itsm-ng.org/).

## Database installation and upgrades

Install into an empty database using the public command; use `--db-type=mysql`
for MySQL/MariaDB or `--db-type=pgsql` for PostgreSQL:

```sh
php bin/console db:install --db-type=pgsql --db-host=localhost --db-name=itsm --db-user=itsm --config-dir=config
```

Existing installations must complete their historical application's upgrade to
schema version 2.1.3 before adopting this branch (including ITSM-NG 2.1.7).
Preserve the database and original
`config/glpicrypt.key`; an existing installation must never receive a replacement
key. Preview and apply the supported transition through the same configuration:

```sh
php bin/console db:update --config-dir=config --dry-run
php bin/console db:update --config-dir=config
```

Fresh installation and adoption replay immutable history through 2.2.0. Editing
current entity mappings does not update an installed database; later schema
changes require an appended migration. PostgreSQL support and broader ORM
modernization remain in progress.

Every deployment that changes application code or dependencies while retaining
the same release version must successfully complete
`php bin/console system:clear_cache --config-dir=config` and reload PHP
workers/opcache before serving requests. The command requires the configured
cache backend to be available and its contents to be cleared. Mapping caches use
the release, installation path, PHP version, and installed dependencies;
requests do not scan source files for changes.

## Tests

Run `composer test:units:isolated` for disconnected tests. With a disposable
installation configured through `GLPI_CONFIG_DIR`, run `composer test:units` and
`composer test:function`. `composer test:migration` additionally requires an
empty, separately provisioned database named `itsm_test_*_migration`, selected by
`ITSM_TEST_MIGRATION_DB` and accessible to the configured database role. The
migration tests own and clean only that auxiliary fixture.

The separate **Pre-ORM upgrade** workflow checks a real ITSM-NG 2.1.7 checkout
at `bdfdcedf6fd039c984dc20374afa24f23decc537`, whose installer creates schema
2.1.3. It uses that source's own dependencies and application dataset, runs the
current public upgrade twice, checks the resulting schema, and verifies that
account/password, asset, ticket, document, membership and rights data and the
original encryption key survive. This covers MySQL and MariaDB; the historical
application did not support PostgreSQL.

To run the same check locally, install the current Composer dependencies and
provision a separate empty MySQL/MariaDB database. Run with PHP 8.2:

```sh
ITSM_UPGRADE_DB_NAME=itsm_test_local_upgrade \
ITSM_UPGRADE_DB_HOST=127.0.0.1 ITSM_UPGRADE_DB_PORT=3306 \
ITSM_UPGRADE_DB_USER=test ITSM_UPGRADE_DB_PASSWORD=test \
bash .github/actions/test_legacy-upgrade.sh
```

The database role needs all privileges on that disposable database and access
to `mysql.time_zone_name`. The harness refuses a nonempty database and retains
its database and diagnostics for inspection. `ITSM_UPGRADE_WORK_DIR` selects the
diagnostics directory; `ITSM_LEGACY_SOURCE` can reuse an unmodified checkout of
the pinned revision. No frontend build is required. The historical source has
no Composer lock file, so its resolved dependency lock is retained with the
diagnostics; security advisory blocking is disabled only for that isolated
historical dependency installation.

## Translation

You can translate ITSM-NG on [Weblate](https://hosted.weblate.org/projects/itsm-ng/itsm-ng/)
