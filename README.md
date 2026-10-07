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
ITSM-NG 2.1.3 before adopting this branch. Preserve the database and original
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

## Tests

Run `composer test:units:isolated` for disconnected tests. With a disposable
installation configured through `GLPI_CONFIG_DIR`, run `composer test:units` and
`composer test:function`. `composer test:migration` additionally requires an
empty, separately provisioned database named `itsm_test_*_migration`, selected by
`ITSM_TEST_MIGRATION_DB` and accessible to the configured database role. The
migration tests own and clean only that auxiliary fixture.

## Translation

You can translate ITSM-NG on [Weblate](https://hosted.weblate.org/projects/itsm-ng/itsm-ng/)
