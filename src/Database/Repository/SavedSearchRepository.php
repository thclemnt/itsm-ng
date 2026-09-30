<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\SavedSearch;
use itsmng\Database\Entity\SavedSearchAlert;
use itsmng\Database\Entity\SavedSearchUser;
use itsmng\Database\RecordCriteria;

final class SavedSearchRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Entity scope comes from the same recursive visibility rules as the search UI. */
    public function visible(int $viewer, bool $public, array $entityScope): array
    {
        $rows = ['private' => [], 'public' => []];
        if ($viewer <= 0) {
            return $rows;
        }
        $query = $this->em->createQueryBuilder()->select('r', 'd.id AS default_id')->from(SavedSearch::class, 'r')
            ->leftJoin(SavedSearchUser::class, 'd', 'WITH', 'd.savedsearches = r.id AND d.itemtype = r.itemtype AND IDENTITY(d.users) = :viewer')
            ->setParameter('viewer', $viewer, Types::INTEGER);
        $criteria = ['is_private' => true, 'users_id' => $viewer];
        if ($public) {
            $criteria = ['OR' => [$criteria, ['is_private' => false]]];
        }
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(SavedSearch::class), false);
        $query->where($compiler->where([$criteria, $entityScope]))
            ->orderBy('r.itemtype')->addOrderBy('r.name')->addOrderBy('r.id');
        $records = new RecordRepository($this->em);
        foreach ($query->getQuery()->toIterable() as $result) {
            $row = $records->toRow($result[0]);
            $row['IS_DEFAULT'] = $result['default_id'] === null ? null : (int)$result['default_id'];
            $rows[$row['is_private'] ? 'private' : 'public'][$row['id']] = $row;
            $this->em->detach($result[0]);
        }
        return $rows;
    }

    public function itemtypes(): array
    {
        return array_column($this->em->createQueryBuilder()->select('DISTINCT r.itemtype AS itemtype')
            ->from(SavedSearch::class, 'r')->orderBy('r.itemtype')->getQuery()->getScalarResult(), 'itemtype');
    }

    /** Atomic counters cannot use an entity read followed by a write. */
    public function recordExecution(int $id, int $milliseconds, bool $increment = true, ?\DateTimeImmutable $at = null): void
    {
        $query = $this->em->createQueryBuilder()->update(SavedSearch::class, 'r')
            ->set('r.last_execution_time', ':time')->set('r.last_execution_date', ':at')->where('r.id = :id')
            ->setParameter('time', $milliseconds, Types::INTEGER)->setParameter('id', $id, Types::INTEGER)
            ->setParameter('at', $at ?? new \DateTimeImmutable(), Types::DATETIMETZ_IMMUTABLE);
        if ($increment) {
            $query->set('r.counter', 'r.counter + 1');
        }
        $query->getQuery()->execute();
    }

    /** Selection only: NULL and boundary dates retain the existing scheduler semantics. */
    public function stale(\DateTimeInterface $before): array
    {
        return (new RecordRepository($this->em))->matching('glpi_savedsearches', [
            'last_execution_date' => ['<', $before->format('Y-m-d H:i:s')],
        ], ['last_execution_date', 'id']);
    }

    /** Ownerless searches cannot establish a user context for alert execution. */
    public function activeAlerts(): array
    {
        $query = $this->em->createQueryBuilder()->select('a')->from(SavedSearchAlert::class, 'a')
            ->join('a.savedsearches', 's')->join('s.users', 'u')
            ->where('a.is_active = :active AND u.id > 0')->setParameter('active', true, Types::BOOLEAN)
            ->orderBy('a.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
            $this->em->detach($record);
        }
        return $rows;
    }

    public function setCountMode(array $ids, int $mode): void
    {
        if (!in_array($mode, [\SavedSearch::COUNT_AUTO, \SavedSearch::COUNT_YES, \SavedSearch::COUNT_NO], true)) {
            throw new \InvalidArgumentException('Invalid saved-search count mode');
        }
        if ($ids) {
            $this->em->createQueryBuilder()->update(SavedSearch::class, 'r')->set('r.do_count', ':mode')
                ->where('r.id IN (:ids)')->setParameter('ids', array_map('intval', $ids), ArrayParameterType::INTEGER)
                ->setParameter('mode', $mode, Types::SMALLINT)->getQuery()->execute();
        }
    }

    public function setEntity(array $ids, int $entity, bool $recursive): void
    {
        if ($ids) {
            $this->em->createQueryBuilder()->update(SavedSearch::class, 'r')->set('r.entities', ':entity')->set('r.is_recursive', ':recursive')
                ->where('r.id IN (:ids)')->setParameter('ids', array_map('intval', $ids), ArrayParameterType::INTEGER)
                ->setParameter('entity', $entity < 0 ? null : $entity, Types::INTEGER)->setParameter('recursive', $recursive, Types::BOOLEAN)->getQuery()->execute();
        }
    }
}
