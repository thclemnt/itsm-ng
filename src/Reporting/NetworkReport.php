<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Reporting;

final class NetworkReport
{
    public static function addresses(\DBAdapter $db, string $portAlias): string
    {
        $platform = $db->getDoctrineConnection()->getDatabasePlatform();
        $q = $platform->quoteIdentifier(...);
        $name = $q('address.name');
        $aggregate = $db->getProvider() === 'pgsql'
            ? "STRING_AGG(DISTINCT $name, ',' ORDER BY $name)"
            : "GROUP_CONCAT(DISTINCT $name ORDER BY $name SEPARATOR ',')";
        $false = $db->getProvider() === 'pgsql' ? 'FALSE' : '0';
        return '(SELECT ' . $aggregate . ' FROM glpi_networknames netname'
            . ' JOIN glpi_ipaddresses address ON address.items_id = netname.id'
            . " AND address.itemtype = 'NetworkName' AND address.is_deleted = $false"
            . ' WHERE netname.items_id = ' . $q($portAlias . '.id')
            . " AND netname.itemtype = 'NetworkPort' AND netname.is_deleted = $false)";
    }
}
