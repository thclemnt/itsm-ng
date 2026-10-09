<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\Migration\Version220;
use itsmng\Database\NativeTimestampSchema;

/** Verify the frozen conversion's actual owners; never inspect future entity metadata. */
final class Postconditions
{
    public static function assert(Connection $connection): void
    {
        if (Version220::pendingPhases(Ledger::states($connection))) {
            throw new \RuntimeException('The 2.2.0 transition has unfinished internal checkpoints.');
        }
        (new ExactDiscriminators())->verify($connection);
        (new References())->verify($connection);
        if ((new ForeignKeys(['glpi_domains' => ['suppliers_id' => 'glpi_suppliers']]))->plan($connection)) {
            throw new \RuntimeException('Frozen direct Domain supplier ownership did not converge.');
        }
        (new BooleanDomains())->verify($connection);
        foreach ([new ProjectAssets(), new ApplianceAssets(), new ApplianceRecipients(),
            new OperatingSystemSubjects(), new DomainDocuments(), new SoftwareInstallationSubjects(),
            new SoftwareLicenseSubjects(), new ProcessorSubjects(), new MotherboardSubjects(),
            new MemorySubjects(), new HardDriveSubjects(), new BatterySubjects(), new PowerSupplySubjects()] as $subject) {
            $subject->verify($connection);
        }
        $date = $connection->getDatabasePlatform()->quoteIdentifier('date_mod');
        $touch = ['glpi_objectlocks' => ['date_mod' => ['trigger' => 'glpi_objectlocks_date_mod_touch',
            'body' => 'BEGIN IF NEW IS DISTINCT FROM OLD AND NEW.' . $date . ' IS NOT DISTINCT FROM OLD.' . $date
                . ' THEN NEW.' . $date . ' = CURRENT_TIMESTAMP; END IF; RETURN NEW; END']]];
        $differences = NativeTimestampSchema::touchDifferences($connection, $touch);
        if ($differences) {
            throw new \RuntimeException(implode("\n", $differences));
        }
        // DBAL maps native MySQL TIMESTAMP and DATETIME to the same logical type.
        // This historical check takes its declarations from the frozen input.
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $types = [];
            foreach ($connection->fetchAllAssociative('SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, DATA_TYPE AS data_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $column) {
                $types[$column['table_name']][$column['column_name']] = $column['data_type'];
            }
            foreach ((new Baseline())->build($connection->getDatabasePlatform())->getTables() as $table) {
                foreach ($table->getColumns() as $column) {
                    if (str_starts_with($column->getColumnDefinition() ?? '', 'TIMESTAMP')
                        && ($types[$table->getName()][$column->getName()] ?? null) !== 'timestamp') {
                        throw new \RuntimeException('Expected frozen native TIMESTAMP: ' . $table->getName() . '.' . $column->getName());
                    }
                }
            }
        }
    }
}
