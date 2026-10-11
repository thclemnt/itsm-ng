<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\NetworkNameParents;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use itsmng\Database\Migration\Frozen\V1\SingleOpenParentAdoption;

/** Frozen v1 declarations: exact NetworkPort branch, with all other parents opaque. */
final class Definition extends SingleOpenParentAdoption
{
    public const PHASE = 'schema.networkname_parents.v1.ddl';
    protected const TABLE = 'glpi_networknames';
    protected const CHECK = 'glpi_networknames_parent_kind';
    protected const FOREIGN = 'fk_networknames_networkports_id';
    protected const INDEX = 'glpi_networknames_networkports_id';
    protected const OWNER = 'networkports_id';
    protected const TARGET = 'glpi_networkports';
    protected const KIND = 'NetworkPort';
    protected const LABEL = 'Network name';
    protected const LOWER_LABEL = 'network name';
    protected const PARENT_LABEL = 'name parents';

    public static function policy(AbstractPlatform $platform): array
    {
        $quote = $platform->quoteIdentifier(...);
        $kind = $quote('itemtype');
        if ($platform instanceof AbstractMySQLPlatform) {
            $kind = 'CAST(' . $kind . ' AS BINARY)';
        }
        $owner = $quote('networkports_id');
        $opaque = $quote('opaque_parent_id');
        return [
            'projection' => "CASE WHEN $kind IN ('NetworkPort') THEN COALESCE($owner, 0) ELSE $opaque END",
            'constraint' => self::CHECK,
            'check' => "($kind IN ('NetworkPort') AND ($owner IS NULL OR $owner > 0) AND $opaque IS NULL)"
                . " OR ($kind NOT IN ('NetworkPort') AND $owner IS NULL AND $opaque IS NOT NULL)",
            'discriminators' => ['itemtype'],
            'integer_types' => ['opaque_parent_id' => 'bigint', 'networkports_id' => 'bigint'],
            'string_selections' => ['itemtype' => ['NetworkPort']],
            'open_string_fallback' => true,
        ];
    }

}
