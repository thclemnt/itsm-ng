<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;

final class DomainRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Domain record view ordered by record type, then record name. */
    public function records(int $domain): array
    {
        $query = $this->em->createQueryBuilder()->select('r')->from(Entity\DomainRecord::class, 'r')
            ->leftJoin(Entity\DomainRecordType::class, 't', 'WITH', 't.id = r.domainrecordtypes_id')
            ->where('r.domains = :domain')->setParameter('domain', $domain, Types::INTEGER)
            ->addSelect('CASE WHEN t.name IS NULL THEN 0 ELSE 1 END AS HIDDEN type_order')
            ->addSelect('CASE WHEN r.name IS NULL THEN 0 ELSE 1 END AS HIDDEN name_order')
            ->orderBy('type_order')->addOrderBy('t.name')->addOrderBy('name_order')->addOrderBy('r.name')->addOrderBy('r.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
            $this->em->detach($record);
        }
        return $rows;
    }
}
