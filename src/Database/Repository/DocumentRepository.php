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
        $task = match ($type) {
            'Ticket' => [Entity\TicketTask::class, 'tickets'],
            'Change' => [Entity\ChangeTask::class, 'changes'],
            'Problem' => [Entity\ProblemTask::class, 'problems'],
            default => throw new \InvalidArgumentException('Unsupported ITIL document type'),
        };
        $query = $this->em->createQueryBuilder()->select('d.id')->from(Entity\DocumentItem::class, 'd')
            ->where('IDENTITY(d.documents) = :document')->setParameter('document', $document, Types::INTEGER)
            ->setParameter('type', $type)->setParameter('item', $item, Types::INTEGER);
        $conditions = ['(d.itemtype = :type AND d.items_id = :item)'];
        if ($access->followups) {
            $private = '';
            if (!$access->privateFollowups) {
                $private = ' AND (f.is_private = :public OR IDENTITY(f.author) = :viewer)';
                $query->setParameter('public', false, Types::BOOLEAN)->setParameter('viewer', $access->user, Types::INTEGER);
            }
            $conditions[] = "(d.itemtype = 'ITILFollowup' AND EXISTS (SELECT f.id FROM " . Entity\ITILFollowup::class . ' f WHERE f.id = d.items_id AND f.itemtype = :type AND f.items_id = :item' . $private . '))';
        }
        if ($access->solutions) {
            $conditions[] = "(d.itemtype = 'ITILSolution' AND EXISTS (SELECT s.id FROM " . Entity\ITILSolution::class . ' s WHERE s.id = d.items_id AND s.itemtype = :type AND s.items_id = :item))';
        }
        if ($access->tasks) {
            $private = '';
            if (!$access->privateTasks) {
                $private = ' AND (t.is_private = :public OR IDENTITY(t.author) = :viewer)';
                $query->setParameter('public', false, Types::BOOLEAN)->setParameter('viewer', $access->user, Types::INTEGER);
            }
            $conditions[] = '(d.itemtype = :taskType AND EXISTS (SELECT t.id FROM ' . $task[0] . ' t WHERE t.id = d.items_id AND IDENTITY(t.' . $task[1] . ') = :item' . $private . '))';
            $query->setParameter('taskType', $type . 'Task');
        }
        return $query->andWhere('(' . implode(' OR ', $conditions) . ')')->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }
}
