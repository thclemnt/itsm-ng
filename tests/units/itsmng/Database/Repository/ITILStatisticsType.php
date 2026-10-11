<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Repository;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping as Mapping;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use InvalidArgumentException;
use LogicException;
use Throwable;
use WeakReference;
use atoum\atoum\test;
use itsmng\Database\Entity;
use itsmng\Database\Mapping\AttributeDriver;
use itsmng\Database\Mapping\ITILStatisticsRelation;
use itsmng\Database\Mapping\ITILStatisticsRole;
use itsmng\Database\Orm;
use itsmng\Database\Repository\ITILStatisticsType as StatisticsType;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** No application bootstrap, database, session, or GLPITestCase data hooks. */
class ITILStatisticsType extends test
{
    private function manager(?MappingDriver $driver = null, ?EventManager $events = null): EntityManager
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
        $this->boolean($manager->getConnection()->isConnected())->isFalse();
    }

    public function testCanonicalMetadataListenersRetainFullDiscovery(): void
    {
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new class () {
            public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
            {
                $metadata = $event->getClassMetadata();
                if ($metadata->name === Entity\TicketTask::class) {
                    unset($metadata->associationMappings['tickets']);
                }
            }
        });
        $manager = $this->manager($this->canonicalDriver(), $events);
        $this->exception(static fn () => StatisticsType::definition($manager, 'Ticket'))
            ->isInstanceOf(LogicException::class)->hasMessage('Missing ITIL statistics association: Ticket.Tasks');
        $this->boolean(isset($manager->getMetadataFactory()->getLoadedMetadata()[Entity\Software::class]))->isTrue();
        $this->boolean($manager->getConnection()->isConnected())->isFalse();

        $warm = $this->manager($this->canonicalDriver());
        $this->array(StatisticsType::definition($warm, 'Ticket'))->hasSize(7);
        $this->boolean(isset($warm->getMetadataFactory()->getLoadedMetadata()[Entity\Software::class]))->isFalse();
        $listener = array_values($events->getListeners(Events::loadClassMetadata))[0];
        $warm->getEventManager()->addEventListener(Events::loadClassMetadata, $listener);
        // The late listener requires full discovery without reloading existing associations.
        $this->array(StatisticsType::definition($warm, 'Ticket'))->hasSize(7);
        $this->boolean(isset($warm->getMetadataFactory()->getLoadedMetadata()[Entity\Software::class]))->isTrue();
        $this->boolean($warm->getConnection()->isConnected())->isFalse();
    }

    public function testCanonicalMetadataNotFoundListenersRetainFullDiscovery(): void
    {
        $events = new EventManager();
        $events->addEventListener(Events::onClassMetadataNotFound, new class () {
            public function onClassMetadataNotFound(): void
            {
            }
        });
        $manager = $this->manager($this->canonicalDriver(), $events);
        $this->array(StatisticsType::definition($manager, 'Ticket'))->hasSize(7);
        $this->boolean(isset($manager->getMetadataFactory()->getLoadedMetadata()[Entity\Software::class]))->isTrue();
        $this->boolean($manager->getConnection()->isConnected())->isFalse();
    }

    public function testCanonicalInheritanceMatchesFullDiscoveryDiagnostics(): void
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
                $expected = $outcome($this->manager(new StatisticsMappingDriver($canonical)));
                $this->array($outcome($this->manager($canonical)))->isIdenticalTo($expected);
                $this->boolean(isset($expected['error']))->isTrue();
                if ($visibility === 'public') {
                    $this->string($expected['message'])->isIdenticalTo('Ambiguous ITIL statistics association: Ticket.Tasks');
                }
                // A class selected through wider paths must not enter a narrower driver's discovery.
                $narrow = $this->canonicalDriver();
                $narrowClasses = $narrow->getAllClassNames();
                $this->boolean(in_array('tests\\fixtures\\StatisticsDiscovery\\Parent' . $suffix, $narrowClasses, true))->isFalse();
                $this->boolean(in_array('tests\\fixtures\\StatisticsDiscovery\\Child' . $suffix, $narrowClasses, true))->isFalse();
                $narrowExpected = $outcome($this->manager(new StatisticsMappingDriver($narrow)));
                $this->array($outcome($this->manager($narrow)))->isIdenticalTo($narrowExpected);
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
        $this->integer($driver->discoveries)->isIdenticalTo(3);
        $this->boolean(isset($first->getMetadataFactory()->getLoadedMetadata()[Entity\Software::class]))->isTrue();
        $second = $this->manager($driver);
        foreach ($expected as $type => $definition) {
            $this->array(StatisticsType::definition($second, $type))->isIdenticalTo($definition);
        }
        $this->integer($driver->discoveries)->isIdenticalTo(6);
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
            ->isInstanceOf(LogicException::class)->hasMessage('Missing ITIL statistics association: Ticket.Tasks');
        // An invalid Ticket mapping cannot newly reject an independent family.
        $this->array(StatisticsType::definition($missing, 'Problem'))->hasSize(7);
        $duplicate = $this->manager(new StatisticsMappingDriver($delegate, null, true));
        $this->exception(static fn () => StatisticsType::definition($duplicate, 'Ticket'))
            ->isInstanceOf(LogicException::class)->hasMessage('Ambiguous ITIL statistics association: Ticket.Tasks');
        $this->array(StatisticsType::definition($complete, 'Ticket'))->hasSize(7);
        $this->exception(static fn () => StatisticsType::definition($complete, 'Computer'))
            ->isInstanceOf(InvalidArgumentException::class);
    }

    public function testMetadataListenersDoNotReuseCanonicalDefinitions(): void
    {
        $canonical = $this->manager();
        StatisticsType::definition($canonical, 'Ticket');
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new class () {
            public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
            {
                $metadata = $event->getClassMetadata();
                if ($metadata->name === Entity\TicketTask::class) {
                    unset($metadata->associationMappings['tickets']);
                }
            }
        });
        $custom = $this->manager($canonical->getConfiguration()->getMetadataDriverImpl(), $events);
        $this->exception(static fn () => StatisticsType::definition($custom, 'Ticket'))
            ->isInstanceOf(LogicException::class)->hasMessage('Missing ITIL statistics association: Ticket.Tasks');
        $this->array(StatisticsType::definition($canonical, 'Ticket'))->hasSize(7);
    }

    public function testCachedStringsDoNotRetainManagersOrDrivers(): void
    {
        foreach ([false, true] as $canonical) {
            $manager = $this->manager($canonical ? $this->canonicalDriver() : null);
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
            (new Mapping\Driver\AttributeDriver([]))->loadMetadataForClass($className, $metadata);
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
    protected function connect(): DriverConnection
    {
        throw new LogicException('Statistics metadata unit tests cannot open a database connection.');
    }
}
