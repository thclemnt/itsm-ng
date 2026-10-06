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

/* Test for inc/notificationtemplatetranslation.class.php */

class NotificationTemplateTranslation extends DbTestCase
{
    public function testUsedLanguagesAvoidsLoadingMessageBodies(): void
    {
        global $DB;

        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $em = new class ($connection, \itsmng\Database\Orm::configuration($connection->getDatabasePlatform())) extends \Doctrine\ORM\EntityManager {
            public array $queries = [];

            public function createQuery(string $dql = ''): \Doctrine\ORM\Query
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
        $em->getEventManager()->addEventListener([\Doctrine\ORM\Events::postLoad], $listener);
        try {
            $template = new \itsmng\Database\Entity\NotificationTemplate();
            $template->name = 'Language projection ' . $this->getUniqueString();
            $template->itemtype = 'Ticket';
            $other = new \itsmng\Database\Entity\NotificationTemplate();
            $other->name = 'Other language projection ' . $this->getUniqueString();
            $other->itemtype = 'Ticket';
            $em->persist($template);
            $em->persist($other);
            $translations = [];
            foreach (['', 'fr_FR', 'fr_FR', 'FR_fr', 'ja_JP'] as $language) {
                $translation = new \itsmng\Database\Entity\NotificationTemplateTranslation();
                $translation->notificationtemplates = $template;
                $translation->language = $language;
                $translation->subject = 'Untouched subject';
                $translation->content_text = str_repeat('Text body ', 1024);
                $translation->content_html = '<p>' . str_repeat('HTML body ', 1024) . '</p>';
                $translations[] = $translation;
                $em->persist($translation);
            }
            $otherTranslation = new \itsmng\Database\Entity\NotificationTemplateTranslation();
            $otherTranslation->notificationtemplates = $other;
            $otherTranslation->language = 'en_GB';
            $em->persist($otherTranslation);
            $em->flush();
            $templateId = $template->id;
            $japaneseId = $translations[4]->id;
            $em->clear();
            $repository = new \itsmng\Database\Repository\NotificationTemplateRepository($em);

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
            $assertLanguages(\NotificationTemplateTranslation::getAllUsedLanguages($templateId), $languages);

            $managed = $em->find(\itsmng\Database\Entity\NotificationTemplateTranslation::class, $japaneseId);
            $before = $listener->loaded;
            $connection->update('glpi_notificationtemplatetranslations', ['language' => 'de_DE'], ['id' => $japaneseId]);
            $expected = $languages;
            unset($expected['ja_JP']);
            $expected['de_DE'] = 'de_DE';
            $assertLanguages($repository->usedLanguages($templateId), $expected);
            $this->integer($listener->loaded)->isIdenticalTo($before);
            $this->string($managed->language)->isIdenticalTo('ja_JP');
            $assertLanguages(\NotificationTemplateTranslation::getAllUsedLanguages($templateId), $expected);
            $this->object($em->getConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $em->clear();
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
