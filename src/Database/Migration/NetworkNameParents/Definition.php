<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\NetworkNameParents;

use itsmng\Database\Migration\V220\OpenParentAdoption;

/** Final 2.2.0 ownership, copied directly from the released stored identity. */
final class Definition extends OpenParentAdoption
{
    public const PHASE = '2.2.0.networknames.parents';
    public const TABLE = 'glpi_networknames';
    protected const CHECK = 'glpi_networknames_parent_kind';
    protected const LABEL = 'Network name';
    protected const LOWER_LABEL = 'network name';
    protected const PARENT_LABEL = 'name parents';
    protected const OWNERS = [
        'NetworkPort' => ['networkports_id', 'glpi_networkports', 'glpi_networknames_networkports_id', 'fk_networknames_networkports_id'],
    ];
}
