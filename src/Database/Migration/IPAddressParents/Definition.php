<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\IPAddressParents;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use itsmng\Database\Migration\Frozen\V1\SingleOpenParentAdoption;

/** Frozen v1 declarations: exact NetworkName branch, with all other parents opaque. */
final class Definition extends SingleOpenParentAdoption
{
    public const PHASE = 'schema.ipaddress_parents.v1.ddl';
    protected const TABLE = 'glpi_ipaddresses';
    protected const CHECK = 'glpi_ipaddresses_parent_kind';
    protected const FOREIGN = 'fk_ipaddresses_networknames_id';
    protected const INDEX = 'glpi_ipaddresses_networknames_id';
    protected const OWNER = 'networknames_id';
    protected const TARGET = 'glpi_networknames';
    protected const KIND = 'NetworkName';
    protected const LABEL = 'IP address';
    protected const LOWER_LABEL = 'IP address';
    protected const PARENT_LABEL = 'address parents';

    public static function policy(AbstractPlatform $platform): array
    {
        $quote = $platform->quoteIdentifier(...);
        $kind = $quote('itemtype');
        if ($platform instanceof AbstractMySQLPlatform) {
            $kind = 'CAST(' . $kind . ' AS BINARY)';
        }
        $owner = $quote('networknames_id');
        $opaque = $quote('opaque_parent_id');
        return [
            'projection' => "CASE WHEN $kind IN ('NetworkName') THEN COALESCE($owner, 0) ELSE $opaque END",
            'constraint' => self::CHECK,
            'check' => "($kind IN ('NetworkName') AND ($owner IS NULL OR $owner > 0) AND $opaque IS NULL)"
                . " OR ($kind NOT IN ('NetworkName') AND $owner IS NULL AND $opaque IS NOT NULL)",
            'discriminators' => ['itemtype'],
            'integer_types' => ['opaque_parent_id' => 'bigint', 'networknames_id' => 'bigint'],
            'string_selections' => ['itemtype' => ['NetworkName']],
            'open_string_fallback' => true,
        ];
    }

}
