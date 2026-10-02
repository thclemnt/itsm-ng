<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;
use itsmng\Database\ITILDocumentAccess;
use itsmng\Database\RecordCriteria;

final class DocumentRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function contentId(int $entity, string $hash): ?int
    {
        $row = $this->em->createQueryBuilder()->select('d.id')->from(Entity\Document::class, 'd')
            ->where('IDENTITY(d.entities) = :entity AND d.sha1sum = :hash')->setParameter('entity', $entity, Types::INTEGER)
            ->setParameter('hash', $hash)->orderBy('d.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        return $row === null ? null : (int)$row['id'];
    }

    public function icon(string $extension): ?string
    {
        $row = $this->em->createQueryBuilder()->select('t.icon')->from(Entity\DocumentType::class, 't')
            ->where("LOWER(t.ext) LIKE :extension AND t.icon <> ''")->setParameter('extension', strtolower($extension))
            ->orderBy('t.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        return $row === null ? null : $row['icon'];
    }

    public function uploadableTypes(string $pattern): array
    {
        return $this->em->createQueryBuilder()->select('t.ext')->from(Entity\DocumentType::class, 't')
            ->where('LOWER(t.ext) LIKE :pattern AND t.is_uploadable = :yes')->setParameter('pattern', strtolower($pattern))
            ->setParameter('yes', true, Types::BOOLEAN)->orderBy('t.id')->getQuery()->getScalarResult();
    }

    public function categories(array $criteria): array
    {
        $query = $this->em->createQueryBuilder()->select('DISTINCT c.id', 'c.name')->from(Entity\Document::class, 'r')
            ->join('r.documentcategories', 'c')->orderBy('c.name')->addOrderBy('c.id');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata(Entity\Document::class)))->where($criteria));
        return array_column($query->getQuery()->getScalarResult(), 'name', 'id');
    }

    /** Snapshot before the model purge lifecycle mutates document bindings. */
    public function orphanIds(): array
    {
        return array_map('intval', array_column($this->em->createQueryBuilder()->select('d.id')->from(Entity\Document::class, 'd')
            ->where('NOT EXISTS (SELECT binding.id FROM ' . Entity\DocumentItem::class . ' binding WHERE IDENTITY(binding.documents) = d.id)')
            ->orderBy('d.id')->getQuery()->getScalarResult(), 'id'));
    }

    public function linkedToITIL(int $document, string $type, int $item, ITILDocumentAccess $access): bool
    {
        if ($access->user <= 0) {
            return false;
        }
        return $this->itilBindings($type, $item, $access)->andWhere('IDENTITY(d.documents) = :document')
            ->setParameter('document', $document, Types::INTEGER)->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }

    /** Template attachments retain one row per visible timeline binding. */
    public function notificationDocuments(string $type, int $item, ITILDocumentAccess $access): array
    {
        $query = $this->itilBindings($type, $item, $access)->select('d', 'document')->join('d.documents', 'document')
            ->andWhere('d.timeline_position > :inline')->setParameter('inline', \CommonITILObject::NO_TIMELINE, Types::INTEGER)
            ->orderBy('d.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $binding) {
            $rows[] = $records->toRow($binding->documents);
            $this->em->detach($binding);
        }
        return $rows;
    }

    /** One document row per binding, scoped to the complete legacy item identity. */
    public function documentsForItem(string $type, int $item): array
    {
        $query = $this->itemBindings($type, $item)->select('binding', 'document')->join('binding.documents', 'document');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $binding) {
            $rows[] = $records->toRow($binding->documents);
            $this->em->detach($binding);
        }
        return $rows;
    }

    /** Binding identities for callers that must load documents through their model hooks. */
    public function bindingsForItem(string $type, int $item): array
    {
        return $this->itemBindings($type, $item)->select('binding.id', 'IDENTITY(binding.documents) AS documents_id')
            ->getQuery()->getScalarResult();
    }

    private function itemBindings(string $type, int $item): \Doctrine\ORM\QueryBuilder
    {
        $query = $this->em->createQueryBuilder()->from(Entity\DocumentItem::class, 'binding')->orderBy('binding.id');
        try {
            $association = Entity\DocumentItem::referenceAssociation($type);
        } catch (\InvalidArgumentException) {
            // No mapped subject can own a binding for an unknown item kind.
            return $query->where('1 = 0');
        }
        return $query->where('IDENTITY(binding.' . $association . ') = :item')
            ->setParameter('item', $item, Types::BIGINT)->orderBy('binding.id');
    }

    private function itilBindings(string $type, int $item, ITILDocumentAccess $access): \Doctrine\ORM\QueryBuilder
    {
        [$task, , $taskAssociation] = (new ITILTaskRepository($this->em))->definition($type . 'Task');
        $query = $this->em->createQueryBuilder()->select('d.id')->from(Entity\DocumentItem::class, 'd')
            ->setParameter('type', $type)->setParameter('item', $item, Types::INTEGER);
        $conditions = ['(d.itemtype = :type AND d.items_id = :item)'];
        if ($access->followups) {
            $private = '';
            if (!$access->privateFollowups) {
                $private = ' AND (f.is_private = :public OR IDENTITY(f.author) = :viewer)';
                $query->setParameter('public', false, Types::BOOLEAN)->setParameter('viewer', $access->user, Types::INTEGER);
            }
            $subject = Entity\ITILFollowup::subjectAssociation($type);
            $conditions[] = "(d.itemtype = 'ITILFollowup' AND EXISTS (SELECT f.id FROM " . Entity\ITILFollowup::class . ' f WHERE f.id = d.items_id AND IDENTITY(f.' . $subject . ') = :item' . $private . '))';
        }
        if ($access->solutions) {
            $subject = Entity\ITILSolution::subjectAssociation($type);
            $conditions[] = "(d.itemtype = 'ITILSolution' AND EXISTS (SELECT s.id FROM " . Entity\ITILSolution::class . ' s WHERE s.id = d.items_id AND IDENTITY(s.' . $subject . ') = :item))';
        }
        if ($access->tasks) {
            $private = '';
            if (!$access->privateTasks) {
                $private = ' AND (t.is_private = :public OR IDENTITY(t.author) = :viewer)';
                $query->setParameter('public', false, Types::BOOLEAN)->setParameter('viewer', $access->user, Types::INTEGER);
            }
            $conditions[] = '(d.itemtype = :taskType AND EXISTS (SELECT t.id FROM ' . $task . ' t WHERE t.id = d.items_id AND IDENTITY(t.' . $taskAssociation . ') = :item' . $private . '))';
            $query->setParameter('taskType', $type . 'Task');
        }
        return $query->where('(' . implode(' OR ', $conditions) . ')');
    }
}
