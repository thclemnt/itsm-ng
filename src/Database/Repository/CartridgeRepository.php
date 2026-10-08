<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Database\RecordCriteria;

/** Stock operations retain their explicit application history at the caller. */
final class CartridgeRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** The guarded update prevents two callers from claiming the same stock row. */
    public function install(int $printer, int $model): bool
    {
        $rows = $this->em->createQueryBuilder()->select('c.id')->from(Entity\Cartridge::class, 'c')
            ->where('c.cartridgeitems = :model AND c.date_use IS NULL')
            ->setParameter('model', $model, Types::INTEGER)->orderBy('c.id')->setMaxResults(1)
            ->getQuery()->getScalarResult();
        if (!$rows || $printer <= 0) {
            return false;
        }
        return $this->em->createQueryBuilder()->update(Entity\Cartridge::class, 'c')
            ->set('c.date_use', ':today')->setParameter('today', new DateTimeImmutable('today'), Types::DATE_IMMUTABLE)
            ->set('c.printers', ':printer')->setParameter('printer', $printer, Types::INTEGER)
            ->where('c.id = :id AND c.date_use IS NULL')->setParameter('id', (int)$rows[0]['id'], Types::INTEGER)
            ->getQuery()->execute() > 0;
    }

    /** Keep dates and counters as historical data when a printer is purged. */
    public function detachPrinter(int $printer): void
    {
        $this->em->createQueryBuilder()->update(Entity\Cartridge::class, 'c')->set('c.printers', 'NULL')
            ->where('c.printers = :printer')->setParameter('printer', $printer, Types::INTEGER)->getQuery()->execute();
    }

    public function endLife(int $id, ?int $pages): bool
    {
        $query = $this->em->createQueryBuilder()->update(Entity\Cartridge::class, 'c')
            ->set('c.date_out', ':today')->setParameter('today', new DateTimeImmutable('today'), Types::DATE_IMMUTABLE)
            ->where('c.id = :id')->setParameter('id', $id, Types::INTEGER);
        $changed = 'c.date_out IS NULL OR c.date_out <> :today';
        if ($pages !== null) {
            $query->set('c.pages', ':pages')->setParameter('pages', $pages, Types::INTEGER);
            $changed .= ' OR c.pages <> :pages';
        }
        return $query->andWhere('(' . $changed . ')')->getQuery()->execute() > 0;
    }

    public function availableForPrinter(int $model, array $scope): array
    {
        if ($model <= 0) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('COUNT(c.id) AS cpt', 'l.completename AS location', 'r.ref AS ref', 'r.name AS name', 'r.id AS tID')
            ->from(Entity\CartridgeItem::class, 'r')
            ->innerJoin(Entity\CartridgeItemPrinterModel::class, 'm', 'WITH', 'm.cartridgeitems = r.id')
            ->innerJoin(Entity\Cartridge::class, 'c', 'WITH', 'c.cartridgeitems = r.id AND c.date_use IS NULL')
            ->leftJoin('r.locations', 'l')
            ->where('m.printermodels = :model')->setParameter('model', $model, Types::INTEGER);
        $query->andWhere((new RecordCriteria($query, $this->em->getClassMetadata(Entity\CartridgeItem::class)))->where($scope))
            ->groupBy('r.id, r.name, r.ref, l.completename')->orderBy('r.name')->addOrderBy('r.ref')->addOrderBy('r.id');
        return $query->getQuery()->getScalarResult();
    }

    public function forModel(int $model, bool $old): array
    {
        $query = $this->em->createQueryBuilder()->select('c', 'p.id AS printID', 'p.name AS printname', 'p.init_pages_counter AS init_pages_counter')
            ->from(Entity\Cartridge::class, 'c')->leftJoin('c.printers', 'p')
            ->where('c.cartridgeitems = :model')->setParameter('model', $model, Types::INTEGER)
            ->andWhere('c.date_out IS ' . ($old ? 'NOT NULL' : 'NULL'));
        $this->order($query, $old ? ['date_use' => 'ASC', 'date_out' => 'DESC', 'date_in' => 'ASC'] : ['date_out' => 'ASC', 'date_use' => 'ASC', 'date_in' => 'ASC']);
        return $this->rows($query);
    }

    public function forPrinter(int $printer, bool $old): array
    {
        $query = $this->em->createQueryBuilder()->select('c', 'm.id AS tID', 'm.is_deleted AS is_deleted', 'm.ref AS ref', 'm.name AS type', 't.name AS typename')
            ->from(Entity\Cartridge::class, 'c')->innerJoin('c.cartridgeitems', 'm')->leftJoin('m.cartridgeitemtypes', 't')
            ->where('c.printers = :printer')->setParameter('printer', $printer, Types::INTEGER)
            ->andWhere('c.date_out IS ' . ($old ? 'NOT NULL' : 'NULL'));
        $this->order($query, ['date_out' => 'ASC', 'date_use' => 'DESC', 'date_in' => 'ASC']);
        return $this->rows($query);
    }

    private function order(QueryBuilder $query, array $fields): void
    {
        foreach ($fields as $field => $direction) {
            $alias = $field . '_null_order';
            $query->addSelect('CASE WHEN c.' . $field . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN ' . $alias)
                ->addOrderBy($alias, $direction)->addOrderBy('c.' . $field, $direction);
        }
        $query->addOrderBy('c.id');
    }

    private function rows(QueryBuilder $query): array
    {
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $row = $records->toRow($result[0]);
            $this->em->detach($result[0]);
            unset($result[0]);
            $rows[] = $row + $result;
        }
        return $rows;
    }
}
