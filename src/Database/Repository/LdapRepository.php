<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Auth;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Database\RecordCriteria;

/** Local directory configuration and synchronization candidates, without LDAP network calls. */
final class LdapRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function replicas(int $master, bool $byName = false): array
    {
        $query = $this->em->createQueryBuilder()->select('r')->from(Entity\AuthLdapReplicate::class, 'r')
            ->where('IDENTITY(r.authldaps) = :master')->setParameter('master', $master, Types::INTEGER);
        if ($byName) {
            $query->orderBy('r.name');
        }
        return $this->rows($query->addOrderBy('r.id'));
    }

    public function directories(bool $activeOnly = false): array
    {
        $query = $this->em->createQueryBuilder()->select('d')->from(Entity\AuthLDAP::class, 'd');
        if ($activeOnly) {
            $query->where('d.is_active = :active')->setParameter('active', true, Types::BOOLEAN)->orderBy('d.name');
        } else {
            $query->orderBy('d.is_default', 'DESC');
        }
        return $this->rows($query->addOrderBy('d.id'));
    }

    public function activeCount(): int
    {
        return (int)$this->em->createQueryBuilder()->select('COUNT(d.id)')->from(Entity\AuthLDAP::class, 'd')
            ->where('d.is_active = :yes')->setParameter('yes', true, Types::BOOLEAN)->getQuery()->getSingleScalarResult();
    }

    public function isActive(int $id): bool
    {
        return $this->em->createQueryBuilder()->select('d.id')->from(Entity\AuthLDAP::class, 'd')
            ->where('d.id = :id AND d.is_active = :yes')->setParameter('id', $id, Types::INTEGER)
            ->setParameter('yes', true, Types::BOOLEAN)->getQuery()->getOneOrNullResult() !== null;
    }

    public function defaultId(): int
    {
        $row = $this->em->createQueryBuilder()->select('d.id')->from(Entity\AuthLDAP::class, 'd')
            ->where('d.is_active = :yes AND d.is_default = :yes')->setParameter('yes', true, Types::BOOLEAN)
            ->orderBy('d.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        return (int)($row['id'] ?? 0);
    }

    /** Called after the selected directory has been persisted by its model lifecycle. */
    public function clearOtherDefaults(int $selected): void
    {
        $this->em->createQueryBuilder()->update(Entity\AuthLDAP::class, 'd')->set('d.is_default', ':no')
            ->where('d.id <> :selected')->setParameter('selected', $selected, Types::INTEGER)
            ->setParameter('no', false, Types::BOOLEAN)->getQuery()->execute();
    }

    public function emailImportDirectoryIds(): array
    {
        $rows = $this->em->createQueryBuilder()->select('d.id')->from(Entity\AuthLDAP::class, 'd')
            ->where('d.is_active = :yes')->andWhere("d.email1_field <> '' OR d.email2_field <> '' OR d.email3_field <> '' OR d.email4_field <> ''")
            ->setParameter('yes', true, Types::BOOLEAN)->orderBy('d.is_default', 'DESC')->addOrderBy('d.id')
            ->getQuery()->getScalarResult();
        return array_column($rows, 'id');
    }

    public function knownServerIds(string $login): array
    {
        return array_column($this->em->createQueryBuilder()->select('DISTINCT u.auths_id')->from(Entity\User::class, 'u')
            ->where('u.name = :login')->setParameter('login', $login)->getQuery()->getScalarResult(), 'auths_id');
    }

    /** Import compares all logins; synchronization considers the requested authentication source. */
    public function userCandidates(?int $server, string $order): iterable
    {
        $query = $this->em->createQueryBuilder()->select('u')->from(Entity\User::class, 'u')
            ->orderBy('u.name', strtoupper($order) === 'DESC' ? 'DESC' : 'ASC')->addOrderBy('u.id');
        if ($server !== null) {
            $query->where('u.auths_id = :server AND u.authtype IN (:types)')->setParameter('server', $server, Types::INTEGER)
                ->setParameter('types', [-1, Auth::NOT_YET_AUTHENTIFIED, Auth::LDAP, Auth::EXTERNAL, Auth::CAS]);
        }
        $records = new RecordRepository($this->em);
        foreach ($query->getQuery()->toIterable() as $user) {
            $row = $records->toRow($user);
            $this->em->detach($user);
            yield $row;
        }
    }

    /** Source maintenance must not replay user hooks that connect to the directory being deleted. */
    public function reassignUsers(int $server, int $replacement): void
    {
        $this->em->getConnection()->transactional(function () use ($server, $replacement): void {
            $this->em->createQueryBuilder()->update(Entity\User::class, 'u')->set('u.authldap', ':replacement')
                ->where('IDENTITY(u.authldap) = :server')->setParameter('server', $server, Types::INTEGER)
                ->setParameter('replacement', $replacement > 0 ? $this->em->getReference(Entity\AuthLDAP::class, $replacement) : null)
                ->getQuery()->execute();
            // Older unclassified/API/cookie accounts carry an opaque source code; preserve their cleanup behavior.
            $this->em->createQueryBuilder()->update(Entity\User::class, 'u')->set('u.auth_source_code', ':replacement')
                ->where('u.auth_source_code = :server AND u.authtype IN (:types)')->setParameter('server', $server, Types::INTEGER)
                ->setParameter('replacement', $replacement, Types::INTEGER)
                ->setParameter('types', [-1, Auth::API, Auth::COOKIE])->getQuery()->execute();
        });
    }

    public function groupIdentifiers(array $entityScope): array
    {
        $query = $this->em->createQueryBuilder()->select('r.ldap_group_dn', 'r.ldap_value')->from(Entity\Group::class, 'r');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata(Entity\Group::class)))->where($entityScope));
        return $query->getQuery()->getScalarResult();
    }

    public function groupValuesForDns(array $dns): array
    {
        if (!$dns) {
            return [];
        }
        return $this->em->createQueryBuilder()->select('g.ldap_value')->from(Entity\Group::class, 'g')
            ->where('g.ldap_group_dn IN (:dns)')->setParameter('dns', array_values($dns))->orderBy('g.id')
            ->getQuery()->getScalarResult();
    }

    public function usesSyncField(int $server): bool
    {
        return $this->em->createQueryBuilder()->select('u.id')->from(Entity\User::class, 'u')
            ->where('u.auths_id = :server AND u.sync_field IS NOT NULL')->setParameter('server', $server, Types::INTEGER)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }

    public function membershipFields(): array
    {
        return array_column($this->em->createQueryBuilder()->select('DISTINCT g.ldap_field')->from(Entity\Group::class, 'g')
            ->where("g.ldap_field <> ''")->orderBy('g.ldap_field')->getQuery()->getScalarResult(), 'ldap_field');
    }

    public function groupsForDns(array $dns): array
    {
        if (!$dns) {
            return [];
        }
        return array_column($this->em->createQueryBuilder()->select('g.id')->from(Entity\Group::class, 'g')
            ->where('g.ldap_group_dn IN (:dns)')->setParameter('dns', array_values($dns))->orderBy('g.id')
            ->getQuery()->getScalarResult(), 'id');
    }

    /** Directory values are subjects; the stored ldap_value supplies the LIKE pattern. */
    public function groupsForAttribute(string $field, array $values): array
    {
        if (!$values) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('g.id')->from(Entity\Group::class, 'g')
            ->where('LOWER(g.ldap_field) = LOWER(:field)')->setParameter('field', $field);
        $matches = [];
        foreach (array_values($values) as $index => $value) {
            $matches[] = ':value' . $index . ' LIKE g.ldap_value';
            $query->setParameter('value' . $index, $value);
        }
        return array_column($query->andWhere('(' . implode(' OR ', $matches) . ')')->orderBy('g.id')
            ->getQuery()->getScalarResult(), 'id');
    }

    private function rows(QueryBuilder $query): array
    {
        return (new RecordRepository($this->em))->toRows($query->getQuery()->toIterable());
    }
}
