<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use itsmng\Database\Orm;
use itsmng\Database\Entity\NotificationTemplateTranslation;
use itsmng\Database\Repository\NotificationTemplateRepository;
use itsmng\Database\Repository\RecordRepository;

/** Locale fallback selects content without changing nullable bodies or rendering state. */
final class NotificationTemplateService
{
    public function __construct(private \DBAdapter $database)
    {
    }

    public function contentForLanguage(int $template, ?string $language): ?NotificationTemplateContent
    {
        $em = Orm::create($this->database);
        $translation = (new NotificationTemplateRepository($em))->preferredTranslation($template, $language);
        return $translation === null ? null : $this->content($em, $translation);
    }

    /** @return list<NotificationTemplateContent> */
    public function translations(int $template): array
    {
        $em = Orm::create($this->database);
        return array_map(
            fn (NotificationTemplateTranslation $translation): NotificationTemplateContent => $this->content($em, $translation),
            (new NotificationTemplateRepository($em))->translations($template)
        );
    }

    public function usedLanguages(int $template): array
    {
        $languages = [];
        foreach ($this->translations($template) as $translation) {
            $languages[$translation->language] = $translation->language;
        }
        return $languages;
    }

    private function content(\Doctrine\ORM\EntityManager $em, NotificationTemplateTranslation $translation): NotificationTemplateContent
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
