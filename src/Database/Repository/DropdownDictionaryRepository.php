<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use RuntimeException;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Domain\DictionaryMutation;

/** Dynamic dictionary types resolve their real associations through Doctrine metadata. */
final class DropdownDictionaryRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    private function classFor(string $table): string
    {
        return EntityRegistry::tables()[$table] ?? throw new InvalidArgumentException('Unmapped dictionary table: ' . $table);
    }

    public function count(string $table): int
    {
        return (int)$this->em->createQueryBuilder()->select('COUNT(r.id)')->from($this->classFor($table), 'r')->getQuery()->getSingleScalarResult();
    }

    public function rows(string $table, int $offset): iterable
    {
        return $this->em->createQueryBuilder()->select('r.id AS id', 'r.name AS name', 'r.comment AS comment')
            ->from($this->classFor($table), 'r')->orderBy('r.id')->setFirstResult(max(0, $offset))->getQuery()->toIterable();
    }

    /** Property names are projections of mapped targets, never guessed column names. */
    private function association(string $owner, string $target): string
    {
        $metadata = $this->em->getClassMetadata($this->classFor($owner));
        $properties = [];
        foreach ($metadata->associationMappings as $property => $mapping) {
            if ($mapping->isToOneOwningSide() && $mapping->targetEntity === $this->classFor($target)) {
                $properties[] = $property;
            }
        }
        if (count($properties) !== 1) {
            throw new InvalidArgumentException('Dictionary requires one mapped association: ' . $owner . ' -> ' . $target);
        }
        return $properties[0];
    }

    private function modelQuery(string $model, string $owner): QueryBuilder
    {
        $modelProperty = $this->association($owner, $model);
        $manufacturer = $this->association($owner, 'glpi_manufacturers');
        return $this->em->createQueryBuilder()->select('DISTINCT m.id AS id', 'm.name AS name', 'm.comment AS comment', 'f.id AS idmanu', 'f.name AS manufacturer')
            ->from($this->classFor($owner), 'r')->innerJoin('r.' . $modelProperty, 'm')->leftJoin('r.' . $manufacturer, 'f')
            ->addSelect('CASE WHEN f.id IS NULL THEN 0 ELSE 1 END AS HIDDEN manufacturer_order')
            ->orderBy('m.id')->addOrderBy('manufacturer_order')->addOrderBy('f.id');
    }

    public function modelCount(string $model, string $owner): int
    {
        // DQL has no composite DISTINCT count. Let ORM resolve the same joins,
        // then aggregate their identity pairs without fetching labels or comments.
        // Keep the joined manufacturer identity: NULL is one real partition.
        $pairs = $this->modelQuery($model, $owner)
            ->select('DISTINCT m.id AS id', 'f.id AS idmanu')
            ->resetDQLPart('orderBy')->getQuery();
        return (int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM (' . $pairs->getSQL() . ') AS dictionary_pairs');
    }

    public function modelRows(string $model, string $owner, int $offset): iterable
    {
        return $this->modelQuery($model, $owner)->setFirstResult(max(0, $offset))->getQuery()->toIterable();
    }

    /** Remap one model's manufacturer partitions and its children before deleting it. */
    public function replaceModel(string $model, string $owner, int $source, array $moves, ?callable $addCompatibility = null): void
    {
        $modelProperty = $this->association($owner, $model);
        $manufacturerProperty = $this->association($owner, 'glpi_manufacturers');
        DictionaryMutation::run($this->em->getConnection(), function (callable $assertActive) use ($model, $owner, $source, $moves, $modelProperty, $manufacturerProperty, $addCompatibility): void {
            $targets = [];
            foreach ($moves as $manufacturer => $target) {
                $target = (int)$target;
                if ($target === $source) {
                    continue;
                }
                $query = $this->em->createQueryBuilder()->update($this->classFor($owner), 'r')
                    ->set('r.' . $modelProperty, ':target')->setParameter('target', $target, Types::INTEGER)
                    ->where('r.' . $modelProperty . ' = :source')->setParameter('source', $source, Types::INTEGER);
                if ((int)$manufacturer === 0) {
                    $query->andWhere('r.' . $manufacturerProperty . ' IS NULL');
                } else {
                    $query->andWhere('r.' . $manufacturerProperty . ' = :manufacturer')->setParameter('manufacturer', (int)$manufacturer, Types::INTEGER);
                }
                $query->getQuery()->execute();
                $targets[] = $target;
            }
            if (!$targets) {
                return;
            }
            $remaining = (int)$this->em->createQueryBuilder()->select('COUNT(r.id)')->from($this->classFor($owner), 'r')
                ->where('r.' . $modelProperty . ' = :source')->setParameter('source', $source, Types::INTEGER)->getQuery()->getSingleScalarResult();
            if ($model === 'glpi_printermodels') {
                $links = $this->em->createQueryBuilder()->select('IDENTITY(c.cartridgeitems) AS id')->from(Entity\CartridgeItemPrinterModel::class, 'c')
                    ->where('c.printermodels = :source')->setParameter('source', $source, Types::INTEGER)->getQuery()->getScalarResult();
                if ($remaining === 0) {
                    $this->em->createQueryBuilder()->delete(Entity\CartridgeItemPrinterModel::class, 'c')
                        ->where('c.printermodels = :source')->setParameter('source', $source, Types::INTEGER)->getQuery()->execute();
                }
                $compatibility = new PrinterCompatibilityRepository($this->em);
                foreach ($links as $link) {
                    foreach (array_unique($targets) as $target) {
                        $added = $addCompatibility === null ? $compatibility->add((int)$link['id'], $target) : $addCompatibility((int)$link['id'], $target);
                        $assertActive();
                        if (!$added) {
                            throw new RuntimeException('Unable to move printer model compatibility.');
                        }
                    }
                }
            }
            if ($remaining === 0) {
                // Former replay bypassed model hooks. Keep that boundary while
                // deleting through the mapped unit of work after dependent links.
                (new RecordWriter($this->em))->delete($model, $source);
            }
        });
    }
}
