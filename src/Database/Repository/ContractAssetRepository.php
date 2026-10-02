<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\ContractItem;
use itsmng\Database\RecordCriteria;

/** Contract lists count before loading rows and join the selected owning asset. */
final class ContractAssetRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function assets(int $contract, string $kind, array $criteria, string $name, int $limit, ?string $componentColumn = null): array
    {
        try {
            $association = ContractItem::referenceAssociation($kind);
        } catch (\InvalidArgumentException) {
            return ['count' => 0, 'rows' => []];
        }
        $class = $this->em->getClassMetadata(ContractItem::class)->getAssociationTargetClass($association);
        $metadata = $this->em->getClassMetadata($class);
        $query = $this->em->createQueryBuilder()->select('COUNT(r.id)')->from($class, 'r')
            ->join(ContractItem::class, 'l', 'WITH', 'l.' . $association . ' = r')
            ->where('IDENTITY(l.contracts) = :contract')->setParameter('contract', $contract, Types::BIGINT);
        if ($criteria) {
            $query->andWhere((new RecordCriteria($query, $metadata))->where($criteria));
        }
        $count = (int)$query->getQuery()->getSingleScalarResult();
        if ($count === 0 || $count > $limit) {
            return ['count' => $count, 'rows' => []];
        }
        $query->select('r, l.id AS linkid, IDENTITY(r.entities) AS entity')->join('r.entities', 'e')->orderBy('e.completename');
        if ($componentColumn !== null) {
            $component = null;
            foreach ($metadata->associationMappings as $property => $mapping) {
                if ($mapping->isToOneOwningSide() && $mapping->joinColumns[0]->name === $componentColumn) {
                    $component = $property;
                    break;
                }
            }
            if ($component === null) {
                throw new \InvalidArgumentException('Installed component requires its owning definition association');
            }
            $query->leftJoin('r.' . $component, 'd')->addSelect('d.designation AS name_device')->addOrderBy('d.designation');
        } else {
            $query->addOrderBy('r.' . $metadata->getFieldName($name));
        }
        $query->addOrderBy('r.id')->setMaxResults($limit);
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->getResult() as $result) {
            $record = $result[0];
            unset($result[0]);
            $rows[] = $records->toRow($record) + $result;
            $this->em->detach($record);
        }
        return ['count' => $count, 'rows' => $rows];
    }
}
