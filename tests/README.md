## GLPI test suite

To run the GLPI test suite you need

* [atoum](http://atoum.org/)

Installing composer development dependencies
----------------------

Run the **composer install** command without --no-dev option in the top of GLPI tree:

```bash
$ composer install -o

Loading composer repositories with package information
Installing dependencies (including require-dev) from lock file
[...]
Generating optimized autoload files
```

Creating a dedicated database
-----------------------------

Use the **glpi:database:install** CLI command to create a new database,
only used for the test suite, using the `--config-dir=./tests/config` option:

```bash
$ bin/console glpi:database:install --config-dir=./tests/config --db-name=glpitests --db-user=root --db-password=xxxx
Creating the database...
Saving configuration file...
Loading default schema...
Installation done.
```

The configuration file is saved as `tests/config/config_db.php`.

The database is created using the default schema for current version.

If you need to recreate the database (e.g. for a new schema), you need to run
**glpi:database:install** CLI command again with the `--force` option.


Changing database configuration
-------------------------------

Using the same database than the web application is not recommended. Use the `tests/config/config_db.php` file to adjust connection settings.

Running the test suite on developpement env
-------------------------------------------

There are multiple directories for tests:
- `tests/units` for unit tests;
- `tests/e2e` for Playwright browser end-to-end tests, with specs stored in `tests/e2e/spec`;
- `tests/functional` for functional tests;
- `tests/imap` for Mail collector tests;
- `tests/LDAP` for LDAP connection tests;
- `tests/web` for API tests.

Run both unit groups with their Composer commands. `test:units` runs application tests with `tests/bootstrap.php`; `test:units:isolated` runs Doctrine and domain units in `tests/units/itsmng` with only the Composer autoloader. The application command and CI action exclude the isolated group.

For a specific application test file or \<class::method>, specify the application bootstrap:

```bash
$ composer test:units
[...]
$ composer test:units:isolated
[...]
$ atoum -bf tests/bootstrap.php -f tests/units/Html.php
[...]
$ atoum -bf tests/bootstrap.php -f tests/units/Html.php -m tests\units\Html::testConvDateTime
```
In `tests\units\Html::testConvDateTime`, you may need to double the backslashes (depending on the shell you use);

If you want to run the API tests suite, you need to run a development server:

```bash
php -S localhost:8088 tests/router.php &>/dev/null &
```

Running `atoum` without any arguments will show you the possible options. Most important are:
- `-bf` to set bootstrap file,
- `-d` to run tests located in a whole directory,
- `-f` to run tests on a standalone file,
- `-m` to run tests on all \<class::method>, * may be used as wildcard for class name or method name,
- `--debug` to get extra information when something goes wrong,
- `-mcn` limit number of concurrent runs. This is unfortunately mandatory running the whole test suite right now :/,
- `-ncc` do not generate code coverage,
- `--php` to change PHP executable to use,
- `-l` loop mode.

Note that if you do not use the `-ncc` switch; coverage will be generated in the `tests/code-coverage/` directory.

On first run, additional data are loaded into the test database. On following run, this step is skipped. Note that if the test dataset version changes; you'll have to reset your database using the **CliInstall** script again.

Note: you may see a skipped tests regarding missing extension `event`; this is expected ;)

Running the test suite on containerized env
-------------------------------------------

If you want to execute tests in an environment similar to what is done by CI, you can use the `tests/run_tests.sh`.
This script requires Docker and its Docker Compose v2 plugin.
The harness relies on scripts and compose files located in `.github/actions/`, and builds local test images (app + dovecot) automatically.
The database service defaults to MariaDB. Selecting `imap` starts Dovecot and loads `tests/emails-tests/*.eml`; selecting `ldap` starts OpenLDAP and loads its fixtures. `--all` starts both services. Local app, browser-runner and mail images are built from their Dockerfiles; `app-web` uses the resulting app image.
The `e2e` suite uses the same test install data as the `web` suite, but in local containerized mode it runs against a dedicated `app-web` PHP server container and a separate Playwright runner container on the same compose network. Test data setup for E2E scenarios is done through the public REST API, after an app-side prep step enables the API and provisions a dedicated test API client/token for the browser runner.
LDAP fixtures are now initialized idempotently, so rerunning `tests/run_tests.sh ldap` does not require manual volume cleanup.
The `update` suite runs `composer test:migration` against the current public installation and a separate, initially empty database. The proper Atoum tests cover frozen DBAL input adoption, refusal of unsupported older provenance, populated data and original-key preservation, interrupted installation, final schema convergence, and retry. The container harness orders `update` immediately after the public `install`, before ordinary fixture-loading suites. Direct invocation refuses a parent containing the ordinary dataset or tester plugin before creating its target; it never resets that parent. Relabeling a modern database with an old version is not upgrade coverage.

That frozen fixture is not an installation of the official 2.1.3 application. Independent released-version coverage must install 2.1.3 with its own source and dependencies, then execute the public 2.1.3→2.2.0 transition and subsequent ORM history. It must preserve the original key and application data. The historical 0.72.3 dump and update files remain available separately; the current update suite does not run that dump through the modern adoption command or claim a 2.0.0–2.1.2 release matrix.

Run `tests/run_tests.sh --help` for more information about its usage.
