<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use InvalidArgumentException;
use itsmng\Database\Entity;
use itsmng\Database\EntityRegistry;
use itsmng\Database\MappedRowProjection;
use itsmng\Database\RecordCriteria;
use itsmng\Database\SharedContentAccess;

/** Personal content and sharing predicates without row-multiplying audience joins. */
final class SharedContentRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Complete audience rows grouped by group identity, in link-ID order. */
    public function rssfeedGroups(mixed $rssfeed, ?string $table = null): array
    {
        return $this->rssfeedAudience(Entity\GroupRSSFeed::class, 'groups_id', $rssfeed, $table);
    }

    /** Complete audience rows grouped by profile identity, in link-ID order. */
    public function rssfeedProfiles(mixed $rssfeed, ?string $table = null): array
    {
        return $this->rssfeedAudience(Entity\ProfileRSSFeed::class, 'profiles_id', $rssfeed, $table);
    }

    private function rssfeedAudience(string $class, string $audienceKey, mixed $rssfeed, ?string $table): array
    {
        // Legacy forceTable/subclass routes still resolve their registered mapping.
        if ($table !== null) {
            $class = EntityRegistry::tables()[$table];
        }
        $metadata = $this->em->getClassMetadata($class);
        $query = $this->em->createQueryBuilder()->select('r')->from($class, 'r');
        // Keep the public helpers' existing NULL, scalar and structured-criteria language.
        $criteria = new RecordCriteria($query, $metadata);
        if (($rejection = $criteria->applyMatching(['rssfeeds_id' => $rssfeed], 'id')) !== null) {
            throw $rejection;
        }
        $audience = [];
        // Custom identity maps and post-load dispatch keep ordinary entity hydration.
        if ($this->em->getUnitOfWork()->size() === 0
            && count($metadata->identifier) === 1
            && $metadata->hasField($metadata->getSingleIdentifierFieldName())
            && !$metadata->hasLifecycleCallbacks(Events::postLoad)
            && empty($metadata->entityListeners[Events::postLoad])
            && !$this->em->getEventManager()->hasListeners(Events::postLoad)) {
            $projection = new MappedRowProjection($this->em, $metadata);
            $projection->select($query);
            foreach ($query->getQuery()->toIterable([], Query::HYDRATE_ARRAY) as $values) {
                $row = $projection->toRow($values);
                $audience[$row[$audienceKey]][] = $row;
            }
        } else {
            $records = new RecordRepository($this->em);
            foreach ($query->getQuery()->toIterable() as $record) {
                $row = $records->toRow($record);
                $audience[$row[$audienceKey]][] = $row;
            }
        }
        return $audience;
    }

    public function listing(string $kind, SharedContentAccess $access, bool $personal, bool $excludeOwned, DateTimeImmutable $now, ?string $language = null): array
    {
        $class = $this->contentClass($kind);
        if ($access->user <= 0) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('r')->from($class, 'r')
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
        [$user, $userParent] = $this->audience($kind, 'audienceUsers');
        [$group, $groupParent] = $this->audience($kind, 'audienceGroups');
        [$profile, $profileParent] = $this->audience($kind, 'audienceProfiles');
        [$entity, $entityParent] = $this->audience($kind, 'audienceEntities');
        $query->setParameter('yes', true, Types::BOOLEAN)->setParameter('entities', $access->entities ?: [-1])
            ->setParameter('ancestors', $access->ancestors ?: [-1]);
        $scope = static fn (string $field, string $alias): string => '(' . $field . ' IN (:entities) OR (' . $alias . '.is_recursive = :yes AND ' . $field . ' IN (:ancestors)))';
        $conditions = ['IDENTITY(r.users) = :viewer',
            'EXISTS (SELECT u.id FROM ' . $user . ' u WHERE IDENTITY(u.' . $userParent . ') = r.id AND IDENTITY(u.users) = :viewer)'];
        if ($access->groups) {
            $query->setParameter('groups', $access->groups);
            $conditions[] = 'EXISTS (SELECT g.id FROM ' . $group . ' g WHERE IDENTITY(g.' . $groupParent . ') = r.id AND IDENTITY(g.groups) IN (:groups) AND (IDENTITY(g.entities) IS NULL OR ' . $scope('IDENTITY(g.entities)', 'g') . '))';
        }
        if ($access->profile > 0) {
            $query->setParameter('profile', $access->profile, Types::INTEGER);
            $conditions[] = 'EXISTS (SELECT p.id FROM ' . $profile . ' p WHERE IDENTITY(p.' . $profileParent . ') = r.id AND IDENTITY(p.profiles) = :profile AND (IDENTITY(p.entities) IS NULL OR ' . $scope('IDENTITY(p.entities)', 'p') . '))';
        }
        $conditions[] = 'EXISTS (SELECT e.id FROM ' . $entity . ' e WHERE IDENTITY(e.' . $entityParent . ') = r.id AND ' . $scope('IDENTITY(e.entities)', 'e') . ')';
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

    public function expiredReminders(DateTimeImmutable $before): array
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
        }
        return $rows;
    }

    private function contentClass(string $kind): string
    {
        return match ($kind) {
            'reminder' => Entity\Reminder::class,
            'rssfeed' => Entity\RSSFeed::class,
            default => throw new InvalidArgumentException('Unsupported shared content kind'),
        };
    }

    /** The entity's inverse association identifies its owning audience link. */
    private function audience(string $kind, string $property): array
    {
        $mapping = $this->em->getClassMetadata($this->contentClass($kind))->getAssociationMapping($property);
        return [$mapping->targetEntity, $mapping->mappedBy];
    }
}
