<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\ItemProject;
use itsmng\Database\Entity\Project;
use itsmng\Database\RecordCriteria;

/** Project subjects retain their binding identity and distinct owner/subject roles. */
final class ProjectAssetRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function kinds(int $project, array $criteria = []): array
    {
        if ($project <= 0) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('DISTINCT r.itemtype AS itemtype')->from(ItemProject::class, 'r')
            ->where('IDENTITY(r.projects) = :project')->setParameter('project', $project, Types::BIGINT);
        if ($criteria) {
            $query->andWhere((new RecordCriteria($query, $this->em->getClassMetadata(ItemProject::class)))->where($criteria));
        }
        return $query->orderBy('r.itemtype')->getQuery()->getScalarResult();
    }

    public function hasBinding(int $project, string $kind, int $subject): bool
    {
        try {
            $association = ItemProject::referenceAssociation($kind);
        } catch (\InvalidArgumentException) {
            return false;
        }
        return (int)$this->em->createQueryBuilder()->select('COUNT(r.id)')->from(ItemProject::class, 'r')
            ->where('IDENTITY(r.projects) = :project AND IDENTITY(r.' . $association . ') = :subject')
            ->setParameter('project', $project, Types::BIGINT)->setParameter('subject', $subject, Types::BIGINT)
            ->getQuery()->getSingleScalarResult() > 0;
    }

    public function subjectCount(int $project, string $kind, array $criteria): int
    {
        $query = $this->subjectQuery($project, $kind, $criteria);
        return $query === null ? 0 : (int)$query->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
    }

    /** Criteria come from the application's entity/template policy, not an unrestricted fallback. */
    public function subjects(int $project, string $kind, array $criteria, string $orderField, ?string $componentColumn = null): array
    {
        $query = $this->subjectQuery($project, $kind, $criteria);
        if ($query === null) {
            return [];
        }
        $association = ItemProject::referenceAssociation($kind);
        $metadata = $this->em->getClassMetadata($this->em->getClassMetadata(ItemProject::class)->getAssociationTargetClass($association));
        $query->select('r, l.id AS linkid, IDENTITY(r.entities) AS entity')->leftJoin('r.entities', 'e')
            ->addSelect('CASE WHEN e.completename IS NULL THEN 0 ELSE 1 END AS HIDDEN entity_missing')
            ->orderBy('entity_missing')->addOrderBy('e.completename');
        if ($componentColumn !== null) {
            $definition = null;
            foreach ($metadata->associationMappings as $property => $mapping) {
                if ($mapping->isToOneOwningSide() && $mapping->joinColumns[0]->name === $componentColumn) {
                    $definition = $property;
                    break;
                }
            }
            if ($definition === null) {
                throw new \InvalidArgumentException('Installed project subject requires its owning device definition');
            }
            // Installed components have no name column; their definition owns the display label.
            $query->leftJoin('r.' . $definition, 'd')->addSelect('d.designation AS name');
        }
        $order = 'r.' . $metadata->getFieldName($orderField);
        $query->addSelect('CASE WHEN ' . $order . ' IS NULL THEN 0 ELSE 1 END AS HIDDEN subject_missing')
            ->addOrderBy('subject_missing')->addOrderBy($order)->addOrderBy('r.id')->addOrderBy('l.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->getResult() as $result) {
            $record = $result[0];
            unset($result[0]);
            $rows[] = $records->toRow($record) + $result;
            $this->em->detach($record);
        }
        return $rows;
    }

    /** The opposite direction always counts project owners, including a Project subject. */
    public function ownerCount(string $kind, int $subject, array $criteria): int
    {
        try {
            $association = ItemProject::referenceAssociation($kind);
        } catch (\InvalidArgumentException) {
            return 0;
        }
        if ($subject <= 0) {
            return 0;
        }
        $query = $this->em->createQueryBuilder()->select('COUNT(l.id)')->from(Project::class, 'r')
            ->join(ItemProject::class, 'l', 'WITH', 'l.projects = r')
            ->where('IDENTITY(l.' . $association . ') = :subject')->setParameter('subject', $subject, Types::BIGINT);
        if ($criteria) {
            $query->andWhere((new RecordCriteria($query, $this->em->getClassMetadata(Project::class)))->where($criteria));
        }
        return (int)$query->getQuery()->getSingleScalarResult();
    }

    /** Notification rendering still loads each public subject model and runs its hooks. */
    public function bindings(int $project): array
    {
        if ($project <= 0) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('r')->from(ItemProject::class, 'r')
            ->where('IDENTITY(r.projects) = :project')->setParameter('project', $project, Types::BIGINT)->orderBy('r.id');
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $binding) {
            $association = ItemProject::referenceAssociation($binding->itemtype);
            $rows[] = ['id' => $binding->id, 'itemtype' => $binding->itemtype, 'items_id' => $binding->{$association}->id];
            $this->em->detach($binding);
        }
        return $rows;
    }

    /** A self-link occupies both roles once; an equal ID in another kind only occupies its subject role. */
    public function relationshipsForItem(string $kind, int $id): array
    {
        if ($id <= 0) {
            return [];
        }
        try {
            $association = ItemProject::referenceAssociation($kind);
        } catch (\InvalidArgumentException) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('r')->from(ItemProject::class, 'r')
            ->where('IDENTITY(r.' . $association . ') = :item')->setParameter('item', $id, Types::BIGINT);
        if ($kind === \Project::class) {
            $query->orWhere('IDENTITY(r.projects) = :item');
        }
        $rows = [];
        foreach ($query->orderBy('r.id')->getQuery()->toIterable() as $binding) {
            $subject = ItemProject::referenceAssociation($binding->itemtype);
            $ownerId = $binding->projects->id;
            $subjectId = $binding->{$subject}->id;
            $rows[] = ['id' => $binding->id, 'itemtype_1' => \Project::class, 'items_id_1' => $ownerId,
                'itemtype_2' => $binding->itemtype, 'items_id_2' => $subjectId,
                'is_1' => (int)($kind === \Project::class && $ownerId === $id),
                'is_2' => (int)($binding->itemtype === $kind && $subjectId === $id)];
            $this->em->detach($binding);
        }
        return $rows;
    }

    private function subjectQuery(int $project, string $kind, array $criteria): ?QueryBuilder
    {
        try {
            $association = ItemProject::referenceAssociation($kind);
        } catch (\InvalidArgumentException) {
            return null;
        }
        if ($project <= 0) {
            return null;
        }
        $metadata = $this->em->getClassMetadata($this->em->getClassMetadata(ItemProject::class)->getAssociationTargetClass($association));
        $query = $this->em->createQueryBuilder()->from($metadata->name, 'r')
            ->join(ItemProject::class, 'l', 'WITH', 'l.' . $association . ' = r')
            ->where('IDENTITY(l.projects) = :project')->setParameter('project', $project, Types::BIGINT);
        if ($criteria) {
            $query->andWhere((new RecordCriteria($query, $metadata))->where($criteria));
        }
        return $query;
    }
}
