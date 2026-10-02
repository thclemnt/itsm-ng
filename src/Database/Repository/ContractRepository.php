<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\Alert;
use itsmng\Database\Entity\Contract;
use itsmng\Database\Entity\ContractItem;
use itsmng\Database\Entity\ContractSupplier;
use itsmng\Database\Entity\DropdownTranslation;
use itsmng\Database\RecordCriteria;

/** Contract deadlines and associations; public models retain their lifecycle and UI policy. */
final class ContractRepository extends DropdownChoiceRepository
{
    /** Exact legacy dashboard day buckets, evaluated together on the supplied connection. */
    public function deadlineCounts(array $scope, ?\DateTimeImmutable $today = null): array
    {
        $query = $this->createQueryBuilder('r')->where('r.is_deleted = :deleted')->setParameter('deleted', false, Types::BOOLEAN);
        $query->andWhere((new RecordCriteria($query, $this->getClassMetadata()))->where($scope));
        $date = $this->today($query, $today);
        $end = "DATE_DIFF(DATE_ADD(r.begin_date, r.duration, 'month'), " . $date . ')';
        $notice = "DATE_DIFF(DATE_ADD(r.begin_date, (r.duration - r.notice), 'month'), " . $date . ')';
        $query->select(
            'SUM(CASE WHEN ' . $end . ' > -30 AND ' . $end . ' < 0 THEN 1 ELSE 0 END) AS expired',
            'SUM(CASE WHEN ' . $end . ' > 0 AND ' . $end . ' <= 7 THEN 1 ELSE 0 END) AS ending7',
            'SUM(CASE WHEN ' . $end . ' > 7 AND ' . $end . ' < 30 THEN 1 ELSE 0 END) AS ending30',
            'SUM(CASE WHEN r.notice <> 0 AND ' . $notice . ' > 0 AND ' . $notice . ' <= 7 THEN 1 ELSE 0 END) AS notice7',
            'SUM(CASE WHEN r.notice <> 0 AND ' . $notice . ' > 7 AND ' . $notice . ' < 30 THEN 1 ELSE 0 END) AS notice30',
        );
        return array_map('intval', $query->getQuery()->getSingleResult());
    }

    /** Typed owning Alert branches keep other events and overlapping item identities separate. */
    public function notificationCandidates(int $entity, int $event, int $daysBefore, ?\DateTimeImmutable $today = null): array
    {
        if (!in_array($event, [\Alert::END, \Alert::NOTICE], true)) {
            throw new \InvalidArgumentException('Unsupported contract deadline event');
        }
        $query = $this->createQueryBuilder('r')
            ->leftJoin(Alert::class, 'a', 'WITH', 'a.contract = r AND a.type = :event')
            ->where('IDENTITY(r.entities) = :entity AND r.is_deleted = :deleted AND r.begin_date IS NOT NULL')
            ->andWhere('r.duration <> 0 AND BIT_AND(r.alert, :mask) > 0 AND a.id IS NULL')
            ->setParameter('entity', $entity, Types::BIGINT)->setParameter('event', $event, Types::INTEGER)
            ->setParameter('deleted', false, Types::BOOLEAN)->setParameter('mask', 1 << $event, Types::INTEGER)
            ->setParameter('days', $daysBefore, Types::INTEGER)->orderBy('r.id');
        $date = $this->today($query, $today);
        $end = "DATE_DIFF(DATE_ADD(r.begin_date, r.duration, 'month'), " . $date . ')';
        if ($event === \Alert::NOTICE) {
            $query->andWhere('r.notice <> 0 AND ' . $end . ' > 0')
                ->andWhere("DATE_DIFF(DATE_ADD(r.begin_date, (r.duration - r.notice), 'month'), " . $date . ') < :days');
        } else {
            $query->andWhere($end . ' < :days');
        }
        return $this->rows($query);
    }

    /** Load previous owned events with each periodic contract, without per-contract alert queries. */
    public function periodicContracts(int $entity): array
    {
        $query = $this->createQueryBuilder('r')->addSelect('period.date AS last_period', 'notice.date AS last_notice')
            ->leftJoin(Alert::class, 'period', 'WITH', 'period.contract = r AND period.type = :period')
            ->leftJoin(Alert::class, 'notice', 'WITH', 'notice.contract = r AND notice.type = :notice')
            ->where('IDENTITY(r.entities) = :entity AND r.is_deleted = :deleted AND BIT_AND(r.alert, :mask) > 0')
            ->andWhere('r.begin_date IS NOT NULL AND r.periodicity > 0')
            ->setParameter('entity', $entity, Types::BIGINT)->setParameter('deleted', false, Types::BOOLEAN)
            ->setParameter('mask', 1 << \Alert::PERIODICITY, Types::INTEGER)
            ->setParameter('period', \Alert::PERIODICITY, Types::INTEGER)->setParameter('notice', \Alert::NOTICE, Types::INTEGER)
            ->orderBy('r.id');
        return $this->rows($query);
    }

    /** Every binding counts toward the contractual maximum, independent of subject visibility. */
    public function availableForConnection(array $scope, bool $expired, array $used, bool $ignoreLimit, ?\DateTimeImmutable $today = null, ?string $language = null): array
    {
        $query = $this->createQueryBuilder('r')->leftJoin('r.entities', 'e')
            ->where('r.is_deleted = :deleted AND r.is_template = :deleted')->setParameter('deleted', false, Types::BOOLEAN);
        $query->andWhere((new RecordCriteria($query, $this->getClassMetadata()))->where($scope));
        if ($language !== null) {
            $query->leftJoin(DropdownTranslation::class, 'translation', 'WITH', 'translation.items_id = r.id AND translation.itemtype = :kind AND translation.language = :language AND translation.field = :field')
                ->addSelect('translation.value AS translatedName')->setParameter('kind', 'Contract', Types::STRING)
                ->setParameter('field', 'name', Types::STRING)->setParameter('language', $language, Types::STRING);
        }
        if ($used) {
            $query->andWhere('r.id NOT IN (:used)')->setParameter('used', array_values(array_map('intval', $used)));
        }
        if (!$expired) {
            $query->andWhere("r.renewal = :automatic OR r.begin_date IS NULL OR DATE_DIFF(DATE_ADD(r.begin_date, r.duration, 'month'), " . $this->today($query, $today) . ') > 0')
                ->setParameter('automatic', \Contract::RENEWAL_TACIT, Types::INTEGER);
        }
        if (!$ignoreLimit) {
            $query->andWhere('r.max_links_allowed = 0 OR r.max_links_allowed > (SELECT COUNT(binding.id) FROM ' . ContractItem::class . ' binding WHERE binding.contracts = r)');
        }
        $query->addSelect('CASE WHEN e.completename IS NULL THEN 0 ELSE 1 END AS HIDDEN entityNull')
            ->addSelect('CASE WHEN r.name IS NULL THEN 0 ELSE 1 END AS HIDDEN nameNull')
            ->addSelect('CASE WHEN r.begin_date IS NULL THEN 1 ELSE 0 END AS HIDDEN dateNull')
            ->orderBy('entityNull')->addOrderBy('e.completename')->addOrderBy('nameNull')->addOrderBy('r.name')
            ->addOrderBy('dateNull')->addOrderBy('r.begin_date', 'DESC')->addOrderBy('r.id');
        return $this->rows($query);
    }

    public function supplierNames(int $contract, ?string $language = null): array
    {
        $query = $this->getEntityManager()->createQueryBuilder()->select('s.name AS name')
            ->from(ContractSupplier::class, 'binding')->innerJoin('binding.suppliers', 's')
            ->where('IDENTITY(binding.contracts) = :contract')->setParameter('contract', $contract, Types::BIGINT)
            ->orderBy('binding.id');
        if ($language !== null) {
            $query->leftJoin(DropdownTranslation::class, 'translation', 'WITH', 'translation.items_id = s.id AND translation.itemtype = :kind AND translation.language = :language AND translation.field = :field')
                ->addSelect('translation.value AS translated')->setParameter('kind', 'Supplier', Types::STRING)
                ->setParameter('field', 'name', Types::STRING)->setParameter('language', $language, Types::STRING);
        }
        return array_map(static fn (array $row): string => !empty($row['translated']) ? $row['translated'] : ($row['name'] ?? ''), $query->getQuery()->getScalarResult());
    }

    private function today(QueryBuilder $query, ?\DateTimeImmutable $today): string
    {
        if ($today === null) {
            return 'CURRENT_DATE()';
        }
        $query->setParameter('today', $today, Types::DATE_IMMUTABLE);
        return ':today';
    }

    private function rows(QueryBuilder $query): array
    {
        $records = new RecordRepository($this->getEntityManager());
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $record = $result instanceof Contract ? $result : $result[0];
            $extra = $result instanceof Contract ? [] : array_diff_key($result, [0 => true]);
            $rows[] = $records->toRow($record) + $extra;
            $this->getEntityManager()->detach($record);
        }
        return $rows;
    }
}
