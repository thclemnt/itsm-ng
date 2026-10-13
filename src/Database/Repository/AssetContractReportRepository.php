<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Reporting\Criteria;

use function getTableForItemType;

/** Year and contract reports share asset scope and optional financial projections. */
final class AssetContractReportRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public static function supports(string $itemtype): bool
    {
        return isset(EntityRegistry::tables()[getTableForItemType($itemtype)]);
    }

    public function rows(string $itemtype, array $years, ?array $entities, bool $underContract): array
    {
        $class = EntityRegistry::tables()[getTableForItemType($itemtype)];
        $metadata = $this->em->getClassMetadata($class);
        $query = $this->em->createQueryBuilder()->from($class, 'a')
            ->select('a.id AS itemid', 'ct.name AS type', 'c.duration', 'c.begin_date', 'e.completename AS entname', 'e.id AS entID')
            ->leftJoin('a.entities', 'e')->setParameter('itemtype', $itemtype);
        $join = $underContract ? 'innerJoin' : 'leftJoin';
        $query->$join(Entity\ContractItem::class, 'binding', 'WITH', 'binding.items_id = a.id AND binding.itemtype = :itemtype');
        $query->$join('binding.contracts', 'c')->leftJoin('c.contracttypes', 'ct');
        $query->addSelect($metadata->hasField('name') ? 'a.name AS itemname' : "'' AS itemname");

        // These two reports historically treat projects and licenses differently.
        $project = $itemtype === 'Project';
        $license = $itemtype === 'SoftwareLicense';
        $financial = !$underContract || !$project;
        if ($financial) {
            $query->leftJoin(Entity\Infocom::class, 'i', 'WITH', 'i.items_id = a.id AND i.itemtype = :itemtype')
                ->addSelect('i.buy_date', 'i.warranty_duration');
        }
        if (!$project && !$license && $metadata->hasAssociation('locations')) {
            $query->leftJoin('a.locations', 'l')->addSelect('l.completename AS location');
        } else {
            $query->addSelect("'' AS location");
        }
        $deleted = $metadata->hasField('is_deleted') ? 'a.is_deleted' : '0';
        if ($license) {
            if ($underContract) {
                $deleted = '0';
            } else {
                $query->leftJoin('a.softwares', 's')->andWhere('s.is_template = :template');
                $deleted = 's.is_deleted';
            }
        }
        $query->addSelect($deleted . ' AS itemdeleted');
        if (!$project && !($license && $underContract) && $metadata->hasField('is_template')) {
            $query->andWhere('a.is_template = :template')->setParameter('template', false, Types::BOOLEAN);
        }
        if ($entities !== null) {
            $query->andWhere('IDENTITY(a.entities) IN (:entities)')->setParameter('entities', $entities ?: [-1]);
        }
        $dates = [];
        foreach (Criteria::years($years) as $index => $year) {
            [$start, $end] = Criteria::yearBounds($year);
            foreach ($financial ? ['i.buy_date', 'c.begin_date'] : ['c.begin_date'] as $date) {
                $dates[] = '(' . $date . ' >= :start' . $index . ' AND ' . $date . ' < :end' . $index . ')';
            }
            $query->setParameter('start' . $index, new DateTimeImmutable($start), Types::DATE_IMMUTABLE)
                ->setParameter('end' . $index, new DateTimeImmutable($end), Types::DATE_IMMUTABLE);
        }
        if ($dates) {
            $query->andWhere('(' . implode(' OR ', $dates) . ')');
        }
        $query->orderBy('e.completename');
        if (!($license && $underContract)) {
            $query->addOrderBy('itemdeleted', 'DESC');
        }
        $query->addOrderBy('itemname')->addOrderBy('a.id')->addOrderBy('c.id');
        $rows = $query->getQuery()->getScalarResult();
        foreach ($rows as &$row) {
            foreach (['buy_date', 'begin_date'] as $date) {
                $value = $row[$date] ?? null;
                $row[$date] = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : $value;
            }
            $row['warranty_duration'] ??= null;
            $row['itemdeleted'] = (int)$row['itemdeleted'];
        }
        return $rows;
    }
}
