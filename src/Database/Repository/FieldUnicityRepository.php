<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

final class FieldUnicityRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Use all rules at the closest matching scope, with global as the fallback. */
    public function configuration(string $type, int $entity, array $ancestors, bool $active): array
    {
        $query = $this->em->createQueryBuilder()->select('r')
            ->addSelect('CASE WHEN IDENTITY(r.entities) = :entity THEN 2 WHEN r.entities IS NOT NULL THEN 1 ELSE 0 END AS HIDDEN scope_rank')
            ->from(Entity\FieldUnicity::class, 'r')->leftJoin('r.entities', 'scope')
            ->where('r.itemtype = :type')->setParameter('type', $type)->setParameter('entity', $entity, Types::INTEGER);
        $criteria = 'r.entities IS NULL OR IDENTITY(r.entities) = :entity';
        if ($ancestors) {
            $criteria .= ' OR (r.is_recursive = :recursive AND IDENTITY(r.entities) IN (:ancestors))';
            $query->setParameter('recursive', true, Types::BOOLEAN)->setParameter('ancestors', array_map('intval', array_values($ancestors)));
        }
        $query->andWhere('(' . $criteria . ')');
        if ($active) {
            $query->andWhere('r.is_active = :active')->setParameter('active', true, Types::BOOLEAN);
        }
        $query->orderBy('scope_rank', 'DESC')->addOrderBy('scope.level', 'DESC')->addOrderBy('r.id');
        $records = new RecordRepository($this->em);
        $result = [];
        $first = true;
        $chosen = null;
        foreach ($query->getQuery()->toIterable() as $record) {
            $row = $records->toRow($record);
            if (!$first && $chosen !== $row['entities_id']) {
                break;
            }
            $first = false;
            $chosen = $row['entities_id'];
            $result[] = $row;
        }
        return $result;
    }

    public function deletePluginRules(string $plugin): void
    {
        // Plugin uninstall historically removes these rules without model hooks.
        $this->em->createQueryBuilder()->delete(Entity\FieldUnicity::class, 'r')->where('r.itemtype LIKE :plugin')
            ->setParameter('plugin', '%Plugin' . $plugin . '%')->getQuery()->execute();
    }

    /** Group only mapped fields; SQL expressions and unknown plugin tables fail closed. */
    public function duplicates(string $table, array $fields, ?array $entities, bool $excludeTemplates): array
    {
        if (!$fields || $entities === []) {
            return [];
        }
        $class = EntityRegistry::TABLES[$table] ?? throw new \InvalidArgumentException('Unmapped uniqueness target');
        $metadata = $this->em->getClassMetadata($class);
        $query = $this->em->createQueryBuilder()->from($class, 'r')->select('COUNT(r.id) AS cpt')->having('COUNT(r.id) > 1');
        $compiler = new RecordCriteria($query, $metadata);
        foreach ($fields as $field) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $field)) {
                throw new \InvalidArgumentException('Uniqueness fields require mapped column names');
            }
            $expression = $compiler->column($field);
            $query->addSelect($expression . ' AS ' . $field)->addGroupBy($field)->andWhere($expression . ' IS NOT NULL');
            if ($metadata->hasField($field) && in_array($metadata->getTypeOfField($field), [Types::STRING, Types::TEXT], true)) {
                $query->andWhere($expression . " <> ''");
            }
            // Required root-entity zero is a real reference; nullable ones use NULL.
        }
        if ($entities !== null) {
            $query->andWhere('IDENTITY(r.entities) IN (:entities)')->setParameter('entities', array_map('intval', $entities));
        }
        if ($excludeTemplates) {
            $query->andWhere('r.is_template = :template')->setParameter('template', false, Types::BOOLEAN);
        }
        $query->orderBy('cpt', 'DESC');
        foreach ($fields as $field) {
            $query->addOrderBy($field);
        }
        $rows = $query->getQuery()->getScalarResult();
        foreach ($rows as &$row) {
            $row['cpt'] = (int)$row['cpt'];
        }
        return $rows;
    }
}
