<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use DBAdapter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Repository\AssetRepository;
use itsmng\Database\Repository\RecordRepository;
use ReflectionMethod;

/** Fresh connection identities on one selected route; no retained rows or shared manager. */
final class ComputerItemReadOperation
{
    private ?EntityManager $manager = null;
    private bool $project = false;

    public static function forDatabase(DBAdapter $database): self
    {
        $connection = $database->getDoctrineConnection();
        OwnershipUpdateUnit::assertResolvedWriter($database, $connection);
        return new self($connection);
    }

    public function __construct(private readonly Connection $connection)
    {
        // Preserve extension callbacks before the caller evaluates getType/getID.
        if ((new ReflectionMethod($connection, 'getDatabasePlatform'))->getDeclaringClass()->getName() !== Connection::class
            || method_exists($connection, 'getEventManager')) {
            $this->manager = Orm::forConnection($connection);
            return;
        }
        $this->project = Orm::ownsReadMapping($connection);
        if ($this->project) {
            Orm::registerTypes();
        } else {
            $this->manager = Orm::forConnection($connection);
        }
    }

    public function linkedItems(string $itemtype, int $id): array
    {
        $mapping = $this->project ? EntityRegistry::computerItemMapping() : null;
        if ($mapping !== null) {
            return AssetRepository::projectedLinkedItems($this->connection, $mapping, $itemtype, $id);
        }
        $this->manager ??= Orm::forConnection($this->connection);
        return (new AssetRepository($this->manager))->linkedItems($itemtype, $id);
    }

    /** The caller has admitted the exact one-integer Computer_Item criteria shape. */
    public function distinctTypes(string $table, string $column, int $id): array
    {
        $mapping = $this->project ? EntityRegistry::computerItemMapping() : null;
        if ($mapping !== null && $mapping['table'][0] === $table && $mapping['computer'][0] === $column
            && $mapping['fields']['itemtype'][0] === 'itemtype'
            && !isset(EntityRegistry::references($table)[$column])) {
            return AssetRepository::projectedComputerItemTypes($this->connection, $mapping, $id);
        }
        $this->manager ??= Orm::forConnection($this->connection);
        return (new RecordRepository($this->manager))->distinctValues($table, 'itemtype', [$column => $id], 'itemtype');
    }

    /** Linked-item callers retain their existing finally; distinct-types adds no clear event. */
    public function close(): void
    {
        $this->manager?->clear();
    }
}
