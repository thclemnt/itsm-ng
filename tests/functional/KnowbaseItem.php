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

namespace test\units;

use DBAdapter;
use DBConnection;
use DbTestCase;
use Closure;
use RuntimeException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\KnowbaseItemTranslation as KnowbaseItemTranslationEntity;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Entity_KnowbaseItem;
use KnowbaseItem as LegacyKnowbaseItem;
use KnowbaseItemCategory;
use KnowbaseItemTranslation;
use KnowbaseItem_Profile;
use ReflectionProperty;
use Session;
use Throwable;
use itsmng\Database\Entity\KnowbaseItem as KnowbaseItemEntity;
use itsmng\Database\KnowledgeBaseAccess;
use itsmng\Database\MutationCleanupFailure;
use itsmng\Database\MySQLConnection;
use itsmng\Database\Orm;
use itsmng\Database\PostgresConnection;
use itsmng\Database\Query\KnowledgeBaseFullText;
use itsmng\Database\Repository\KnowledgeBaseRepository;
use itsmng\Database\Entity\KnowbaseItemCategory as CategoryEntity;
use Doctrine\Common\EventManager;
use mock\DBmysql as CategoryAdapterProbe;
use mock\KnowbaseItem as CategoryVisibilityProbe;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/knowbaseitem.class.php */

class KnowbaseItem extends DbTestCase
{
    public function testTranslationReadsMaterializeValuesWithoutClearingCaller(): void
    {
        global $DB;
        $manager = Orm::create($DB);
        $connection = $manager->getConnection();
        $depth = $connection->getTransactionNestingLevel();
        try {
            $parent = new KnowbaseItemEntity();
            $parent->name = 'Scoped translation parent';
            $empty = new KnowbaseItemEntity();
            $nullParent = new KnowbaseItemEntity();
            foreach ([$parent, $empty, $nullParent] as $owner) {
                $manager->persist($owner);
            }
            $translations = [];
            foreach (['fr_FR', 'fr_FR', 'FR_fr', '0', '01', ''] as $language) {
                $translation = new KnowbaseItemTranslationEntity();
                $translation->knowbaseitems = $parent;
                $translation->language = $language;
                $manager->persist($translation);
                $translations[] = $translation;
            }
            $nullable = new KnowbaseItemTranslationEntity();
            $nullable->knowbaseitems = $nullParent;
            $manager->persist($nullable);
            $manager->flush();
            $item = new LegacyKnowbaseItem();
            $item->fields['id'] = $parent->id;
            $emptyItem = new LegacyKnowbaseItem();
            $emptyItem->fields['id'] = $empty->id;
            $nullItem = new LegacyKnowbaseItem();
            $nullItem->fields['id'] = $nullParent->id;
            $this->integer(KnowbaseItemTranslation::getNumberOfTranslationsForItem($item))->isIdenticalTo(6);
            $snapshot = KnowbaseItemTranslation::getAlreadyTranslatedForItem($item);
            $expected = ['fr_FR' => 'fr_FR', 'FR_fr' => 'FR_fr', 0 => '0', '01' => '01', '' => ''];
            $sorted = $snapshot;
            ksort($sorted);
            ksort($expected);
            $this->array($sorted)->isIdenticalTo($expected);
            $this->integer(KnowbaseItemTranslation::getNumberOfTranslationsForItem($emptyItem))->isIdenticalTo(0);
            $this->array(KnowbaseItemTranslation::getAlreadyTranslatedForItem($emptyItem))->isEmpty();
            $this->array(KnowbaseItemTranslation::getAlreadyTranslatedForItem($nullItem))->isIdenticalTo(['' => null]);

            $this->integer($connection->update(
                'glpi_knowbaseitemtranslations',
                ['language' => 'es_ES'],
                ['id' => $translations[0]->id],
                ['language' => Types::STRING, 'id' => Types::BIGINT]
            ))->isIdenticalTo(1);
            $creations = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $before = $creations->getValue();
            for ($repeat = 0; $repeat < 2; ++$repeat) {
                $this->integer(KnowbaseItemTranslation::getNumberOfTranslationsForItem($item))->isIdenticalTo(6);
                $current = KnowbaseItemTranslation::getAlreadyTranslatedForItem($item);
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
            $this->integer(KnowbaseItemTranslation::getNumberOfTranslationsForItem($probe))->isIdenticalTo(6);
            $this->string(KnowbaseItemTranslation::getAlreadyTranslatedForItem($probe)['es_ES'])->isIdenticalTo('es_ES');
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
                    KnowbaseItemTranslation::$method($invalid);
                } catch (RuntimeException $error) {
                    $caught = $error;
                }
                $this->boolean($caught === $failure)->isTrue();
                $this->boolean($active->getValue($connection))->isFalse();
            }
            $this->integer($creations->getValue())->isIdenticalTo($before);
            Orm::read($DB, function (EntityManager $outer) use ($parent, $item): void {
                $reference = $outer->getReference(KnowbaseItemEntity::class, $parent->id);
                $this->integer(KnowbaseItemTranslation::getNumberOfTranslationsForItem($item))->isIdenticalTo(6);
                $this->string(KnowbaseItemTranslation::getAlreadyTranslatedForItem($item)['es_ES'])->isIdenticalTo('es_ES');
                $this->boolean($outer->contains($reference))->isTrue();
            });
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
            $this->boolean($manager->contains($parent))->isTrue();
        } finally {
            $manager->clear();
        }
    }

    public function testShowListPreservesAudienceCategoryAndCurrentContent(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $this->integer($entity)->isGreaterThan(0);
        $this->boolean(in_array(0, $_SESSION['glpiactiveentities'], false))->isFalse();
        $author = (int)getItemByTypeName('User', 'itsm', true);
        $this->integer($author)->isGreaterThan(0);
        $this->boolean($author !== (int)Session::getLoginUserID())->isTrue();
        $category = $this->createItem(KnowbaseItemCategory::class, ['name' => 'Visible article category']);
        $otherCategory = $this->createItem(KnowbaseItemCategory::class, ['name' => 'Other article category']);
        $articles = [];
        foreach ([
            ['Visible article alpha', 'Original alpha content', $category->getID(), $entity],
            ['Visible article beta', 'Original beta content', $category->getID(), $entity],
            ['Hidden article audience', 'Hidden audience content', $category->getID(), 0],
            ['Other category article', 'Other category content', $otherCategory->getID(), $entity],
        ] as [$name, $answer, $categoryId, $audience]) {
            $article = $this->createItem(LegacyKnowbaseItem::class, [
                'name' => $name, 'answer' => $answer, 'users_id' => $author,
                'knowbaseitemcategories_id' => $categoryId, 'is_faq' => 0,
            ]);
            $this->createItem(Entity_KnowbaseItem::class, [
                'knowbaseitems_id' => $article->getID(), 'entities_id' => $audience, 'is_recursive' => 0,
            ]);
            $articles[] = $article;
        }
        $session = $_SESSION;
        $get = $_GET;
        $readRouting = $CFG_GLPI['use_slave_for_search'];
        try {
            // Exercise entity audience filtering, without administrator/author bypasses.
            $_SESSION['glpiactiveprofile']['knowbase'] = READ;
            $_SESSION['glpilist_limit'] = 20;
            $_GET = [];
            $CFG_GLPI['use_slave_for_search'] = 0;
            $this->boolean((bool)Session::haveRight('knowbase', LegacyKnowbaseItem::KNOWBASEADMIN))->isFalse();
            $this->object(DBConnection::getReadConnection())->isIdenticalTo($DB);
            $render = static function () use ($category): string {
                ob_start();
                try {
                    LegacyKnowbaseItem::showList(['knowbaseitemcategories_id' => $category->getID()], 'browse');
                    return ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            };
            $html = $render();
            $this->string($html)->contains('Visible article alpha')->contains('Original alpha content')
                ->contains('Visible article beta')->contains('Original beta content')
                ->contains('Visible article category')
                ->notContains('Hidden article audience')->notContains('Hidden audience content')
                ->notContains('Other category article');
            foreach (array_slice($articles, 0, 2) as $article) {
                $this->string($html)->contains(LegacyKnowbaseItem::getFormURLWithID($article->getID()));
            }
            $this->boolean($DB->update('glpi_knowbaseitems', [
                'name' => 'Updated article alpha', 'answer' => 'Updated alpha content',
            ], ['id' => $articles[0]->getID()]))->isTrue();
            $this->string($render())->contains('Updated article alpha')->contains('Updated alpha content')
                ->notContains('Visible article alpha')->notContains('Original alpha content')
                ->notContains('Hidden article audience')->notContains('Other category article');
        } finally {
            $_SESSION = $session;
            $_GET = $get;
            $CFG_GLPI['use_slave_for_search'] = $readRouting;
        }
    }

    public function testSearchPagesUseNativeFullTextAndPreserveVisibility(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $this->setEntity('_test_root_entity', true);
        $original = $DB;
        $session = $_SESSION;
        $config = $CFG_GLPI;
        $get = $_GET;
        $level = $original->getDoctrineConnection()->getTransactionNestingLevel();
        $parameters = $original->getDoctrineConnection()->getParams();
        $connection = $original->getProvider() === 'pgsql'
            ? PostgresConnection::create($parameters)
            : MySQLConnection::create($parameters);
        $probe = clone $original;
        (new ReflectionProperty(DBAdapter::class, 'doctrine'))->setValue($probe, $connection);
        $articles = [];
        $category = null;
        $primary = null;
        try {
            $DB = $probe;
            $connection->beginTransaction();
            $entity = (int)getItemByTypeName('Entity', '_test_root_entity', true);
            $author = (int)getItemByTypeName('User', 'itsm', true);
            $this->integer($entity)->isGreaterThan(0);
            $this->integer($author)->isGreaterThan(0);
            $this->boolean($author !== (int)Session::getLoginUserID())->isTrue();
            $category = $this->createItem(KnowbaseItemCategory::class, ['name' => 'Native search category']);
            foreach ([
                ['Zxquasar alpha Zxbasehit', 'First searchable content ZxunderXscoremark', $entity, null, null],
                ['Second visible article', 'Zxquasar second content Zxunder_scoremark Zxnullmark', $entity, null, null],
                ['Zxquasar hidden audience', 'Hidden content', 0, null, null],
                ['Zxquasar future article', 'Future content', $entity, '2037-01-01 00:00:00', null],
                ['Zxquasar expired article', 'Expired content', $entity, null, '2000-01-01 00:00:00'],
                ['Original translation title', null, $entity, null, null],
            ] as [$name, $answer, $audience, $begin, $end]) {
                $article = $this->createItem(LegacyKnowbaseItem::class, [
                    'name' => $name, 'answer' => $answer, 'users_id' => $author,
                    'knowbaseitemcategories_id' => $category->getID(), 'is_faq' => 0,
                    'begin_date' => $begin, 'end_date' => $end,
                ]);
                $this->createItem(Entity_KnowbaseItem::class, [
                    'knowbaseitems_id' => $article->getID(), 'entities_id' => $audience, 'is_recursive' => 0,
                ]);
                $articles[] = $article;
            }
            // Overlapping grants must neither multiply the count nor occupy two page slots.
            $this->createItem(KnowbaseItem_Profile::class, [
                'knowbaseitems_id' => $articles[0]->getID(), 'profiles_id' => $_SESSION['glpiactiveprofile']['id'],
                'entities_id' => $entity, 'is_recursive' => 0,
            ]);
            foreach ([['fr_FR', 'Zxnebula étoile traduite'], ['de_DE', 'Wronglanguage unique result'],
                ['fr_FR', 'Duplicate Zxduplicate Zxnebula translation']] as [$language, $name]) {
                $this->createItem(KnowbaseItemTranslation::class, [
                    'knowbaseitems_id' => $articles[5]->getID(), 'language' => $language,
                    'name' => $name, 'answer' => null,
                ]);
            }
            foreach (['Base article earlier translation', 'Zxbasehit later translation'] as $name) {
                $this->createItem(KnowbaseItemTranslation::class, [
                    'knowbaseitems_id' => $articles[0]->getID(), 'language' => 'fr_FR',
                    'name' => $name, 'answer' => null,
                ]);
            }
            // InnoDB FULLTEXT indexes committed rows, unlike ordinary transactional reads.
            // Publish only this connection's graph, never the caller's outer test frame.
            $connection->commit();
            $_SESSION['glpiactiveprofile']['knowbase'] = READ;
            $_SESSION['glpilanguage'] = 'fr_FR';
            $_SESSION['glpilist_limit'] = 20;
            $_GET = [];
            $CFG_GLPI['translate_kb'] = 1;
            $CFG_GLPI['use_slave_for_search'] = 0;
            $access = KnowledgeBaseAccess::current();
            $this->boolean($access->administrator)->isFalse();
            $manager = Orm::create($DB);
            $repository = new KnowledgeBaseRepository($manager);
            $loads = new class () {
                public int $articles = 0;
                public function postLoad(PostLoadEventArgs $event): void
                {
                    if ($event->getObject() instanceof KnowbaseItemEntity) {
                        ++$this->articles;
                    }
                }
            };
            $manager->getEventManager()->addEventListener(['postLoad'], $loads);
            $options = ['type' => 'search', 'contains' => 'zxquas', 'category' => 0,
                'faq' => false, 'language' => 'fr_FR', 'offset' => 0, 'limit' => 20];
            $page = $repository->listPage($access, $options);
            $this->integer($page['total'])->isIdenticalTo(2);
            $expectedIds = [(int)$articles[0]->getID(), (int)$articles[1]->getID()];
            $ids = array_column($page['rows'], 'id');
            $this->array($ids)->hasSize(2)->containsValues($expectedIds);
            $basePage = $repository->listPage($access, array_replace($options, ['contains' => 'zxbasehit']));
            $this->integer($basePage['total'])->isIdenticalTo(1);
            $this->array(array_column($basePage['rows'], 'id'))->isIdenticalTo([(int)$articles[0]->getID()]);
            $this->string($basePage['rows'][0]['transname'])->isIdenticalTo(
                'Base article earlier translation',
                'An article hit keeps its first translation even when a later translation also matches'
            );
            $criteria = LegacyKnowbaseItem::getListRequest(['contains' => 'zxbasehit', 'faq' => false,
                'knowbaseitemcategories_id' => 0], 'search');
            $legacyBase = array_values(iterator_to_array($DB->request($criteria)));
            $this->array(array_column($legacyBase, 'id'))->isIdenticalTo([(int)$articles[0]->getID()]);
            $this->string($legacyBase[0]['transname'])->isIdenticalTo('Base article earlier translation');
            $this->integer($page['rows'][0]['is_faq'])->isIdenticalTo(0);
            foreach ([0, 1] as $offset) {
                $part = $repository->listPage($access, array_replace($options, ['limit' => 1, 'offset' => $offset]));
                $this->integer($part['total'])->isIdenticalTo(2);
                $this->array(array_column($part['rows'], 'id'))->isIdenticalTo([$ids[$offset]]);
            }
            foreach (['quasa', "'zxquas'", 'zxquas / nonexistentword', "'quasa'", 'quasa / nonexistentword'] as $text) {
                $result = $repository->listPage($access, array_replace($options, ['contains' => $text]));
                $this->integer($result['total'])->isIdenticalTo(2, 'Search: ' . $text);
                $this->array(array_column($result['rows'], 'id'))->hasSize(2)->containsValues($expectedIds);
            }
            // Interior words have no native prefix hit: punctuation must still select
            // the same OR alternatives in the fallback, independently of index timing.
            foreach (['glpi_knowbaseitems' => ['quasa', $expectedIds],
                'glpi_knowbaseitemtranslations' => ['uplicat', null]] as $table => [$word, $owned]) {
                $native = KnowledgeBaseFullText::sql(
                    $connection->getDatabasePlatform(),
                    [$DB->quoteName('name'), $DB->quoteName('answer')],
                    '?'
                );
                $restriction = $owned === null ? 'knowbaseitems_id = ?' : 'id IN (?, ?)';
                $parameters = $owned ?? [(int)$articles[5]->getID()];
                $parameters[] = KnowledgeBaseRepository::fullTextQuery(
                    $word,
                    $connection->getDatabasePlatform()
                );
                $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM ' . $DB->quoteName($table)
                    . ' WHERE ' . $restriction . ' AND ' . $native, $parameters))->isIdenticalTo(0);
            }
            $this->array(KnowledgeBaseRepository::fallbackPatterns("^NULL$ / under_score"))
                ->isIdenticalTo(['%NULL%', '%under!_score%']);
            $this->array(KnowledgeBaseRepository::fallbackPatterns('()<>+*'))->isEmpty();
            foreach (['()<>+*', 'wronglanguage'] as $text) {
                $this->integer($repository->listPage($access, array_replace($options, ['contains' => $text]))['total'])->isIdenticalTo(0);
            }
            foreach (['zxnebu', 'étoile', 'zxduplicate', 'uplicat', "'uplicat'", 'uplicat / nonexistentword'] as $text) {
                $translated = $repository->listPage($access, array_replace($options, ['contains' => $text]));
                $this->integer($translated['total'])->isIdenticalTo(1);
                $this->array(array_column($translated['rows'], 'id'))->isIdenticalTo([(int)$articles[5]->getID()]);
                $this->string($translated['rows'][0]['transname'])->isIdenticalTo(
                    in_array($text, ['zxduplicate', 'uplicat', "'uplicat'", 'uplicat / nonexistentword'], true) ? 'Duplicate Zxduplicate Zxnebula translation' : 'Zxnebula étoile traduite'
                );
                $this->variable($translated['rows'][0]['transanswer'])->isNull();
                $after = $repository->listPage($access, array_replace($options, ['contains' => $text, 'limit' => 1, 'offset' => 1]));
                $this->integer($after['total'])->isIdenticalTo(1);
                $this->array($after['rows'])->isEmpty();
            }
            // The retained public criteria API preserves later-only full-text and fallback matches.
            foreach (['zxduplicate', 'uplicat', "'uplicat'", 'uplicat / nonexistentword'] as $text) {
                $criteria = LegacyKnowbaseItem::getListRequest(['contains' => $text, 'faq' => false,
                    'knowbaseitemcategories_id' => 0], 'search');
                $legacy = array_values(iterator_to_array($DB->request($criteria)));
                $this->array(array_column($legacy, 'id'))->isIdenticalTo([(int)$articles[5]->getID()]);
                $this->string($legacy[0]['transname'])->isIdenticalTo('Duplicate Zxduplicate Zxnebula translation');
            }
            foreach (['nullmark', '^nullmark$', 'under_score'] as $text) {
                $literal = $repository->listPage($access, array_replace($options, ['contains' => $text]));
                $this->integer($literal['total'])->isIdenticalTo(1, 'Literal search: ' . $text);
                $this->array(array_column($literal['rows'], 'id'))->isIdenticalTo([(int)$articles[1]->getID()]);
                $criteria = LegacyKnowbaseItem::getListRequest(['contains' => $text, 'faq' => false,
                    'knowbaseitemcategories_id' => 0], 'search');
                $legacy = array_values(iterator_to_array($DB->request($criteria)));
                $this->array(array_column($legacy, 'id'))->isIdenticalTo([(int)$articles[1]->getID()]);
            }
            foreach (["'quasa'", 'quasa / nonexistentword', '()<>+*'] as $text) {
                $criteria = LegacyKnowbaseItem::getListRequest(['contains' => $text, 'faq' => false,
                    'knowbaseitemcategories_id' => 0], 'search');
                $legacy = array_values(iterator_to_array($DB->request($criteria)));
                if ($text === '()<>+*') {
                    $this->array($legacy)->isEmpty();
                } else {
                    $this->array(array_column($legacy, 'id'))->hasSize(2)->containsValues($expectedIds);
                }
            }
            ob_start();
            try {
                LegacyKnowbaseItem::showList(['contains' => 'zxnebu', 'faq' => false], 'search');
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->string($html)->contains('Zxnebula étoile traduite')->contains('Native search category')
                ->notContains('Original translation title')->notContains('Wronglanguage unique result');
            // FAQ-only and anonymous views retain the existing audience policy.
            $this->boolean($DB->update(
                'glpi_knowbaseitems',
                ['is_faq' => 1],
                ['id' => $articles[1]->getID()]
            ))->isTrue();
            $faqViewer = new KnowledgeBaseAccess(
                $access->user,
                false,
                false,
                true,
                $access->multiEntity,
                $access->groups,
                $access->profile,
                $access->entities,
                $access->ancestors
            );
            $this->array(array_column($repository->listPage($faqViewer, $options)['rows'], 'id'))
                ->isIdenticalTo([(int)$articles[1]->getID()]);
            foreach ([[true, false, 1], [true, true, 0], [false, false, 0]] as [$publicFaq, $multiEntity, $expected]) {
                $anonymous = new KnowledgeBaseAccess(
                    0,
                    false,
                    false,
                    $publicFaq,
                    $multiEntity,
                    [],
                    0,
                    [],
                    []
                );
                $this->integer($repository->listPage($anonymous, $options)['total'])->isIdenticalTo($expected);
            }
            $this->integer($loads->articles)->isIdenticalTo(0);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            $this->boolean($DB->update(
                'glpi_knowbaseitems',
                ['name' => 'Updated unrelated title'],
                ['id' => $articles[0]->getID()]
            ))->isTrue();
            $this->array(array_column($repository->listPage($access, $options)['rows'], 'id'))
                ->isIdenticalTo([(int)$articles[1]->getID()]);
            $manager->find(KnowbaseItemEntity::class, (int)$articles[1]->getID());
            $this->integer($loads->articles)->isIdenticalTo(1, 'The observer detects a real entity load');
            $manager->clear();
        } catch (Throwable $error) {
            $primary = $error;
        } finally {
            // Restore fixture-creation rights for public lifecycle cleanup.
            $_SESSION = $session;
            $CFG_GLPI = $config;
            $_GET = $get;
            try {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                foreach ($articles as $article) {
                    if ($article->getFromDB($article->getID())) {
                        $this->boolean($article->delete(['id' => $article->getID()], true))->isTrue();
                    }
                }
                if ($category !== null && $category->getFromDB($category->getID())) {
                    $this->boolean($category->delete(['id' => $category->getID()], true))->isTrue();
                }
            } catch (Throwable $cleanup) {
                $primary = $primary === null ? $cleanup : new MutationCleanupFailure($primary, $cleanup);
            } finally {
                $DB = $original;
                $_SESSION = $session;
                $CFG_GLPI = $config;
                $_GET = $get;
                try {
                    $probe->close();
                } catch (Throwable $cleanup) {
                    $primary = $primary === null ? $cleanup : new MutationCleanupFailure($primary, $cleanup);
                }
            }
        }
        if ($primary !== null) {
            throw $primary;
        }
        $this->integer($original->getDoctrineConnection()->getTransactionNestingLevel())->isIdenticalTo($level);
    }

    public function testGetTypeName()
    {
        $expected = 'Knowledge base';
        $this->string(\KnowbaseItem::getTypeName(1))->isIdenticalTo($expected);

        $expected = 'Knowledge base';
        $this->string(\KnowbaseItem::getTypeName(0))->isIdenticalTo($expected);
        $this->string(\KnowbaseItem::getTypeName(2))->isIdenticalTo($expected);
        $this->string(\KnowbaseItem::getTypeName(10))->isIdenticalTo($expected);
    }

    public function testCleanDBonPurge()
    {
        global $DB;

        $users_id = getItemByTypeName('User', TU_USER, true);

        $kb = new \KnowbaseItem();
        $this->integer(
            (int)$kb->add([
              'name'     => 'Test to remove',
              'answer'   => 'An KB entry to remove',
              'is_faq'   => 0,
              'users_id' => $users_id,
              'date'     => '2017-10-06 12:27:48',
         ])
        )->isGreaterThan(0);

        //add some comments
        $comment = new \KnowbaseItem_Comment();
        $input = [
           'knowbaseitems_id' => $kb->getID(),
           'users_id'         => $users_id
        ];

        $id = 0;
        for ($i = 0; $i < 4; ++$i) {
            $input['comment'] = "Comment $i";
            $this->integer(
                (int)$comment->add($input)
            )->isGreaterThan($id);
            $id = (int)$comment->getID();
        }

        //change KB entry
        $this->boolean(
            $kb->update([
              'id'     => $kb->getID(),
              'answer' => 'Answer has changed'
         ])
        )->isTrue();

        //add an user
        $kbu = new \KnowbaseItem_User();
        $this->integer(
            (int)$kbu->add([
              'knowbaseitems_id'   => $kb->getID(),
              'users_id'           => $users_id
         ])
        )->isGreaterThan(0);

        //add an entity
        $kbe = new \Entity_KnowbaseItem();
        $this->integer(
            (int)$kbe->add([
              'knowbaseitems_id'   => $kb->getID(),
              'entities_id'        => 0
         ])
        )->isGreaterThan(0);

        //add a group
        $group = new \Group();
        $this->integer(
            (int)$group->add([
              'name'   => 'KB group'
         ])
        )->isGreaterThan(0);
        $kbg = new \Group_KnowbaseItem();
        $this->integer(
            (int)$kbg->add([
              'knowbaseitems_id'   => $kb->getID(),
              'groups_id'          => $group->getID()
         ])
        )->isGreaterThan(0);

        //add a profile
        $profiles_id = getItemByTypeName('Profile', 'Admin', true);
        $kbp = new \KnowbaseItem_Profile();
        $this->integer(
            (int)$kbp->add([
              'knowbaseitems_id'   => $kb->getID(),
              'profiles_id'        => $profiles_id
         ])
        )->isGreaterThan(0);

        //add an item
        $kbi = new \KnowbaseItem_Item();
        $tickets_id = getItemByTypeName('Ticket', '_ticket01', true);
        $this->integer(
            (int)$kbi->add([
              'knowbaseitems_id'   => $kb->getID(),
              'itemtype'           => 'Ticket',
              'items_id'           => $tickets_id
         ])
        )->isGreaterThan(0);

        $relations = [
           $comment->getTable(),
           \KnowbaseItem_Revision::getTable(),
           \KnowbaseItem_User::getTable(),
           \Entity_KnowbaseItem::getTable(),
           \Group_KnowbaseItem::getTable(),
           \KnowbaseItem_Profile::getTable(),
           \KnowbaseItem_Item::getTable()
        ];

        //check all relations have been created
        foreach ($relations as $relation) {
            $iterator = $DB->request([
               'FROM'   => $relation,
               'WHERE'  => ['knowbaseitems_id' => $kb->getID()]
            ]);
            $this->integer(count($iterator))->isGreaterThan(0);
        }

        //remove KB entry
        $this->boolean(
            $kb->delete(['id' => $kb->getID()], true)
        )->isTrue();

        //check all relations has been removed
        foreach ($relations as $relation) {
            $iterator = $DB->request([
               'FROM'   => $relation,
               'WHERE'  => ['knowbaseitems_id' => $kb->getID()]
            ]);
            $this->integer(count($iterator))->isIdenticalTo(0);
        }
    }

    public function testScreenshotConvertedIntoDocument()
    {

        $this->login(); // must be logged as Document_Item uses Session::getLoginUserID()

        // Test uploads for item creation
        $base64Image = base64_encode(file_get_contents(__DIR__ . '/../fixtures/uploads/foo.png'));
        $filename = '5e5e92ffd9bd91.11111111image_paste22222222.png';
        $users_id = getItemByTypeName('User', TU_USER, true);
        $instance = new \KnowbaseItem();
        $input = [
           'name'     => 'Test to remove',
           'answer'   => '&lt;p&gt; &lt;/p&gt;&lt;p&gt;&lt;img id="3e29dffe-0237ea21-5e5e7034b1d1a1.00000000"'
                          . ' src="data:image/png;base64,' . $base64Image . '" width="12" height="12" /&gt;&lt;/p&gt;',
           '_answer' => [
              $filename,
           ],
           '_tag_answer' => [
              '3e29dffe-0237ea21-5e5e7034b1d1a1.00000000',
           ],
           '_prefix_answer' => [
              '5e5e92ffd9bd91.11111111',
           ],
           'is_faq'   => 0,
           'users_id' => $users_id,
           'date'     => '2017-10-06 12:27:48',
        ];
        copy(__DIR__ . '/../fixtures/uploads/foo.png', GLPI_TMP_DIR . '/' . $filename);
        $instance->add($input);
        $this->boolean($instance->isNewItem())->isFalse();
        $expected = 'a href=&quot;/front/document.send.php?docid=';
        $this->string($instance->fields['answer'])->contains($expected);

        // Test uploads for item update
        $base64Image = base64_encode(file_get_contents(__DIR__ . '/../fixtures/uploads/bar.png'));
        $filename = '5e5e92ffd9bd91.44444444image_paste55555555.png';
        $tmpFilename = GLPI_TMP_DIR . '/' . $filename;
        file_put_contents($tmpFilename, base64_decode($base64Image));
        $success = $instance->update([
           'id'       => $instance->getID(),
           'answer'   => '&lt;p&gt; &lt;/p&gt;&lt;p&gt;&lt;img id="3e29dffe-0237ea21-5e5e7034b1ffff.33333333"'
                          . ' src="data:image/png;base64,' . $base64Image . '" width="12" height="12" /&gt;&lt;/p&gt;',
           '_answer' => [
              $filename,
           ],
           '_tag_answer' => [
              '3e29dffe-0237ea21-5e5e7034b1ffff.33333333',
           ],
           '_prefix_answer' => [
              '5e5e92ffd9bd91.44444444',
           ],
        ]);
        $this->boolean($success)->isTrue();
        // Ensure there is an anchor to the uploaded document
        $expected = 'a href=&quot;/front/document.send.php?docid=';
        $this->string($instance->fields['answer'])->contains($expected);
    }

    public function testUploadDocuments()
    {

        $this->login(); // must be logged as Document_Item uses Session::getLoginUserID()

        // Test uploads for item creation
        $filename = '5e5e92ffd9bd91.11111111' . 'foo.txt';
        $instance = new \KnowbaseItem();
        $input = [
           'name'    => 'a kb item',
           'answer' => 'testUploadDocuments',
           '_answer' => [
              $filename,
           ],
           '_tag_answer' => [
              '3e29dffe-0237ea21-5e5e7034b1ffff.00000000',
           ],
           '_prefix_answer' => [
              '5e5e92ffd9bd91.11111111',
           ]
        ];
        copy(__DIR__ . '/../fixtures/uploads/foo.txt', GLPI_TMP_DIR . '/' . $filename);
        $instance->add($input);
        $this->boolean($instance->isNewItem())->isFalse();
        $this->string($instance->fields['answer'])->contains('testUploadDocuments');
        $count = (new \DBUtils())->countElementsInTable(\Document_Item::getTable(), [
           'itemtype' => 'KnowbaseItem',
           'items_id' => $instance->getID(),
        ]);
        $this->integer($count)->isEqualTo(1);

        // Test uploads for item update (adds a 2nd document)
        $filename = '5e5e92ffd9bd91.44444444bar.txt';
        copy(__DIR__ . '/../fixtures/uploads/bar.txt', GLPI_TMP_DIR . '/' . $filename);
        $success = $instance->update([
           'id' => $instance->getID(),
           'answer' => 'update testUploadDocuments',
           '_answer' => [
              $filename,
           ],
           '_tag_answer' => [
              '3e29dffe-0237ea21-5e5e7034b1d1a1.33333333',
           ],
           '_prefix_answer' => [
              '5e5e92ffd9bd91.44444444',
           ]
        ]);
        $this->boolean($success)->isTrue();
        $this->string($instance->fields['answer'])->contains('update testUploadDocuments');
        $count = (new \DBUtils())->countElementsInTable(\Document_Item::getTable(), [
           'itemtype' => 'KnowbaseItem',
           'items_id' => $instance->getID(),
        ]);
        $this->integer($count)->isEqualTo(2);
    }

    public function testCategoryReadCompletesBeforeVisibilityAndPreservesDirtyOwners(): void
    {
        global $DB;
        $this->login();
        $category = $this->createItem(KnowbaseItemCategory::class, ['name' => 'Materialized category ' . $this->getUniqueString()]);
        $other = $this->createItem(KnowbaseItemCategory::class, ['name' => 'Other materialized category ' . $this->getUniqueString()]);
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $article = $this->createItem(LegacyKnowbaseItem::class, [
                'name' => 'Materialized article ' . $i . ' ' . $this->getUniqueString(),
                'knowbaseitemcategories_id' => $category->getID(),
            ]);
            $ids[] = (int)$article->getID();
        }
        $connection = $DB->getDoctrineConnection();
        $probe = new CategoryVisibilityProbe();
        $loaded = [];
        $current = 0;
        $expectedActive = false;
        $test = $this;
        $this->calling($probe)->getFromDB = static function ($id) use ($test, $connection, $ids, $other, &$loaded, &$current, &$expectedActive): bool {
            $test->boolean($connection->isApplicationEntityManagerActive())->isIdenticalTo($expectedActive);
            $current = (int)$id;
            $loaded[] = $current;
            if ($current === $ids[0]) {
                $connection->update('glpi_knowbaseitems', ['knowbaseitemcategories_id' => $other->getID()], ['id' => $ids[2]]);
            }
            return true;
        };
        $this->calling($probe)->canViewItem = static function () use ($ids, &$current): bool {
            return $current === $ids[1];
        };
        $this->array(LegacyKnowbaseItem::getForCategory((string)$category->getID(), $probe))->isIdenticalTo([1 => $ids[1]]);
        $this->array($loaded)->isIdenticalTo($ids);
        $loaded = [];
        $this->array(LegacyKnowbaseItem::getForCategory($category->getID(), $probe))->isIdenticalTo([1 => $ids[1]]);
        $this->array($loaded)->isIdenticalTo([$ids[0], $ids[1]]);
        $writer = Orm::create($DB);
        $live = $writer->find(KnowbaseItemEntity::class, $ids[0]);
        $live->knowbaseitemcategories = $writer->find(CategoryEntity::class, (int)$other->getID());
        // The completed nested reader must leave the enclosing owner active.
        $expectedActive = true;
        Orm::read($DB, function (EntityManager $outer) use ($category, $other, $ids, $probe, $writer, $live): void {
            $owned = $outer->find(KnowbaseItemEntity::class, $ids[0]);
            $owned->knowbaseitemcategories = $outer->find(CategoryEntity::class, (int)$other->getID());
            $this->array(LegacyKnowbaseItem::getForCategory($category->getID(), $probe))->isIdenticalTo([1 => $ids[1]]);
            $this->boolean($outer->contains($owned))->isTrue();
            $this->integer($owned->knowbaseitemcategories->id)->isIdenticalTo((int)$other->getID());
            $this->boolean($writer->contains($live))->isTrue();
            $this->integer($live->knowbaseitemcategories->id)->isIdenticalTo((int)$other->getID());
        });
        $expectedActive = false;
        $this->calling($probe)->canViewItem = true;
        $this->array(LegacyKnowbaseItem::getForCategory([$category->getID(), $other->getID()], $probe))->isIdenticalTo($ids);
        $this->calling($probe)->canViewItem = false;
        $this->array(LegacyKnowbaseItem::getForCategory($category->getID(), $probe))->isIdenticalTo([-1]);
        $loaded = [];
        $this->array(LegacyKnowbaseItem::getForCategory(PHP_INT_MAX, $probe))->isIdenticalTo([-1]);
        $this->array($loaded)->isEmpty();
    }

    public function testCategoryReadRetainsCustomConstructorAndSelectedRouteBeforeCallbacks(): void
    {
        global $DB;
        $original = $DB;
        try {
            $this->login();
            $category = $this->createItem(KnowbaseItemCategory::class, ['name' => 'Custom category ' . $this->getUniqueString()]);
            $article = $this->createItem(LegacyKnowbaseItem::class, [
                'name' => 'Custom category article ' . $this->getUniqueString(), 'knowbaseitemcategories_id' => $category->getID(),
            ]);
            $connection = $DB->getDoctrineConnection();
            $observer = new class () {
                public array $trace = [];
                public int $clears = 0;
                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $selected = new class ($connection) extends ScalarReadProbe {
                public EventManager $events;
                public object $observer;
                public function getEventManager(): EventManager
                {
                    $this->observer->trace[] = 'constructed';
                    return $this->events;
                }
            };
            $selected->observer = $observer;
            $selected->events = new EventManager();
            $selected->events->addEventListener(['onClear'], $observer);
            $other = new ScalarReadProbe($connection);
            $route = $selected;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new CategoryAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = static function () use (&$route) {
                return $route;
            };
            $this->calling($adapter)->getProvider = $original->getProvider();
            $DB = $adapter;
            $probe = new CategoryVisibilityProbe();
            $test = $this;
            $this->calling($probe)->getFromDB = static function ($id) use ($test, $article, $connection, $observer, $selected, $other, &$route): bool {
                $test->integer((int)$id)->isIdenticalTo((int)$article->getID());
                $test->boolean($connection->isApplicationEntityManagerActive())->isFalse();
                $test->array($selected->queries)->hasSize(1);
                $observer->trace[] = 'loaded';
                $route = $other;
                return true;
            };
            $this->calling($probe)->canViewItem = static function () use ($test, $connection, $observer): bool {
                $test->boolean($connection->isApplicationEntityManagerActive())->isFalse();
                $observer->trace[] = 'visible';
                return true;
            };
            $this->array(LegacyKnowbaseItem::getForCategory($category->getID(), $probe))->isIdenticalTo([(int)$article->getID()]);
            $this->array($observer->trace)->isIdenticalTo(['constructed', 'loaded', 'visible']);
            $this->integer($observer->clears)->isIdenticalTo(0);
            $this->array($other->queries)->isEmpty();
        } finally {
            $DB = $original;
        }
    }

    public function testGetForCategory()
    {
        $this->login();
        $category = (new KnowbaseItemCategory())->add(['name' => 'Article lookup category']);
        $this->integer((int)$category)->isGreaterThan(0);
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = (int)(new LegacyKnowbaseItem())->add([
                'name' => 'Category article ' . $i,
                'knowbaseitemcategories_id' => $category,
            ]);
            $this->integer($ids[$i])->isGreaterThan(0);
        }
        $m_kbi = new \mock\KnowbaseItem();
        $this->calling($m_kbi)->getFromDB = true;
        $this->calling($m_kbi)->canViewItem[0] = false;
        $this->calling($m_kbi)->canViewItem[1] = true;
        $this->calling($m_kbi)->canViewItem[2] = false;
        $this->calling($m_kbi)->canViewItem[3] = true;

        $this->array(LegacyKnowbaseItem::getForCategory($category, $m_kbi))
            ->hasSize(2)->containsValues([$ids[0], $ids[2]]);
        $this->array(LegacyKnowbaseItem::getForCategory($category, $m_kbi))
            ->isIdenticalTo([-1]);
    }

    protected function testGetListRequestProvider(): array
    {
        return [
           [
              'params' => [
                 'knowbaseitemcategories_id' => 0,
                 'faq' => false,
                 'contains' => "test1 ",
              ],
              'type' => 'search'
           ],
           [
              'params' => [
                 'knowbaseitemcategories_id' => 0,
                 'faq' => false,
                 'contains' => "test1 / test2 ( test3 )",
              ],
              'type' => 'search'
           ]
        ];
    }

    /**
     * @dataprovider testGetListRequestProvider
     */
    public function testGetListRequest(array $params, string $type): void
    {
        global $DB;

        // Build criteria array
        $criteria = \KnowbaseItem::getListRequest($params, $type);
        $this->array($criteria);

        // Check that the request is valid
        $DB->request($criteria);
    }

    public function testGetAnswerAnchors(): void
    {
        // Create test KB with multiple headers
        $kb_name = 'Test testGetAnswerAnchors' . mt_rand();
        $input = [
           'name' => $kb_name,
           // Answer :
           // <h1>title 1a</h1>
           // <h2>title2</h2>
           // <h1>title 1b</h1>
           // <h1>title 1c</h1>
           'answer' => '&lt;h1&gt;title 1a&lt;/h1&gt;&lt;h2&gt;title2&lt;/h2&gt;&lt;h1&gt;title 1b&lt;/h1&gt;&lt;h1&gt;title 1c&lt;/h1&gt;'
        ];
        $this->createItems('KnowbaseItem', [$input]);

        // Load KB
        /** @var \KnowbaseItem */
        $kbi = getItemByTypeName("KnowbaseItem", $kb_name);
        $answer = $kbi->getAnswer();

        // Test anchors, there should be one per header
        $this->string($answer)->contains('<h1 id="title-1a">');
        $this->string($answer)->contains('<a href="#title-1a">');
        $this->string($answer)->contains('<h2 id="title2">');
        $this->string($answer)->contains('<a href="#title2">');
        $this->string($answer)->contains('<h1 id="title-1b">');
        $this->string($answer)->contains('<a href="#title-1b">');
        $this->string($answer)->contains('<h1 id="title-1c">');
        $this->string($answer)->contains('<a href="#title-1c">');
    }
}
