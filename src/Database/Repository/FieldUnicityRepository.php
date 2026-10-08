<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use CommonDBTM;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

use function getTableNameForForeignKeyField;
use function isPluginItemType;

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

    /** Registered plugin models retain their own schema; core records keep their ORM query. */
    public function duplicatesForItem(CommonDBTM $item, array $fields, ?array $entities): array
    {
        if (!$fields || $entities === []) {
            return [];
        }
        $table = $item->getTable();
        if (isset(EntityRegistry::tables()[$table])) {
            return $this->duplicates($table, $fields, $entities, $item->maybeTemplate());
        }
        $plugin = isPluginItemType($item->getType());
        if (!$plugin || !in_array($item->getType(), $GLOBALS['CFG_GLPI']['unicity_types'] ?? [], true)
            || !preg_match('/^glpi_plugin_[a-z0-9_]+$/D', $table)
            || !str_starts_with($table, 'glpi_plugin_' . strtolower($plugin['plugin']) . '_')) {
            throw new InvalidArgumentException('Uniqueness requires a mapped record or a registered plugin model.');
        }
        $scoped = $entities !== null && $item->isEntityAssign();
        $templates = $item->maybeTemplate();
        $connection = $this->em->getConnection();
        $schema = $connection->createSchemaManager()->introspectTable($table);
        $integers = [Types::SMALLINT, Types::INTEGER, Types::BIGINT];
        $strings = [Types::STRING, Types::ASCII_STRING, Types::TEXT];
        $groupable = [...$integers, ...$strings, Types::BOOLEAN, Types::DECIMAL, Types::FLOAT,
            Types::DATE_MUTABLE, Types::DATE_IMMUTABLE, Types::TIME_MUTABLE, Types::TIME_IMMUTABLE,
            Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE, Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE];
        $types = [];
        foreach (array_unique([...$fields, ...($scoped ? ['entities_id'] : []), ...($templates ? ['is_template'] : [])]) as $field) {
            if (!is_string($field) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $field) || !$schema->hasColumn($field)) {
                throw new InvalidArgumentException('Plugin uniqueness requires existing column names.');
            }
            $types[$field] = Type::lookupName($schema->getColumn($field)->getType());
            if (!in_array($types[$field], $groupable, true)) {
                throw new InvalidArgumentException('Plugin uniqueness requires scalar columns.');
            }
        }
        if (($scoped && !in_array($types['entities_id'], $integers, true))
            || ($templates && !in_array($types['is_template'], [...$integers, Types::BOOLEAN], true))) {
            throw new InvalidArgumentException('Plugin uniqueness requires integer entity and boolean or integer template columns.');
        }
        $platform = $connection->getDatabasePlatform();
        $query = $connection->createQueryBuilder()->select('COUNT(*) AS cpt')
            ->from($platform->quoteIdentifier($table))->having('COUNT(*) > 1')->orderBy('cpt', 'DESC');
        foreach (array_values($fields) as $index => $field) {
            $column = $platform->quoteIdentifier($field);
            $query->addSelect($column)->addGroupBy($column)->addOrderBy($column)->andWhere($column . ' IS NOT NULL');
            if (getTableNameForForeignKeyField($field) !== '') {
                // Unmapped plugin references retain their legacy empty-zero sentinel.
                if (!in_array($types[$field], [...$integers, ...$strings], true)) {
                    throw new InvalidArgumentException('Plugin uniqueness references require integer or text columns.');
                }
                $query->andWhere($column . ' <> :empty_' . $index)
                    ->setParameter('empty_' . $index, in_array($types[$field], $strings, true) ? '0' : 0, $types[$field]);
            } elseif (in_array($types[$field], $strings, true)) {
                $query->andWhere($column . ' <> :empty_' . $index)->setParameter('empty_' . $index, '', $types[$field]);
            }
        }
        if ($scoped) {
            $query->andWhere($platform->quoteIdentifier('entities_id') . ' IN (:entities)')
                ->setParameter('entities', array_map('intval', $entities), ArrayParameterType::INTEGER);
        }
        if ($templates) {
            $query->andWhere($platform->quoteIdentifier('is_template') . ' = :template')
                ->setParameter('template', $types['is_template'] === Types::BOOLEAN ? false : 0, $types['is_template']);
        }
        $rows = $query->executeQuery()->fetchAllAssociative();
        foreach ($rows as &$row) {
            $row['cpt'] = (int)$row['cpt'];
        }
        return $rows;
    }

    /** Group only mapped fields; SQL expressions and unknown tables fail closed. */
    public function duplicates(string $table, array $fields, ?array $entities, bool $excludeTemplates): array
    {
        if (!$fields || $entities === []) {
            return [];
        }
        $class = EntityRegistry::tables()[$table] ?? throw new InvalidArgumentException('Unmapped uniqueness target');
        $metadata = $this->em->getClassMetadata($class);
        $query = $this->em->createQueryBuilder()->from($class, 'r')->select('COUNT(r.id) AS cpt')->having('COUNT(r.id) > 1');
        $compiler = new RecordCriteria($query, $metadata);
        foreach ($fields as $field) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $field)) {
                throw new InvalidArgumentException('Uniqueness fields require mapped column names');
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
