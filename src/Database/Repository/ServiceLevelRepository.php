<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity;

/** Shared SLA/OLA selection; callers retain escalation and ticket lifecycle hooks. */
final class ServiceLevelRepository
{
    private string $levelClass;
    private string $queueClass;

    public function __construct(private EntityManager $em, private string $kind)
    {
        [$this->levelClass, $this->queueClass] = match ($kind) {
            'sla' => [Entity\SlaLevel::class, Entity\SlaLevelTicket::class],
            'ola' => [Entity\OlaLevel::class, Entity\OlaLevelTicket::class],
            default => throw new \InvalidArgumentException('Unknown service-level kind'),
        };
    }

    public function firstLevel(int $agreement): int
    {
        $row = $this->em->createQueryBuilder()->select('l.id')->from($this->levelClass, 'l')
            ->where('IDENTITY(l.' . $this->kind . 's) = :agreement AND l.is_active = :active')
            ->setParameter('agreement', $agreement, Types::INTEGER)->setParameter('active', true, Types::BOOLEAN)
            ->orderBy('l.execution_time')->addOrderBy('l.id')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        return (int)($row['id'] ?? 0);
    }

    public function nextLevel(int $agreement, int $current): int
    {
        $row = $this->em->createQueryBuilder()->select('l.id')->from($this->levelClass, 'l')
            ->join($this->levelClass, 'c', 'WITH', 'c.id = :current AND c.' . $this->kind . 's = l.' . $this->kind . 's')
            ->where('IDENTITY(l.' . $this->kind . 's) = :agreement AND l.is_active = :active AND l.execution_time > c.execution_time')
            ->setParameter('current', $current, Types::INTEGER)->setParameter('agreement', $agreement, Types::INTEGER)
            ->setParameter('active', true, Types::BOOLEAN)->orderBy('l.execution_time')->addOrderBy('l.id')
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        return (int)($row['id'] ?? 0);
    }

    public function executionTimes(int $agreement): array
    {
        $rows = $this->em->createQueryBuilder()->select('DISTINCT l.execution_time AS delay')->from($this->levelClass, 'l')
            ->where('IDENTITY(l.' . $this->kind . 's) = :agreement')->setParameter('agreement', $agreement, Types::INTEGER)
            ->orderBy('l.execution_time')->getQuery()->getScalarResult();
        $times = array_map('intval', array_column($rows, 'delay'));
        return array_combine($times, $times);
    }

    /** NULL dates remain unscheduled; use a single bound clock for due selection. */
    public function scheduled(?int $ticket = null, ?int $type = null, ?\DateTimeImmutable $before = null, ?int $limit = null): array
    {
        $query = $this->em->createQueryBuilder()->select('q', 'a.type AS agreement_type')->from($this->queueClass, 'q')
            ->join('q.' . $this->kind . 'levels', 'l')->join('l.' . $this->kind . 's', 'a')
            ->addSelect('CASE WHEN q.date IS NULL THEN 1 ELSE 0 END AS HIDDEN unscheduled')
            ->orderBy('unscheduled')->addOrderBy('q.date')->addOrderBy('q.id');
        if ($ticket !== null) {
            $query->andWhere('IDENTITY(q.tickets) = :ticket')->setParameter('ticket', $ticket, Types::INTEGER);
        }
        if ($type !== null) {
            $query->andWhere('a.type = :type')->setParameter('type', $type, Types::INTEGER);
        }
        if ($before !== null) {
            $query->andWhere('q.date < :before')->setParameter('before', $before, Types::DATETIMETZ_IMMUTABLE);
        }
        if ($limit !== null) {
            $query->setMaxResults($limit);
        }
        $rows = [];
        $records = new RecordRepository($this->em);
        foreach ($query->getQuery()->toIterable() as $result) {
            $rows[] = $records->toRow($result[0]) + ['type' => (int)$result['agreement_type']];
            $this->em->detach($result[0]);
        }
        return $rows;
    }

    public function agreementForTicket(int $ticket, int $type): ?int
    {
        $suffix = match ($type) {
            \SLM::TTO => 'tto',
            \SLM::TTR => 'ttr',
            default => throw new \InvalidArgumentException('Unknown service-level type'),
        };
        $row = $this->em->createQueryBuilder()->select('IDENTITY(t.' . $this->kind . 's_' . $suffix . ') AS agreement')
            ->from(Entity\Ticket::class, 't')->where('t.id = :ticket')->setParameter('ticket', $ticket, Types::INTEGER)
            ->getQuery()->getOneOrNullResult();
        return isset($row['agreement']) ? (int)$row['agreement'] : null;
    }

    public function ruleIds(string $field, int $agreement): array
    {
        $rows = $this->em->createQueryBuilder()->select('DISTINCT IDENTITY(a.rules) AS rule_id')->from(Entity\RuleAction::class, 'a')
            ->where('a.field = :field AND a.value = :value')->setParameter('field', $field, Types::STRING)
            ->setParameter('value', (string)$agreement, Types::STRING)->orderBy('rule_id')->getQuery()->getScalarResult();
        return array_map('intval', array_column($rows, 'rule_id'));
    }
}
