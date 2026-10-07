<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Index;
use itsmng\Database\PhysicalIndexSchema;

/** Frozen forward repair of missing physical FK lookup coverage; no row changes. */
final class PhysicalReferenceIndexes implements ReleaseMigration
{
    public const VERSION = 'schema.physical_reference_indexes.v1';

    // Captured from the 15426dad current property/association declarations.
    // Do not consult later entity metadata during historical replay.
    private const INDEXES = [
        ['glpi_apiclients', 'entities_id', 'IDX_D00BB2E4F4829AED'],
        ['glpi_businesscriticities', 'entities_id', 'IDX_5119F8B4F4829AED'],
        ['glpi_businesscriticities', 'businesscriticities_id', 'IDX_5119F8B4D723F691'],
        ['glpi_calendarsegments', 'entities_id', 'IDX_8021521DF4829AED'],
        ['glpi_computerantiviruses', 'manufacturers_id', 'IDX_68671079714AFAD6'],
        ['glpi_computervirtualmachines', 'virtualmachinetypes_id', 'IDX_6FDC320CFACD4096'],
        ['glpi_dashboards', 'profileId', 'IDX_7331D49BE4B388C'],
        ['glpi_dashboards', 'userId', 'IDX_7331D4925A316AF'],
        ['glpi_devicesensors', 'devicesensormodels_id', 'IDX_E8328652B9D9C45B'],
        ['glpi_displaypreferences', 'users_id', 'IDX_67F2BE713DB09D8'],
        ['glpi_documentcategories', 'documentcategories_id', 'IDX_44E98B1F9F4EDE47'],
        ['glpi_documents_items', 'entities_id', 'IDX_DDD24B25F4829AED'],
        ['glpi_domainrecordtypes', 'entities_id', 'IDX_19DBFAF6F4829AED'],
        ['glpi_domainrelations', 'entities_id', 'IDX_29A9192DF4829AED'],
        ['glpi_domaintypes', 'entities_id', 'IDX_C060118EF4829AED'],
        ['glpi_entities', 'authldaps_id', 'IDX_1A59F36F500D4AFA'],
        ['glpi_entities', 'calendars_id', 'IDX_1A59F36FBDBA0E81'],
        ['glpi_entities', 'entities_id_software', 'IDX_1A59F36FE9573678'],
        ['glpi_fieldblacklists', 'entities_id', 'IDX_2EF3241AF4829AED'],
        ['glpi_fieldunicities', 'entities_id', 'IDX_9CB981EEF4829AED'],
        ['glpi_holidays', 'entities_id', 'IDX_70D33686F4829AED'],
        ['glpi_ipnetworks', 'ipnetworks_id', 'IDX_2D47D3C8B0247248'],
        ['glpi_ipnetworks_vlans', 'vlans_id', 'IDX_35A7AD8AF96F069'],
        ['glpi_items_devicebatteries', 'computers_id', 'glpi_items_devicebatteries_computers_id_typed'],
        ['glpi_items_devicebatteries', 'locations_id', 'IDX_7DEDB6B56F283895'],
        ['glpi_items_devicebatteries', 'states_id', 'IDX_7DEDB6B5F104FBDD'],
        ['glpi_items_devicefirmwares', 'locations_id', 'IDX_FC12EA676F283895'],
        ['glpi_items_devicefirmwares', 'states_id', 'IDX_FC12EA67F104FBDD'],
        ['glpi_items_devicegenerics', 'locations_id', 'IDX_75A6BF0E6F283895'],
        ['glpi_items_devicegenerics', 'states_id', 'IDX_75A6BF0EF104FBDD'],
        ['glpi_items_deviceharddrives', 'computers_id', 'glpi_items_deviceharddrives_computers_id_typed'],
        ['glpi_items_devicememories', 'computers_id', 'glpi_items_devicememories_computers_id_typed'],
        ['glpi_items_devicemotherboards', 'computers_id', 'glpi_items_devicemotherboards_computers_id_typed'],
        ['glpi_items_devicepowersupplies', 'computers_id', 'glpi_items_devicepowersupplies_computers_id_typed'],
        ['glpi_items_deviceprocessors', 'computers_id', 'glpi_items_deviceprocessors_computers_id_typed'],
        ['glpi_items_devicesensors', 'computers_id', 'glpi_items_devicesensors_computers_id_typed'],
        ['glpi_items_devicesensors', 'locations_id', 'IDX_85EAB9D46F283895'],
        ['glpi_items_devicesensors', 'states_id', 'IDX_85EAB9D4F104FBDD'],
        ['glpi_items_kanbans', 'users_id', 'IDX_757D98D913DB09D8'],
        ['glpi_knowbaseitemcategories', 'knowbaseitemcategories_id', 'IDX_60FBD150A48699B3'],
        ['glpi_knowbaseitems_comments', 'knowbaseitems_id', 'IDX_33AB0631D8397986'],
        ['glpi_knowbaseitems_comments', 'parent_comment_id', 'IDX_33AB0631A6452818'],
        ['glpi_knowbaseitems_comments', 'users_id', 'IDX_33AB063113DB09D8'],
        ['glpi_knowbaseitems_items', 'knowbaseitems_id', 'IDX_BC1A9B08D8397986'],
        ['glpi_knowbaseitems_revisions', 'users_id', 'IDX_3B8DEF913DB09D8'],
        ['glpi_lines', 'groups_id', 'IDX_AC635CC04CBD296B'],
        ['glpi_lines', 'locations_id', 'IDX_AC635CC06F283895'],
        ['glpi_lines', 'states_id', 'IDX_AC635CC0F104FBDD'],
        ['glpi_lines', 'linetypes_id', 'IDX_AC635CC0C5805DC7'],
        ['glpi_networkaliases', 'fqdns_id', 'IDX_4F9E21DC16D0B945'],
        ['glpi_networkportwifis', 'networkportwifis_id', 'IDX_FB43456A1EB90C3F'],
        ['glpi_objectlocks', 'users_id', 'IDX_55A8E45D13DB09D8'],
        ['glpi_olalevels', 'entities_id', 'IDX_EC99B26DF4829AED'],
        ['glpi_olas', 'entities_id', 'IDX_B7FD34E5F4829AED'],
        ['glpi_queuedchats', 'notificationtemplates_id', 'IDX_7E072DC23E89F867'],
        ['glpi_queuedchats', 'locations_id', 'IDX_7E072DC26F283895'],
        ['glpi_queuedchats', 'groups_id', 'IDX_7E072DC24CBD296B'],
        ['glpi_queuedchats', 'itilcategories_id', 'IDX_7E072DC241ADC625'],
        ['glpi_queuednotifications', 'notificationtemplates_id', 'IDX_FDE960543E89F867'],
        ['glpi_slalevels', 'entities_id', 'IDX_A66D0308F4829AED'],
        ['glpi_slas', 'entities_id', 'IDX_AD32DCC2F4829AED'],
        ['glpi_softwarelicenses', 'softwarelicenses_id', 'IDX_8DF16B5819DD4FCC'],
        ['glpi_softwarelicensetypes', 'entities_id', 'IDX_D4B117C3F4829AED'],
        ['glpi_states', 'entities_id', 'IDX_B329E15CF4829AED'],
        ['glpi_states', 'states_id', 'IDX_B329E15CF104FBDD'],
        ['glpi_ticketrecurrents', 'calendars_id', 'IDX_8996AB3FBDBA0E81'],
        ['glpi_tickets_tickets', 'tickets_id_2', 'IDX_C1295DC58B13BB02'],
        ['glpi_users', 'default_requesttypes_id', 'IDX_F7E175BF7F248429'],
    ];

    public function version(): string
    {
        return self::VERSION;
    }

    /** Frozen DBAL declarations; names never substitute for column coverage. */
    public static function declarations(): array
    {
        $tables = [];
        foreach (self::INDEXES as [$table, $column, $name]) {
            // DBAL preserves quoted column identity across providers, including
            // the historical mixed-case dashboard profileId/userId columns.
            $tables[$table][] = new Index($name, ['`' . $column . '`']);
        }
        return $tables;
    }

    public function plan(Connection $connection): array
    {
        $required = self::declarations();
        $catalog = PhysicalIndexSchema::catalog($connection, array_keys($required));
        $sql = [];
        $missing = [];
        $platform = $connection->getDatabasePlatform();
        foreach (PhysicalIndexSchema::missing($required, $catalog) as $table => $indexes) {
            foreach ($indexes as $index) {
                // Frozen index names are unquoted: PostgreSQL folds them to lower
                // case; MySQL index identifiers compare without case sensitivity.
                $nativeName = strtolower($index->getName());
                $existing = $catalog[$table] ?? [];
                if (!$platform instanceof PostgreSQLPlatform) {
                    $existing = array_change_key_case($existing, CASE_LOWER);
                }
                if (isset($existing[$nativeName])) {
                    throw new \RuntimeException('Physical reference index name has a different definition: ' . $table . '.' . $index->getName());
                }
                $missing[] = $table . '.' . $index->getName();
                $sql[] = $platform->getCreateIndexSQL($index, $platform->quoteIdentifier($table));
            }
        }
        return ['missing_physical_indexes' => $missing, 'sql' => $sql];
    }

    public function apply(Connection $connection, ?callable $progress = null): void
    {
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform && $connection->isTransactionActive()) {
            throw new \RuntimeException('Physical index migration must run outside a MySQL application transaction.');
        }
        // Each completed CREATE remains discoverable on retry, including MySQL
        // implicit commits. No historical index is renamed or removed.
        foreach ($this->plan($connection)['sql'] as $sql) {
            $connection->executeStatement($sql);
            $progress && $progress('Created physical reference index');
        }
    }

    public function verify(Connection $connection): void
    {
        $required = self::declarations();
        $missing = PhysicalIndexSchema::missing($required, PhysicalIndexSchema::catalog($connection, array_keys($required)));
        if ($missing) {
            throw new \RuntimeException('Physical reference index migration did not converge: ' . implode(', ', array_keys($missing)));
        }
        // This index-only release does not supersede the preceding subject
        // policy or its retained native proof; terminal replay must retain it.
        (new SensorSubjects())->verify($connection);
    }
}
