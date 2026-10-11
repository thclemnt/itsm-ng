<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use itsmng\Database\BooleanValue;
use itsmng\Database\Entity\Domain;
use itsmng\Database\Entity\DomainRecord;
use itsmng\Database\Entity\Entity as EntityRecord;
use itsmng\Database\Entity\Supplier;
use itsmng\Database\LegacyValues;
use itsmng\Database\RecordCriteria;
use itsmng\Database\ReferenceValues;

final class DomainRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Validate the effective public assignment without changing a managed record. */
    public function assertCommercialSupplierAssignment(array $input, ?int $id = null): void
    {
        $metadata = $this->em->getClassMetadata(Domain::class);
        foreach (['entities_id', 'suppliers_id'] as $column) {
            if (array_key_exists($column, $input)) {
                $input[$column] = LegacyValues::decode($input[$column]);
            }
        }
        $input = ReferenceValues::normalizeLegacy($metadata->getTableName(), $input);
        if ($id !== null) {
            $stored = $this->em->find(Domain::class, $id);
            if ($stored === null) {
                throw new InvalidArgumentException('Domain commercial supplier assignment requires an existing Domain.');
            }
            $candidate = clone $stored;
        } else {
            $candidate = new Domain();
            $ownerDefault = $metadata->getAssociationMapping('entities')->joinColumns[0]->options['default'];
            $candidate->entities = $this->em->find(EntityRecord::class, $ownerDefault);
        }
        if (array_key_exists('entities_id', $input)) {
            $candidate->entities = $this->em->find(EntityRecord::class, self::identifier($input['entities_id'], true));
        }
        if ($candidate->entities === null) {
            throw new InvalidArgumentException('Domain commercial supplier assignment requires a valid Domain owner.');
        }
        if (array_key_exists('suppliers_id', $input)) {
            $candidate->suppliers = $input['suppliers_id'] === null ? null
                : $this->em->find(Supplier::class, self::identifier($input['suppliers_id']));
            if ($input['suppliers_id'] !== null && $candidate->suppliers === null) {
                throw new InvalidArgumentException('Domain commercial supplier does not exist.');
            }
        }
        $this->assertSupplierBoolean($candidate->suppliers);
        $candidate->assertCommercialSupplierOwnership();
    }

    /** Validate a proposed Supplier scope without changing the managed owner. */
    public function assertSupplierScopeAssignment(array $input, int $id): void
    {
        $stored = $this->em->find(Supplier::class, $id);
        if ($stored === null) {
            throw new InvalidArgumentException('Commercial Domain ownership requires an existing Supplier.');
        }
        $candidate = clone $stored;
        $table = $this->em->getClassMetadata(Supplier::class)->getTableName();
        $input = BooleanValue::normalizeLegacyInput($table, $input);
        if (array_key_exists('entities_id', $input)) {
            $owner = self::identifier(LegacyValues::decode($input['entities_id']), true);
            $candidate->entities = $this->em->find(EntityRecord::class, $owner);
            if ($candidate->entities === null) {
                throw new InvalidArgumentException('Commercial Domain ownership requires a valid Supplier owner.');
            }
        }
        if (array_key_exists('is_recursive', $input)) {
            $candidate->is_recursive = (bool)$input['is_recursive'];
        }
        if ($candidate->entities !== $stored->entities || $candidate->is_recursive !== $stored->is_recursive) {
            $this->assertSupplierDomains($candidate);
        }
    }

    /** Both directions of the association share the Domain's ownership rule. */
    public function assertSupplierDomains(Supplier $supplier): void
    {
        $work = $this->em->getUnitOfWork();
        // Query the owning association: another public model on this writer may
        // have added a Domain after this Supplier was loaded. Never rely on an
        // inverse collection snapshot or refresh away pending managed changes.
        $domains = $this->em->createQueryBuilder()
            ->select('d')
            ->from(Domain::class, 'd')
            ->where('IDENTITY(d.suppliers) = :supplier')
            ->setParameter('supplier', $supplier->id, Types::BIGINT)
            ->getQuery()->getResult();
        foreach ($domains as $domain) {
            if ($work->isScheduledForDelete($domain)
                || $domain->suppliers === null
                || $domain->suppliers->id !== $supplier->id) {
                continue;
            }
            // Respect pending Domain reassignments without mutating managed state.
            $candidate = clone $domain;
            $candidate->suppliers = $supplier;
            $candidate->assertCommercialSupplierOwnership();
        }
    }

    /** MySQL BOOLEAN storage must not reinterpret legacy 2 as a recursive grant. */
    public function assertSupplierBoolean(?Supplier $supplier): void
    {
        if ($supplier === null || $supplier->id === null || $this->em->getUnitOfWork()->isScheduledForInsert($supplier)) {
            return;
        }
        $valid = $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Supplier::class, 's')
            ->where('s.id = :id AND (s.is_recursive = :recursive OR s.is_recursive = :local)')
            ->setParameter('id', $supplier->id, Types::BIGINT)
            ->setParameter('recursive', true, Types::BOOLEAN)
            ->setParameter('local', false, Types::BOOLEAN)
            ->getQuery()->getSingleScalarResult();
        if ((int)$valid !== 1) {
            throw new InvalidArgumentException('Domain commercial supplier has a missing record or invalid recursive flag; repair its zero/one flag before assignment.');
        }
    }

    private static function identifier(mixed $value, bool $rootAllowed = false): int
    {
        if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < ($rootAllowed ? 0 : 1)) {
            throw new InvalidArgumentException('Domain commercial supplier assignment requires valid owner and supplier identifiers.');
        }
        return (int)$value;
    }

    private function supplierQuery(int $supplier, array $scope): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()
            ->from(Domain::class, 'r')
            ->where('IDENTITY(r.suppliers) = :supplier AND r.is_deleted = :deleted')
            ->setParameter('supplier', $supplier, Types::BIGINT)
            ->setParameter('deleted', false, Types::BOOLEAN);
        if ($scope) {
            $query
                ->andWhere((new RecordCriteria($query, $this->em->getClassMetadata(Domain::class)))
                    ->where($scope));
        }
        return $query;
    }

    public function countForSupplier(int $supplier, array $scope): int
    {
        return (int)$this->supplierQuery($supplier, $scope)
            ->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
    }

    /** Active domains owned by this supplier within the caller's recursive entity scope. */
    public function forSupplier(int $supplier, array $scope): array
    {
        $query = $this->supplierQuery($supplier, $scope)
            ->select(
                'r',
                'e.completename AS entity_name',
                't.name AS type_name',
                'g.name AS group_name',
                'u.name AS technician_name'
            )
            ->join('r.entities', 'e')
            ->leftJoin('r.domaintypes', 't')
            ->leftJoin('r.groups_tech', 'g')
            ->leftJoin('r.users_tech', 'u');
        $query
            ->addSelect('CASE WHEN r.name IS NULL THEN 0 ELSE 1 END AS HIDDEN named')
            ->orderBy('named')
            ->addOrderBy('r.name')
            ->addOrderBy('r.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->getResult() as $result) {
            $rows[] = $records->toRow($result[0]) + [
                'entity_name' => $result['entity_name'],
                'type_name' => $result['type_name'],
                'group_name' => $result['group_name'],
                'technician_name' => $result['technician_name']
            ];
        }
        return $rows;
    }

    /** Domain record view ordered by record type, then record name. */
    public function records(int $domain): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('r')
            ->from(DomainRecord::class, 'r')
            ->leftJoin('r.domainrecordtypes', 't')
            ->where('r.domains = :domain')
            ->setParameter('domain', $domain, Types::INTEGER)
            ->addSelect('CASE WHEN t.name IS NULL THEN 0 ELSE 1 END AS HIDDEN type_order')
            ->addSelect('CASE WHEN r.name IS NULL THEN 0 ELSE 1 END AS HIDDEN name_order')
            ->orderBy('type_order')
            ->addOrderBy('t.name')
            ->addOrderBy('name_order')
            ->addOrderBy('r.name')
            ->addOrderBy('r.id');
        return (new RecordRepository($this->em))->toRows($query->getQuery()->toIterable());
    }
}
