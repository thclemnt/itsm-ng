<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Alert;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Infocom;
use InvalidArgumentException;
use Item_Devices;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\RecordCriteria;

use function getTableForItemType;

final class InfocomRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Activation is a property of the exact item, independent of the viewer's financial rights. */
    public function isActivatedFor(string $itemtype, int $id): bool
    {
        return $this->em->createQueryBuilder()->select('i.id')->from(Entity\Infocom::class, 'i')
            ->where('i.itemtype = :type AND i.items_id = :id')
            ->setParameter('type', $itemtype, Types::STRING)->setParameter('id', $id, Types::BIGINT)
            ->setMaxResults(1)->getQuery()->getScalarResult() !== [];
    }

    /** Inclusive day cutoff and exact prior end-of-warranty events within one entity. */
    public function warrantiesExpiring(int $entity, int $days, ?DateTimeImmutable $today = null): array
    {
        $date = $today === null ? 'CURRENT_DATE()' : ':today';
        $query = $this->em->createQueryBuilder()->select('i')->from(Entity\Infocom::class, 'i')
            ->leftJoin(Entity\Alert::class, 'a', 'WITH', 'a.infocom = i AND a.type = :end')
            ->where('IDENTITY(i.entities) = :entity AND BIT_AND(i.alert, :mask) > 0')
            ->andWhere('i.warranty_duration > 0 AND i.warranty_date IS NOT NULL AND a.id IS NULL')
            ->andWhere("DATE_DIFF(DATE_ADD(i.warranty_date, i.warranty_duration, 'month'), " . $date . ') <= :days')
            ->setParameter('entity', $entity, Types::BIGINT)
            ->setParameter('end', Alert::END, Types::INTEGER)
            ->setParameter('mask', 1 << Alert::END, Types::INTEGER)
            ->setParameter('days', $days, Types::INTEGER)
            ->orderBy('i.id');
        if ($today !== null) {
            $query->setParameter('today', $today, Types::DATE_IMMUTABLE);
        }
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record) + ['warrantyexpiration' => $record->warrantyExpiresOn()?->format('Y-m-d')];
            $this->em->detach($record);
        }
        return $rows;
    }

    public function types(array $criteria): array
    {
        $metadata = $this->em->getClassMetadata(Entity\Infocom::class);
        $query = $this->em->createQueryBuilder()->select('DISTINCT r.itemtype AS itemtype')->from($metadata->name, 'r');
        $query->where((new RecordCriteria($query, $metadata))->where([
            'AND' => [['NOT' => ['itemtype' => Infocom::getExcludedTypes()]], $criteria],
        ]))->orderBy('r.itemtype');
        return $query->getQuery()->getScalarResult();
    }

    /** Stock and component purchases link to their model record. */
    public static function linkFor(string $itemtype): array
    {
        return match ($itemtype) {
            'Cartridge' => ['CartridgeItem', 'cartridgeitems_id'],
            'Consumable' => ['ConsumableItem', 'consumableitems_id'],
            default => is_a($itemtype, Item_Devices::class, true)
                ? [$itemtype::$itemtype_2, $itemtype::$items_id_2]
                : [$itemtype, 'id'],
        };
    }

    public static function supports(string $itemtype): bool
    {
        [$linktype] = self::linkFor($itemtype);
        return isset(EntityRegistry::tables()[getTableForItemType($itemtype)], EntityRegistry::tables()[getTableForItemType($linktype)]);
    }

    /** Count first; the renderer replaces oversized groups with a search link. */
    public function forSupplier(string $itemtype, int $supplier, ?array $entities, int $limit): array
    {
        $class = EntityRegistry::tables()[getTableForItemType($itemtype)] ?? throw new InvalidArgumentException('Unmapped financial item type');
        [$linktype, $linkfield] = self::linkFor($itemtype);
        $query = $this->em->createQueryBuilder()->from(Entity\Infocom::class, 'i')
            ->innerJoin($class, 'a', 'WITH', 'a.id = i.items_id')
            ->where('i.itemtype = :type AND i.suppliers = :supplier')
            ->setParameter('type', $itemtype, Types::STRING)->setParameter('supplier', $supplier, Types::INTEGER);
        $linkAlias = 'a';
        if ($linkfield !== 'id') {
            $linkAlias = 'model';
            $metadata = $this->em->getClassMetadata($class);
            $association = null;
            foreach ($metadata->associationMappings as $field => $mapping) {
                if ($mapping->joinColumns[0]->name === $linkfield) {
                    $association = $field;
                    break;
                }
            }
            if ($association !== null) {
                $query->innerJoin('a.' . $association, $linkAlias);
            } else {
                $linkClass = EntityRegistry::tables()[getTableForItemType($linktype)];
                $query->innerJoin($linkClass, $linkAlias, 'WITH', 'model.id = a.' . $metadata->getFieldName($linkfield));
            }
        }
        if ($entities !== null) {
            $query->andWhere('IDENTITY(' . $linkAlias . '.entities) IN (:entities)')->setParameter('entities', $entities ?: [-1]);
        }
        $count = (int)(clone $query)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
        if ($count === 0 || $count > max(0, $limit)) {
            return ['count' => $count, 'rows' => []];
        }
        $name = $linktype::getNameField();
        $query->select('a', $linkAlias . '.' . $name . ' AS linked_name')
            ->orderBy('IDENTITY(i.entities)')->addOrderBy($linkAlias . '.' . $name)->addOrderBy('a.id')
            ->setMaxResults(max(0, $limit));
        $rows = [];
        $records = new RecordRepository($this->em);
        foreach ($query->getQuery()->toIterable() as $result) {
            $record = $result[0];
            $rows[] = array_replace($records->toRow($record), [$name => $result['linked_name']]);
            $this->em->detach($record);
        }
        return ['count' => $count, 'rows' => $rows];
    }
}
