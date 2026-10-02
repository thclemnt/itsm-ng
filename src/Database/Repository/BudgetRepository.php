<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;

/** Budget spending projections; callers retain budget and item-type rights checks. */
final class BudgetRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public static function supports(string $itemtype): bool
    {
        return isset(EntityRegistry::tables()[\getTableForItemType($itemtype)]);
    }

    /** Type discovery deliberately retains the existing infocom-entity scope for totals. */
    public function itemTypes(int $budget, ?array $entities = null): array
    {
        $query = $this->em->createQueryBuilder()->select('DISTINCT i.itemtype AS itemtype')
            ->from(Entity\Infocom::class, 'i')->where('i.budgets = :budget')
            ->setParameter('budget', $budget, Types::INTEGER)
            ->andWhere('i.itemtype NOT IN (:excluded)')->setParameter('excluded', \Infocom::getExcludedTypes())
            ->orderBy('i.itemtype');
        $this->scope($query, 'i', $entities);
        return array_column($query->getQuery()->getScalarResult(), 'itemtype');
    }

    public function items(string $itemtype, int $budget, ?array $entities): array
    {
        if (CostRepository::supports($itemtype . 'Cost')) {
            $query = $this->costs($itemtype, $budget, $entities)
                ->select('a.id AS id', 'IDENTITY(a.entities) AS entities_id', $this->costValue($itemtype) . ' AS value')
                ->groupBy('a.id, entities_id, a.name')->orderBy('IDENTITY(a.entities)')->addOrderBy('a.name')->addOrderBy('a.id');
            if ($itemtype === 'Contract') {
                $query->andWhere('a.is_template = :false')->setParameter('false', false, Types::BOOLEAN);
            }
            return $query->getQuery()->getScalarResult();
        }
        $query = $this->infocoms($itemtype, $budget, $entities)->select('a', 'i.value AS value')->orderBy('IDENTITY(a.entities)');
        if (in_array($itemtype, ['Cartridge', 'Consumable'], true)) {
            $query->innerJoin('a.' . strtolower($itemtype) . 'items', 'model')->addSelect('model.name AS name')->addOrderBy('model.name');
        } else {
            $query->addOrderBy('a.' . (is_a($itemtype, \Item_Devices::class, true) ? 'itemtype' : 'name'));
        }
        $query->addOrderBy('a.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $record = $result[0];
            unset($result[0]);
            $rows[] = array_merge($records->toRow($record), $result);
            $this->em->detach($record);
        }
        return $rows;
    }

    public function totalsByEntity(string $itemtype, int $budget, ?array $entities): array
    {
        if (CostRepository::supports($itemtype . 'Cost')) {
            $query = $this->costs($itemtype, $budget, $entities);
            $value = $this->costValue($itemtype);
        } else {
            $query = $this->infocoms($itemtype, $budget, $entities);
            $value = 'SUM(i.value)';
        }
        return $query->select('IDENTITY(a.entities) AS entities_id', $value . ' AS sumvalue')
            ->groupBy('entities_id')->orderBy('IDENTITY(a.entities)')->getQuery()->getScalarResult();
    }

    private function costs(string $itemtype, int $budget, ?array $entities): QueryBuilder
    {
        [$class, $association] = CostRepository::definition($itemtype . 'Cost') ?? throw new \InvalidArgumentException('Unmapped budget cost type');
        $query = $this->em->createQueryBuilder()->from($class, 'c')->innerJoin('c.' . $association, 'a')
            ->where('c.budgets = :budget')->setParameter('budget', $budget, Types::INTEGER);
        $this->scope($query, 'a', $entities);
        return $query;
    }

    private function costValue(string $itemtype): string
    {
        return in_array($itemtype, ['Contract', 'Project'], true)
            ? 'SUM(c.cost)'
            : 'SUM(c.actiontime * c.cost_time / ' . \HOUR_TIMESTAMP . ' + c.cost_fixed + c.cost_material)';
    }

    private function infocoms(string $itemtype, int $budget, ?array $entities): QueryBuilder
    {
        $class = EntityRegistry::tables()[\getTableForItemType($itemtype)] ?? throw new \InvalidArgumentException('Unmapped budget item type');
        $query = $this->em->createQueryBuilder()->from(Entity\Infocom::class, 'i')
            ->innerJoin($class, 'a', 'WITH', 'a.id = i.items_id')
            ->where('i.itemtype = :type AND i.budgets = :budget')
            ->setParameter('type', $itemtype, Types::STRING)->setParameter('budget', $budget, Types::INTEGER);
        if ($this->em->getClassMetadata($class)->hasField('is_template')) {
            $query->andWhere('a.is_template = :false')->setParameter('false', false, Types::BOOLEAN);
        }
        $this->scope($query, 'a', $entities);
        return $query;
    }

    /** null = unrestricted; [] = no authorized entities. */
    private function scope(QueryBuilder $query, string $alias, ?array $entities): void
    {
        if ($entities !== null) {
            $query->andWhere('IDENTITY(' . $alias . '.entities) IN (:entities)')->setParameter('entities', $entities ?: [-1]);
        }
    }
}
