<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\Rule;
use itsmng\Database\Entity\RuleAction;
use itsmng\Database\Entity\RuleCriteria;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;
use itsmng\Database\UnsupportedCriteria;

/** Rule selection and rank arithmetic; application updates retain lifecycle hooks. */
final class RuleRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function matching(array $criteria, array|string $order = [], int $limit = 0, int $offset = 0): array
    {
        $query = $this->em->createQueryBuilder()->select('r')->from(Rule::class, 'r')->join('r.entities', 'e');
        $compiler = (new RecordCriteria($query, $this->em->getClassMetadata(Rule::class)))
            ->withJoinedMetadata($this->em->getClassMetadata(Entity::class), 'e');
        $query->where($compiler->where($criteria));
        // Preserve NULL-first ascending / NULL-last descending names on both providers.
        foreach ((array)$order as $clause) {
            if (!is_string($clause)) {
                throw new UnsupportedCriteria('Rule ordering requires mapped columns.');
            }
            foreach (explode(',', $clause) as $part) {
                if (!preg_match('/^\s*([a-zA-Z0-9_.`]+)(?:\s+(ASC|DESC))?\s*$/iD', $part, $match)) {
                    throw new UnsupportedCriteria('Invalid rule ordering.');
                }
                $column = $compiler->column($match[1]);
                $direction = strtoupper($match[2] ?? 'ASC');
                $alias = 'absent' . count($query->getDQLPart('orderBy'));
                $query->addSelect('CASE WHEN ' . $column . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN ' . $alias)
                    ->addOrderBy($alias, $direction)->addOrderBy($column, $direction);
            }
        }
        $query->addOrderBy('r.id')->setFirstResult(max(0, $offset));
        if ($limit > 0) {
            $query->setMaxResults($limit);
        }
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $rule) {
            $rows[] = $records->toRow($rule);
            $this->em->detach($rule);
        }
        return $rows;
    }

    public function count(array $criteria): int
    {
        return (new RecordRepository($this->em))->countMatching('glpi_rules', $criteria);
    }

    public function maximumRank(string $type): int
    {
        return (int)$this->em->createQueryBuilder()->select('MAX(r.ranking)')->from(Rule::class, 'r')
            ->where('r.sub_type = :type')->setParameter('type', $type)->getQuery()->getSingleScalarResult();
    }

    public function closeRankGap(string $type, int $rank): void
    {
        $this->em->createQueryBuilder()->update(Rule::class, 'r')->set('r.ranking', 'r.ranking - 1')
            ->where('r.sub_type = :type AND r.ranking > :rank')->setParameter('type', $type)
            ->setParameter('rank', $rank, Types::INTEGER)->getQuery()->execute();
    }

    public function criteriaFields(string $type, int $condition = 0): array
    {
        $query = $this->em->createQueryBuilder()->select('DISTINCT c.criteria AS criterion')->from(RuleCriteria::class, 'c')
            ->join('c.rules', 'r')->where('r.sub_type = :type AND r.is_active = :active')
            ->setParameter('type', $type)->setParameter('active', true, Types::BOOLEAN);
        if ($condition > 0) {
            $query->andWhere('BIT_AND(r.condition, :condition) <> 0')->setParameter('condition', $condition, Types::INTEGER);
        }
        return array_column($query->orderBy('c.criteria')->getQuery()->getScalarResult(), 'criterion');
    }

    public function entityActionCount(array $types, int $entity): int
    {
        if (!$types) {
            return 0;
        }
        return (int)$this->em->createQueryBuilder()->select('COUNT(a.id)')->from(RuleAction::class, 'a')->join('a.rules', 'r')
            ->where('r.sub_type IN (:types) AND a.field = :field AND a.value = :entity')
            ->setParameter('types', $types)->setParameter('field', 'entities_id')->setParameter('entity', (string)$entity)
            ->getQuery()->getSingleScalarResult();
    }

    /** Resolve rule/action variants through the owning association, including SLA/OLA levels. */
    public function rulesForActions(string $table, string $foreignColumn, string $type, array $criteria): array
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        foreach ($metadata->associationMappings as $association) {
            if ($association->joinColumns[0]->name === $foreignColumn) {
                $parent = $this->em->getClassMetadata($association->targetEntity);
                $query = $this->em->createQueryBuilder()->select('DISTINCT s.id AS rule_id')->from($metadata->name, 'r')
                    ->join('r.' . $association->fieldName, 's');
                $query->where((new RecordCriteria($query, $metadata))->where($criteria));
                if ($parent->hasField('sub_type')) {
                    $query->andWhere('s.sub_type = :type')->setParameter('type', $type);
                }
                return array_map('intval', array_column($query->orderBy('s.id')->getQuery()->getScalarResult(), 'rule_id'));
            }
        }
        throw new UnsupportedCriteria('Rule action requires a mapped parent association.');
    }

    /** Repoint dropdown selections stored in rule payloads using their mapped scalar types. */
    public function replaceSelection(string $table, string $valueColumn, string $fieldColumn, int $id, int $replacement, string $field): void
    {
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        $query = $this->em->createQueryBuilder()->update($metadata->name, 'r');
        $compiler = new RecordCriteria($query, $metadata, false);
        $query->set($compiler->column($valueColumn), ':replacement')
            ->where($compiler->where([$valueColumn => (string)$id, $fieldColumn => ['LIKE', $field]]))
            ->setParameter('replacement', (string)$replacement, $metadata->getTypeOfField($metadata->getFieldName($valueColumn)))
            ->getQuery()->execute();
    }
}
