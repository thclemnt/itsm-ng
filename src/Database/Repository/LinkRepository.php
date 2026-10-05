<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Database\RecordCriteria;

/** External-link definitions, scopes and the local inventory values used by their tags. */
final class LinkRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    private function visible(string $type, array $scope): QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->from(Entity\Link::class, 'r')
            ->where('EXISTS (SELECT binding.id FROM ' . Entity\LinkItemtype::class . ' binding WHERE IDENTITY(binding.links) = r.id AND binding.itemtype = :itemtype)')
            ->setParameter('itemtype', $type);
        return $query->andWhere((new RecordCriteria($query, $this->em->getClassMetadata(Entity\Link::class)))->where($scope));
    }

    public function forItem(string $type, array $scope): array
    {
        $rows = $this->visible($type, $scope)->select('r.id, r.name, r.link, r.data, r.open_window')
            ->orderBy('r.name')->addOrderBy('r.id')->getQuery()->getArrayResult();
        foreach ($rows as &$row) {
            $row['id'] = RecordRepository::legacyScalarValue($row['id'], Types::BIGINT);
            $row['open_window'] = (int)$row['open_window'];
        }
        return $rows;
    }

    public function countForItem(string $type, array $scope): int
    {
        return (int)$this->visible($type, $scope)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
    }

    public function itemtypes(int $link): array
    {
        return $this->em->createQueryBuilder()->select('b.id', 'IDENTITY(b.links) AS links_id', 'b.itemtype')
            ->from(Entity\LinkItemtype::class, 'b')->where('IDENTITY(b.links) = :link')->setParameter('link', $link, Types::INTEGER)
            ->orderBy('b.itemtype')->addOrderBy('b.id')->getQuery()->getScalarResult();
    }

    public function countItemtypes(int $link): int
    {
        return (int)$this->em->createQueryBuilder()->select('COUNT(b.id)')->from(Entity\LinkItemtype::class, 'b')
            ->where('IDENTITY(b.links) = :link')->setParameter('link', $link, Types::INTEGER)->getQuery()->getSingleScalarResult();
    }

    public function deletePluginItemtypes(string $plugin): int
    {
        return $this->em->createQueryBuilder()->delete(Entity\LinkItemtype::class, 'b')->where('b.itemtype LIKE :pattern')
            ->setParameter('pattern', '%Plugin' . $plugin . '%')->getQuery()->execute();
    }

    public function domainName(string $type, int $item): ?string
    {
        try {
            $association = Entity\DomainItem::referenceAssociation($type);
        } catch (\InvalidArgumentException) {
            return null;
        }
        $row = $this->em->createQueryBuilder()->select('d.name')->from(Entity\DomainItem::class, 'binding')->join('binding.domains', 'd')
            ->where('IDENTITY(binding.' . $association . ') = :item')->setParameter('item', $item, Types::BIGINT)
            ->orderBy('d.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        return $row === null ? null : (string)$row['name'];
    }

    public function equipmentAddresses(int $item): array
    {
        return $this->em->createQueryBuilder()->select('address.id', 'address.name AS ip')->from(Entity\NetworkName::class, 'n')
            ->join(Entity\IPAddress::class, 'address', 'WITH', 'address.items_id = n.id AND address.itemtype = :addressType')
            ->where('n.items_id = :item AND n.itemtype = :type')->setParameter('item', $item, Types::INTEGER)
            ->setParameter('type', 'NetworkEquipment')->setParameter('addressType', 'NetworkName')
            ->orderBy('address.id')->getQuery()->getScalarResult();
    }

    public function portAddresses(string $type, int $item): array
    {
        return $this->em->createQueryBuilder()->select('address.id', 'address.name AS ip', 'p.mac')->from(Entity\NetworkPort::class, 'p')
            ->join(Entity\NetworkName::class, 'n', 'WITH', 'n.items_id = p.id AND n.itemtype = :nameType')
            ->join(Entity\IPAddress::class, 'address', 'WITH', 'address.items_id = n.id AND address.itemtype = :addressType')
            ->where('p.items_id = :item AND p.itemtype = :type')->setParameter('item', $item, Types::INTEGER)
            ->setParameter('type', $type)->setParameter('nameType', 'NetworkPort')->setParameter('addressType', 'NetworkName')
            ->orderBy('address.id')->getQuery()->getScalarResult();
    }

    public function portMacs(string $type, int $item, bool $withoutNetworkName): array
    {
        $query = $this->em->createQueryBuilder()->select('MIN(p.id) AS id', 'p.mac')->from(Entity\NetworkPort::class, 'p')
            ->where('p.items_id = :item AND p.itemtype = :type')->setParameter('item', $item, Types::INTEGER)->setParameter('type', $type);
        if ($withoutNetworkName) {
            $query->andWhere('NOT EXISTS (SELECT n.id FROM ' . Entity\NetworkName::class . ' n WHERE n.items_id = p.id AND n.itemtype = :nameType)')
                ->setParameter('nameType', 'NetworkPort');
        }
        return $query->groupBy('p.mac')->orderBy('id')->getQuery()->getScalarResult();
    }
}
