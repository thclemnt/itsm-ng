<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\ContractSupplier;
use itsmng\Database\Entity\Infocom;
use itsmng\Database\LegacyValues;

/** Completed ownership decisions; public transfer models perform the mutations. */
final class SupplierTransferRepository
{
    public function __construct(private EntityManager $manager)
    {
    }

    /** @param array<int|string, int|string|null> $selectedContracts */
    public function countOtherContractOwners(int $supplier, array $selectedContracts): int
    {
        $query = $this->manager->createQueryBuilder()
            ->select('COUNT(binding.id)')->from(ContractSupplier::class, 'binding')
            ->where('IDENTITY(binding.suppliers) = :supplier')
            ->setParameter('supplier', $supplier, Types::BIGINT);
        if ($selectedContracts !== []) {
            $parameters = [];
            foreach (array_values($selectedContracts) as $index => $contract) {
                $name = 'contract' . $index;
                $parameters[] = ':' . $name;
                $query->setParameter($name, LegacyValues::decode($contract), Types::BIGINT);
            }
            $query->andWhere('IDENTITY(binding.contracts) NOT IN (' . implode(', ', $parameters) . ')');
        }
        return (int)$query->getQuery()->getSingleScalarResult();
    }

    /**
     * Financial kinds retain their scalar identity, including plugin namespaces.
     *
     * @param array<int|string, int|string|null> $selectedItems
     */
    public function countOtherFinancialOwners(int $supplier, string $kind, array $selectedItems): int
    {
        $query = $this->manager->createQueryBuilder()
            ->select('COUNT(financial.id)')->from(Infocom::class, 'financial')
            ->where('IDENTITY(financial.suppliers) = :supplier AND financial.itemtype = :kind')
            ->setParameter('supplier', $supplier, Types::BIGINT)
            ->setParameter('kind', $kind, Types::STRING);
        if ($selectedItems !== []) {
            $parameters = [];
            foreach (array_values($selectedItems) as $index => $item) {
                $name = 'item' . $index;
                $parameters[] = ':' . $name;
                $query->setParameter($name, LegacyValues::decode($item), Types::BIGINT);
            }
            $query->andWhere('financial.items_id NOT IN (' . implode(', ', $parameters) . ')');
        }
        return (int)$query->getQuery()->getSingleScalarResult();
    }
}
