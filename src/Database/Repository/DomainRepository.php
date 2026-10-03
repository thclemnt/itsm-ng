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

    /** Validate the effective public assignment without changing a managed record. */
    public function assertCommercialSupplierAssignment(array $input, ?int $id = null): void
    {
        $metadata = $this->em->getClassMetadata(Entity\Domain::class);
        foreach (['entities_id', 'suppliers_id'] as $column) {
            if (array_key_exists($column, $input)) {
                $input[$column] = \itsmng\Database\LegacyValues::decode($input[$column]);
            }
        }
        $input = \itsmng\Database\ReferenceValues::normalizeLegacy($metadata->getTableName(), $input);
        if ($id !== null) {
            $stored = $this->em->find(Entity\Domain::class, $id);
            if ($stored === null) {
                throw new \InvalidArgumentException('Domain commercial supplier assignment requires an existing Domain.');
            }
            $candidate = clone $stored;
        } else {
            $candidate = new Entity\Domain();
            $ownerDefault = $metadata->getAssociationMapping('entities')->joinColumns[0]->options['default'];
            $candidate->entities = $this->em->find(Entity\Entity::class, $ownerDefault);
        }
        if (array_key_exists('entities_id', $input)) {
            $candidate->entities = $this->em->find(Entity\Entity::class, self::identifier($input['entities_id'], true));
        }
        if ($candidate->entities === null) {
            throw new \InvalidArgumentException('Domain commercial supplier assignment requires a valid Domain owner.');
        }
        if (array_key_exists('suppliers_id', $input)) {
            $candidate->suppliers = $input['suppliers_id'] === null ? null
                : $this->em->find(Entity\Supplier::class, self::identifier($input['suppliers_id']));
            if ($input['suppliers_id'] !== null && $candidate->suppliers === null) {
                throw new \InvalidArgumentException('Domain commercial supplier does not exist.');
            }
        }
        $this->assertSupplierBoolean($candidate->suppliers);
        $candidate->assertCommercialSupplierOwnership();
    }

    /** MySQL BOOLEAN storage must not reinterpret legacy 2 as a recursive grant. */
    public function assertSupplierBoolean(?Entity\Supplier $supplier): void
    {
        if ($supplier === null || $supplier->id === null || $this->em->getUnitOfWork()->isScheduledForInsert($supplier)) {
            return;
        }
        $valid = $this->em->createQueryBuilder()->select('COUNT(s.id)')->from(Entity\Supplier::class, 's')
            ->where('s.id = :id AND (s.is_recursive = :recursive OR s.is_recursive = :local)')
            ->setParameter('id', $supplier->id, Types::BIGINT)
            ->setParameter('recursive', true, Types::BOOLEAN)->setParameter('local', false, Types::BOOLEAN)
            ->getQuery()->getSingleScalarResult();
        if ((int)$valid !== 1) {
            throw new \InvalidArgumentException('Domain commercial supplier has a missing record or invalid recursive flag; repair its zero/one flag before assignment.');
        }
    }

    private static function identifier(mixed $value, bool $rootAllowed = false): int
    {
        if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < ($rootAllowed ? 0 : 1)) {
            throw new \InvalidArgumentException('Domain commercial supplier assignment requires valid owner and supplier identifiers.');
        }
        return (int)$value;
    }

    private function supplierQuery(int $supplier, array $scope): \Doctrine\ORM\QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->from(Entity\Domain::class, 'r')
            ->where('IDENTITY(r.suppliers) = :supplier AND r.is_deleted = :deleted')
            ->setParameter('supplier', $supplier, Types::BIGINT)->setParameter('deleted', false, Types::BOOLEAN);
        if ($scope) {
            $query->andWhere((new \itsmng\Database\RecordCriteria($query, $this->em->getClassMetadata(Entity\Domain::class)))->where($scope));
        }
        return $query;
    }

    public function countForSupplier(int $supplier, array $scope): int
    {
        return (int)$this->supplierQuery($supplier, $scope)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
    }

    /** Active domains owned by this supplier within the caller's recursive entity scope. */
    public function forSupplier(int $supplier, array $scope): array
    {
        $query = $this->supplierQuery($supplier, $scope)
            ->select('r', 'e.completename AS entity_name', 't.name AS type_name', 'g.name AS group_name', 'u.name AS technician_name')
            ->join('r.entities', 'e')->leftJoin('r.domaintypes', 't')
            ->leftJoin('r.groups_tech', 'g')->leftJoin('r.users_tech', 'u');
        $query->addSelect('CASE WHEN r.name IS NULL THEN 0 ELSE 1 END AS HIDDEN named')
            ->orderBy('named')->addOrderBy('r.name')->addOrderBy('r.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->getResult() as $result) {
            $rows[] = $records->toRow($result[0]) + ['entity_name' => $result['entity_name'], 'type_name' => $result['type_name'], 'group_name' => $result['group_name'], 'technician_name' => $result['technician_name']];
            $this->em->detach($result[0]);
        }
        return $rows;
    }

    /** Domain record view ordered by record type, then record name. */
    public function records(int $domain): array
    {
        $query = $this->em->createQueryBuilder()->select('r')->from(Entity\DomainRecord::class, 'r')
            ->leftJoin('r.domainrecordtypes', 't')
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
