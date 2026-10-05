<?php

namespace tests\units;

use DbTestCase;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\Orm;
use itsmng\Database\OwnedMutationFrame;
use itsmng\Database\Repository\DropdownDictionaryRepository;
use itsmng\Database\Repository\PrinterDictionaryRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\TransactionOwnershipMismatch;

class RuleDictionnaryDropdownCollection extends DbTestCase
{
    protected function dictionaryCallbackProvider(): array
    {
        $cases = [];
        foreach (['move', 'remove', 'model'] as $operation) {
            foreach (['success', 'commit-reopen', 'rollback-reopen', 'writer', 'veto', 'primary', 'commit-primary', 'rollback-primary'] as $scenario) {
                $cases[$operation . '-' . $scenario] = [$operation, $scenario];
            }
        }
        return $cases;
    }

    /** @dataProvider dictionaryCallbackProvider */
    public function testDictionaryCallbackRetainsItsExactWriterFrame(string $operation, string $scenario): void
    {
        global $DB;
        $this->login();
        $database = $DB;
        $connection = $database->getDoctrineConnection();
        // DbTestCase owns the physical transaction. All replacement operations
        // below release or revert ONLY the dictionary's nested savepoint.
        $caller = OwnedMutationFrame::begin($connection);
        $replacement = null;
        $session = $_SESSION;
        try {
            $fixture = $this->dictionaryFixture($operation);
            $tracked = $fixture['source'];
            $before = $tracked->fields;
            $calls = 0;
            $visited = [];
            $witness = null;
            $primary = new \RuntimeException('Dictionary callback primary failure');
            $callback = function (callable $change) use ($connection, $tracked, $fixture, $scenario, $primary, &$replacement, &$calls, &$witness): bool {
                ++$calls;
                $this->boolean($tracked->update(['id' => $tracked->getID(), 'comment' => 'Dictionary callback mutation']))->isTrue();
                $this->boolean((bool)$change())->isTrue();
                $_SESSION['dictionary_callback_marker'] = $scenario;
                if (in_array($scenario, ['commit-reopen', 'commit-primary', 'rollback-reopen', 'rollback-primary'], true)) {
                    // Never commit/rollback the caller frame or physical transaction.
                    if (str_starts_with($scenario, 'commit')) {
                        $connection->commit();
                    } else {
                        $connection->rollBack();
                    }
                    $replacement = OwnedMutationFrame::begin($connection);
                    $witness = $this->createItem(\Printer::class, ['name' => 'Dictionary replacement witness', 'entities_id' => $fixture['entity']]);
                }
                if ($scenario === 'writer') {
                    $GLOBALS['DB'] = clone $GLOBALS['DB'];
                }
                if (in_array($scenario, ['primary', 'commit-primary', 'rollback-primary'], true)) {
                    throw $primary;
                }
                if ($scenario === 'veto') {
                    \Session::addMessageAfterRedirect('Dictionary callback veto', true, WARNING, false);
                }
                return $scenario !== 'veto';
            };
            $error = null;
            try {
                if ($operation === 'model') {
                    (new DropdownDictionaryRepository(Orm::create($database)))->replaceModel(
                        'glpi_printermodels',
                        'glpi_printers',
                        $tracked->getID(),
                        [0 => $fixture['target']->getID()],
                        function (int $cartridge, int $target) use ($callback, &$visited): bool {
                            $visited[] = $cartridge;
                            return $callback(static fn (): bool => (new \CartridgeItem())->addCompatibleType($cartridge, $target));
                        }
                    );
                } else {
                    $link = new \Computer_Item();
                    (new PrinterDictionaryRepository(Orm::create($database)))->moveConnections(
                        $tracked->getID(),
                        $fixture['target']->getID(),
                        static fn (int $id, int $target): bool => $callback(static fn (): bool => (bool)$link->update(['id' => $id, 'items_id' => $target])),
                        static fn (array $row): bool => $callback(static fn (): bool => (bool)$link->delete($row + ['_no_auto_action' => true], 1))
                    );
                }
            } catch (\Throwable $failure) {
                $error = $failure;
            } finally {
                $DB = $database;
            }
            $replaced = $replacement !== null;
            if ($replaced) {
                $this->object($error)->isInstanceOf(MutationRollbackFailure::class);
                $this->boolean($error->rollbackUnproven)->isTrue();
                if (str_ends_with($scenario, 'primary')) {
                    $this->object($error->primary)->isIdenticalTo($primary);
                } else {
                    $this->object($error->primary)->isInstanceOf(TransactionOwnershipMismatch::class);
                }
                $this->object($error->cleanup)->isInstanceOf(TransactionOwnershipMismatch::class);
                $replacement->assertActive();
                $this->boolean((new \Printer())->getFromDB($witness->getID()))->isTrue();
                $this->string($_SESSION['dictionary_callback_marker'])->isIdenticalTo($scenario);
                // Unproven rollback must not rewind a model already touched by a hook.
                $this->string($tracked->fields['comment'])->isIdenticalTo('Dictionary callback mutation');
            } else {
                $caller->assertActive();
                if ($scenario === 'success') {
                    $this->variable($error)->isNull();
                    $this->string($_SESSION['dictionary_callback_marker'])->isIdenticalTo('success');
                } else {
                    if ($scenario === 'primary') {
                        $this->object($error)->isIdenticalTo($primary);
                    } elseif ($scenario === 'writer') {
                        $this->object($error)->isInstanceOf(TransactionOwnershipMismatch::class);
                    } else {
                        $this->object($error)->isInstanceOf(\RuntimeException::class);
                        $this->string($error->getMessage())->isIdenticalTo($operation === 'model'
                            ? 'Unable to move printer model compatibility.' : 'Unable to move printer direct connection.');
                    }
                    $this->array($tracked->fields)->isIdenticalTo($before);
                    $this->array($_SESSION)->notHasKey('dictionary_callback_marker');
                    if ($scenario === 'veto') {
                        $this->array($_SESSION['MESSAGE_AFTER_REDIRECT'][WARNING])->contains('Dictionary callback veto');
                    }
                }
            }
            $this->integer($calls)->isIdenticalTo($scenario === 'success' ? 2 : 1);
            $read = new RecordRepository(Orm::create($database));
            $committed = $scenario === 'success' || str_starts_with($scenario, 'commit');
            if ($operation !== 'model' || $scenario !== 'success') {
                $table = $operation === 'model' ? 'glpi_printermodels' : 'glpi_printers';
                $stored = $read->find($table, 'id', $tracked->getID());
                $this->string($stored['comment'])->isIdenticalTo($committed ? 'Dictionary callback mutation' : 'Before callback');
            }
            if ($operation === 'model') {
                $source = $read->find('glpi_printermodels', 'id', $tracked->getID());
                if ($scenario === 'success') {
                    $this->variable($source)->isNull();
                } else {
                    $this->array($source);
                }
                $this->integer((int)$read->find('glpi_printers', 'id', $fixture['printer']->getID())['printermodels_id'])
                    ->isIdenticalTo((int)($committed ? $fixture['target']->getID() : $tracked->getID()));
                foreach ($fixture['cartridges'] as $cartridge) {
                    $rows = $read->matching('glpi_cartridgeitems_printermodels', ['cartridgeitems_id' => $cartridge->getID(), 'printermodels_id' => $fixture['target']->getID()]);
                    $this->integer(count($rows))->isIdenticalTo($committed && in_array((int)$cartridge->getID(), $visited, true) ? 1 : 0);
                }
            } else {
                foreach ($fixture['links'] as $index => $link) {
                    $row = $read->find('glpi_computers_items', 'id', $link->getID());
                    $changed = $committed && ($index === 0 || $scenario === 'success');
                    if ($operation === 'remove' && $changed) {
                        $this->variable($row)->isNull();
                    } else {
                        $this->integer((int)$row['items_id'])->isIdenticalTo((int)($changed ? $fixture['target']->getID() : $tracked->getID()));
                    }
                }
            }
        } finally {
            $DB = $database;
            if ($replacement !== null) {
                $replacement->rollBack();
            }
            $caller->assertActive();
            $caller->rollBack();
            $_SESSION = $session;
        }
    }

    public function testPrinterDictionaryPublicConnectionMergeRetainsCallerFrame(): void
    {
        global $DB;
        $this->login();
        $caller = OwnedMutationFrame::begin($DB->getDoctrineConnection());
        try {
            foreach (['move', 'remove'] as $operation) {
                $fixture = $this->dictionaryFixture($operation);
                (new \RuleDictionnaryPrinterCollection())->moveDirectConnections($fixture['source']->getID(), $fixture['target']->getID());
                $caller->assertActive();
                $read = new RecordRepository(Orm::create($DB));
                foreach ($fixture['links'] as $link) {
                    $row = $read->find('glpi_computers_items', 'id', $link->getID());
                    if ($operation === 'remove') {
                        $this->variable($row)->isNull();
                    } else {
                        $this->integer((int)$row['items_id'])->isIdenticalTo((int)$fixture['target']->getID());
                    }
                }
                // A connection merge does not purge either printer.
                $this->array($read->find('glpi_printers', 'id', $fixture['source']->getID()));
                $this->array($read->find('glpi_printers', 'id', $fixture['target']->getID()));
            }
        } finally {
            $caller->rollBack();
        }
    }

    private function dictionaryFixture(string $operation): array
    {
        $name = $this->getUniqueString();
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $class = $operation === 'model' ? \PrinterModel::class : \Printer::class;
        $values = $operation === 'model' ? [] : ['entities_id' => $entity, 'is_global' => 1];
        $fixture = [
            'entity' => $entity,
            'source' => $this->createItem($class, ['name' => $name . ' source', 'comment' => 'Before callback'] + $values),
            'target' => $this->createItem($class, ['name' => $name . ' target'] + $values),
        ];
        $this->boolean($fixture['source']->can($fixture['source']->getID(), UPDATE))->isTrue();
        $this->boolean($fixture['target']->can($fixture['target']->getID(), UPDATE))->isTrue();
        if ($operation === 'model') {
            $fixture['printer'] = $this->createItem(\Printer::class, ['name' => $name, 'entities_id' => $entity, 'printermodels_id' => $fixture['source']->getID()]);
            $this->boolean($fixture['printer']->can($fixture['printer']->getID(), UPDATE))->isTrue();
            for ($i = 0; $i < 2; ++$i) {
                $cartridge = $this->createItem(\CartridgeItem::class, ['name' => $name . ' cartridge ' . $i, 'entities_id' => $entity]);
                $this->boolean($cartridge->can($cartridge->getID(), UPDATE))->isTrue();
                $this->boolean($cartridge->addCompatibleType($cartridge->getID(), $fixture['source']->getID()))->isTrue();
                $fixture['cartridges'][] = $cartridge;
            }
        } else {
            for ($i = 0; $i < 2; ++$i) {
                $computer = $this->createItem(\Computer::class, ['name' => $name . ' computer ' . $i, 'entities_id' => $entity]);
                $this->boolean($computer->can($computer->getID(), UPDATE))->isTrue();
                $values = ['computers_id' => $computer->getID(), 'itemtype' => 'Printer'];
                $link = $this->createItem(\Computer_Item::class, $values + ['items_id' => $fixture['source']->getID()]);
                $this->boolean($link->can($link->getID(), UPDATE))->isTrue();
                $fixture['links'][] = $link;
                if ($operation === 'remove') {
                    $this->createItem(\Computer_Item::class, $values + ['items_id' => $fixture['target']->getID()]);
                }
            }
        }
        return $fixture;
    }

    protected function nonSoftwareCollectionProvider()
    {
        return [
           ['RuleDictionnaryPrinterCollection', 'RuleDictionnaryPrinter', ['manufacturer' => 'Acme', 'comment' => 'Printer']],
           ['RuleDictionnaryOperatingSystemCollection', 'RuleDictionnaryOperatingSystem', []],
           ['RuleDictionnaryNetworkEquipmentModelCollection', 'RuleDictionnaryNetworkEquipmentModel', ['manufacturer' => 'Acme']],
        ];
    }

    /**
     * @dataProvider nonSoftwareCollectionProvider
     */
    public function testCollectionAppliesAssignRule($collection_class, $rule_type, array $extra_input)
    {
        $this->login();

        $rule = new \Rule();
        $criteria = new \RuleCriteria();
        $action = new \RuleAction();
        $collection_fqcn = '\\' . $collection_class;
        $collection = new $collection_fqcn();

        $name = 'dictionnary-' . $this->getUniqueString();
        $target_name = 'mapped-' . $name;
        $rules_id = (int)$rule->add([
           'name'        => 'Dictionnary rule ' . $name,
           'is_active'   => 1,
           'entities_id' => 0,
           'sub_type'    => $rule_type,
           'match'       => \Rule::AND_MATCHING,
           'condition'   => 0,
           'description' => '',
        ]);
        $this->integer($rules_id)->isGreaterThan(0);

        $criteria_id = (int)$criteria->add([
           'rules_id'  => $rules_id,
           'criteria'  => 'name',
           'condition' => \Rule::PATTERN_IS,
           'pattern'   => $name,
        ]);
        $this->integer($criteria_id)->isGreaterThan(0);

        $action_id = (int)$action->add([
           'rules_id'    => $rules_id,
           'action_type' => 'assign',
           'field'       => 'name',
           'value'       => $target_name,
        ]);
        $this->integer($action_id)->isGreaterThan(0);

        $collection->RuleList = new \stdClass();
        $collection->RuleList->load = true;

        $input = array_merge(['name' => $name], $extra_input);
        $result = $collection->processAllRules($input);
        // RuleCollection returns the mapped ID; input escaping preserves integers.
        $this->array($result)->isIdenticalTo([
           'name'    => $target_name,
           '_ruleid' => $rules_id,
        ]);
    }

    public function testModelCountPreservesDistinctIdentityPairs()
    {
        global $DB;

        $this->login();
        $em = \itsmng\Database\Orm::create($DB);
        $repository = new \itsmng\Database\Repository\DropdownDictionaryRepository($em);
        $baseline = $repository->modelCount('glpi_networkequipmentmodels', 'glpi_networkequipments');
        $name = 'dictionary-count-' . $this->getUniqueString();
        $models = [];
        $manufacturers = [];
        for ($i = 0; $i < 3; ++$i) {
            $model = $this->createItem(\NetworkEquipmentModel::class, [
                'name' => $name,
                'comment' => str_repeat('Shared model metadata ', 128),
            ]);
            $models[] = (int)$model->getID();
        }
        for ($i = 0; $i < 2; ++$i) {
            $manufacturer = $this->createItem(\Manufacturer::class, ['name' => $name]);
            $manufacturers[] = (int)$manufacturer->getID();
        }
        sort($models, SORT_NUMERIC);
        sort($manufacturers, SORT_NUMERIC);
        $this->integer($models[0])->isNotIdenticalTo($models[1]);
        $this->integer($manufacturers[0])->isNotIdenticalTo($manufacturers[1]);

        // Duplicate assets do not add a pair. Deleted/template owners still do;
        // owners without a model and a wholly unused model add nothing.
        foreach ([
            [$models[0], $manufacturers[0], 0, 0],
            [$models[0], $manufacturers[0], 0, 0],
            [$models[0], $manufacturers[1], 0, 0],
            [$models[0], null, 0, 0],
            [$models[0], null, 0, 0],
            [$models[1], $manufacturers[0], 0, 1],
            [$models[1], $manufacturers[1], 1, 0],
            [$models[1], null, 0, 0],
            [null, $manufacturers[0], 0, 0],
            [null, null, 0, 0],
        ] as [$model, $manufacturer, $deleted, $template]) {
            $this->createItem(\NetworkEquipment::class, [
                'name' => $name,
                'entities_id' => 0,
                'networkequipmentmodels_id' => $model,
                'manufacturers_id' => $manufacturer,
                'is_deleted' => $deleted,
                'is_template' => $template,
            ]);
        }

        $this->integer($repository->modelCount('glpi_networkequipmentmodels', 'glpi_networkequipments'))
            ->isIdenticalTo($baseline + 6);
        $pairs = [];
        $all_rows = 0;
        foreach ($repository->modelRows('glpi_networkequipmentmodels', 'glpi_networkequipments', 0) as $row) {
            ++$all_rows;
            if (in_array((int)$row['id'], $models, true)) {
                $pairs[] = [(int)$row['id'], $row['idmanu'] === null ? null : (int)$row['idmanu']];
            }
        }
        $this->integer($all_rows)->isIdenticalTo($baseline + 6);
        $this->array($pairs)->isIdenticalTo([
            [$models[0], null],
            [$models[0], $manufacturers[0]],
            [$models[0], $manufacturers[1]],
            [$models[1], null],
            [$models[1], $manufacturers[0]],
            [$models[1], $manufacturers[1]],
        ]);
        $em->close();
    }

    public function testModelCountRetainsSuppliedConnection()
    {
        global $DB;

        $this->login();
        $em = \itsmng\Database\Orm::create($DB);
        $connection = $em->getConnection();
        $repository = new \itsmng\Database\Repository\DropdownDictionaryRepository($em);
        $expected = $repository->modelCount('glpi_networkequipmentmodels', 'glpi_networkequipments');
        $depth = $connection->getTransactionNestingLevel();
        $original = $DB;
        try {
            $DB = null;
            $this->integer($repository->modelCount('glpi_networkequipmentmodels', 'glpi_networkequipments'))
                ->isIdenticalTo($expected);
            $this->object($em->getConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
        } finally {
            $DB = $original;
            $em->close();
        }
    }
}
