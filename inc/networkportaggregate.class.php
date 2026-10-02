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

/// NetworkPortAggregate class : aggregate instantiation of NetworkPort. Aggregate can represent a
/// trunk on switch, specific port under that regroup several ethernet ports to manage Ethernet
/// Bridging.
/// @since 0.84
class NetworkPortAggregate extends NetworkPortInstantiation
{
    private ?array $pendingOrigins = null;

    private function originsRepository(): \itsmng\Database\Repository\NetworkPortAggregateRepository
    {
        global $DB;
        return new \itsmng\Database\Repository\NetworkPortAggregateRepository(\itsmng\Database\Orm::create($DB));
    }

    private function atomic(callable $operation)
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $connection->beginTransaction();
        $this->pendingOrigins = null;
        try {
            $result = $operation();
            $result === false ? $connection->rollBack() : $connection->commit();
            return $result;
        } catch (Throwable $error) {
            $connection->rollBack();
            throw $error;
        } finally {
            $this->pendingOrigins = null;
        }
    }

    public function add(array $input, $options = [], $history = true)
    {
        return $this->atomic(fn () => parent::add($input, $options, $history));
    }

    public function update(array $input, $history = 1, $options = [])
    {
        return $this->atomic(fn () => parent::update($input, $history, $options));
    }

    public function post_getEmpty()
    {
        parent::post_getEmpty();
        $this->fields['networkports_id_list'] = '[]';
    }

    public function post_getFromDB()
    {
        parent::post_getFromDB();
        $this->fields['networkports_id_list'] = json_encode($this->originsRepository()->originIds((int)$this->fields['id']), JSON_THROW_ON_ERROR);
    }

    private function saveOrigins(): void
    {
        if ($this->pendingOrigins !== null) {
            $this->originsRepository()->replaceOrigins((int)$this->fields['id'], $this->pendingOrigins);
            $this->fields['networkports_id_list'] = json_encode($this->pendingOrigins, JSON_THROW_ON_ERROR);
        }
    }

    public function post_addItem()
    {
        $this->saveOrigins();
        parent::post_addItem();
    }

    public function post_updateItem($history = 1)
    {
        $this->saveOrigins();
        parent::post_updateItem($history);
    }

    public function cleanDBonPurge()
    {
        $this->originsRepository()->removeForAggregate((int)$this->fields['id']);
        parent::cleanDBonPurge();
    }

    public static function getTypeName($nb = 0)
    {
        return __('Aggregation port');
    }


    public function prepareInputForAdd($input)
    {

        $input['networkports_id_list'] ??= [];
        $input = $this->prepareOrigins($input);
        return parent::prepareInputForAdd($input);
    }


    public function prepareInputForUpdate($input)
    {

        $input = $this->prepareOrigins($input);
        return parent::prepareInputForUpdate($input);
    }

    private function prepareOrigins(array $input): array
    {
        if (array_key_exists('networkports_id_list', $input)) {
            $values = $input['networkports_id_list'];
            if (is_string($values)) {
                $values = json_decode(\itsmng\Database\LegacyValues::decodeString($values), true, flags: JSON_THROW_ON_ERROR);
            }
            if (!is_array($values)) {
                throw new InvalidArgumentException('Aggregate origin selection requires an array');
            }
            $this->pendingOrigins = \itsmng\Database\Repository\NetworkPortAggregateRepository::origins($values);
            unset($input['networkports_id_list']);
        }
        return $input;
    }


    public function showInstantiationForm(NetworkPort $netport, $options, $recursiveItems)
    {
        global $DB;

        if (
            isset($this->fields['networkports_id_list'])
            && is_string($this->fields['networkports_id_list'])
        ) {
            $this->fields['networkports_id_list']
                           = importArrayFromDB($this->fields['networkports_id_list']);
        }

        $lastItem = $recursiveItems[count($recursiveItems) - 1];
        $possible_ports = [];
        $netport_types = ['NetworkPortEthernet', 'NetworkPortWifi'];
        foreach ($netport_types as $netport_type) {
            $iterator = new \itsmng\Database\RowIterator($this->originsRepository()->availablePorts($lastItem->getType(), (int)$lastItem->getID(), $netport_type));

            if (count($iterator)) {
                $array_element_name = call_user_func(
                    [$netport_type, 'getTypeName'],
                    count($iterator)
                );
                $possible_ports[$array_element_name] = [];

                while ($portEntry = $iterator->next()) {
                    $macAddresses[$portEntry['id']] = $portEntry['mac'];
                    if (!empty($portEntry['mac'])) {
                        $portEntry['name'] = sprintf(
                            __('%1$s - %2$s'),
                            $portEntry['name'],
                            $portEntry['mac']
                        );
                    }
                    $possible_ports[$array_element_name][$portEntry['id']] = $portEntry['name'];
                }
            }
        }
        $checklistOptions = [];
        foreach ($possible_ports as $key => $value) {
            $checklistOptions += $value;
        }

        return [
           $this->getTypeName() => [
              'visible' => true,
              'inputs' => [
                 __('MAC') => [
                    'type' => 'text',
                    'name' => 'mac',
                    'value' => $netport->fields['mac'],
                 ],
                 __('Origin port') => [
                    'type' => 'checklist',
                    'name' => 'networkports_id_list',
                    'options' => $checklistOptions,
                    'values' => $this->fields['networkports_id_list'],
                 ]
              ]
           ]
        ];
    }


    public function getInstantiationHTMLTableHeaders(
        HTMLTableGroup $group,
        HTMLTableSuperHeader $super,
        ?HTMLTableSuperHeader $internet_super = null,
        ?HTMLTableHeader $father = null,
        array $options = []
    ) {

        $group->addHeader('Origin', __('Origin port'), $super);

        parent::getInstantiationHTMLTableHeaders($group, $super, $internet_super, $father, $options);
        return null;
    }


    public function getInstantiationHTMLTable(
        NetworkPort $netport,
        HTMLTableRow $row,
        ?HTMLTableCell $father = null,
        array $options = []
    ) {

        if (
            isset($this->fields['networkports_id_list'])
            && is_string($this->fields['networkports_id_list'])
        ) {
            $this->fields['networkports_id_list']
                           = importArrayFromDB($this->fields['networkports_id_list']);
        }

        $row->addCell(
            $row->getHeaderByName('Instantiation', 'Origin'),
            $this->getInstantiationNetworkPortHTMLTable()
        );

        parent::getInstantiationHTMLTable($netport, $row, $father, $options);
        return null;
    }
}
