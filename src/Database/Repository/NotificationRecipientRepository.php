<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;
use itsmng\Database\UnsupportedCriteria;

/** Scoped recipient projections; delivery and notification hooks remain in the target. */
final class NotificationRecipientRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function users(array $ids, array $profileCriteria): array
    {
        if (!$ids) {
            return [];
        }
        $query = $this->usersQuery($profileCriteria);
        return $query->andWhere('r.id IN (:recipients)')->setParameter('recipients', array_map('intval', $ids))
            ->getQuery()->getScalarResult();
    }

    public function linkedUsers(string $table, string $parentColumn, int $parent, int $role, array $profileCriteria): array
    {
        $class = $this->classFor($table);
        $query = $this->usersQuery($profileCriteria);
        $query->join($class, 'actor', 'WITH', 'IDENTITY(actor.' . $this->association($class, 'users_id') . ') = r.id')
            ->addSelect('actor.use_notification AS notif', 'actor.alternative_email AS altemail')
            ->andWhere('IDENTITY(actor.' . $this->association($class, $parentColumn) . ') = :parent AND actor.type = :role')
            ->setParameter('parent', $parent, Types::INTEGER)->setParameter('role', $role, Types::INTEGER);
        return $query->getQuery()->getScalarResult();
    }

    public function groupUsers(int $group, int $manager, array $profileCriteria): array
    {
        $query = $this->usersQuery($profileCriteria)
            ->join(Entity\GroupMembership::class, 'membership', 'WITH', 'IDENTITY(membership.users) = r.id')
            ->join('membership.groups', 'g')->andWhere('g.id = :group AND g.is_notify = :notify')
            ->setParameter('group', $group, Types::INTEGER)->setParameter('notify', true, Types::BOOLEAN);
        if ($manager === 1 || $manager === 2) {
            $query->andWhere('membership.is_manager = :manager')->setParameter('manager', $manager === 1, Types::BOOLEAN);
        }
        return $query->getQuery()->getScalarResult();
    }

    public function profileUsers(int $profile, array $profileCriteria): array
    {
        return $this->usersQuery($profileCriteria)->addSelect('IDENTITY(grant.entities) AS entity')
            ->andWhere('IDENTITY(grant.profiles) = :profile')->setParameter('profile', $profile, Types::INTEGER)
            ->getQuery()->getScalarResult();
    }

    public function recordUsers(string $table, string $userColumn, int $id, array $profileCriteria): array
    {
        $class = $this->classFor($table);
        return $this->usersQuery($profileCriteria)
            ->join($class, 'child', 'WITH', 'IDENTITY(child.' . $this->association($class, $userColumn) . ') = r.id')
            ->andWhere('child.id = :child')->setParameter('child', $id, Types::INTEGER)->getQuery()->getScalarResult();
    }

    public function anonymousUsers(string $table, string $parentColumn, int $parent, int $role): array
    {
        return (new RecordRepository($this->em))->matching($table, [$parentColumn => $parent, 'users_id' => 0, 'type' => $role, 'use_notification' => true]);
    }

    public function linkedGroups(string $table, string $parentColumn, int $parent, int $role): array
    {
        return (new RecordRepository($this->em))->identifiers($table, 'groups_id', [$parentColumn => $parent, 'type' => $role]);
    }

    public function taskGroups(string $table, int $id): array
    {
        return (new RecordRepository($this->em))->identifiers($table, 'groups_id_tech', ['id' => $id, 'NOT' => ['groups_id_tech' => 0]]);
    }

    public function suppliers(string $table, string $parentColumn, int $parent): array
    {
        $class = $this->classFor($table);
        return $this->em->createQueryBuilder()->select('DISTINCT s.email AS email', 's.name AS name')->from($class, 'r')
            ->leftJoin('r.' . $this->association($class, 'suppliers_id'), 's')
            ->where('IDENTITY(r.' . $this->association($class, $parentColumn) . ') = :parent')
            ->setParameter('parent', $parent, Types::INTEGER)->getQuery()->getScalarResult();
    }

    public function privateProfiles(): array
    {
        return array_map('intval', array_column($this->em->createQueryBuilder()->select('IDENTITY(r.profiles) AS id')
            ->from(Entity\ProfileRight::class, 'r')->where('r.name = :right AND BIT_AND(r.rights, :private) <> 0')
            ->setParameter('right', 'followup')->setParameter('private', \ITILFollowup::SEEPRIVATE, Types::INTEGER)
            ->getQuery()->getScalarResult(), 'id'));
    }

    public function canSeePrivate(int $user, array $profiles, array $scope): bool
    {
        if ($user <= 0 || !$profiles) {
            return false;
        }
        return (new RecordRepository($this->em))->countMatching(
            'glpi_profiles_users',
            ['users_id' => $user, 'profiles_id' => array_values($profiles), $scope]
        ) > 0;
    }

    public function countForGroup(int $group, array $scope): int
    {
        return (int)$this->groupTargets($group, $scope)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
    }

    public function notificationsForGroup(int $group, array $scope): array
    {
        return $this->groupTargets($group, $scope)->select('notification.id AS id')->orderBy('r.id')->getQuery()->getScalarResult();
    }

    public function hasAuthorMailing(): bool
    {
        return $this->em->createQueryBuilder()->select('r.id')->from(Entity\NotificationTarget::class, 'r')
            ->join('r.notifications', 'notification')
            ->join(Entity\NotificationNotificationTemplate::class, 'binding', 'WITH', 'binding.notifications = notification')
            ->where('notification.itemtype = :itemtype AND binding.mode = :mode AND r.type = :kind AND r.recipient_code = :recipient')
            ->setParameter('itemtype', 'Ticket')->setParameter('mode', \Notification_NotificationTemplate::MODE_MAIL)
            ->setParameter('kind', \Notification::USER_TYPE, Types::INTEGER)->setParameter('recipient', \Notification::AUTHOR, Types::INTEGER)
            ->setMaxResults(1)->getQuery()->getScalarResult() !== [];
    }

    private function groupTargets(int $group, array $scope): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->from(Entity\NotificationTarget::class, 'r')->join('r.notifications', 'notification');
        $criteria = (new RecordCriteria($query, $this->em->getClassMetadata(Entity\NotificationTarget::class)))
            ->withJoinedMetadata($this->em->getClassMetadata(Entity\Notification::class), 'notification');
        return $query->where($criteria->where([
            'type' => [\Notification::SUPERVISOR_GROUP_TYPE, \Notification::GROUP_TYPE], 'groups_id' => $group, $scope,
        ]));
    }

    /** Remove or reassign the whole target; clearing a required audience is invalid. */
    public function replaceGroup(int $id, int $replacement): void
    {
        $this->replaceAudience('group', $id, $replacement);
    }

    public function replaceProfile(int $id, int $replacement): void
    {
        $this->replaceAudience('profile', $id, $replacement);
    }

    private function replaceAudience(string $association, int $id, int $replacement): void
    {
        $query = $this->em->createQueryBuilder()->where('IDENTITY(r.' . $association . ') = :old')
            ->setParameter('old', $id, Types::INTEGER);
        if ($replacement > 0) {
            $query->update(Entity\NotificationTarget::class, 'r')->set('r.' . $association, ':replacement')
                ->setParameter('replacement', $replacement, Types::INTEGER);
        } else {
            $query->delete(Entity\NotificationTarget::class, 'r');
        }
        $query->getQuery()->execute();
    }

    private function usersQuery(array $profileCriteria): QueryBuilder
    {
        if (array_diff(array_keys($profileCriteria), ['INNER JOIN', 'WHERE'])) {
            throw new UnsupportedCriteria('Recipient profile extensions require mapped inner joins and criteria');
        }
        $query = $this->em->createQueryBuilder()->select('DISTINCT r.id AS users_id', 'r.language AS language')
            ->from(Entity\User::class, 'r')->join(Entity\ProfileUser::class, 'grant', 'WITH', 'IDENTITY(grant.users) = r.id');
        $compiler = (new RecordCriteria($query, $this->em->getClassMetadata(Entity\User::class)))
            ->withJoinedMetadata($this->em->getClassMetadata(Entity\ProfileUser::class), 'grant');
        // Keep the public extension hook, but accept only its explicit mapped joins.
        foreach ($profileCriteria['INNER JOIN'] ?? [] as $table => $definition) {
            $class = $this->classFor($table);
            [$alias, $parentAlias, $property] = match ($class) {
                Entity\ProfileUser::class => ['grant', 'r', 'users'],
                Entity\Profile::class => ['profile', 'grant', 'profiles'],
                Entity\ProfileRight::class => ['rights', 'profile', 'profiles'],
                default => throw new UnsupportedCriteria('Unmapped recipient profile join: ' . $table),
            };
            $parentClass = match ($parentAlias) {
                'r' => Entity\User::class,
                'grant' => Entity\ProfileUser::class,
                'profile' => Entity\Profile::class,
            };
            $owner = $class === Entity\Profile::class ? $parentClass : $class;
            $mapping = $this->em->getClassMetadata($owner)->getAssociationMapping($property);
            $expected = [
                $this->em->getClassMetadata($owner)->getTableName() => $mapping->joinColumns[0]->name,
                $this->em->getClassMetadata($mapping->targetEntity)->getTableName() => 'id',
            ];
            if (array_keys($definition) !== ['ON'] || count($definition['ON']) !== 2
                || array_diff_assoc($definition['ON'], $expected)) {
                throw new UnsupportedCriteria('Recipient profile join must use its declared association');
            }
            if ($alias === 'grant') {
                continue;
            }
            if (!in_array($parentAlias, $query->getAllAliases(), true)) {
                throw new UnsupportedCriteria('Recipient profile joins require their mapped parent alias');
            }
            if ($class === Entity\Profile::class) {
                $query->join($parentAlias . '.' . $property, $alias);
            } else {
                $query->join($class, $alias, 'WITH', 'IDENTITY(' . $alias . '.' . $property . ') = ' . $parentAlias . '.id');
            }
            $compiler->withJoinedMetadata($this->em->getClassMetadata($class), $alias);
        }
        return $query->where($compiler->where($profileCriteria['WHERE'] ?? []));
    }

    private function classFor(string $table): string
    {
        return EntityRegistry::tables()[$table] ?? throw new \InvalidArgumentException('Unmapped recipient table');
    }

    private function association(string $class, string $column): string
    {
        foreach ($this->em->getClassMetadata($class)->associationMappings as $property => $mapping) {
            if ($mapping->isToOneOwningSide() && count($mapping->joinColumns) === 1 && $mapping->joinColumns[0]->name === $column) {
                return $property;
            }
        }
        throw new \InvalidArgumentException('Recipient field is not a mapped association: ' . $column);
    }
}
