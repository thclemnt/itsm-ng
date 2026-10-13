<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\IPAddressParents;

use itsmng\Database\Migration\V220\OpenParentAdoption;

/** Final 2.2.0 ownership, copied directly from the released stored identity. */
final class Definition extends OpenParentAdoption
{
    public const PHASE = '2.2.0.ipaddresses.parents';
    public const TABLE = 'glpi_ipaddresses';
    protected const CHECK = 'glpi_ipaddresses_parent_kind';
    protected const LABEL = 'IP address';
    protected const LOWER_LABEL = 'IP address';
    protected const PARENT_LABEL = 'address parents';
    protected const OWNERS = [
        'NetworkName' => ['networknames_id', 'glpi_networknames', 'glpi_ipaddresses_networknames_id', 'fk_ipaddresses_networknames_id'],
    ];
}
