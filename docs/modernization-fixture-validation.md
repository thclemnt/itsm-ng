# Foreign-key-aware legacy fixture validation

The Certificate and Consumable legacy tests exposed invalid fixture references after real foreign keys were installed. Certificate's shared add/update/clone fixture generated arbitrary integers for its technician and owner; it now creates two actual User records. Consumable's stock test purged its first group, then used that deleted group as the replacement when purging a second group; it now creates a separate remaining replacement and explicitly checks that the original group was removed. The force-purge argument is passed in the public API's second parameter.

Every original field, clone, stock-total, unused/used-count and assignment assertion remains. Replacement assertions follow the real replacement identity. Certificate_Item and Domain tests were run unchanged to cover associated asset links, domain cleanup, transfer and notification selection.

Validation on PostgreSQL 15.19 and MariaDB 10.11.18 against isolated populated lifecycle databases:

| Test selection | Before repair | After repair |
| --- | --- | --- |
| Certificate, Certificate_Item, Domain, Consumable | 14/14 methods executed; four exceptions; no void/skipped methods | 14/14 methods passed; 446 assertions; no void/skipped methods |

Both provider schema-check contracts passed after the tests. Their standalone CLI bootstrap emits the existing tester-fixture information warning because it does not add the legacy test bootstrap's fixture plugin directory. PHP lint and CS Fixer dry-run passed. No schema, migration or application behavior was changed by this fixture repair; no historical migration replay was repeated. Native MySQL 8.4 and remote CI were not run.

The exact command uses `php vendor/bin/atoum -p 'php -d memory_limit=512M' --debug --force-terminal --use-dot-report --bootstrap-file tests/bootstrap.php --no-code-coverage --max-children-number 1`, with `-f tests/functional/Certificate.php -f tests/functional/Certificate_Item.php -f tests/functional/Domain.php -f tests/functional/Consumable.php`, `GLPI_CONFIG_DIR` pointing to the provider's disposable configuration and `GLPI_VAR_DIR` to the legacy test files directory. Runner summaries, not process status alone, establish the result.

An independent public-API probe also confirmed a separate application defect: supplying a previously purged group as `_replace_by` throws the Consumable recipient foreign-key exception after deleting the source group's membership and audit history. On both providers, the source group and stock survived, but its one membership and two history records were removed; no outer transaction was active. The valid fixture repair does not resolve that failure.

The next application step is to reject invalid replacement identities before purge hooks and any audit/relationship mutation, then make the existing mapped lifecycle operation atomic on its supplied write connection. Validation must cover same-connection rollback, caller transaction/savepoint preservation, actual replacement scope and authorization, nullable/zero policies, typed recipients, tree reparenting and plugin cancellation. The separate legacy PostgreSQL numeric ILIKE dropdown regression is handled in the dropdown workstream.

The subsequent application repair is documented in [Atomic mapped deletion lifecycle](modernization-delete-lifecycle.md). The original before-repair probe above remains historical evidence; the valid fixture commit itself still contains no application repair.
