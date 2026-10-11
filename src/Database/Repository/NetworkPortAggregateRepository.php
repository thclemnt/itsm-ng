<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use itsmng\Database\Entity\NetworkPort;
use itsmng\Database\Entity\NetworkPortAggregate;
use itsmng\Database\Entity\NetworkPortAggregateOrigin;
use itsmng\Database\Entity\NetworkPortAlias;

final class NetworkPortAggregateRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public static function origins(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value <= 0) {
                throw new InvalidArgumentException('Aggregate origins require positive port IDs');
            }
            $ids[(int)$value] = (int)$value;
        }
        return array_values($ids);
    }

    public function originIds(int $aggregate): array
    {
        return array_map('intval', array_column($this->em->createQueryBuilder()
            ->select('IDENTITY(o.port) AS port')
            ->from(NetworkPortAggregateOrigin::class, 'o')
            ->where('o.aggregate = :aggregate')
            ->setParameter('aggregate', $aggregate, Types::INTEGER)
            ->orderBy('o.position')
            ->addOrderBy('o.id')
            ->getQuery()
            ->getScalarResult(), 'port'));
    }

    public function replaceOrigins(int $aggregate, array $values): void
    {
        $ids = self::origins($values);
        $this->em->getConnection()->transactional(function () use ($aggregate, $ids): void {
            $board = $this->em->find(NetworkPortAggregate::class, $aggregate);
            if ($board === null) {
                throw new InvalidArgumentException('Unknown aggregate');
            }
            // Serialize edits to one ordered membership set.
            $this->em->lock($board, LockMode::PESSIMISTIC_WRITE);
            // Only existence is needed. Keep batches bounded and diagnose the
            // first missing origin in selection order before changing membership.
            foreach (array_chunk($ids, 1000) as $batch) {
                $existing = array_fill_keys($this->em->createQueryBuilder()
                    ->select('p.id')
                    ->from(NetworkPort::class, 'p')
                    ->where('p.id IN (:origins)')
                    ->setParameter('origins', $batch, ArrayParameterType::INTEGER)
                    ->getQuery()
                    ->getSingleColumnResult(), true);
                foreach ($batch as $id) {
                    if (!isset($existing[$id])) {
                        throw new InvalidArgumentException('Unknown aggregate origin: ' . $id);
                    }
                }
            }
            $this->removeForAggregate($aggregate);
            foreach ($ids as $position => $id) {
                $origin = new NetworkPortAggregateOrigin();
                $origin->aggregate = $board;
                $origin->port = $this->em->getReference(NetworkPort::class, $id);
                $origin->position = $position;
                $this->em->persist($origin);
            }
            $this->em->flush();
        });
    }

    public function removeForAggregate(int $aggregate): void
    {
        $this->em->createQueryBuilder()
            ->delete(NetworkPortAggregateOrigin::class, 'o')
            ->where('o.aggregate = :id')
            ->setParameter('id', $aggregate, Types::INTEGER)
            ->getQuery()
            ->execute();
    }

    public function removeForPort(int $port): void
    {
        $this->em->createQueryBuilder()
            ->delete(NetworkPortAggregateOrigin::class, 'o')
            ->where('o.port = :id')
            ->setParameter('id', $port, Types::INTEGER)
            ->getQuery()
            ->execute();
    }

    public function replacePort(int $source, int $destination): void
    {
        if ($source === $destination) {
            return;
        }
        $this->em->getConnection()->transactional(function () use ($source, $destination): void {
            if ($this->em->find(NetworkPort::class, $destination) === null) {
                throw new InvalidArgumentException('Unknown replacement origin port');
            }
            $aggregates = $this->em->createQueryBuilder()
                ->select('IDENTITY(o.aggregate) AS id')
                ->from(NetworkPortAggregateOrigin::class, 'o')
                ->where('o.port = :port')
                ->setParameter('port', $source, Types::INTEGER)
                ->orderBy('o.aggregate')
                ->getQuery()
                ->getScalarResult();
            foreach ($aggregates as $aggregate) {
                $id = (int)$aggregate['id'];
                $board = $this->em->find(NetworkPortAggregate::class, $id);
                if ($board === null) {
                    continue;
                }
                $this->em->lock($board, LockMode::PESSIMISTIC_WRITE);
                $ids = array_map(static fn (int $port): int => $port === $source ? $destination : $port, $this->originIds($id));
                $this->replaceOrigins($id, $ids);
            }
        });
    }

    public function aggregatesForPort(int $port): array
    {
        return $this->em->createQueryBuilder()
            ->select('IDENTITY(a.networkports_id) AS id')
            ->from(NetworkPortAggregateOrigin::class, 'o')
            ->innerJoin('o.aggregate', 'a')
            ->where('o.port = :port')
            ->setParameter('port', $port, Types::INTEGER)
            ->orderBy('a.id')
            ->getQuery()
            ->getScalarResult();
    }

    public function virtualPorts(int $port): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(a.networkports_id) AS id')
            ->from(NetworkPortAlias::class, 'a')
            ->where('a.networkports_id_alias = :port')
            ->setParameter('port', $port, Types::INTEGER)
            ->getQuery()
            ->getScalarResult();
        $ids = [];
        foreach (array_merge($rows, $this->aggregatesForPort($port)) as $row) {
            $ids[(int)$row['id']] = ['networkports_id' => (int)$row['id']];
        }
        ksort($ids);
        return array_values($ids);
    }

    public function availablePorts(string $type, int $item, string $instantiation): array
    {
        return $this->em->createQueryBuilder()
            ->select('p.id, p.name, p.mac')
            ->from(NetworkPort::class, 'p')
            ->where('p.itemtype = :type AND p.items_id = :item AND p.instantiation_type = :instantiation')
            ->setParameter('type', $type)
            ->setParameter('item', $item, Types::INTEGER)
            ->setParameter('instantiation', $instantiation)
            ->orderBy('p.logical_number')
            ->addOrderBy('p.name')
            ->addOrderBy('p.id')
            ->getQuery()
            ->getScalarResult();
    }
}
