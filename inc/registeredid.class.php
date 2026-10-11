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
use Doctrine\DBAL\Connection;
use itsmng\Database\DeletionCancelled;
use itsmng\Database\DeletionUnit;
use itsmng\Database\EntityRegistry;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\LifecycleModelJournal;
use itsmng\Database\TransactionOwnershipMismatch;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RegisteredIDRepository;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * RegisteredID class
 * @since 0.85
**/
class RegisteredID extends CommonDBChild
{
    // From CommonDBTM
    public $auto_message_on_action = false;

    // From CommonDBChild
    public static $itemtype        = 'itemtype';
    public static $items_id        = 'items_id';
    public $dohistory              = true;

    private bool $parentOperationActive = false;
    private ?DBAdapter $parentWriter = null;
    private ?Connection $parentConnection = null;
    private ?array $admittedParent = null;
    private ?self $parentReceiver = null;
    private ?array $parentPendingState = null;

    /** Admission state belongs to this public operation, including nested reuse. */
    public function add(array $input, $options = [], $history = true)
    {
        $outer = $this->parentOperationActive ? new LifecycleModelJournal() : null;
        $outer?->remember($this);
        $previous = [$this->parentWriter, $this->parentConnection, $this->admittedParent, $this->parentReceiver, $this->parentPendingState, $this->parentOperationActive];
        $this->parentOperationActive = true;
        try {
            return parent::add($input, $options, $history);
        } finally {
            [$this->parentWriter, $this->parentConnection, $this->admittedParent, $this->parentReceiver, $this->parentPendingState, $this->parentOperationActive] = $previous;
            $outer?->restore();
        }
    }

    public function update(array $input, $history = 1, $options = [])
    {
        $outer = $this->parentOperationActive ? new LifecycleModelJournal() : null;
        $outer?->remember($this);
        $previous = [$this->parentWriter, $this->parentConnection, $this->admittedParent, $this->parentReceiver, $this->parentPendingState, $this->parentOperationActive];
        $this->parentOperationActive = true;
        try {
            return parent::update($input, $history, $options);
        } finally {
            [$this->parentWriter, $this->parentConnection, $this->admittedParent, $this->parentReceiver, $this->parentPendingState, $this->parentOperationActive] = $previous;
            $outer?->restore();
        }
    }

    public function delete(array $input, $force = 0, $history = 1)
    {
        $outer = $this->parentOperationActive ? new LifecycleModelJournal() : null;
        $outer?->remember($this);
        $previous = [$this->parentWriter, $this->parentConnection, $this->admittedParent, $this->parentReceiver, $this->parentPendingState, $this->parentOperationActive];
        $this->parentOperationActive = true;
        try {
            return parent::delete($input, $force, $history);
        } finally {
            [$this->parentWriter, $this->parentConnection, $this->admittedParent, $this->parentReceiver, $this->parentPendingState, $this->parentOperationActive] = $previous;
            $outer?->restore();
        }
    }

    protected function captureLifecycleWriter(DBAdapter $writer): void
    {
        parent::captureLifecycleWriter($writer);
        $connection = $writer->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($writer, $connection);
        $this->parentWriter = $writer;
        $this->parentConnection = $connection;
        $this->admittedParent = null;
        $this->parentReceiver = $this;
        $this->parentPendingState = null;
        $this->assertParentWriter();
    }

    private function assertParentWriter(): void
    {
        if ($this->parentWriter === null || $this->parentConnection === null) {
            throw new LogicException('Registered identifier admission has no selected writer.');
        }
        global $DB;
        if ($DB !== $this->parentWriter) {
            throw new TransactionOwnershipMismatch('Registered identifier admission changed its selected writer.');
        }
        $receiver = $this->parentReceiver ?? $this;
        $state = LifecycleModelJournal::state($receiver);
        $context = $this->parentContext();
        $connection = $this->parentWriter->getDoctrineConnection();
        if ($DB !== $this->parentWriter || $connection !== $this->parentConnection) {
            throw new TransactionOwnershipMismatch('Registered identifier admission changed its selected connection.');
        }
        OwnershipUpdateUnit::assertResolvedWriter($this->parentWriter, $this->parentConnection);
        if (LifecycleModelJournal::state($receiver) !== $state || $this->parentContext() !== $context) {
            throw new TransactionOwnershipMismatch('Registered identifier writer lookup changed the pending receiver.');
        }
    }

    /** Load the actual final target; an adopted literal cannot route to a different table. */
    private function resolvedParent(array $values): CommonDBTM|false
    {
        $this->assertParentWriter();
        $kind = $values['itemtype'] ?? null;
        $identity = $values['items_id'] ?? null;
        if (!is_string($kind) || $kind === '' || !is_int($identity) || $identity <= 0) {
            return false;
        }
        $parent = getItemForItemtype($kind);
        $this->assertParentWriter();
        if (!$parent instanceof CommonDBTM) {
            return false;
        }
        $selection = EntityRegistry::discriminatedReferences(static::getTable())['items_id']['selections'][$kind] ?? null;
        $table = $parent->getTable();
        $this->assertParentWriter();
        if ($selection !== null && $selection['target'] !== $table) {
            return false;
        }
        if (!$parent->getFromDB($identity)) {
            $this->assertParentWriter();
            return false;
        }
        $this->assertParentWriter();
        $loadedIdentity = $parent->getID();
        $loadedTable = $parent->getTable();
        $this->assertParentWriter();
        return (int)$loadedIdentity === $identity && $loadedTable === $table ? $parent : false;
    }

    public function prepareInputForAdd($input)
    {
        $state = LifecycleModelJournal::state($this);
        $context = $this->parentContext();
        if (!is_array($input) || $this->resolvedParent($input) === false
            || !$this->retainsParentReceiver($state, $context)) {
            return false;
        }
        // The validated load supplies CommonDBChild's mustBeAttached proof;
        // retain its existing forwarding continuation without a second blind load.
        $input = $this->addNeededInfoToInput($input);
        if (!$this->retainsParentReceiver($state, $context)) {
            return false;
        }
        $this->admittedParent = [$input['itemtype'], $input['items_id']];
        return $input;
    }

    protected function executePreparedAdd(callable $operation, array $priorState): mixed
    {
        $this->assertParentWriter();
        $state = LifecycleModelJournal::state($this);
        $context = $this->parentContext();
        $fields = $this->fields;
        $input = $this->input;
        $values = $this->normalizeLifecycleInput($fields);
        if (!$this->retainsParentReceiver($state, $context) || $values === false
            || $this->resolvedParent($values) === false || !$this->retainsParentReceiver($state, $context)
            || $this->admittedParent === null) {
            return false;
        }
        if ([$values['itemtype'], $values['items_id']] !== $this->admittedParent) {
            $probe = clone $this;
            $probe->fields = $values;
            $probe->input = array_replace($input, $values);
            $allowed = $probe->canCreateItem();
            if (!$this->retainsParentReceiver($state, $context) || !$allowed) {
                return false;
            }
        }
        if (!$this->retainsParentReceiver($state, $context)) {
            return false;
        }
        $this->fields = $values;
        $this->input = array_replace($input, $values);
        $prepared = LifecycleModelJournal::state($this);
        $context = $this->parentContext();
        return parent::executePreparedAdd(
            fn () => $this->retainsParentReceiver($prepared, $context) ? $operation() : false,
            $priorState
        );
    }

    private function parentContext(): array
    {
        return [$this->parentWriter, $this->parentConnection, $this->admittedParent, $this->parentReceiver];
    }

    private function retainsParentReceiver(array $state, array $context): bool
    {
        $this->assertParentWriter();
        return LifecycleModelJournal::state($this) === $state && $this->parentContext() === $context;
    }

    public function getConnexityItem($itemtype, $items_id, $getFromDB = true, $getEmpty = true, $getFromDBOrEmpty = false)
    {
        if ($this->parentReceiver !== null && $itemtype === static::$itemtype && $items_id === static::$items_id
            && $getFromDB && !$getFromDBOrEmpty) {
            return $this->resolvedParent($this->fields);
        }
        return parent::getConnexityItem($itemtype, $items_id, $getFromDB, $getEmpty, $getFromDBOrEmpty);
    }

    public function checkAttachedItemChangesAllowed(array $input, array $fields)
    {
        if ($this->parentReceiver === null) {
            return parent::checkAttachedItemChangesAllowed($input, $fields);
        }
        $receiver = $this->parentReceiver;
        if ($receiver === $this) {
            [$state, $context] = [LifecycleModelJournal::state($receiver), $receiver->parentContext()];
        } else {
            $this->parentPendingState ??= [LifecycleModelJournal::state($receiver), $receiver->parentContext()];
            [$state, $context] = $this->parentPendingState;
        }
        $allowed = parent::checkAttachedItemChangesAllowed($input, $fields);
        return $receiver->retainsParentReceiver($state, $context) && $allowed;
    }

    protected function validateLifecycleEndpoints(array $input): array|false
    {
        $receiver = $this->parentReceiver ?? $this;
        [$state, $context] = $this->parentPendingState ?? [LifecycleModelJournal::state($receiver), $receiver->parentContext()];
        $stored = $this->normalizeLifecycleInput($this->fields);
        if (!$receiver->retainsParentReceiver($state, $context)) {
            return false;
        }
        if ($stored !== false && [$stored['itemtype'], $stored['items_id']] === [$input['itemtype'], $input['items_id']]) {
            return $input;
        }
        $parent = $this->resolvedParent($input);
        return $receiver->retainsParentReceiver($state, $context) && $parent !== false ? $input : false;
    }

    protected function retainLifecycleEndpointDecision(CommonDBConnexity $probe): void
    {
        if ($probe instanceof self && $probe->parentPendingState !== null) {
            [$state, $context] = $probe->parentPendingState;
            if (!$this->retainsParentReceiver($state, $context)) {
                throw new InvalidArgumentException('Registered identifier callbacks changed the pending write receiver.');
            }
        }
    }

    protected function executePreparedUpdate(callable $operation, array $storedFields): bool
    {
        $state = LifecycleModelJournal::state($this);
        $context = $this->parentContext();
        return parent::executePreparedUpdate(
            fn () => $this->retainsParentReceiver($state, $context) ? $operation() : false,
            $storedFields
        );
    }

    /** Containing owner cleanup uses public child lifecycles on the supplied deletion writer. */
    public static function cleanForContainingParent(CommonDBTM $parent): void
    {
        global $DB;
        $writer = $DB;
        $connection = $writer->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($writer, $connection);
        $kind = $parent->getType();
        $identity = $parent->getID();
        $table = $parent->getTable();
        $policy = EntityRegistry::discriminatedReferences(static::getTable())['items_id'] ?? [];
        $selection = $policy['selections'][$kind] ?? null;
        if ($selection === null || $selection['target'] !== $table) {
            return;
        }
        $replacement = $parent->input['_replace_by'] ?? 0;
        $assertSource = static function () use ($writer, $connection, $parent, $kind, $identity, $table, $replacement): void {
            OwnershipUpdateUnit::assertResolvedWriter($writer, $connection);
            if ($parent->getType() !== $kind || $parent->getID() !== $identity || $parent->getTable() !== $table
                || ($parent->input['_replace_by'] ?? 0) !== $replacement) {
                throw new DeletionCancelled('Registered identifier cleanup changed its containing owner.');
            }
        };
        $assertSource();
        $children = (new static())->find(['itemtype' => $kind, 'items_id' => $identity]);
        $children = array_map(static fn (array $row): array => ['id' => $row['id'], 'itemtype' => $row['itemtype']], $children);
        $assertSource();
        foreach ($children as $child) {
            $model = new static();
            if (!$model->getFromDB($child['id']) || $model->fields['itemtype'] !== $child['itemtype']
                || (int)$model->fields['items_id'] !== (int)$identity) {
                throw new DeletionCancelled('Registered identifier cleanup lost its selected child.');
            }
            $assertSource();
            $model->captureLifecycleWriter($writer);
            $values = $model->normalizeLifecycleInput($model->fields);
            $source = $values === false ? false : $model->resolvedParent($values);
            if ($source === false || $source->getTable() !== $table) {
                throw new DeletionCancelled('Registered identifier cleanup cannot resolve its actual source role.');
            }
            if (!empty($replacement)) {
                $values = $model->normalizeLifecycleInput(['itemtype' => $child['itemtype'], 'items_id' => $replacement]);
                $destination = $values === false ? false : $model->resolvedParent($values);
                if ($destination === false || $destination->getTable() !== $table) {
                    throw new DeletionCancelled('Registered identifier cleanup cannot resolve its actual destination role.');
                }
            }
            $assertSource();
            $success = empty($replacement)
                ? $model->delete(['id' => $child['id'], '_disablenotif' => true], true)
                : $model->update(['id' => $child['id'], 'itemtype' => $child['itemtype'], 'items_id' => $replacement, '_disablenotif' => true]);
            DeletionUnit::requireSuccess($connection, $success);
            $assertSource();
        }
    }

    /** Fixed registered-identifier form values; the arbitrary Twig selection helper remains separate. */
    public static function getFormOptions(string $itemtype, $items_id): array
    {
        global $DB;
        $table = getTableForItemType('RegisteredID');
        $database = $DB;
        // Legacy SQL-null sentinels are recognized before object string conversion.
        $identity = $items_id === null || (is_string($items_id) && strtolower($items_id) === 'null')
            ? null : (is_int($items_id) ? $items_id : (is_bool($items_id) ? (int)$items_id : (string)$items_id));
        return Orm::read(
            $database,
            static fn (EntityManager $manager): array => (new RegisteredIDRepository($manager))->formOptions($table, $itemtype, $identity)
        );
    }

    public static function getRegisteredIDTypes()
    {

        return ['PCI' => __('PCI'),
                     'USB' => __('USB')];
    }


    public static function getTypeName($nb = 0)
    {
        return _n('Registered ID (issued by PCI-SIG)', 'Registered IDs (issued by PCI-SIG)', $nb);
    }


    /**
     * @param $field_name
     * @param $child_count_js_var
     *
     * @return string
    **/
    public static function getJSCodeToAddForItemChild($field_name, $child_count_js_var)
    {

        $result = "<select name='" . $field_name . "_type[-'+$child_count_js_var+']' aria-label='" . $field_name . "'>";
        $result .= "<option value=\'\'>" . Dropdown::EMPTY_VALUE . "</option>";
        foreach (self::getRegisteredIDTypes() as $name => $label) {
            $result .= "<option value=\'$name\'>$label</option>";
        }
        $result .= "</select> : ";
        $result .= "<input type=\'text\' size=\'30\' " . "name=\'" . $field_name .
                  "[-'+$child_count_js_var+']\'>";
        return $result;
    }


    /**
     * @see CommonDBChild::showChildForItemForm()
    **/
    public function showChildForItemForm($canedit, $field_name, $id)
    {

        if ($this->isNewID($this->getID())) {
            $value = '';
        } else {
            $value = $this->getName();
        }
        $main_field        = $field_name . "[$id]";
        $type_field        = $field_name . "_type[$id]";
        $registeredIDTypes = self::getRegisteredIDTypes();

        if ($canedit) {
            echo "<select aria-label='$field_name' name='$type_field'>";
            echo "<option value=''>" . Dropdown::EMPTY_VALUE . "</option>";
            foreach ($registeredIDTypes as $name => $label) {
                echo "<option value='$name'";
                if ($this->fields['device_type'] == $name) {
                    echo " selected";
                }
                echo ">$label</option>";
            }
            echo "</select> : <input type='text' size='30' name='$main_field' value='$value'>\n";
        } else {
            echo "<input type='hidden' name='$main_field' value='$value'>";
            if (!empty($this->fields['device_type'])) {
                printf(
                    __('%1$s: %2$s'),
                    $registeredIDTypes[$this->fields['device_type']],
                    $value
                );
            } else {
                echo $value;
            }
        }
    }
}
