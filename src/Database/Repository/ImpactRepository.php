<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

final class ImpactRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function itemId(string $type, int $id): ?int
    {
        $row = $this->em->createQueryBuilder()->select('i.id')->from(Entity\ImpactItem::class, 'i')
            ->where('i.itemtype = :type AND i.items_id = :id')->setParameter('type', $type)->setParameter('id', $id, Types::INTEGER)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        return $row === null ? null : (int)$row['id'];
    }

    public function relationId(array $criteria): ?int
    {
        $ids = (new RecordRepository($this->em))->identifiers('glpi_impactrelations', 'id', $criteria);
        return $ids[0] ?? null;
    }

    public function relations(string $type, int $id, string $endpoint): array
    {
        if (!in_array($endpoint, ['source', 'impacted'], true)) {
            throw new \InvalidArgumentException('Invalid impact endpoint');
        }
        return (new RecordRepository($this->em))->matching('glpi_impactrelations', ['itemtype_' . $endpoint => $type, 'items_id_' . $endpoint => $id], ['id'], legacyValues: false);
    }

    public function relationCount(string $type, int $id, array $enabled): int
    {
        $query = $this->em->createQueryBuilder()->select('COUNT(r.id)')->from(Entity\ImpactRelation::class, 'r')
            ->where('(r.itemtype_source = :type AND r.items_id_source = :id) OR (r.itemtype_impacted = :type AND r.items_id_impacted = :id AND r.itemtype_source IN (:enabled))')
            ->setParameter('type', $type)->setParameter('id', $id, Types::INTEGER)->setParameter('enabled', $enabled ?: ['']);
        return (int)$query->getQuery()->getSingleScalarResult();
    }

    /** Same filtered query supplies total and a bounded, deterministic page. */
    public function searchAssets(string $table, string $nameField, array $criteria, array $used, string $filter, int $page, bool $firstNameFirst, bool $allProjects, int $user, array $groups): array
    {
        $entity = EntityRegistry::tables()[$table] ?? throw new \InvalidArgumentException('Impact asset type needs an ORM mapping');
        $query = $this->em->createQueryBuilder()->from($entity, 'r');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata($entity));
        $query->where($compiler->where($criteria));
        if ($used) {
            $query->andWhere('r.id NOT IN (:used)')->setParameter('used', array_map('intval', $used));
        }
        // Bound parameters still use LIKE syntax: preserve literal backslashes.
        $filter = str_replace('\\', '\\\\', $filter);
        if ($entity === Entity\User::class) {
            $query->andWhere("LOWER(r.name) LIKE LOWER(:filter) OR LOWER(REPLACE(CONCAT(COALESCE(r.firstname, ''), COALESCE(r.realname, '')), ' ', '')) LIKE LOWER(:compact) OR LOWER(REPLACE(CONCAT(COALESCE(r.realname, ''), COALESCE(r.firstname, '')), ' ', '')) LIKE LOWER(:compact)")
                ->setParameter('compact', '%' . str_replace(' ', '', $filter) . '%');
            [$first, $last] = $firstNameFirst ? ['firstname', 'realname'] : ['realname', 'firstname'];
            $name = "CASE WHEN r.$first <> '' AND r.$last <> '' THEN CONCAT(r.$first, ' ', r.$last) ELSE r.name END";
        } else {
            $name = $compiler->column($nameField);
            $query->andWhere('LOWER(' . $name . ') LIKE LOWER(:filter)');
        }
        $query->setParameter('filter', '%' . $filter . '%');
        if ($entity === Entity\Project::class) {
            (new ProjectRepository($this->em))->restrictVisibility($query, $allProjects, $user, $groups);
        }
        $total = (int)(clone $query)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $items = $query->select('r.id', $name . ' AS name')->orderBy('r.id')->setFirstResult(max(0, $page) * 20)->setMaxResults(20)->getQuery()->getScalarResult();
        return ['items' => $items, 'total' => $total];
    }

    /** Remove graph state atomically while preserving contexts owned by other nodes. */
    public function clean(string $type, int $id): void
    {
        $this->em->getConnection()->transactional(function () use ($type, $id): void {
            $this->em->createQueryBuilder()->delete(Entity\ImpactRelation::class, 'r')
                ->where('(r.itemtype_source = :type AND r.items_id_source = :id) OR (r.itemtype_impacted = :type AND r.items_id_impacted = :id)')
                ->setParameter('type', $type)->setParameter('id', $id, Types::INTEGER)->getQuery()->execute();
            $item = $this->em->getRepository(Entity\ImpactItem::class)->findOneBy(['itemtype' => $type, 'items_id' => $id]);
            if ($item === null) {
                return;
            }
            $context = $item->context;
            $compound = $item->compound;
            $ownsContext = !$item->is_slave;
            $this->em->remove($item);
            $this->em->flush();
            if ($context !== null && $ownsContext) {
                $this->clearAssociation('context', $context->id);
                $this->em->remove($context);
                $this->em->flush();
            }
            if ($compound !== null) {
                $count = $this->em->createQueryBuilder()->select('COUNT(i.id)')->from(Entity\ImpactItem::class, 'i')
                    ->where('IDENTITY(i.compound) = :id')->setParameter('id', $compound->id, Types::INTEGER)->getQuery()->getSingleScalarResult();
                if ($count < 2) {
                    $this->clearAssociation('compound', $compound->id);
                    $this->em->remove($compound);
                    $this->em->flush();
                }
            }
        });
    }

    private function clearAssociation(string $association, int $id): void
    {
        $this->em->createQueryBuilder()->update(Entity\ImpactItem::class, 'i')->set('i.' . $association, 'NULL')
            ->where('IDENTITY(i.' . $association . ') = :id')->setParameter('id', $id, Types::INTEGER)->getQuery()->execute();
    }
}
