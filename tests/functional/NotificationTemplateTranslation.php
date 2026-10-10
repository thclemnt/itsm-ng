<?php

/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2022 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
*/

namespace tests\units;

use DbTestCase;
use Doctrine\DBAL\Types\Types;
use NotificationTemplate as LegacyNotificationTemplate;
use ReflectionProperty;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Query;
use NotificationTemplateTranslation as LegacyNotificationTemplateTranslation;
use itsmng\Database\Entity\NotificationTemplate;
use itsmng\Database\Entity\NotificationTemplateTranslation as NotificationTemplateTranslationEntity;
use itsmng\Database\Orm;
use itsmng\Database\Repository\NotificationTemplateRepository;
use itsmng\Domain\NotificationTemplateContent;
use itsmng\Domain\NotificationTemplateService;

/* Test for inc/notificationtemplatetranslation.class.php */

class NotificationTemplateTranslation extends DbTestCase
{
    public function testUsedLanguagesAvoidsLoadingMessageBodies(): void
    {
        global $DB;

        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $em = new class ($connection, Orm::configuration($connection->getDatabasePlatform())) extends EntityManager {
            public array $queries = [];

            public function createQuery(string $dql = ''): Query
            {
                return $this->queries[] = parent::createQuery($dql);
            }
        };
        $listener = new class () {
            public int $loaded = 0;

            public function postLoad(): void
            {
                ++$this->loaded;
            }
        };
        $em->getEventManager()->addEventListener([Events::postLoad], $listener);
        try {
            $template = new NotificationTemplate();
            $template->name = 'Language projection ' . $this->getUniqueString();
            $template->itemtype = 'Ticket';
            $other = new NotificationTemplate();
            $other->name = 'Other language projection ' . $this->getUniqueString();
            $other->itemtype = 'Ticket';
            $em->persist($template);
            $em->persist($other);
            $translations = [];
            foreach (['', 'fr_FR', 'fr_FR', 'FR_fr', 'ja_JP'] as $language) {
                $translation = new NotificationTemplateTranslationEntity();
                $translation->notificationtemplates = $template;
                $translation->language = $language;
                $translation->subject = 'Untouched subject';
                $translation->content_text = str_repeat('Text body ', 1024);
                $translation->content_html = '<p>' . str_repeat('HTML body ', 1024) . '</p>';
                $translations[] = $translation;
                $em->persist($translation);
            }
            $otherTranslation = new NotificationTemplateTranslationEntity();
            $otherTranslation->notificationtemplates = $other;
            $otherTranslation->language = 'en_GB';
            $em->persist($otherTranslation);
            $em->flush();
            $templateId = $template->id;
            $japaneseId = $translations[4]->id;
            $em->clear();
            $repository = new NotificationTemplateRepository($em);

            $languages = $repository->usedLanguages($templateId);
            $this->array($languages)->hasSize(4)->hasKey('')->hasKey('fr_FR')->hasKey('FR_fr')->hasKey('ja_JP');
            foreach ($languages as $key => $language) {
                $this->string($language)->isIdenticalTo($key);
            }
            $this->integer($listener->loaded)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
            $this->integer(count($em->queries))->isIdenticalTo(1);
            $this->string($em->queries[0]->getSQL())->notContains('content_text')->notContains('content_html')->notContains('subject');
            $this->array($repository->usedLanguages(-1))->isEmpty();
            $this->array($repository->usedLanguages($other->id))->isIdenticalTo(['en_GB' => 'en_GB']);

            // Neither query specifies an order. Compare exact locale entries,
            // including duplicate/default folding, independently of the scan plan.
            $assertLanguages = function (array $actual, array $expected): void {
                ksort($actual);
                ksort($expected);
                $this->array($actual)->isIdenticalTo($expected);
            };

            // Positive hydration control and parity with the unchanged full API.
            $full = $repository->translations($templateId);
            $this->integer($listener->loaded)->isGreaterThan(0);
            $fullLanguages = [];
            foreach ($full as $translation) {
                $fullLanguages[$translation->language] = $translation->language;
            }
            $assertLanguages($languages, $fullLanguages);
            $this->string($full[0]->subject)->isIdenticalTo('Untouched subject');
            $this->string($full[0]->content_text)->contains('Text body');
            $assertLanguages(LegacyNotificationTemplateTranslation::getAllUsedLanguages($templateId), $languages);

            $managed = $em->find(NotificationTemplateTranslationEntity::class, $japaneseId);
            $before = $listener->loaded;
            $connection->update('glpi_notificationtemplatetranslations', ['language' => 'de_DE'], ['id' => $japaneseId]);
            $expected = $languages;
            unset($expected['ja_JP']);
            $expected['de_DE'] = 'de_DE';
            $assertLanguages($repository->usedLanguages($templateId), $expected);
            $this->integer($listener->loaded)->isIdenticalTo($before);
            $this->string($managed->language)->isIdenticalTo('ja_JP');
            $assertLanguages(LegacyNotificationTemplateTranslation::getAllUsedLanguages($templateId), $expected);
            $this->object($em->getConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $em->clear();
        }
    }

    public function testTemplateContentReadsMaterializeCurrentValuesAndReuseTheirManager(): void
    {
        global $DB;
        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $writer = Orm::create($DB);
        try {
            $template = new NotificationTemplate();
            $template->name = 'Scoped content ' . $this->getUniqueString();
            $template->itemtype = 'Ticket';
            $writer->persist($template);
            $translations = [];
            foreach (['', 'fr_FR', 'fr_FR', 'FR_fr', 'ja_JP'] as $index => $language) {
                $translation = new NotificationTemplateTranslationEntity();
                $translation->notificationtemplates = $template;
                $translation->language = $language;
                $translation->subject = 'Subject ' . $index;
                $translation->content_text = $index === 0 ? null : 'Text ' . $index;
                $translation->content_html = $index === 0 ? null : '<p>HTML ' . $index . '</p>';
                $translations[] = $translation;
                $writer->persist($translation);
            }
            $writer->flush();
            $service = new NotificationTemplateService($DB);
            $all = $service->translations($template->id);
            $this->array($all)->hasSize(5);
            foreach ($all as $content) {
                $this->object($content)->isInstanceOf(NotificationTemplateContent::class);
            }
            $expectedIds = array_map(static fn (NotificationTemplateTranslationEntity $translation): int => $translation->id, $translations);
            $actualIds = array_column($all, 'id');
            sort($expectedIds);
            sort($actualIds);
            $this->array($actualIds)->isIdenticalTo($expectedIds);
            $fallback = $service->contentForLanguage($template->id, 'zz_ZZ');
            $this->integer($fallback->id)->isIdenticalTo($translations[0]->id);
            $this->variable($fallback->text)->isNull();
            $this->variable($fallback->html)->isNull();
            $this->integer($service->contentForLanguage($template->id, null)->id)->isIdenticalTo($translations[0]->id);
            $preferred = $service->contentForLanguage($template->id, 'fr_FR');
            // Native case-insensitive collations may also select the differently cased locale.
            $this->boolean(in_array($preferred->language, ['fr_FR', 'FR_fr'], true))->isTrue();
            $this->boolean(in_array($preferred->id, [$translations[1]->id, $translations[2]->id, $translations[3]->id], true))->isTrue();
            $languages = $service->usedLanguages($template->id);
            $expectedLanguages = ['' => '', 'fr_FR' => 'fr_FR', 'FR_fr' => 'FR_fr', 'ja_JP' => 'ja_JP'];
            ksort($languages);
            ksort($expectedLanguages);
            $this->array($languages)->isIdenticalTo($expectedLanguages);
            $this->variable($service->contentForLanguage(-1, 'fr_FR'))->isNull();
            $this->array($service->translations(-1))->isEmpty();
            $this->array($service->usedLanguages(-1))->isEmpty();
            $legacy = new LegacyNotificationTemplate();
            $this->boolean($legacy->getFromDB($template->id))->isTrue();
            $this->array($legacy->getByLanguage('zz_ZZ'))->isIdenticalTo($fallback->legacyRow());
            $managed = $translations[0];
            $currentSubject = "Current O'Reilly \ body";
            $this->integer($connection->update('glpi_notificationtemplatetranslations', [
                'subject' => $currentSubject, 'content_text' => 'Current text', 'content_html' => null,
            ], ['id' => $managed->id], [
                'subject' => Types::STRING, 'content_text' => Types::TEXT, 'content_html' => Types::TEXT, 'id' => Types::BIGINT,
            ]))->isIdenticalTo(1);
            $creations = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $beforeCreations = $creations->getValue();
            for ($repeat = 0; $repeat < 2; ++$repeat) {
                $current = $service->contentForLanguage($template->id, 'zz_ZZ');
                $this->string($current->subject)->isIdenticalTo($currentSubject);
                $this->string($current->text)->isIdenticalTo('Current text');
                $this->variable($current->html)->isNull();
                $this->array($legacy->getByLanguage('zz_ZZ'))->isIdenticalTo($current->legacyRow());
                $rows = array_column($service->translations($template->id), null, 'id');
                $this->string($rows[$managed->id]->subject)->isIdenticalTo($currentSubject);
                $currentLanguages = $service->usedLanguages($template->id);
                ksort($currentLanguages);
                $this->array($currentLanguages)->isIdenticalTo($expectedLanguages);
            }
            $this->integer($creations->getValue() - $beforeCreations)->isIdenticalTo(
                0,
                'Template content, translations and locale choices share the completed canonical read manager'
            );
            $this->string($fallback->subject)->isIdenticalTo('Subject 0');
            $this->string($managed->subject)->isIdenticalTo('Subject 0');
            $this->boolean($writer->contains($managed))->isTrue();
            Orm::withConnection($connection, function (EntityManager $parent) use ($service, $template, $managed): void {
                $reference = $parent->getReference(NotificationTemplateTranslationEntity::class, $managed->id);
                $this->boolean($parent->contains($reference))->isTrue();
                $this->object($service->contentForLanguage($template->id, 'zz_ZZ'))->isInstanceOf(NotificationTemplateContent::class);
                $this->boolean($parent->contains($reference))->isTrue();
            });
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $writer->clear();
        }
    }

    public function testClone()
    {
        global $DB;

        $iterator = $DB->request([
           'SELECT' => 'id',
           'FROM'   => \NotificationTemplateTranslation::getTable(),
           'LIMIT'  => 1
        ]);

        $data = $iterator->next();
        $translation = new \NotificationTemplateTranslation();
        $translation->getFromDB($data['id']);
        $added = $translation->clone();
        $this->integer((int)$added)->isGreaterThan(0);

        $clonedTranslation = new \NotificationTemplateTranslation();
        $this->boolean($clonedTranslation->getFromDB($added))->isTrue();

        unset($translation->fields['id']);
        unset($clonedTranslation->fields['id']);

        $this->array($translation->fields)->isIdenticalTo($clonedTranslation->fields);
    }

    public function testCloneFromTemplate()
    {
        global $DB;

        $iterator = $DB->request([
           'SELECT' => [
              'id',
              'notificationtemplates_id'
           ],
           'FROM'   => \NotificationTemplateTranslation::getTable(),
           'LIMIT'  => 1
        ]);

        $data = $iterator->next();
        $template = new \NotificationTemplate();
        $template->getFromDB($data['notificationtemplates_id']);
        $added = $template->clone();

        $translations = $DB->request([
           'FROM'   => \NotificationTemplateTranslation::getTable(),
           'WHERE'  => ['notificationtemplates_id' => $data['notificationtemplates_id']]
        ]);

        $clonedTranslations = $DB->request([
           'FROM'   => \NotificationTemplateTranslation::getTable(),
           'WHERE'  => ['notificationtemplates_id' => $added]
        ]);

        $this->integer(count($translations))->isIdenticalTo(count($clonedTranslations));
    }
}
