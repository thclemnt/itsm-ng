<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use DBAdapter;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Orm;
use itsmng\Database\Entity\NotificationTemplateTranslation;
use itsmng\Database\Repository\NotificationTemplateRepository;
use itsmng\Database\Repository\RecordRepository;

/** Locale fallback selects content without changing nullable bodies or rendering state. */
final class NotificationTemplateService
{
    public function __construct(private DBAdapter $database)
    {
    }

    public function contentForLanguage(int $template, ?string $language): ?NotificationTemplateContent
    {
        return Orm::read($this->database, function (EntityManager $manager) use ($template, $language): ?NotificationTemplateContent {
            $translation = (new NotificationTemplateRepository($manager))->preferredTranslation($template, $language);
            return $translation === null ? null : $this->content($manager, $translation);
        });
    }

    /** @return list<NotificationTemplateContent> */
    public function translations(int $template): array
    {
        return Orm::read($this->database, fn (EntityManager $manager): array => array_map(
            fn (NotificationTemplateTranslation $translation): NotificationTemplateContent => $this->content($manager, $translation),
            (new NotificationTemplateRepository($manager))->translations($template)
        ));
    }

    public function usedLanguages(int $template): array
    {
        return Orm::read(
            $this->database,
            static fn (EntityManager $manager): array => (new NotificationTemplateRepository($manager))->usedLanguages($template),
            clearCustomManager: true
        );
    }

    private function content(EntityManager $em, NotificationTemplateTranslation $translation): NotificationTemplateContent
    {
        $row = (new RecordRepository($em))->toRow($translation);
        return new NotificationTemplateContent(
            $translation->id,
            $row['notificationtemplates_id'],
            $translation->language,
            $translation->subject,
            $translation->content_text,
            $translation->content_html
        );
    }
}
