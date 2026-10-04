<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\NotificationTemplateTranslation;

/** Translation ownership and locale selection; no HTML cleaning or lifecycle writes. */
final class NotificationTemplateRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    public function preferredTranslation(int $template, ?string $language): ?NotificationTemplateTranslation
    {
        // The schema permits duplicate locales. Preserve native comparison and
        // the existing language DESC/first-row policy, without inventing uniqueness.
        return $this->translationsQuery($template)->andWhere('t.language IN (:languages)')
            ->setParameter('languages', [$language, ''])->orderBy('t.language', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** @return list<NotificationTemplateTranslation> */
    public function translations(int $template): array
    {
        return $this->translationsQuery($template)->getQuery()->getResult();
    }

    private function translationsQuery(int $template): \Doctrine\ORM\QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('t')->from(NotificationTemplateTranslation::class, 't')
            ->where('IDENTITY(t.notificationtemplates) = :template')->setParameter('template', $template, Types::BIGINT);
    }
}
