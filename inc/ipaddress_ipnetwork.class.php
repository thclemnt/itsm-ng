<?php

/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2022 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Class IPAddress_IPNetwork : Connection between IPAddress and IPNetwork
 *
 * @since 0.84
**/
class IPAddress_IPNetwork extends CommonDBRelation
{
    // From CommonDBRelation
    public static $itemtype_1 = 'IPAddress';
    public static $items_id_1 = 'ipaddresses_id';

    public static $itemtype_2 = 'IPNetwork';
    public static $items_id_2 = 'ipnetworks_id';


    /**
     * Update IPNetwork's dependency
     *
     * @param $network IPNetwork object
    **/
    public static function linkIPAddressFromIPNetwork(IPNetwork $network)
    {
        global $DB;

        $linkObject    = new self();
        $ipnetworks_id = $network->getID();

        // First, remove all links of the current Network
        $ids = \itsmng\Database\MappedReads::identifiers($DB, self::getTable(), 'id', ['ipnetworks_id' => $ipnetworks_id]);
        foreach ($ids as $id) {
            $linkObject->delete(['id' => $id]);
        }

        // Then, look each IP address contained inside current Network
        $addresses = (new \itsmng\Database\Repository\IPNetworkRepository(\itsmng\Database\Orm::create($DB)))->containedAddresses((int)$ipnetworks_id);
        foreach ($addresses as $address) {
            $linkObject->add(['ipnetworks_id' => $ipnetworks_id, 'ipaddresses_id' => $address]);
        }
    }


    /**
     * @param $ipaddress IPAddress object
    **/
    public static function addIPAddress(IPAddress $ipaddress)
    {

        $linkObject = new self();
        $input      = ['ipaddresses_id' => $ipaddress->getID()];

        $entity         = $ipaddress->getEntityID();
        $ipnetworks_ids = IPNetwork::searchNetworksContainingIP($ipaddress, $entity);
        if ($ipnetworks_ids !== false) {
            // Beware that invalid IPaddresses don't have any valid address !
            $entity = $ipaddress->getEntityID();
            foreach (IPNetwork::searchNetworksContainingIP($ipaddress, $entity) as $ipnetworks_id) {
                $input['ipnetworks_id'] = $ipnetworks_id;
                $linkObject->add($input);
            }
        }
    }
}
