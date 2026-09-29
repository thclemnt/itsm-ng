<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\Contact;
use itsmng\Database\Entity\ContactSupplier;
use itsmng\Database\Entity\Supplier;
use itsmng\Database\RecordCriteria;

final class ContactRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Address and website resolve the same supplier, even when several are linked. */
    public function companyDetails(int $contact): ?array
    {
        return $this->em->createQueryBuilder()->select('s.name', 's.address', 's.postcode', 's.town', 's.state', 's.country', 's.website')
            ->from(ContactSupplier::class, 'l')->innerJoin('l.suppliers', 's')
            ->where('IDENTITY(l.contacts) = :contact')->setParameter('contact', $contact, Types::INTEGER)
            ->orderBy('s.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function dropdown(array $criteria, bool $groupByEntity, int $limit, int $offset): array
    {
        $query = $this->em->createQueryBuilder()->select('r')->from(Contact::class, 'r');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata(Contact::class)))->where($criteria));
        if ($groupByEntity) {
            $query->orderBy('r.entities');
        }
        $query->addSelect('CASE WHEN r.name IS NULL THEN 0 ELSE 1 END AS HIDDEN name_null')
            ->addOrderBy('name_null')->addOrderBy('r.name')->addOrderBy('r.id')->setFirstResult(max(0, $offset));
        if ($limit > 0) {
            $query->setMaxResults($limit);
        }
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $record) {
            $row = $records->toRow($record);
            $row['name'] = ($row['name'] ?? '') . ' ' . ($row['firstname'] ?? '');
            $rows[] = $row;
            $this->em->detach($record);
        }
        return $rows;
    }

    /** The scope applies to the opposite endpoint, not to the viewed contact/supplier. */
    public function related(int $id, bool $forContact, ?array $scope): array
    {
        $query = $this->relationQuery($id, $forContact, $scope)->select('r', 'l.id AS linkid');
        if ($scope !== null) {
            $query->addSelect('e.id AS entity')->orderBy('e.completename');
        }
        $query->addSelect('CASE WHEN r.name IS NULL THEN 0 ELSE 1 END AS HIDDEN name_null')
            ->addOrderBy('name_null')->addOrderBy('r.name')->addOrderBy('r.id')->addOrderBy('l.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $record = $result[0];
            unset($result[0]);
            $rows[] = $records->toRow($record) + $result;
            $this->em->detach($record);
        }
        return $rows;
    }

    public function countRelated(int $id, bool $forContact, ?array $scope): int
    {
        return (int)$this->relationQuery($id, $forContact, $scope)->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
    }

    private function relationQuery(int $id, bool $forContact, ?array $scope): QueryBuilder
    {
        $target = $forContact ? Supplier::class : Contact::class;
        $query = $this->em->createQueryBuilder()->from($target, 'r')
            ->innerJoin(ContactSupplier::class, 'l', 'WITH', 'IDENTITY(l.' . ($forContact ? 'suppliers' : 'contacts') . ') = r.id')
            ->where('IDENTITY(l.' . ($forContact ? 'contacts' : 'suppliers') . ') = :item')
            ->setParameter('item', $id, Types::INTEGER);
        if ($scope !== null) {
            $query->innerJoin('r.entities', 'e')->andWhere((new RecordCriteria($query, $this->em->getClassMetadata($target)))->where($scope));
        }
        return $query;
    }
}
