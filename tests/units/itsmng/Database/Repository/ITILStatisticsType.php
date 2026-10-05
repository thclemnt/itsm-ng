<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Repository;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping as Mapping;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use itsmng\Database\Entity;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ITILStatisticsType as StatisticsType;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** No application bootstrap, database, session, or GLPITestCase data hooks. */
class ITILStatisticsType extends \atoum\atoum\test
{
    private function manager(?StatisticsMappingDriver $driver = null, ?EventManager $events = null): EntityManager
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql', 'serverVersion' => '16.0',
            'wrapperClass' => StatisticsMetadataConnection::class,
        ]);
        $configuration = Orm::configuration($connection->getDatabasePlatform());
        $configuration->setMetadataDriverImpl($driver ?? new StatisticsMappingDriver($configuration->getMetadataDriverImpl()));
        // Each manager must load its own metadata, so a shared metadata cache
        // cannot hide a second discovery or a listener's changed declarations.
        $configuration->setMetadataCache(new ArrayAdapter(storeSerialized: true));
        return new EntityManager($connection, $configuration, $events);
    }

    public function testAllFamiliesShareOneDiscoveryAcrossOperations(): void
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
        $this->integer($driver->discoveries)->isIdenticalTo(1);
        $second = $this->manager($driver);
        foreach ($expected as $type => $definition) {
            $this->array(StatisticsType::definition($second, $type))->isIdenticalTo($definition);
        }
        $this->integer($driver->discoveries)->isIdenticalTo(1);
        $this->object($second->getConnection())->isNotIdenticalTo($first->getConnection());
        $changed = StatisticsType::definition($second, 'Ticket');
        $changed[2] = 'Changed by a caller';
        $this->array(StatisticsType::definition($first, 'Ticket'))->isIdenticalTo($expected['Ticket']);
        $this->boolean($first->getConnection()->isConnected())->isFalse();
        $this->boolean($second->getConnection()->isConnected())->isFalse();
    }

    public function testDifferentDriversKeepMissingAndAmbiguousRolesIsolated(): void
    {
        $complete = $this->manager();
        StatisticsType::definition($complete, 'Ticket');
        $delegate = $complete->getConfiguration()->getMetadataDriverImpl()->delegate;
        $missing = $this->manager(new StatisticsMappingDriver($delegate, Entity\TicketTask::class));
        $this->exception(static fn () => StatisticsType::definition($missing, 'Ticket'))
            ->isInstanceOf(\LogicException::class)->hasMessage('Missing ITIL statistics association: Ticket.Tasks');
        // An invalid Ticket mapping cannot newly reject an independent family.
        $this->array(StatisticsType::definition($missing, 'Problem'))->hasSize(7);
        $duplicate = $this->manager(new StatisticsMappingDriver($delegate, null, true));
        $this->exception(static fn () => StatisticsType::definition($duplicate, 'Ticket'))
            ->isInstanceOf(\LogicException::class)->hasMessage('Ambiguous ITIL statistics association: Ticket.Tasks');
        $this->array(StatisticsType::definition($complete, 'Ticket'))->hasSize(7);
        $this->exception(static fn () => StatisticsType::definition($complete, 'Computer'))
            ->isInstanceOf(\InvalidArgumentException::class);
    }

    public function testMetadataListenersDoNotReuseCanonicalDefinitions(): void
    {
        $canonical = $this->manager();
        StatisticsType::definition($canonical, 'Ticket');
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new class {
            public function loadClassMetadata(\Doctrine\ORM\Event\LoadClassMetadataEventArgs $event): void
            {
                $metadata = $event->getClassMetadata();
                if ($metadata->name === Entity\TicketTask::class) {
                    unset($metadata->associationMappings['tickets']);
                }
            }
        });
        $custom = $this->manager($canonical->getConfiguration()->getMetadataDriverImpl(), $events);
        $this->exception(static fn () => StatisticsType::definition($custom, 'Ticket'))
            ->isInstanceOf(\LogicException::class)->hasMessage('Missing ITIL statistics association: Ticket.Tasks');
        $this->array(StatisticsType::definition($canonical, 'Ticket'))->hasSize(7);
    }

    public function testCachedStringsDoNotRetainManagersOrDrivers(): void
    {
        $manager = $this->manager();
        $driver = $manager->getConfiguration()->getMetadataDriverImpl();
        StatisticsType::definition($manager, 'Ticket');
        $managerReference = \WeakReference::create($manager);
        $driverReference = \WeakReference::create($driver);
        unset($manager, $driver);
        gc_collect_cycles();
        $this->variable($managerReference->get())->isNull();
        $this->variable($driverReference->get())->isNull();
    }
}

/** Instrument the authoritative declaration driver, not cache implementation. */
final class StatisticsMappingDriver implements MappingDriver
{
    public int $discoveries = 0;

    public function __construct(public readonly MappingDriver $delegate, private ?string $excluded = null, private bool $duplicate = false)
    {
    }

    public function getAllClassNames(): array
    {
        ++$this->discoveries;
        $classes = array_values(array_filter($this->delegate->getAllClassNames(), fn (string $class): bool => $class !== $this->excluded));
        if ($this->duplicate) {
            $classes[] = DuplicateStatisticsTask::class;
        }
        return $classes;
    }

    public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
    {
        if ($className === DuplicateStatisticsTask::class) {
            (new \Doctrine\ORM\Mapping\Driver\AttributeDriver([]))->loadMetadataForClass($className, $metadata);
        } else {
            $this->delegate->loadMetadataForClass($className, $metadata);
        }
    }

    public function isTransient(string $className): bool
    {
        return $className !== DuplicateStatisticsTask::class && $this->delegate->isTransient($className);
    }
}

#[Mapping\Entity]
class DuplicateStatisticsTask
{
    #[Mapping\Id]
    #[Mapping\Column(type: 'integer')]
    public int $id;

    #[Mapping\ManyToOne(targetEntity: Entity\Ticket::class)]
    #[Mapping\JoinColumn(name: 'tickets_id', nullable: false)]
    #[ITILStatisticsRelation(ITILStatisticsRole::Tasks)]
    public ?Entity\Ticket $tickets = null;
}

final class StatisticsMetadataConnection extends Connection
{
    protected function connect(): \Doctrine\DBAL\Driver\Connection
    {
        throw new \LogicException('Statistics metadata unit tests cannot open a database connection.');
    }
}
