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
use itsmng\Database\Entity\NetworkPort as NetworkPortRecord;
use itsmng\Database\EntityRegistry;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\TransactionOwnershipMismatch;
use itsmng\Database\Orm;
use itsmng\Database\Repository\NetworkConnectionRepository;
use itsmng\Database\Repository\NetworkPortAggregateRepository;
use itsmng\Database\RowIterator;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}


/**
 * NetworkPort Class
 *
 * There is two parts for a given NetworkPort.
 * The first one, generic, only contains the link to the item, the name and the type of network port.
 * All specific characteristics are owned by the instanciation of the network port : NetworkPortInstantiation.
 * Whenever a port is display (through its form or though item port listing), the NetworkPort class
 * load its instantiation from the instantiation database to display the elements.
 * Moreover, in NetworkPort form, if there is no more than one NetworkName attached to the current
 * port, then, the fields of NetworkName are display. Thus, NetworkPort UI remain similar to 0.83
**/
class NetworkPort extends CommonDBChild
{
    /** Only the current operation owns its child staging and permission subject. */
    private ?array $networkPortOperation = null;
    private ?array $networkPortPendingState = null;

    public function add(array $input, $options = [], $history = true)
    {
        return $this->withNetworkPortOperation(fn () => parent::add($input, $options, $history));
    }

    public function update(array $input, $history = 1, $options = [])
    {
        return $this->withNetworkPortOperation(fn () => parent::update($input, $history, $options));
    }

    private function withNetworkPortOperation(callable $operation): mixed
    {
        $outer = $this->networkPortOperation;
        $pending = $this->networkPortPendingState;
        $this->networkPortPendingState = null;
        $journal = $outer !== null ? new LifecycleModelJournal() : null;
        $staged = [];
        foreach (['input_for_instantiation', 'input_for_NetworkName', 'input_for_NetworkPortConnect'] as $property) {
            if (array_key_exists($property, get_object_vars($this))) {
                $staged[$property] = $this->$property;
            }
            if ($outer !== null) {
                unset($this->$property);
            }
        }
        if ($outer !== null) {
            $journal->remember($this, LifecycleModelJournal::state($this));
        }
        $this->networkPortOperation = ['receiver' => $this, 'writer' => $GLOBALS['DB'], 'connection' => null, 'parent' => null, 'scope' => []];
        $completed = false;
        try {
            $result = $operation();
            $completed = $result !== false;
            return $result;
        } finally {
            if ($outer !== null) {
                // Success of the inner command must not replace the outer audit subject.
                $journal->restore();
            }
            if ($outer !== null || !$completed) {
                foreach (['input_for_instantiation', 'input_for_NetworkName', 'input_for_NetworkPortConnect'] as $property) {
                    if (array_key_exists($property, $staged)) {
                        $this->$property = $staged[$property];
                    } else {
                        unset($this->$property);
                    }
                }
            }
            $this->networkPortOperation = $outer;
            $this->networkPortPendingState = $pending;
        }
    }

    protected function captureLifecycleWriter(DBAdapter $writer): void
    {
        parent::captureLifecycleWriter($writer);
        if ($this->networkPortOperation !== null) {
            if ($writer !== $this->networkPortOperation['writer'] || ($GLOBALS['DB'] ?? null) !== $writer) {
                throw new TransactionOwnershipMismatch('Network port lifecycle changed its supplied writer.');
            }
            $connection = $writer->getDoctrineConnection();
            if (($GLOBALS['DB'] ?? null) !== $writer) {
                throw new TransactionOwnershipMismatch('Network port lifecycle changed its supplied writer.');
            }
            OwnershipUpdateUnit::assertResolvedWriter($writer, $connection);
            $this->networkPortOperation['connection'] = $connection;
        }
    }

    private function assertNetworkPortWriter(): void
    {
        $writer = $this->networkPortOperation['writer'] ?? null;
        if ($writer === null) {
            return;
        }
        if (($GLOBALS['DB'] ?? null) !== $writer
            || $writer->getDoctrineConnection() !== $this->networkPortOperation['connection']
            || ($GLOBALS['DB'] ?? null) !== $writer) {
            throw new TransactionOwnershipMismatch('Network port lifecycle changed its supplied writer.');
        }
        OwnershipUpdateUnit::assertResolvedWriter($writer, $this->networkPortOperation['connection']);
    }

    /** The actual public parent must agree with the property-owned target before loading. */
    private function resolvedNetworkPortParent(array $input, $getFromDB = true, $getEmpty = true, $getFromDBOrEmpty = false): CommonDBTM|false
    {
        $this->assertNetworkPortWriter();
        $table = static::getTable();
        $this->assertNetworkPortWriter();
        if ((EntityRegistry::tables()[$table] ?? null) !== NetworkPortRecord::class) {
            throw new InvalidArgumentException('Network port ownership requires its declared physical table.');
        }
        $parent = static::getItemFromArray(static::$itemtype, static::$items_id, $input, false, false);
        $this->assertNetworkPortWriter();
        if ($parent === false) {
            return false;
        }
        $selection = EntityRegistry::discriminatedReferences($table)['items_id']['selections'][$input['itemtype']] ?? null;
        if ($selection !== null) {
            $parentTable = $parent->getTable();
            $this->assertNetworkPortWriter();
            if ($parentTable !== $selection['target']) {
                throw new InvalidArgumentException('Network port parent uses a different physical owner table.');
            }
        }
        if ($getFromDB || $getFromDBOrEmpty) {
            $loaded = isset($input[static::$items_id]) && $parent->getFromDB($input[static::$items_id]);
            $this->assertNetworkPortWriter();
            if ($loaded) {
                return $parent;
            }
            if (!$getFromDBOrEmpty) {
                return false;
            }
        } elseif (!$getEmpty) {
            return $parent;
        }
        $empty = $parent->getEmpty();
        $this->assertNetworkPortWriter();
        return $empty ? $parent : false;
    }

    public function getConnexityItem(
        $itemtype,
        $items_id,
        $getFromDB = true,
        $getEmpty = true,
        $getFromDBOrEmpty = false
    ) {
        if ($this->networkPortOperation !== null && $itemtype === static::$itemtype && $items_id === static::$items_id) {
            return $this->resolvedNetworkPortParent($this->fields, $getFromDB, $getEmpty, $getFromDBOrEmpty);
        }
        return parent::getConnexityItem($itemtype, $items_id, $getFromDB, $getEmpty, $getFromDBOrEmpty);
    }

    public function addNeededInfoToInput($input)
    {
        if ($this->networkPortOperation === null) {
            return parent::addNeededInfoToInput($input);
        }
        $forward = $this->tryEntityForwarding();
        $this->assertNetworkPortWriter();
        if ($forward && !isset($input['entities_id'])) {
            $parent = $this->resolvedNetworkPortParent(array_merge($this->fields, $input));
            if ($parent !== false) {
                $forwards = $parent->isEntityForwardTo(get_called_class());
                $this->assertNetworkPortWriter();
                if ($forwards) {
                    $entity = $parent->getEntityID();
                    $this->assertNetworkPortWriter();
                    $recursive = $parent->isRecursive();
                    $this->assertNetworkPortWriter();
                    $input['entities_id'] = $entity;
                    $input['is_recursive'] = intval($recursive);
                } else {
                    $input['entities_id'] = 0;
                    $input['is_recursive'] = 0;
                }
            }
        }
        return $input;
    }

    /** Callbacks may restrict the pending write, but cannot substitute its receiver. */
    private function retainsNetworkPortReceiver(array $state, ?array $context): bool
    {
        $this->assertNetworkPortWriter();
        return LifecycleModelJournal::state($this) === $state && $this->networkPortOperation === $context;
    }

    public function checkAttachedItemChangesAllowed(array $input, array $fields)
    {
        if ($this->networkPortOperation === null) {
            return parent::checkAttachedItemChangesAllowed($input, $fields);
        }
        $receiver = $this->networkPortOperation['receiver'];
        if ($receiver === $this) {
            // Early authorization precedes ordinary preparation of pending fields.
            [$state, $context] = [LifecycleModelJournal::state($receiver), $receiver->networkPortOperation];
        } else {
            $this->networkPortPendingState ??= [LifecycleModelJournal::state($receiver), $receiver->networkPortOperation];
            [$state, $context] = $this->networkPortPendingState;
        }
        $allowed = parent::checkAttachedItemChangesAllowed($input, $fields);
        return $receiver->retainsNetworkPortReceiver($state, $context) && $allowed;
    }

    protected function validateLifecycleEndpoints(array $input): array|false
    {
        $receiver = $this->networkPortOperation['receiver'] ?? $this;
        [$state, $context] = $this->networkPortPendingState ?? [LifecycleModelJournal::state($receiver), $receiver->networkPortOperation];
        if (($input['itemtype'] ?? null) === ($this->fields['itemtype'] ?? null)
            && ($input['items_id'] ?? null) == ($this->fields['items_id'] ?? null)) {
            // Preserved native empty/opaque parents do not gain an attachment requirement.
            $table = static::getTable();
            if (!$receiver->retainsNetworkPortReceiver($state, $context)) {
                return false;
            }
            if ((EntityRegistry::tables()[$table] ?? null) !== NetworkPortRecord::class) {
                throw new InvalidArgumentException('Network port ownership requires its declared physical table.');
            }
            return $input;
        }
        $parent = $this->resolvedNetworkPortParent($input);
        if (!$receiver->retainsNetworkPortReceiver($state, $context) || $parent === false) {
            return false;
        }
        $input = $this->addNeededInfoToInput($input);
        return $receiver->retainsNetworkPortReceiver($state, $context) ? $input : false;
    }

    protected function retainLifecycleEndpointDecision(CommonDBConnexity $probe): void
    {
        if ($probe instanceof self && $probe->networkPortPendingState !== null) {
            [$state, $context] = $probe->networkPortPendingState;
            if (!$this->retainsNetworkPortReceiver($state, $context)) {
                throw new InvalidArgumentException('Network port callbacks changed the pending write receiver.');
            }
        }
    }

    protected function executePreparedAdd(callable $operation, array $priorState): mixed
    {
        $state = LifecycleModelJournal::state($this);
        $context = $this->networkPortOperation;
        $input = $this->normalizeLifecycleInput(array_replace($this->input, $this->fields));
        if (!$this->retainsNetworkPortReceiver($state, $context) || $input === false) {
            return false;
        }
        $parent = $this->resolvedNetworkPortParent($input);
        if (!$this->retainsNetworkPortReceiver($state, $context) || $parent === false) {
            return false;
        }
        $admitted = $context['parent'] ?? null;
        if ($admitted !== [$input['itemtype'], $input['items_id']]) {
            $probe = clone $this;
            $probe->fields = $input;
            $allowed = $probe->canChildItem('canUpdateItem', 'canUpdate');
            if (!$this->retainsNetworkPortReceiver($state, $context) || !$allowed) {
                return false;
            }
            foreach (['entities_id', 'is_recursive'] as $column) {
                if (!in_array($column, $context['scope'] ?? [], true)) {
                    unset($input[$column]);
                }
            }
            $input = $this->addNeededInfoToInput($input);
            $this->assertNetworkPortWriter();
            $forwarded = LifecycleModelJournal::state($this);
            // Forwarding owns fields-cache initialization, not pending input.
            // The validated input below supplies the final physical field bag.
            unset($state['fields'], $forwarded['fields']);
            if ($forwarded !== $state || $this->networkPortOperation !== $context) {
                return false;
            }
            $state = LifecycleModelJournal::state($this);
        }
        $columns = EntityRegistry::columnNames(static::getTable());
        if (!$this->retainsNetworkPortReceiver($state, $context)) {
            return false;
        }
        $this->input = $input;
        $this->fields = array_intersect_key($input, array_fill_keys($columns, true));
        $prepared = LifecycleModelJournal::state($this);
        return parent::executePreparedAdd(
            fn () => $this->retainsNetworkPortReceiver($prepared, $context) ? $operation() : false,
            $priorState
        );
    }

    protected function executePreparedUpdate(callable $operation, array $priorState): bool
    {
        $state = LifecycleModelJournal::state($this);
        $context = $this->networkPortOperation;
        return parent::executePreparedUpdate(
            fn () => $this->retainsNetworkPortReceiver($state, $context) ? $operation() : false,
            $priorState
        );
    }

    /** Whether a cable reaches an entity that cannot see the non-recursive owner. */
    public static function hasConnectionsOutsideEntities(string $itemtype, int $id, array $entities): bool
    {
        global $DB;

        $em = Orm::create($DB);
        try {
            $peers = (new NetworkConnectionRepository($em))->peers($itemtype, $id);
        } finally {
            $em->clear();
        }
        foreach ($peers as $type => $ids) {
            $item = getItemForItemtype($type);
            if ($item && $item->isEntityAssign() && countElementsInTable($item->getTable(), [
                'id' => array_values($ids), 'NOT' => ['entities_id' => $entities],
            ]) > 0) {
                return true;
            }
        }
        return false;
    }

    // From CommonDBChild
    public static $itemtype             = 'itemtype';
    public static $items_id             = 'items_id';
    public $dohistory                   = true;

    public static $checkParentRights    = CommonDBConnexity::HAVE_SAME_RIGHT_ON_ITEM;

    protected static $forward_entity_to = ['NetworkName'];

    public static $rightname                   = 'networking';


    public function getForbiddenStandardMassiveAction()
    {

        $forbidden   = parent::getForbiddenStandardMassiveAction();
        $forbidden[] = 'update';
        return $forbidden;
    }


    /**
     * @since 0.84
     *
     * @see CommonDBTM::getPreAdditionalInfosForName
    **/
    public function getPreAdditionalInfosForName()
    {

        if ($item = $this->getItem()) {
            return $item->getName();
        }
        return '';
    }


    /**
     * \brief get the list of available network port type.
     *
     * @since 0.84
     *
     * @return array of available type of network ports
    **/
    public static function getNetworkPortInstantiations()
    {
        global $CFG_GLPI;

        return $CFG_GLPI['networkport_instantiations'];
    }


    public static function getNetworkPortInstantiationsWithNames()
    {

        $types = self::getNetworkPortInstantiations();
        $tab   = [];
        foreach ($types as $itemtype) {
            $tab[$itemtype] = call_user_func([$itemtype, 'getTypeName']);
        }
        return $tab;
    }


    public static function getTypeName($nb = 0)
    {
        return _n('Network port', 'Network ports', $nb);
    }


    /**
     * \brief get the instantiation of the current NetworkPort
     * The instantiation rely on the instantiation_type field and the id of the NetworkPort. If the
     * network port exists, but not its instantiation, then, the instantiation will be empty.
     *
     * @since 0.84
     *
     * @return NetworkPortInstantiation|false  the instantiation object or false if the type of instantiation is not known
    **/
    public function getInstantiation()
    {

        if (
            isset($this->fields['instantiation_type'])
            && in_array($this->fields['instantiation_type'], self::getNetworkPortInstantiations())
        ) {
            if ($instantiation = getItemForItemtype($this->fields['instantiation_type'])) {
                if (!$instantiation->getFromDB($this->getID())) {
                    if (!$instantiation->getEmpty()) {
                        unset($instantiation);
                        return false;
                    }
                }
                return $instantiation;
            }
        }
        return false;
    }


    /**
     * Change the instantion type of a NetworkPort : check validity of the new type of
     * instantiation and that it is not equal to current ones. Update the NetworkPort and delete
     * the previous instantiation. It is up to the caller to create the new instantiation !
     *
     * @since 0.84
     *
     * @param string $new_instantiation_type  the name of the new instaniation type
     *
     * @return boolean false on error, true if the previous instantiation is not available
     *                 (ie.: invalid instantiation type) or the object of the previous instantiation.
    **/
    public function switchInstantiationType($new_instantiation_type)
    {

        // First, check if the new instantiation is a valid one ...
        if (!in_array($new_instantiation_type, self::getNetworkPortInstantiations())) {
            return false;
        }

        // Load the previous instantiation
        $previousInstantiation = $this->getInstantiation();

        // If the previous instantiation is the same than the new one: nothing to do !
        if (
            ($previousInstantiation !== false)
            && ($previousInstantiation->getType() == $new_instantiation_type)
        ) {
            return $previousInstantiation;
        }

        // We update the current NetworkPort
        $input                       = $this->fields;
        $input['instantiation_type'] = $new_instantiation_type;
        $this->update($input);

        // Then, we delete the previous instantiation
        if ($previousInstantiation !== false) {
            $previousInstantiation->delete($previousInstantiation->fields);
            return $previousInstantiation;
        }

        return true;
    }

    public function prepareInputForUpdate($input)
    {
        if (!isset($input["_no_history"])) {
            $input['_no_history'] = false;
        }
        if (
            isset($input['_create_children'])
            && $input['_create_children']
        ) {
            $input = $this->splitInputForElements($input);
        }

        return parent::prepareInputForUpdate($input);
    }

    public function post_updateItem($history = 1)
    {
        global $DB;

        if (count($this->updates)) {
            // Update Ticket Tco
            if (
                in_array("itemtype", $this->updates)
                || in_array("items_id", $this->updates)
            ) {
                $ip = new IPAddress();
                // Update IPAddress
                foreach (
                    $DB->request(
                        'glpi_networknames',
                        ['itemtype' => 'NetworkPort',
                                             'items_id' => $this->getID()]
                    ) as $dataname
                ) {
                    foreach (
                        $DB->request(
                            'glpi_ipaddresses',
                            ['itemtype' => 'NetworkName',
                                                'items_id' => $dataname['id']]
                        ) as $data
                    ) {
                        $ip->update(['id'           => $data['id'],
                                          'mainitemtype' => $this->fields['itemtype'],
                                          'mainitems_id' => $this->fields['items_id']]);
                    }
                }
            }
        }
        parent::post_updateItem($history);

        $this->updateDependencies(!$this->input['_no_history']);
    }

    public function post_clone($source, $history)
    {
        parent::post_clone($source, $history);
        $instantiation = $source->getInstantiation();
        if ($instantiation !== false) {
            $instantiation->fields[$instantiation->getIndexName()] = $this->getID();
            return $instantiation->clone([], $history);
        }
    }

    /**
     * \brief split input fields when validating a port
     *
     * The form of the NetworkPort can contain the details of the NetworkPortInstantiation as well as
     * NetworkName elements (if no more than one name is attached to this port). Feilds from both
     * NetworkPortInstantiation and NetworkName must not be process by the NetworkPort::add or
     * NetworkPort::update. But they must be kept for adding or updating these elements. This is
     * done after creating or updating the current port. Otherwise, its ID may not be known (in case
     * of new port).
     * To keep the unused fields, we check each field key. If it is owned by NetworkPort (ie :
     * exists inside the $this->fields array), then they remain inside $input. If they are prefix by
     * "Networkname_", then they are added to $this->input_for_NetworkName. Else, they are for the
     * instantiation and added to $this->input_for_instantiation.
     *
     * This method must be call before NetworkPort::add or NetworkPort::update in case of NetworkPort
     * form. Otherwise, the entry of the database may contain wrong values.
     *
     * @since 0.84
     *
     * @param $input
     *
     * @see self::updateDependencies() for the update
    **/
    public function splitInputForElements($input)
    {

        if (
            isset($this->input_for_instantiation)
            || isset($this->input_for_NetworkName)
            || isset($this->input_for_NetworkPortConnect)
            || !isset($input)
        ) {
            return;
        }

        $this->input_for_instantiation      = [];
        $this->input_for_NetworkName        = [];
        $this->input_for_NetworkPortConnect = [];

        $clone = clone $this;
        $clone->getEmpty();

        foreach ($input as $field => $value) {
            if (array_key_exists($field, $clone->fields) || $field[0] == '_') {
                continue;
            }
            if (preg_match('/^NetworkName_/', (string) $field)) {
                $networkName_field = preg_replace('/^NetworkName_/', '', (string) $field);
                $this->input_for_NetworkName[$networkName_field] = $value;
            } elseif (preg_match('/^NetworkPortConnect_/', (string) $field)) {
                $networkName_field = preg_replace('/^NetworkPortConnect_/', '', (string) $field);
                $this->input_for_NetworkPortConnect[$networkName_field] = $value;
            } else {
                $this->input_for_instantiation[$field] = $value;
            }
            unset($input[$field]);
        }

        return $input;
    }


    /**
     * \brief update all related elements after adding or updating an element
     *
     * splitInputForElements() prepare the data for adding or updating NetworkPortInstantiation and
     * NetworkName. This method will update NetworkPortInstantiation and NetworkName. I must be call
     * after NetworkPort::add or NetworkPort::update otherwise, the networkport ID will not be known
     * and the dependencies won't have a valid items_id field.
     *
     * @since 0.84
     *
     * @param $history   (default 1)
     *
     * @see splitInputForElements() for preparing the input
    **/
    public function updateDependencies($history = true)
    {
        if ($this->networkPortOperation === null) {
            $this->withNetworkPortOperation(function () use ($history): void {
                $this->captureLifecycleWriter($GLOBALS['DB']);
                $this->applyNetworkPortDependencies($history);
            });
            return;
        }
        $this->applyNetworkPortDependencies($history);
    }

    private function applyNetworkPortDependencies($history): void
    {
        $this->assertNetworkPortWriter();
        $instantiation = $this->getInstantiation();
        if (
            $instantiation !== false
            && isset($this->input_for_instantiation)
            && count($this->input_for_instantiation) > 0
        ) {
            $this->input_for_instantiation['networkports_id'] = $this->getID();
            if ($instantiation->isNewID($instantiation->getID())) {
                $instantiation->add($this->input_for_instantiation, [], $history);
            } else {
                $instantiation->update($this->input_for_instantiation, $history);
            }
        }
        $this->assertNetworkPortWriter();
        unset($this->input_for_instantiation);

        if (
            isset($this->input_for_NetworkName)
            && count($this->input_for_NetworkName) > 0
            && !isset($_POST['several'])
        ) {
            // Check to see if the NetworkName is empty
            $empty_networkName = empty($this->input_for_NetworkName['name'])
                                 && empty($this->input_for_NetworkName['fqdns_id']);
            if (($empty_networkName) && is_array($this->input_for_NetworkName['_ipaddresses'])) {
                foreach ($this->input_for_NetworkName['_ipaddresses'] as $ip_address) {
                    if (!empty($ip_address)) {
                        $empty_networkName = false;
                        break;
                    }
                }
            }

            $network_name = new NetworkName();
            if (isset($this->input_for_NetworkName['id'])) {
                if ($empty_networkName) {
                    // If the NetworkName is empty, then delete it !
                    $network_name->delete($this->input_for_NetworkName, true, $history);
                } else {
                    // Else, update it
                    $network_name->update($this->input_for_NetworkName, $history);
                }
            } else {
                if (!$empty_networkName) { // Only create a NetworkName if it is not empty
                    $this->input_for_NetworkName['itemtype'] = 'NetworkPort';
                    $this->input_for_NetworkName['items_id'] = $this->getID();
                    $network_name->add($this->input_for_NetworkName, [], $history);
                }
            }
        }
        $this->assertNetworkPortWriter();
        unset($this->input_for_NetworkName);

        if (
            isset($this->input_for_NetworkPortConnect)
            && count($this->input_for_NetworkPortConnect) > 0
        ) {
            if (
                isset($this->input_for_NetworkPortConnect['networkports_id_1'])
                && isset($this->input_for_NetworkPortConnect['networkports_id_2'])
                && !empty($this->input_for_NetworkPortConnect['networkports_id_2'])
            ) {
                $nn  = new NetworkPort_NetworkPort();
                $nn->add($this->input_for_NetworkPortConnect, [], $history);
            }
        }
        $this->assertNetworkPortWriter();
        unset($this->input_for_NetworkPortConnect);
    }


    public function prepareInputForAdd($input)
    {

        if (isset($input["logical_number"]) && (strlen($input["logical_number"]) == 0)) {
            unset($input["logical_number"]);
        }

        if (!isset($input["_no_history"])) {
            $input['_no_history'] = false;
        }

        if (
            isset($input['_create_children'])
            && $input['_create_children']
        ) {
            $input = $this->splitInputForElements($input);
        }

        $scope = is_array($input) ? array_keys(array_intersect_key($input, array_flip(['entities_id', 'is_recursive']))) : [];
        if (!is_array($input)) {
            return false;
        }
        if (static::$mustBeAttached && $this->resolvedNetworkPortParent($input) === false) {
            return false;
        }
        $input = $this->addNeededInfoToInput($input);
        if (is_array($input)) {
            if ($this->resolvedNetworkPortParent($input) === false) {
                return false;
            }
            if ($this->networkPortOperation !== null) {
                $this->networkPortOperation['parent'] = [$input['itemtype'], $input['items_id']];
                $this->networkPortOperation['scope'] = $scope;
            }
        }
        return $input;
    }

    public function post_addItem()
    {
        $this->updateDependencies(!$this->input['_no_history']);
    }

    public function cleanDBonPurge()
    {
        global $DB;
        $origins = new NetworkPortAggregateRepository(Orm::create($DB));
        if (!empty($this->input['_replace_by'])) {
            $origins->replacePort((int)$this->getID(), (int)$this->input['_replace_by']);
        } else {
            $origins->removeForPort((int)$this->getID());
        }

        $instantiation = $this->getInstantiation();
        if ($instantiation !== false) {
            $instantiation->cleanDBonItemDelete($this->getType(), $this->getID());
            unset($instantiation);
        }

        $this->deleteChildrenAndRelationsFromDb(
            [
              NetworkPortAggregate::class,
              NetworkPortAlias::class,
              NetworkPortDialup::class,
              NetworkPortEthernet::class,
              NetworkPortFiberchannel::class,
              NetworkPortLocal::class,
              NetworkPortWifi::class,
              NetworkName::class,
              NetworkPort_NetworkPort::class,
              NetworkPort_Vlan::class,
            ]
        );
    }


    /**
     * Get port opposite port ID if linked item
     *
     * @param integer $ID  networking port ID
     *
     * @return integer|false  ID of the NetworkPort found, false if not found
    **/
    public function getContact($ID)
    {

        $wire = new NetworkPort_NetworkPort();
        if ($contact_id = $wire->getOppositeContact($ID)) {
            return $contact_id;
        }
        return false;
    }


    public function defineTabs($options = [])
    {

        $ong = [];
        $this->addDefaultFormTab($ong);
        $this->addStandardTab('NetworkName', $ong, $options);
        $this->addStandardTab('NetworkPort_Vlan', $ong, $options);
        $this->addStandardTab('Log', $ong, $options);
        $this->addStandardTab('NetworkPortInstantiation', $ong, $options);
        $this->addStandardTab('NetworkPort', $ong, $options);

        return $ong;
    }


    /**
     * Delete All connection of the given network port
     *
     * @param integer $ID ID of the port
     *
     * @return boolean true on success
    **/
    public function resetConnections($ID)
    {
    }


    /**
     * Get available display options array
     *
     * @since 0.84
     *
     * @return array  all the options
    **/
    public static function getAvailableDisplayOptions()
    {

        $options = [];
        $options[__('Global displays')]
           =  ['characteristics' => ['name'    => __('Characteristics'),
                                               'default' => true],
                    'internet'        => ['name'    => __('Internet information'),
                                               'default' => true],
                    'dynamic_import'  => ['name'    => __('Automatic inventory'),
                                               'default' => false]];
        $options[__('Common options')]
           = NetworkPortInstantiation::getGlobalInstantiationNetworkPortDisplayOptions();
        $options[__('Internet information')]
           = ['names'       => ['name'    => NetworkName::getTypeName(Session::getPluralNumber()),
                                          'default' => false],
                   'aliases'     => ['name'    => NetworkAlias::getTypeName(Session::getPluralNumber()),
                                          'default' => false],
                   'ipaddresses' => ['name'    => IPAddress::getTypeName(Session::getPluralNumber()),
                                          'default' => true],
                   'ipnetworks'  => ['name'    => IPNetwork::getTypeName(Session::getPluralNumber()),
                                          'default' => true]];

        foreach (self::getNetworkPortInstantiations() as $portType) {
            $portTypeName           = $portType::getTypeName(0);
            $options[$portTypeName] = $portType::getInstantiationNetworkPortDisplayOptions();
        }
        return $options;
    }


    /**
     * Show ports for an item
     *
     * @param $item                     CommonDBTM object
     * @param $withtemplate   integer   withtemplate param (default 0)
    **/
    public static function showForItem(CommonDBTM $item, $withtemplate = 0)
    {
        global $DB;

        $rand     = mt_rand();

        $itemtype = $item->getType();
        $items_id = $item->getField('id');

        if (
            !NetworkEquipment::canView()
            || !$item->can($items_id, READ)
        ) {
            return false;
        }

        $netport       = new self();
        $netport->item = $item;

        if (
            ($itemtype == 'NetworkPort')
            || ($withtemplate == 2)
        ) {
            $canedit = false;
        } else {
            $canedit = $item->canEdit($items_id);
        }
        $showmassiveactions = false;
        if ($withtemplate != 2) {
            $showmassiveactions = $canedit;
        }

        // Show Add Form
        if (
            $canedit
            && (empty($withtemplate) || ($withtemplate != 2))
        ) {
            $instantiations = [];
            foreach (self::getNetworkPortInstantiations() as $inst_type) {
                if (call_user_func([$inst_type, 'canCreate'])) {
                    $instantiations[$inst_type] = call_user_func([$inst_type, 'getTypeName']);
                }
            }

            $form = [
               'action' => $netport->getFormURL(),
               'method' => 'get',
               'buttons' => [
                  [
                     'type' => 'submit',
                     'name' => 'add',
                     'value' => _sx('button', 'Add'),
                     'class' => 'btn btn-secondary'
                  ]
               ],
               'content' => [
                  '' => [
                     'visible' => true,
                     'inputs' => [
                        [
                           'type' => 'hidden',
                           'name' => 'items_id',
                           'value' => $item->getID()
                        ],
                        [
                           'type' => 'hidden',
                           'name' => 'itemtype',
                           'value' => $item->getType()
                        ],
                        __('Network port type to be added') => [
                           'type' => 'select',
                           'name' => 'instantiation_type',
                           'values' => $instantiations,
                           'value' => 'NetworkPortEthernet',
                        ],
                        __('Add several ports') => [
                           'type' => 'checkbox',
                           'name' => 'several',
                           'value' => 0
                        ]
                     ]
                  ]
               ]
            ];
            renderTwigForm($form);
        }

        if ($itemtype == 'NetworkPort') {
            $porttypes = ['NetworkPortAlias', 'NetworkPortAggregate'];
        } else {
            $porttypes = self::getNetworkPortInstantiations();
            // Manage NetworkPortMigration
            $porttypes[] = '';
        }
        $display_options = self::getDisplayOptions($itemtype);
        $table           = new HTMLTableMain();
        $number_port     = self::countForItem($item);
        $table_options   = ['canedit'         => $canedit,
                                 'display_options' => &$display_options];

        // Make table name and add the correct show/hide parameters
        $table_name  = sprintf(__('%1$s: %2$d'), self::getTypeName($number_port), $number_port);

        // Add the link to the modal to display the options ...
        $table_namelink = self::getDisplayOptionsLink($itemtype);

        $table_name = sprintf(__('%1$s - %2$s'), $table_name, $table_namelink);

        $c_main = $table->addHeader('main', self::getTypeName(Session::getPluralNumber()));

        if (($display_options['dynamic_import']) && ($item->isDynamic())) {
            $table_options['display_isDynamic'] = true;
        } else {
            $table_options['display_isDynamic'] = false;
        }

        if ($display_options['characteristics']) {
            $c_instant = $table->addHeader('Instantiation', __('Characteristics'));
            $c_instant->setHTMLClass('center');
        }

        if ($display_options['internet']) {
            $options = ['names'       => 'NetworkName',
                             'aliases'     => 'NetworkAlias',
                             'ipaddresses' => 'IPAddress',
                             'ipnetworks'  => 'IPNetwork'];

            $table_options['dont_display'] = [];
            foreach ($options as $option => $itemtype_for_option) {
                if (!$display_options[$option]) {
                    $table_options['dont_display'][$itemtype_for_option] = true;
                }
            }

            $c_network = $table->addHeader('Internet', __('Internet information'));
            $c_network->setHTMLClass('center');
        } else {
            $c_network = null;
        }

        foreach ($porttypes as $portType) {
            if (empty($portType)) {
                $group_name  = 'Migration';
                $group_title = __('Network ports waiting for manual migration');
            } else {
                $group_name  = $portType;
                $group_title = $portType::getTypeName(Session::getPluralNumber());
            }

            $t_group = $table->createGroup($group_name, $group_title);

            $c_number  = $t_group->addHeader('NetworkPort', "#", $c_main);
            $c_name    = $t_group->addHeader("Name", __('Name'), $c_main);
            $c_name->setItemType('NetworkPort');
            $c_name->setHTMLClass('center');

            if ($table_options['display_isDynamic']) {
                $c_dynamic = $t_group->addHeader("Dynamic", __('Automatic inventory'), $c_main);
                $c_dynamic->setHTMLClass('center');
            }

            if ($display_options['characteristics']) {
                if (empty($portType)) {
                    NetworkPortMigration::getMigrationInstantiationHTMLTableHeaders(
                        $t_group,
                        $c_instant,
                        $c_network,
                        null,
                        $table_options
                    );
                } else {
                    $instantiation = new $portType();
                    $instantiation->getInstantiationHTMLTableHeaders(
                        $t_group,
                        $c_instant,
                        $c_network,
                        null,
                        $table_options
                    );
                    unset($instantiation);
                }
            }

            if (
                $display_options['internet']
                && !$display_options['characteristics']
            ) {
                NetworkName::getHTMLTableHeader(__CLASS__, $t_group, $c_network, null, $table_options);
            }

            if ($itemtype == 'NetworkPort') {
                switch ($portType) {
                    case 'NetworkPortAlias':
                        $search_table   = 'glpi_networkportaliases';
                        $search_request = ['networkports_id_alias' => $items_id];
                        break;

                    case 'NetworkPortAggregate':
                        $search_table   = 'glpi_networkportaggregates';
                        $search_request = [];
                        break;
                }
                $criteria = [
                   'SELECT' => 'networkports_id AS id',
                   'FROM'   => $search_table,
                   'WHERE'  => $search_request
                ];
            } else {
                $criteria = [
                   'SELECT' => 'id',
                   'FROM'   => 'glpi_networkports',
                   'WHERE'  => [
                      'items_id'           => $items_id,
                      'itemtype'           => $itemtype,
                      'instantiation_type' => $portType,
                      'is_deleted'         => 0
                   ],
                   'ORDER'  => ['name', 'logical_number']
                ];
            }

            $iterator = $itemtype === 'NetworkPort' && $portType === 'NetworkPortAggregate'
                ? new RowIterator(Orm::readPrepared(
                    $DB,
                    static fn (): int => (int)$items_id,
                    static fn (EntityManager $manager, int $port): array =>
                        (new NetworkPortAggregateRepository($manager))->aggregatesForPort($port)
                ))
                : $DB->request($criteria);
            $number_port = count($iterator);

            if ($number_port != 0) {
                $is_active_network_port = true;

                $save_canedit = $canedit;

                if (!empty($portType)) {
                    $name = sprintf(
                        __('%1$s (%2$s)'),
                        self::getTypeName($number_port),
                        call_user_func([$portType, 'getTypeName'])
                    );
                    $name = sprintf(__('%1$s: %2$s'), $name, $number_port);
                } else {
                    $name    = __('Network ports waiting for manual migration');
                    $canedit = false;
                }

                $values = [];
                $massive_actions = [];
                while ($devid = $iterator->next()) {
                    $t_row = $t_group->createRow();

                    $netport->getFromDB(current($devid));

                    // No massive action for migration ports
                    $content = "<span class='b'>";
                    // Display link based on default rights
                    if (
                        $save_canedit
                          && ($withtemplate != 2)
                    ) {
                        if (!empty($portType)) {
                            $content .= "<a href=\"" . NetworkPort::getFormURLWithID($netport->fields["id"]) . "\">";
                        } else {
                            $content .= "<a href=\"" . NetworkPortMigration::getFormURLWithID($netport->fields["id"]) . "\">";
                        }
                    }
                    $content .= $netport->fields["logical_number"];

                    if (
                        $canedit
                          && ($withtemplate != 2)
                    ) {
                        $content .= "</a>";
                    }
                    $content .= "</span>";
                    $content .= Html::showToolTip(
                        $netport->fields['comment'],
                        ['display' => false]
                    );

                    $t_row->addCell($c_number, $content);

                    $value = $netport->fields["name"];
                    $t_row->addCell($c_name, $value, null, $netport);

                    if ($table_options['display_isDynamic']) {
                        $t_row->addCell(
                            $c_dynamic,
                            Dropdown::getYesNo($netport->fields['is_dynamic'])
                        );
                    }
                    if ($display_options['characteristics']) {
                        $instantiation = $netport->getInstantiation();
                        if ($instantiation !== false) {
                            $instantiation->getInstantiationHTMLTable(
                                $netport,
                                $t_row,
                                null,
                                $table_options
                            );
                            unset($instantiation);
                        }
                    } elseif ($display_options['internet']) {
                        NetworkName::getHTMLTableCellsForItem($t_row, $netport, null, $table_options);
                    }
                    $headers = $t_row->getGroup()->headers;
                    if (!isset($categories)) {
                        $categories = [];
                        foreach ($headers as $header) {
                            foreach ($header as $key => $value) {
                                ob_start();
                                $value->displayContent();
                                $categories[$key] = ob_get_clean();
                            }
                        }
                    }
                    $newValue = [];
                    foreach ($t_row->cells as $name => $cell) {
                        foreach ($cell as $key => $value) {
                            $content = $value->content;
                            if (gettype($content) == 'array') {
                                $classtype = $content[0]['function'][0];
                                $funcname = $content[0]['function'][1];
                                ob_start();
                                $classtype::$funcname($netport);
                                $content = ob_get_clean();
                            }
                            $headerName = explode(':', (string) $name)[1];
                            $newValue[$headerName] = $content;
                        }
                    }
                    $values[] = $newValue;
                    $massive_actions[] = sprintf('item[%s][%s]', $netport->getType(), $netport->getID());
                }
                $canedit = $save_canedit;
                echo '<hr><h2>' . $group_title . '</h2>';
                $fields = $categories;
                $rand = mt_rand();
                $massiveContainerId = 'mass' . $netport->getType() . $rand;
                if ($canedit) {
                    Html::showMassiveActions([
                       'display_arrow' => false,
                       'container' => $massiveContainerId,
                       'is_deleted' => 0,
                       'check_itemtype' => $item->getType(),
                       'check_items_id' => $item->getID(),
                       'itemtype' => $netport->getType(),
                    ]);
                }
                renderTwigTemplate('table.twig', [
                   'id' => $massiveContainerId,
                   'fields' => $fields,
                   'values' => $values,
                   'massive_action' => $massive_actions,
                ]);
            }
        }
    }


    public function showForm($ID, $options = [])
    {
        if (!isset($options['several'])) {
            $options['several'] = false;
        }

        if (!self::canView()) {
            return false;
        }

        $this->initForm($ID, $options);

        $recursiveItems = $this->recursivelyGetItems();
        if (count($recursiveItems) > 0) {
            $lastItem             = $recursiveItems[count($recursiveItems) - 1];
            $lastItem_entities_id = $lastItem->getField('entities_id');
        } else {
            $lastItem_entities_id = $_SESSION['glpiactive_entity'];
        }

        $options['entities_id'] = $lastItem_entities_id;

        ob_start();
        $this->displayRecursiveItems($recursiveItems, 'Type');
        $type = ob_get_clean();

        ob_start();
        $this->displayRecursiveItems($recursiveItems, "Link");
        $link = ob_get_clean();

        $instantiation = $this->getInstantiation();

        $form = [
           'action' => $this->getFormURL(),
           'itemtype' => $this::class,
            'content' => [
              NetworkPort::getTypeName() => [
                 'visible' => true,
                 'inputs' => [
                    $this->isNewID($ID) ? [] : [
                       'type' => 'hidden',
                       'name' => 'id',
                       'value' => $ID
                    ],
                    [
                       'type' => 'hidden',
                       'name' => 'items_id',
                       'value' => $this->fields["items_id"]
                    ],
                    [
                       'type' => 'hidden',
                       'name' => 'itemtype',
                       'value' => $this->fields["itemtype"]
                    ],
                    [
                       'type' => 'hidden',
                       'name' => '_create_children',
                       'value' => 1
                    ],
                    [
                       'type' => 'hidden',
                       'name' => 'instantiation_type',
                       'value' => $this->fields["instantiation_type"]
                    ],
                    $type . ' :' => [
                       'content' => $link
                    ],
                    __('Name') . ' :' => [
                       'type' => 'text',
                       'name' => 'name',
                       'value' => $this->fields["name"]
                    ],
                    _n('Port number', 'Port numbers', 1) => (!$options['several']) ? [
                       'type' => 'number',
                       'name' => 'logical_number',
                       'value' => $this->fields["logical_number"],
                    ] : [],
                    _n('Port number', 'Port numbers', Session::getPluralNumber()) . ' ' . __('from') . ' :' => ($options['several']) ? [
                       'type' => 'number',
                       'name' => 'from_logical_number',
                       'value' => $this->fields["logical_number"],
                    ] : [],
                    _n('Port number', 'Port numbers', Session::getPluralNumber()) . ' ' . __('to') . ' :' => ($options['several']) ? [
                       'type' => 'number',
                       'name' => 'to_logical_number',
                       'value' => $this->fields["logical_number"],
                    ] : [],
                    __('Comments') . ' :' => [
                       'type' => 'textarea',
                       'name' => 'comment',
                       'value' => $this->fields["comment"],
                       'col_lg' => 12,
                       'col_md' => 12,
                    ]
                 ]
              ],
            ]
        ];
        if ($instantiation !== false) {
            $form['content'] = array_merge($form['content'], $instantiation->showInstantiationForm($this, $options, $recursiveItems));
        }
        if (!$options['several']) {
            $form['content'] = array_merge($form['content'], NetworkName::showFormForNetworkPort($this->getID()));
        }
        renderTwigForm($form, '', $this->fields);
    }


    /**
     * @param $itemtype
    **/
    public static function rawSearchOptionsToAdd($itemtype = null)
    {
        $tab = [];

        $tab[] = [
           'id'                 => 'network',
           'name'               => __('Networking')
        ];

        $joinparams = ['jointype' => 'itemtype_item'];

        $tab[] = [
           'id'                 => '21',
           'table'              => 'glpi_networkports',
           'field'              => 'mac',
           'name'               => __('MAC address'),
           'datatype'           => 'mac',
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => $joinparams
        ];

        $tab[] = [
           'id'                 => '87',
           'table'              => 'glpi_networkports',
           'field'              => 'instantiation_type',
           'name'               => __('Network port type'),
           'datatype'           => 'itemtypename',
           'itemtype_list'      => 'networkport_instantiations',
           'massiveaction'      => false,
           'joinparams'         => $joinparams
        ];

        $networkNameJoin = ['jointype'          => 'itemtype_item',
                                 'specific_itemtype' => 'NetworkPort',
                                 'condition'         => 'AND NEWTABLE.`is_deleted` = \'0\'',
                                 'beforejoin'        => ['table'      => 'glpi_networkports',
                                                              'joinparams' => $joinparams]];
        NetworkName::rawSearchOptionsToAdd($tab, $networkNameJoin, $itemtype);

        $instantjoin = ['jointype'   => 'child',
                             'beforejoin' => ['table'      => 'glpi_networkports',
                                                   'joinparams' => $joinparams]];
        foreach (self::getNetworkPortInstantiations() as $instantiationType) {
            $instantiationType::getSearchOptionsToAddForInstantiation($tab, $instantjoin);
        }

        $netportjoin = [['table'      => 'glpi_networkports',
                                   'joinparams' => ['jointype' => 'itemtype_item']],
                             ['table'      => 'glpi_networkports_vlans',
                                   'joinparams' => ['jointype' => 'child']]];

        $tab[] = [
           'id'                 => '88',
           'table'              => 'glpi_vlans',
           'field'              => 'name',
           'name'               => Vlan::getTypeName(1),
           'datatype'           => 'dropdown',
           'forcegroupby'       => true,
           'massiveaction'      => false,
           'joinparams'         => ['beforejoin' => $netportjoin]
        ];

        return $tab;
    }


    public function getSpecificMassiveActions($checkitem = null)
    {

        $isadmin = $checkitem !== null && $checkitem->canUpdate();
        $actions = parent::getSpecificMassiveActions($checkitem);
        if ($isadmin) {
            $vlan_prefix                    = 'NetworkPort_Vlan' . MassiveAction::CLASS_ACTION_SEPARATOR;
            $actions[$vlan_prefix . 'add']    = __('Associate a VLAN');
            $actions[$vlan_prefix . 'remove'] = __('Dissociate a VLAN');
        }
        return $actions;
    }


    public function rawSearchOptions()
    {
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
           'type'               => 'text',
           'massiveaction'      => false,
           'datatype'           => 'itemlink',
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
           'field'              => 'logical_number',
           'name'               => _n('Port number', 'Port numbers', 1),
           'datatype'           => 'integer',
           'autocomplete'       => true,
        ];

        $tab[] = [
           'id'                 => '4',
           'table'              => $this->getTable(),
           'field'              => 'mac',
           'name'               => __('MAC address'),
           'datatype'           => 'mac',
           'autocomplete'       => true,
        ];

        $tab[] = [
           'id'                 => '5',
           'table'              => $this->getTable(),
           'field'              => 'instantiation_type',
           'name'               => __('Network port type'),
           'datatype'           => 'itemtypename',
           'itemtype_list'      => 'networkport_instantiations',
           'massiveaction'      => false
        ];

        if ($this->isField('netpoints_id')) {
            $tab[] = [
               'id'                 => '9',
               'table'              => 'glpi_netpoints',
               'field'              => 'name',
               'name'               => _n('Network outlet', 'Network outlets', 1),
               'datatype'           => 'dropdown'
            ];
        }

        $tab[] = [
           'id'                 => '16',
           'table'              => $this->getTable(),
           'field'              => 'comment',
           'name'               => __('Comments'),
           'datatype'           => 'text'
        ];

        $tab[] = [
           'id'                 => '20',
           'table'              => $this->getTable(),
           'field'              => 'itemtype',
           'name'               => _n('Type', 'Types', 1),
           'datatype'           => 'itemtype',
           'massiveaction'      => false
        ];

        $tab[] = [
           'id'                 => '21',
           'table'              => $this->getTable(),
           'field'              => 'items_id',
           'name'               => __('ID'),
           'datatype'           => 'integer',
           'massiveaction'      => false
        ];

        return $tab;
    }


    /**
     * Clone the current NetworkPort when the item is clone
     *
     * @deprecated 9.5
     * @since 0.84
     *
     * @param string  $itemtype      the type of the item that was clone
     * @param integer $old_items_id  the id of the item that was clone
     * @param integer $new_items_id  the id of the item after beeing cloned
    **/
    public static function cloneItem($itemtype, $old_items_id, $new_items_id)
    {
        global $DB;

        Toolbox::deprecated('Use clone');
        $np = new self();
        // ADD Ports
        $iterator = $DB->request([
           'FROM'   => self::getTable(),
           'WHERE'  => [
              'items_id'  => $old_items_id,
              'itemtype'  => $itemtype
           ]
        ]);

        while ($data = $iterator->next()) {
            $np->getFromDB($data["id"]);
            $instantiation = $np->getInstantiation();
            unset($np->fields["id"]);
            $np->fields = NetworkPortRecord::withReference($np->fields, $np->fields['itemtype'], 0);
            $np->fields['items_id'] = $new_items_id;
            $np->fields = (new NetworkPortRecord())->normalizeInput($np->fields);
            $portid                 = $np->addToDB();

            if ($instantiation !== false) {
                $input = [];
                $input["networkports_id"] = $portid;
                unset($instantiation->fields["id"]);
                unset($instantiation->fields["networkports_id"]);
                foreach ($instantiation->fields as $key => $val) {
                    if (!empty($val)) {
                        $input[$key] = $val;
                    }
                }
                $instantiation->add($input);
                unset($instantiation);
            }

            $npv = new NetworkPort_Vlan();
            foreach (
                NetworkPort_Vlan::membershipsForPort($data["id"]) as $vlan
            ) {
                $input = [$npv::$items_id_1 => $portid,
                               $npv::$items_id_2 => $vlan['vlans_id']];
                if (isset($vlan['tagged'])) {
                    $input['tagged'] = $vlan['tagged'];
                }
                $npv->add($input);
            }
        }
    }


    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        global $CFG_GLPI, $DB;

        // Can exists on template
        $nb = 0;
        if (NetworkEquipment::canView()) {
            if (in_array($item->getType(), $CFG_GLPI["networkport_types"])) {
                if ($_SESSION['glpishow_count_on_tabs']) {
                    $nb = self::countForItem($item);
                }
                return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $nb);
            }
        }

        if ($item->getType() == 'NetworkPort') {
            $nbAlias = countElementsInTable(
                'glpi_networkportaliases',
                ['networkports_id_alias' => $item->getField('id')]
            );
            if ($nbAlias > 0) {
                $aliases = self::createTabEntry(NetworkPortAlias::getTypeName(Session::getPluralNumber()), $nbAlias);
            } else {
                $aliases = '';
            }
            $nbAggregates = count(Orm::readPrepared(
                $DB,
                static fn (): int => (int)$item->getField('id'),
                static fn (EntityManager $manager, int $port): array =>
                    (new NetworkPortAggregateRepository($manager))->aggregatesForPort($port)
            ));
            if ($nbAggregates > 0) {
                $aggregates = self::createTabEntry(
                    NetworkPortAggregate::getTypeName(Session::getPluralNumber()),
                    $nbAggregates
                );
            } else {
                $aggregates = '';
            }
            if (!empty($aggregates) && !empty($aliases)) {
                return $aliases . '/' . $aggregates;
            }
            return $aliases . $aggregates;
        }
        return '';
    }


    /**
     * @param CommonDBTM $item
    **/
    public static function countForItem(CommonDBTM $item)
    {

        return countElementsInTable(
            'glpi_networkports',
            ['itemtype'   => $item->getType(),
                                     'items_id'   => $item->getField('id'),
                                     'is_deleted' => 0 ]
        );
    }


    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $CFG_GLPI;

        if (
            in_array($item->getType(), $CFG_GLPI["networkport_types"])
            || ($item->getType() == 'NetworkPort')
        ) {
            self::showForItem($item, $withtemplate);
            return true;
        }
    }


    /**
     * @since 0.85
     *
     * @see CommonDBConnexity::getConnexityMassiveActionsSpecificities()
    **/
    public static function getConnexityMassiveActionsSpecificities()
    {

        $specificities                           = parent::getConnexityMassiveActionsSpecificities();

        $specificities['reaffect']               = true;
        $specificities['itemtypes']              = ['Computer', 'NetworkEquipment'];

        $specificities['normalized']['unaffect'] = [];
        $specificities['action_name']['affect']  = _x('button', 'Move');

        return $specificities;
    }

    public function computeFriendlyName()
    {
        global $DB;

        $iterator = $DB->request([
           'SELECT' => ['name'],
           'FROM'   => $this->fields['itemtype']::getTable(),
           'WHERE'  => ['id' => $this->fields['items_id']]
        ]);

        if ($iterator->count()) {
            return sprintf(__('%1$s on %2$s'), parent::computeFriendlyName(), $iterator->next()['name']);
        }

        return parent::computeFriendlyName();
    }
}
