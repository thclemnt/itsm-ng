<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\ComponentParents;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use itsmng\Database\Migration\Frozen\V1\SingleOpenParentAdoption;

/** Frozen v1 declarations: exact Computer branch, with all other parents opaque. */
final class NetworkCardDefinition extends SingleOpenParentAdoption
{
    public const PHASE = 'schema.component_parents.v1.networkcard.ddl';
    protected const TABLE = 'glpi_items_devicenetworkcards';
    protected const CHECK = 'glpi_items_devicenetworkcards_parent_kind';
    protected const FOREIGN = 'fk_items_devicenetworkcards_computers_id';
    protected const INDEX = 'glpi_items_devicenetworkcards_computer_owner';
    protected const OWNER = 'computers_id';
    protected const TARGET = 'glpi_computers';
    protected const KIND = 'Computer';
    protected const LABEL = 'NetworkCard component';
    protected const LOWER_LABEL = 'NetworkCard component';
    protected const PARENT_LABEL = 'NetworkCard component parents';
    protected const DISCRIMINATOR_NULLABLE = true;
    protected const DISCRIMINATOR_LENGTH = 255;

    public static function policy(AbstractPlatform $platform): array
    {
        $quote = $platform->quoteIdentifier(...);
        $rawKind = $quote('itemtype');
        $kind = $rawKind;
        if ($platform instanceof AbstractMySQLPlatform) {
            $kind = 'CAST(' . $kind . ' AS BINARY)';
        }
        $owner = $quote('computers_id');
        $opaque = $quote('opaque_parent_id');
        return [
            'projection' => "CASE WHEN $kind IN ('Computer') THEN COALESCE($owner, 0) ELSE $opaque END",
            'constraint' => self::CHECK,
            'check' => "($rawKind IS NOT NULL AND $kind IN ('Computer') AND ($owner IS NULL OR $owner > 0) AND $opaque IS NULL)"
                . " OR (($rawKind IS NULL OR $kind NOT IN ('Computer')) AND $owner IS NULL AND $opaque IS NOT NULL)",
            'discriminators' => ['itemtype'],
            'integer_types' => ['opaque_parent_id' => 'bigint', 'computers_id' => 'bigint'],
            'string_selections' => ['itemtype' => ['Computer']],
            'open_string_fallback' => true,
        ];
    }

}
