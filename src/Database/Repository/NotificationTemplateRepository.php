<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
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

    /** Locale selection does not need subjects or message bodies. */
    public function usedLanguages(int $template): array
    {
        $languages = [];
        // Keep PHP's exact string keys and duplicate folding. SQL DISTINCT could
        // merge differently cased locale strings under native collation. Neither
        // this query nor the full translation API specifies an ordering.
        foreach ($this->translationsQuery($template)->select('t.language')
            ->getQuery()->getSingleColumnResult() as $language) {
            $languages[$language] = $language;
        }
        return $languages;
    }

    private function translationsQuery(int $template): QueryBuilder
    {
        return $this->em->createQueryBuilder()->select('t')->from(NotificationTemplateTranslation::class, 't')
            ->where('IDENTITY(t.notificationtemplates) = :template')->setParameter('template', $template, Types::BIGINT);
    }
}
