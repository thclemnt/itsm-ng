<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\ItilProject;

/** Project/ITIL tab projections follow the declared owning associations. */
final class ItilProjectRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function subjects(string $kind, int $project): array
    {
        $association = ItilProject::subjectAssociation($kind);
        $class = $this->em->getClassMetadata(ItilProject::class)->getAssociationTargetClass($association);
        $query = $this->em->createQueryBuilder()->select('r', 'link.id AS linkid')->from($class, 'r')
            ->join(ItilProject::class, 'link', 'WITH', 'IDENTITY(link.' . $association . ') = r.id')
            ->where('IDENTITY(link.projects) = :project')->setParameter('project', $project, Types::INTEGER);
        return $this->rows($query);
    }

    public function projects(string $kind, int $subject): array
    {
        $association = ItilProject::subjectAssociation($kind);
        $class = $this->em->getClassMetadata(ItilProject::class)->getAssociationTargetClass('projects');
        $query = $this->em->createQueryBuilder()->select('r', 'link.id AS linkid')->from($class, 'r')
            ->join(ItilProject::class, 'link', 'WITH', 'IDENTITY(link.projects) = r.id')
            ->where('IDENTITY(link.' . $association . ') = :subject')->setParameter('subject', $subject, Types::INTEGER);
        return $this->rows($query);
    }

    private function rows(QueryBuilder $query): array
    {
        $query->addSelect('CASE WHEN r.name IS NULL THEN 0 ELSE 1 END AS HIDDEN named')
            ->orderBy('named')->addOrderBy('r.name')->addOrderBy('r.id')->addOrderBy('link.id');
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $rows[] = $records->toRow($result[0]) + ['linkid' => (int)$result['linkid']];
            $this->em->detach($result[0]);
        }
        return $rows;
    }
}
