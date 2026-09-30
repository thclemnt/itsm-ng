<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity;
use itsmng\Database\SharedContentAccess;

/** Personal content and sharing predicates without row-multiplying audience joins. */
final class SharedContentRepository
{
    private const KINDS = [
        'reminder' => [Entity\Reminder::class, 'reminders', Entity\ReminderUser::class, Entity\GroupReminder::class, Entity\ProfileReminder::class, Entity\EntityReminder::class],
        'rssfeed' => [Entity\RSSFeed::class, 'rssfeeds', Entity\RSSFeedUser::class, Entity\GroupRSSFeed::class, Entity\ProfileRSSFeed::class, Entity\EntityRSSFeed::class],
    ];

    public function __construct(private EntityManager $em)
    {
    }

    public function listing(string $kind, SharedContentAccess $access, bool $personal, bool $excludeOwned, \DateTimeImmutable $now, ?string $language = null): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException('Unsupported shared content kind');
        }
        if ($access->user <= 0) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('r')->from(self::KINDS[$kind][0], 'r')
            ->setParameter('viewer', $access->user, Types::INTEGER)->orderBy('r.name')->addOrderBy('r.id');
        if ($personal || !$access->readPublic) {
            $query->where('IDENTITY(r.users) = :viewer');
        } else {
            $this->visibility($query, $kind, $access);
        }
        if (!$personal && $excludeOwned) {
            $query->andWhere('(r.users IS NULL OR IDENTITY(r.users) <> :viewer)');
        }
        if ($kind === 'reminder') {
            $query->andWhere('(r.begin_view_date IS NULL OR r.begin_view_date < :now)')
                ->andWhere('(r.end_view_date IS NULL OR r.end_view_date > :now)')
                ->setParameter('now', $now, Types::DATETIMETZ_IMMUTABLE);
            if ($personal) {
                $query->andWhere('(r.is_planned = :unplanned OR r.end >= :today)')
                    ->setParameter('unplanned', false, Types::BOOLEAN)
                    ->setParameter('today', $now->setTime(0, 0), Types::DATETIMETZ_IMMUTABLE);
            }
            if ($language !== null) {
                // Legacy data can contain duplicate translations; consistently choose the first.
                $query->addSelect('translation.name AS transname', 'translation.text AS transtext')
                    ->leftJoin(Entity\ReminderTranslation::class, 'translation', 'WITH', 'translation.id = (SELECT MIN(t.id) FROM ' . Entity\ReminderTranslation::class . ' t WHERE IDENTITY(t.reminders) = r.id AND t.language = :language)')
                    ->setParameter('language', $language);
            }
        } elseif ($personal) {
            $query->andWhere('r.is_active = :active')->setParameter('active', true, Types::BOOLEAN);
        }
        return $this->rows($query);
    }

    public function reminderHasDocument(int $document, SharedContentAccess $access): bool
    {
        if ($access->user <= 0) {
            return false;
        }
        $query = $this->em->createQueryBuilder()->select('r.id')->from(Entity\Reminder::class, 'r')
            ->setParameter('viewer', $access->user, Types::INTEGER);
        if ($access->readPublic) {
            $this->visibility($query, 'reminder', $access);
        } else {
            $query->where('IDENTITY(r.users) = :viewer');
        }
        return $query->andWhere("EXISTS (SELECT d.id FROM " . Entity\DocumentItem::class . " d WHERE IDENTITY(d.documents) = :document AND d.itemtype = 'Reminder' AND d.items_id = r.id)")
            ->setParameter('document', $document, Types::INTEGER)->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }

    private function visibility(QueryBuilder $query, string $kind, SharedContentAccess $access): void
    {
        [, $parent, $user, $group, $profile, $entity] = self::KINDS[$kind];
        $query->setParameter('yes', true, Types::BOOLEAN)->setParameter('entities', $access->entities ?: [-1])
            ->setParameter('ancestors', $access->ancestors ?: [-1]);
        $scope = static fn (string $field, string $alias): string => '(' . $field . ' IN (:entities) OR (' . $alias . '.is_recursive = :yes AND ' . $field . ' IN (:ancestors)))';
        $conditions = ['IDENTITY(r.users) = :viewer',
            'EXISTS (SELECT u.id FROM ' . $user . ' u WHERE IDENTITY(u.' . $parent . ') = r.id AND IDENTITY(u.users) = :viewer)'];
        if ($access->groups) {
            $query->setParameter('groups', $access->groups);
            $conditions[] = 'EXISTS (SELECT g.id FROM ' . $group . ' g WHERE IDENTITY(g.' . $parent . ') = r.id AND IDENTITY(g.groups) IN (:groups) AND (IDENTITY(g.entities) IS NULL OR ' . $scope('IDENTITY(g.entities)', 'g') . '))';
        }
        if ($access->profile > 0) {
            $query->setParameter('profile', $access->profile, Types::INTEGER);
            $conditions[] = 'EXISTS (SELECT p.id FROM ' . $profile . ' p WHERE IDENTITY(p.' . $parent . ') = r.id AND IDENTITY(p.profiles) = :profile AND (IDENTITY(p.entities) IS NULL OR ' . $scope('IDENTITY(p.entities)', 'p') . '))';
        }
        $conditions[] = 'EXISTS (SELECT e.id FROM ' . $entity . ' e WHERE IDENTITY(e.' . $parent . ') = r.id AND ' . $scope('IDENTITY(e.entities)', 'e') . ')';
        $query->where('(' . implode(' OR ', $conditions) . ')');
    }

    /** Caller still checks each reminder's visibility when producing VCalendar objects. */
    public function calendarReminders(?int $user = null, ?int $group = null): array
    {
        if (($user ?? 0) <= 0 && ($group ?? 0) <= 0) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('r')->from(Entity\Reminder::class, 'r')->orderBy('r.id');
        if ($group !== null) {
            $query->where('EXISTS (SELECT g.id FROM ' . Entity\GroupReminder::class . ' g WHERE IDENTITY(g.reminders) = r.id AND IDENTITY(g.groups) = :group)')->setParameter('group', $group, Types::INTEGER);
        } else {
            $query->where('IDENTITY(r.users) = :user')->setParameter('user', $user, Types::INTEGER);
        }
        return $this->rows($query);
    }

    public function expiredReminders(\DateTimeImmutable $before): array
    {
        return $this->em->createQueryBuilder()->select('r.id')->from(Entity\Reminder::class, 'r')
            ->where('r.end_view_date < :before OR (r.end_view_date IS NULL AND r.is_planned = :planned AND r.end < :before)')
            ->setParameter('before', $before, Types::DATETIMETZ_IMMUTABLE)->setParameter('planned', true, Types::BOOLEAN)
            ->orderBy('r.id')->getQuery()->getScalarResult();
    }

    public function translatedLanguages(int $reminder): array
    {
        $rows = $this->em->createQueryBuilder()->select('DISTINCT t.language')->from(Entity\ReminderTranslation::class, 't')
            ->where('IDENTITY(t.reminders) = :reminder')->setParameter('reminder', $reminder, Types::INTEGER)
            ->orderBy('t.language')->getQuery()->getScalarResult();
        $languages = array_column($rows, 'language');
        return array_combine($languages, $languages);
    }

    private function rows(QueryBuilder $query): array
    {
        $records = new RecordRepository($this->em);
        $rows = [];
        foreach ($query->getQuery()->toIterable() as $result) {
            $record = is_array($result) ? $result[0] : $result;
            $row = $records->toRow($record);
            if (is_array($result)) {
                unset($result[0]);
                $row += $result;
            }
            $rows[] = $row;
            $this->em->detach($record);
        }
        return $rows;
    }
}
