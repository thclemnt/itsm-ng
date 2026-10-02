<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\ProfileUser;
use itsmng\Database\Entity\User;
use itsmng\Database\Entity\UserEmail;
use itsmng\Database\Entity\GroupMembership;
use itsmng\Database\RecordCriteria;

final class UserRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function profiles(int $user): array
    {
        $rows = $this->em->createQueryBuilder()->select('DISTINCT p.id, p.name')->from(ProfileUser::class, 'a')
            ->join('a.profiles', 'p')->where('IDENTITY(a.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->orderBy('p.id')->getQuery()->getScalarResult();
        return array_column($rows, 'name', 'id');
    }

    /** Replacements are preferences only; this query never grants a profile. */
    public function defaultProfileReplacements(int $profile, int $replacement): array
    {
        return $this->em->createQueryBuilder()->select('DISTINCT u.id, IDENTITY(a.profiles) AS profiles_id')->from(User::class, 'u')
            ->leftJoin(ProfileUser::class, 'a', 'WITH', 'a.users = u AND IDENTITY(a.profiles) = :replacement')
            ->where('IDENTITY(u.profiles) = :profile')->setParameter('profile', $profile, Types::INTEGER)
            ->setParameter('replacement', $replacement === $profile ? 0 : $replacement, Types::INTEGER)
            ->orderBy('u.id')->getQuery()->getScalarResult();
    }

    public function emails(int $user): array
    {
        return $this->em->createQueryBuilder()->select('e.id, e.is_default, e.email, e.is_dynamic, IDENTITY(e.users) AS users_id')->from(UserEmail::class, 'e')
            ->where('IDENTITY(e.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->orderBy('e.id')->getQuery()->getScalarResult();
    }

    public function preferredByEmail(string $email): ?int
    {
        $row = $this->em->createQueryBuilder()->select('u.id')->from(UserEmail::class, 'e')->join('e.users', 'u')
            ->where('LOWER(e.email) = LOWER(:email)')->setParameter('email', $email)
            ->orderBy('u.is_active', 'DESC')->addOrderBy('u.is_deleted')->addOrderBy('u.id')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        return $row === null ? null : (int)$row['id'];
    }

    /** Fetch at most two IDs so ambiguity never resolves to an arbitrary account. */
    public function uniqueId(string $field, mixed $value, bool $legacyValues = false): ?int
    {
        $query = $this->em->createQueryBuilder()->select('r.id')->from(User::class, 'r');
        $criteria = new RecordCriteria($query, $this->em->getClassMetadata(User::class), $legacyValues);
        $query->where($criteria->where([$field => $value]))->setMaxResults(2);
        $rows = $query->getQuery()->getScalarResult();
        return count($rows) === 1 ? (int)$rows[0]['id'] : null;
    }

    public function exists(array $criteria, bool $legacyValues = false): bool
    {
        $query = $this->em->createQueryBuilder()->select('r.id')->from(User::class, 'r');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata(User::class), $legacyValues))->where($criteria));
        return $query->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }

    /** Directory imports accept either a login or any of the account's email addresses. */
    public function authenticationMatch(array $criteria): ?array
    {
        $query = $this->em->createQueryBuilder()->select('DISTINCT r.id, r.password, r.user_dn')->from(User::class, 'r')
            ->leftJoin(UserEmail::class, 'email', 'WITH', 'email.users = r');
        $compiler = (new RecordCriteria($query, $this->em->getClassMetadata(User::class)))
            ->withJoinedMetadata($this->em->getClassMetadata(UserEmail::class), 'email');
        $qualifyEmails = static function (array $conditions) use (&$qualifyEmails): array {
            $qualified = [];
            foreach ($conditions as $column => $value) {
                if ((is_int($column) || in_array($column, ['AND', 'OR', 'NOT'], true)) && is_array($value)) {
                    $value = $qualifyEmails($value);
                }
                $qualified[$column === 'email' ? 'glpi_useremails.email' : $column] = $value;
            }
            return $qualified;
        };
        return $query->where($compiler->where($qualifyEmails($criteria)))->orderBy('r.id')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function memberships(int $user): array
    {
        return $this->em->createQueryBuilder()->select('m.id, IDENTITY(m.groups) AS groups_id, m.is_dynamic')
            ->from(GroupMembership::class, 'm')->where('IDENTITY(m.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->orderBy('m.id')->getQuery()->getScalarResult();
    }

    /** Partial account deletion removes grants only in an authorized entity. */
    public function removeEntityGrants(int $user, int $entity): void
    {
        $this->em->createQueryBuilder()->delete(ProfileUser::class, 'p')
            ->where('IDENTITY(p.users) = :user AND IDENTITY(p.entities) = :entity')
            ->setParameter('user', $user, Types::INTEGER)->setParameter('entity', $entity, Types::INTEGER)->getQuery()->execute();
    }

    /** Authentication maintenance deliberately bypasses the external-directory update hooks. */
    public function clearPassword(string $login): void
    {
        $this->em->createQueryBuilder()->update(User::class, 'u')->set('u.password', ':empty')
            ->where('u.name = :login')->setParameter('empty', '')->setParameter('login', $login)->getQuery()->execute();
    }

    public function changeAuthentication(array $users, int $type, int $server): void
    {
        if (!$users) {
            return;
        }
        $values = (new User())->normalizeInput(['authtype' => $type, 'auths_id' => $server]);
        $query = $this->em->createQueryBuilder()->update(User::class, 'u')
            ->set('u.authtype', ':type')->set('u.password', ':empty')->set('u.is_deleted_ldap', ':no')
            ->where('u.id IN (:users)')->setParameter('users', array_values(array_map('intval', $users)))
            ->setParameter('type', $type, Types::INTEGER)->setParameter('empty', '')->setParameter('no', false, Types::BOOLEAN);
        $metadata = $this->em->getClassMetadata(User::class);
        foreach ($metadata->associationMappings as $field => $mapping) {
            $column = $mapping->joinColumns[0]->name;
            if (array_key_exists($column, $values)) {
                $query->set('u.' . $field, ':' . $field)->setParameter($field, $values[$column] === null ? null : $this->em->getReference($mapping->targetEntity, $values[$column]));
            }
        }
        $query->set('u.auth_source_code', ':code')->setParameter('code', $values['auth_source_code'], Types::INTEGER)->getQuery()->execute();
    }

    /** Purge/replacement changes the selected source without connecting to a remote server. */
    public function reassignMailServer(int $server, int $replacement): void
    {
        $this->em->createQueryBuilder()->update(User::class, 'u')->set('u.authmail', ':replacement')
            ->where('IDENTITY(u.authmail) = :server')->setParameter('server', $server, Types::INTEGER)
            ->setParameter('replacement', $replacement > 0 ? $this->em->getReference(\itsmng\Database\Entity\AuthMail::class, $replacement) : null)
            ->getQuery()->execute();
    }

    public function defaultPasswordCandidates(array $logins): array
    {
        if (!$logins) {
            return [];
        }
        return $this->em->createQueryBuilder()->select('u.name, u.password')->from(User::class, 'u')
            ->where('u.is_active = :yes AND u.is_deleted = :no AND LOWER(u.name) IN (:logins)')
            ->setParameter('yes', true, Types::BOOLEAN)->setParameter('no', false, Types::BOOLEAN)
            ->setParameter('logins', array_values(array_map('strtolower', $logins)))->orderBy('u.id')->getQuery()->getScalarResult();
    }
}
