<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use itsmng\Database\EntityRegistry;

/** Current owning definition and binding records for one component command. */
final class ComponentDefinitionRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function ownsDefinition(string $bindingTable, string $column, string $definitionTable): bool
    {
        $tables = EntityRegistry::tables();
        if (!isset($tables[$bindingTable], $tables[$definitionTable])) {
            return false;
        }
        foreach ($this->em->getClassMetadata($tables[$bindingTable])->associationMappings as $mapping) {
            if ($mapping->isToOneOwningSide() && count($mapping->joinColumns) === 1
                && $mapping->joinColumns[0]->name === $column && $mapping->targetEntity === $tables[$definitionTable]) {
                return true;
            }
        }
        return false;
    }

    public function current(string $table, int $id): ?array
    {
        $class = EntityRegistry::tables()[$table] ?? null;
        if ($class === null) {
            throw new \InvalidArgumentException('Component ownership requires a mapped record.');
        }
        $query = $this->em->createQueryBuilder()->select('component')->from($class, 'component')
            ->where('component.id = :id')->setParameter('id', $id, 'bigint')->getQuery();
        $record = $query->setHint(Query::HINT_REFRESH, true)->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
        if ($record === null) {
            return null;
        }
        $row = (new RecordRepository($this->em))->toRow($record);
        $this->em->detach($record);
        return $row;
    }
}
