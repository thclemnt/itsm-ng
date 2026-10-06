<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\ORM\EntityManager;
use itsmng\Database\Mapping\CostParent;

/** Cost history and totals for the five core parent/child associations. */
final class CostRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public static function supports(string $type): bool
    {
        return self::definition($type) !== null;
    }

    public static function definition(string $type): ?array
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $type) || !class_exists($class = 'itsmng\\Database\\Entity\\' . $type)) {
            return null;
        }
        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            if ($property->getAttributes(CostParent::class)) {
                return [$class, $property->name];
            }
        }
        return null;
    }

    public function rows(string $type, int|array $parents, bool $last = false): array
    {
        [$class, $association] = self::definition($type) ?? throw new \InvalidArgumentException('Unmapped cost type');
        $query = $this->em->createQueryBuilder()->select('c')->from($class, 'c')
            ->where('c.' . $association . ' IN (:parents)')->setParameter('parents', (array)$parents ?: [-1]);
        $date = $last ? 'end_date' : 'begin_date';
        // MySQL's date ordering: NULL first ascending, last descending.
        $query->addSelect('CASE WHEN c.' . $date . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN date_present')
            ->orderBy('date_present', $last ? 'DESC' : 'ASC')->addOrderBy('c.' . $date, $last ? 'DESC' : 'ASC')
            ->addOrderBy('c.id', $last ? 'DESC' : 'ASC');
        if ($last) {
            $query->setMaxResults(1);
        }
        $rows = [];
        $records = new RecordRepository($this->em);
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
            $this->em->detach($record);
        }
        return $rows;
    }

    /**
     * An action-time total belongs to cost identities, not to the fanout of a
     * search's display joins. Build this correlated aggregate on the supplied
     * connection; the caller retains ownership of its parent visibility rule.
     *
     * @param callable(string): string $parentScope Predicate for the parent alias
     */
    public function searchActionTime(string $type, string $subjectType, string $subjectTable, callable $parentScope): ?string
    {
        $definition = self::definition($type);
        if ($definition === null) {
            return null;
        }
        [$class, $parentProperty] = $definition;
        $cost = $this->em->getClassMetadata($class);
        if (!$cost->hasField('actiontime')) {
            return null;
        }
        $parent = $this->em->getClassMetadata($cost->getAssociationTargetClass($parentProperty));
        $parentType = \getItemTypeForTable($parent->getTableName());
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $column = fn ($metadata, string $property, string $alias) => $platform->quoteIdentifier($alias)
            . '.' . $quote->getColumnName($property, $metadata, $platform);
        $joinColumn = fn ($metadata, string $property, string $alias) => $platform->quoteIdentifier($alias)
            . '.' . $quote->getJoinColumnName($metadata->getAssociationMapping($property)->joinColumns[0], $metadata, $platform);
        $outer = $platform->quoteIdentifier($subjectTable) . '.' . $platform->quoteIdentifier('id');
        $query = $connection->createQueryBuilder()
            ->select('SUM(' . $column($cost, 'actiontime', 'cost_duration') . ')')
            ->from($quote->getTableName($cost, $platform), $platform->quoteIdentifier('cost_duration'));
        if ($parentType === $subjectType) {
            return $query->where($joinColumn($cost, $parentProperty, 'cost_duration') . ' = ' . $outer)->getSQL();
        }
        [, $linkParent, , , , , $linkClass] = ITILStatisticsType::definition($this->em, $parentType);
        try {
            $assetProperty = $linkClass::referenceAssociation($subjectType);
        } catch (\InvalidArgumentException) {
            // Other meta relationships (including plugin associations) are not
            // represented by the typed ITIL asset ownership being aggregated.
            return null;
        }
        $link = $this->em->getClassMetadata($linkClass);
        $parentId = $column($parent, $parent->getSingleIdentifierFieldName(), 'cost_parent');
        $exists = $connection->createQueryBuilder()->select('1')
            ->from($quote->getTableName($link, $platform), $platform->quoteIdentifier('cost_link'))
            ->where($joinColumn($link, $linkParent, 'cost_link') . ' = ' . $parentId)
            ->andWhere($joinColumn($link, $assetProperty, 'cost_link') . ' = ' . $outer);
        $query->innerJoin(
            $platform->quoteIdentifier('cost_duration'),
            $quote->getTableName($parent, $platform),
            $platform->quoteIdentifier('cost_parent'),
            $joinColumn($cost, $parentProperty, 'cost_duration') . ' = ' . $parentId
        )
            ->where('EXISTS (' . $exists->getSQL() . ')');
        $scope = $parentScope('cost_parent');
        if (trim($scope) !== '') {
            $query->andWhere($scope);
        }
        return $query->getSQL();
    }

    public function actionTime(string $type, int $parent): ?int
    {
        [$class, $association] = self::definition($type) ?? throw new \InvalidArgumentException('Unmapped cost type');
        if (!in_array($type, ['TicketCost', 'ProblemCost', 'ChangeCost'], true)) {
            throw new \InvalidArgumentException('Cost type has no action time');
        }
        $value = $this->em->createQueryBuilder()->select('SUM(c.actiontime)')->from($class, 'c')
            ->where('c.' . $association . ' = :parent')->setParameter('parent', $parent)
            ->getQuery()->getSingleScalarResult();
        return $value === null ? null : (int)$value;
    }
}
