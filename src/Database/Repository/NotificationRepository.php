<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Notification;
use itsmng\Database\Entity\NotificationNotificationTemplate;
use itsmng\Database\RecordCriteria;

/** Rule aggregates and their owning delivery bindings on the supplied connection. */
final class NotificationRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /**
     * The event target supplies scope, rather than the interactive user's scope.
     * Fetching bindings preserves each delivery and the legacy unbound-rule case.
     * The domain service supplies a fresh read unit for this selection; these
     * filtered collections must not be reused as writable aggregates.
     *
     * @return list<Notification>
     */
    public function firing(string $event, string $itemtype, array $entityScope, array $enabledModes): array
    {
        $query = $this->em->createQueryBuilder()->select('r', 'binding')->from(Notification::class, 'r')
            ->leftJoin('r.entities', 'entity')->leftJoin('r.templateBindings', 'binding')
            ->where('r.event = :event AND r.itemtype = :itemtype AND r.is_active = :active')
            ->setParameter('event', $event)->setParameter('itemtype', $itemtype)
            ->setParameter('active', true, Types::BOOLEAN)->orderBy('entity.level', 'DESC');
        $restriction = (new RecordCriteria($query, $this->em->getClassMetadata(Notification::class)))->where($entityScope);
        if ($restriction !== '') {
            $query->andWhere($restriction);
        }
        // Retain native mode collation. With no enabled modes the public legacy
        // selector intentionally has no mode filter, including unbound rules.
        if ($enabledModes) {
            $query->andWhere('binding.mode IN (:modes)')->setParameter('modes', array_values($enabledModes));
        }
        return $query->getQuery()->getResult();
    }

    /** @return list<NotificationNotificationTemplate> */
    public function bindingsForNotification(int $notification): array
    {
        return $this->em->createQueryBuilder()->select('b')->from(NotificationNotificationTemplate::class, 'b')
            ->where('IDENTITY(b.notifications) = :notification')->setParameter('notification', $notification, Types::BIGINT)
            ->getQuery()->getResult();
    }

    /** @return list<NotificationNotificationTemplate> */
    public function bindingsForTemplate(int $template): array
    {
        return $this->em->createQueryBuilder()->select('b')->from(NotificationNotificationTemplate::class, 'b')
            ->where('IDENTITY(b.notificationtemplates) = :template')->setParameter('template', $template, Types::BIGINT)
            ->getQuery()->getResult();
    }
}
