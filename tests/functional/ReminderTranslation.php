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
use Closure;
use RuntimeException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use ReflectionProperty;
use Reminder as LegacyReminder;
use ReminderTranslation as LegacyReminderTranslation;
use itsmng\Database\Entity\Reminder as ReminderEntity;
use itsmng\Database\Entity\ReminderTranslation as ReminderTranslationEntity;
use itsmng\Database\Orm;

/* Test for inc/ReminderTranslation.class.php */

/**
 * @engine isolate
 */
class ReminderTranslation extends DbTestCase
{
    public function testTranslationReadsMaterializeValuesWithoutClearingCaller(): void
    {
        global $DB;
        $manager = Orm::create($DB);
        $connection = $manager->getConnection();
        $depth = $connection->getTransactionNestingLevel();
        try {
            $parent = new ReminderEntity();
            $parent->name = 'Scoped translation parent';
            $empty = new ReminderEntity();
            $nullParent = new ReminderEntity();
            foreach ([$parent, $empty, $nullParent] as $owner) {
                $manager->persist($owner);
            }
            $translations = [];
            foreach (['fr_FR', 'fr_FR', 'de_DE', '0', '01', null, ''] as $language) {
                $translation = new ReminderTranslationEntity();
                $translation->reminders = $parent;
                $translation->language = $language;
                $manager->persist($translation);
                $translations[] = $translation;
            }
            $nullable = new ReminderTranslationEntity();
            $nullable->reminders = $nullParent;
            $manager->persist($nullable);
            $manager->flush();
            $item = new LegacyReminder();
            $item->fields['id'] = $parent->id;
            $emptyItem = new LegacyReminder();
            $emptyItem->fields['id'] = $empty->id;
            $nullItem = new LegacyReminder();
            $nullItem->fields['id'] = $nullParent->id;
            $this->integer(LegacyReminderTranslation::getNumberOfTranslationsForItem($item))->isIdenticalTo(7);
            $snapshot = LegacyReminderTranslation::getAlreadyTranslatedForItem($item);
            $languages = $connection->fetchFirstColumn(
                'SELECT DISTINCT language FROM glpi_remindertranslations WHERE reminders_id = ? ORDER BY language',
                [$parent->id],
                [Types::BIGINT]
            );
            $this->array($snapshot)->isIdenticalTo(array_combine($languages, $languages));
            $this->integer(LegacyReminderTranslation::getNumberOfTranslationsForItem($emptyItem))->isIdenticalTo(0);
            $this->array(LegacyReminderTranslation::getAlreadyTranslatedForItem($emptyItem))->isEmpty();
            $this->array(LegacyReminderTranslation::getAlreadyTranslatedForItem($nullItem))->isIdenticalTo(['' => null]);

            $this->integer($connection->update(
                'glpi_remindertranslations',
                ['language' => 'es_ES'],
                ['id' => $translations[0]->id],
                ['language' => Types::STRING, 'id' => Types::BIGINT]
            ))->isIdenticalTo(1);
            $creations = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $before = $creations->getValue();
            for ($repeat = 0; $repeat < 2; ++$repeat) {
                $this->integer(LegacyReminderTranslation::getNumberOfTranslationsForItem($item))->isIdenticalTo(7);
                $current = LegacyReminderTranslation::getAlreadyTranslatedForItem($item);
                $this->string($current['es_ES'])->isIdenticalTo('es_ES');
                $this->string($current['fr_FR'])->isIdenticalTo('fr_FR');
            }
            $this->integer($creations->getValue())->isIdenticalTo($before);
            $this->boolean(array_key_exists('es_ES', $snapshot))->isFalse();
            $this->boolean($manager->contains($translations[0]))->isTrue();
            $this->string($translations[0]->language)->isIdenticalTo('fr_FR');
            $this->boolean($manager->contains($parent))->isTrue();

            $active = new ReflectionProperty($connection, 'applicationEntityManagerActive');
            $probe = new class ($parent->id, function () use ($active, $connection): void {
                $this->boolean($active->getValue($connection))->isFalse('Model getID runs before the reusable scope');
            }) {
                public int $calls = 0;
                public function __construct(private int $id, private Closure $probe)
                {
                }
                public function getID(): string
                {
                    ++$this->calls;
                    ($this->probe)();
                    return $this->id . '.75';
                }
            };
            $this->integer(LegacyReminderTranslation::getNumberOfTranslationsForItem($probe))->isIdenticalTo(7);
            $this->string(LegacyReminderTranslation::getAlreadyTranslatedForItem($probe)['es_ES'])->isIdenticalTo('es_ES');
            $this->integer($probe->calls)->isIdenticalTo(2);
            $this->integer($creations->getValue())->isIdenticalTo($before);
            $failure = new RuntimeException('Translation model identity failure');
            $invalid = new class ($failure) {
                public function __construct(private RuntimeException $failure)
                {
                }
                public function getID(): never
                {
                    throw $this->failure;
                }
            };
            foreach (['getNumberOfTranslationsForItem', 'getAlreadyTranslatedForItem'] as $method) {
                $caught = null;
                try {
                    LegacyReminderTranslation::$method($invalid);
                } catch (RuntimeException $error) {
                    $caught = $error;
                }
                $this->boolean($caught === $failure)->isTrue();
                $this->boolean($active->getValue($connection))->isFalse();
            }
            $this->integer($creations->getValue())->isIdenticalTo($before);
            Orm::read($DB, function (EntityManager $outer) use ($parent, $item): void {
                $reference = $outer->getReference(ReminderEntity::class, $parent->id);
                $this->integer(LegacyReminderTranslation::getNumberOfTranslationsForItem($item))->isIdenticalTo(7);
                $this->string(LegacyReminderTranslation::getAlreadyTranslatedForItem($item)['es_ES'])->isIdenticalTo('es_ES');
                $this->boolean($outer->contains($reference))->isTrue();
            });
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
            $this->boolean($manager->contains($parent))->isTrue();
        } finally {
            $manager->clear();
        }
    }

    public function testGetTranslationForReminder()
    {

        $this->login();
        $this->setEntity('_test_root_entity', true);

        $date = date('Y-m-d H:i:s');
        $_SESSION['glpi_currenttime'] = $date;

        $data = [
           'name'         => '_test_reminder01',
           'entities_id'  => 0
        ];

        $reminder = new \Reminder();
        $added = $reminder->add($data);
        $this->integer((int)$added)->isGreaterThan(0);

        $reminder1 = getItemByTypeName(\Reminder::getType(), '_test_reminder01');

        //first, set data
        $text_orig = 'Translation 1 for Reminder1';
        $text_fr = 'Traduction 1 pour Note1';
        $this->addTranslation($reminder1, $text_orig);
        $this->addTranslation($reminder1, $text_fr, 'fr_FR');

        $nb = countElementsInTable(
            'glpi_remindertranslations'
        );
        $this->integer((int)$nb)->isIdenticalTo(2);

        // second, test what we retrieve
        $current_lang = $_SESSION['glpilanguage'];
        $_SESSION['glpilanguage'] = 'fr_FR';
        $text = \ReminderTranslation::getTranslatedValue($reminder1, "text");
        $_SESSION['glpilanguage'] = $current_lang;
        $this->string($text)->isIdenticalTo($text_fr);

    }

    /**
     * Add translation into database
     *
     * @param \Reminder $reminder
     * @param string    $name Reminder name
     * @param string    $lang Reminder language, defaults to null
     *
     * @return void
     */
    private function addTranslation(\Reminder $reminder, $text, $lang = 'NULL')
    {
        $this->login();
        $trans = new \ReminderTranslation();

        $input = [
           'reminders_id' => $reminder->getID(),
           'users_id'     => getItemByTypeName('User', TU_USER, true),
           'text'         => $text,
           'language'     => $lang
        ];
        $transID1 = $trans->add($input);
        $this->boolean($transID1 > 0)->isTrue();
    }
}
