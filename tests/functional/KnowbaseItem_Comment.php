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
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostLoadEventArgs;
use itsmng\Database\Entity\KnowbaseItem as ArticleRecord;
use itsmng\Database\Entity\KnowbaseItemComment as CommentRecord;
use itsmng\Database\Entity\User as UserRecord;
use itsmng\Database\Orm;
use KnowbaseItem as ApplicationArticle;
use KnowbaseItem_Comment as ApplicationComment;
use KnowbaseItemTranslation as ArticleTranslation;
use mock\DBmysql as CommentAdapterProbe;
use tests\fixtures\ScalarReadProbe;
use UnexpectedValueException;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/knowbaseitem_comment.class.php */

/**
 * @engine isolate
 */
class KnowbaseItem_Comment extends DbTestCase
{
    public function testMaterializedCommentsKeepLanguagesAndLiveOwners(): void
    {
        global $DB;
        $this->login();
        $connection = $DB->getDoctrineConnection();
        $owner = Orm::create($DB);
        $article = new ArticleRecord();
        $article->name = 'Comment projection ' . $this->getUniqueString();
        $article->is_faq = true;
        $article->users = $owner->getReference(UserRecord::class, (int)$_SESSION['glpiID']);
        $owner->persist($article);
        $root = new CommentRecord();
        $root->knowbaseitems = $article;
        $root->comment = 'Original root';
        $reply = new CommentRecord();
        $reply->knowbaseitems = $article;
        $reply->parent_comment = $root;
        $reply->comment = 'Original reply';
        $translated = new CommentRecord();
        $translated->knowbaseitems = $article;
        $translated->language = 'fr_FR';
        $translated->comment = 'French comment';
        $empty = new CommentRecord();
        $empty->knowbaseitems = $article;
        $empty->language = '';
        $empty->comment = 'Empty language';
        foreach ([$root, $reply, $translated, $empty] as $comment) {
            $comment->users = $article->users;
            $owner->persist($comment);
        }
        $owner->flush();
        $tree = ApplicationComment::getCommentsForKbItem((string)$article->id, 'NULL');
        $this->array(array_column($tree, 'id'))->isIdenticalTo([$root->id]);
        $this->array(array_column($tree[0]['answers'], 'id'))->isIdenticalTo([$reply->id]);
        $this->array(ApplicationComment::getCommentsForKbItem($article->id, null, 0))->isEmpty();
        $this->array(array_column(ApplicationComment::getCommentsForKbItem($article->id, null, (string)$root->id), 'id'))
            ->isIdenticalTo([$reply->id]);
        $this->array(array_column(ApplicationComment::getCommentsForKbItem($article->id, ''), 'id'))
            ->isIdenticalTo([$empty->id]);
        $model = new ApplicationArticle();
        $this->boolean($model->getFromDB($article->id))->isTrue();
        $translation = new class () extends ArticleTranslation {
            public static function getType()
            {
                return ArticleTranslation::getType();
            }
            public function canUpdateItem(): bool
            {
                return true;
            }
        };
        $translation->fields = ['knowbaseitems_id' => (string)$article->id, 'language' => 'fr_FR'];
        $tabs = new ApplicationComment();
        $previousCount = $_SESSION['glpishow_count_on_tabs'];
        try {
            $_SESSION['glpishow_count_on_tabs'] = 1;
            $this->string($tabs->getTabNameForItem($translation))->isIdenticalTo("Comments <sup class='tab_nb'>1</sup>");
            $this->output(static fn () => ApplicationComment::showForItem($translation))->contains('French comment');
            $connection->update('glpi_knowbaseitems_comments', ['comment' => 'Current root'], ['id' => $root->id]);
            $connection->update('glpi_knowbaseitems_comments', ['language' => null], ['id' => $translated->id]);
            $this->string($tabs->getTabNameForItem($translation))->isIdenticalTo('Comments');
            $this->output(static fn () => ApplicationComment::showForItem($translation))->contains('No comments');
            $fresh = ApplicationComment::getCommentsForKbItem($article->id, null);
            $this->array(array_column($fresh, 'id'))->isIdenticalTo([$root->id, $translated->id]);
            $this->string($fresh[0]['comment'])->isIdenticalTo('Current root');
            $this->string($tree[0]['comment'])->isIdenticalTo('Original root');
            $this->output(static fn () => ApplicationComment::showForItem($model))->contains('Current root');
            $root->comment = 'Unflushed independent root';
            Orm::read($DB, function (EntityManager $outer) use ($article, $root, $owner, $fresh, $tabs, $translation): void {
                $owned = $outer->find(CommentRecord::class, $root->id);
                $owned->comment = 'Unflushed enclosing root';
                $this->array(ApplicationComment::getCommentsForKbItem($article->id, null))->isIdenticalTo($fresh);
                $this->string($tabs->getTabNameForItem($translation))->isIdenticalTo('Comments');
                $this->boolean($outer->contains($owned))->isTrue();
                $this->string($owned->comment)->isIdenticalTo('Unflushed enclosing root');
                $this->boolean($owner->contains($root))->isTrue();
                $this->string($root->comment)->isIdenticalTo('Unflushed independent root');
            });
        } finally {
            $_SESSION['glpishow_count_on_tabs'] = $previousCount;
        }
    }

    public function testCustomCommentReadPinsRouteBeforeLanguageConversion(): void
    {
        global $DB;
        $original = $DB;
        try {
            $owner = Orm::create($DB);
            $article = new ArticleRecord();
            $article->name = 'Custom comment ' . $this->getUniqueString();
            $comment = new CommentRecord();
            $comment->knowbaseitems = $article;
            $comment->language = 'NULL';
            $comment->comment = 'Stored custom comment';
            $owner->persist($article);
            $owner->persist($comment);
            $owner->flush();
            $connection = $DB->getDoctrineConnection();
            $observer = new class () {
                public array $trace = [];
                public int $clears = 0;
                public function postLoad(PostLoadEventArgs $event): void
                {
                    if ($event->getObject() instanceof CommentRecord) {
                        $event->getObject()->comment = 'Custom loaded comment';
                    }
                }
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
            $selected->events->addEventListener(['postLoad', 'onClear'], $observer);
            $other = new ScalarReadProbe($connection);
            $route = $selected;
            $this->mockGenerator()->orphanize('__construct');
            $adapter = new CommentAdapterProbe();
            $this->calling($adapter)->getDoctrineConnection = static function () use (&$route) {
                return $route;
            };
            $this->calling($adapter)->getProvider = $original->getProvider();
            $DB = $adapter;
            $language = new class ($this, $connection, $observer, $other, $route) {
                public function __construct(
                    private object $test,
                    private Connection $connection,
                    private object $observer,
                    private Connection $other,
                    private Connection &$route
                ) {
                }
                public function __toString(): string
                {
                    $this->test->boolean($this->connection->isApplicationEntityManagerActive())->isFalse();
                    $this->observer->trace[] = 'language';
                    $this->route = $this->other;
                    return 'NULL';
                }
            };
            $rows = ApplicationComment::getCommentsForKbItem((string)$article->id, $language);
            $this->array($observer->trace)->isIdenticalTo(['constructed', 'language']);
            $this->array(array_column($rows, 'id'))->isIdenticalTo([$comment->id]);
            $this->string($rows[0]['comment'])->isIdenticalTo('Custom loaded comment');
            $this->array($other->queries)->isEmpty();
            $this->array($selected->queries)->isNotEmpty();
            $this->integer($observer->clears)->isIdenticalTo(0);
            $this->string($connection->fetchOne('SELECT comment FROM glpi_knowbaseitems_comments WHERE id=?', [$comment->id]))
                ->isIdenticalTo('Stored custom comment');
        } finally {
            $DB = $original;
        }
    }

    public function testCommentCycleFailureLeavesNextReadUsable(): void
    {
        global $DB;
        $owner = Orm::create($DB);
        $article = new ArticleRecord();
        $article->name = 'Comment cycle ' . $this->getUniqueString();
        $root = new CommentRecord();
        $root->knowbaseitems = $article;
        $reply = new CommentRecord();
        $reply->knowbaseitems = $article;
        $reply->parent_comment = $root;
        foreach ([$article, $root, $reply] as $record) {
            $owner->persist($record);
        }
        $owner->flush();
        $connection = $DB->getDoctrineConnection();
        $connection->update('glpi_knowbaseitems_comments', ['parent_comment_id' => $reply->id], ['id' => $root->id]);
        try {
            $this->exception(static fn () => ApplicationComment::getCommentsForKbItem($article->id, null, $root->id))
                ->isInstanceOf(UnexpectedValueException::class)
                ->hasMessage('Cycle in knowledge-base comment ancestry.');
            $this->boolean($connection->isApplicationEntityManagerActive())->isFalse();
        } finally {
            $connection->update('glpi_knowbaseitems_comments', ['parent_comment_id' => null], ['id' => $root->id]);
        }
        $tree = ApplicationComment::getCommentsForKbItem($article->id, null);
        $this->array(array_column($tree, 'id'))->isIdenticalTo([$root->id]);
        $this->array(array_column($tree[0]['answers'], 'id'))->isIdenticalTo([$reply->id]);
    }

    public function testGetTypeName()
    {
        $expected = 'Comment';
        $this->string(\KnowbaseItem_Comment::getTypeName(1))->isIdenticalTo($expected);

        $expected = 'Comments';
        foreach ([0, 2, 10] as $i) {
            $this->string(\KnowbaseItem_Comment::getTypeName($i))->isIdenticalTo($expected);
        }
    }

    public function testGetCommentsForKbItem()
    {
        $kb1 = getItemByTypeName(\KnowbaseItem::getType(), '_knowbaseitem01');

        //first, set data
        $this->addComments($kb1);
        $this->addComments($kb1, 'fr_FR');

        $nb = countElementsInTable(
            'glpi_knowbaseitems_comments'
        );
        $this->integer((int)$nb)->isIdenticalTo(10);

        // second, test what we retrieve
        $comments = \KnowbaseItem_Comment::getCommentsForKbItem($kb1->getID(), null);
        $this->array($comments)->hasSize(2);
        $this->array($comments[0])->hasSize(9);
        $this->array($comments[0]['answers'])->hasSize(2);
        $this->array($comments[0]['answers'][0]['answers'])->hasSize(1);
        $this->array($comments[0]['answers'][1]['answers'])->hasSize(0);
        $this->array($comments[1])->hasSize(9);
        $this->array($comments[1]['answers'])->hasSize(0);
    }

    /**
     * Add comments into database
     *
     * @param KnowbaseItem $kb   KB item instance
     * @param string       $lang KB item language, defaults to null
     *
     * @return void
     */
    private function addComments(\KnowbaseItem $kb, $lang = 'NULL')
    {
        $this->login();
        $kbcom = new \KnowbaseItem_Comment();
        $input = [
           'knowbaseitems_id' => $kb->getID(),
           'users_id'         => getItemByTypeName('User', TU_USER, true),
           'comment'          => 'Comment 1 for KB1',
           'language'         => $lang
        ];
        $kbcom1 = $kbcom->add($input);
        $this->boolean($kbcom1 > 0)->isTrue();

        $input['comment'] = 'Comment 2 for KB1';
        $kbcom2 = $kbcom->add($input);
        $this->boolean($kbcom2 > $kbcom1)->isTrue();

        //this one is from another user.
        $input['comment'] = 'Comment 1 - 1 for KB1';
        $input['parent_comment_id'] = $kbcom1;
        $input['users_id'] = getItemByTypeName('User', 'glpi', true);
        $kbcom11 = $kbcom->add($input);
        $this->boolean($kbcom11 > $kbcom2)->isTrue();

        $input['comment'] = 'Comment 1 - 2 for KB1';
        $input['users_id'] = getItemByTypeName('User', TU_USER, true);
        $kbcom12 = $kbcom->add($input);
        $this->boolean($kbcom12 > $kbcom11)->isTrue();

        $input['comment'] = 'Comment 1 - 1 - 1 for KB1';
        $input['parent_comment_id'] = $kbcom11;
        $kbcom111 = $kbcom->add($input);
        $this->boolean($kbcom111 > $kbcom12)->isTrue();
    }

    public function testGetTabNameForItemNotLogged()
    {
        //we are not logged, we should not see comment tab
        $kb1 = getItemByTypeName(\KnowbaseItem::getType(), '_knowbaseitem01');
        $kbcom = new \KnowbaseItem_Comment();

        $name = $kbcom->getTabNameForItem($kb1, true);
        $this->string($name)->isIdenticalTo('');
    }

    public function testGetTabNameForItemLogged()
    {
        $this->login();

        $kb1 = getItemByTypeName(\KnowbaseItem::getType(), '_knowbaseitem01');
        $this->addComments($kb1);
        $kbcom = new \KnowbaseItem_Comment();

        $name = $kbcom->getTabNameForItem($kb1, true);
        $this->string($name)->isIdenticalTo('Comments <sup class=\'tab_nb\'>5</sup>');

        $_SESSION['glpishow_count_on_tabs'] = 1;
        $name = $kbcom->getTabNameForItem($kb1);
        $this->string($name)->isIdenticalTo('Comments <sup class=\'tab_nb\'>5</sup>');

        $_SESSION['glpishow_count_on_tabs'] = 0;
        $name = $kbcom->getTabNameForItem($kb1);
        $this->string($name)->isIdenticalTo('Comments');
    }

    public function testDisplayComments()
    {
        $kb1 = getItemByTypeName(\KnowbaseItem::getType(), '_knowbaseitem01');
        $this->addComments($kb1);

        $html = \KnowbaseItem_Comment::displayComments(
            \KnowbaseItem_Comment::getCommentsForKbItem($kb1->getID(), null),
            true
        );

        preg_match_all("/li class='comment'/", $html, $results);
        $this->array($results[0])->hasSize(2);

        preg_match_all("/li class='comment subcomment'/", $html, $results);
        $this->array($results[0])->hasSize(3);

        preg_match_all("/span class='fa fa-pencil-square-o edit_item'/", $html, $results);
        $this->array($results[0])->hasSize(4);

        preg_match_all("/span class='add_answer'/", $html, $results);
        $this->array($results[0])->hasSize(5);

        //same tests, from another user
        $auth = new \Auth();
        $result = $auth->login('itsm', 'itsm', true);
        $this->boolean($result)->isTrue();

        $html = \KnowbaseItem_Comment::displayComments(
            \KnowbaseItem_Comment::getCommentsForKbItem($kb1->getID(), null),
            true
        );

        preg_match_all("/li class='comment'/", $html, $results);
        $this->array($results[0])->hasSize(2);

        preg_match_all("/li class='comment subcomment'/", $html, $results);
        $this->array($results[0])->hasSize(3);

        preg_match_all("/span class='fa fa-pencil-square-o edit_item'/", $html, $results);
        $this->integer(count($results[0]))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);

        preg_match_all("/span class='add_answer'/", $html, $results);
        $this->array($results[0])->hasSize(5);
    }
}
