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
use ArrayObject;
use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use KnowbaseItem_Revision as RevisionModel;
use KnowbaseItemTranslation as ArticleTranslation;
use RuntimeException;
use Stringable;
use itsmng\Database\Entity\KnowbaseItemRevision as RevisionRecord;
use itsmng\Database\Orm;
use mock\DBmysql as RevisionRouteAdapter;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/knowbaseitem_revision.class.php */

class KnowbaseItem_Revision extends DbTestCase
{
    public function testRevisionNumbersAndTabCountsStayCurrentAroundLiveOwners(): void
    {
        global $DB;
        $session = $_SESSION;
        $owner = null;
        try {
            $this->login();
            $_SESSION['glpishow_count_on_tabs'] = 1;
            $article = $this->getNewKbItem();
            $this->boolean($article->getFromDB($article->getID()))->isTrue();
            $originalName = $article->fields['name'];
            $revisions = new RevisionModel();
            $this->boolean($article->update(['id' => $article->getID(), 'name' => 'First current revision']))->isTrue();
            $connection = $DB->getDoctrineConnection();
            $firstId = (int)$connection->fetchOne('SELECT id FROM glpi_knowbaseitems_revisions WHERE knowbaseitems_id=? AND language=? ORDER BY id', [$article->getID(), '']);
            $this->integer($firstId)->isGreaterThan(0);
            $owner = Orm::create($DB);
            $pending = $owner->find(RevisionRecord::class, $firstId);
            $pending->name = 'Unflushed independent revision';
            Orm::read($DB, function (EntityManager $outer) use ($article, $firstId, $revisions): void {
                $dirty = $outer->find(RevisionRecord::class, $firstId);
                $dirty->name = 'Unflushed enclosing revision';
                $this->boolean($article->update(['id' => $article->getID(), 'name' => 'Second current revision']))->isTrue();
                $this->string($revisions->getTabNameForItem($article))->isIdenticalTo("Revisions <sup class='tab_nb'>2</sup>");
                $this->boolean($outer->contains($dirty))->isTrue();
                $this->string($dirty->name)->isIdenticalTo('Unflushed enclosing revision');
            });
            $rows = $connection->fetchAllAssociative('SELECT revision, name FROM glpi_knowbaseitems_revisions WHERE knowbaseitems_id=? AND language=? ORDER BY revision', [$article->getID(), '']);
            $this->array(array_map('intval', array_column($rows, 'revision')))->isIdenticalTo([1, 2]);
            $this->array(array_column($rows, 'name'))->isIdenticalTo([$originalName, 'First current revision']);
            $translation = $this->createItem(ArticleTranslation::class, [
                'knowbaseitems_id' => $article->getID(), 'language' => 'fr_FR',
                'name' => 'Original translated revision', 'answer' => 'Original translated answer',
            ]);
            foreach (['First translated revision', 'Second translated revision'] as $name) {
                $this->boolean($translation->update(['id' => $translation->getID(), 'name' => $name]))->isTrue();
            }
            $translated = $connection->fetchAllAssociative('SELECT revision, name FROM glpi_knowbaseitems_revisions WHERE knowbaseitems_id=? AND language=? ORDER BY revision', [$article->getID(), 'fr_FR']);
            $this->array(array_map('intval', array_column($translated, 'revision')))->isIdenticalTo([1, 2]);
            $this->array(array_column($translated, 'name'))->isIdenticalTo(['Original translated revision', 'First translated revision']);
            $this->string($revisions->getTabNameForItem($translation))->isIdenticalTo("Revisions <sup class='tab_nb'>2</sup>");
            $this->boolean($owner->contains($pending))->isTrue();
            $this->string($pending->name)->isIdenticalTo('Unflushed independent revision');
        } finally {
            $owner?->clear();
            $_SESSION = $session;
        }
    }

    public function testRevisionCountPreservesCustomConstructionAndLanguageFailure(): void
    {
        global $DB;
        $original = $DB;
        $session = $_SESSION;
        try {
            $this->login();
            $_SESSION['glpishow_count_on_tabs'] = 1;
            $article = $this->getNewKbItem();
            $this->boolean($article->update(['id' => $article->getID(), 'name' => 'Custom route revision']))->isTrue();
            $trace = new ArrayObject();
            $selected = new class ($DB->getDoctrineConnection()) extends ScalarReadProbe {
                public object $original;
                public ArrayObject $trace;
                public EventManager $events;
                public int $clears = 0;
                public function getEventManager(): EventManager
                {
                    $this->trace[] = 'constructed';
                    $GLOBALS['DB'] = $this->original;
                    return $this->events;
                }
                public function onClear(): void
                {
                    ++$this->clears;
                }
            };
            $selected->original = $original;
            $selected->trace = $trace;
            $selected->events = new EventManager();
            $selected->events->addEventListener(['onClear'], $selected);
            $language = new class ($trace) implements Stringable {
                public ?RuntimeException $failure = null;
                public function __construct(private ArrayObject $trace)
                {
                }
                public function __toString(): string
                {
                    $this->trace[] = 'language';
                    if ($this->failure !== null) {
                        throw $this->failure;
                    }
                    return '';
                }
            };
            $item = new class () extends ArticleTranslation {
                public bool $parentCanUpdate;
                public static function getType()
                {
                    return ArticleTranslation::getType();
                }
                public function canUpdateItem()
                {
                    return $this->parentCanUpdate;
                }
            };
            $item->parentCanUpdate = $article->canUpdateItem();
            $this->boolean($item->parentCanUpdate)->isTrue();
            $item->fields = ['knowbaseitems_id' => $article->getID(), 'language' => $language];
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new RevisionRouteAdapter();
            $this->calling($adapter)->getDoctrineConnection = $selected;
            $revisions = new RevisionModel();
            $DB = $adapter;
            $this->string($revisions->getTabNameForItem($item))->isIdenticalTo("Revision <sup class='tab_nb'>1</sup>");
            $this->array($trace->getArrayCopy())->isIdenticalTo(['constructed', 'language']);
            $this->array($selected->queries)->hasSize(1);
            $failure = new RuntimeException('Refused revision language conversion');
            $language->failure = $failure;
            $DB = $adapter;
            $caught = null;
            try {
                $revisions->getTabNameForItem($item);
            } catch (RuntimeException $error) {
                $caught = $error;
            }
            $this->object($caught)->isIdenticalTo($failure);
            $this->array($selected->queries)->hasSize(1);
            $language->failure = null;
            $DB = $adapter;
            $this->string($revisions->getTabNameForItem($item))->isIdenticalTo("Revision <sup class='tab_nb'>1</sup>");
            $this->array($trace->getArrayCopy())->isIdenticalTo(['constructed', 'language', 'constructed', 'language', 'constructed', 'language']);
            $this->array($selected->queries)->hasSize(2);
            $this->integer($selected->clears)->isIdenticalTo(0);
        } finally {
            $DB = $original;
            $_SESSION = $session;
        }
    }

    public function afterTestMethod($method)
    {
        global $DB;
        $DB->delete('glpi_knowbaseitems_revisions', [true]);
        parent::afterTestMethod($method);
    }

    public function testGetTypeName()
    {
        $expected = 'Revision';
        $this->string(\KnowbaseItem_Revision::getTypeName(1))->isIdenticalTo($expected);

        $expected = 'Revisions';
        $this->string(\KnowbaseItem_Revision::getTypeName(0))->isIdenticalTo($expected);
        $this->string(\KnowbaseItem_Revision::getTypeName(2))->isIdenticalTo($expected);
        $this->string(\KnowbaseItem_Revision::getTypeName(10))->isIdenticalTo($expected);
    }

    public function testNewRevision()
    {
        global $DB;
        $this->login();

        $kb1 = $this->getNewKbItem();

        $where = [
           'knowbaseitems_id' => $kb1->getID(),
           'language'         => ''
        ];

        $nb = countElementsInTable(
            'glpi_knowbaseitems_revisions',
            $where
        );
        $this->integer((int)$nb)->isIdenticalTo(0);

        $this->boolean(
            $kb1->update(
                [
                 'id'   => $kb1->getID(),
                 'name' => '_knowbaseitem01-01'
            ]
            )
        )->isTrue();

        $nb = countElementsInTable(
            'glpi_knowbaseitems_revisions',
            $where
        );
        $this->integer((int)$nb)->isIdenticalTo(1);

        $rev_id = null;
        $data = $DB->request([
           'SELECT' => ['MIN' => 'id as id'],
           'FROM'   => 'glpi_knowbaseitems_revisions'
        ])->next();
        $rev_id = $data['id'];

        $kb1->getFromDB($kb1->getID());
        $this->boolean($kb1->revertTo($rev_id))->isTrue();

        $nb = countElementsInTable(
            'glpi_knowbaseitems_revisions',
            $where
        );
        $this->integer((int)$nb)->isIdenticalTo(2);

        //try a change on contents
        $this->boolean(
            $kb1->update(
                [
                 'id'     => $kb1->getID(),
                 'answer' => \Toolbox::addslashes_deep('Don\'t use paths with spaces, like C:\\Program Files\\MyApp')
            ]
            )
        )->isTrue();

        $this->boolean(
            $kb1->update(
                [
                 'id'     => $kb1->getID(),
                 'answer' => 'Answer changed'
            ]
            )
        )->isTrue();

        $nb = countElementsInTable(
            'glpi_knowbaseitems_revisions',
            $where
        );
        $this->integer((int)$nb)->isIdenticalTo(4);

        $nrev_id = null;
        $data = $DB->request([
           'SELECT' => new \QueryExpression('MAX(id) AS id'),
           'FROM'   => 'glpi_knowbaseitems_revisions'
        ])->next();
        $nrev_id = $data['id'];

        $this->boolean($kb1->getFromDB($kb1->getID()))->isTrue();
        $this->boolean($kb1->revertTo($nrev_id))->isTrue();

        $this->boolean($kb1->getFromDB($kb1->getID()))->isTrue();
        $this->string($kb1->fields['answer'])->isIdenticalTo('Don\'t use paths with spaces, like C:\\Program Files\\MyApp');

        //reset
        $this->boolean($kb1->getFromDB($kb1->getID()))->isTrue();
        $this->boolean($kb1->revertTo($rev_id))->isTrue();

    }

    public function testGetTabNameForItemNotLogged()
    {
        //we are not logged, we should not see revision tab
        $kb_rev = new \KnowbaseItem_Revision();
        $kb1 = $this->getNewKbItem();

        $_SESSION['glpishow_count_on_tabs'] = 1;
        $name = $kb_rev->getTabNameForItem($kb1);
        $this->string($name)->isIdenticalTo('');
    }

    public function testGetTabNameForItemLogged()
    {
        $this->login();

        $kb_rev = new \KnowbaseItem_Revision();
        $kb1 = $this->getNewKbItem();

        $this->boolean(
            $kb1->update(
                [
                 'id'   => $kb1->getID(),
                 'name' => '_knowbaseitem01-01'
            ]
            )
        )->isTrue();

        $_SESSION['glpishow_count_on_tabs'] = 1;
        $name = $kb_rev->getTabNameForItem($kb1);
        $this->string($name)->isIdenticalTo('Revision <sup class=\'tab_nb\'>1</sup>');

        $this->boolean(
            $kb1->update(
                [
                 'id'   => $kb1->getID(),
                 'name' => '_knowbaseitem01-02'
            ]
            )
        )->isTrue();

        $name = $kb_rev->getTabNameForItem($kb1);
        $this->string($name)->isIdenticalTo('Revisions <sup class=\'tab_nb\'>2</sup>');

        $_SESSION['glpishow_count_on_tabs'] = 0;
        $name = $kb_rev->getTabNameForItem($kb1);
        $this->string($name)->isIdenticalTo('Revisions');
    }

    private function getNewKbItem()
    {
        $kb1 = getItemByTypeName(\KnowbaseItem::getType(), '_knowbaseitem01');
        $toadd = $kb1->fields;
        unset($toadd['id']);
        unset($toadd['date_creation']);
        unset($toadd['date_mod']);
        $toadd['name'] = $this->getUniqueString();
        $this->integer((int)$kb1->add($toadd))->isGreaterThan(0);
        return $kb1;
    }
}
