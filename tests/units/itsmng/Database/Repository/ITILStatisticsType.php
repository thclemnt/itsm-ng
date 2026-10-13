<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use LogicException;
use Throwable;
use WeakReference;
use atoum\atoum\test;
use itsmng\Database\Entity;
use itsmng\Database\Mapping\AttributeDriver;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ITILStatisticsType as StatisticsType;

/** No application bootstrap, database, session, or GLPITestCase data hooks. */
class ITILStatisticsType extends test
{
    private function manager(?AttributeDriver $driver = null): EntityManager
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '16.0',
            'wrapperClass' => StatisticsMetadataConnection::class,
        ]);
        $configuration = Orm::configuration($connection->getDatabasePlatform());
        $configuration->setMetadataDriverImpl($driver ?? $this->canonicalDriver());
        return new EntityManager($connection, $configuration);
    }

    private function canonicalDriver(): AttributeDriver
    {
        return Orm::configuration(new PostgreSQLPlatform())->getMetadataDriverImpl();
    }

    public function testCanonicalDiscoveryLeavesUnrelatedMetadataUnloaded(): void
    {
        $manager = $this->manager($this->canonicalDriver());
        $this->array(StatisticsType::definition($manager, 'Ticket'))->isIdenticalTo([
            Entity\Ticket::class, 'tickets', Entity\TicketUser::class, Entity\GroupTicket::class,
            Entity\SupplierTicket::class, Entity\TicketTask::class, Entity\ItemTicket::class,
        ]);
        $this->array(StatisticsType::definition($manager, 'Problem'))->hasSize(7);
        $this->array(StatisticsType::definition($manager, 'Change'))->hasSize(7);
        $loaded = $manager->getMetadataFactory()->getLoadedMetadata();
        $this->boolean(isset($loaded[Entity\TicketTask::class]))->isTrue();
        $this->boolean(isset($loaded[Entity\Software::class]))->isFalse();
        $this->boolean($manager->getConnection()->isConnected())->isFalse();
    }

    public function testSuppliedMetadataEditsRemainLiveAfterDiscovery(): void
    {
        $manager = $this->manager($this->canonicalDriver());
        $this->array(StatisticsType::definition($manager, 'Ticket'))->hasSize(7);
        unset($manager->getClassMetadata(Entity\TicketTask::class)->associationMappings['tickets']);
        $this->exception(static fn () => StatisticsType::definition($manager, 'Ticket'))
            ->isInstanceOf(LogicException::class)->hasMessage('Missing ITIL statistics association: Ticket.Tasks');
        $this->array(StatisticsType::definition($manager, 'Problem'))->hasSize(7);
        $this->boolean($manager->getConnection()->isConnected())->isFalse();
    }

    public function testCanonicalInheritanceReportsRoleAmbiguity(): void
    {
        foreach (['public', 'private'] as $visibility) {
            $directory = sys_get_temp_dir() . '/itsm-statistics-' . bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            $file = $directory . '/Declarations.php';
            $suffix = ucfirst($visibility);
            // Real declarations are exposed through the canonical driver's own
            // class locator. Both superclass and subclass must remain visible.
            $declarations = '<?php namespace tests\\fixtures\\StatisticsDiscovery;'
                . '#[\\Doctrine\\ORM\\Mapping\\MappedSuperclass] class Parent' . $suffix . ' {'
                . '#[\\Doctrine\\ORM\\Mapping\\Id] #[\\Doctrine\\ORM\\Mapping\\Column(type: "integer")] public int $id;'
                . '#[\\Doctrine\\ORM\\Mapping\\ManyToOne(targetEntity: \\itsmng\\Database\\Entity\\Ticket::class)]'
                . '#[\\Doctrine\\ORM\\Mapping\\JoinColumn(name: "tickets_id")]'
                . '#[\\itsmng\\Database\\Mapping\\ITILStatisticsRelation(\\itsmng\\Database\\Mapping\\ITILStatisticsRole::Tasks)]'
                . $visibility . ' ?\\itsmng\\Database\\Entity\\Ticket $tickets = null; }'
                . '#[\\Doctrine\\ORM\\Mapping\\Entity] class Child' . $suffix . ' extends Parent' . $suffix . ' {}';
            file_put_contents($file, $declarations);
            try {
                $canonical = new AttributeDriver(
                    [...$this->canonicalDriver()->getPaths(), $directory],
                    new PostgreSQLPlatform(),
                );
                $classes = $canonical->getAllClassNames();
                $this->boolean(in_array('tests\\fixtures\\StatisticsDiscovery\\Parent' . $suffix, $classes, true))->isTrue();
                $this->boolean(in_array('tests\\fixtures\\StatisticsDiscovery\\Child' . $suffix, $classes, true))->isTrue();
                $outcome = static function (EntityManager $manager): array {
                    try {
                        return ['definition' => StatisticsType::definition($manager, 'Ticket')];
                    } catch (Throwable $error) {
                        return ['error' => $error::class, 'message' => $error->getMessage()];
                    }
                };
                $expected = $outcome($this->manager($canonical));
                $this->boolean(isset($expected['error']))->isTrue();
                if ($visibility === 'public') {
                    $this->string($expected['message'])->isIdenticalTo('Ambiguous ITIL statistics association: Ticket.Tasks');
                }
                // A class selected through wider paths must not enter a narrower driver's discovery.
                $narrow = $this->canonicalDriver();
                $narrowClasses = $narrow->getAllClassNames();
                $this->boolean(in_array('tests\\fixtures\\StatisticsDiscovery\\Parent' . $suffix, $narrowClasses, true))->isFalse();
                $this->boolean(in_array('tests\\fixtures\\StatisticsDiscovery\\Child' . $suffix, $narrowClasses, true))->isFalse();
                $narrowExpected = $outcome($this->manager($narrow));
                $this->array($narrowExpected['definition'])->hasSize(7);
            } finally {
                unlink($file);
                rmdir($directory);
            }
        }
    }

    public function testEachSuppliedManagerOwnsItsDiscovery(): void
    {
        $first = $this->manager();
        $driver = $first->getConfiguration()->getMetadataDriverImpl();
        $expected = [
            'Ticket' => [Entity\Ticket::class, 'tickets', Entity\TicketUser::class, Entity\GroupTicket::class, Entity\SupplierTicket::class, Entity\TicketTask::class, Entity\ItemTicket::class],
            'Problem' => [Entity\Problem::class, 'problems', Entity\ProblemUser::class, Entity\GroupProblem::class, Entity\ProblemSupplier::class, Entity\ProblemTask::class, Entity\ItemProblem::class],
            'Change' => [Entity\Change::class, 'changes', Entity\ChangeUser::class, Entity\ChangeGroup::class, Entity\ChangeSupplier::class, Entity\ChangeTask::class, Entity\ChangeItem::class],
        ];
        foreach ($expected as $type => $definition) {
            $this->array(StatisticsType::definition($first, $type))->isIdenticalTo($definition);
        }
        $this->boolean(isset($first->getMetadataFactory()->getLoadedMetadata()[Entity\Software::class]))->isFalse();
        $second = $this->manager($driver);
        foreach ($expected as $type => $definition) {
            $this->array(StatisticsType::definition($second, $type))->isIdenticalTo($definition);
        }
        $this->object($second->getConnection())->isNotIdenticalTo($first->getConnection());
        $changed = StatisticsType::definition($second, 'Ticket');
        $changed[2] = 'Changed by a caller';
        $this->array(StatisticsType::definition($first, 'Ticket'))->isIdenticalTo($expected['Ticket']);
        $this->boolean($first->getConnection()->isConnected())->isFalse();
        $this->boolean($second->getConnection()->isConnected())->isFalse();
        $this->exception(static fn () => StatisticsType::definition($first, 'Computer'))
            ->isInstanceOf(InvalidArgumentException::class);
    }

    public function testCachedStringsDoNotRetainManagersOrDrivers(): void
    {
        $manager = $this->manager();
        $driver = $manager->getConfiguration()->getMetadataDriverImpl();
        StatisticsType::definition($manager, 'Ticket');
        $managerReference = WeakReference::create($manager);
        $driverReference = WeakReference::create($driver);
        unset($manager, $driver);
        gc_collect_cycles();
        $this->variable($managerReference->get())->isNull();
        $this->variable($driverReference->get())->isNull();
    }
}

final class StatisticsMetadataConnection extends Connection
{
    protected function connect(): DriverConnection
    {
        throw new LogicException('Statistics metadata unit tests cannot open a database connection.');
    }
}
