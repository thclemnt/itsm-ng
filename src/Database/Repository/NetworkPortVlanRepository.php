<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use UnexpectedValueException;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\NetworkPort;
use itsmng\Database\Entity\NetworkPortVlan;
use itsmng\Database\Entity\Vlan;
use itsmng\Database\MySQLConnection;

/** Membership identity and the two distinct endpoint projections belong to this aggregate. */
final class NetworkPortVlanRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** The relation ID remains separate from the displayed VLAN ID. */
    public function forPort(int $port): array
    {
        $memberships = $this->em->createQueryBuilder()->select('m', 'v')->from(NetworkPortVlan::class, 'm')
            ->leftJoin('m.vlans', 'v')->where('m.networkports = :port')->setParameter('port', $port, Types::BIGINT)
            ->orderBy('m.id')->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
        $rows = [];
        foreach ($memberships as $membership) {
            $rows[] = ['assocID' => $membership->id, 'tagged' => (int)$membership->tagged]
                + $this->endpointRow($membership->vlans, Vlan::class);
        }
        return $rows;
    }

    /** The relation ID remains separate from the displayed port ID. */
    public function forVlan(int $vlan): array
    {
        $memberships = $this->em->createQueryBuilder()->select('m', 'p')->from(NetworkPortVlan::class, 'm')
            ->leftJoin('m.networkports', 'p')->where('m.vlans = :vlan')->setParameter('vlan', $vlan, Types::BIGINT)
            ->orderBy('m.id')->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();
        $rows = [];
        foreach ($memberships as $membership) {
            $rows[] = ['assocID' => $membership->id, 'tagged' => (int)$membership->tagged]
                + $this->endpointRow($membership->networkports, NetworkPort::class);
        }
        return $rows;
    }

    /** Public compatibility cloning still invokes each membership's public add. */
    public function membershipsForPort(int $port): array
    {
        return $this->membershipQuery()->where('m.networkports = :port')->setParameter('port', $port, Types::BIGINT)
            ->orderBy('m.id')->getQuery()->getArrayResult();
    }

    public function countForPort(int $port): int
    {
        return (int)$this->em->createQueryBuilder()->select('COUNT(m.id)')->from(NetworkPortVlan::class, 'm')
            ->where('m.networkports = :port')->setParameter('port', $port, Types::BIGINT)->getQuery()->getSingleScalarResult();
    }

    public function countForVlan(int $vlan): int
    {
        return (int)$this->em->createQueryBuilder()->select('COUNT(m.id)')->from(NetworkPortVlan::class, 'm')
            ->where('m.vlans = :vlan')->setParameter('vlan', $vlan, Types::BIGINT)->getQuery()->getSingleScalarResult();
    }

    public function membership(int $id, bool $current = false): ?array
    {
        if ($current) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $rows = $this->membershipQuery()->where('m.id = :id')->setParameter('id', $id, Types::BIGINT)
            ->getQuery()->setLockMode($current ? LockMode::PESSIMISTIC_WRITE : LockMode::NONE)->getArrayResult();
        return $rows[0] ?? null;
    }

    public function selectedPair(int $port, int $vlan, bool $current = false): ?array
    {
        if ($current) {
            MySQLConnection::assertCurrentReads($this->em->getConnection());
        }
        $rows = $this->membershipQuery()->where('m.networkports = :port AND m.vlans = :vlan')
            ->setParameter('port', $port, Types::BIGINT)->setParameter('vlan', $vlan, Types::BIGINT)
            ->getQuery()->setLockMode($current ? LockMode::PESSIMISTIC_WRITE : LockMode::NONE)->getArrayResult();
        return $rows[0] ?? null;
    }

    /** Scalar locking reads never inherit managed endpoint state or lock nullable joins. */
    public function currentPort(int $id): ?array
    {
        MySQLConnection::assertCurrentReads($this->em->getConnection());
        $rows = $this->em->createQueryBuilder()->select('p.id, IDENTITY(p.entities) AS entity, p.is_recursive AS recursive, p.itemtype, p.items_id')
            ->from(NetworkPort::class, 'p')->where('p.id = :id')->setParameter('id', $id, Types::BIGINT)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_READ)->getArrayResult();
        return $rows[0] ?? null;
    }

    public function currentVlan(int $id): ?array
    {
        MySQLConnection::assertCurrentReads($this->em->getConnection());
        $rows = $this->em->createQueryBuilder()->select('v.id, IDENTITY(v.entities) AS entity, v.is_recursive AS recursive')
            ->from(Vlan::class, 'v')->where('v.id = :id')->setParameter('id', $id, Types::BIGINT)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_READ)->getArrayResult();
        return $rows[0] ?? null;
    }

    /** Entity ancestry follows its owning property; cyclic data is never accepted as availability. */
    public function containsEntity(int $ancestor, int $entity, ?callable $assertActive = null): bool
    {
        $seen = [];
        $found = false;
        while (!isset($seen[$entity])) {
            $found = $found || $entity === $ancestor;
            $seen[$entity] = true;
            if ($assertActive !== null) {
                $assertActive();
            }
            MySQLConnection::assertCurrentReads($this->em->getConnection());
            $rows = $this->em->createQueryBuilder()->select('e.id, IDENTITY(e.parent) AS parent')
                ->from(Entity::class, 'e')->where('e.id = :id')->setParameter('id', $entity, Types::BIGINT)
                ->getQuery()->setLockMode(LockMode::PESSIMISTIC_READ)->getArrayResult();
            if ($assertActive !== null) {
                $assertActive();
            }
            if (!$rows) {
                return false;
            }
            if ($rows[0]['parent'] === null) {
                return $found;
            }
            $entity = (int)$rows[0]['parent'];
        }
        throw new UnexpectedValueException('Cyclic VLAN membership entity ancestry.');
    }

    private function membershipQuery(): QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('m.id, IDENTITY(m.networkports) AS networkports_id, IDENTITY(m.vlans) AS vlans_id, m.tagged')
            ->from(NetworkPortVlan::class, 'm');
    }

    private function endpointRow(?object $endpoint, string $class): array
    {
        if ($endpoint !== null) {
            return (new RecordRepository($this->em))->toRow($endpoint);
        }
        $metadata = $this->em->getClassMetadata($class);
        $columns = $metadata->getColumnNames();
        foreach ($metadata->associationMappings as $association) {
            if ($association->isToOneOwningSide()) {
                foreach ($association->joinColumns as $column) {
                    $columns[] = $column->name;
                }
            }
        }
        return array_fill_keys($columns, null);
    }
}
