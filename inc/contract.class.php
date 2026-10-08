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

use itsmng\Database\Entity\Contract as ContractEntity;
use itsmng\Database\Entity\ContractItem;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ContractRepository;
use itsmng\Database\Repository\TransferBindingRepository;
use itsmng\Domain\ContractAlertOutcome;
use itsmng\Domain\ContractAlertPublisher;
use itsmng\Domain\ContractSchedule;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 *  Contract class
 */
class Contract extends CommonDBTM
{
    use Glpi\Features\Clonable;

    // From CommonDBTM
    public $dohistory                   = true;
    protected static $forward_entity_to = ['ContractCost'];

    public static $rightname                   = 'contract';
    protected $usenotepad               = true;

    public const RENEWAL_NEVER = 0;
    public const RENEWAL_TACIT = 1;
    public const RENEWAL_EXPRESS = 2;

    public function getCloneRelations(): array
    {
        return [
           Contract_Item::class,
           Contract_Supplier::class,
           ContractCost::class,
        ];
    }



    private static function repository(): ContractRepository
    {
        global $DB;
        return Orm::create($DB)->getRepository(ContractEntity::class);
    }

    /** Contract calendar labels use the same dates as selection and periodic scheduling. */
    public static function formatDeadline(array $fields, bool $notice = false, bool $color = false, bool $automaticRenewal = false): string
    {
        $deadline = ContractSchedule::fromFields($fields)->deadline($notice, $automaticRenewal);
        if ($deadline === null) {
            return '';
        }
        $label = Html::convDate($deadline->format('Y-m-d'));
        return $color && $deadline <= new DateTimeImmutable('today') ? "<span class='red'>" . $label . '</span>' : $label;
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Contract', 'Contracts', $nb);
    }


    public function post_getEmpty()
    {

        $this->fields["alert"] = Entity::getUsedConfig(
            "use_contracts_alert",
            $this->fields["entities_id"],
            "default_contract_alert",
            0
        );
        $this->fields["notice"] = 0;
    }


    public function cleanDBonPurge()
    {

        $this->deleteChildrenAndRelationsFromDb(
            [
              Contract_Item::class,
              Contract_Supplier::class,
              ContractCost::class,
            ]
        );

        // Alert does not extends CommonDBConnexity
        $alert = new Alert();
        $alert->cleanDBonItemDelete($this->getType(), $this->fields['id']);
    }


    public function defineTabs($options = [])
    {

        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addImpactTab($ong, $options);
        $this->addStandardTab('ContractCost', $ong, $options);
        $this->addStandardTab('Contract_Supplier', $ong, $options);
        $this->addStandardTab('Contract_Item', $ong, $options);
        $this->addStandardTab('Document_Item', $ong, $options);
        $this->addStandardTab('Link', $ong, $options);
        $this->addStandardTab('Notepad', $ong, $options);
        $this->addStandardTab('KnowbaseItem_Item', $ong, $options);
        $this->addStandardTab('Log', $ong, $options);

        return $ong;
    }

    /**
     * Duplicate all contracts from a item template to his clone
     *
     * @deprecated 9.5
     * @since 9.2
     *
     * @param string $itemtype      itemtype of the item
     * @param integer $oldid        ID of the item to clone
     * @param integer $newid        ID of the item cloned
     **/
    public static function cloneItem($itemtype, $oldid, $newid)
    {
        global $DB;

        Toolbox::deprecated('Use clone');
        $repository = TransferBindingRepository::contracts(Orm::create($DB));
        foreach ($repository->links($itemtype, (int)$oldid) as $link) {
            $cd = new Contract_Item();
            $data = ContractItem::withReference(['contracts_id' => $link['parent_id']], $itemtype, (int)$newid);
            $data = self::checkTemplateEntity($data, $data['items_id'], $data['itemtype']);
            $data             = Toolbox::addslashes_deep($data);

            $cd->add($data);
        }
    }


    public function pre_updateInDB()
    {

        // Clean end alert if begin_date is after old one
        // Or if duration is greater than old one
        if (
            (isset($this->oldvalues['begin_date'])
             && ($this->oldvalues['begin_date'] < $this->fields['begin_date']))
            || (isset($this->oldvalues['duration'])
                && ($this->oldvalues['duration'] < $this->fields['duration']))
        ) {
            $alert = new Alert();
            $alert->clear($this->getType(), $this->fields['id'], Alert::END);
        }

        // Clean notice alert if begin_date is after old one
        // Or if duration is greater than old one
        // Or if notice is lesser than old one
        if (
            (isset($this->oldvalues['begin_date'])
             && ($this->oldvalues['begin_date'] < $this->fields['begin_date']))
            || (isset($this->oldvalues['duration'])
                && ($this->oldvalues['duration'] < $this->fields['duration']))
            || (isset($this->oldvalues['notice'])
                && ($this->oldvalues['notice'] > $this->fields['notice']))
        ) {
            $alert = new Alert();
            $alert->clear($this->getType(), $this->fields['id'], Alert::NOTICE);
        }
    }


    /**
     * Print the contract form
     *
     * @param $ID        integer ID of the item
     * @param $options   array
     *     - target filename : where to go when done.
     *     - withtemplate boolean : template or basic item
     *
     *@return boolean item found
    **/
    public function showForm($ID, $options = [])
    {

        $form = [
           'action' => $this->getFormURL(),
           'itemtype' => $this::class,
           'content' => [
              __('Add a contract') => [
                 'visible' => true,
                 'inputs' => [
                    $this->isNewID($ID) ? [] : [
                       'type' => 'hidden',
                       'name' => 'id',
                       'value' => $ID
                    ],
                    __('Name') => [
                       'type' => 'text',
                       'name' => 'name',
                       'value' => $this->fields['name'],
                    ],
                    ContractType::getTypeName(1) => [
                       'type' => 'select',
                       'name' => 'contracttypes_id',
                       'values' => getOptionForItems('ContractType'),
                       'value' => $this->fields['contracttypes_id'],
                       'actions' => getItemActionButtons(['info', 'add'], "contracttype"),
                    ],
                    _x('phone', 'Number') => [
                       'type' => 'text',
                       'name' => 'num',
                       'value' => $this->fields['num'],
                    ],
                    __('Status') => [
                       'type' => 'select',
                       'name' => 'states_id',
                       'itemtype' => State::class,
                       'conditions' => ['is_visible_contract' => 1],
                       'value' => $this->fields['states_id'],
                    ],
                    __('Start date') => [
                       'type' => 'date',
                       'name' => 'begin_date',
                       'value' => $this->fields['begin_date'],
                    ],
                    __('Initial contract period') => [
                       'type' => 'number',
                       'name' => 'duration',
                       'min' => 0,
                       'max' => 120,
                       'step' => 1,
                       'after' => __('month') . (!empty($this->fields['begin_date']) ? ' -> ' . self::formatDeadline($this->fields, false, true, $this->fields['renewal'] == self::RENEWAL_TACIT) : ''),
                       'value' => $this->fields['duration'],
                    ],
                    __('Notice') => [
                       'type' => 'number',
                       'name' => 'notice',
                       'min' => 0,
                       'max' => 120,
                       'step' => 1,
                       'after' => __('month') . (!empty($this->fields['begin_date']) ? ' -> ' . self::formatDeadline($this->fields, true, true, $this->fields['renewal'] == self::RENEWAL_TACIT) : ''),
                       'value' => $this->fields['notice'],
                    ],
                    __('Account number') => [
                       'type' => 'text',
                       'name' => 'accounting_number',
                       'value' => $this->fields['accounting_number'],
                    ],
                    __('Contract renewal period') => [
                       'type' => 'number',
                       'name' => 'periodicity',
                       'min' => 1,
                       'max' => 60,
                       'step' => 1,
                       'after' => __('month'),
                       'value' => $this->fields['periodicity'],
                    ],
                    __('Invoice period') => [
                       'type' => 'number',
                       'name' => 'billing',
                       'min' => 1,
                       'max' => 60,
                       'step' => 1,
                       'after' => __('month'),
                       'value' => $this->fields['billing'],
                    ],
                    __('Renewal') => [
                       'type' => 'select',
                       'name' => 'renewal',
                       'values' => [
                          self::RENEWAL_NEVER => __('Never'),
                          self::RENEWAL_TACIT => __('Tacit'),
                          self::RENEWAL_EXPRESS => __('Express'),
                       ],
                       'value' => $this->fields['renewal'],
                    ],
                    __('Max number of items') => [
                       'type' => 'number',
                       'name' => 'max_links_allowed',
                       'min' => 1,
                       'max' => 200000,
                       'step' => 1,
                       'AFTER' => "(0:" . __('Unlimited'),
                       'value' => $this->fields['max_links_allowed'],
                    ],
                    __('Email alarms') => (Entity::getUsedConfig("use_contracts_alert", $this->fields["entities_id"])) ?
                       [
                          'type' => 'select',
                          'name' => 'alert',
                          'values' => [
                             Alert::END => __('End'),
                             Alert::NOTICE => __('Notice'),
                          ],
                          'value' => $this->fields['alert'],
                       ] : [],
                    __('Comments') => [
                       'type' => 'textarea',
                       'name' => 'comment',
                       'value' => $this->fields['comment'],
                    ],
                 ]
              ],
              __('Support hours') => [
               'visible' => true,
               'inputs' => [
                  __('on week start') => [
                       'type' => 'time',
                       'name' => 'week_begin_hour',
                       'value' => $this->fields['week_begin_hour'],
                       'col_lg' => 6,
                  ],
                  __('on week end') => [
                       'type' => 'time',
                       'name' => 'week_end_hour',
                       'value' => $this->fields['week_end_hour'],
                       'col_lg' => 6,
                  ],
                  __('on Saturday') => [
                       'type' => 'checkbox',
                       'name' => 'use_saturday',
                       'value' => $this->fields['use_saturday'],
                  ],
                  __('on Saturday start') => [
                       'type' => 'time',
                       'name' => 'saturday_begin_hour',
                       'value' => $this->fields['saturday_begin_hour'],
                  ],
                  __('on Saturday end') => [
                       'type' => 'time',
                       'name' => 'saturday_end_hour',
                       'value' => $this->fields['saturday_end_hour'],
                  ],
                  __('Sundays and holidays') => [
                       'type' => 'checkbox',
                       'name' => 'use_monday',
                       'value' => $this->fields['use_monday'],
                  ],
                  __('on Sunday start') => [
                       'type' => 'time',
                       'name' => 'monday_begin_hour',
                       'value' => $this->fields['monday_begin_hour'],
                  ],
                  __('on Sunday end') => [
                       'type' => 'time',
                       'name' => 'monday_end_hour',
                       'value' => $this->fields['monday_end_hour'],
                  ],
               ]
              ]
           ]
        ];
        renderTwigForm($form, '', $this->fields);

        return true;
    }


    public static function rawSearchOptionsToAdd()
    {
        global $DB;

        $tab = [];

        $joinparams = [
           'beforejoin' => [
              'table'      => 'glpi_contracts_items',
              'joinparams' => [
                 'jointype' => 'itemtype_item'
              ]
           ]
        ];

        $joinparamscost = [
           'jointype'   => 'child',
           'beforejoin' => [
              'table'      => 'glpi_contracts',
              'joinparams' => $joinparams
           ]
        ];

        $tab[] = [
           'id'                 => 'contract',
           'name'               => self::getTypeName(Session::getPluralNumber())
        ];

        $tab[] = [
           'id'                 => '139',
           'table'              => 'glpi_contracts_items',
           'field'              => 'id',
           'name'               => _x('quantity', 'Number of contracts'),
           'forcegroupby'       => true,
           'usehaving'          => true,
           'datatype'           => 'count',
           'massiveaction'      => false,
           'joinparams'         => [
              'jointype'           => 'itemtype_item'
           ]
        ];

        $tab[] = [
           'id'                 => '29',
           'table'              => 'glpi_contracts',
           'field'              => 'name',
           'name'               => __('Name'),
           'forcegroupby'       => true,
           'datatype'           => 'itemlink',
           'massiveaction'      => false,
           'joinparams'         => $joinparams
        ];

        $tab[] = [
           'id'                 => '30',
           'table'              => 'glpi_contracts',
           'field'              => 'num',
           'name'               => __('Number'),
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => $joinparams,
           'datatype'           => 'string'
        ];

        $tab[] = [
           'id'                 => '129',
           'table'              => 'glpi_contracttypes',
           'field'              => 'name',
           'name'               => _n('Type', 'Types', 1),
           'datatype'           => 'dropdown',
           'massiveaction'      => false,
           'joinparams'         => [
              'beforejoin'         => [
                 'table'              => 'glpi_contracts',
                 'joinparams'         => $joinparams
              ]
           ]
        ];

        $tab[] = [
           'id'                 => '130',
           'table'              => 'glpi_contracts',
           'field'              => 'duration',
           'name'               => __('Duration'),
           'datatype'           => 'number',
           'max'                => '120',
           'unit'               => 'month',
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => $joinparams
        ];

        $tab[] = [
           'id'                 => '131',
           'table'              => 'glpi_contracts',
           'field'              => 'periodicity',
                                   //TRANS: %1$s is Contract, %2$s is field name
           'name'               => __('Periodicity'),
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => $joinparams,
           'datatype'           => 'number',
           'min'                => '12',
           'max'                => '60',
           'step'               => '12',
           'toadd'              => [
              0 => Dropdown::EMPTY_VALUE,
              1 => sprintf(_n('%d month', '%d months', 1), 1),
              2 => sprintf(_n('%d month', '%d months', 2), 2),
              3 => sprintf(_n('%d month', '%d months', 3), 3),
              6 => sprintf(_n('%d month', '%d months', 6), 6)
           ],
           'unit'               => 'month'
        ];

        $tab[] = [
           'id'                 => '132',
           'table'              => 'glpi_contracts',
           'field'              => 'begin_date',
           'name'               => __('Start date'),
           'forcegroupby'       => true,
           'datatype'           => 'date',
           'maybefuture'        => true,
           'massiveaction'      => false,
           'joinparams'         => $joinparams
        ];

        $tab[] = [
           'id'                 => '133',
           'table'              => 'glpi_contracts',
           'field'              => 'accounting_number',
           'name'               => __('Account number'),
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'datatype'           => 'string',
           'joinparams'         => $joinparams,
           'autocomplete'       => true,
        ];

        $tab[] = [
           'id'                 => '134',
           'table'              => 'glpi_contracts',
           'field'              => 'end_date',
           'name'               => __('End date'),
           'forcegroupby'       => true,
           'datatype'           => 'date_delay',
           'maybefuture'        => true,
           'datafields'         => [
              '1'                  => 'begin_date',
              '2'                  => 'duration'
           ],
           'searchunit'         => 'MONTH',
           'delayunit'          => 'MONTH',
           'massiveaction'      => false,
           'joinparams'         => $joinparams
        ];

        $tab[] = [
           'id'                 => '135',
           'table'              => 'glpi_contracts',
           'field'              => 'notice',
           'name'               => __('Notice'),
           'datatype'           => 'number',
           'max'                => '120',
           'unit'               => 'month',
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => $joinparams
        ];

        $tab[] = [
           'id'                 => '136',
           'table'              => 'glpi_contractcosts',
           'field'              => 'totalcost',
           'name'               => _n('Cost', 'Costs', 1),
           'forcegroupby'       => true,
           'usehaving'          => true,
           'datatype'           => 'decimal',
           'massiveaction'      => false,
           'joinparams'         => $joinparamscost,
           'computation'        =>
              '(SUM(' . $DB->quoteName('TABLE.cost') . ') / COUNT(' .
              $DB->quoteName('TABLE.id') . ')) * COUNT(DISTINCT ' .
              $DB->quoteName('TABLE.id') . ')',
           'nometa'             => true, // cannot GROUP_CONCAT a SUM
        ];

        $tab[] = [
           'id'                 => '137',
           'table'              => 'glpi_contracts',
           'field'              => 'billing',
           'name'               => __('Invoice period'),
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => $joinparams,
           'datatype'           => 'number',
           'min'                => '12',
           'max'                => '60',
           'step'               => '12',
           'toadd'              => [
              0 => Dropdown::EMPTY_VALUE,
              1 => sprintf(_n('%d month', '%d months', 1), 1),
              2 => sprintf(_n('%d month', '%d months', 2), 2),
              3 => sprintf(_n('%d month', '%d months', 3), 3),
              6 => sprintf(_n('%d month', '%d months', 6), 6)
           ],
           'unit'               => 'month'
        ];

        $tab[] = [
           'id'                 => '138',
           'table'              => 'glpi_contracts',
           'field'              => 'renewal',
           'name'               => __('Renewal'),
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => $joinparams,
           'datatype'           => 'specific'
        ];

        return $tab;
    }


    public function getSpecificMassiveActions($checkitem = null)
    {

        $isadmin = static::canUpdate();
        $actions = parent::getSpecificMassiveActions($checkitem);

        if ($isadmin) {
            $prefix                    = 'Contract_Item' . MassiveAction::CLASS_ACTION_SEPARATOR;
            $actions[$prefix . 'add']    = _x('button', 'Add an item');
            $actions[$prefix . 'remove'] = _x('button', 'Remove an item');
        }

        return $actions;
    }


    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {

        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        switch ($field) {
            case 'alert':
                $options['name']  = $name;
                $options['value'] = $values[$field];
                return self::dropdownAlert($options);

            case 'renewal':
                $options['name']  = $name;
                return self::dropdownContractRenewal($name, $values[$field], false);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }


    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {

        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'alert':
                return self::getAlertName($values[$field]);

            case 'renewal':
                return self::getContractRenewalName($values[$field]);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }


    public function rawSearchOptions()
    {
        global $DB;

        $tab = [];

        $tab[] = [
           'id'                 => 'common',
           'name'               => __('Characteristics')
        ];

        $tab[] = [
           'id'                 => '1',
           'table'              => $this->getTable(),
           'field'              => 'name',
           'name'               => __('Name'),
           'datatype'           => 'itemlink',
           'massiveaction'      => false,
           'autocomplete'       => true,
        ];

        $tab[] = [
           'id'                 => '2',
           'table'              => $this->getTable(),
           'field'              => 'id',
           'name'               => __('ID'),
           'massiveaction'      => false,
           'datatype'           => 'number'
        ];

        $tab[] = [
           'id'                 => '3',
           'table'              => $this->getTable(),
           'field'              => 'num',
           'name'               => _x('phone', 'Number'),
           'datatype'           => 'string',
           'autocomplete'       => true,
        ];

        $tab[] = [
           'id'                 => '31',
           'table'              => 'glpi_states',
           'field'              => 'completename',
           'name'               => __('Status'),
           'datatype'           => 'dropdown',
           'condition'          => ['is_visible_contract' => 1]
        ];

        $tab[] = [
           'id'                 => '4',
           'table'              => 'glpi_contracttypes',
           'field'              => 'name',
           'name'               => _n('Type', 'Types', 1),
           'datatype'           => 'dropdown'
        ];

        $tab[] = [
           'id'                 => '5',
           'table'              => $this->getTable(),
           'field'              => 'begin_date',
           'name'               => __('Start date'),
           'datatype'           => 'date',
           'maybefuture'        => true
        ];

        $tab[] = [
           'id'                 => '6',
           'table'              => $this->getTable(),
           'field'              => 'duration',
           'name'               => __('Duration'),
           'datatype'           => 'number',
           'max'                => 120,
           'unit'               => 'month'
        ];

        $tab[] = [
           'id'                 => '19',
           'table'              => $this->getTable(),
           'field'              => 'date_mod',
           'name'               => __('Last update'),
           'datatype'           => 'datetime',
           'massiveaction'      => false
        ];

        $tab[] = [
           'id'                 => '121',
           'table'              => $this->getTable(),
           'field'              => 'date_creation',
           'name'               => __('Creation date'),
           'datatype'           => 'datetime',
           'massiveaction'      => false
        ];

        $tab[] = [
           'id'                 => '20',
           'table'              => $this->getTable(),
           'field'              => 'end_date',
           'name'               => __('End date'),
           'datatype'           => 'date_delay',
           'datafields'         => [
              '1'                  => 'begin_date',
              '2'                  => 'duration'
           ],
           'searchunit'         => 'MONTH',
           'delayunit'          => 'MONTH',
           'maybefuture'        => true,
           'massiveaction'      => false
        ];

        $tab[] = [
           'id'                 => '7',
           'table'              => $this->getTable(),
           'field'              => 'notice',
           'name'               => __('Notice'),
           'datatype'           => 'number',
           'max'                => 120,
           'unit'               => 'month'
        ];

        $tab[] = [
           'id'                 => '21',
           'table'              => $this->getTable(),
           'field'              => 'periodicity',
           'name'               => __('Periodicity'),
           'massiveaction'      => false,
           'datatype'           => 'number',
           'min'                => 12,
           'max'                => 60,
           'step'               => 12,
           'toadd'              => [
              0 => Dropdown::EMPTY_VALUE,
              1 => sprintf(_n('%d month', '%d months', 1), 1),
              2 => sprintf(_n('%d month', '%d months', 2), 2),
              3 => sprintf(_n('%d month', '%d months', 3), 3),
              6 => sprintf(_n('%d month', '%d months', 6), 6)
           ],
           'unit'               => 'month'
        ];

        $tab[] = [
           'id'                 => '22',
           'table'              => $this->getTable(),
           'field'              => 'billing',
           'name'               => __('Invoice period'),
           'massiveaction'      => false,
           'datatype'           => 'number',
           'min'                => 12,
           'max'                => 60,
           'step'               => 12,
           'toadd'              => [
              0 => Dropdown::EMPTY_VALUE,
              1 => sprintf(_n('%d month', '%d months', 1), 1),
              2 => sprintf(_n('%d month', '%d months', 2), 2),
              3 => sprintf(_n('%d month', '%d months', 3), 3),
              6 => sprintf(_n('%d month', '%d months', 6), 6)
           ],
           'unit'               => 'month'
        ];

        $tab[] = [
           'id'                 => '10',
           'table'              => $this->getTable(),
           'field'              => 'accounting_number',
           'name'               => __('Account number'),
           'datatype'           => 'string'
        ];

        $tab[] = [
           'id'                 => '23',
           'table'              => $this->getTable(),
           'field'              => 'renewal',
           'name'               => __('Renewal'),
           'massiveaction'      => false,
           'datatype'           => 'specific',
           'searchtype'         => ['equals', 'notequals']
        ];

        $tab[] = [
           'id'                 => '12',
           'table'              => $this->getTable(),
           'field'              => 'expire',
           'name'               => __('Expiration'),
           'datatype'           => 'date_delay',
           'datafields'         => [
              '1'                  => 'begin_date',
              '2'                  => 'duration'
           ],
           'searchunit'         => 'DAY',
           'delayunit'          => 'MONTH',
           'maybefuture'        => true,
           'massiveaction'      => false
        ];

        $tab[] = [
           'id'                 => '13',
           'table'              => $this->getTable(),
           'field'              => 'expire_notice',
           'name'               => __('Expiration date + notice'),
           'datatype'           => 'date_delay',
           'datafields'         => [
              '1'                  => 'begin_date',
              '2'                  => 'duration',
              '3'                  => 'notice'
           ],
           'searchunit'         => 'DAY',
           'delayunit'          => 'MONTH',
           'maybefuture'        => true,
           'massiveaction'      => false
        ];

        $tab[] = [
           'id'                 => '16',
           'table'              => $this->getTable(),
           'field'              => 'comment',
           'name'               => __('Comments'),
           'datatype'           => 'text'
        ];

        $tab[] = [
           'id'                 => '80',
           'table'              => 'glpi_entities',
           'field'              => 'completename',
           'name'               => Entity::getTypeName(1),
           'massiveaction'      => false,
           'datatype'           => 'dropdown'
        ];

        $tab[] = [
           'id'                 => '59',
           'table'              => $this->getTable(),
           'field'              => 'alert',
           'name'               => __('Email alarms'),
           'datatype'           => 'specific',
           'searchtype'         => ['equals', 'notequals']
        ];

        $tab[] = [
           'id'                 => '86',
           'table'              => $this->getTable(),
           'field'              => 'is_recursive',
           'name'               => __('Child entities'),
           'datatype'           => 'bool'
        ];

        $tab[] = [
           'id'                 => '72',
           'table'              => 'glpi_contracts_items',
           'field'              => 'id',
           'name'               => _x('quantity', 'Number of items'),
           'forcegroupby'       => true,
           'usehaving'          => true,
           'datatype'           => 'count',
           'massiveaction'      => false,
           'joinparams'         => [
              'jointype'           => 'child'
           ]
        ];

        $tab[] = [
           'id'                 => '29',
           'table'              => 'glpi_suppliers',
           'field'              => 'name',
           'name'               => _n(
               'Associated supplier',
               'Associated suppliers',
               Session::getPluralNumber()
           ),
           'forcegroupby'       => true,
           'datatype'           => 'itemlink',
           'massiveaction'      => false,
           'joinparams'         => [
              'beforejoin'         => [
                 'table'              => 'glpi_contracts_suppliers',
                 'joinparams'         => [
                    'jointype'           => 'child'
                 ]
              ]
           ]
        ];

        $tab[] = [
           'id'                 => '50',
           'table'              => $this->getTable(),
           'field'              => 'template_name',
           'name'               => __('Template name'),
           'datatype'           => 'text',
           'massiveaction'      => false,
           'nosearch'           => true,
           'nodisplay'          => true,
           'autocomplete'       => true,
        ];

        // add objectlock search options
        $tab = array_merge($tab, ObjectLock::rawSearchOptionsToAdd(get_class($this)));

        $tab = array_merge($tab, Notepad::rawSearchOptionsToAdd());

        $tab[] = [
           'id'                 => 'cost',
           'name'               => _n('Cost', 'Costs', 1)
        ];

        $tab[] = [
           'id'                 => '11',
           'table'              => 'glpi_contractcosts',
           'field'              => 'totalcost',
           'name'               => __('Total cost'),
           'datatype'           => 'decimal',
           'forcegroupby'       => true,
           'usehaving'          => true,
           'massiveaction'      => false,
           'joinparams'         => [
              'jointype'           => 'child'
           ],
           'computation'        =>
              '(SUM(' . $DB->quoteName('TABLE.cost') . ') / COUNT(' .
              $DB->quoteName('TABLE.id') . ')) * COUNT(DISTINCT ' .
              $DB->quoteName('TABLE.id') . ')',
           'nometa'             => true, // cannot GROUP_CONCAT a SUM
        ];

        $tab[] = [
           'id'                 => '41',
           'table'              => 'glpi_contractcosts',
           'field'              => 'cost',
           'name'               => _n('Cost', 'Costs', Session::getPluralNumber()),
           'datatype'           => 'decimal',
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => [
              'jointype'           => 'child'
           ]
        ];

        $tab[] = [
           'id'                 => '42',
           'table'              => 'glpi_contractcosts',
           'field'              => 'begin_date',
           'name'               => sprintf(__('%1$s - %2$s'), _n('Cost', 'Costs', 1), __('Begin date')),
           'datatype'           => 'date',
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => [
              'jointype'           => 'child'
           ]
        ];

        $tab[] = [
           'id'                 => '43',
           'table'              => 'glpi_contractcosts',
           'field'              => 'end_date',
           'name'               => sprintf(__('%1$s - %2$s'), _n('Cost', 'Costs', 1), __('End date')),
           'datatype'           => 'date',
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => [
              'jointype'           => 'child'
           ]
        ];

        $tab[] = [
           'id'                 => '44',
           'table'              => 'glpi_contractcosts',
           'field'              => 'name',
           'name'               => sprintf(__('%1$s - %2$s'), _n('Cost', 'Costs', 1), __('Name')),
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => [
              'jointype'           => 'child'
           ],
           'datatype'           => 'dropdown'
        ];

        $tab[] = [
           'id'                 => '45',
           'table'              => 'glpi_budgets',
           'field'              => 'name',
           'name'               => sprintf(__('%1$s - %2$s'), _n('Cost', 'Costs', 1), Budget::getTypeName(1)),
           'datatype'           => 'dropdown',
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => [
              'beforejoin'         => [
                 'table'              => 'glpi_contractcosts',
                 'joinparams'         => [
                    'jointype'           => 'child'
                 ]
              ]
           ]
        ];

        return $tab;
    }


    /**
     * Show central contract resume
     * HTML array
     *
     * @return void
     **/
    public static function showCentral()
    {
        global $CFG_GLPI;

        if (!Contract::canView()) {
            return;
        }

        // Dashboard counts use exact active ownership, preserving nonrecursive buckets.
        $counts = self::repository()->deadlineCounts(getEntitiesRestrictCriteria(self::getTable()));
        $contract0 = $counts['expired'];
        $contract7 = $counts['ending7'];
        $contract30 = $counts['ending30'];
        $contractpre7 = $counts['notice7'];
        $contractpre30 = $counts['notice30'];

        echo "<table class='tab_cadrehov' aria-label='Contracts Table'>";
        echo "<tr class='noHover'><th colspan='2'>";
        echo "<p class='table-title mt-0'><a href=\"" . $CFG_GLPI["root_doc"] . "/front/contract.php?reset=reset\">" .
               self::getTypeName(1) . "</a></p></th></tr>";

        echo "<tr class='tab_bg_2'>";
        $options = [
           'reset' => 'reset',
           'sort'  => 12,
           'order' => 'DESC',
           'start' => 0,
           'criteria' => [
              [
                 'field'      => 12,
                 'value'      => '<0',
                 'searchtype' => 'contains',
              ],
              [
                 'field'      => 12,
                 'link'       => 'AND',
                 'value'      => '>-30',
                 'searchtype' => 'contains',
              ]
           ]
        ];
        echo "<td><a href=\"" . $CFG_GLPI["root_doc"] . "/front/contract.php?" .
                   Toolbox::append_params($options, '&amp;') . "\">" .
                   __('Contracts expired in the last 30 days') . "</a> </td>";
        echo "<td class='numeric'>" . $contract0 . "</td></tr>";

        echo "<tr class='tab_bg_2'>";
        $options['criteria'][0]['value'] = '>0';
        $options['criteria'][1]['value'] = '<7';
        echo "<td><a href=\"" . $CFG_GLPI["root_doc"] . "/front/contract.php?" .
                   Toolbox::append_params($options, '&amp;') . "\">" .
                   __('Contracts expiring in less than 7 days') . "</a></td>";
        echo "<td class='numeric'>" . $contract7 . "</td></tr>";

        echo "<tr class='tab_bg_2'>";
        $options['criteria'][0]['value'] = '>6';
        $options['criteria'][1]['value'] = '<30';
        echo "<td><a href=\"" . $CFG_GLPI["root_doc"] . "/front/contract.php?" .
                   Toolbox::append_params($options, '&amp;') . "\">" .
                   __('Contracts expiring in less than 30 days') . "</a></td>";
        echo "<td class='numeric'>" . $contract30 . "</td></tr>";

        echo "<tr class='tab_bg_2'>";
        $options['criteria'][0]['field'] = 13;
        $options['criteria'][0]['value'] = '>0';
        $options['criteria'][1]['field'] = 13;
        $options['criteria'][1]['value'] = '<7';

        echo "<td><a href=\"" . $CFG_GLPI["root_doc"] . "/front/contract.php?" .
                   Toolbox::append_params($options, '&amp;') . "\">" .
                   __('Contracts where notice begins in less than 7 days') . "</a></td>";
        echo "<td class='numeric'>" . $contractpre7 . "</td></tr>";

        echo "<tr class='tab_bg_2'>";
        $options['criteria'][0]['value'] = '>6';
        $options['criteria'][1]['value'] = '<30';
        echo "<td><a href=\"" . $CFG_GLPI["root_doc"] . "/front/contract.php?" .
                   Toolbox::append_params($options, '&amp;') . "\">" .
                   __('Contracts where notice begins in less than 30 days') . "</a></td>";
        echo "<td class='numeric'>" . $contractpre30 . "</td></tr>";
        echo "</table>";
    }


    /**
     * Get the entreprise name  for the contract
     *
     *@return string of names (HTML)
    **/
    public function getSuppliersNames()
    {
        $out = '';
        foreach (self::repository()->supplierNames((int)$this->getID(), Session::haveTranslations('Supplier', 'name') ? ($_SESSION['glpilanguage'] ?? '') : null) as $name) {
            $out .= (empty($name) ? '&nbsp;' : $name) . '<br>';
        }
        return $out;
    }


    public static function cronInfo($name)
    {
        return ['description' => __('Send alarms on contracts')];
    }


    /**
     * Cron action on contracts : alert depending of the config : on notice and expire
     *
     * @param CronTask $task CronTask for log, if NULL display (default NULL)
     *
     * @return integer
    **/
    public static function cronContract(?CronTask $task = null)
    {
        global $DB, $CFG_GLPI;

        if (!$CFG_GLPI["use_notifications"]) {
            return 0;
        }

        $message       = [];
        $cron_status   = 0;

        $contract_infos = [];
        $contract_messages = [];

        $repository = self::repository();
        $publisher = new ContractAlertPublisher($DB);
        foreach (Entity::getEntitiesToNotify('use_contracts_alert') as $entity => $value) {
            $before       = Entity::getUsedConfig('send_contracts_alert_before_delay', $entity);

            foreach (['notice' => Alert::NOTICE, 'end' => Alert::END] as $type => $event) {
                foreach ($repository->notificationCandidates((int)$entity, $event, (int)$before) as $data) {
                    $entity  = $data['entities_id'];

                    $message = sprintf(
                        __('%1$s: %2$s') . "<br>\n",
                        $data["name"],
                        self::formatDeadline($data, $type === 'notice')
                    );
                    $data['items']      = Contract_Item::getItemsForContract($data['id'], $entity);
                    $contract_infos[$type][$entity][$data['id']] = $data;

                    if (!isset($contract_messages[$type][$entity])) {
                        switch ($type) {
                            case 'notice':
                                $contract_messages[$type][$entity] = __('Contract entered in notice time') .
                                                                     "<br>";
                                break;

                            case 'end':
                                $contract_messages[$type][$entity] = __('Contract ended') . "<br>";
                                break;
                        }
                    }
                    $contract_messages[$type][$entity] .= $message;
                }
            }

            foreach ($repository->periodicContracts((int)$entity) as $data) {
                $schedule = ContractSchedule::fromFields($data);
                $todo = ['periodicity' => Alert::PERIODICITY];
                if ($data['alert'] & (1 << Alert::NOTICE)) {
                    $todo['periodicitynotice'] = Alert::NOTICE;
                }
                foreach ($todo as $type => $event) {
                    $previous = $data[$event === Alert::NOTICE ? 'last_notice' : 'last_period'];
                    $deadline = $schedule->duePeriod((int)$before, $previous, $event === Alert::NOTICE);
                    if ($deadline !== null) {
                        $data['alert_date'] = $deadline->format('Y-m-d');
                        $contract_infos[$type][$entity][$data['id']] = $data;
                        if (!isset($contract_messages[$type][$entity])) {
                            $contract_messages[$type][$entity] = ($type === 'periodicitynotice'
                                ? __('Contract entered in notice time for period')
                                : __('Contract period ended')) . '<br>';
                        }
                        $contract_messages[$type][$entity] .= sprintf(__('%1$s: %2$s') . "<br>\n", $data['name'], Html::convDate($data['alert_date']));
                    }
                }
            }
        }

        foreach (
            ['notice'            => Alert::NOTICE,
                    'end'               => Alert::END,
                    'periodicity'       => Alert::PERIODICITY,
                    'periodicitynotice' => Alert::NOTICE] as $event => $type
        ) {
            if (isset($contract_infos[$event]) && count($contract_infos[$event])) {
                foreach ($contract_infos[$event] as $entity => $contracts) {
                    $outcome = $publisher->publish($event, $type, (int)$entity, $contracts, in_array($event, ['periodicity', 'periodicitynotice'], true));
                    if ($outcome === ContractAlertOutcome::Skipped) {
                        continue;
                    }
                    if ($outcome === ContractAlertOutcome::Published) {
                        $message     = $contract_messages[$event][$entity];
                        $cron_status = 1;
                        $entityname  = Dropdown::getDropdownName("glpi_entities", $entity);
                        if ($task) {
                            $task->log(sprintf(__('%1$s: %2$s') . "\n", $entityname, $message));
                            $task->addVolume(1);
                        } else {
                            Session::addMessageAfterRedirect(sprintf(
                                __('%1$s: %2$s'),
                                $entityname,
                                $message
                            ));
                        }

                    } else {
                        $entityname = Dropdown::getDropdownName('glpi_entities', $entity);
                        //TRANS: %1$s is entity name, %2$s is the message
                        $msg = sprintf(__('%1$s: %2$s'), $entityname, __('send contract alert failed'));
                        if ($task) {
                            $task->log($msg);
                        } else {
                            Session::addMessageAfterRedirect($msg, false, ERROR);
                        }
                    }
                }
            }
        }

        return $cron_status;
    }


    /**
     * Print a select with contracts
     *
     * Print a select named $name with contracts options and selected value $value
     * @param array $options
     *    - name          : string / name of the select (default is contracts_id)
     *    - value         : integer / preselected value (default 0)
     *    - entity        : integer or array / restrict to a defined entity or array of entities
     *                      (default -1 : no restriction)
     *    - rand          : (defauolt mt_rand)
     *    - entity_sons   : boolean / if entity restrict specified auto select its sons
     *                      only available if entity is a single value not an array (default false)
     *    - used          : array / Already used items ID: not to display in dropdown (default empty)
     *    - nochecklimit  : boolean / disable limit for nomber of device (for supplier, default false)
     *    - on_change     : string / value to transmit to "onChange"
     *    - display       : boolean / display or return string (default true)
     *    - expired       : boolean / display expired contract (default false)
     *
     * @return string|integer HTML output, or random part of dropdown ID.
    **/
    public static function dropdown($options = [])
    {
        //$name,$entity_restrict=-1,$alreadyused=array(),$nochecklimit=false
        $p = [
           'name'           => 'contracts_id',
           'value'          => '',
           'entity'         => '',
           'rand'           => mt_rand(),
           'entity_sons'    => false,
           'used'           => [],
           'nochecklimit'   => false,
           'on_change'      => '',
           'display'        => true,
           'expired'        => false,
        ];

        if (is_array($options) && count($options)) {
            foreach ($options as $key => $val) {
                $p[$key] = $val;
            }
        }

        if (
            !($p['entity'] < 0)
            && $p['entity_sons']
        ) {
            if (is_array($p['entity'])) {
                // no translation needed (only for dev)
                echo "entity_sons options is not available with array of entity";
            } else {
                $p['entity'] = getSonsOf('glpi_entities', $p['entity']);
            }
        }

        $values = self::connectionChoices($p['entity'], $p['expired'], $p['used'], $p['nochecklimit']);
        return Dropdown::showFromArray(
            $p['name'],
            $values,
            ['value'               => $p['value'],
                                             'on_change'           => $p['on_change'],
                                             'display'             => $p['display'],
                                             'display_emptychoice' => true]
        );
    }


    /** Authorized connection candidates share expiry, maximum-binding and entity policy. */
    public static function connectionChoices($entity, bool $expired = false, array $used = [], bool $ignoreLimit = false, bool $detailed = true): array
    {
        if (!self::canView()) {
            return [];
        }
        $scope = getEntitiesRestrictCriteria(self::getTable(), 'entities_id', $entity, true);
        $sessionScope = Session::getActiveEntityScope();
        if ($sessionScope !== null) {
            $scope = ['AND' => [$scope, getEntitiesRestrictCriteria(self::getTable(), 'entities_id', $sessionScope, true)]];
        }
        $values = $detailed ? [] : [0 => Dropdown::EMPTY_VALUE];
        $language = !$detailed && Session::haveTranslations('Contract', 'name') ? ($_SESSION['glpilanguage'] ?? '') : null;
        foreach (self::repository()->availableForConnection($scope, $expired, $used, $ignoreLimit, language: $language) as $data) {
            $group = Dropdown::getDropdownName('glpi_entities', $data['entities_id']);
            $name = !empty($data['translatedName']) ? $data['translatedName'] : $data['name'];
            if (!empty($_SESSION['glpiis_ids_visible']) || empty($name)) {
                $name = sprintf(__('%1$s (%2$s)'), $name, $data['id']);
            }
            if ($detailed) {
                $name = sprintf(__('%1$s - %2$s'), $name, $data['num']);
                $name = sprintf(__('%1$s - %2$s'), $name, Html::convDateTime($data['begin_date']));
            }
            $values[$group][$data['id']] = $name;
        }
        return $values;
    }


    /**
     * Print a select with contract renewal
     *
     * Print a select named $name with contract renewal options and selected value $value
     *
     * @param string  $name    HTML select name
     * @param integer $value   HTML select selected value (default = 0)
     * @param boolean $display get or display string ? (true by default)
     *
     * @return string|integer HTML output, or random part of dropdown ID.
    **/
    public static function dropdownContractRenewal($name, $value = 0, $display = true)
    {

        $values = [
           self::RENEWAL_NEVER => __('Never'),
           self::RENEWAL_TACIT => __('Tacit'),
           self::RENEWAL_EXPRESS => __('Express'),
        ];
        return Dropdown::showFromArray($name, $values, ['value'   => $value,
                                                          'display' => $display]);
    }


    /**
     * Get the renewal type name
     *
     * @param $value integer   HTML select selected value
     *
     * @return string
    **/
    public static function getContractRenewalName($value)
    {

        switch ($value) {
            case 0:
                return __('Never');

            case 1:
                return __('Tacit');

            case 2:
                return __('Express');

            default:
                return "";
        }
    }


    /**
     * Get renewal ID by name
     *
     * @param string $value the name of the renewal
     *
     * @return integer ID of the renewal
    **/
    public static function getContractRenewalIDByName($value)
    {

        if (stristr($value, __('Tacit'))) {
            return 1;
        }
        if (stristr($value, __('Express'))) {
            return 2;
        }
        return 0;
    }


    /**
     * @param array $options
     *
     * @return string|integer HTML output, or random part of dropdown ID.
    **/
    public static function dropdownAlert(array $options)
    {

        $p = [
           'name'           => 'alert',
           'value'          => 0,
           'display'        => true,
           'inherit_parent' => false,
        ];

        if (count($options)) {
            foreach ($options as $key => $val) {
                $p[$key] = $val;
            }
        }

        $tab = [];
        if ($p['inherit_parent']) {
            $tab[Entity::CONFIG_PARENT] = __('Inheritance of the parent entity');
        }

        $tab += self::getAlertName();

        return Dropdown::showFromArray($p['name'], $tab, $p);
    }


    /**
     * Get the possible value for contract alert
     *
     * @since 0.83
     *
     * @param string|integer|null $val if not set, ask for all values, else for 1 value (default NULL)
     *
     * @return string|string[]
    **/
    public static function getAlertName($val = null)
    {

        $names = [
           0                                                  => Dropdown::EMPTY_VALUE,
           pow(2, Alert::END)                                 => __('End'),
           pow(2, Alert::NOTICE)                              => __('Notice'),
           (pow(2, Alert::END) + pow(2, Alert::NOTICE))       => __('End + Notice'),
           pow(2, Alert::PERIODICITY)                         => __('Period end'),
           pow(2, Alert::PERIODICITY) + pow(2, Alert::NOTICE) => __('Period end + Notice'),
        ];

        if (is_null($val)) {
            return $names;
        }
        // Default value for display
        $names[0] = ' ';

        if (isset($names[$val])) {
            return $names[$val];
        }
        // If not set and is a string return value
        if (is_string($val)) {
            return $val;
        }
        return NOT_AVAILABLE;
    }


    /**
     * Display debug information for current object
    **/
    public function showDebug()
    {

        $options = [
           'entities_id' => $this->getEntityID(),
           'contracts'   => [],
           'items'       => [],
        ];
        NotificationEvent::debugEvent($this, $options);
    }


    public function getUnallowedFieldsForUnicity()
    {

        return array_merge(
            parent::getUnallowedFieldsForUnicity(),
            ['begin_date', 'duration', 'entities_id', 'monday_begin_hour',
                                 'monday_end_hour', 'saturday_begin_hour', 'saturday_end_hour',
                                 'week_begin_hour', 'week_end_hour']
        );
    }


    public static function getMassiveActionsForItemtype(
        array &$actions,
        $itemtype,
        $is_deleted = 0,
        ?CommonDBTM $checkitem = null
    ) {
        global $CFG_GLPI;

        if (in_array($itemtype, $CFG_GLPI["contract_types"])) {
            if (self::canUpdate()) {
                $action_prefix                    = 'Contract_Item' . MassiveAction::CLASS_ACTION_SEPARATOR;
                $actions[$action_prefix . 'add']    = "<i class='ma-icon fas fa-file-contract' aria-hidden='true'></i>" .
                                                    _x('button', 'Add a contract');
                $actions[$action_prefix . 'remove'] = _x('button', 'Remove a contract');
            }
        }
    }


    public static function getIcon()
    {
        return "fas fa-file-signature";
    }
}
