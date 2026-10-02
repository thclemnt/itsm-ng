<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\DropdownTranslation;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

/** Translation reads bind the dropdown kind, identifier, field and language together. */
final class DropdownTranslationRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function rows(array $criteria, array|string $order = ['id ASC'], ?int $limit = null): array
    {
        return (new RecordRepository($this->em))->matching('glpi_dropdowntranslations', $criteria, $order, $limit, legacyValues: false);
    }

    public function available(string $language): array
    {
        return $this->em->createQueryBuilder()->select('DISTINCT t.itemtype, t.field')->from(DropdownTranslation::class, 't')
            ->where('t.language = :language')->setParameter('language', $language, Types::STRING)
            ->orderBy('t.itemtype')->addOrderBy('t.field')->getQuery()->getScalarResult();
    }

    /** Snapshot only identifiers before recursive translation hooks update descendants. */
    public function childIds(string $table, string $parentColumn, int $parent): array
    {
        return (new RecordRepository($this->em))->identifiers($table, 'id', [$parentColumn => $parent], ['id ASC']);
    }

    /** Literal names, including NULL and backslashes, are data rather than legacy SQL values. */
    public function dropdownId(string $table, string $field, string $value): ?int
    {
        $class = EntityRegistry::tables()[$table];
        $query = $this->em->createQueryBuilder()->select('r.id')->from($class, 'r');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata($class), false);
        $ids = $query->where($compiler->where([$field => $value]))->orderBy('r.id')->setMaxResults(1)->getQuery()->getSingleColumnResult();
        return $ids ? (int)$ids[0] : null;
    }

    /** Bound type/field/language joins prevent translations crossing dropdown kinds. */
    public function dropdownRow(string $table, int $id, string $type, string $language, array $translations, ?array $columns = null): ?array
    {
        $translations = array_values($translations);
        $columns = $columns === null ? null : array_values($columns);
        $metadata = $this->em->getClassMetadata(EntityRegistry::tables()[$table]);
        $query = $this->em->createQueryBuilder()->from($metadata->name, 'r')
            ->where('r.id = :id')->setParameter('id', $id, Types::INTEGER);
        if ($columns === null) {
            $query->select('r');
        } else {
            $compiler = new RecordCriteria($query, $metadata, false);
            foreach ($columns as $index => $column) {
                $query->addSelect($compiler->column($column) . ' AS column' . $index);
            }
        }
        foreach ($translations as $index => $field) {
            $alias = 'translation' . $index;
            $query->leftJoin(DropdownTranslation::class, $alias, 'WITH', $alias . '.items_id = r.id AND '
                . $alias . '.itemtype = :type AND ' . $alias . '.language = :language AND ' . $alias . '.field = :field' . $index)
                ->addSelect($alias . '.value AS translated' . $index)->setParameter('field' . $index, $field, Types::STRING);
        }
        if ($translations) {
            $query->setParameter('type', $type, Types::STRING)->setParameter('language', $language, Types::STRING);
        }
        $result = $query->getQuery()->getOneOrNullResult();
        if ($result === null) {
            return null;
        }
        if ($columns === null) {
            $record = $translations ? $result[0] : $result;
            $row = (new RecordRepository($this->em))->toRow($record);
            $this->em->detach($record);
        } else {
            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = $result['column' . $index];
            }
        }
        $row += ['transname' => '', 'transcomment' => ''];
        foreach ($translations as $index => $field) {
            $row[$field === 'comment' ? 'transcomment' : 'transname'] = $result['translated' . $index];
        }
        return $row;
    }
}
