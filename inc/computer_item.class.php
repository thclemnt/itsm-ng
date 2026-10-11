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

use Doctrine\ORM\EntityManager;
use itsmng\Database\ConnexityInput;
use itsmng\Database\EntityRegistry;
use itsmng\Database\DeletionUnit;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\Orm;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\Repository\AssetRepository;
use itsmng\Database\Repository\ComputerAttachmentRepository;
use itsmng\Database\TransactionOwnership;
use itsmng\Database\TransactionOwnershipMismatch;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Computer_Item Class
 *
 * Relation between Computer and Items (monitor, printer, phone, peripheral only)
**/
class Computer_Item extends CommonDBRelation
{
    // From CommonDBRelation
    public static $itemtype_1          = 'Computer';
    public static $items_id_1          = 'computers_id';

    public static $itemtype_2          = 'itemtype';
    public static $items_id_2          = 'items_id';
    public static $checkItem_2_Rights  = self::HAVE_VIEW_RIGHT_ON_ITEM;

    private bool $relationOperationActive = false;
    private ?array $relationWriter = null;
    private ?array $admittedPair = null;

    public function add(array $input, $options = [], $history = true)
    {
        return $this->relationOperation(fn () => parent::add($input, $options, $history));
    }

    public function update(array $input, $history = 1, $options = [])
    {
        return $this->relationOperation(fn () => parent::update($input, $history, $options));
    }

    /** A nested public command keeps its result but cannot replace the outer model's identity. */
    private function relationOperation(callable $operation): mixed
    {
        $nested = $this->relationOperationActive;
        $writer = $this->relationWriter;
        $admitted = $this->admittedPair;
        $journal = new LifecycleModelJournal();
        if ($nested) {
            $journal->remember($this);
        }
        $this->relationOperationActive = true;
        $this->relationWriter = null;
        $this->admittedPair = null;
        try {
            return $operation();
        } finally {
            if ($nested) {
                $journal->restore();
            }
            $this->relationOperationActive = $nested;
            $this->relationWriter = $writer;
            $this->admittedPair = $admitted;
        }
    }

    protected function captureLifecycleWriter(DBAdapter $writer): void
    {
        parent::captureLifecycleWriter($writer);
        $connection = $writer->getDoctrineConnection();
        if (($GLOBALS['DB'] ?? null) !== $writer) {
            throw new TransactionOwnershipMismatch('Computer attachment changed its selected writer.');
        }
        TransactionOwnership::assertManaged($connection);
        $level = $connection->getTransactionNestingLevel();
        $this->relationWriter = [
            $writer, $connection, $level,
            $level > 0 ? $connection->captureManagedTransactionScope() : null,
        ];
        $this->assertRelationWriter();
    }

    private function assertRelationWriter(): void
    {
        if ($this->relationWriter === null) {
            throw new TransactionOwnershipMismatch('Computer attachment has no selected writer.');
        }
        $state = LifecycleModelJournal::state($this);
        [$writer, $connection, $level, $scope] = $this->relationWriter;
        if (($GLOBALS['DB'] ?? null) !== $writer) {
            throw new TransactionOwnershipMismatch('Computer attachment changed its selected writer.');
        }
        $resolved = $writer->getDoctrineConnection();
        if (($GLOBALS['DB'] ?? null) !== $writer || $resolved !== $connection
            || $connection->getTransactionNestingLevel() !== $level) {
            throw new TransactionOwnershipMismatch('Computer attachment changed its selected connection or frame.');
        }
        TransactionOwnership::assertManaged($connection);
        $scope?->assertActive();
        if (LifecycleModelJournal::state($this) !== $state) {
            throw new RuntimeException('The selected writer callback changed the computer attachment.');
        }
    }

    public function filterValues($display = true)
    {
        if ($this->relationOperationActive) {
            $this->assertRelationWriter();
        }
        $result = parent::filterValues($display);
        if ($this->relationOperationActive) {
            $this->assertRelationWriter();
        }
        return $result;
    }

    public function checkUnicity($add = false, $options = [])
    {
        if ($this->relationOperationActive) {
            $this->assertRelationWriter();
        }
        $result = parent::checkUnicity($add, $options);
        if ($this->relationOperationActive) {
            $this->assertRelationWriter();
        }
        return $result;
    }

    protected function assertLifecycleUpdateContext(bool $persisted): void
    {
        parent::assertLifecycleUpdateContext($persisted);
        if ($this->relationOperationActive) {
            $this->assertRelationWriter();
        }
    }

    /** Every virtual parent decision observes the same final relation and loaded subjects. */
    private function attachmentDecision(callable $decision, array $parents = []): mixed
    {
        $state = LifecycleModelJournal::state($this);
        $writer = $this->relationWriter;
        $admitted = $this->admittedPair;
        $parentStates = array_map(LifecycleModelJournal::state(...), $parents);
        $assertState = function () use ($state, $writer, $admitted, $parents, $parentStates): void {
            if (LifecycleModelJournal::state($this) !== $state
                || $this->relationWriter !== $writer || $this->admittedPair !== $admitted) {
                throw new RuntimeException('A parent callback changed the prepared computer attachment.');
            }
            foreach ($parents as $index => $parent) {
                if (LifecycleModelJournal::state($parent) !== $parentStates[$index]) {
                    throw new RuntimeException('A parent callback changed its resolved permission subject.');
                }
            }
        };
        $this->assertRelationWriter();
        $assertState();
        $result = $decision();
        $this->assertRelationWriter();
        $assertState();
        return $result;
    }

    protected function executePreparedAdd(callable $operation, array $priorState): mixed
    {
        $endpoint = $this->attachmentDecision(fn () => ConnexityInput::endpoints($this)['items_id'] ?? null);
        if ($endpoint === null) {
            // A different physical relation table does not acquire the canonical owning policy.
            return parent::executePreparedAdd($operation, $priorState);
        }
        $pair = array_intersect_key($this->fields, array_fill_keys(['computers_id', 'itemtype', 'items_id'], true));
        if (count($pair) !== 3 || $this->admittedPair === null) {
            return false;
        }
        $computer = $this->attachmentDecision(fn () => getItemForItemtype(static::$itemtype_1));
        $item = $this->attachmentDecision(fn () => getItemForItemtype($pair['itemtype']));
        if (!$computer instanceof Computer || !$item instanceof CommonDBTM) {
            return false;
        }
        $parents = [$computer, $item];
        $computerTable = $this->attachmentDecision(fn () => $computer->getTable(), $parents);
        $itemTable = $this->attachmentDecision(fn () => $item->getTable(), $parents);
        $relationTable = $this->attachmentDecision(fn () => static::getTable(), $parents);
        if ((EntityRegistry::discriminatedReferences($relationTable)['items_id'] ?? null) !== $endpoint) {
            return false;
        }
        $target = $endpoint['selections'][$pair['itemtype']]['target'] ?? null;
        if ($computerTable !== EntityRegistry::relations()[$relationTable]['computers_id']
            || ($target !== null && $itemTable !== $target)) {
            return false;
        }
        $targetsMatch = function () use ($computer, $item, $parents, $computerTable, $itemTable, $relationTable): bool {
            return $this->attachmentDecision(fn () => static::getTable(), $parents) === $relationTable
                && $this->attachmentDecision(fn () => $computer->getTable(), $parents) === $computerTable
                && $this->attachmentDecision(fn () => $item->getTable(), $parents) === $itemTable;
        };
        if (!$targetsMatch()
            || !$this->attachmentDecision(fn () => $computer->getFromDB($pair['computers_id']), [$item])
            || !$targetsMatch()
            || !$this->attachmentDecision(fn () => $item->getFromDB($pair['items_id']), [$computer])
            || !$targetsMatch()
            || $this->attachmentDecision(fn () => $computer->getID(), $parents) != $pair['computers_id']
            || $this->attachmentDecision(fn () => $item->getID(), $parents) != $pair['items_id']) {
            return false;
        }
        $unchanged = $pair['itemtype'] === $this->admittedPair['itemtype']
            && $pair['computers_id'] == $this->admittedPair['computers_id']
            && $pair['items_id'] == $this->admittedPair['items_id'];
        if (!$unchanged && !$this->authorizePreparedAttachment($computer, $item)) {
            return false;
        }
        $kind = $this->attachmentDecision(fn () => $item->getType(), $parents);
        $computerId = $this->attachmentDecision(fn () => $computer->getField('id'), $parents);
        $itemId = $this->attachmentDecision(fn () => $item->getField('id'), $parents);
        $global = $this->attachmentDecision(fn () => $item->getField('is_global'), $parents);
        $scope = null;
        if (!$global && !Session::isCron()) {
            $linked = $this->attachmentDecision(fn () => new Computer(), $parents);
            if ($this->attachmentDecision(fn () => $linked->isEntityAssign(), $parents)) {
                $scope = $this->attachmentDecision(
                    fn () => (new DbUtils())->getEntityRestriction($computerTable, '', '', 'auto'),
                    $parents
                );
                if (!$scope->hasEntityMembership) {
                    return false;
                }
            }
        }
        $this->assertRelationWriter();
        $repositoryTable = $this->attachmentDecision(fn () => static::getTable(), $parents);
        if ($repositoryTable !== $relationTable) {
            return false;
        }
        $repository = new ComputerAttachmentRepository($this->relationWriter[1], $repositoryTable);
        if ($this->attachmentDecision(fn () => $repository->hasDuplicate($computerId, $kind, $itemId), $parents)
            || (!$global && $this->attachmentDecision(fn () => $repository->hasActiveConnection($kind, $itemId, $scope), $parents))) {
            return false;
        }
        $this->assertRelationWriter();
        return parent::executePreparedAdd($operation, $priorState);
    }

    /** Existing CREATE relation roles: Computer UPDATE, other endpoint VIEW, coherent entities. */
    private function authorizePreparedAttachment(Computer $computer, CommonDBTM $item): bool
    {
        $parents = [$computer, $item];
        if (!$this->attachmentDecision(fn () => $computer->retainItemPermission(UPDATE), $parents)
            || !$this->attachmentDecision(fn () => $computer->canUpdateItem(), $parents)
            || !$this->attachmentDecision(fn () => $computer->canUpdate(), $parents)
            || !$this->attachmentDecision(fn () => $item->retainItemPermission(READ), $parents)
            || !$this->attachmentDecision(fn () => $item->canView(), $parents)
            || !$this->attachmentDecision(fn () => $item->canViewItem(), $parents)) {
            return false;
        }
        if (!static::$check_entity_coherency
            || !$this->attachmentDecision(fn () => $computer->isEntityAssign(), $parents)
            || !$this->attachmentDecision(fn () => $item->isEntityAssign(), $parents)) {
            return true;
        }
        $computerEntity = $this->attachmentDecision(fn () => $computer->getEntityID(), $parents);
        $itemEntity = $this->attachmentDecision(fn () => $item->getEntityID(), $parents);
        return $computerEntity == $itemEntity
            || ($this->attachmentDecision(fn () => $computer->isRecursive(), $parents)
                && in_array($computerEntity, $this->attachmentDecision(fn () => getAncestorsOf('glpi_entities', $itemEntity), $parents)))
            || ($this->attachmentDecision(fn () => $item->isRecursive(), $parents)
                && in_array($itemEntity, $this->attachmentDecision(fn () => getAncestorsOf('glpi_entities', $computerEntity), $parents)));
    }


    public function getForbiddenStandardMassiveAction()
    {

        $forbidden   = parent::getForbiddenStandardMassiveAction();
        $forbidden[] = 'update';
        return $forbidden;
    }


    /**
     * Count connection for a Computer and an itemtype
     *
     * @since 0.84
     *
     * @param $comp   Computer object
     * @param $item   CommonDBTM object
     *
     * @return integer: count
    **/
    public static function countForAll(Computer $comp, CommonDBTM $item)
    {

        return countElementsInTable(
            'glpi_computers_items',
            ['computers_id' => $comp->getField('id'),
                                     'itemtype'     => $item->getType(),
                                     'items_id'     => $item->getField('id')]
        );
    }


    public function prepareInputForAdd($input)
    {
        global $CFG_GLPI;

        if ($this->relationOperationActive) {
            $this->assertRelationWriter();
        }

        $item = static::getItemFromArray(static::$itemtype_2, static::$items_id_2, $input);
        if ($this->relationOperationActive) {
            $this->assertRelationWriter();
        }
        if (
            !($item instanceof CommonDBTM)
            || (($item->getField('is_global') == 0)
                && ($this->countForItem($item) > 0))
        ) {
            return false;
        }

        $comp = static::getItemFromArray(static::$itemtype_1, static::$items_id_1, $input);
        if ($this->relationOperationActive) {
            $this->assertRelationWriter();
        }
        if (
            !($comp instanceof Computer)
            || (self::countForAll($comp, $item) > 0)
        ) {
            // no duplicates
            return false;
        }

        $admittedPair = ['computers_id' => $comp->fields['id'], 'itemtype' => $input['itemtype'], 'items_id' => $item->fields['id']];

        if (!$item->getField('is_global')) {
            // Autoupdate some fields - should be in post_addItem (here to avoid more DB access)
            $updates = [];

            if (
                $CFG_GLPI["is_location_autoupdate"]
                && ($comp->fields['locations_id'] != $item->getField('locations_id'))
            ) {
                $updates['locations_id'] = addslashes((string) $comp->fields['locations_id']);
                Session::addMessageAfterRedirect(
                    __('Location updated. The connected items have been moved in the same location.'),
                    true
                );
            }
            if (
                ($CFG_GLPI["is_user_autoupdate"]
                 && ($comp->fields['users_id'] != $item->getField('users_id')))
                || ($CFG_GLPI["is_group_autoupdate"]
                    && ($comp->fields['groups_id'] != $item->getField('groups_id')))
            ) {
                if ($CFG_GLPI["is_user_autoupdate"]) {
                    $updates['users_id'] = $comp->fields['users_id'];
                }
                if ($CFG_GLPI["is_group_autoupdate"]) {
                    $updates['groups_id'] = $comp->fields['groups_id'];
                }
                Session::addMessageAfterRedirect(
                    __('User or group updated. The connected items have been moved in the same values.'),
                    true
                );
            }

            if (
                $CFG_GLPI["is_contact_autoupdate"]
                && (($comp->fields['contact'] != $item->getField('contact'))
                    || ($comp->fields['contact_num'] != $item->getField('contact_num')))
            ) {
                $updates['contact']     = addslashes((string) $comp->fields['contact']);
                $updates['contact_num'] = addslashes((string) $comp->fields['contact_num']);
                Session::addMessageAfterRedirect(
                    __('Alternate username updated. The connected items have been updated using this alternate username.'),
                    true
                );
            }

            if (
                ($CFG_GLPI["state_autoupdate_mode"] < 0)
                && ($comp->fields['states_id'] != $item->getField('states_id'))
            ) {
                $updates['states_id'] = $comp->fields['states_id'];
                Session::addMessageAfterRedirect(
                    __('Status updated. The connected items have been updated using this status.'),
                    true
                );
            }

            if (
                ($CFG_GLPI["state_autoupdate_mode"] > 0)
                && ($item->getField('states_id') != $CFG_GLPI["state_autoupdate_mode"])
            ) {
                $updates['states_id'] = $CFG_GLPI["state_autoupdate_mode"];
            }

            if (count($updates)) {
                $updates['id'] = $input['items_id'];
                $history = true;
                if (isset($input['_no_history']) && $input['_no_history']) {
                    $history = false;
                }
                $item->update($updates, $history);
            }
        }
        $prepared = parent::prepareInputForAdd($input);
        if ($this->relationOperationActive) {
            $this->assertRelationWriter();
            if ($prepared !== false) {
                $this->admittedPair = $admittedPair;
            }
        }
        return $prepared;
    }


    public function cleanDBonPurge()
    {
        global $CFG_GLPI, $DB;

        $connection = $DB->getDoctrineConnection();

        if (!isset($this->input['_no_auto_action'])) {
            //Get device fields
            if ($device = getItemForItemtype($this->fields['itemtype'])) {
                if ($device->getFromDB($this->fields['items_id'])) {
                    if (!$device->getField('is_global')) {
                        $updates = [];
                        if (
                            $CFG_GLPI["is_location_autoclean"]
                            && $device->isField('locations_id')
                            && $device->getField('locations_id') !== null
                        ) {
                            $updates['locations_id'] = 0;
                        }
                        if (
                            $CFG_GLPI["is_user_autoclean"]
                            && $device->isField('users_id')
                            && $device->getField('users_id') !== null
                        ) {
                            $updates['users_id'] = 0;
                        }
                        if (
                            $CFG_GLPI["is_group_autoclean"]
                            && $device->isField('groups_id')
                            && $device->getField('groups_id') !== null
                        ) {
                            $updates['groups_id'] = 0;
                        }
                        if ($CFG_GLPI["is_contact_autoclean"] && $device->isField('contact')) {
                            $updates['contact'] = "";
                        }
                        if ($CFG_GLPI["is_contact_autoclean"] && $device->isField('contact_num')) {
                            $updates['contact_num'] = "";
                        }
                        if (
                            ($CFG_GLPI["state_autoclean_mode"] < 0)
                            && $device->isField('states_id')
                            && $device->getField('states_id') !== null
                        ) {
                            $updates['states_id'] = 0;
                        }

                        if (
                            ($CFG_GLPI["state_autoclean_mode"] > 0)
                            && $device->isField('states_id')
                            && ($device->getField('states_id') != $CFG_GLPI["state_autoclean_mode"])
                        ) {
                            $updates['states_id'] = $CFG_GLPI["state_autoclean_mode"];
                        }

                        if (count($updates)) {
                            $updates['id'] = $this->fields['items_id'];
                            DeletionUnit::requireSuccess($connection, (bool)$device->update($updates));
                        }
                    }
                }
            }
        }
    }


    public static function getMassiveActionsForItemtype(
        array &$actions,
        $itemtype,
        $is_deleted = 0,
        ?CommonDBTM $checkitem = null
    ) {

        $action_prefix = __CLASS__ . MassiveAction::CLASS_ACTION_SEPARATOR;
        $specificities = self::getRelationMassiveActionsSpecificities();

        if (in_array($itemtype, $specificities['itemtypes'])) {
            $actions[$action_prefix . 'add']    = "<i class='ma-icon fas fa-plug' aria-hidden='true'></i>" .
                                                _x('button', 'Connect');
            $actions[$action_prefix . 'remove'] = _x('button', 'Disconnect');
        }
        parent::getMassiveActionsForItemtype($actions, $itemtype, $is_deleted, $checkitem);
    }


    public static function getRelationMassiveActionsSpecificities()
    {

        $specificities              = parent::getRelationMassiveActionsSpecificities();

        $specificities['itemtypes'] = ['Monitor', 'Peripheral', 'Phone', 'Printer'];

        $specificities['select_items_options_2']['entity_restrict'] = $_SESSION['glpiactive_entity'];
        $specificities['select_items_options_2']['onlyglobal']      = true;

        $specificities['only_remove_all_at_once']                   = true;

        // Set the labels for add_item and remove_item
        $specificities['button_labels']['add']                      = _sx('button', 'Connect');
        $specificities['button_labels']['remove']                   = _sx('button', 'Disconnect');

        return $specificities;
    }


    /**
    * Disconnect an item to its computer
    *
    * @param $item    CommonDBTM object: the Monitor/Phone/Peripheral/Printer
    *
    * @return boolean : action succeeded
    */
    public function disconnectForItem(CommonDBTM $item)
    {
        global $DB;

        if ($item->getField('id')) {
            $iterator = $DB->request([
               'SELECT' => ['id'],
               'FROM'   => $this->getTable(),
               'WHERE'  => [
                  'itemtype'  => $item->getType(),
                  'items_id'  => $item->getID()
               ]
            ]);

            if (count($iterator) > 0) {
                $ok = true;
                while ($data = $iterator->next()) {
                    if ($this->can($data["id"], UPDATE)) {
                        $ok &= $this->delete($data);
                    }
                }
                return $ok;
            }
        }
        return false;
    }


    /**
     *
     * Print the form for computers or templates connections to printers, screens or peripherals
     *
     * @param Computer $comp         Computer object
     * @param boolean  $withtemplate Template or basic item (default 0)
     *
     * @return void
    **/
    public static function showForComputer(Computer $comp, $withtemplate = 0)
    {
        global $CFG_GLPI;

        $ID      = $comp->fields['id'];
        $canedit = $comp->canEdit($ID);

        $datas = [];
        $used  = [];
        foreach ($CFG_GLPI["directconnect_types"] as $itemtype) {
            $item = new $itemtype();
            if ($item->canView()) {
                $iterator = self::getTypeItems($ID, $itemtype);

                while ($data = $iterator->next()) {
                    $data['assoc_itemtype'] = $itemtype;
                    $datas[]           = $data;
                    $used[$itemtype][] = $data['id'];
                }
            }
        }
        $AjaxUsedData = json_encode($used);
        $number = count($datas);

        if (
            $canedit
            && !(!empty($withtemplate) && ($withtemplate == 2))
        ) {
            $valuesForDropdown = [];
            foreach ($CFG_GLPI['directconnect_types'] as $type) {
                if ($item = getItemForItemtype($type)) {
                    if (!$item->canView()) {
                        continue;
                    }
                    $valuesForDropdown[$type] = $item->getTypeName(1);
                }
            }
            $form = [
               'action' => Toolbox::getItemTypeFormURL(__CLASS__),
               'buttons' => [
                  [
                     'type'  => 'submit',
                     'name'  => 'add',
                     'value' => _sx('button', 'Connect'),
                     'class' => 'btn btn-secondary',
                  ]
                  ],
                  'content' => [
                     __('Connect an item') => [
                        'visible' => true,
                        'inputs' => [
                           [
                              'type'  => 'hidden',
                              'name'  => 'computers_id',
                              'value' => $comp->fields['id'],
                           ],
                           [
                              'type'  => 'hidden',
                              'name'  => 'itemtype',
                              'value' => $comp::class,
                           ],
                           [
                              'type'  => 'hidden',
                              'name'  => 'items_id',
                              'value' => $comp->getID(),
                           ],
                           !empty($withtemplate) ? [
                              'type'  => 'hidden',
                              'name'  => '_no_history',
                              'value' => '1',
                           ] : [],
                           __('Device type') => [
                              'type' => 'select',
                              'name' => 'itemtype',
                              'id' => 'ItemTypeConnectDropdown',
                              'values' => array_merge([ Dropdown::EMPTY_VALUE ], $valuesForDropdown),
                              'value' => '',
                              'col_lg' => 6,
                              'hooks' => [
                                 'change' => <<<JS
                                 $('select[name="items_id"]').empty();
                                 if ($('select[name="itemtype"]').val() == 0) {
                                    $('select[name="items_id"]').prop('disabled', true);
                                 } else {
                                    $('select[name="items_id"]').prop('disabled', false);
                                 }
                                 $.ajax({
                                    url: '{$CFG_GLPI['root_doc']}/ajax/dropdownConnect.php',
                                    type: 'POST',
                                    data: {
                                       itemtype: $('select[name="itemtype"]').val(),
                                       fromtype: 'Computer',
                                       value: 0,
                                       myname: 'items_id',
                                       onlyglobal: '',
                                       'used': JSON.parse('{$AjaxUsedData}'),
                                       entity_restrict: {$comp->getEntityID()},
                                    },
                                    dataType: 'json',
                                    success: function(data) {
                                       $.each(data, function(key, value) {
                                          if (typeof value === 'object') {
                                             const group = $('#ItemConnectDropdown')
                                                .append("<optgroup label='" + key + "'></optgroup>");
                                             for (const j in value) {
                                                group.append("<option value='" + j + "'>" + value[j] + "</option>");
                                             }
                                          } else {
                                             $('#ItemConnectDropdown').append("<option value='" + key + "'>" + value + "</option>");
                                          }

                                       });
                                    }
                                 });
                              JS,
                              ]
                           ],
                           __('Device') => [
                              'type' => 'select',
                              'id' => 'ItemConnectDropdown',
                              'name' => 'items_id',
                              'values' => [],
                              'value' => '',
                              'col_lg' => 6,
                              'disabled' => true,
                           ],
                        ],
                     ]
                  ]
            ];
            renderTwigForm($form);
        }

        if ($number) {
            if ($canedit) {
                $massiveactionparams                   = [
                   'container' => "ComputerConnectionTable",
                   'display_arrow' => false,
                   'specific_actions' => [
                      'purge' => __('Disconnect'),
                   ],
                   'is_deleted' => false,
                ];

                Html::showMassiveActions($massiveactionparams);
            }
            $fields = [
               _n('Type', 'Types', 1),
               __('Name'),
               Entity::getTypeName(1),
               __('Serial number'),
               __('Inventory number'),
            ];
            $values = [];
            $massiveActionValues = [];
            foreach ($datas as $data) {
                $linkname = $data["name"];
                if ($_SESSION["glpiis_ids_visible"] || empty($data["name"])) {
                    $linkname = sprintf(__('%1$s (%2$s)'), $linkname, $data["id"]);
                }
                $link = $data['assoc_itemtype']::getFormURLWithID($data["id"]);
                $massiveActionValues[$data['id']] = 'item[Computer_Item][' . $data['linkid'] . ']';
                $values[$data['id']] = [
                   $data['assoc_itemtype']::getTypeName(1),
                   "<a href=\"" . $link . "\">" . $linkname . "</a>",
                   Dropdown::getDropdownName("glpi_entities", $data['entities_id']),
                   (isset($data["serial"]) ? "" . $data["serial"] . "" : "-"),
                   (isset($data["otherserial"]) ? "" . $data["otherserial"] . "" : "-"),
                ];
                if (Plugin::haveImport()) {
                    $values[$data['id']][6] = Dropdown::getYesNo($data[static::getTable() . '_is_dynamic']);
                }
            }
            $twig_vars = [
               'id' => 'ComputerConnectionTable',
               'fields' => $fields,
               'values' => $values,
               'massive_action' => $massiveActionValues,
            ];
            if (Plugin::haveImport()) {
                $twig_vars['fields'][] = __('Automatic inventory');
            }
            renderTwigTemplate('table.twig', $twig_vars);
        }
    }


    /**
     * Prints a direct connection to a computer
     *
     * @param $item                     CommonDBTM object: the Monitor/Phone/Peripheral/Printer
     * @param $withtemplate    integer  withtemplate param (default 0)
     *
     * @return void
    **/
    public static function showForItem(CommonDBTM $item, $withtemplate = 0)
    {
        // Prints a direct connection to a computer
        global $DB, $PLUGIN_HOOKS;

        $comp   = new Computer();
        $ID     = $item->getField('id');

        if (!$item->can($ID, READ)) {
            return;
        }
        $canedit = $item->canEdit($ID);
        $rand    = mt_rand();

        // Is global connection ?
        $global  = $item->getField('is_global');

        $used    = [];
        $compids = [];
        $dynamic = [];
        // Capture the receiver before table/model callbacks, as request() did.
        $database = $DB;
        $table = self::getTable();
        $itemtype = $item->getType();
        if ($table === 'glpi_computers_items' && is_string($itemtype)
            && (is_int($ID) || (is_string($ID) && ctype_digit($ID)))) {
            $result = Orm::read($database, static fn (EntityManager $manager): array =>
                (new AssetRepository($manager))->activeComputerConnections($itemtype, $ID));
        } else {
            // Forced tables and extension-supplied criteria values keep their original compiler.
            $result = $database->request([
                'SELECT' => ['id', 'computers_id', 'is_dynamic'],
                'FROM' => $table,
                'WHERE' => ['itemtype' => $itemtype, 'items_id' => $ID, 'is_deleted' => 0],
            ]);
        }
        foreach ($result as $data) {
            $compids[$data['id']] = $data['computers_id'];
            $dynamic[$data['id']] = $data['is_dynamic'];
            $used['Computer'][]   = $data['computers_id'];
        }
        $number = count($compids);
        if (
            $canedit
            && ($global || !$number)
            && !(!empty($withtemplate) && ($withtemplate == 2))
        ) {
            $form = [
               'action' => Toolbox::getItemTypeFormURL(__CLASS__),
               'buttons' => [
                   [
                       'type'  => 'submit',
                       'name'  => 'add',
                       'value' => _sx('button', 'Connect'),
                       'class' => 'btn btn-secondary',
                   ]
               ],
               'content' => [
                   '' => [
                       'visible' => true,
                       'inputs' => [
                           Computer::getTypeName() => [
                               'type' => 'select',
                               'name' => 'computers_id',
                               'values' => getItemByEntity(Computer::class, $item->fields['entities_id']),
                               'col_lg' => 12,
                               'col_md' => 12,
                           ],
                           [
                               'type'  => 'hidden',
                               'name'  => 'itemtype',
                               'value' => $item->getType(),
                           ],
                           [
                               'type'  => 'hidden',
                               'name'  => 'items_id',
                               'value' => $ID,
                           ],
                       ]
                   ]
               ],
            ];
            renderTwigForm($form);
        }

        echo "<div class='spaced'>";
        $massActionId = 'mass' . __CLASS__ . $rand;
        if ($canedit && $number) {
            $massiveactionparams = [
               'num_displayed' => min($_SESSION['glpilist_limit'], $number),
               'specific_actions' => ['purge' => _x('button', 'Disconnect')],
               'container' => 'mass' . __CLASS__ . $rand,
               'display_arrow' => false,
            ];
            Html::showMassiveActions($massiveactionparams);
        }
        if ($number > 0) {
            $fields = [
                'name' => __('Name'),
                'entity' => Entity::getTypeName(1),
                'serial' => __('Serial number'),
               'otherserial' =>  __('Inventory number'),
            ];
            if (Plugin::haveImport()) {
                $fields['inventory'] = __('Automatic inventory');
            }
            $values = [];
            $massiveActionValues = [];
            // Form/massive-action callbacks have finished. Permission hooks
            // still require complete per-row reads and may change later rows.
            $display = empty($PLUGIN_HOOKS['item_can'])
                ? (new AssetRepository(Orm::create($DB)))
                    ->computerDisplayData($compids)
                : [];
            foreach ($compids as $key => $compid) {
                if (isset($display[$compid])) {
                    // Display-only fields stay inside the ordinary getLink/can
                    // path; this model is never handed to a lifecycle writer.
                    $comp->fields = $display[$compid];
                } else {
                    $comp->getFromDB($compid);
                }

                if ($canedit) {
                    $massiveActionValues[$key] = 'item[Computer_Item][' . $key . ']';
                }
                $newValue = [
                    'name' => $comp->getLink(),
                    'entity' => Dropdown::getDropdownName("glpi_entities", $comp->getField('entities_id')),
                    'serial' => $comp->getField('serial'),
                    'otherserial' => $comp->getField('otherserial'),
                ];
                if (Plugin::haveImport()) {
                    $newValue['inventory'] = Dropdown::getYesNo($dynamic[$key]);
                }
                $values[$key] = $newValue;
            }
            renderTwigTemplate('table.twig', [
               'id' => $massActionId,
               'fields' => $fields,
               'values' => $values,
               'massive_action' => $massiveActionValues,
            ]);
        } else {
            echo "<tr><td class='tab_bg_1 b'><i>" . __('Not connected') . "</i>";
            echo "</td></tr>";
        }
    }


    /**
     * Unglobalize an item : duplicate item and connections
     *
     * @param $item   CommonDBTM object to unglobalize
     * @return bool Whether every required change completed
    **/
    public static function unglobalizeItem(CommonDBTM $item): bool
    {
        global $DB;

        if (!$item->getField('is_global')) {
            return false;
        }

        $writer = $DB;
        $connection = $writer->getDoctrineConnection();
        $itemId = $item->getID();
        $itemType = $item->getType();
        return OwnershipUpdateUnit::run($writer, $item, $item->fields, static function () use (
            $writer,
            $connection,
            $item,
            $itemId,
            $itemType
        ): bool {
            $scope = $connection->captureManagedTransactionScope();
            $level = $connection->getTransactionNestingLevel();
            $assertWriter = static function () use ($writer, $connection, $scope, $level): void {
                $scope->assertActive();
                if ($writer !== ($GLOBALS['DB'] ?? null)
                    || $writer->getDoctrineConnection() !== $connection
                    || $connection->getTransactionNestingLevel() !== $level) {
                    throw new TransactionOwnershipMismatch('Unglobalize changed its supplied writer or frame.');
                }
            };
            OwnershipUpdateUnit::assertTransactionalStorage($writer, $item->getTable());
            OwnershipUpdateUnit::assertTransactionalStorage($writer, self::getTable());

            $connections = iterator_to_array($writer->request([
                'SELECT' => ['id', 'computers_id'],
                'FROM' => self::getTable(),
                'WHERE' => ['items_id' => $itemId, 'itemtype' => $itemType],
                'ORDER' => 'id',
            ]));
            $toMove = array_slice($connections, 1);
            $conn = new self();
            LifecycleModelJournal::capture($connection, $conn);
            foreach ($toMove as $data) {
                $allowed = $conn->can($data['id'], UPDATE);
                $assertWriter();
                if (!$allowed) {
                    return false;
                }
            }

            $updated = $item->update(['id' => $itemId, 'is_global' => 0]);
            $assertWriter();
            if (!$updated) {
                return false;
            }
            $loaded = $item->getFromDB($itemId);
            $assertWriter();
            if (!$loaded || $item->getID() != $itemId || $item->getField('is_global')) {
                return false;
            }

            foreach ($toMove as $data) {
                $temp = clone $item;
                unset($temp->fields['id']);
                LifecycleModelJournal::capture($connection, $temp);
                $newId = $temp->add($temp->fields);
                $assertWriter();
                if (!$newId) {
                    return false;
                }
                $loaded = $temp->getFromDB($newId);
                $assertWriter();
                if (!$loaded || $temp->getID() != $newId || $temp->getField('is_global')) {
                    return false;
                }
                $updated = $conn->update(['id' => $data['id'], 'items_id' => $newId]);
                $assertWriter();
                if (!$updated) {
                    return false;
                }
                $loaded = $conn->getFromDB($data['id']);
                $assertWriter();
                if (!$loaded || $conn->getID() != $data['id']
                    || $conn->getField('items_id') != $newId
                    || $conn->getField('computers_id') != $data['computers_id']
                    || $conn->getField('itemtype') !== $itemType) {
                    return false;
                }
            }
            return true;
        }, guardWriter: true);
    }


    /**
    * Make a select box for connections
    *
    * @since 0.84
    *
    * @param string            $fromtype        from where the connection is
    * @param string            $myname          select name
    * @param integer|integer[] $entity_restrict Restrict to a defined entity (default = -1)
    * @param boolean           $onlyglobal      display only global devices (used for templates) (default 0)
    * @param integer[]         $used            Already used items ID: not to display in dropdown
    *
    * @return integer Random generated number used for select box ID (select box HTML is printed)
    */
    public static function dropdownAllConnect(
        $fromtype,
        $myname,
        $entity_restrict = -1,
        $onlyglobal = 0,
        $used = []
    ) {
        global $CFG_GLPI;

        $rand = mt_rand();

        $options               = [];
        $options['checkright'] = true;
        $options['name']       = 'itemtype';

        $rand = Dropdown::showItemType($CFG_GLPI['directconnect_types'], $options);
        if ($rand) {
            $params = ['itemtype'        => '__VALUE__',
                            'fromtype'        => $fromtype,
                            'value'           => 0,
                            'myname'          => $myname,
                            'onlyglobal'      => $onlyglobal,
                            'entity_restrict' => $entity_restrict,
                            'used'            => $used];

            if ($onlyglobal) {
                $params['condition'] = ['is_global' => 1];
            }
            Ajax::updateItemOnSelectEvent(
                "dropdown_itemtype$rand",
                "show_$myname$rand",
                $CFG_GLPI["root_doc"] . "/ajax/dropdownConnect.php",
                $params
            );

            echo "<br><div id='show_$myname$rand'>&nbsp;</div>\n";
        }
        return $rand;
    }


    /**
    * Make a select box for connections
    *
    * @param string            $itemtype        type to connect
    * @param string            $fromtype        from where the connection is
    * @param string            $myname          select name
    * @param integer|integer[] $entity_restrict Restrict to a defined entity (default = -1)
    * @param boolean           $onlyglobal      display only global devices (used for templates) (default 0)
    * @param integer[]         $used            Already used items ID: not to display in dropdown
    *
    * @return integer Random generated number used for select box ID (select box HTML is printed)
    */
    public static function dropdownConnect(
        $itemtype,
        $fromtype,
        $myname,
        $entity_restrict = -1,
        $onlyglobal = 0,
        $used = []
    ) {
        global $CFG_GLPI;

        $rand     = mt_rand();

        $field_id = Html::cleanId("dropdown_" . $myname . $rand);
        $param    = [
           'entity_restrict' => $entity_restrict,
           'fromtype'        => $fromtype,
           'itemtype'        => $itemtype,
           'onlyglobal'      => $onlyglobal,
           'used'            => $used,
           '_idor_token'     => Session::getNewIDORToken($itemtype, [
              'entity_restrict' => $entity_restrict,
           ]),
        ];

        echo Html::jsAjaxDropdown(
            $myname,
            $field_id,
            $CFG_GLPI['root_doc'] . "/ajax/getDropdownConnect.php",
            $param
        );

        return $rand;
    }


    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {

        // can exists for Template
        if ($item->can($item->getField('id'), READ)) {
            $nb = 0;
            switch ($item->getType()) {
                case 'Phone':
                case 'Printer':
                case 'Peripheral':
                case 'Monitor':
                    if (Computer::canView()) {
                        if ($_SESSION['glpishow_count_on_tabs']) {
                            $nb = self::countForItem($item);
                        }
                        return self::createTabEntry(
                            _n('Connection', 'Connections', Session::getPluralNumber()),
                            $nb
                        );
                    }
                    break;

                case 'Computer':
                    if (
                        Phone::canView()
                        || Printer::canView()
                        || Peripheral::canView()
                        || Monitor::canView()
                    ) {
                        if ($_SESSION['glpishow_count_on_tabs']) {
                            $nb = self::countForMainItem($item);
                        }
                        return self::createTabEntry(
                            _n('Connection', 'Connections', Session::getPluralNumber()),
                            $nb
                        );
                    }
                    break;
            }
        }
        return '';
    }


    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {

        switch ($item->getType()) {
            case 'Phone':
            case 'Printer':
            case 'Peripheral':
            case 'Monitor':
                self::showForItem($item, $withtemplate);
                return true;

            case 'Computer':
                self::showForComputer($item, $withtemplate);
                return true;
        }
    }


    /**
     * Duplicate connected items to computer from an item template to its clone
     *
     * @deprecated 9.5
     * @since 0.84
     *
     * @param integer $oldid ID of the item to clone
     * @param integer $newid ID of the item cloned
    **/
    public static function cloneComputer($oldid, $newid)
    {
        global $DB;

        Toolbox::deprecated('Use clone');
        $iterator = $DB->request([
           'FROM'   => self::getTable(),
           'WHERE'  => ['computers_id' => $oldid]
        ]);

        while ($data = $iterator->next()) {
            $conn = new Computer_Item();
            $conn->add(['computers_id' => $newid,
                        'itemtype'     => $data["itemtype"],
                        'items_id'     => $data["items_id"]]);
        }
    }


    /**
     * Duplicate connected items to item from an item template to its clone
     *
     * @deprecated 9.5
     * @since 0.83.3
     *
     * @param string  $itemtype type of the item to clone
     * @param integer $oldid    ID of the item to clone
     * @param integer $newid    ID of the item cloned
    **/
    public static function cloneItem($itemtype, $oldid, $newid)
    {
        global $DB;

        Toolbox::deprecated('Use clone');
        $iterator = $DB->request([
           'FROM'   => self::getTable(),
           'WHERE'  => [
              'itemtype'  => $itemtype,
              'items_id'  => $oldid
           ]
        ]);

        while ($data = $iterator->next()) {
            $conn = new self();
            $conn->add(['computers_id' => $data["computers_id"],
                        'itemtype'     => $data["itemtype"],
                        'items_id'     => $newid]);
        }
    }


    /**
     * @since 9.1.7
     *
     * @param CommonDBTM $item     item linked to the computer to check
     * @param integer[]  $entities entities to check
     *
     * @return boolean
    **/
    public static function canUnrecursSpecif(CommonDBTM $item, $entities)
    {
        global $DB;

        $em = Orm::create($DB);
        try {
            $links = (new AssetRepository($em))->linkedItems(
                $item instanceof Computer ? Computer::class : $item->getType(),
                (int)$item->getID()
            );
        } finally {
            $em->clear();
        }
        foreach ($links as $type => $ids) {
            $target = getItemForItemtype($type);
            if ($target && $target->isEntityAssign() && countElementsInTable($target->getTable(), [
                'id' => array_values($ids), 'NOT' => ['entities_id' => $entities],
            ]) > 0) {
                return false;
            }
        }

        return true;
    }


    protected static function getListForItemParams(CommonDBTM $item, $noent = false)
    {
        $params = parent::getListForItemParams($item, $noent);
        $params['WHERE'][self::getTable() . '.is_deleted'] = 0;
        return $params;
    }

    /**
     * Get SELECT param for getTypeItemsQueryParams
     *
     * @param CommonDBTM $item
     *
     * @return array
     */
    public static function getTypeItemsQueryParams_Select(CommonDBTM $item): array
    {
        $table = static::getTable();
        $select = parent::getTypeItemsQueryParams_Select($item);
        $select[] = "$table.is_dynamic AS {$table}_is_dynamic";

        return $select;
    }
}
