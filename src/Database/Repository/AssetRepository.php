<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use LogicException;
use ReflectionProperty;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\Mapping\AssetClassification;

final class AssetRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function supports(string $itemtype): bool
    {
        $class = EntityRegistry::tables()[getTableForItemType($itemtype)] ?? null;
        return $class !== null && $this->classificationAssociation($class) !== null;
    }

    /** Connection identities, including locked/deleted links, as required by lifecycle callers. */
    public function linkedItems(string $itemtype, int $id): array
    {
        $query = $this->em->createQueryBuilder()->from(Entity\ComputerItem::class, 'link')
            ->setParameter('id', $id, Types::INTEGER)->orderBy('link.id');
        if ($itemtype === 'Computer') {
            $query->select('link.itemtype AS itemtype', 'link.items_id AS item_id')->where('link.computers = :id');
        } else {
            $query->select('IDENTITY(link.computers) AS item_id')->where('link.itemtype = :type AND link.items_id = :id')
                ->setParameter('type', $itemtype, Types::STRING);
        }
        $items = [];
        foreach ($query->getQuery()->getScalarResult() as $row) {
            $target = $itemtype === 'Computer' ? $row['itemtype'] : 'Computer';
            $targetId = (int)$row['item_id'];
            $items[$target][$targetId] = $targetId;
        }
        return $items;
    }

    /** Link and serial fields for already selected connections; callers retain item rights. */
    public function computerDisplayData(array $computers): array
    {
        if (!$computers) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()
            ->select('c.id, c.name, c.serial, c.otherserial, c.is_template, c.is_recursive')
            ->addSelect('IDENTITY(c.entities) AS entities_id')
            ->from(Entity\Computer::class, 'c')->where('c.id IN (:computers)')
            ->setParameter('computers', array_values(array_unique(array_map('intval', $computers))))
            ->getQuery()->getArrayResult();
        return array_column($rows, null, 'id');
    }

    /** null = all authorized entities; an empty list deliberately matches none. */
    public function count(string $itemtype, ?array $entities): int
    {
        $class = $this->entityClass($itemtype);
        $query = $this->em->createQueryBuilder()->select('COUNT(a.id)')->from($class, 'a');
        $this->visible($query, $class, $entities);
        return (int)$query->getQuery()->getSingleScalarResult();
    }

    /** Group by the displayed name, retaining the unclassified NULL group. */
    public function countsByType(string $itemtype, ?array $entities): array
    {
        $class = $this->entityClass($itemtype);
        $association = $this->classificationAssociation($class);
        if ($association === null) {
            throw new InvalidArgumentException('Asset classification requires a mapped association');
        }
        $query = $this->em->createQueryBuilder()->select('COUNT(a.id) AS count', 't.name AS name')
            ->from($class, 'a')->leftJoin('a.' . $association, 't')->groupBy('t.name')->orderBy('t.name');
        $this->visible($query, $class, $entities);
        return $query->getQuery()->getScalarResult();
    }

    /** Count OS installations on visible computers, independently of child entity caches. */
    public function operatingSystems(?array $entities): array
    {
        $query = $this->em->createQueryBuilder()->select('COUNT(os.id) AS count', 't.name AS name')
            ->from(Entity\ItemOperatingSystem::class, 'os')
            ->innerJoin('os.computer', 'a')
            ->leftJoin('os.operatingsystems', 't')
            ->where('os.is_deleted = :false')->groupBy('t.name')->orderBy('t.name');
        $this->visible($query, Entity\Computer::class, $entities);
        return $query->getQuery()->getScalarResult();
    }

    private function visible(QueryBuilder $query, string $class, ?array $entities): void
    {
        foreach (['is_deleted', 'is_template'] as $flag) {
            if ($this->em->getClassMetadata($class)->hasField($flag)) {
                $query->andWhere('a.' . $flag . ' = :false')->setParameter('false', false, Types::BOOLEAN);
            }
        }
        if ($entities !== null) {
            $query->andWhere('IDENTITY(a.entities) IN (:entities)')->setParameter('entities', $entities ?: [-1]);
        }
    }

    private function entityClass(string $itemtype): string
    {
        return EntityRegistry::tables()[getTableForItemType($itemtype)] ?? throw new InvalidArgumentException('Unmapped asset type');
    }

    private function classificationAssociation(string $class): ?string
    {
        $selected = null;
        foreach ($this->em->getClassMetadata($class)->associationMappings as $name => $mapping) {
            if (!(new ReflectionProperty($class, $name))->getAttributes(AssetClassification::class)) {
                continue;
            }
            if (!$mapping->isToOneOwningSide() || $selected !== null) {
                throw new LogicException('Asset reporting requires one owning classification association');
            }
            $selected = $name;
        }
        return $selected;
    }
}
