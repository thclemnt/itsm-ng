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

use CommonITILActor;
use CommonITILValidation;
use DbTestCase;
use Computer;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\InvalidArgumentException as InvalidDbalArgument;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManager;
use ReflectionProperty;
use Stringable;
use NetworkPort as LegacyNetworkPort;
use NetworkPortInstantiation;
use RuleAction;
use RuleTicket as LegacyRuleTicket;
use mock\DBmysql as MacReadAdapter;
use tests\fixtures\ScalarReadProbe;
use itsmng\Database\Entity\NetworkPort as NetworkPortRecord;
use itsmng\Database\Orm;
use Group_User;
use Ticket_User;
use Toolbox;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/ruleticket.class.php */

class RuleTicket extends DbTestCase
{
    public function testMacAssignmentKeepsDuplicateParentsCurrentAndOwned(): void
    {
        global $DB;
        $this->login();
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $entity = (int)$computer->getEntityID();
        $this->setEntity($entity, true);
        $prefix = $this->getUniqueString();
        $mac = '02:aa:bb:' . implode(':', str_split(substr(hash('sha256', $prefix), 0, 6), 2));
        $deleted = $this->createItem(
            Computer::class,
            [
                'name' => $prefix . '-deleted', 'entities_id' => $entity, 'is_deleted' => 1,
            ]
        );
        $ports = [];
        foreach ([$computer, $computer, $deleted] as $index => $parent) {
            $ports[] = $this->createItem(
                LegacyNetworkPort::class,
                [
                    'itemtype' => 'Computer', 'items_id' => $parent->getID(), 'entities_id' => $entity,
                    'name' => $prefix . '-' . $index, 'mac' => $mac, 'instantiation_type' => null,
                ]
            );
        }
        $ids = static function (array $chains): array {
            $values = array_map(static fn (array $chain): int => (int)$chain[array_key_last($chain)]->getID(), $chains);
            sort($values);
            return $values;
        };
        $expected = array_map(static fn (LegacyNetworkPort $port): int => (int)$port->getID(), $ports);
        sort($expected);
        $independent = Orm::create($DB);
        try {
            $dirty = $independent->find(NetworkPortRecord::class, $ports[0]->getID());
            $dirty->name = 'Independent pending MAC owner';
            Orm::read($DB, function (EntityManager $outer) use ($ports, $mac, $ids, $expected): void {
                $pending = $outer->find(NetworkPortRecord::class, $ports[0]->getID());
                $pending->name = 'Enclosing pending MAC owner';
                $chains = NetworkPortInstantiation::getItemsByMac(strtoupper($mac));
                $this->array($ids($chains))->isIdenticalTo($expected);
                foreach ($chains as $chain) {
                    $this->string($chain[array_key_last($chain)]->fields['name'])->notContains('pending MAC owner');
                }
                $this->boolean($outer->contains($pending))->isTrue();
                $this->string($pending->name)->isIdenticalTo('Enclosing pending MAC owner');
            });
            $this->boolean($independent->contains($dirty))->isTrue();
            $this->string($dirty->name)->isIdenticalTo('Independent pending MAC owner');
            // Despite the historical method name, duplicate MACs choose the first eligible owner.
            $identity = NetworkPortInstantiation::getUniqueItemByMac($mac, $entity);
            $this->integer((int)$identity['id'])->isIdenticalTo((int)$computer->getID());
            $this->string($identity['itemtype'])->isIdenticalTo('Computer');
            $this->array(NetworkPortInstantiation::getUniqueItemByMac($mac, -1))->isEmpty();
            $rule = $this->createItem(
                LegacyRuleTicket::class,
                [
                    'name' => $prefix, 'match' => 'OR', 'is_active' => 1,
                    'sub_type' => 'RuleTicket', 'condition' => LegacyRuleTicket::ONADD,
                ]
            );
            $this->createItem(
                RuleAction::class,
                [
                    'rules_id' => $rule->getID(), 'field' => 'affectobject',
                    'action_type' => 'affectbymac', 'value' => strtoupper($mac),
                ]
            );
            $this->boolean($rule->getRuleWithCriteriasAndActions($rule->getID(), 0, 1))->isTrue();
            $output = $rule->executeActions([], ['entities_id' => $entity]);
            $this->array($output['items_id'])->isIdenticalTo(['Computer' => [$computer->getID()]]);
            foreach (array_slice($ports, 0, 2) as $port) {
                $this->boolean($port->update(['id' => $port->getID(), 'mac' => '02:00:00:00:00:00']))->isTrue();
            }
            $this->array($ids(NetworkPortInstantiation::getItemsByMac($mac)))->isIdenticalTo([(int)$ports[2]->getID()]);
            $this->array(NetworkPortInstantiation::getUniqueItemByMac($mac, $entity))->isEmpty();
            $unchanged = ['Printer' => [0]];
            $this->array($rule->executeActions(['items_id' => $unchanged], ['entities_id' => $entity])['items_id'])->isIdenticalTo($unchanged);
            $this->boolean($independent->contains($dirty))->isTrue();
            $this->string($dirty->name)->isIdenticalTo('Independent pending MAC owner');
        } finally {
            $independent->clear();
        }
    }

    public function testMacIdentityBindingsAndCustomRouteKeepNativeContracts(): void
    {
        global $DB;
        $this->login();
        $database = $DB;
        $connection = $database->getDoctrineConnection();
        $computer = getItemByTypeName('Computer', '_test_pc01');
        $this->setEntity((int)$computer->getEntityID(), true);
        $text = "literal'\\mac-" . strtolower($this->getUniqueString());
        $ports = [];
        foreach ([$text, 'NULL', null, '', '0'] as $mac) {
            $port = $this->createItem(
                LegacyNetworkPort::class,
                [
                    'itemtype' => 'Computer', 'items_id' => $computer->getID(),
                    'name' => $this->getUniqueString(), 'instantiation_type' => null,
                ]
            );
            // Native fixture values deliberately exercise the read contract, not MAC UI validation.
            $connection->update('glpi_networkports', ['mac' => $mac], ['id' => $port->getID()]);
            $this->boolean($port->getFromDB($port->getID()))->isTrue();
            $this->variable($port->fields['mac'])->isIdenticalTo($mac);
            $ports[] = $port;
        }
        $ids = static function (array $chains): array {
            $values = array_map(static fn (array $chain): int => (int)$chain[array_key_last($chain)]->getID(), $chains);
            sort($values);
            return $values;
        };
        $nativeIds = static function (string $predicate, array $params = []) use ($connection): array {
            $values = array_map('intval', $connection->fetchFirstColumn('SELECT id FROM glpi_networkports WHERE ' . $predicate, $params));
            sort($values);
            return $values;
        };
        $this->array($ids(NetworkPortInstantiation::getItemsByMac(addslashes($text))))->isIdenticalTo($nativeIds('mac = ?', [$text]));
        foreach (['NULL', 'null'] as $sentinel) {
            $this->array($ids(NetworkPortInstantiation::getItemsByMac($sentinel)))->isIdenticalTo($nativeIds('mac IS NULL'));
        }
        foreach (['', '0'] as $value) {
            $this->array($ids(NetworkPortInstantiation::getItemsByMac($value)))->isIdenticalTo($nativeIds('mac = ?', [$value]));
        }
        $operator = $database->getComparisonOperator('LIKE');
        foreach ([['null', '%null%'], ['*null*', '%null%'], [addslashes($text), '%' . $text . '%']] as [$value, $pattern]) {
            $this->array($ids(NetworkPortInstantiation::getItemsByMac($value, true)))->isIdenticalTo($nativeIds('mac ' . $operator . ' ?', [$pattern]));
        }
        $rule = $this->createItem(
            LegacyRuleTicket::class,
            ['name' => $this->getUniqueString(), 'sub_type' => 'RuleTicket', 'condition' => LegacyRuleTicket::ONADD]
        );
        $this->createItem(
            RuleAction::class,
            ['rules_id' => $rule->getID(), 'field' => 'affectobject', 'action_type' => 'affectbymac', 'value' => '02:00:00:00:00:00']
        );
        $this->boolean($rule->getRuleWithCriteriasAndActions($rule->getID(), 0, 1))->isTrue();
        $selected = new class ($connection, $database) extends ScalarReadProbe {
            public int $unitsAtQuery = 0;
            public ?DbalException $failure = null;

            public function __construct($connection, private object $original)
            {
                parent::__construct($connection);
            }

            public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
            {
                $this->unitsAtQuery = (\DbTestCase::ormManagerCounter())->getValue();
                if ($this->failure !== null) {
                    throw $this->failure;
                }
                $result = parent::executeQuery($sql, $params, $types, $qcp);
                // Public port/parent callbacks retain their own later native reader route.
                $GLOBALS['DB'] = $this->original;
                return $result;
            }
        };
        $this->mockGenerator()->orphanize('__construct');
        $adapter = new MacReadAdapter();
        $resolutions = 0;
        $this->calling($adapter)->getDoctrineConnection = static function () use ($selected, &$resolutions) {
            ++$resolutions;
            return $selected;
        };
        try {
            $DB = $adapter;
            $units = (\DbTestCase::ormManagerCounter())->getValue();
            $value = new class ($text) implements Stringable {
                public int $conversions = 0;

                public function __construct(private string $value)
                {
                }

                public function __toString(): string
                {
                    ++$this->conversions;
                    return addslashes($this->value);
                }
            };
            $this->array($ids(NetworkPortInstantiation::getItemsByMac($value)))->isIdenticalTo([(int)$ports[0]->getID()]);
            $this->integer($value->conversions)->isIdenticalTo(1);
            $this->integer($resolutions)->isIdenticalTo(1);
            $this->integer(count($selected->queries))->isIdenticalTo(1);
            $this->array($selected->queries[0]['params'])->contains($text);
            $this->integer($selected->unitsAtQuery)->isIdenticalTo($units);
            $DB = $adapter;
            $units = (\DbTestCase::ormManagerCounter())->getValue();
            $failure = new InvalidDbalArgument('Refused MAC identity read');
            $selected->failure = $failure;
            $this->exception(static fn () => $rule->executeActions([], ['entities_id' => $computer->getEntityID()]))->isIdenticalTo($failure);
            $this->integer($resolutions)->isIdenticalTo(2);
            $this->integer($selected->unitsAtQuery)->isIdenticalTo($units);
            $selected->failure = null;
            $DB = $adapter;
            $this->array($ids(NetworkPortInstantiation::getItemsByMac(addslashes($text))))->isIdenticalTo([(int)$ports[0]->getID()]);
        } finally {
            $DB = $database;
        }
    }

    public function testGetCriteria()
    {
        $rule = new \RuleTicket();
        $criteria = $rule->getCriterias();
        $this->array($criteria)->size->isGreaterThan(20);
    }

    public function testGetActions()
    {
        $rule = new \RuleTicket();
        $actions  = $rule->getActions();
        $this->array($actions)->size->isGreaterThan(20);
    }

    public function testDefaultRuleExists()
    {
        $this->integer(
            (int)countElementsInTable(
                'glpi_rules',
                [
                 'name' => 'Ticket location from item',
                 'is_active' => 0
            ]
            )
        )->isIdenticalTo(1);
        $this->integer(
            (int)countElementsInTable(
                'glpi_rules',
                [
                 'name' => 'Ticket location from use',
                 'is_active' => 1
            ]
            )
        )->isIdenticalTo(0);
    }

    public function testTriggerAdd()
    {
        $this->login();

        // prepare rule
        $this->_createTestTriggerRule(\RuleTicket::ONADD);

        // test create ticket (trigger on title)
        $ticket = new \Ticket();
        $tickets_id = $ticket->add($ticket_input = [
           'name'    => "test ticket, will trigger on rule (title)",
           'content' => "test"
        ]);
        $this->checkInput($ticket, $tickets_id, $ticket_input);
        $this->integer((int)$ticket->getField('urgency'))->isEqualTo(5);

        // test create ticket (trigger on user assign)
        $ticket = new \Ticket();
        $tickets_id = $ticket->add($ticket_input = [
           'name'             => "test ticket, will trigger on rule (user)",
           'content'          => "test",
           '_users_id_assign' => getItemByTypeName('User', "tech", true)
        ]);
        // _users_id_assign is stored in glpi_tickets_users table, so remove it
        unset($ticket_input['_users_id_assign']);
        $this->checkInput($ticket, $tickets_id, $ticket_input);
        $this->integer((int)$ticket->getField('urgency'))->isEqualTo(5);
    }

    public function testTriggerUpdate()
    {
        $this->login();
        $this->setEntity('Root entity', true);

        $users_id = (int) getItemByTypeName('User', 'tech', true);

        // prepare rule
        $this->_createTestTriggerRule(\RuleTicket::ONUPDATE);

        // test create ticket (for check triggering on title after update)
        $ticket = new \Ticket();
        $tickets_id = $ticket->add($ticket_input = [
           'name'    => "test ticket, will not trigger on rule",
           'content' => "test"
        ]);
        $this->checkInput($ticket, $tickets_id, $ticket_input);
        $this->integer((int)$ticket->getField('urgency'))->isEqualTo(3);

        // update ticket title and trigger rule on title updating
        $ticket->update([
           'id'   => $tickets_id,
           'name' => 'test ticket, will trigger on rule (title)'
        ]);
        $ticket->getFromDB($tickets_id);
        $this->integer((int)$ticket->getField('urgency'))->isEqualTo(5);

        // test create ticket (for check triggering on actor after update)
        $ticket = new \Ticket();
        $tickets_id = $ticket->add($ticket_input = [
           'name'    => "test ticket, will not trigger on rule (actor)",
           'content' => "test"
        ]);
        $this->checkInput($ticket, $tickets_id, $ticket_input);
        $this->integer((int)$ticket->getField('urgency'))->isEqualTo(3);

        // update ticket title and trigger rule on actor addition
        $ticket->update([
           'id'           => $tickets_id,
           'content'      => "updated",
           '_lgd'         => true,
           '_itil_assign' => [
              '_type'    => 'user',
              'users_id' => $users_id
           ]
        ]);
        $ticket->getFromDB($tickets_id);
        $ticket_user = new \Ticket_User();
        $actors = $ticket_user->getActors($tickets_id);
        $this->integer((int)$actors[2][1]['users_id'])->isEqualTo($users_id);
        $this->integer((int)$ticket->getField('urgency'))->isEqualTo(5);
    }

    private function _createTestTriggerRule($condition)
    {
        $ruleticket = new \RuleTicket();
        $rulecrit   = new \RuleCriteria();
        $ruleaction = new \RuleAction();

        $ruletid = $ruleticket->add($ruletinput = [
           'name'         => "test rule add",
           'match'        => 'OR',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => $condition,
           'is_recursive' => 1
        ]);
        $this->checkInput($ruleticket, $ruletid, $ruletinput);
        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $ruletid,
           'criteria'  => 'name',
           'condition' => \Rule::PATTERN_CONTAIN,
           'pattern'   => "trigger on rule (title)"
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);
        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $ruletid,
           'criteria'  => '_users_id_assign',
           'condition' => \Rule::PATTERN_IS,
           'pattern'   => getItemByTypeName('User', "tech", true)
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);
        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $ruletid,
           'action_type' => 'assign',
           'field'       => 'urgency',
           'value'       => 5
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);
    }

    /**
     * Test status criterion in rules.
     */
    public function testStatusCriterion()
    {
        $this->login();

        // Create rule
        $ruleticket = new \RuleTicket();
        $rulecrit   = new \RuleCriteria();
        $ruleaction = new \RuleAction();

        $ruletid = $ruleticket->add($ruletinput = [
           'name'         => 'test status criterion',
           'match'        => 'AND',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONADD,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $ruletid, $ruletinput);

        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $ruletid,
           'criteria'  => 'status',
           'condition' => \Rule::PATTERN_IS,
           'pattern'   => \Ticket::INCOMING,
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $ruletid,
           'criteria'  => '_users_id_assign',
           'condition' => \Rule::PATTERN_IS,
           'pattern'   => getItemByTypeName('User', 'tech', true)
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $ruletid,
           'action_type' => 'assign',
           'field'       => 'status',
           'value'       => \Ticket::WAITING,
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        // Check ticket that trigger rule on creation
        $ticket = new \Ticket();
        $tickets_id = $ticket->add($ticket_input = [
           'name'             => 'change status to waiting if new and assigned to tech',
           'content'          => 'test',
           '_users_id_assign' => getItemByTypeName('User', 'tech', true)
        ]);
        unset($ticket_input['_users_id_assign']); // _users_id_assign is stored in glpi_tickets_users table, so remove it
        $this->checkInput($ticket, $tickets_id, $ticket_input);
        $this->integer((int)$ticket->getField('status'))->isEqualTo(\Ticket::WAITING);
    }

    /**
     * Test that new status setting by rules is not overrided when an actor is assigned at the same time.
     */
    public function testStatusAssignNewFromRule()
    {
        $this->login();

        // Create rule
        $ruleticket = new \RuleTicket();
        $rulecrit   = new \RuleCriteria();
        $ruleaction = new \RuleAction();

        $ruletid = $ruleticket->add($ruletinput = [
           'name'         => 'test assign new actor and keep new status',
           'match'        => 'OR',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONADD | \RuleTicket::ONUPDATE,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $ruletid, $ruletinput);

        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $ruletid,
           'criteria'  => 'name',
           'condition' => \Rule::PATTERN_CONTAIN,
           'pattern'   => 'assign to tech',
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $ruletid,
           'action_type' => 'assign',
           'field'       => '_users_id_assign',
           'value'       => getItemByTypeName('User', 'tech', true),
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $ruletid,
           'action_type' => 'assign',
           'field'       => 'status',
           'value'       => \Ticket::INCOMING,
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        // Check ticket that trigger rule on creation
        $ticket = new \Ticket();
        $tickets_id = $ticket->add($ticket_input = [
           'name'    => 'assign to tech (on creation)',
           'content' => 'test'
        ]);
        $this->checkInput($ticket, $tickets_id, $ticket_input);
        $this->integer((int)$ticket->getField('status'))->isEqualTo(\Ticket::INCOMING);
        $this->integer(countElementsInTable(
            \Ticket_User::getTable(),
            ['tickets_id' => $tickets_id, 'type' => \CommonITILActor::ASSIGN]
        ))->isEqualTo(1);

        // Remove assign self as default tech from session
        $default_tech = $_SESSION['glpiset_default_tech'];
        $_SESSION['glpiset_default_tech'] = false;

        // Check ticket that trigger rule on update
        $ticket = new \Ticket();
        $tickets_id = $ticket->add($ticket_input = [
           'name'    => 'some ticket',
           'content' => 'test'
        ]);
        $this->checkInput($ticket, $tickets_id, $ticket_input);
        $this->integer((int)$ticket->getField('status'))->isEqualTo(\Ticket::INCOMING);
        $this->integer(countElementsInTable(
            \Ticket_User::getTable(),
            ['tickets_id' => $tickets_id, 'type' => \CommonITILActor::ASSIGN]
        ))->isEqualTo(0);

        $this->boolean($ticket->update([
           'id'      => $tickets_id,
           'name'    => 'assign to tech (on update)',
           'content' => 'test'
        ]))->isTrue();
        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $this->integer((int)$ticket->getField('status'))->isEqualTo(\Ticket::INCOMING);
        $this->integer(countElementsInTable(
            \Ticket_User::getTable(),
            ['tickets_id' => $tickets_id, 'type' => \CommonITILActor::ASSIGN]
        ))->isEqualTo(1);

        // Restore assign self as default tech in session
        $_SESSION['glpiset_default_tech'] = $default_tech;
    }

    public function testITILCategoryAssignFromRule()
    {
        $this->login();

        // Create ITILCategory with code
        $ITILCategoryForAdd = new \ITILCategory();
        $ITILCategoryForAddId = $ITILCategoryForAdd->add($categoryinput = [
           "name" => "ITIL Category",
           "code" => "itil_category_for_add",
        ]);

        $this->integer((int)$ITILCategoryForAddId)->isGreaterThan(0);

        // Create ITILCategory with code
        $ITILCategoryForUpdate = new \ITILCategory();
        $ITILCategoryForUpdateId = $ITILCategoryForUpdate->add($categoryinput = [
           "name" => "ITIL Category",
           "code" => "itil_category_for_update",
        ]);

        $this->integer((int)$ITILCategoryForUpdateId)->isGreaterThan(0);

        // Create rule
        $ruleticket = new \RuleTicket();
        $rulecrit   = new \RuleCriteria();
        $ruleaction = new \RuleAction();

        $ruletid = $ruleticket->add($ruletinput = [
           'name'         => 'test to assign ITILCategory',
           'match'        => 'OR',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONADD | \RuleTicket::ONUPDATE,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $ruletid, $ruletinput);

        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $ruletid,
           'criteria'  => 'content',
           'condition' => \Rule::REGEX_MATCH,
           'pattern'   => '/#(.*?)#/',
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $ruletid,
           'action_type' => 'regex_result',
           'field'       => '_affect_itilcategory_by_code',
           'value'       => '#0',
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        // Check ticket that trigger rule on add
        $ticket = new \Ticket();
        $tickets_id = $ticket->add($ticket_input = [
           'name'    => 'some ticket (on insert)',
           'content' => 'some text #itil_category_for_add# some text'
        ]);

        $this->checkInput($ticket, $tickets_id, $ticket_input);
        $this->integer((int)$ticket->getField('itilcategories_id'))->isEqualTo($ITILCategoryForAddId);

        $this->boolean($ticket->update($ticket_input = [
           'id'      => $tickets_id,
           'name'    => 'some ticket (on update)',
           'content' => 'some text #itil_category_for_update# some text'
        ]))->isTrue();

        $this->checkInput($ticket, $tickets_id, $ticket_input);
        $this->integer((int)$ticket->getField('itilcategories_id'))->isEqualTo($ITILCategoryForUpdateId);

    }

    public function testITILSolutionAssignFromRule()
    {
        $this->login();

        // Create solution template
        $solutionTemplate = new \SolutionTemplate();
        $solutionTemplate_id = $solutionTemplate->add($solutionInput = [
           'content' => Toolbox::addslashes_deep("content of solution template  white ' quote")
        ]);
        $this->integer((int)$solutionTemplate_id)->isGreaterThan(0);

        // Create rule
        $ruleticket = new \RuleTicket();
        $rulecrit   = new \RuleCriteria();
        $ruleaction = new \RuleAction();

        $ruletid = $ruleticket->add($ruletinput = [
           'name'         => "test to assign ITILSolution",
           'match'        => 'OR',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONUPDATE,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $ruletid, $ruletinput);

        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $ruletid,
           'criteria'  => 'content',
           'condition' => \Rule::REGEX_MATCH,
           'pattern'   => '/(.*?)/',
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $ruletid,
           'action_type' => 'assign',
           'field'       => 'solution_template',
           'value'       => $solutionTemplate_id,
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        $ticket = new \Ticket();
        $tickets_id = $ticket->add($ticket_input = [
           'name'    => 'some ticket',
           'content' => 'some text some text'
        ]);

        $this->checkInput($ticket, $tickets_id, $ticket_input);
        $this->integer((int)$tickets_id)->isGreaterThan(0);

        // update ticket content and trigger rule on content updating
        $ticket->update([
           'id'   => $tickets_id,
           'content' => 'test ticket, will trigger on rule (content)'
        ]);

        //load ITILSolution
        $itilSolution = new \ITILSolution();
        $this->boolean($itilSolution->getFromDBByCrit(['items_id'            => $tickets_id,
                                                     'itemtype'              => 'Ticket',
                                                     'content'               => Toolbox::addslashes_deep("content of solution template  white ' quote")]))->isTrue();

        $this->integer((int)$itilSolution->getID())->isGreaterThan(0);

        //reload and check ticket status
        $ticket->getFromDB($tickets_id);
        $this->integer((int)$ticket->getField('status'))->isEqualTo(\CommonITILObject::SOLVED);

    }

    public function testAssignGroup()
    {
        $this->login();

        //create new group1
        $group1 = new \Group();
        $group_id1 = $group1->add($group_input1 = [
           "name" => "group1",
           "is_requester" => true
        ]);
        $this->checkInput($group1, $group_id1, $group_input1);

        //create new group2
        $group2 = new \Group();
        $group_id2 = $group2->add($group_input2 = [
           "name" => "group2",
           "is_requester" => true
        ]);
        $this->checkInput($group2, $group_id2, $group_input2);

        // Create rule
        $ruleticket = new \RuleTicket();
        $rulecrit   = new \RuleCriteria();
        $ruleaction = new \RuleAction();

        $ruletid = $ruleticket->add($ruletinput = [
           'name'         => 'test add group on add',
           'match'        => 'AND',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONADD,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $ruletid, $ruletinput);

        //create criteria to check
        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $ruletid,
           'criteria'  => 'content',
           'condition' => \Rule::PATTERN_EXISTS,
           'pattern'   => 1,
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        //create action to add group as group requester
        $action_id = $ruleaction->add($action_input = [
           'rules_id'    => $ruletid,
           'action_type' => 'add',
           'field'       => '_groups_id_requester',
           'value'       => $group_id1,
        ]);
        $this->checkInput($ruleaction, $action_id, $action_input);

        //create rule for assign
        $ruletid_assign = $ruleticket->add($ruletinput_assign = [
           'name'         => 'test assign group on add',
           'match'        => 'AND',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONADD,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $ruletid_assign, $ruletinput_assign);

        //create criteria to check
        $crit_id_assign = $rulecrit->add($crit_input_assing = [
           'rules_id'  => $ruletid_assign,
           'criteria'  => 'content',
           'condition' => \Rule::PATTERN_EXISTS,
           'pattern'   => 1,
        ]);
        $this->checkInput($rulecrit, $crit_id_assign, $crit_input_assing);

        //create action to assign group as group requester
        $action_id_assign = $ruleaction->add($action_input_assign = [
           'rules_id'    => $ruletid_assign,
           'action_type' => 'assign',
           'field'       => '_groups_id_requester',
           'value'       => $group_id2,
        ]);
        $this->checkInput($ruleaction, $action_id_assign, $action_input_assign);

        // Create ticket
        $ticket = new \Ticket();
        $tickets_id = $ticket->add($ticket_input = [
           'name'             => 'when assigning delete groups to add',
           'content'          => 'test',
        ]);
        $this->checkInput($ticket, $tickets_id, $ticket_input);

        //load TicketGroup1 (expected false)
        $ticketGroup = new \Group_Ticket();
        $this->boolean(
            $ticketGroup->getFromDBByCrit([
              'tickets_id'         => $tickets_id,
              'groups_id'          => $group_id1,
              'type'               => \CommonITILActor::REQUESTER
         ])
        )->isFalse();

        //load TicketGroup2 (expected true)
        $ticketGroup = new \Group_Ticket();
        $this->boolean(
            $ticketGroup->getFromDBByCrit([
              'tickets_id'         => $tickets_id,
              'groups_id'          => $group_id2,
              'type'               => \CommonITILActor::REQUESTER
         ])
        )->isTrue();
    }

    public function testGroupRequesterAssignFromDefaultUserOnCreate()
    {
        $this->login();

        // Create rule
        $ruleticket = new \RuleTicket();
        $rulecrit   = new \RuleCriteria();
        $ruleaction = new \RuleAction();

        $ruletid = $ruleticket->add($ruletinput = [
           'name'         => 'test group requester criterion',
           'match'        => 'AND',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONADD,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $ruletid, $ruletinput);

        //create criteria to check if group requester already define
        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $ruletid,
           'criteria'  => '_groups_id_requester',
           'condition' => \Rule::PATTERN_DOES_NOT_EXISTS,
           'pattern'   => 1,
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        //create action to put default user group as group requester
        $action_id = $ruleaction->add($action_input = [
           'rules_id'    => $ruletid,
           'action_type' => 'defaultfromuser',
           'field'       => '_groups_id_requester',
           'value'       => 1,
        ]);
        $this->checkInput($ruleaction, $action_id, $action_input);

        //create new group
        $group = new \Group();
        $group_id = $group->add($group_input = [
           "name" => "group1",
           "is_requester" => true
        ]);
        $this->checkInput($group, $group_id, $group_input);

        //Load user tech
        $user = new \User();
        $user->getFromDB(getItemByTypeName('User', 'tech', true));

        //add user to group
        $group_user = new Group_User();
        $group_user_id = $group_user->add($group_user_input = [
           "groups_id" => $group_id,
           "users_id"  => $user->fields['id']
        ]);
        $this->checkInput($group_user, $group_user_id, $group_user_input);

        //add default group to user
        $user->fields['groups_id'] = $group_id;
        $this->boolean($user->update($user->fields))->isTrue();

        // Check ticket that trigger rule on creation
        $ticket = new \Ticket();
        $tickets_id = $ticket->add($ticket_input = [
           'name'             => 'Add group requester if requester have default group',
           'content'          => 'test',
           '_users_id_requester' => $user->fields['id']
        ]);
        unset($ticket_input['_users_id_requester']); // _users_id_requester is stored in glpi_tickets_users table, so remove it
        $this->checkInput($ticket, $tickets_id, $ticket_input);

        //load TicketGroup
        $ticketGroup = new \Group_Ticket();
        $this->boolean(
            $ticketGroup->getFromDBByCrit([
              'tickets_id'         => $tickets_id,
              'groups_id'          => $group_id,
              'type'               => \CommonITILActor::REQUESTER
         ])
        )->isTrue();
    }

    public function testGroupRequesterAssignFromDefaultUserAndLocationFromUserOnUpdate()
    {
        $this->login();

        // Create rule
        $ruleticket = new \RuleTicket();
        $rulecrit   = new \RuleCriteria();
        $ruleaction = new \RuleAction();

        $ruletid = $ruleticket->add($ruletinput = [
           'name'         => 'test group requester from user on update',
           'match'        => 'AND',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONUPDATE,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $ruletid, $ruletinput);

        //create criteria to check an update
        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $ruletid,
           'criteria'  => 'content',
           'condition' => \Rule::PATTERN_EXISTS,
           'pattern'   => 1,
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        //create action to put default user group as group requester
        $action_id = $ruleaction->add($action_input = [
           'rules_id'    => $ruletid,
           'action_type' => 'defaultfromuser',
           'field'       => '_groups_id_requester',
           'value'       => 1,
        ]);
        $this->checkInput($ruleaction, $action_id, $action_input);

        //create action to put user location as ticket location
        $action_id = $ruleaction->add($action_input = [
           'rules_id'    => $ruletid,
           'action_type' => 'fromuser',
           'field'       => 'locations_id',
           'value'       => 1,
        ]);
        $this->checkInput($ruleaction, $action_id, $action_input);

        //create new group
        $group = new \Group();
        $group_id = $group->add($group_input = [
           "name" => "group1",
           "is_requester" => true
        ]);
        $this->checkInput($group, $group_id, $group_input);

        //Load user tech
        $user = new \User();
        $user->getFromDB(getItemByTypeName('User', 'tech', true));

        //add user to group
        $group_user = new Group_User();
        $group_user_id = $group_user->add($group_user_input = [
           "groups_id" => $group_id,
           "users_id"  => $user->fields['id']
        ]);
        $this->checkInput($group_user, $group_user_id, $group_user_input);

        //add default group to user
        $user->fields['groups_id'] = $group_id;
        $this->boolean($user->update($user->fields))->isTrue();

        //create new location
        $location = new \Location();
        $location_id = $location->add($location_input = [
           "name" => "location1",
        ]);
        $this->checkInput($location, $location_id, $location_input);

        //add location to user
        $user->fields['locations_id'] = $location_id;
        $this->boolean($user->update($user->fields))->isTrue();

        // Create ticket
        $ticket = new \Ticket();
        $tickets_id = $ticket->add($ticket_input = [
           'name'             => 'Add group requester if requester have default group',
           'content'          => 'test',
           '_users_id_requester' => $user->fields['id']
        ]);
        unset($ticket_input['_users_id_requester']); // _users_id_requester is stored in glpi_tickets_users table, so remove it
        $this->checkInput($ticket, $tickets_id, $ticket_input);

        // No location is assigned before the update rule runs.
        $this->variable($ticket->fields['locations_id'])->isNull();

        //load TicketGroup (expected false)
        $ticketGroup = new \Group_Ticket();
        $this->boolean(
            $ticketGroup->getFromDBByCrit([
              'tickets_id'         => $tickets_id,
              'groups_id'          => $group_id,
              'type'               => \CommonITILActor::REQUESTER
         ])
        )->isFalse();

        //Update ticket to trigger rule
        $ticket->update($ticket_input = [
           'id' => $tickets_id,
           'content' => 'test on update'
        ]);
        $this->checkInput($ticket, $tickets_id, $ticket_input);

        //load TicketGroup
        $ticketGroup = new \Group_Ticket();
        $this->boolean(
            $ticketGroup->getFromDBByCrit([
              'tickets_id'         => $tickets_id,
              'groups_id'          => $group_id,
              'type'               => \CommonITILActor::REQUESTER
         ])
        )->isTrue();

        //locations_id must be set to
        $ticket->getFromDB($tickets_id);
        $this->integer($ticket->fields['locations_id'])->isIdenticalTo($location_id);

    }

    public function testITILCategoryCode()
    {
        $this->login();

        // Create rule
        $ruleticket = new \RuleTicket();
        $rulecrit   = new \RuleCriteria();
        $ruleaction = new \RuleAction();

        $ruletid = $ruleticket->add($ruletinput = [
           'name'         => 'test category code',
           'match'        => 'AND',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONADD,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $ruletid, $ruletinput);

        // Create criteria to check if category code is R
        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $ruletid,
           'criteria'  => 'itilcategories_id_code',
           'condition' => \Rule::PATTERN_IS,
           'pattern'   => 'R',
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        // Create action to put impact to very low
        $action_id = $ruleaction->add($action_input = [
           'rules_id'    => $ruletid,
           'action_type' => 'assign',
           'field'       => 'impact',
           'value'       => 1,
        ]);
        $this->checkInput($ruleaction, $action_id, $action_input);

        // Create new group
        $category = new \ITILCategory();
        $category_id = $category->add($category_input = [
           "name" => "group1",
           "code" => "R"
        ]);
        $this->checkInput($category, $category_id, $category_input);

        // Check ticket that trigger rule on creation
        $ticket = new \Ticket();
        $tickets_id = $ticket->add($ticket_input = [
           'name'              => 'test category code',
           'content'           => 'test category code',
           'itilcategories_id' => $category_id
        ]);
        $this->checkInput($ticket, $tickets_id, $ticket_input);

        // Check that the rule was executed
        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $this->integer($ticket->fields['impact'])->isEqualTo(1);

        // Create another ticket that doesn't match the rule
        $tickets_id = $ticket->add($ticket_input = [
           'name'              => 'test category code',
           'content'           => 'test category code',
           'itilcategories_id' => 0
        ]);
        // Empty dropdown selections are nullable foreign keys after the ORM migration.
        $this->checkInput($ticket, $tickets_id, array_replace($ticket_input, ['itilcategories_id' => null]));

        // Check that the rule was NOT executed
        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $this->integer($ticket->fields['impact'])->isNotEqualTo(1);
    }

    public function testHeaderCriteria(): void
    {
        $this->login();

        $ruleticket = new \RuleTicket();
        $rulecrit   = new \RuleCriteria();
        $ruleaction = new \RuleAction();

        $ruletid = $ruleticket->add($ruletinput = [
           'name'         => 'test x-priority',
           'match'        => 'AND',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONADD,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $ruletid, $ruletinput);

        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $ruletid,
           'criteria'  => '_x-priority',
           'condition' => \Rule::PATTERN_IS,
           'pattern'   => '1',
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        // Create action to put priority to very high
        $action_id = $ruleaction->add($action_input = [
           'rules_id'    => $ruletid,
           'action_type' => 'assign',
           'field'       => 'priority',
           'value'       => 5,
        ]);
        $this->checkInput($ruleaction, $action_id, $action_input);

        // Create ticket with "x-priority" = 1 in "_head" property
        $ticket = new \Ticket();
        $tickets_id = $ticket->add([
           'name'              => 'test x-priority header',
           'content'           => 'test x-priority header',
           '_head'             => [
              'x-priority' => '1'
           ]
        ]);

        // Verify ticket has priority 5
        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $this->integer($ticket->fields['priority'])->isEqualTo(5);

        // Retest ticket with different x-priority
        $tickets_id = $ticket->add([
           'name'              => 'test x-priority header',
           'content'           => 'test x-priority header',
           '_head'             => [
              'x-priority' => '2'
           ]
        ]);

        // Verify ticket does not have priority 5
        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $this->integer($ticket->fields['priority'])->isNotEqualTo(5);
    }

    public function testAppendActionsAreClearedByAssign()
    {
        $this->login();

        $users_id_first = getItemByTypeName('User', 'tech', true);
        $users_id_second = getItemByTypeName('User', 'normal', true);

        $ruleticket = new \RuleTicket();
        $rulecrit = new \RuleCriteria();
        $ruleaction = new \RuleAction();

        $append_pattern = 'append-only-' . $this->getUniqueString();
        $rule_append_id = $ruleticket->add($rule_append_input = [
           'name'         => 'append rule ' . $append_pattern,
           'match'        => 'AND',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONADD,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $rule_append_id, $rule_append_input);

        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $rule_append_id,
           'criteria'  => 'name',
           'condition' => \Rule::PATTERN_CONTAIN,
           'pattern'   => $append_pattern,
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $rule_append_id,
           'action_type' => 'append',
           'field'       => '_users_id_assign',
           'value'       => $users_id_first,
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $rule_append_id,
           'action_type' => 'append',
           'field'       => '_users_id_assign',
           'value'       => $users_id_second,
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        $rule_append = new \RuleTicket();
        $this->boolean($rule_append->getRuleWithCriteriasAndActions($rule_append_id, 1, 1))->isTrue();
        $input = [
           'name'    => $append_pattern,
           'content' => 'test'
        ];
        $output = [];
        $params = [];
        $rule_append->process($input, $output, $params);

        $this->array($output)->hasKey('_additional_assigns');
        $this->array($output['_additional_assigns'])->hasSize(2);
        $assign_ids = array_column($output['_additional_assigns'], 'users_id');
        $this->array($assign_ids)->contains($users_id_first)->contains($users_id_second);

        $assign_pattern = 'append-assign-' . $this->getUniqueString();
        $rule_assign_id = $ruleticket->add($rule_assign_input = [
           'name'         => 'assign rule ' . $assign_pattern,
           'match'        => 'AND',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONADD,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $rule_assign_id, $rule_assign_input);

        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $rule_assign_id,
           'criteria'  => 'name',
           'condition' => \Rule::PATTERN_CONTAIN,
           'pattern'   => $assign_pattern,
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $rule_assign_id,
           'action_type' => 'append',
           'field'       => '_users_id_assign',
           'value'       => $users_id_first,
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $rule_assign_id,
           'action_type' => 'append',
           'field'       => '_users_id_assign',
           'value'       => $users_id_second,
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $rule_assign_id,
           'action_type' => 'assign',
           'field'       => '_users_id_assign',
           'value'       => $users_id_first,
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        $rule_assign = new \RuleTicket();
        $this->boolean($rule_assign->getRuleWithCriteriasAndActions($rule_assign_id, 1, 1))->isTrue();
        $input = [
           'name'    => $assign_pattern,
           'content' => 'test'
        ];
        $output = [];
        $params = [];
        $rule_assign->process($input, $output, $params);

        $this->array($output)->notHasKey('_additional_assigns');
        $this->integer((int)$output['_users_id_assign'])->isEqualTo($users_id_first);
    }

    public function testStatusAssignmentSetsDoNotComputeStatus()
    {
        $this->login();

        $ruleticket = new \RuleTicket();
        $rulecrit = new \RuleCriteria();
        $ruleaction = new \RuleAction();

        $pattern = 'status-flag-' . $this->getUniqueString();
        $rules_id = $ruleticket->add($rule_input = [
           'name'         => 'status flag ' . $pattern,
           'match'        => 'AND',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONADD,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $rules_id, $rule_input);

        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $rules_id,
           'criteria'  => 'name',
           'condition' => \Rule::PATTERN_CONTAIN,
           'pattern'   => $pattern,
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $rules_id,
           'action_type' => 'assign',
           'field'       => 'status',
           'value'       => \Ticket::INCOMING,
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        $rule_instance = new \RuleTicket();
        $this->boolean($rule_instance->getRuleWithCriteriasAndActions($rules_id, 1, 1))->isTrue();
        $input = [
           'name'    => $pattern,
           'content' => 'test'
        ];
        $output = [];
        $params = [];
        $rule_instance->process($input, $output, $params);

        $this->boolean($output['_rule_process'])->isTrue();
        $this->integer((int)$output['status'])->isEqualTo(\Ticket::INCOMING);
        $this->boolean($output['_do_not_compute_status'])->isTrue();
    }

    public function testUrgencyImpactRecomputePriority()
    {
        $this->login();

        $ruleticket = new \RuleTicket();
        $rulecrit = new \RuleCriteria();
        $ruleaction = new \RuleAction();

        $pattern = 'priority-recompute-' . $this->getUniqueString();
        $rules_id = $ruleticket->add($rule_input = [
           'name'         => 'priority recompute ' . $pattern,
           'match'        => 'AND',
           'is_active'    => 1,
           'sub_type'     => 'RuleTicket',
           'condition'    => \RuleTicket::ONADD,
           'is_recursive' => 1,
        ]);
        $this->checkInput($ruleticket, $rules_id, $rule_input);

        $crit_id = $rulecrit->add($crit_input = [
           'rules_id'  => $rules_id,
           'criteria'  => 'name',
           'condition' => \Rule::PATTERN_CONTAIN,
           'pattern'   => $pattern,
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $rules_id,
           'action_type' => 'assign',
           'field'       => 'urgency',
           'value'       => 5,
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $rules_id,
           'action_type' => 'assign',
           'field'       => 'impact',
           'value'       => 1,
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        $act_id = $ruleaction->add($act_input = [
           'rules_id'    => $rules_id,
           'action_type' => 'compute',
           'field'       => 'priority',
           'value'       => 1,
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        $rule_instance = new \RuleTicket();
        $this->boolean($rule_instance->getRuleWithCriteriasAndActions($rules_id, 1, 1))->isTrue();
        $input = [
           'name'     => $pattern,
           'content'  => 'test'
        ];
        $output = [
           'priority' => 1,
        ];
        $params = [];
        $rule_instance->process($input, $output, $params);

        $expected_priority = \Ticket::computePriority(5, 1);
        $this->boolean($output['_rule_process'])->isTrue();
        $this->integer((int)$output['urgency'])->isEqualTo(5);
        $this->integer((int)$output['impact'])->isEqualTo(1);
        $this->integer((int)$output['priority'])->isEqualTo($expected_priority);
    }

    public function testSlaOlaAssignmentAddsShadowFields()
    {
        $rule = new \RuleTicket();

        $sla_action = new \RuleAction();
        $sla_action->fields = [
           'action_type' => 'assign',
           'field'       => 'slas_id_ttr',
           'value'       => 123,
        ];

        $ola_action = new \RuleAction();
        $ola_action->fields = [
           'action_type' => 'assign',
           'field'       => 'olas_id_tto',
           'value'       => 456,
        ];

        $rule->actions = [$sla_action, $ola_action];
        $output = $rule->executeActions([], []);

        $this->integer((int)$output['slas_id_ttr'])->isEqualTo(123);
        $this->integer((int)$output['_slas_id_ttr'])->isEqualTo(123);
        $this->integer((int)$output['olas_id_tto'])->isEqualTo(456);
        $this->integer((int)$output['_olas_id_tto'])->isEqualTo(456);
    }
}
