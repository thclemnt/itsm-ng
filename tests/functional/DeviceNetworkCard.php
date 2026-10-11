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

namespace tests\units;

use DbTestCase;
use DeviceNetworkCard as DeviceNetworkCardModel;
use DevicePci as DevicePciModel;
use DeviceControl as DeviceControlModel;
use DeviceGraphicCard as DeviceGraphicCardModel;
use DeviceSoundCard as DeviceSoundCardModel;
use Manufacturer as ManufacturerModel;
use RegisteredID as RegisteredIDModel;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use itsmng\Database\Entity\RegisteredID as RegisteredIDEntity;
use itsmng\Database\Orm;
use mock\DBmysql as RegisteredOptionAdapterProbe;
use Stringable;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

class DeviceNetworkCard extends DbTestCase
{
    /** @dataProvider registeredIdentifierFormParents */
    public function testRegisteredIdentifierFormOptionsKeepValuesAndLiveOwners(string $parentType, string $nameField): void
    {
        global $DB;
        $session = $_SESSION;
        $writer = null;
        try {
            $this->login();
            $parent = $this->createItem($parentType, [$nameField => 'registered-options-' . $this->getUniqueString()]);
            $other = $this->createItem($parentType, [$nameField => 'other-options-' . $this->getUniqueString()]);
            $first = $this->createItem(RegisteredIDModel::class, ['itemtype' => $parentType, 'items_id' => $parent->getID(), 'device_type' => 'PCI', 'name' => '1234:abcd']);
            $second = $this->createItem(RegisteredIDModel::class, ['itemtype' => $parentType, 'items_id' => $parent->getID(), 'device_type' => 'USB', 'name' => '2345:bcde']);
            $unrelated = $this->createItem(RegisteredIDModel::class, ['itemtype' => $parentType, 'items_id' => $other->getID(), 'device_type' => 'PCI', 'name' => '3456:cdef']);
            $formValues = static function () use ($parent): array {
                $fields = array_values(array_filter($parent->getAdditionalFields(), static fn (array $field): bool => ($field['type'] ?? null) === 'multiSelect'));
                return $fields[0]['values'];
            };
            $values = $formValues();
            $this->array($values)->hasSize(2);
            $byId = array_column($values, null, 'id');
            $this->array(array_keys($byId[$first->getID()]))->isIdenticalTo(['id', '_registeredID_type', '_registeredID']);
            $this->string($byId[$first->getID()]['_registeredID_type'])->isIdenticalTo('PCI');
            $this->string($byId[$first->getID()]['_registeredID'])->isIdenticalTo('1234:abcd');
            $this->array(array_keys($byId))->notContains((int)$unrelated->getID());
            $connection = $DB->getDoctrineConnection();
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                foreach ($values as $value) {
                    $this->integer($value['id']);
                }
            }
            $writer = Orm::create($DB);
            $retained = $writer->find(RegisteredIDEntity::class, (int)$first->getID());
            $retained->name = 'Independent pending identifier';
            $this->boolean($parent->update(['id' => $parent->getID(), '_registeredID' => [$first->getID() => '4567:def0'], '_registeredID_type' => [$first->getID() => 'USB']]))->isTrue();
            $connection->update('glpi_registeredids', ['name' => null], ['id' => $second->getID()]);
            $fresh = array_column($formValues(), null, 'id');
            $this->string($fresh[$first->getID()]['_registeredID'])->isIdenticalTo('4567:def0');
            $this->string($fresh[$first->getID()]['_registeredID_type'])->isIdenticalTo('USB');
            $this->variable($fresh[$second->getID()]['_registeredID'])->isNull();
            $this->string($byId[$first->getID()]['_registeredID'])->isIdenticalTo('1234:abcd');
            $this->boolean($writer->contains($retained))->isTrue();
            $this->string($retained->name)->isIdenticalTo('Independent pending identifier');
            Orm::read(
                $DB,
                function (EntityManager $outer) use ($first, $formValues): void {
                    $owned = $outer->find(RegisteredIDEntity::class, (int)$first->getID());
                    $owned->name = 'Outer pending identifier';
                    $fresh = array_column($formValues(), null, 'id');
                    $this->string($fresh[$first->getID()]['_registeredID'])->isIdenticalTo('4567:def0');
                    $this->boolean($outer->contains($owned))->isTrue();
                    $this->string($owned->name)->isIdenticalTo('Outer pending identifier');
                }
            );
            $this->boolean($first->delete(['id' => $first->getID()], true))->isTrue();
            $this->array($formValues())->hasSize(1);
        } finally {
            $writer?->clear();
            $_SESSION = $session;
        }
    }

    protected function registeredIdentifierFormParents(): array
    {
        return [
            [DeviceNetworkCardModel::class, 'designation'], [DevicePciModel::class, 'designation'],
            [DeviceControlModel::class, 'designation'], [DeviceGraphicCardModel::class, 'designation'],
            [DeviceSoundCardModel::class, 'designation'], [ManufacturerModel::class, 'name'],
        ];
    }

    public function testRegisteredIdentifierOptionsSelectRouteBeforeIdentityCallbacks(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        $writer = null;
        try {
            $this->login();
            $parent = $this->createItem(DeviceNetworkCardModel::class, ['designation' => 'route-options-' . $this->getUniqueString()]);
            $identifier = $this->createItem(RegisteredIDModel::class, ['itemtype' => DeviceNetworkCardModel::class, 'items_id' => $parent->getID(), 'device_type' => 'PCI', 'name' => '5678:ef01']);
            $listener = new class () {
                public int $loads = 0;
                public int $clears = 0;
                public function postLoad(PostLoadEventArgs $event): void
                {
                    if ($event->getObject() instanceof RegisteredIDEntity) {
                        ++$this->loads;
                    }
                }
                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $events = new EventManager();
            $events->addEventListener(['postLoad', 'onClear'], $listener);
            $probe = new class ($original->getDoctrineConnection()) extends ScalarReadProbe {
                public EventManager $events;
                public function getEventManager(): EventManager
                {
                    return $this->events;
                }
            };
            $probe->events = $events;
            $writer = Orm::forConnection($probe);
            $live = $writer->find(RegisteredIDEntity::class, (int)$identifier->getID());
            $live->name = 'Custom pending identifier';
            $listener->loads = 0;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new RegisteredOptionAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = $probe;
            $this->calling($adapter)->getProvider = $original->getProvider();
            $identity = new class ((int)$parent->getID(), $original) implements Stringable {
                public int $calls = 0;
                public function __construct(private int $id, private object $original)
                {
                }
                public function __toString(): string
                {
                    ++$this->calls;
                    $GLOBALS['DB'] = $this->original;
                    return (string)$this->id;
                }
            };
            $parent->fields['id'] = $identity;
            $DB = $adapter;
            $fields = array_values(array_filter($parent->getAdditionalFields(), static fn (array $field): bool => ($field['type'] ?? null) === 'multiSelect'));
            $this->string($fields[0]['values'][0]['_registeredID'])->isIdenticalTo('5678:ef01');
            $this->integer($identity->calls)->isIdenticalTo(1);
            $this->object($DB)->isIdenticalTo($original);
            $this->array(array_values(array_filter($probe->queries, static fn (array $query): bool => isset($query['params']['parent']) && ($query['params']['kind'] ?? null) === DeviceNetworkCardModel::class)))->hasSize(1);
            foreach (['null', 'NULL', 'Null', 'nUlL'] as $sentinel) {
                $parent->fields['id'] = $sentinel;
                $DB = $adapter;
                $fields = array_values(array_filter($parent->getAdditionalFields(), static fn (array $field): bool => ($field['type'] ?? null) === 'multiSelect'));
                $this->array($fields[0]['values'])->isEmpty();
                $query = end($probe->queries);
                $this->string($query['sql'])->contains(' IS NULL');
                $this->array($query['params'])->notHasKey('parent');
            }
            $parent->fields['id'] = null;
            $DB = $adapter;
            $fields = array_values(array_filter($parent->getAdditionalFields(), static fn (array $field): bool => ($field['type'] ?? null) === 'multiSelect'));
            $this->array($fields[0]['values'])->isEmpty();
            $query = end($probe->queries);
            $this->integer($query['params']['parent'])->isIdenticalTo(-1);
            $this->array(RegisteredIDModel::getFormOptions(DeviceNetworkCardModel::class, null))->isEmpty();
            $query = end($probe->queries);
            $this->string($query['sql'])->contains(' IS NULL');
            $this->array($query['params'])->notHasKey('parent');
            $this->integer($listener->loads)->isIdenticalTo(0);
            $this->integer($listener->clears)->isIdenticalTo(0);
            $this->boolean($writer->contains($live))->isTrue();
            $this->string($live->name)->isIdenticalTo('Custom pending identifier');
        } finally {
            $DB = $original;
            $writer?->clear();
            $_SESSION = $session;
        }
    }

    public function testImportUsesDesignationAndBandwidth()
    {
        $this->login();

        $obj = new \DeviceNetworkCard();
        $designation = 'nic-' . $this->getUniqueString();

        $id_1 = $obj->import([
            'designation' => $designation,
            'bandwidth'   => '1000',
        ]);
        $this->integer((int)$id_1)->isGreaterThan(0);

        $id_2 = $obj->import([
            'designation' => $designation,
            'bandwidth'   => '1000',
        ]);
        $this->integer((int)$id_2)->isEqualTo((int)$id_1);

        $id_3 = $obj->import([
            'designation' => $designation,
            'bandwidth'   => '100',
        ]);
        $this->integer((int)$id_3)->isGreaterThan(0);
        $this->integer((int)$id_3)->isNotEqualTo((int)$id_1);
    }
}
