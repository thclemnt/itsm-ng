<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Domain\DictionaryMutation;

final class PrinterDictionaryRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    private function groups(): QueryBuilder
    {
        return $this->em->createQueryBuilder()->select(
            'p.name AS name',
            'm.name AS manufacturer',
            'IDENTITY(p.manufacturers) AS manufacturers_id',
            'p.comment AS comment',
            'MIN(p.id) AS HIDDEN first_id'
        )
            ->from(Entity\Printer::class, 'p')->leftJoin('p.manufacturers', 'm')
            ->where('p.is_deleted = :inactive AND p.is_template = :inactive')->setParameter('inactive', false, Types::BOOLEAN)
            ->groupBy('p.name, m.name, p.manufacturers, p.comment')->orderBy('first_id');
    }

    public function groupCount(): int
    {
        $count = 0;
        foreach ($this->groups()->getQuery()->toIterable() as $row) {
            ++$count;
        }
        return $count;
    }

    public function replayGroups(int $offset): iterable
    {
        return $this->groups()->setFirstResult(max(0, $offset))->getQuery()->toIterable();
    }

    public function matchingPrinters(?string $name, ?int $manufacturer): array
    {
        $query = $this->em->createQueryBuilder()->select('p.id AS id')->from(Entity\Printer::class, 'p')->orderBy('p.id')
            ->where($name === null ? 'p.name IS NULL' : 'p.name = :name')
            ->andWhere($manufacturer === null ? 'p.manufacturers IS NULL' : 'p.manufacturers = :manufacturer');
        if ($name !== null) {
            $query->setParameter('name', $name, Types::STRING);
        }
        if ($manufacturer !== null) {
            $query->setParameter('manufacturer', $manufacturer, Types::INTEGER);
        }
        return array_map('intval', array_column($query->getQuery()->getScalarResult(), 'id'));
    }

    public function replayPrinters(array $ids): array
    {
        $rows = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 1000) as $chunk) {
            array_push($rows, ...$this->em->createQueryBuilder()->select(
                'p.id AS id',
                'p.name AS name',
                'IDENTITY(p.entities) AS entities_id',
                'p.is_global AS is_global',
                'm.name AS manufacturer'
            )
                ->from(Entity\Printer::class, 'p')->leftJoin('p.manufacturers', 'm')
                ->where('p.id IN (:ids) AND p.is_template = :inactive')->setParameter('ids', $chunk)
                ->setParameter('inactive', false, Types::BOOLEAN)->orderBy('p.id')->getQuery()->getScalarResult());
        }
        usort($rows, static fn (array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
        return $rows;
    }

    /** Keep destination connection metadata and invoke the public relation lifecycle. */
    public function moveConnections(int $source, int $target, callable $move, callable $remove): void
    {
        DictionaryMutation::run($this->em->getConnection(), function (callable $assertActive) use ($source, $target, $move, $remove): void {
            $ids = array_values(array_unique([$source, $target]));
            $count = $this->em->createQueryBuilder()->select('COUNT(p.id)')->from(Entity\Printer::class, 'p')
                ->where('p.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getSingleScalarResult();
            if ((int)$count !== count($ids)) {
                throw new \InvalidArgumentException('Printer connection move requires existing printers.');
            }
            if ($source === $target) {
                return;
            }
            $links = $this->em->createQueryBuilder()->select(
                'l.id AS id',
                'IDENTITY(l.computers) AS computers_id',
                'l.items_id AS items_id',
                'l.itemtype AS itemtype',
                'l.is_deleted AS is_deleted',
                'l.is_dynamic AS is_dynamic'
            )
                ->from(Entity\ComputerItem::class, 'l')->where('l.items_id = :source AND l.itemtype = :type')
                ->setParameter('source', $source, Types::INTEGER)->setParameter('type', 'Printer', Types::STRING)
                ->orderBy('l.id')->getQuery()->getScalarResult();
            foreach ($links as $link) {
                $duplicate = $this->em->createQueryBuilder()->select('l.id')->from(Entity\ComputerItem::class, 'l')
                    ->where('l.items_id = :target AND l.itemtype = :type AND l.computers = :computer')
                    ->setParameter('target', $target, Types::INTEGER)->setParameter('type', 'Printer', Types::STRING)
                    ->setParameter('computer', (int)$link['computers_id'], Types::INTEGER)->setMaxResults(1)->getQuery()->getScalarResult();
                $changed = $duplicate ? $remove($link) : $move((int)$link['id'], $target);
                $assertActive();
                if (!$changed) {
                    throw new \RuntimeException('Unable to move printer direct connection.');
                }
            }
        });
    }
}
