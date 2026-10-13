<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Search;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;
use itsmng\Database\UnsupportedCriteria;

/** Mapped collection pages after the caller has admitted the Search authorization policy. */
final class ApiCollectionRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /**
     * Decline only during compilation. A database/hydration failure never retries on
     * another connection. The caller retains opaque Search/plugin predicates.
     *
     * @return array{rows: array, total: int}|null
     */
    public function page(string $table, array $params, array $scope, array $criteria, ?array $parent = null): ?array
    {
        $class = EntityRegistry::tables()[$table] ?? null;
        if ($class === null) {
            return null;
        }
        $metadata = $this->em->getClassMetadata($class);
        $query = $this->em->createQueryBuilder()->from($class, 'r');
        $compiler = new RecordCriteria($query, $metadata, legacyValues: false);
        try {
            $query->where($compiler->where($criteria));
            if ($parent !== null) {
                $this->parent($query, $compiler, $parent);
            }
            $filters = $params['searchText'];
            if (is_array($filters)) {
                foreach ($filters as $field => $value) {
                    // Preserve the collection endpoint's historical empty-value rule.
                    if (empty($value)) {
                        continue;
                    }
                    if (!is_scalar($value)) {
                        return null;
                    }
                    $property = $metadata->fieldNames[$field] ?? null;
                    if ($property !== null && in_array($metadata->getTypeOfField($property), [
                        Types::BOOLEAN, Types::DATE_MUTABLE, Types::DATE_IMMUTABLE,
                        Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE,
                        Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE,
                        Types::TIME_MUTABLE, Types::TIME_IMMUTABLE,
                    ], true)) {
                        // Native boolean/temporal casts are a different text language
                        // from typed equality and application calendar formatting.
                        return null;
                    }
                    $pattern = Search::makeTextSearchValue(str_replace('\\', '\\\\', (string)$value));
                    $query->andWhere($compiler->where([$field => $pattern === null || $pattern === '' ? null : ['LIKE', $pattern]]));
                }
            }
            $compiler->order([$params['sort'] . ' ' . strtoupper($params['order'])]);
            $query->andWhere($compiler->where($scope));
        } catch (UnsupportedCriteria $error) {
            return null;
        }
        $count = clone $query;
        $count->resetDQLPart('orderBy');
        $query->select('r')->setFirstResult((int)$params['start'])->setMaxResults((int)$params['list_limit']);
        $rows = (new RecordRepository($this->em))->toRows($query->getQuery()->toIterable());
        $total = (int)$count->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        return ['rows' => $rows, 'total' => $total];
    }

    private function parent(QueryBuilder $query, RecordCriteria $compiler, array $parent): void
    {
        if ($parent['direction'] === 'child') {
            $query->andWhere($compiler->where([$parent['foreignKey'] => $parent['id']]));
            return;
        }
        if ($parent['direction'] === 'child-kind') {
            $query->andWhere($compiler->where(['itemtype' => $parent['kind'], 'items_id' => $parent['id']]));
            return;
        }
        $class = EntityRegistry::tables()[$parent['table']] ?? null;
        if ($class === null) {
            throw new UnsupportedCriteria('Reverse collection parent requires a mapped record.');
        }
        $parentMetadata = $this->em->getClassMetadata($class);
        $query->leftJoin($class, 'parent', 'WITH', 'parent.id = :parentId')
            ->setParameter('parentId', $parent['id'], Types::BIGINT);
        $compiler->withJoinedMetadata($parentMetadata, 'parent');
        if ($parent['direction'] === 'parent') {
            $query->andWhere($compiler->column($parent['table'] . '.' . $parent['foreignKey']) . ' = r.id');
        } else {
            $query->andWhere($compiler->column($parent['table'] . '.items_id') . ' = r.id')
                ->andWhere($compiler->column($parent['table'] . '.itemtype') . ' = :parentKind')
                ->setParameter('parentKind', $parent['kind'], Types::STRING);
        }
        // A single identified parent cannot multiply this collection's rows/count.
    }
}
