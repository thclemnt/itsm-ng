<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;

/** Transfer links use their owning association; application callbacks move the parent. */
final class TransferBindingRepository
{
    private string $parentClass;

    private function __construct(private EntityManager $em, private string $class, private string $parent)
    {
        $this->parentClass = $em->getClassMetadata($class)->getAssociationTargetClass($parent);
    }

    public static function contracts(EntityManager $em): self
    {
        return new self($em, Entity\ContractItem::class, 'contracts');
    }

    public static function documents(EntityManager $em): self
    {
        return new self($em, Entity\DocumentItem::class, 'documents');
    }

    /** Snapshot links before callbacks can change their ownership. */
    public function links(string $type, int $item, array $excluded = []): array
    {
        $identity = $this->itemIdentity($type);
        if ($identity === null) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('r.id AS id', 'IDENTITY(r.' . $this->parent . ') AS parent_id')
            ->from($this->class, 'r')->where('r.itemtype = :type AND ' . $identity . ' = :item')
            ->setParameter('type', $type, Types::STRING)->setParameter('item', $item, Types::BIGINT);
        if ($excluded) {
            $query->andWhere('r.' . $this->parent . ' NOT IN (:excluded)')->setParameter('excluded', array_map('intval', $excluded));
        }
        return $query->orderBy('r.id')->getQuery()->getScalarResult();
    }

    public function hasOutsideItems(int $parent, string $type, array $included): bool
    {
        $identity = $this->itemIdentity($type);
        if ($identity === null) {
            return false;
        }
        $query = $this->em->createQueryBuilder()->select('r.id')->from($this->class, 'r')
            ->where('r.' . $this->parent . ' = :parent AND r.itemtype = :type')
            ->setParameter('parent', $parent, Types::BIGINT)->setParameter('type', $type, Types::STRING);
        if ($included) {
            $query->andWhere($identity . ' NOT IN (:included)')->setParameter('included', array_map('intval', $included));
        }
        return (bool)$query->setMaxResults(1)->getQuery()->getScalarResult();
    }

    /** Internal copy selection includes templates and trash and compares literal names. */
    public function destination(int $entity, string $name): ?int
    {
        $rows = $this->em->createQueryBuilder()->select('p.id AS id')->from($this->parentClass, 'p')
            ->where('p.entities = :entity AND p.name = :name')
            ->setParameter('entity', $entity, Types::BIGINT)->setParameter('name', $name, Types::STRING)
            ->orderBy('p.id')->setMaxResults(1)->getQuery()->getScalarResult();
        return $rows ? (int)$rows[0]['id'] : null;
    }

    public function move(int $link, ?int $parent = null, ?int $item = null): void
    {
        $record = $this->em->find($this->class, $link);
        if ($record === null) {
            return;
        }
        if ($parent !== null) {
            $record->{$this->parent} = $this->em->getReference($this->parentClass, $parent);
        }
        if ($item !== null) {
            $association = $this->class::referenceAssociation($record->itemtype);
            $target = $this->em->getClassMetadata($this->class)->getAssociationTargetClass($association);
            $record->{$association} = $this->em->getReference($target, $item);
        }
        $this->em->flush();
        $this->em->detach($record);
    }

    public function copy(int $parent, string $type, int $item): int
    {
        $metadata = $this->em->getClassMetadata($this->class);
        return (new RecordWriter($this->em))->insert($metadata->getTableName(), [
            $metadata->getAssociationMapping($this->parent)->joinColumns[0]->name => $parent,
            'itemtype' => $type, 'items_id' => $item,
        ]);
    }

    public function isReferenced(int $parent): bool
    {
        return (bool)$this->em->createQueryBuilder()->select('r.id')->from($this->class, 'r')
            ->where('r.' . $this->parent . ' = :parent')->setParameter('parent', $parent, Types::BIGINT)
            ->setMaxResults(1)->getQuery()->getScalarResult();
    }

    public function unlink(string $type, int $item): void
    {
        $identity = $this->itemIdentity($type);
        if ($identity === null) {
            return;
        }
        $this->em->createQueryBuilder()->delete($this->class, 'r')->where('r.itemtype = :type AND ' . $identity . ' = :item')
            ->setParameter('type', $type, Types::STRING)->setParameter('item', $item, Types::BIGINT)
            ->getQuery()->execute();
        $this->em->clear();
    }
    private function itemIdentity(string $type): ?string
    {
        try {
            return 'IDENTITY(r.' . $this->class::referenceAssociation($type) . ')';
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
