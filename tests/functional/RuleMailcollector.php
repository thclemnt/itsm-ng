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
use BlacklistedMailContent;
use NotImportedEmail;
use TypeError;
use Doctrine\Common\EventManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\OnClearEventArgs;
use itsmng\Database\Entity\BlacklistedMailContent as BlacklistedContentRecord;
use itsmng\Database\Entity\NotImportedEmail as RejectedEmailRecord;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use MailCollector;
use itsmng\Database\Entity\MailCollector as MailCollectorRecord;
use itsmng\Database\Orm;
use tests\fixtures\ScalarReadProbe;
use mock\DBmysql as CollectorReadRoute;
use Group;
use Group_User;
use Rule;
use RuleAction;
use RuleCriteria;
use RuleMailCollectorCollection;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

class RuleMailCollector extends DbTestCase
{
    public function testLocalMailCorpusReadsKeepCurrentTextAndDirtyOwners(): void
    {
        global $DB;
        $this->login();
        $collector = new MailCollector();
        $token = 'mailcorpus' . strtolower($this->getUniqueString());
        $next = $token . 'next';
        $pendingText = $token . 'pending';
        $blacklist = $this->createItem(
            BlacklistedMailContent::class,
            ['name' => $token, 'content' => $token]
        );
        $rejected = $this->createItem(
            NotImportedEmail::class,
            ['mailcollectors_id' => null, 'messageid' => $token, 'subject' => 'NULL', 'date' => $_SESSION['glpi_currenttime']]
        );
        $owner = Orm::create($DB);
        try {
            $dirty = $owner->find(BlacklistedContentRecord::class, $blacklist->getID());
            $dirty->content = $pendingText;
            Orm::read(
                $DB,
                function (EntityManager $outer) use ($collector, $blacklist, $rejected, $token, $next, $pendingText): void {
                    $live = $outer->find(BlacklistedContentRecord::class, $blacklist->getID());
                    $live->content = $pendingText;
                    $email = $outer->find(RejectedEmailRecord::class, $rejected->getID());
                    $email->subject = 'Pending rejected subject';
                    $this->string($collector->cleanContent($token))->isEmpty();
                    $this->string($collector->cleanContent($pendingText))->isIdenticalTo('pending');
                    // NULL collector rows stay logged, but are never sent to a mailbox.
                    $collector->deleteOrImportSeveralEmails([$rejected->getID(), $rejected->getID(), 0, -1, 'NULL']);
                    $this->boolean($rejected->getFromDB($rejected->getID()))->isTrue();
                    $this->variable($rejected->fields['mailcollectors_id'])->isNull();
                    $this->string($rejected->fields['subject'])->isIdenticalTo('NULL');
                    $this->boolean($blacklist->update(['id' => $blacklist->getID(), 'content' => $next]))->isTrue();
                    $this->string($collector->cleanContent($token))->isIdenticalTo($token);
                    $this->string($collector->cleanContent($next))->isEmpty();
                    $this->boolean($outer->contains($live))->isTrue();
                    $this->string($live->content)->isIdenticalTo($pendingText);
                    $this->boolean($outer->contains($email))->isTrue();
                    $this->string($email->subject)->isIdenticalTo('Pending rejected subject');
                }
            );
            $this->boolean($owner->contains($dirty))->isTrue();
            $this->string($dirty->content)->isIdenticalTo($pendingText);
            $this->boolean($blacklist->update(['id' => $blacklist->getID(), 'content' => null]))->isTrue();
            $this->string($collector->cleanContent($next))->isIdenticalTo($next);
            $this->boolean($blacklist->update(['id' => $blacklist->getID(), 'content' => 'NULL']))->isTrue();
            $this->string($collector->cleanContent('NULL'))->isEmpty();
            $this->string($collector->cleanContent('null'))->isIdenticalTo('null');
            $this->boolean($blacklist->getFromDB($blacklist->getID()))->isTrue();
            $this->string($blacklist->fields['content'])->isIdenticalTo('NULL');
        } finally {
            $owner->clear();
        }
    }

    public function testLocalMailCorpusCustomPreparationAndRecoveryStaySelected(): void
    {
        global $DB;
        $this->login();
        $original = $DB;
        $connection = $original->getDoctrineConnection();
        $collector = new MailCollector();
        $token = 'mailcustom' . strtolower($this->getUniqueString());
        $this->createItem(BlacklistedMailContent::class, ['name' => $token, 'content' => $token]);
        $listener = new class () {
            public int $clears = 0;

            public function onClear(OnClearEventArgs $event): void
            {
                ++$this->clears;
            }
        };
        $events = new EventManager();
        $events->addEventListener([Events::onClear], $listener);
        $probe = new class ($connection, $events, $original) extends ScalarReadProbe {
            public int $constructions = 0;

            public function __construct($connection, private EventManager $events, private object $original)
            {
                parent::__construct($connection);
            }

            public function getEventManager(): EventManager
            {
                ++$this->constructions;
                $GLOBALS['DB'] = $this->original;
                return $this->events;
            }
        };
        $this->mockGenerator()->orphanize('__construct');
        $adapter = new CollectorReadRoute();
        $this->calling($adapter)->getDoctrineConnection = $probe;
        try {
            $DB = $adapter;
            $this->exception(static fn () => $collector->deleteOrImportSeveralEmails('invalid-array'))
                ->isInstanceOf(TypeError::class);
            $this->array($probe->queries)->isEmpty();
            $this->integer($probe->constructions)->isIdenticalTo(1);
            $this->integer($listener->clears)->isIdenticalTo(0);
            $DB = $adapter;
            $collector->deleteOrImportSeveralEmails([0, -1, 'NULL']);
            $this->array($probe->queries)->isEmpty();
            $DB = $adapter;
            $this->string($collector->cleanContent($token))->isEmpty();
            $this->integer($probe->constructions)->isIdenticalTo(3);
            $this->integer($listener->clears)->isIdenticalTo(0);
            $this->array($probe->queries)->hasSize(1);
            $this->string($probe->queries[0]['sql'])->contains('glpi_blacklistedmailcontents');
        } finally {
            $DB = $original;
        }
    }

    public function testLocalCollectorCountsAndRenderingRetainCompletedReadOwnership(): void
    {
        global $DB;
        $this->login();
        $database = $DB;
        $connection = $DB->getDoctrineConnection();
        $owner = null;
        try {
            $all = MailCollector::countCollectors();
            $active = MailCollector::countActiveCollectors();
            $collector = $this->createItem(MailCollector::class, [
                'name' => $this->getUniqueString(), 'is_active' => true,
            ]);
            $owner = Orm::create($database);
            $pending = $owner->find(MailCollectorRecord::class, $collector->getID());
            $pending->name = 'Pending collector observer';
            Orm::read($database, function (EntityManager $outer) use ($collector, $all, $active): void {
                $live = $outer->find(MailCollectorRecord::class, $collector->getID());
                $live->name = 'Pending enclosing collector observer';
                $this->integer(MailCollector::countActiveCollectors())->isIdenticalTo($active + 1);
                $this->integer(MailCollector::countCollectors(1))->isIdenticalTo($all + 1);
                ob_start();
                try {
                    $collector->showSystemInformations(200);
                    $this->string(ob_get_contents())->contains($collector->fields['name'])
                        ->notContains('Pending enclosing collector observer');
                } finally {
                    ob_end_clean();
                }
                $this->boolean($outer->contains($live))->isTrue();
                $this->string($live->name)->isIdenticalTo('Pending enclosing collector observer');
            });
            $fresh = $this->getUniqueString();
            $connection->update(
                'glpi_mailcollectors',
                ['name' => $fresh, 'is_active' => false],
                ['id' => $collector->getID()],
                ['is_active' => Types::BOOLEAN]
            );
            $probe = new ScalarReadProbe($connection);
            $this->mockGenerator->orphanize('__construct');
            $route = new CollectorReadRoute();
            $this->calling($route)->getDoctrineConnection = $probe;
            $DB = $route;
            $this->integer(MailCollector::countActiveCollectors())->isIdenticalTo($active);
            $this->integer(MailCollector::countCollectors())->isIdenticalTo($all + 1);
            ob_start();
            try {
                $collector->showSystemInformations(200);
                $this->string(ob_get_contents())->contains($fresh)->notContains('Pending collector observer');
            } finally {
                ob_end_clean();
            }
            $this->array($probe->queries)->hasSize(3);
            $this->boolean($owner->contains($pending))->isTrue();
            $this->string($pending->name)->isIdenticalTo('Pending collector observer');
        } finally {
            $DB = $database;
            $owner?->clear();
        }
    }

    public function testAssignEntityBasedOnGroup()
    {
        $entity_id      = getItemByTypeName('Entity', '_test_child_1', true);
        $normal_user_id = getItemByTypeName('User', 'normal', true);

        $this->login();

        // Delete all existing rule
        $rule     = new Rule();
        $rule->deleteByCriteria(['sub_type' => 'RuleMailCollector']);

        // Create new group
        $group = new Group();
        $group_id = $group->add($group_input = [
           'name' => 'group1',
        ]);
        $this->checkInput($group, $group_id, $group_input);

        // Create rule
        $rule     = new \RuleMailCollector();
        $rule_id = $rule->add($rule_input = [
           'name'         => 'test assign entity based on group',
           'match'        => 'AND',
           'is_active'    => 1,
           'sub_type'     => 'RuleMailCollector',
        ]);
        $this->checkInput($rule, $rule_id, $rule_input);

        // Create criteria to check if requester group matches a specific group
        $criteria = new RuleCriteria();
        $criteria_id = $criteria->add($criteria_input = [
           'rules_id'  => $rule_id,
           'criteria'  => '_groups_id_requester',
           'condition' => Rule::PATTERN_IS,
           'pattern'   => $group_id,
        ]);
        $this->checkInput($criteria, $criteria_id, $criteria_input);

        // Create action to assign entity
        $action   = new RuleAction();
        $action_id = $action->add($action_input = [
           'rules_id'    => $rule_id,
           'action_type' => 'assign',
           'field'       => 'entities_id',
           'value'       => $entity_id,
        ]);
        $this->checkInput($action, $action_id, $action_input);

        // Check rules output: no rule should match
        $rulecollection = new RuleMailCollectorCollection();
        $output         = $rulecollection->processAllRules(
            [],
            [],
            [
              'mailcollector'       => 0,
              '_users_id_requester' => $normal_user_id,
         ]
        );

        $this->array($output)->isEqualTo(
            [
              '_no_rule_matches' => '1',
              '_rule_process'    => '',
         ]
        );

        // Add "normal" user to group
        $group_user = new Group_User();
        $group_user_id = $group_user->add($group_user_input = [
           'groups_id' => $group_id,
           'users_id'  => $normal_user_id,
        ]);
        $this->checkInput($group_user, $group_user_id, $group_user_input);

        // Check rules output: new rule should match
        $rulecollection = new RuleMailCollectorCollection();
        $output         = $rulecollection->processAllRules(
            [],
            [],
            [
              'mailcollector'       => 0,
              '_users_id_requester' => $normal_user_id,
         ]
        );

        $this->array($output)->isEqualTo(
            [
              'entities_id' => $entity_id,
              '_ruleid'     => $rule_id,
         ]
        );
    }

    public function testRejectEmailActions()
    {
        $this->login();

        // Isolate this scenario from previously created mail collector rules.
        $cleanup = new Rule();
        $cleanup->deleteByCriteria(['sub_type' => 'RuleMailCollector']);

        $rule = new \RuleMailCollector();
        $criteria = new RuleCriteria();
        $action = new RuleAction();

        $pattern = 'reject-' . $this->getUniqueString();
        $rules_id = $rule->add([
           'name'         => 'reject mail ' . $pattern,
           'match'        => 'AND',
           'is_active'    => 1,
           'sub_type'     => 'RuleMailCollector',
        ]);
        $this->integer((int)$rules_id)->isGreaterThan(0);

        $criteria_id = $criteria->add([
           'rules_id'  => $rules_id,
           'criteria'  => 'subject',
           'condition' => Rule::PATTERN_CONTAIN,
           'pattern'   => $pattern,
        ]);
        $this->integer((int)$criteria_id)->isGreaterThan(0);

        $action_id = $action->add([
           'rules_id'    => $rules_id,
           'action_type' => 'assign',
           'field'       => '_refuse_email_no_response',
           'value'       => 1,
        ]);
        $this->integer((int)$action_id)->isGreaterThan(0);

        $action_id = $action->add([
           'rules_id'    => $rules_id,
           'action_type' => 'assign',
           'field'       => '_refuse_email_with_response',
           'value'       => 1,
        ]);
        $this->integer((int)$action_id)->isGreaterThan(0);

        $collection = new RuleMailCollectorCollection();
        $input = [
           'mailcollector'       => 0,
           '_users_id_requester' => getItemByTypeName('User', 'normal', true),
           'headers'             => [
              'subject' => 'email ' . $pattern,
           ],
        ];
        $output = $collection->processAllRules([], [], $input);

        $this->array($output)->hasKeys([
            '_refuse_email_no_response',
            '_refuse_email_with_response',
            '_ruleid',
        ]);
        $this->string((string)$output['_refuse_email_no_response'])->isEqualTo('1');
        $this->string((string)$output['_refuse_email_with_response'])->isEqualTo('1');
        $this->integer((int)$output['_ruleid'])->isEqualTo((int)$rules_id);
    }
}
