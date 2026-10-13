<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use RuntimeException;
use itsmng\Database\Entity\DisplayPreference;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\User;

final class DisplayPreferenceRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    private function query(string $type, int $owner): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->from(DisplayPreference::class, 'p')
            ->where('p.itemtype = :type')->setParameter('type', $type);
        return $this->scope($query, $owner);
    }

    private function scope(QueryBuilder $query, int $owner): QueryBuilder
    {
        if ($owner < 0) {
            throw new InvalidArgumentException('Invalid display preference owner');
        }
        return $owner === 0 ? $query->andWhere('p.owner IS NULL')
            : $query->andWhere('IDENTITY(p.owner) = :owner')->setParameter('owner', $owner, Types::INTEGER);
    }

    private function lockOwner(int $owner): ?User
    {
        if ($owner < 0) {
            throw new InvalidArgumentException('Invalid display preference owner');
        }
        if ($owner === 0) {
            // The real root entity anchors application-wide defaults, even when
            // a type has no preference rows yet. No synthetic user is required.
            if ($this->em->find(Entity::class, 0, LockMode::PESSIMISTIC_WRITE) === null) {
                throw new RuntimeException('Missing root entity for default display preferences');
            }
            return null;
        }
        return $this->em->find(User::class, $owner, LockMode::PESSIMISTIC_WRITE);
    }

    public function rows(string $type, int $owner): array
    {
        return (new RecordRepository($this->em))->matching('glpi_displaypreferences', ['itemtype' => $type, 'users_id' => $owner === 0 ? null : $owner], ['rank', 'id'], legacyValues: false);
    }

    public function nextRank(string $type, int $owner): int
    {
        return 1 + (int)$this->query($type, $owner)->select('MAX(p.rank)')->getQuery()->getSingleScalarResult();
    }

    /** Personal settings replace the default list completely when any exist. */
    public function columns(string $type, int $owner): array
    {
        $query = $this->em->createQueryBuilder()->select('p.num, IDENTITY(p.owner) AS owner')->from(DisplayPreference::class, 'p')
            ->where('p.itemtype = :type AND (p.owner IS NULL OR IDENTITY(p.owner) = :owner)')
            ->setParameter('type', $type)->setParameter('owner', $owner, Types::INTEGER)->orderBy('p.rank')->addOrderBy('p.id');
        $defaults = $personal = [];
        foreach ($query->getQuery()->getScalarResult() as $row) {
            if ($row['owner'] === null) {
                $defaults[] = (int)$row['num'];
            } else {
                $personal[] = (int)$row['num'];
            }
        }
        return $personal ?: $defaults;
    }

    public function countsByType(int $owner): array
    {
        $query = $this->em->createQueryBuilder()->select('p.itemtype, COUNT(p.id) AS nb')->from(DisplayPreference::class, 'p');
        return $this->scope($query, $owner)->groupBy('p.itemtype')->orderBy('p.itemtype')->getQuery()->getScalarResult();
    }

    /** Save the modal's complete list atomically while retaining protected existing columns. */
    public function replaceColumns(string $type, int $owner, array $columns, array $protected): bool
    {
        $columns = array_values(array_unique(array_map('intval', $columns)));
        return $this->em->getConnection()->transactional(function () use ($type, $owner, $columns, $protected): bool {
            $user = $this->lockOwner($owner);
            if ($owner > 0 && $user === null) {
                return false;
            }
            $existing = $this->query($type, $owner)->select('p')->orderBy('p.id')->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getResult();
            if ($owner > 0 && !$existing) {
                return false; // Personal lists must be explicitly activated first.
            }
            usort($existing, static fn ($a, $b) => [$a->rank, $a->id] <=> [$b->rank, $b->id]);
            $byColumn = [];
            foreach ($existing as $record) {
                $byColumn[$record->num] = $record;
                if (in_array($record->num, $protected, true) && !in_array($record->num, $columns, true)) {
                    $columns[] = $record->num;
                }
            }
            foreach ($existing as $record) {
                if (!in_array($record->num, $columns, true)) {
                    $this->em->remove($record);
                }
            }
            foreach ($columns as $rank => $num) {
                $record = $byColumn[$num] ?? new DisplayPreference();
                $record->itemtype = $type;
                $record->owner = $user;
                $record->num = $num;
                $record->rank = $rank + 1;
                if ($record->id === null) {
                    $this->em->persist($record);
                }
            }
            $this->em->flush();
            return true;
        });
    }

    /** Serialize activation for a user; an existing personal list is never overwritten. */
    public function activate(string $type, int $owner, ?int $fallback): bool
    {
        if ($owner <= 0) {
            return false;
        }
        return $this->em->getConnection()->transactional(function () use ($type, $owner, $fallback): bool {
            $user = $this->lockOwner($owner);
            if ($user === null || $this->query($type, $owner)->select('p.id')->setMaxResults(1)->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult() !== null) {
                return false;
            }
            $defaults = $this->rows($type, 0);
            if (!$defaults && $fallback !== null) {
                $defaults = [['num' => $fallback, 'rank' => 1]];
            }
            foreach ($defaults as $row) {
                $preference = new DisplayPreference();
                $preference->owner = $user;
                $preference->itemtype = $type;
                $preference->num = (int)$row['num'];
                $preference->rank = (int)$row['rank'];
                $this->em->persist($preference);
            }
            $this->em->flush();
            return $defaults !== [];
        });
    }

    /** Lock in ID order, then reorder and renumber the selected owner's list atomically. */
    public function move(string $type, int $owner, int $id, string $direction): bool
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            return false;
        }
        return $this->em->getConnection()->transactional(function () use ($type, $owner, $id, $direction): bool {
            if ($this->lockOwner($owner) === null && $owner > 0) {
                return false;
            }
            $rows = $this->query($type, $owner)->select('p')->orderBy('p.id')->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getResult();
            usort($rows, static fn ($a, $b) => [$a->rank, $a->id] <=> [$b->rank, $b->id]);
            $position = array_search($id, array_map(static fn ($row) => $row->id, $rows), true);
            if ($position === false) {
                return false;
            }
            $next = $position + ($direction === 'up' ? -1 : 1);
            if (!isset($rows[$next])) {
                return false;
            }
            [$rows[$position], $rows[$next]] = [$rows[$next], $rows[$position]];
            foreach ($rows as $rank => $row) {
                $row->rank = $rank + 1;
            }
            $this->em->flush();
            return true;
        });
    }
}
