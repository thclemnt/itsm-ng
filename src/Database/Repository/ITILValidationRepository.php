<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use CommonITILValidation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use itsmng\Database\Mapping\ValidationRequest;

/** Workflow request counts and assignment checks; callers own rights and lifecycle. */
final class ITILValidationRepository
{
    /** @param class-string<ValidationRequest> $request */
    public function __construct(private EntityManager $em, private string $request)
    {
        if (!is_a($request, ValidationRequest::class, true)) {
            throw new InvalidArgumentException('An approval request requires its mapped subject association.');
        }
    }

    public function waitingForValidator(?int $validator): int
    {
        $query = $this->query()->select('COUNT(r.id)')->where('r.status = :status')
            ->setParameter('status', CommonITILValidation::WAITING, Types::INTEGER);
        return (int)$this->forValidator($query, $validator)->getQuery()->getSingleScalarResult();
    }

    public function countForSubject(?int $subject, ?int $status): int
    {
        $query = $this->forSubject($this->query()->select('COUNT(r.id)'), $subject);
        if ($status === null) {
            $query->andWhere('r.status IS NULL');
        } else {
            $query->andWhere('r.status = :status')->setParameter('status', $status, Types::INTEGER);
        }
        return (int)$query->getQuery()->getSingleScalarResult();
    }

    /** Existing requests suppress automatic duplicates even after an answer. */
    public function hasValidator(?int $subject, ?int $validator): bool
    {
        $query = $this->forSubject($this->query()->select('r.id'), $subject);
        return $this->forValidator($query, $validator)->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }

    private function query(): QueryBuilder
    {
        return $this->em->createQueryBuilder()->from($this->request, 'r');
    }

    private function forSubject(QueryBuilder $query, ?int $subject): QueryBuilder
    {
        $request = $this->request;
        if ($subject === null) {
            return $query->andWhere('r.' . $request::subjectAssociation() . ' IS NULL');
        }
        return $query->andWhere('IDENTITY(r.' . $request::subjectAssociation() . ') = :subject')
            ->setParameter('subject', $subject, Types::BIGINT);
    }

    private function forValidator(QueryBuilder $query, ?int $validator): QueryBuilder
    {
        if ($validator === null) {
            return $query->andWhere('r.validator IS NULL');
        }
        return $query->andWhere('IDENTITY(r.validator) = :validator')
            ->setParameter('validator', $validator, Types::BIGINT);
    }
}
