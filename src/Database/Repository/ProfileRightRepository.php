<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Profile;
use itsmng\Database\Entity\ProfileRight;
use itsmng\Database\Entity\ProfileUser;
use itsmng\Database\RecordCriteria;

/** Permission definitions and migrations; application updates retain their history/session hooks. */
final class ProfileRightRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function names(): array
    {
        return array_column($this->em->createQueryBuilder()->select('DISTINCT r.name AS name')->from(ProfileRight::class, 'r')
            ->getQuery()->getScalarResult(), 'name');
    }

    public function forProfile(int $profile, array $names = []): array
    {
        $query = $this->em->createQueryBuilder()->select('r.name AS name', 'r.rights AS rights')->from(ProfileRight::class, 'r')
            ->where('IDENTITY(r.profiles) = :profile')->setParameter('profile', $profile, Types::INTEGER);
        if ($names) {
            $query->andWhere('r.name IN (:names)')->setParameter('names', array_values($names));
        }
        return array_column($query->getQuery()->getScalarResult(), 'rights', 'name');
    }

    public function idFor(int $profile, string $name): ?int
    {
        $rows = $this->em->createQueryBuilder()->select('r.id AS id')->from(ProfileRight::class, 'r')
            ->where('IDENTITY(r.profiles) = :profile AND r.name = :name')
            ->setParameter('profile', $profile, Types::INTEGER)->setParameter('name', $name)->setMaxResults(1)->getQuery()->getScalarResult();
        return isset($rows[0]) ? (int)$rows[0]['id'] : null;
    }

    /** Definitions are installed atomically on every profile, initially without permissions. */
    public function addDefinitions(array $names): bool
    {
        if (!$names) {
            return true;
        }
        return $this->mutate(function () use ($names): void {
            $profiles = $this->em->createQueryBuilder()->select('p.id AS id')->from(Profile::class, 'p')->getQuery()->getScalarResult();
            foreach ($profiles as $profile) {
                foreach ($names as $name) {
                    $right = new ProfileRight();
                    $right->profiles = $this->em->getReference(Profile::class, $profile['id']);
                    $right->name = $name;
                    $this->em->persist($right);
                }
            }
            $this->em->flush();
        });
    }

    public function deleteDefinitions(array $names): bool
    {
        return $this->mutate(function () use ($names): void {
            foreach ($names as $name) {
                $query = $this->em->createQueryBuilder()->delete(ProfileRight::class, 'r');
                $query->where((new RecordCriteria($query, $this->em->getClassMetadata(ProfileRight::class), false))->where(['name' => $name]));
                $query->getQuery()->execute();
            }
        });
    }

    /** Snapshot source profile IDs before updating a target right, including a self-copy. */
    public function grantFrom(string $name, int $mask, array $sourceCriteria): bool
    {
        return $this->mutate(function () use ($name, $mask, $sourceCriteria): void {
            $source = $this->em->createQueryBuilder()->select('DISTINCT IDENTITY(r.profiles) AS id')->from(ProfileRight::class, 'r');
            $source->where((new RecordCriteria($source, $this->em->getClassMetadata(ProfileRight::class), false))->where($sourceCriteria));
            $profiles = array_column($source->getQuery()->getScalarResult(), 'id');
            if (!$profiles) {
                return;
            }
            $this->em->createQueryBuilder()->update(ProfileRight::class, 'r')->set('r.rights', 'BIT_OR(r.rights, :mask)')
                ->where('r.name = :name AND IDENTITY(r.profiles) IN (:profiles)')
                ->setParameter('mask', $mask, Types::INTEGER)->setParameter('name', $name)->setParameter('profiles', $profiles)
                ->getQuery()->execute();
        });
    }

    public function copyFrom(string $target, string $source, array $criteria): bool
    {
        return $this->mutate(function () use ($target, $source, $criteria): void {
            $query = $this->em->createQueryBuilder()->select('IDENTITY(r.profiles) AS profile', 'r.rights AS rights')->from(ProfileRight::class, 'r');
            $query->where((new RecordCriteria($query, $this->em->getClassMetadata(ProfileRight::class), false))->where(['name' => $source] + $criteria));
            foreach ($query->getQuery()->getScalarResult() as $row) {
                $this->em->createQueryBuilder()->update(ProfileRight::class, 'r')->set('r.rights', ':rights')
                    ->where('r.name = :name AND IDENTITY(r.profiles) = :profile')
                    ->setParameter('rights', $row['rights'], Types::INTEGER)->setParameter('name', $target)->setParameter('profile', $row['profile'], Types::INTEGER)
                    ->getQuery()->execute();
            }
        });
    }

    /** Complete only missing definitions; a selected profile's existing mask is never reset. */
    public function fill(int $profile): void
    {
        $this->em->getConnection()->transactional(function () use ($profile): void {
            $missing = $this->em->createQueryBuilder()->select('DISTINCT possible.name AS name')->from(ProfileRight::class, 'possible')
                ->where('NOT EXISTS (SELECT current.id FROM ' . ProfileRight::class . ' current WHERE IDENTITY(current.profiles) = :profile AND current.name = possible.name)')
                ->setParameter('profile', $profile, Types::INTEGER)->getQuery()->getScalarResult();
            foreach ($missing as $definition) {
                $right = new ProfileRight();
                $right->profiles = $this->em->getReference(Profile::class, $profile);
                $right->name = $definition['name'];
                $this->em->persist($right);
            }
            if ($missing) {
                $this->em->flush();
            }
        });
    }

    public function userHas(int $user, string $name, int $mask, array $scope): bool
    {
        $query = $this->em->createQueryBuilder()->select('r.id')->from(ProfileRight::class, 'r')->join('r.profiles', 'profile')
            ->join(ProfileUser::class, 'grant', 'WITH', 'grant.profiles = profile')
            ->where('IDENTITY(grant.users) = :user AND r.name = :name AND BIT_AND(r.rights, :mask) <> 0')
            ->setParameter('user', $user, Types::INTEGER)->setParameter('name', $name)->setParameter('mask', $mask, Types::INTEGER);
        $compiler = (new RecordCriteria($query, $this->em->getClassMetadata(ProfileRight::class), false))
            ->withJoinedMetadata($this->em->getClassMetadata(ProfileUser::class), 'grant');
        return $query->andWhere($compiler->where($scope))->setMaxResults(1)->getQuery()->getScalarResult() !== [];
    }

    private function mutate(callable $operation): bool
    {
        try {
            $this->em->getConnection()->transactional($operation);
            return true;
        } catch (Exception) {
            return false;
        }
    }
}
