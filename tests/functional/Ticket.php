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

use Change;
use CommonITILActor;
use CommonITILObject;
use CommonITILTask;
use DateTime;
use DbTestCase;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\ServerVersionProvider;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\Query;
use Document;
use Document_Item;
use Dropdown;
use Group;
use Group_Ticket;
use ITILFollowup;
use ITILSolution;
use LogicException;
use Plugin;
use Problem;
use ReflectionProperty;
use Session;
use Supplier;
use Supplier_Ticket;
use Ticket as LegacyTicket;
use TicketSatisfaction;
use Ticket_User;
use UserEmail;
use itsmng\Database\Entity\DocumentItem;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\ITILSolution as SolutionRecord;
use itsmng\Database\Entity\Profile;
use itsmng\Database\Entity\ProfileUser;
use itsmng\Database\Entity\SupplierTicket;
use itsmng\Database\Entity\Ticket as TicketEntity;
use itsmng\Database\Entity\TicketUser;
use itsmng\Database\Entity\User as UserEntity;
use itsmng\Database\ITILActorReadOperation;
use itsmng\Database\ITILDocumentAccess;
use itsmng\Database\Orm;
use itsmng\Database\OwnershipUpdateUnit;
use itsmng\Database\Repository\DocumentRepository;
use itsmng\Database\Repository\ITILActorRepository;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\TimelineRepository;
use itsmng\Database\Repository\UserRepository;
use itsmng\Database\Repository\UserSelectionRepository;
use itsmng\Database\TimelineCountReadOperation;
use itsmng\Database\TimelineSelection;
use itsmng\Database\TransactionOwnershipMismatch;
use itsmng\Database\UserDisplayReadOperation;
use mock\DBmysql as TimelineCountAdapter;
use Psr\Cache\CacheItemInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use TicketValidation;
use User;
use itsmng\Database\EntityRegistry;
use itsmng\Database\PromotionSourceReadOperation;
use itsmng\Database\Repository\ITILOriginRepository;
use tests\fixtures\ScalarReadProbe;

require_once dirname(__DIR__) . '/fixtures/ScalarReadProbe.php';

/* Test for inc/ticket.class.php */

class Ticket extends DbTestCase
{
    public function testActorDisplayReadsKeepPreferredEmailAndMissingUserBoundary(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $mailing = $CFG_GLPI['notifications_mailing'];
        $manager = Orm::create($DB);
        try {
            $user = $this->createItem(User::class, ['name' => 'Actor address ' . $this->getUniqueString()]);
            $id = (int)$user->getID();
            $first = $this->createItem(UserEmail::class, ['users_id' => $id, 'email' => 'first@example.com']);
            $second = $this->createItem(UserEmail::class, ['users_id' => $id, 'email' => 'second@example.com']);
            $group = $this->createItem(Group::class, ['name' => 'Actor group ' . $this->getUniqueString(), 'entities_id' => 0]);
            $supplier = $this->createItem(Supplier::class, ['name' => 'Actor supplier ' . $this->getUniqueString(),
                'entities_id' => 0, 'email' => 'supplier@example.com']);
            $repository = new ITILActorRepository($manager);
            $loads = new class () {
                public int $count = 0;
                public function postLoad(): void
                {
                    ++$this->count;
                }
            };
            $manager->getEventManager()->addEventListener([Events::postLoad], $loads);
            $connection = $DB->getDoctrineConnection();
            $level = $connection->getTransactionNestingLevel();
            $this->string($repository->groupName((int)$group->getID()))->isIdenticalTo($group->getName());
            $this->array($repository->supplierDisplayData((int)$supplier->getID()))
                ->isIdenticalTo(['name' => $supplier->getName(), 'email' => 'supplier@example.com']);
            $this->variable($repository->groupName(PHP_INT_MAX))->isNull();
            $this->variable($repository->supplierDisplayData(PHP_INT_MAX))->isNull();
            foreach ([[false, false, 'first@example.com'], [false, true, 'second@example.com'],
                [true, true, 'first@example.com']] as [$firstDefault, $secondDefault, $expected]) {
                $this->boolean($DB->update('glpi_useremails', ['is_default' => $firstDefault], ['id' => $first->getID()]))->isTrue();
                $this->boolean($DB->update('glpi_useremails', ['is_default' => $secondDefault], ['id' => $second->getID()]))->isTrue();
                $this->string($repository->userDefaultEmail($id))->isIdenticalTo($expected);
                $this->string($repository->userDefaultEmail($id))->isIdenticalTo($user->getDefaultEmail());
            }
            $this->boolean($DB->update('glpi_useremails', ['email' => null], ['id' => $first->getID()]))->isTrue();
            $this->string($repository->userDefaultEmail($id))->isIdenticalTo('');
            $this->string($user->getDefaultEmail())->isIdenticalTo('');
            $this->variable($repository->userDefaultEmail(PHP_INT_MAX))->isNull();
            $withoutEmail = $this->createItem(User::class, ['name' => 'No address ' . $this->getUniqueString()]);
            $this->string($repository->userDefaultEmail((int)$withoutEmail->getID()))->isIdenticalTo('');
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            $this->object($manager->find(UserEntity::class, $id))->isInstanceOf(UserEntity::class);
            $this->integer($loads->count)->isGreaterThan(0);
            $manager->clear();

            $CFG_GLPI['notifications_mailing'] = true;
            $ticket = new LegacyTicket();
            foreach ([PHP_INT_MAX, null, '', 0] as $missing) {
                ob_start();
                try {
                    $link = $ticket->generateFollowupLink(['id' => 1, 'users_id' => $missing,
                        'use_notification' => 1, 'alternative_email' => ''], User::class);
                } finally {
                    ob_end_clean();
                }
                $this->string($link['followupTitle'])->contains(__('Invalid email address'));
            }
            ob_start();
            try {
                $missingLink = $ticket->generateFollowupLink(['id' => 1, 'users_id' => PHP_INT_MAX,
                    'use_notification' => 1, 'alternative_email' => '0'], User::class);
                $emptyLink = $ticket->generateFollowupLink(['id' => 1, 'users_id' => $withoutEmail->getID(),
                    'use_notification' => 1, 'alternative_email' => '0'], User::class);
            } finally {
                ob_end_clean();
            }
            $this->string($missingLink['followupTitle'])->contains(sprintf(__('%1$s: %2$s'), _n('Email', 'Emails', 1), '0'));
            $this->string($emptyLink['followupTitle'])->notContains(sprintf(__('%1$s: %2$s'), _n('Email', 'Emails', 1), '0'));
            $this->object($DB->getDoctrineConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $CFG_GLPI['notifications_mailing'] = $mailing;
            $manager->clear();
        }
    }

    public function testActorPanelKeepsLabelsAndReadsAfterVirtualCallbacks(): void
    {
        global $DB, $CFG_GLPI;
        $this->login();
        $mailing = $CFG_GLPI['notifications_mailing'];
        $session = $_SESSION;
        try {
            $_SESSION['glpiset_default_tech'] = false;
            $user = $this->createItem(User::class, ['name' => 'Panel user ' . $this->getUniqueString()]);
            $this->createItem(UserEmail::class, ['users_id' => $user->getID(), 'email' => 'panel@example.com']);
            $group = $this->createItem(Group::class, ['name' => 'Panel group ' . $this->getUniqueString(), 'entities_id' => 0]);
            $supplier = $this->createItem(Supplier::class, ['name' => 'Panel supplier ' . $this->getUniqueString(),
                'entities_id' => 0, 'email' => 'before@example.com']);
            $ticket = $this->createItem(LegacyTicket::class, ['name' => 'Panel ticket ' . $this->getUniqueString(),
                'content' => 'Actor callback fixture', 'entities_id' => 0]);
            $this->createItem(Ticket_User::class, ['tickets_id' => $ticket->getID(), 'users_id' => $user->getID(),
                'type' => CommonITILActor::ASSIGN, 'use_notification' => 1, 'alternative_email' => 'PANEL@example.com']);
            $this->createItem(Group_Ticket::class, ['tickets_id' => $ticket->getID(), 'groups_id' => $group->getID(),
                'type' => CommonITILActor::ASSIGN]);
            $this->createItem(Supplier_Ticket::class, ['tickets_id' => $ticket->getID(), 'suppliers_id' => $supplier->getID(),
                'type' => CommonITILActor::ASSIGN, 'use_notification' => 1, 'alternative_email' => '']);
            $panel = new class () extends LegacyTicket {
                public $afterActor;
                public function panel(): array
                {
                    return $this->getActorsForAction(CommonITILActor::ASSIGN);
                }
                public function getSuppliers($type)
                {
                    $rows = parent::getSuppliers($type);
                    return array_merge($rows, $rows);
                }
                protected function getITILActorPanelEntryExtras(array $actor, string $actorType): array
                {
                    $extra = parent::getITILActorPanelEntryExtras($actor, $actorType);
                    ($this->afterActor)($actorType);
                    return $extra;
                }
            };
            $panel->fields = $ticket->fields;
            $panel->userlinkclass = Ticket_User::class;
            $panel->grouplinkclass = Group_Ticket::class;
            $panel->supplierlinkclass = Supplier_Ticket::class;
            $panel->loadActors();
            $CFG_GLPI['notifications_mailing'] = true;
            $calls = [];
            $panel->afterActor = function (string $type) use (&$calls, $DB, $group, $supplier): void {
                $calls[] = $type;
                if ($type === User::class) {
                    $this->boolean($DB->update('glpi_groups', ['name' => 'After user'], ['id' => $group->getID()]))->isTrue();
                    $this->boolean($DB->update('glpi_suppliers', ['name' => 'After user', 'email' => 'after-user@example.com'], ['id' => $supplier->getID()]))->isTrue();
                } elseif (count($calls) === 2) {
                    $this->boolean($DB->update('glpi_suppliers', ['name' => 'After supplier', 'email' => 'after-supplier@example.com'], ['id' => $supplier->getID()]))->isTrue();
                }
            };
            $connection = $DB->getDoctrineConnection();
            $level = $connection->getTransactionNestingLevel();
            ob_start();
            try {
                $rows = $panel->panel();
            } finally {
                ob_end_clean();
            }
            $this->array(array_column($rows, 'type'))->isIdenticalTo(['user', 'group', 'supplier', 'supplier']);
            $this->array(array_column($rows, 'id'))->isEqualTo([$user->getID(), $group->getID(), $supplier->getID(), $supplier->getID()]);
            $this->array(array_column($rows, 'name'))->isIdenticalTo([getUserName($user->getID()), 'After user', 'After user', 'After supplier']);
            $this->string($rows[0]['subtitle'])->isIdenticalTo(__('Email followup') . ': ' . Dropdown::getYesNo(1));
            $this->string($rows[0]['followupTitle'])->contains('PANEL@example.com');
            $this->string($rows[2]['followupTitle'])->contains('after-user@example.com');
            $this->string($rows[3]['followupTitle'])->contains('after-supplier@example.com');
            $this->array($calls)->isIdenticalTo([User::class, Supplier::class, Supplier::class]);
            $panel->afterActor = static function (): void {
            };
            foreach (['', null, '0'] as $name) {
                $this->boolean($DB->update('glpi_groups', ['name' => $name], ['id' => $group->getID()]))->isTrue();
                $this->boolean($DB->update('glpi_suppliers', ['name' => $name], ['id' => $supplier->getID()]))->isTrue();
                $this->boolean($group->getFromDB($group->getID()))->isTrue();
                $this->boolean($supplier->getFromDB($supplier->getID()))->isTrue();
                ob_start();
                try {
                    $current = $panel->panel();
                } finally {
                    ob_end_clean();
                }
                $this->string($current[1]['name'])->isIdenticalTo($group->getName());
                $this->string($current[2]['name'])->isIdenticalTo($supplier->getName());
            }
            $this->object($DB->getDoctrineConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        } finally {
            $CFG_GLPI['notifications_mailing'] = $mailing;
            $_SESSION = $session;
        }
    }

    public function testTimelineAuthorFieldsFollowDisplayCallbacks(): void
    {
        global $DB, $PLUGIN_HOOKS;
        $this->login();
        $database = $DB;
        $hooks = $PLUGIN_HOOKS;
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $active = $plugins->getValue();
        try {
            $user = $this->createItem(User::class, ['name' => 'timeline-hook-' . $this->getUniqueString(),
                'comment' => 'Complete author comment', 'phone' => '0123456789']);
            $ticket = $this->createItem(LegacyTicket::class, ['name' => 'Timeline author callback',
                'content' => 'Original content', 'entities_id' => $_SESSION['glpiactive_entity']]);
            $followups = [];
            foreach (['First', 'Second'] as $label) {
                $followup = $this->createItem(ITILFollowup::class, ['itemtype' => 'Ticket',
                    'items_id' => $ticket->getID(), 'content' => $label . ' author callback followup', 'is_private' => 0]);
                $this->boolean($DB->update('glpi_itilfollowups', ['users_id' => $user->getID()], ['id' => $followup->getID()]))->isTrue();
                $followups[] = (int)$followup->getID();
            }
            $routed = clone $DB;
            $calls = [];
            $expected = [];
            $beforeCalls = 0;
            $plugins->setValue(null, [...$active, 'timeline_author_fixture']);
            $PLUGIN_HOOKS['pre_show_item'] = ['timeline_author_fixture' =>
                static function (array $context) use ($database, $routed, $followups, $user, &$beforeCalls, &$expected): void {
                    global $DB;
                    if ($context['item'] instanceof ITILFollowup && in_array((int)$context['item']->getID(), $followups, true)) {
                        ++$beforeCalls;
                        if ($beforeCalls === 2) {
                            $DB = $routed;
                        }
                        $database->update('glpi_users', ['firstname' => 'After display callback ' . $beforeCalls], ['id' => $user->getID()]);
                        $user->getFromDB($user->getID());
                        $expected[] = $user->fields;
                    }
                }];
            $PLUGIN_HOOKS['item_can'] = ['timeline_author_fixture' => [User::class =>
                static function (User $model) use ($user, &$calls): void {
                    if ($model->getID() == $user->getID()) {
                        $calls[] = $model->fields;
                        $model->fields['name'] = 'Plugin author label';
                        $model->right = false;
                    }
                }]];
            $this->output(fn () => $ticket->showTimeline(745))->contains('Plugin author label');
            $this->integer($beforeCalls)->isIdenticalTo(2);
            $this->object($DB)->isIdenticalTo($routed);
            $this->array($calls)->isIdenticalTo($expected);

            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
            $DB = $database;
            foreach ($followups as $followup) {
                $this->boolean($DB->update('glpi_itilfollowups', ['sourceof_items_id' => $ticket->getID()], ['id' => $followup]))->isTrue();
            }
            // The final requester boundary precedes only the historical-link read.
            // The historical-link projection creates no additional manager.
            $display = new class () extends LegacyTicket {
                public int $lastRequesterFactories = 0;
                public static function getType()
                {
                    return 'Ticket';
                }
                public static function getTable($classname = null)
                {
                    return LegacyTicket::getTable();
                }
                public function getUsers($type)
                {
                    $this->lastRequesterFactories = (new ReflectionProperty(Orm::class, 'unitsOfWork'))->getValue();
                    return [];
                }
            };
            $display->fields = $ticket->fields;
            $factories = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $this->output(fn () => $display->showTimeline(746))
                ->contains('Followup promotion source')
                ->contains('viewitemitilfollowup' . min($followups));
            $this->integer($factories->getValue() - $display->lastRequesterFactories)->isIdenticalTo(0);

            $connection = $DB->getDoctrineConnection();
            $manager = Orm::forConnection($connection);
            $repository = new ITILOriginRepository($manager);
            $reader = new PromotionSourceReadOperation($connection);
            try {
                $baseline = $repository->promotionSource((int)$ticket->getID());
                $this->integer((int)$baseline['id'])->isIdenticalTo(min($followups));
                $this->integer((int)$baseline['items_id'])->isIdenticalTo((int)$ticket->getID());
                $this->array($reader->forTicket((int)$ticket->getID()))->isIdenticalTo($baseline);
                $this->variable($reader->forTicket(-1))->isNull();
                $factoryProbe = new class ($connection) extends ScalarReadProbe {
                    public function createQueryBuilder(): QueryBuilder
                    {
                        throw new LogicException('Connection builder factory must not be invoked');
                    }
                };
                $this->array(ITILOriginRepository::projectedPromotionSource(
                    $factoryProbe,
                    EntityRegistry::promotionSourceMapping(),
                    (int)$ticket->getID()
                ))->isIdenticalTo($baseline);
                $this->array($factoryProbe->queries)->hasSize(1);
                $string = Type::getType(Types::STRING);
                $integer = Type::getType(Types::INTEGER);
                try {
                    Type::overrideType(Types::STRING, new class () extends StringType {
                        public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                        {
                            throw new LogicException('An inferred discriminator is a driver string, not a mapped input type.');
                        }
                        public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
                        {
                            throw new LogicException('An inferred discriminator must bypass mapped input conversion.');
                        }
                        public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                        {
                            return 'LOWER(' . $sqlExpr . ')';
                        }
                        public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
                        {
                            return $value === null ? null : $value . '|php';
                        }
                    });
                    $converted = $reader->forTicket((int)$ticket->getID());
                    $this->array($converted)->isIdenticalTo($repository->promotionSource((int)$ticket->getID()));
                    $this->string($converted['itemtype'])->isIdenticalTo('ticket|php');
                    $this->string($converted['items_id'])->isIdenticalTo($ticket->getID() . '|php');
                    Type::overrideType(Types::INTEGER, new class () extends IntegerType {
                        public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                        {
                            return '(' . $sqlExpr . ' + 1000000)';
                        }
                    });
                    $this->variable($reader->forTicket((int)$ticket->getID()))->isNull();
                    $this->variable($repository->promotionSource((int)$ticket->getID()))->isNull();
                    Type::overrideType(Types::INTEGER, $integer);
                    $replacement = new class () extends StringType {
                        public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
                        {
                            return $value === null ? null : $value . '|late';
                        }
                    };
                    $swapping = static fn () => new class ($replacement) extends StringType {
                        public function __construct(private StringType $replacement)
                        {
                        }
                        public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                        {
                            Type::overrideType(Types::STRING, $this->replacement);
                            return $sqlExpr;
                        }
                        public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
                        {
                            throw new LogicException('Hydration must resolve the replacement Type after SQL compilation.');
                        }
                    };
                    Type::overrideType(Types::STRING, $swapping());
                    $late = $reader->forTicket((int)$ticket->getID());
                    Type::overrideType(Types::STRING, $swapping());
                    $this->array($late)->isIdenticalTo($repository->promotionSource((int)$ticket->getID()));
                    $this->string($late['itemtype'])->isIdenticalTo('Ticket|late');
                    $this->string($late['items_id'])->isIdenticalTo($ticket->getID() . '|late');
                } finally {
                    Type::overrideType(Types::STRING, $string);
                    Type::overrideType(Types::INTEGER, $integer);
                }
                $extended = new TimelineLocalCountProbe($connection);
                $local = new PromotionSourceReadOperation($extended);
                $listener = new class () {
                    public int $loads = 0;
                    public int $clears = 0;
                    public function loadClassMetadata(): void
                    {
                        ++$this->loads;
                    }
                    public function onClear(): void
                    {
                        ++$this->clears;
                    }
                };
                $events = $extended->getEventManager();
                $events->addEventListener(['loadClassMetadata', 'onClear'], $listener);
                try {
                    $this->array($local->forTicket((int)$ticket->getID()))->isIdenticalTo($baseline);
                    $this->integer($listener->loads)->isGreaterThan(0);
                    unset($local);
                    $this->integer($listener->clears)->isIdenticalTo(0);
                } finally {
                    $events->removeEventListener(['loadClassMetadata', 'onClear'], $listener);
                }
            } finally {
                $manager->clear();
            }

        } finally {
            $DB = $database;
            $PLUGIN_HOOKS = $hooks;
            $plugins->setValue(null, $active);
        }
    }

    public function timelineAccessibilityProvider(): array
    {
        return [[LegacyTicket::class], [Change::class], [Problem::class]];
    }

    /** @dataProvider timelineAccessibilityProvider */
    public function testTimelineAccessibilityUsesFreshScalarPreferences(string $type): void
    {
        global $DB;
        $this->login();
        $session = $_SESSION;
        $item = $this->createItem($type, ['name' => 'Timeline preferences ' . $this->getUniqueString(),
            'content' => 'Preference projection', 'entities_id' => $_SESSION['glpiactive_entity']]);
        $user = $this->createItem(User::class, ['name' => 'timeline-font-' . $this->getUniqueString()]);
        $id = (int)$user->getID();
        $manager = Orm::create($DB);
        $repository = new UserRepository($manager);
        $loads = new class () {
            public int $count = 0;
            public function postLoad(): void
            {
                ++$this->count;
            }
        };
        $manager->getEventManager()->addEventListener([Events::postLoad], $loads);
        $reader = new UserDisplayReadOperation($manager->getConnection());
        $defaultFont = '"Bitstream Vera Sans", arial, Tahoma, "Sans serif"';
        try {
            $_SESSION['glpiID'] = $id;
            foreach ([['OpenDyslexic', true], ['Custom font', false], ['', true], [null, null]] as [$font, $shortcuts]) {
                $this->boolean($DB->update('glpi_users', ['access_font' => $font, 'access_shortcuts' => $shortcuts], ['id' => $id]))->isTrue();
                $this->array($repository->timelinePreferences($id))
                    ->isIdenticalTo(['access_font' => $font, 'access_shortcuts' => $shortcuts]);
                $this->array($reader->timelinePreferences($id))->isIdenticalTo($repository->timelinePreferences($id));
                foreach ([0, READ] as $right) {
                    $_SESSION['glpiactiveprofile']['accessibility'] = $right;
                    $expectedFont = $right ? (string)$font : $defaultFont;
                    $this->output(fn () => $item->showTimelineHeader())
                        ->contains("<h2 style='font-family: $expectedFont;'>")
                        ->contains("<h3 style='font-family: $expectedFont;'>");
                    $this->output(fn () => $item->showTimeline(742))
                        ->contains("<div style='font-family: $expectedFont;' class='h_item middle'>");
                    ob_start();
                    try {
                        $item->showTimelineForm(741);
                        $html = ob_get_contents();
                    } finally {
                        ob_end_clean();
                    }
                    $this->boolean(str_contains($html, "class='shortcutpop'"))->isIdenticalTo((bool)$shortcuts);
                    if ($shortcuts) {
                        $this->string($html)->contains("class='shortcutpop' style='font-family: $expectedFont;'");
                    }
                }
            }
            $stringType = Type::getType(Types::STRING);
            try {
                Type::overrideType(Types::STRING, new class () extends StringType {
                    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'UPPER(' . $sqlExpr . ')';
                    }
                    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
                    {
                        return $value === null ? 'converted null' : 'converted ' . $value;
                    }
                });
                foreach (['mixed font', null] as $font) {
                    $this->boolean($DB->update('glpi_users', ['access_font' => $font], ['id' => $id]))->isTrue();
                    $expected = ['access_font' => $font === null ? 'converted null' : 'converted MIXED FONT', 'access_shortcuts' => null];
                    $this->array($repository->timelinePreferences($id))->isIdenticalTo($expected);
                    $this->array($reader->timelinePreferences($id))->isIdenticalTo($expected);
                }
                Type::overrideType(Types::STRING, new class () extends StringType {
                    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
                    {
                        throw new NoResultException();
                    }
                });
                $this->array($repository->timelinePreferences($id))->isEmpty();
                $this->array($reader->timelinePreferences($id))->isEmpty();
            } finally {
                Type::overrideType(Types::STRING, $stringType);
            }
            $bigintType = Type::getType(Types::BIGINT);
            $booleanType = Type::getType(Types::BOOLEAN);
            try {
                Type::overrideType(Types::BOOLEAN, new class () extends BooleanType {
                    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return 'NOT (' . $sqlExpr . ')';
                    }
                    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?bool
                    {
                        return !parent::convertToPHPValue($value, $platform);
                    }
                });
                $expected = $repository->timelinePreferences($id);
                $this->boolean($expected['access_shortcuts'])->isTrue();
                $this->array($reader->timelinePreferences($id))->isIdenticalTo($expected);
                Type::overrideType(Types::BIGINT, new class () extends BigIntType {
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        return '(' . $sqlExpr . ' * 0 - 1)';
                    }
                });
                $this->array($repository->timelinePreferences($id))->isEmpty();
                $this->array($reader->timelinePreferences($id))->isEmpty();
            } finally {
                Type::overrideType(Types::BIGINT, $bigintType);
                Type::overrideType(Types::BOOLEAN, $booleanType);
            }
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($manager->getUnitOfWork()->getIdentityMap())->isEmpty();
            // The virtual callback remains a fresh-read boundary, even when it
            // changes the current account preference before rendering the filter.
            $custom = new class ($id) extends LegacyTicket {
                public int $filterCalls = 0;
                public function __construct(private int $preferenceUser)
                {
                    parent::__construct();
                }
                public static function getType()
                {
                    return 'Ticket';
                }
                public static function getTable($classname = null)
                {
                    return LegacyTicket::getTable();
                }
                public function showTimelineHeader()
                {
                    global $DB;
                    parent::showTimelineHeader();
                    $DB->update('glpi_users', ['access_font' => 'After header callback'], ['id' => $this->preferenceUser]);
                }
                public function filterTimeline()
                {
                    global $DB;
                    ++$this->filterCalls;
                    $DB->update('glpi_users', ['access_font' => 'After callback'], ['id' => $this->preferenceUser]);
                    parent::filterTimeline();
                }
            };
            $this->boolean($DB->update('glpi_users', ['access_font' => 'Before callback'], ['id' => $id]))->isTrue();
            $_SESSION['glpiactiveprofile']['accessibility'] = READ;
            $this->output(fn () => $custom->showTimelineHeader())
                ->contains("<h2 style='font-family: Before callback;'>")
                ->contains("<h3 style='font-family: After callback;'>");
            $this->integer($custom->filterCalls)->isIdenticalTo(1);
            if ($type === LegacyTicket::class) {
                $custom->fields = $item->fields;
                $this->boolean($DB->update('glpi_users', ['access_font' => 'Before callback'], ['id' => $id]))->isTrue();
                $this->output(fn () => $custom->showTimeline(743))
                    ->contains("<h2 style='font-family: Before callback;'>")
                    ->contains("<h3 style='font-family: After callback;'>")
                    ->contains("<div style='font-family: After header callback;' class='h_item middle'>");
                $this->integer($custom->filterCalls)->isIdenticalTo(2);
            }
            $_SESSION = $session;
            $this->boolean($user->delete(['id' => $id], true))->isTrue();
            $this->array($repository->timelinePreferences($id))->isEmpty();
            $this->array($reader->timelinePreferences($id))->isEmpty();
            $_SESSION['glpiID'] = $id;
            $_SESSION['glpiactiveprofile']['accessibility'] = READ;
            $this->output(fn () => $item->showTimelineHeader())->contains("<h2 style='font-family: ;'>")
                ->contains("<h3 style='font-family: ;'>");
            $this->output(fn () => $item->showTimeline(744))
                ->contains("<div style='font-family: ;' class='h_item middle'>");
        } finally {
            $_SESSION = $session;
            $reader->close();
            $manager->clear();
        }
    }

    public function testHelpdeskObserverChoicesRespectUserSelectorScope(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_child_1', false);
        $child = (int)$_SESSION['glpiactive_entity'];
        $parent = (int)getItemByTypeName('Entity', '_test_root_entity', true);
        $sibling = (int)getItemByTypeName('Entity', '_test_child_2', true);
        $em = Orm::create($DB);
        try {
            $profiles = [];
            foreach (['helpdesk', 'central'] as $interface) {
                $profile = new Profile();
                $profile->name = 'Observer choices ' . $interface . $this->getUniqueString();
                $profile->interface = $interface;
                $em->persist($profile);
                $profiles[$interface] = $profile;
            }
            $users = [];
            foreach ([
                'local' => [$child, false, 'helpdesk'],
                'recursive' => [$parent, true, 'helpdesk'],
                'parent_only' => [$parent, false, 'helpdesk'],
                'sibling' => [$sibling, false, 'helpdesk'],
                'central' => [$child, false, 'central'],
                'inactive' => [$child, false, 'helpdesk'],
                'deleted' => [$child, false, 'helpdesk'],
                'future' => [$child, false, 'helpdesk'],
                'expired' => [$child, false, 'helpdesk'],
                'no_profile' => [null, false, 'helpdesk'],
            ] as $name => [$entity, $recursive, $interface]) {
                $user = new UserEntity();
                $user->name = 'observer-' . $name . '-' . $this->getUniqueString();
                $user->realname = '!! Observer ' . $name;
                $user->firstname = 'Display';
                $user->entities = $em->getReference(Entity::class, $child);
                $user->is_active = $name !== 'inactive';
                $user->is_deleted = $name === 'deleted';
                $user->begin_date = $name === 'future' ? new DateTime('+1 day') : null;
                $user->end_date = $name === 'expired' ? new DateTime('2001-01-01') : null;
                $em->persist($user);
                $users[$name] = $user;
                if ($entity !== null) {
                    $grant = new ProfileUser();
                    $grant->users = $user;
                    $grant->profiles = $profiles[$interface];
                    $grant->entities = $em->getReference(Entity::class, $entity);
                    $grant->is_recursive = $recursive;
                    $em->persist($grant);
                }
            }
            $em->flush();
            $ids = array_map(static fn ($user): int => (int)$user->id, $users);
            $em->clear();
            $options = ['entities_id' => $child, '_right' => 'all', '_user_index' => 1,
                '_users_id_observer' => [1 => $ids['local']]];
            ob_start();
            try {
                LegacyTicket::showFormHelpdeskObserver($options);
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            foreach ($ids as $name => $id) {
                $this->boolean(str_contains($html, "value='$id'"))
                    ->isIdenticalTo(in_array($name, ['local', 'recursive', 'central'], true));
            }
            $this->string($html)->contains("value='" . $ids['local'] . "' selected");
            $this->output(fn () => LegacyTicket::showFormHelpdeskObserver(array_replace($options, ['_right' => 'interface'])))
                ->contains("value='" . $ids['central'] . "'")
                ->notContains("value='" . $ids['local'] . "'");
            $this->output(fn () => LegacyTicket::showFormHelpdeskObserver(array_replace($options, ['entities_id' => $sibling])))
                ->notContains("value='" . $ids['sibling'] . "'")
                ->notContains("value='" . $ids['local'] . "'");
            $loads = new class () {
                public int $count = 0;
                public function postLoad(): void
                {
                    ++$this->count;
                }
            };
            $em->getEventManager()->addEventListener([Events::postLoad], $loads);
            $repository = new UserSelectionRepository($em);
            $rows = iterator_to_array($repository->search(['glpi_users.id' => array_values($ids)], false, [], null, false, false, 0, 2, false, true));
            $this->array($rows)->hasSize(2);
            foreach ($rows as $row) {
                $this->array(array_keys($row))->isIdenticalTo(['id', 'name', 'realname', 'firstname']);
            }
            $this->integer($loads->count)->isIdenticalTo(0);
            $this->array($em->getUnitOfWork()->getIdentityMap())->isEmpty();
        } finally {
            $em->clear();
        }
    }

    public function actorProjectionProvider(): array
    {
        return [
            ['Ticket_User', 'TicketUser', 'Ticket', 'tickets', 'actor', 'User'],
            ['Group_Ticket', 'GroupTicket', 'Ticket', 'tickets', 'groups', 'Group'],
            ['Supplier_Ticket', 'SupplierTicket', 'Ticket', 'tickets', 'actor', 'Supplier'],
            ['Change_User', 'ChangeUser', 'Change', 'changes', 'actor', 'User'],
            ['Change_Group', 'ChangeGroup', 'Change', 'changes', 'groups', 'Group'],
            ['Change_Supplier', 'ChangeSupplier', 'Change', 'changes', 'actor', 'Supplier'],
            ['Problem_User', 'ProblemUser', 'Problem', 'problems', 'actor', 'User'],
            ['Group_Problem', 'GroupProblem', 'Problem', 'problems', 'groups', 'Group'],
            ['Problem_Supplier', 'ProblemSupplier', 'Problem', 'problems', 'actor', 'Supplier'],
        ];
    }

    /** @dataProvider actorProjectionProvider */
    public function testActorRowsAvoidAssociatedEntityHydration(string $legacy, string $relationName, string $parentName, string $parentField, string $actorField, string $actorName): void
    {
        global $DB;
        $this->login();
        $em = Orm::create($DB);
        $namespace = 'itsmng\\Database\\Entity\\';
        $parentClass = $namespace . $parentName;
        $actorClass = $namespace . $actorName;
        $relationClass = $namespace . $relationName;
        $parent = new $parentClass();
        $parent->name = 'Actor projection ' . $this->getUniqueString();
        $parent->entities = $em->getReference(Entity::class, 0);
        $actor = new $actorClass();
        $actor->name = 'Projection recipient ' . $this->getUniqueString();
        $actor->entities = $parent->entities;
        $em->persist($parent);
        $em->persist($actor);
        $assign = new $relationClass();
        $assign->$parentField = $parent;
        $assign->$actorField = $actor;
        $assign->type = CommonITILActor::ASSIGN;
        $em->persist($assign);
        $observer = new $relationClass();
        $observer->$parentField = $parent;
        $observer->$actorField = $actorName === 'Group' ? $actor : null;
        $observer->type = CommonITILActor::OBSERVER;
        if ($actorName !== 'Group') {
            $observer->alternative_email = 'projection@example.invalid';
            $observer->use_notification = false;
        }
        $em->persist($observer);
        $em->flush();

        $model = new $legacy();
        $expected = array_values($model->find([$legacy::getItilObjectForeignKey() => $parent->id], 'id'));
        $reader = Orm::create($DB);
        $repository = new ITILActorRepository($reader);
        $rows = $repository->rows($legacy, $parent->id);
        $this->array($rows)->isIdenticalTo($expected);
        $this->array($reader->getUnitOfWork()->getIdentityMap())->isEmpty();
        $this->array($repository->rows($legacy, -1))->isEmpty();
        $grouped = [];
        foreach ($expected as $row) {
            $grouped[$row['type']][] = $row;
        }
        $this->array($repository->actors($legacy, $parent->id))->isIdenticalTo($grouped);
        $this->array($model->getActors($parent->id))->isIdenticalTo($grouped);
        $parentModel = new $parentName();
        $parentModel->fields['id'] = $parent->id;
        $managers = new ReflectionProperty(Orm::class, 'unitsOfWork');
        try {
            $probe = new ScalarReadProbe($em->getConnection());
            $direct = new ITILActorReadOperation($probe);
            $this->array($direct->actors($legacy, $parent->id))->isIdenticalTo($grouped);
            $this->array($probe->queries)->hasSize(1);
            $this->integer($probe->builders)->isIdenticalTo(1);
            $this->array($probe->queries[0]['params'])->isIdenticalTo(['item' => $parent->id]);
            $this->array($probe->queries[0]['types'])->isIdenticalTo(['item' => Types::BIGINT]);
            if ($legacy === Ticket_User::class) {
                $originalBigint = Type::getType('bigint');
                try {
                    Type::overrideType('bigint', new class () extends BigIntType {
                        public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                        {
                            return '(' . $sqlExpr . ' * 0 - 1)';
                        }
                    });
                    $this->array($direct->actors($legacy, $parent->id))->isEmpty();
                    $this->array($repository->actors($legacy, $parent->id))->isEmpty();
                } finally {
                    Type::overrideType('bigint', $originalBigint);
                }
            }
            $direct->close();
            $parentModel->loadActors();
            $before = $managers->getValue();
            $parentModel->loadActors();
            $this->integer($managers->getValue() - $before)->isIdenticalTo(
                0,
                'Repeated actor aggregation reuses its selected canonical manager across built-in families'
            );
            $getter = match ($actorName) {
                'Group' => 'getGroups', 'User' => 'getUsers', 'Supplier' => 'getSuppliers'
            };
            foreach ([CommonITILActor::ASSIGN, CommonITILActor::OBSERVER, CommonITILActor::REQUESTER] as $type) {
                $this->array($parentModel->$getter($type))->isIdenticalTo($grouped[$type] ?? []);
                foreach (array_diff(['getGroups', 'getUsers', 'getSuppliers'], [$getter]) as $emptyGetter) {
                    $this->array($parentModel->$emptyGetter($type))->isEmpty();
                }
            }
            $this->integer($rows[0]['id'])->isIdenticalTo($assign->id);
            $this->integer($rows[1]['id'])->isIdenticalTo($observer->id);
            if ($actorName !== 'Group') {
                $this->integer($rows[1]['use_notification'])->isIdenticalTo(0);
                $this->integer($rows[1]['actor_key'])->isIdenticalTo(0);
                $this->string($rows[1]['actor_email_key'])->isIdenticalTo('projection@example.invalid');
            }
            // A later operation observes writes; no actor rows survive between scopes.
            $observer->type = CommonITILActor::REQUESTER;
            $em->flush();
            $this->array($model->getActors($parent->id))->hasKey(CommonITILActor::REQUESTER);
            $parentModel->loadActors();
            $this->array($parentModel->$getter(CommonITILActor::REQUESTER))->hasSize(1);
            $em->remove($observer);
            $em->flush();
            $this->array($model->getActors($parent->id))->notHasKey(CommonITILActor::REQUESTER);
            $parentModel->loadActors();
            $this->array($parentModel->$getter(CommonITILActor::REQUESTER))->isEmpty();
        } finally {
            $em->clear();
            $reader->clear();
        }
    }

    public function testCustomActorFinderKeepsDispatch(): void
    {
        $relation = new class () extends Ticket_User {
            public function find($condition = [], $order = [], $limit = null)
            {
                return [['id' => 17, 'type' => CommonITILActor::OBSERVER, 'custom' => $condition['tickets_id']]];
            }
        };
        $this->array($relation->getActors(42))->isIdenticalTo([
            CommonITILActor::OBSERVER => [['id' => 17, 'type' => CommonITILActor::OBSERVER, 'custom' => 42]],
        ]);
    }

    public function testActorAggregateKeepsCustomOrderAndLaterWrites(): void
    {
        global $DB;
        $this->login();
        $em = Orm::create($DB);
        $first = new TicketEntity();
        $first->name = $this->getUniqueString();
        $first->entities = $em->getReference(Entity::class, 0);
        $second = new TicketEntity();
        $second->name = $this->getUniqueString();
        $second->entities = $first->entities;
        $supplier = new SupplierTicket();
        $supplier->tickets = $second;
        $supplier->alternative_email = 'operation@example.invalid';
        $supplier->type = CommonITILActor::OBSERVER;
        foreach ([$first, $second, $supplier] as $record) {
            $em->persist($record);
        }
        $em->flush();

        $warm = new LegacyTicket();
        $warm->fields['id'] = $first->id;
        $warm->loadActors();
        $ticket = new LegacyTicket();
        $ticket->fields['id'] = $first->id;
        $custom = new class () extends Ticket_User {
            public static $read;
            public function getActors($items_id)
            {
                return (self::$read)($items_id);
            }
        };
        $ticket->userlinkclass = $custom::class;
        $managers = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $before = $managers->getValue();
        $calls = [];
        $custom::$read = function ($id) use ($DB, $ticket, $first, $second, $supplier, $managers, $before, &$calls): array {
            $calls[] = $id;
            $this->integer((int)$id)->isIdenticalTo($first->id);
            $this->integer($managers->getValue() - $before)->isIdenticalTo(0);
            $this->array($ticket->getGroups(CommonITILActor::REQUESTER))->isEmpty();
            $this->boolean($DB->update('glpi_suppliers_tickets', ['type' => CommonITILActor::ASSIGN], ['id' => $supplier->id]))->isTrue();
            $ticket->fields['id'] = $second->id;
            return [CommonITILActor::REQUESTER => [['custom' => $id]]];
        };
        try {
            $ticket->loadActors();
            $this->integer($managers->getValue() - $before)->isIdenticalTo(0);
            $this->array($calls)->isIdenticalTo([$first->id]);
            $this->array($ticket->getUsers(CommonITILActor::REQUESTER))->isIdenticalTo([['custom' => $first->id]]);
            $rows = $ticket->getSuppliers(CommonITILActor::ASSIGN);
            $this->array($rows)->hasSize(1);
            $this->integer($rows[0]['id'])->isIdenticalTo($supplier->id);
            $this->array($ticket->getSuppliers(CommonITILActor::OBSERVER))->isEmpty();
            // The aggregate's local reader must not clear a caller's live manager.
            $this->boolean($em->contains($supplier))->isTrue();
            $this->integer($supplier->type)->isIdenticalTo(CommonITILActor::OBSERVER);
        } finally {
            $custom::$read = null;
            $em->clear();
        }
    }

    public function anonymousActorProvider(): array
    {
        return ['nullable recipient' => [null], 'legacy zero recipient' => [0]];
    }

    /** @dataProvider anonymousActorProvider */
    public function testAnonymousActorAttachment(?int $recipient): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', false);
        $session = $_SESSION;
        $manager = Orm::create($DB);
        $records = new RecordRepository($manager);
        $depth = $manager->getConnection()->getTransactionNestingLevel();
        try {
            $parent = new TicketEntity();
            $parent->entities = $manager->find(Entity::class, $_SESSION['glpiactive_entity']);
            $parent->name = 'Anonymous actor ' . bin2hex(random_bytes(8));
            $parent->status = LegacyTicket::ASSIGNED;
            $parent->recipient = $manager->find(UserEntity::class, getItemByTypeName('User', 'normal', true));
            $this->object($parent->entities)->isInstanceOf(Entity::class);
            $this->object($parent->recipient)->isInstanceOf(UserEntity::class);
            $this->integer($parent->recipient->id)->isNotEqualTo(Session::getLoginUserID());
            $assignment = new TicketUser();
            $assignment->tickets = $parent;
            $assignment->actor = $manager->find(UserEntity::class, Session::getLoginUserID());
            $assignment->type = CommonITILActor::ASSIGN;
            $manager->persist($parent);
            $manager->persist($assignment);
            $manager->flush();

            $_SESSION['glpiactiveprofile']['ticket'] = LegacyTicket::OWN | LegacyTicket::READASSIGN;
            $_SESSION['glpiactiveprofile']['user'] = 0;
            $this->boolean((bool)Session::haveRight('ticket', UPDATE))->isFalse();
            $this->boolean((new LegacyTicket())->can($parent->id, UPDATE))->isTrue();
            $this->boolean((bool)User::canView())->isFalse();
            $input = ['tickets_id' => $parent->id, 'users_id' => $recipient,
                'type' => CommonITILActor::OBSERVER, 'alternative_email' => 'anonymous-' . $parent->id . '@example.invalid',
                '_disablenotif' => true];
            $relation = new Ticket_User();
            $this->boolean($relation->can(-1, CREATE, $input))->isTrue();
            $id = (int)$relation->add($input);
            $this->integer($id)->isGreaterThan(0);
            $row = $records->find('glpi_tickets_users', 'id', $id);
            $this->variable($row['users_id'])->isNull();
            $this->string($row['alternative_email'])->isIdenticalTo($input['alternative_email']);
            $this->boolean((new Ticket_User())->can($id, READ))->isTrue();

            $before = $records->countMatching('glpi_tickets_users', ['tickets_id' => $parent->id]);
            $missing = (int)$manager->createQuery('SELECT MAX(u.id) FROM itsmng\\Database\\Entity\\User u')->getSingleScalarResult() + 100;
            foreach ([null, 0, $missing] as $invalid) {
                $proposal = $input;
                $proposal['users_id'] = $invalid;
                if ($invalid !== $missing) {
                    unset($proposal['alternative_email']);
                }
                $this->boolean((new Ticket_User())->can(-1, CREATE, $proposal))->isFalse();
            }
            $_SESSION['glpiactiveprofile']['ticket'] = LegacyTicket::READASSIGN;
            $this->boolean((new Ticket_User())->can(-1, CREATE, $input))->isFalse();
            $_SESSION['glpiactiveprofile']['ticket'] = LegacyTicket::OWN | LegacyTicket::READASSIGN;
            $this->setEntity(0, false);
            $this->boolean((new LegacyTicket())->can($parent->id, UPDATE))->isFalse();
            $this->boolean((new Ticket_User())->can(-1, CREATE, $input))->isFalse();
            $this->integer($records->countMatching('glpi_tickets_users', ['tickets_id' => $parent->id]))->isEqualTo($before);
            $this->integer($manager->getConnection()->getTransactionNestingLevel())->isEqualTo($depth);
        } finally {
            $_SESSION = $session;
            $manager->clear();
        }
    }

    public function ticketProvider()
    {
        return [
           'single requester' => [
              [
                 '_users_id_requester' => '3'
              ],
           ],
           'single unknown requester' => [
              [
                 '_users_id_requester'         => '0',
                 '_users_id_requester_notif'   => [
                    'use_notification'   => ['1'],
                    'alternative_email'  => ['unknownuser@localhost.local']
                 ],
              ],
           ],
           'multiple requesters' => [
              [
                 '_users_id_requester' => ['3', '5'],
              ],
           ],
           'multiple mixed requesters' => [
              [
                 '_users_id_requester'         => ['3', '5', '0'],
                 '_users_id_requester_notif'   => [
                    'use_notification'   => ['1', '0', '1'],
                    'alternative_email'  => ['','', 'unknownuser@localhost.local']
                 ],
              ],
           ],
           'single observer' => [
              [
                 '_users_id_observer' => '3'
              ],
           ],
           'multiple observers' => [
              [
                 '_users_id_observer' => ['3', '5'],
              ],
           ],
           'single assign' => [
              [
                 '_users_id_assign' => '3'
              ],
           ],
           'multiple assigns' => [
              [
                 '_users_id_assign' => ['3', '5'],
              ],
           ],
        ];
    }

    /**
     * @dataProvider ticketProvider
    */
    public function testCreateTicketWithActors($ticketActors)
    {
        $this->login();

        $ticket = new \Ticket();
        $this->integer((int)$ticket->add([
              'name'    => 'ticket title',
              'content' => 'a description',
        ] + $ticketActors))->isGreaterThan(0);

        $this->boolean($ticket->isNewItem())->isFalse();
        $ticketId = $ticket->getID();

        foreach ($ticketActors as $actorType => $actorsList) {
            // Convert single actor (scalar value) to array
            if (!is_array($actorsList)) {
                $actorsList = [$actorsList];
            }

            // Check all actors are assigned to the ticket
            foreach ($actorsList as $index => $actor) {
                $notify = isset($actorList['_users_id_requester_notif']['use_notification'][$index])
                          ? $actorList['_users_id_requester_notif']['use_notification'][$index]
                          : 1;
                $alternateEmail = isset($actorList['_users_id_requester_notif']['use_notification'][$index])
                                  ? $actorList['_users_id_requester_notif']['alternative_email'][$index]
                                  : '';
                switch ($actorType) {
                    case '_users_id_requester':
                        //$this->_testTicketUser($ticket, $actor, \CommonITILActor::REQUESTER, $notify, $alternateEmail);
                        break;
                    case '_users_id_observer':
                        $this->_testTicketUser($ticket, $actor, \CommonITILActor::OBSERVER, $notify, $alternateEmail);
                        break;
                    case '_users_id_assign':
                        $this->_testTicketUser($ticket, $actor, \CommonITILActor::ASSIGN, $notify, $alternateEmail);
                        break;
                }
            }
        }
    }

    protected function _testTicketUser(\Ticket $ticket, $actor, $role, $notify, $alternateEmail)
    {
        if ($actor > 0) {
            $user = new \User();
            $this->boolean($user->getFromDB($actor))->isTrue();
            $this->boolean($user->isNewItem())->isFalse();

            $ticketUser = new \Ticket_User();
            $this->boolean(
                $ticketUser->getFromDBByCrit([
                  'tickets_id' => $ticket->getID(),
                  'users_id'   => $user->getID(),
                  'type'       => $role
            ])
            )->isTrue();
        } else {
            $ticketId = $ticket->getID();
            $ticketUser = new \Ticket_User();
            $this->boolean(
                $ticketUser->getFromDBByCrit([
                  'tickets_id'         => $ticketId,
                  'users_id'           => 0,
                  'type'               => $role,
                  'alternative_email'  => $alternateEmail
            ])
            )->isTrue();
        }
        $this->boolean($ticketUser->isNewItem())->isFalse();
        $this->variable($ticketUser->getField('type'))->isEqualTo($role);
        $this->variable($ticketUser->getField('use_notification'))->isEqualTo($notify);
    }

    public function testCreateTicketDeduplicatesRequesters()
    {
        $this->login();

        $users_id_requester = (int)getItemByTypeName('User', 'post-only', true);
        $ticket = new \Ticket();
        $tickets_id = (int)$ticket->add([
           'name'                => 'ticket duplicate requester guard',
           'content'             => 'duplicate requester guard',
           '_users_id_requester' => [$users_id_requester, $users_id_requester],
        ]);

        $this->integer($tickets_id)->isGreaterThan(0);
        $this->integer(countElementsInTable(
            'glpi_tickets_users',
            [
                'tickets_id' => $tickets_id,
                'users_id'   => $users_id_requester,
                'type'       => \CommonITILActor::REQUESTER,
            ]
        ))->isEqualTo(1);
    }

    public function testUpdateTicketDoesNotDuplicateExistingRequester()
    {
        $this->login();

        $users_id_requester = (int)getItemByTypeName('User', 'post-only', true);
        $ticket = new \Ticket();
        $tickets_id = (int)$ticket->add([
           'name'                => 'ticket duplicate requester update guard',
           'content'             => 'duplicate requester update guard',
           '_users_id_requester' => $users_id_requester,
        ]);

        $this->integer($tickets_id)->isGreaterThan(0);
        $this->boolean($ticket->update([
           'id'              => $tickets_id,
           '_itil_requester' => [
              '_type'             => 'user',
              'users_id'          => $users_id_requester,
              'use_notification'  => ['1'],
              'alternative_email' => [''],
           ],
        ]))->isTrue();

        $this->integer(countElementsInTable(
            'glpi_tickets_users',
            [
                'tickets_id' => $tickets_id,
                'users_id'   => $users_id_requester,
                'type'       => \CommonITILActor::REQUESTER,
            ]
        ))->isEqualTo(1);
    }

    public function testTasksFromTemplate()
    {
        $this->login();

        // 1- create a task category
        $taskcat    = new \TaskCategory();
        $taskcat_id = $taskcat->add([
           'name' => 'my task cat',
        ]);
        $this->boolean($taskcat->isNewItem())->isFalse();

        // 2- create some task templates
        $tasktemplate = new \TaskTemplate();
        $ttA_id          = $tasktemplate->add([
           'name'              => 'my task template A',
           'content'           => 'my task template A',
           'taskcategories_id' => $taskcat_id,
           'actiontime'        => 60,
           'is_private'        => true,
           'users_id_tech'     => 2,
           'groups_id_tech'    => 0,
           'state'             => \Planning::INFO,
        ]);
        $this->boolean($tasktemplate->isNewItem())->isFalse();
        $ttB_id          = $tasktemplate->add([
           'name'              => 'my task template B',
           'content'           => 'my task template B',
           'taskcategories_id' => $taskcat_id,
           'actiontime'        => 120,
           'is_private'        => false,
           'users_id_tech'     => 2,
           'groups_id_tech'    => 0,
           'state'             => \Planning::TODO,
        ]);
        $this->boolean($tasktemplate->isNewItem())->isFalse();

        // 3 - create a ticket template with the task templates in predefined fields
        $itiltemplate    = new \TicketTemplate();
        $itiltemplate_id = $itiltemplate->add([
           'name' => 'my ticket template',
        ]);
        $this->boolean($itiltemplate->isNewItem())->isFalse();
        $ttp = new \TicketTemplatePredefinedField();
        $ttp->add([
           'tickettemplates_id' => $itiltemplate_id,
           'num'                => '175',
           'value'              => $ttA_id,
        ]);
        $this->boolean($ttp->isNewItem())->isFalse();
        $ttp->add([
           'tickettemplates_id' => $itiltemplate_id,
           'num'                => '176',
           'value'              => $ttB_id,
        ]);
        $this->boolean($ttp->isNewItem())->isFalse();

        // 4 - create a ticket category using the ticket template
        $itilcat    = new \ITILCategory();
        $itilcat_id = $itilcat->add([
           'name'                        => 'my itil category',
           'ticketltemplates_id_incident' => $itiltemplate_id,
           'tickettemplates_id_demand'   => $itiltemplate_id,
           'is_incident'                 => true,
           'is_request'                  => true,
        ]);
        $this->boolean($itilcat->isNewItem())->isFalse();

        // 5 - create a ticket using the ticket category
        $ticket     = new \Ticket();
        $tickets_id = $ticket->add([
           'name'                => 'test task template',
           'content'             => 'test task template',
           'itilcategories_id'   => $itilcat_id,
           '_tickettemplates_id' => $itiltemplate_id,
           '_tasktemplates_id'   => [$ttA_id, $ttB_id],
        ]);
        $this->boolean($ticket->isNewItem())->isFalse();

        // 6 - check creation of the tasks
        $tickettask = new \TicketTask();
        $found_tasks = $tickettask->find(['tickets_id' => $tickets_id], "id ASC");

        // 6.1 -> check first task
        $taskA = array_shift($found_tasks);
        $this->string($taskA['content'])->isIdenticalTo('my task template A');
        $this->variable($taskA['taskcategories_id'])->isEqualTo($taskcat_id);
        $this->variable($taskA['actiontime'])->isEqualTo(60);
        $this->variable($taskA['is_private'])->isEqualTo(1);
        $this->variable($taskA['users_id_tech'])->isEqualTo(2);
        $this->variable($taskA['groups_id_tech'])->isEqualTo(0);
        $this->variable($taskA['state'])->isEqualTo(\Planning::INFO);

        // 6.2 -> check second task
        $taskB = array_shift($found_tasks);
        $this->string($taskB['content'])->isIdenticalTo('my task template B');
        $this->variable($taskB['taskcategories_id'])->isEqualTo($taskcat_id);
        $this->variable($taskB['actiontime'])->isEqualTo(120);
        $this->variable($taskB['is_private'])->isEqualTo(0);
        $this->variable($taskB['users_id_tech'])->isEqualTo(2);
        $this->variable($taskB['groups_id_tech'])->isEqualTo(0);
        $this->variable($taskB['state'])->isEqualTo(\Planning::TODO);
    }

    public function testAcls()
    {
        $ticket = new \Ticket();
        //to fix an undefined index
        $_SESSION["glpiactiveprofile"]["interface"] = '';
        $this->boolean((bool)$ticket->canAdminActors())->isFalse();
        $this->boolean((bool)$ticket->canAssign())->isFalse();
        $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
        $this->boolean((bool)$ticket->canUpdate())->isFalse();
        $this->boolean((bool)$ticket->canView())->isFalse();
        $this->boolean((bool)$ticket->canViewItem())->isFalse();
        $this->boolean((bool)$ticket->canSolve())->isFalse();
        $this->boolean((bool)$ticket->canApprove())->isFalse();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isFalse();
        $this->boolean((bool)$ticket->canUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canDelete())->isFalse();
        $this->boolean((bool)$ticket->canDeleteItem())->isFalse();
        $this->boolean((bool)$ticket->canAddItem('Document'))->isFalse();
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isFalse();
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isFalse();

        $this->login();
        $this->setEntity('Root entity', true);
        $ticket = new \Ticket();
        $this->boolean((bool)$ticket->canAdminActors())->isTrue(); //=> get 2
        $this->boolean((bool)$ticket->canAssign())->isTrue(); //=> get 8192
        $this->boolean((bool)$ticket->canAssignToMe())->isTrue();
        $this->boolean((bool)$ticket->canUpdate())->isTrue();
        $this->boolean((bool)$ticket->canView())->isTrue();
        $this->boolean((bool)$ticket->canViewItem())->isTrue();
        $this->boolean((bool)$ticket->canSolve())->isTrue();
        $this->boolean((bool)$ticket->canApprove())->isFalse();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isTrue();
        $this->boolean((bool)$ticket->canUpdateItem())->isTrue();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canDelete())->isTrue();
        $this->boolean((bool)$ticket->canDeleteItem())->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Document'))->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isTrue();
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isTrue();

        $ticket = getItemByTypeName('Ticket', '_ticket01');
        $this->boolean((bool)$ticket->canAdminActors())->isTrue(); //=> get 2
        $this->boolean((bool)$ticket->canAssign())->isTrue(); //=> get 8192
        $this->boolean((bool)$ticket->canAssignToMe())->isTrue();
        $this->boolean((bool)$ticket->canUpdate())->isTrue();
        $this->boolean((bool)$ticket->canView())->isTrue();
        $this->boolean((bool)$ticket->canViewItem())->isTrue();
        $this->boolean((bool)$ticket->canSolve())->isTrue();
        $this->boolean((bool)$ticket->canApprove())->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isTrue();
        $this->boolean((bool)$ticket->canUpdateItem())->isTrue();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canDelete())->isTrue();
        $this->boolean((bool)$ticket->canDeleteItem())->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Document'))->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isTrue();
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isTrue();
    }

    public function testPostOnlyAcls()
    {
        $auth = new \Auth();
        $this->boolean((bool)$auth->login('post-only', 'postonly', true))->isTrue();

        $ticket = new \Ticket();
        $this->boolean((bool)$ticket->canAdminActors())->isFalse();
        $this->boolean((bool)$ticket->canAssign())->isFalse();
        $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
        $this->boolean((bool)$ticket->canUpdate())->isTrue();
        $this->boolean((bool)$ticket->canView())->isTrue();
        $this->boolean((bool)$ticket->canViewItem())->isFalse();
        $this->boolean((bool)$ticket->canSolve())->isFalse();
        $this->boolean((bool)$ticket->canApprove())->isFalse();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isTrue();
        $this->boolean((bool)$ticket->canUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canDelete())->isTrue();
        $this->boolean((bool)$ticket->canDeleteItem());
        $this->boolean((bool)$ticket->canAddItem('Document'));
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isFalse();
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isFalse();

        $this->integer(
            (int)$ticket->add([
              'name'    => '',
              'content' => 'A ticket to check ACLS',
         ])
        )->isGreaterThan(0);

        //reload ticket from DB
        $this->boolean((bool)$ticket->getFromDB($ticket->getID()))->isTrue();
        $this->boolean((bool)$ticket->canAdminActors())->isFalse();
        $this->boolean((bool)$ticket->canAssign())->isFalse();
        $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
        $this->boolean((bool)$ticket->canUpdate())->isTrue();
        $this->boolean((bool)$ticket->canView())->isTrue();
        $this->boolean((bool)$ticket->canViewItem())->isTrue();
        $this->boolean((bool)$ticket->canSolve())->isFalse();
        $this->boolean((bool)$ticket->canApprove())->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isTrue();
        $this->boolean((bool)$ticket->canUpdateItem())->isTrue();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isTrue();
        $this->boolean((bool)$ticket->canDelete())->isTrue();
        $this->boolean((bool)$ticket->canDeleteItem())->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Document'))->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isTrue();
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isTrue();

        $uid = getItemByTypeName('User', TU_USER, true);
        //add a followup to the ticket
        $fup = new \ITILFollowup();
        $this->integer(
            (int)$fup->add([
              'itemtype'  => 'Ticket',
              'items_id'   => $ticket->getID(),
              'users_id'     => $uid,
              'content'      => 'A simple followup'
         ])
        )->isGreaterThan(0);

        $this->boolean((bool)$ticket->getFromDB($ticket->getID()))->isTrue();
        $this->boolean((bool)$ticket->canAdminActors())->isFalse();
        $this->boolean((bool)$ticket->canAssign())->isFalse();
        $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
        $this->boolean((bool)$ticket->canUpdate())->isTrue();
        $this->boolean((bool)$ticket->canView())->isTrue();
        $this->boolean((bool)$ticket->canViewItem())->isTrue();
        $this->boolean((bool)$ticket->canSolve())->isFalse();
        $this->boolean((bool)$ticket->canApprove())->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isTrue();
        $this->boolean((bool)$ticket->canUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canDelete())->isTrue();
        $this->boolean((bool)$ticket->canDeleteItem())->isFalse();
        $this->boolean((bool)$ticket->canAddItem('Document'))->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isFalse();
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isTrue();
    }

    public function testTechAcls()
    {
        $auth = new \Auth();
        $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();

        $ticket = new \Ticket();
        $this->boolean((bool)$ticket->canAdminActors())->isTrue();
        $this->boolean((bool)$ticket->canAssign())->isFalse();
        $this->boolean((bool)$ticket->canAssignToMe())->isTrue();
        $this->boolean((bool)$ticket->canUpdate())->isTrue();
        $this->boolean((bool)$ticket->canView())->isTrue();
        $this->boolean((bool)$ticket->canViewItem())->isTrue();
        $this->boolean((bool)$ticket->canSolve())->isTrue();
        $this->boolean((bool)$ticket->canApprove())->isFalse();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isTrue();
        $this->boolean((bool)$ticket->canUpdateItem())->isTrue();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canDelete())->isFalse();
        $this->boolean((bool)$ticket->canDeleteItem())->isFalse();
        $this->boolean((bool)$ticket->canAddItem('Document'))->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isTrue();
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isTrue();

        $this->integer(
            (int)$ticket->add([
              'name'    => '',
              'content' => 'A ticket to check ACLS',
         ])
        )->isGreaterThan(0);

        //reload ticket from DB
        $this->boolean((bool)$ticket->getFromDB($ticket->getID()))->isTrue();
        $this->boolean((bool)$ticket->canAdminActors())->isTrue();
        $this->boolean((bool)$ticket->canAssign())->isFalse();
        $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
        $this->boolean((bool)$ticket->canUpdate())->isTrue();
        $this->boolean((bool)$ticket->canView())->isTrue();
        $this->boolean((bool)$ticket->canViewItem())->isTrue();
        $this->boolean((bool)$ticket->canSolve())->isTrue();
        $this->boolean((bool)$ticket->canApprove())->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isTrue();
        $this->boolean((bool)$ticket->canUpdateItem())->isTrue();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isTrue();
        $this->boolean((bool)$ticket->canDelete())->isFalse();
        $this->boolean((bool)$ticket->canDeleteItem())->isFalse();
        $this->boolean((bool)$ticket->canAddItem('Document'))->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isTrue();
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isTrue();

        $uid = getItemByTypeName('User', TU_USER, true);
        //add a followup to the ticket
        $fup = new \ITILFollowup();
        $this->integer(
            (int)$fup->add([
              'itemtype'  => 'Ticket',
              'items_id'   => $ticket->getID(),
              'users_id'     => $uid,
              'content'      => 'A simple followup'
         ])
        )->isGreaterThan(0);

        $this->boolean((bool)$ticket->getFromDB($ticket->getID()))->isTrue();
        $this->boolean((bool)$ticket->canAdminActors())->isTrue();
        $this->boolean((bool)$ticket->canAssign())->isFalse();
        $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
        $this->boolean((bool)$ticket->canUpdate())->isTrue();
        $this->boolean((bool)$ticket->canView())->isTrue();
        $this->boolean((bool)$ticket->canViewItem())->isTrue();
        $this->boolean((bool)$ticket->canSolve())->isTrue();
        $this->boolean((bool)$ticket->canApprove())->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isTrue();
        $this->boolean((bool)$ticket->canUpdateItem())->isTrue();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canDelete())->isFalse();
        $this->boolean((bool)$ticket->canDeleteItem())->isFalse();
        $this->boolean((bool)$ticket->canAddItem('Document'))->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isTrue();
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isTrue();

        //drop update ticket right from tech profile
        global $DB;
        $DB->update(
            'glpi_profilerights',
            ['rights' => 168965],
            [
              'profiles_id'  => 6,
              'name'         => 'ticket'
         ]
        );
        //ACLs have changed: login again.
        $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();

        //reset rights. Done here so ACLs are reset even if tests fails.
        $DB->update(
            'glpi_profilerights',
            ['rights' => 168967],
            [
              'profiles_id'  => 6,
              'name'         => 'ticket'
         ]
        );

        $this->boolean((bool)$ticket->getFromDB($ticket->getID()))->isTrue();
        $this->boolean((bool)$ticket->canAdminActors())->isFalse();
        $this->boolean((bool)$ticket->canAssign())->isFalse();
        $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
        $this->boolean((bool)$ticket->canUpdate())->isTrue();
        $this->boolean((bool)$ticket->canView())->isTrue();
        $this->boolean((bool)$ticket->canViewItem())->isTrue();
        $this->boolean((bool)$ticket->canSolve())->isTrue();
        $this->boolean((bool)$ticket->canApprove())->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isTrue();
        $this->boolean((bool)$ticket->canUpdateItem())->isTrue();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canDelete())->isFalse();
        $this->boolean((bool)$ticket->canDeleteItem())->isFalse();
        $this->boolean((bool)$ticket->canAddItem('Document'))->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isFalse();
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isTrue();

        $this->integer(
            (int)$ticket->add([
              'name'    => '',
              'content' => 'Another ticket to check ACLS',
         ])
        )->isGreaterThan(0);
        $this->boolean((bool)$ticket->getFromDB($ticket->getID()))->isTrue();
        $this->boolean((bool)$ticket->canAdminActors())->isFalse();
        $this->boolean((bool)$ticket->canAssign())->isFalse();
        $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
        $this->boolean((bool)$ticket->canUpdate())->isTrue();
        $this->boolean((bool)$ticket->canView())->isTrue();
        $this->boolean((bool)$ticket->canViewItem())->isTrue();
        $this->boolean((bool)$ticket->canSolve())->isTrue();
        $this->boolean((bool)$ticket->canApprove())->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isTrue();
        $this->boolean((bool)$ticket->canUpdateItem())->isTrue();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isTrue();
        $this->boolean((bool)$ticket->canDelete())->isFalse();
        $this->boolean((bool)$ticket->canDeleteItem())->isFalse();
        $this->boolean((bool)$ticket->canAddItem('Document'))->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isTrue();
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isTrue();
    }

    public function testNotOwnerAcls()
    {
        $this->login();

        $ticket = new \Ticket();
        $this->integer(
            (int)$ticket->add([
              'name'    => '',
              'content' => 'A ticket to check ACLS',
         ])
        )->isGreaterThan(0);

        $auth = new \Auth();
        $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();

        //reload ticket from DB
        $this->boolean((bool)$ticket->getFromDB($ticket->getID()))->isTrue();
        $this->boolean((bool)$ticket->canAdminActors())->isTrue();
        $this->boolean((bool)$ticket->canAssign())->isFalse();
        $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
        $this->boolean((bool)$ticket->canUpdate())->isTrue();
        $this->boolean((bool)$ticket->canView())->isTrue();
        $this->boolean((bool)$ticket->canViewItem())->isTrue();
        $this->boolean((bool)$ticket->canSolve())->isTrue();
        $this->boolean((bool)$ticket->canApprove())->isFalse();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isTrue();
        $this->boolean((bool)$ticket->canUpdateItem())->isTrue();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canDelete())->isFalse();
        $this->boolean((bool)$ticket->canDeleteItem())->isFalse();
        $this->boolean((bool)$ticket->canAddItem('Document'))->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isTrue();
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isTrue();

        //drop update ticket right from tech profile
        global $DB;
        $DB->update(
            'glpi_profilerights',
            ['rights' => 168965],
            [
              'profiles_id'  => 6,
              'name'         => 'ticket'
         ]
        );
        //ACLs have changed: login again.
        $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();

        //reset rights. Done here so ACLs are reset even if tests fails.
        $DB->update(
            'glpi_profilerights',
            ['rights' => 168967],
            [
              'profiles_id'  => 6,
              'name'         => 'ticket'
         ]
        );

        $this->boolean((bool)$ticket->getFromDB($ticket->getID()))->isTrue();
        $this->boolean((bool)$ticket->canAdminActors())->isFalse();
        $this->boolean((bool)$ticket->canAssign())->isFalse();
        $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
        $this->boolean((bool)$ticket->canUpdate())->isTrue();
        $this->boolean((bool)$ticket->canView())->isTrue();
        $this->boolean((bool)$ticket->canViewItem())->isTrue();
        $this->boolean((bool)$ticket->canSolve())->isFalse();
        $this->boolean((bool)$ticket->canApprove())->isFalse();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isTrue();
        $this->boolean((bool)$ticket->canUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canDelete())->isFalse();
        $this->boolean((bool)$ticket->canDeleteItem())->isFalse();
        $this->boolean((bool)$ticket->canAddItem('Document'))->isTrue();
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isFalse();
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isTrue();

        // post only tests
        $this->boolean((bool)$auth->login('post-only', 'postonly', true))->isTrue();
        $this->boolean((bool)$ticket->getFromDB($ticket->getID()))->isTrue();
        $this->boolean((bool)$ticket->canAdminActors())->isFalse();
        $this->boolean((bool)$ticket->canAssign())->isFalse();
        $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
        $this->boolean((bool)$ticket->canUpdate())->isTrue();
        $this->boolean((bool)$ticket->canView())->isTrue();
        $this->boolean((bool)$ticket->canViewItem())->isFalse();
        $this->boolean((bool)$ticket->canSolve())->isFalse();
        $this->boolean((bool)$ticket->canApprove())->isFalse();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'content', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'name', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'priority', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'type', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canMassiveAction('update', 'location', 'qwerty'))->isTrue();
        $this->boolean((bool)$ticket->canCreateItem())->isTrue();
        $this->boolean((bool)$ticket->canUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canRequesterUpdateItem())->isFalse();
        $this->boolean((bool)$ticket->canDelete())->isTrue();
        $this->boolean((bool)$ticket->canDeleteItem())->isFalse();
        $this->boolean((bool)$ticket->canAddItem('Document'))->isFalse();
        $this->boolean((bool)$ticket->canAddItem('Ticket_Cost'))->isFalse();
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();
        $this->boolean((bool)$ticket->canUserAddFollowups(\Session::getLoginUserID()))->isFalse();
    }

    /**
     * Checks showForm() output
     *
     * @param \Ticket $ticket   Ticket instance
     * @param boolean $name     Name is editable
     * @param boolean $textarea Content is editable
     * @param boolean $priority Priority can be changed
     * @param boolean $save     Save button is present
     * @param boolean $assign   Can assign
     *
     * @return void
     */
    private function checkFormOutput(
        \Ticket $ticket,
        $name = true,
        $textarea = true,
        $priority = true,
        $save = true,
        $assign = true,
        $openDate = true,
        $timeOwnResolve = true,
        $type = true,
        $status = true,
        $urgency = true,
        $impact = true,
        $category = true,
        $requestSource = true,
        $location = true
    ) {
        $_SESSION['_glpi_csrf_token'] = \Session::getNewCSRFToken();

        ob_start();
        $ticket->showForm($ticket->getID());
        $output = ob_get_contents();
        ob_end_clean();

        //Form title
        preg_match(
            '/<input[^>]*name=[\'"]id[\'"][^>]*value=[\'"]' . $ticket->getID() . '[\'"][^>]*>/i',
            $output,
            $matches
        );
        $this->array($matches)->hasSize(1);

        // Opening date, editable
        preg_match(
            '/.*<input[^>]*name=[\'"]date[\'"][^>]*>.*/',
            $output,
            $matches
        );
        if ($openDate === true) {
            $this->array($matches)->hasSize(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        // Time to own, editable
        preg_match(
            '/.*<input[^>]*name=[\'"]time_to_own[\'"][^>]*>.*/',
            $output,
            $matches
        );
        if ($timeOwnResolve === true) {
            $this->array($matches)->hasSize(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        // Internal time to own, editable
        preg_match(
            '/.*<input[^>]*name=[\'"]internal_time_to_own[\'"][^>]*>.*/',
            $output,
            $matches
        );
        if ($timeOwnResolve === true) {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        // Time to resolve, editable
        preg_match(
            '/.*<input[^>]*name=[\'"]time_to_resolve[\'"][^>]*>.*/',
            $output,
            $matches
        );
        if ($timeOwnResolve === true) {
            $this->array($matches)->hasSize(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        // Internal time to resolve, editable
        preg_match(
            '/.*<input[^>]*name=[\'"]internal_time_to_resolve[\'"][^>]*>.*/',
            $output,
            $matches
        );
        if ($timeOwnResolve === true) {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        //Type
        preg_match(
            '/.*(?:<select[^>]*name=[\'"]type[\'"][^>]*>|<input[^>]*name=[\'"]type[\'"][^>]*>).*/',
            $output,
            $matches
        );
        if ($type === true) {
            $this->array($matches)->hasSize(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        //Status
        preg_match(
            '/.*(?:<select[^>]*name=[\'"]status[\'"][^>]*>|<input[^>]*name=[\'"]status[\'"][^>]*>).*/',
            $output,
            $matches
        );
        if ($status === true) {
            $this->array($matches)->hasSize(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        //Urgency
        preg_match(
            '/.*(?:<select[^>]*name=[\'"]urgency[\'"][^>]*>|<input[^>]*name=[\'"]urgency[\'"][^>]*>).*/',
            $output,
            $matches
        );
        if ($urgency === true) {
            $this->array($matches)->hasSize(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        //Impact
        preg_match(
            '/.*(?:<select[^>]*name=[\'"]impact[\'"][^>]*>|<input[^>]*name=[\'"]impact[\'"][^>]*>).*/',
            $output,
            $matches
        );
        if ($impact === true) {
            $this->array($matches)->hasSize(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        //Category
        preg_match(
            '/.*<select[^>]*name="itilcategories_id"[^>]*>.*/',
            $output,
            $matches
        );
        if ($category === true) {
            $this->array($matches)->hasSize(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        //Request source file_put_contents('/tmp/out.html', $output)
        if ($requestSource === true) {
            preg_match(
                '/.*<select[^>]*name="requesttypes_id"[^>]*>.*/',
                $output,
                $matches
            );
            $this->array($matches)->hasSize(1);
        } else {
            preg_match(
                '/.*<input[^>]*name="requesttypes_id"[^>]*>.*/',
                $output,
                $matches
            );
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        //Location
        preg_match(
            '/.*<select[^>]*name="locations_id"[^>]*>.*/',
            $output,
            $matches
        );
        if ($location === true) {
            $this->array($matches)->hasSize(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        //Ticket name, editable
        preg_match(
            '/.*<input[^>]*name=[\'"]name[\'"][^>]*>.*/',
            $output,
            $matches
        );
        if ($name === true) {
            $this->array($matches)->hasSize(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        //Ticket content, editable
        preg_match(
            '/.*<textarea[^>]*name=[\'"]content[\'"][^>]*>.*/',
            $output,
            $matches
        );
        if ($textarea === true) {
            $this->array($matches)->hasSize(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        //Priority, editable
        preg_match(
            '/.*(?:<select[^>]*name=[\'"]priority[\'"][^>]*>|<input[^>]*name=[\'"]priority[\'"][^>]*>).*/',
            $output,
            $matches
        );
        if ($priority === true) {
            $this->array($matches)->hasSize(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        //Save button
        preg_match(
            '/.*(?:<input[^>]*type=[\'"]submit[\'"][^>]*name=[\'"]update[\'"][^>]*>|<button[^>]*name=[\'"]update[\'"][^>]*>).*/',
            $output,
            $matches
        );
        if ($save === true) {
            $this->array($matches)->hasSize(1);
        } else {
            $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
        }

        //Assign to
        preg_match(
            '/.*<select name=\'_itil_assign\[_type\]\'[^>]*>.*/',
            $output,
            $matches
        );
        $this->integer(count($matches))->isGreaterThanOrEqualTo(0)->isLessThanOrEqualTo(1);
    }

    public function testForm()
    {
        $this->login();
        $this->setEntity('Root entity', true);
        $ticket = getItemByTypeName('Ticket', '_ticket01');

        $this->checkFormOutput($ticket);
    }

    public function testFormPostOnly()
    {
        $this->login('post-only', 'postonly');

        //create a new ticket
        $ticket = new \Ticket();
        $this->integer(
            (int)$ticket->add([
              'name'    => '',
              'content' => 'A ticket to check displayed postonly form',
         ])
        )->isGreaterThan(0);
        $this->boolean($ticket->getFromDB($ticket->getId()))->isTrue();

        $this->checkFormOutput(
            $ticket,
            $name = false,
            $textarea = true,
            $priority = false,
            $save = true,
            $assign = false,
            $openDate = true,
            $timeOwnResolve = true,
            $type = true,
            $status = true,
            $urgency = true,
            $impact = true,
            $category = true,
            $requestSource = false,
            $location = false
        );

        $uid = getItemByTypeName('User', TU_USER, true);
        //add a followup to the ticket
        $fup = new \ITILFollowup();
        $this->integer(
            (int)$fup->add([
              'itemtype'  => 'Ticket',
              'items_id'   => $ticket->getID(),
              'users_id'     => $uid,
              'content'      => 'A simple followup'
         ])
        )->isGreaterThan(0);

        $this->checkFormOutput(
            $ticket,
            $name = false,
            $textarea = true,
            $priority = false,
            $save = false,
            $assign = false,
            $openDate = true,
            $timeOwnResolve = true,
            $type = true,
            $status = true,
            $urgency = true,
            $impact = true,
            $category = false,
            $requestSource = false,
            $location = false
        );
    }

    public function testFormTech()
    {
        $output_level = ob_get_level();
        ob_start();
        try {
            //create a new ticket with tu user
            $auth = new \Auth();
            $this->login();
            $ticket = new \Ticket();
            $this->integer(
                (int)$ticket->add([
                  'name'                => '',
                  'content'             => 'A ticket to check displayed tech form',
                  '_users_id_requester' => '3', // post-only
                  '_users_id_assign'    => '4', // tech
         ])
            )->isGreaterThan(0);
            $this->boolean($ticket->getFromDB($ticket->getId()))->isTrue();

            //check output with default ACLs
            $this->changeTechRight();
            $this->checkFormOutput(
                $ticket,
                $name = false,
                $textarea = true,
                $priority = false,
                $save = true,
                $assign = false,
                $openDate = true,
                $timeOwnResolve = true,
                $type = true,
                $status = true,
                $urgency = true,
                $impact = true,
                $category = true,
                $requestSource = true,
                $location = true
            );

            //drop UPDATE ticket right from tech profile (still with OWN)
            $this->changeTechRight(168965);
            $this->checkFormOutput(
                $ticket,
                $name = false,
                $textarea = true,
                $priority = false,
                $save = true,
                $assign = false,
                $openDate = true,
                $timeOwnResolve = true,
                $type = true,
                $status = true,
                $urgency = true,
                $impact = true,
                $category = true,
                $requestSource = true,
                $location = true
            );

            //drop UPDATE ticket right from tech profile (without OWN)
            $this->changeTechRight(136197);
            $this->checkFormOutput(
                $ticket,
                $name = false,
                $textarea = false,
                $priority = false,
                $save = false,
                $assign = false,
                $openDate = false,
                $timeOwnResolve = false,
                $type = false,
                $status = false,
                $urgency = false,
                $impact = false,
                $category = false,
                $requestSource = false,
                $location = false
            );

            // only assign and priority right for tech (without UPDATE and OWN rights)
            $this->changeTechRight(94209);
            $this->checkFormOutput(
                $ticket,
                $name = false,
                $textarea = false,
                $priority = true,
                $save = true,
                $assign = true,
                $openDate = false,
                $timeOwnResolve = false,
                $type = false,
                $status = false,
                $urgency = false,
                $impact = false,
                $category = false,
                $requestSource = false,
                $location = false
            );

            // no update rights, only display for tech
            $this->changeTechRight(3077);
            $this->checkFormOutput(
                $ticket,
                $name = false,
                $textarea = false,
                $priority = false,
                $save = false,
                $assign = false,
                $openDate = false,
                $timeOwnResolve = false,
                $type = false,
                $status = false,
                $urgency = false,
                $impact = false,
                $category = false,
                $requestSource = false,
                $location = false
            );

            $uid = getItemByTypeName('User', TU_USER, true);
            //add a followup to the ticket
            $fup = new \ITILFollowup();
            $this->integer(
                (int)$fup->add([
                  'itemtype'  => 'Ticket',
                  'items_id'   => $ticket->getID(),
                  'users_id'     => $uid,
                  'content'      => 'A simple followup'
         ])
            )->isGreaterThan(0);

            //check output with changed ACLs when a followup has been added
            $this->checkFormOutput(
                $ticket,
                $name = false,
                $textarea = false,
                $priority = false,
                $save = false,
                $assign = false,
                $openDate = false,
                $timeOwnResolve = false,
                $type = false,
                $status = false,
                $urgency = false,
                $impact = false,
                $category = false,
                $requestSource = false,
                $location = false
            );
        } finally {
            while (ob_get_level() > $output_level) {
                ob_end_clean();
            }
        }
    }

    public function changeTechRight($rights = 168967)
    {
        global $DB;

        // set new rights
        $DB->update(
            'glpi_profilerights',
            ['rights' => $rights],
            [
              'profiles_id'  => 6,
              'name'         => 'ticket'
         ]
        );

        //ACLs have changed: login again.
        $auth = new \Auth();
        $this->boolean((bool) $auth->Login('tech', 'tech', true))->isTrue();

        if ($rights != 168967) {
            //reset rights. Done here so ACLs are reset even if tests fails.
            $DB->update(
                'glpi_profilerights',
                ['rights' => 168967],
                [
                  'profiles_id'  => 6,
                  'name'         => 'ticket'
            ]
            );
        }
    }

    public function testPriorityAcl()
    {
        $output_level = ob_get_level();
        ob_start();
        try {
            $this->login();

            $ticket = new \Ticket();
            $this->integer(
                (int)$ticket->add([
                  'name'    => '',
                  'content' => 'A ticket to check priority ACLS',
         ])
            )->isGreaterThan(0);

            $auth = new \Auth();
            $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();
            $this->boolean((bool)$ticket->getFromDB($ticket->getID()))->isTrue();

            $this->boolean((bool)\Session::haveRight(\Ticket::$rightname, \Ticket::CHANGEPRIORITY))->isFalse();
            //check output with default ACLs
            $this->checkFormOutput(
                $ticket,
                $name = false,
                $textarea = true,
                $priority = false,
                $save = true,
                $assign = false,
                $openDate = true,
                $timeOwnResolve = true,
                $type = true,
                $status = true,
                $urgency = true,
                $impact = true,
                $category = true,
                $requestSource = true,
                $location = true
            );

            //Add priority right from tech profile
            global $DB;
            $DB->update(
                'glpi_profilerights',
                ['rights' => 234503],
                [
                  'profiles_id'  => 6,
                  'name'         => 'ticket'
         ]
            );

            //ACLs have changed: login again.
            $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();

            //reset rights. Done here so ACLs are reset even if tests fails.
            $DB->update(
                'glpi_profilerights',
                ['rights' => 168967],
                [
                  'profiles_id'  => 6,
                  'name'         => 'ticket'
         ]
            );

            $this->boolean((bool)\Session::haveRight(\Ticket::$rightname, \Ticket::CHANGEPRIORITY))->isTrue();
            //check output with changed ACLs
            $this->checkFormOutput(
                $ticket,
                $name = false,
                $textarea = true,
                $priority = true,
                $save = true,
                $assign = false,
                $openDate = true,
                $timeOwnResolve = true,
                $type = true,
                $status = true,
                $urgency = true,
                $impact = true,
                $category = true,
                $requestSource = true,
                $location = true
            );
        } finally {
            while (ob_get_level() > $output_level) {
                ob_end_clean();
            }
        }
    }

    public function testAssignAcl()
    {
        $output_level = ob_get_level();
        ob_start();
        try {
            $this->login();

            $ticket = new \Ticket();
            $this->integer(
                (int)$ticket->add([
                  'name'    => '',
                  'content' => 'A ticket to check assign ACLS',
         ])
            )->isGreaterThan(0);

            $auth = new \Auth();
            $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();
            $this->boolean((bool)$ticket->getFromDB($ticket->getID()))->isTrue();

            $this->boolean((bool)$ticket->canAssign())->isFalse();
            $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
            //check output with default ACLs
            $this->checkFormOutput(
                $ticket,
                $name = false,
                $textarea = true,
                $priority = false,
                $save = true,
                $assign = false,
                $openDate = true,
                $timeOwnResolve = true,
                $type = true,
                $status = true,
                $urgency = true,
                $impact = true,
                $category = true,
                $requestSource = true,
                $location = true
            );

            //Drop being in charge from tech profile
            global $DB;
            $DB->update(
                'glpi_profilerights',
                ['rights' => 136199],
                [
                  'profiles_id'  => 6,
                  'name'         => 'ticket'
         ]
            );

            //ACLs have changed: login again.
            $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();

            //reset rights. Done here so ACLs are reset even if tests fails.
            $DB->update(
                'glpi_profilerights',
                ['rights' => 168967],
                [
                  'profiles_id'  => 6,
                  'name'         => 'ticket'
         ]
            );

            $this->boolean((bool)$ticket->canAssign())->isFalse();
            $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
            //check output with changed ACLs
            $this->checkFormOutput(
                $ticket,
                $name = false,
                $textarea = true,
                $priority = false,
                $save = true,
                $assign = false,
                $openDate = true,
                $timeOwnResolve = true,
                $type = true,
                $status = true,
                $urgency = true,
                $impact = true,
                $category = true,
                $requestSource = true,
                $location = true
            );

            //Add assign in charge from tech profile
            $DB->update(
                'glpi_profilerights',
                ['rights' => 144391],
                [
                  'profiles_id'  => 6,
                  'name'         => 'ticket'
         ]
            );

            //ACLs have changed: login again.
            $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();

            //reset rights. Done here so ACLs are reset even if tests fails.
            $DB->update(
                'glpi_profilerights',
                ['rights' => 168967],
                [
                  'profiles_id'  => 6,
                  'name'         => 'ticket'
         ]
            );

            $this->boolean((bool)$ticket->canAssign())->isTrue();
            $this->boolean((bool)$ticket->canAssignToMe())->isFalse();
            //check output with changed ACLs
            $this->checkFormOutput(
                $ticket,
                $name = false,
                $textarea = true,
                $priority = false,
                $save = true,
                $assign = true,
                $openDate = true,
                $timeOwnResolve = true,
                $type = true,
                $status = true,
                $urgency = true,
                $impact = true,
                $category = true,
                $requestSource = true,
                $location = true
            );
        } finally {
            while (ob_get_level() > $output_level) {
                ob_end_clean();
            }
        }
    }

    public function testUpdateFollowup()
    {
        $uid = getItemByTypeName('User', 'tech', true);
        $auth = new \Auth();
        $this->boolean((bool)$auth->login('tech', 'tech', true))->isTrue();

        $ticket = new \Ticket();
        $this->integer(
            (int)$ticket->add([
              'name'    => '',
              'content' => 'A ticket to check followup updates',
         ])
        )->isGreaterThan(0);

        //add a followup to the ticket
        $fup = new \ITILFollowup();
        $this->integer(
            (int)$fup->add([
              'itemtype'  => $ticket::getType(),
              'items_id'   => $ticket->getID(),
              'users_id'     => $uid,
              'content'      => 'A simple followup'
         ])
        )->isGreaterThan(0);

        $this->login();
        $uid2 = getItemByTypeName('User', TU_USER, true);
        $this->boolean($fup->getFromDB($fup->getID()))->isTrue();
        $this->boolean($fup->update([
           'id'        => $fup->getID(),
           'content'   => 'A simple edited followup'
        ]))->isTrue();

        $this->boolean($fup->getFromDB($fup->getID()))->isTrue();
        $this->array($fup->fields)
           ->variable['users_id']->isEqualTo($uid)
           ->variable['users_id_editor']->isEqualTo($uid2);
    }

    public function testClone()
    {
        $this->login();
        $this->setEntity('Root entity', true);
        $ticket = new \Ticket();
        $ticket = getItemByTypeName('Ticket', '_ticket01');

        $date = date('Y-m-d H:i:s');
        $_SESSION['glpi_currenttime'] = $date;

        // Test item cloning
        $added = $ticket->clone();
        $this->integer((int)$added)->isGreaterThan(0);

        $clonedTicket = new \Ticket();
        $this->boolean($clonedTicket->getFromDB($added))->isTrue();

        $fields = $ticket->fields;

        // Check the ticket values. Id and dates must be different, everything else must be equal
        foreach ($fields as $k => $v) {
            switch ($k) {
                case 'id':
                    $this->variable($clonedTicket->getField($k))->isNotEqualTo($ticket->getField($k));
                    break;
                case 'date_mod':
                case 'date_creation':
                    $dateClone = new \DateTime($clonedTicket->getField($k));
                    $expectedDate = new \DateTime($date);
                    $this->dateTime($dateClone)->isEqualTo($expectedDate);
                    break;
                case 'name':
                    $this->variable($clonedTicket->getField($k))->isEqualTo("{$ticket->getField($k)} (copy)");
                    break;
                default:
                    $this->executeOnFailure(
                        function () use ($k) {
                            var_dump($k);
                        }
                    )->variable($clonedTicket->getField($k))->isEqualTo($ticket->getField($k));
            }
        }
    }

    protected function _testGetTimelinePosition($tlp, $tickets_id)
    {
        foreach ($tlp as $users_name => $user) {
            $this->login($users_name, $user['pass']);
            $uid = getItemByTypeName('User', $users_name, true);

            // ITILFollowup
            $fup = new \ITILFollowup();
            $this->integer(
                (int)$fup->add([
                  'itemtype'  => 'Ticket',
                  'items_id'   => $tickets_id,
                  'users_id'     => $uid,
                  'content'      => 'A simple followup'
            ])
            )->isGreaterThan(0);

            $this->integer(
                (int)$fup->fields['timeline_position']
            )->isEqualTo($user['pos']);

            // TicketTask
            $task = new \TicketTask();
            $this->integer(
                (int)$task->add([
                  'tickets_id'   => $tickets_id,
                  'users_id'     => $uid,
                  'content'      => 'A simple Task'
            ])
            )->isGreaterThan(0);

            $this->integer(
                (int)$task->fields['timeline_position']
            )->isEqualTo($user['pos']);

            // Document and Document_Item
            $doc = new \Document();
            $this->integer(
                (int)$doc->add([
                  'users_id'     => $uid,
                  'tickets_id'   => $tickets_id,
                  'name'         => 'A simple document object'
            ])
            )->isGreaterThan(0);

            $doc_item = new \Document_Item();
            $this->integer(
                (int)$doc_item->add([
                  'users_id'      => $uid,
                  'items_id'      => $tickets_id,
                  'itemtype'      => 'Ticket',
                  'documents_id'  => $doc->getID()
            ])
            )->isGreaterThan(0);

            $this->integer(
                (int)$doc_item->fields['timeline_position']
            )->isEqualTo($user['pos']);

            // TicketValidation
            $val = new \TicketValidation();
            $this->integer(
                (int)$val->add([
                  'tickets_id'   => $tickets_id,
                  'comment_submission'      => 'A simple validation',
                  'users_id_validate' => 5, // normal
                  'status' => 2
            ])
            )->isGreaterThan(0);

            $this->integer(
                (int)$val->fields['timeline_position']
            )->isEqualTo($user['pos']);
        }
    }

    protected function _testGetTimelinePositionSolution($tlp, $tickets_id)
    {
        foreach ($tlp as $users_name => $user) {
            $this->login($users_name, $user['pass']);
            $uid = getItemByTypeName('User', $users_name, true);

            // Ticket Solution
            $tkt = new \Ticket();
            $this->boolean(
                (bool)$tkt->update([
                  'id'   => $tickets_id,
                  'solution'      => 'A simple solution from '.$users_name
            ])
            )->isEqualto(true);

            $this->integer(
                (int)$tkt->getTimelinePosition($tickets_id, 'Solution', $uid)
            )->isEqualTo($user['pos']);
        }
    }

    public function testGetTimelinePosition()
    {

        // login TU_USER
        $this->login();

        // create ticket
        // with post-only as requester
        // tech as assigned to
        // normal as observer
        $ticket = new \Ticket();
        $this->integer((int)$ticket->add([
              'name'                => 'ticket title',
              'content'             => 'a description',
              '_users_id_requester' => '3', // post-only
              '_users_id_observer'  => '5', // normal
              '_users_id_assign'    => ['4', '5'] // tech and normal
        ]))->isGreaterThan(0);

        $tlp = [
           'itsm'      => ['pass' => 'itsm',     'pos' => \CommonITILObject::TIMELINE_LEFT],
           'post-only' => ['pass' => 'postonly', 'pos' => \CommonITILObject::TIMELINE_LEFT],
           'tech'      => ['pass' => 'tech',     'pos' => \CommonITILObject::TIMELINE_RIGHT],
           'normal'    => ['pass' => 'normal',   'pos' => \CommonITILObject::TIMELINE_RIGHT]
        ];

        $this->_testGetTimelinePosition($tlp, $ticket->getID());

        // Solution timeline tests
        $tlp = [
           'tech'      => ['pass' => 'tech',     'pos' => \CommonITILObject::TIMELINE_RIGHT]
        ];

        $this->_testGetTimelinePositionSolution($tlp, $ticket->getID());

        return $ticket->getID();
    }

    public function testGetTimelineItems()
    {

        $tkt_id = $this->testGetTimelinePosition();

        // login TU_USER
        $this->login();

        $ticket = new \Ticket();
        $this->boolean(
            (bool)$ticket->getFromDB($tkt_id)
        )->isTrue();

        // test timeline_position from getTimelineItems()
        $timeline_items = $ticket->getTimelineItems();
        $this->integer($ticket->getTimelineItemCount())->isEqualTo(count($timeline_items));

        foreach ($timeline_items as $item) {
            switch ($item['type']) {
                case 'ITILFollowup':
                case 'TicketTask':
                case 'TicketValidation':
                case 'Document_Item':
                    if (in_array($item['item']['users_id'], [2, 3])) {
                        $this->integer((int)$item['item']['timeline_position'])->isEqualTo(\CommonITILObject::TIMELINE_LEFT);
                    } else {
                        $this->integer((int)$item['item']['timeline_position'])->isEqualTo(\CommonITILObject::TIMELINE_RIGHT);
                    }
                    break;
                case 'Solution':
                    $this->integer((int)$item['item']['timeline_position'])->isEqualTo(\CommonITILObject::TIMELINE_RIGHT);
                    break;
            }
        }
    }

    private function checkTimelineDocumentCount(CommonITILObject $item, int $expected, bool $bypassRights = false): void
    {
        global $DB;
        $manager = Orm::create($DB);
        try {
            // Keep the ordinary ORM projection as an independent semantic oracle.
            $count = (new DocumentRepository($manager))->countTimelineDocuments(
                $item->getType(),
                (int)$item->getID(),
                $item::getAssociatedDocumentAccess($bypassRights)
            );
            $this->integer($count)->isEqualTo($expected);
            $probe = new TimelineDocumentReadProbe($DB->getDoctrineConnection());
            $reader = new TimelineCountReadOperation($probe);
            try {
                $this->integer($reader->documents(
                    $item->getType(),
                    (int)$item->getID(),
                    $item::getAssociatedDocumentAccess($bypassRights)
                ))->isIdenticalTo($count);
                $this->array($probe->queries)->hasSize(1);
                $this->string($probe->queries[0]['sql'])->startWith('SELECT COUNT(*) FROM (SELECT DISTINCT ')
                    ->contains(' AS document_id')->contains(' AS event_date')
                    ->contains('COALESCE(')->contains(') document_events');
                // Missing subjects must not load IDs or inherit another discriminator's events.
                foreach ([0, PHP_INT_MAX] as $missing) {
                    $this->integer($reader->documents(
                        $item->getType(),
                        $missing,
                        $item::getAssociatedDocumentAccess($bypassRights)
                    ))->isIdenticalTo(0);
                }
            } finally {
                $reader->close();
            }
        } finally {
            $manager->clear();
        }
    }

    public function testTimelineCountVisibility()
    {
        global $DB;
        $this->login();
        $profile = $_SESSION['glpiactiveprofile'];
        $author = Session::getLoginUserID();
        $other = getItemByTypeName('User', 'normal', true);
        try {
            foreach (['Ticket', 'Change', 'Problem'] as $type) {
                $_SESSION['glpiactiveprofile'] = $profile;
                $item = new $type();
                $this->integer((int)$item->add(['name' => 'Timeline count visibility', 'content' => 'Count only']))->isGreaterThan(0);
                $this->integer($item->getTimelineItemCount())->isEqualTo(0);
                $task_class = $type . 'Task';
                $private_tasks = (new $task_class())->maybePrivate();
                $this->boolean($private_tasks)->isTrue();
                $task_ids_by_role = $followup_ids_by_role = [];
                foreach (['public' => [0, $other], 'author' => [1, $author], 'other' => [1, $other], 'anonymous' => [1, null]] as $role => [$private, $user]) {
                    $followup = new ITILFollowup();
                    $this->integer((int)$followup->add([
                        'itemtype' => $type, 'items_id' => $item->getID(),
                        'content' => 'Followup visibility', 'is_private' => $private,
                    ]))->isGreaterThan(0);
                    $this->boolean($DB->update($followup->getTable(), ['users_id' => $user], ['id' => $followup->getID()]))->isTrue();
                    $task = new $task_class();
                    $this->integer((int)$task->add([
                        $item->getForeignKeyField() => $item->getID(),
                        'content' => 'Task visibility',
                    ] + ($private_tasks ? ['is_private' => $private] : [])))->isGreaterThan(0);
                    $this->boolean($DB->update($task->getTable(), ['users_id' => $user], ['id' => $task->getID()]))->isTrue();
                    $task_ids_by_role[$role] = $task->getID();
                    $followup_ids_by_role[$role] = $followup->getID();
                }
                $solution = new ITILSolution();
                $this->integer((int)$solution->add([
                    'itemtype' => $type, 'items_id' => $item->getID(), 'content' => 'Solution count',
                ]))->isGreaterThan(0);
                $this->integer($item->getTimelineItemCount())->isEqualTo(9);
                $this->integer($item->getTimelineItemCount())->isEqualTo(count($item->getTimelineItems()));
                $originalAdapter = $DB;
                $probe = new ScalarReadProbe($DB->getDoctrineConnection());
                $this->mockGenerator()->orphanize('__construct');
                $adapter = new TimelineCountAdapter();
                $this->calling($adapter)->getDoctrineConnection = $probe;
                $this->calling($adapter)->getProvider = $DB->getProvider();
                try {
                    $DB = $adapter;
                    $this->integer($item->getTimelineItemCount())->isIdenticalTo(9);
                    $eventQueries = array_filter($probe->queries, static fn (array $query): bool =>
                        str_contains($query['sql'], 'glpi_itilsolutions')
                        || str_contains($query['sql'], 'glpi_itilfollowups')
                        || str_contains($query['sql'], 'glpi_' . strtolower($type) . 'tasks'));
                    // Document EXISTS subqueries can mention the same tables; the
                    // three standalone COUNT statements must still be present.
                    $eventQueries = array_filter($eventQueries, static fn (array $query): bool =>
                        preg_match('/^SELECT COUNT\(/i', $query['sql']) === 1
                        && !str_contains($query['sql'], 'glpi_documents_items'));
                    $this->array($eventQueries)->hasSize(3);
                    $this->integer($probe->builders)->isIdenticalTo(3);
                } finally {
                    $DB = $originalAdapter;
                }


                if ($type === 'Ticket') {
                    $previousCache = $GLOBALS['GLPI_CACHE'];
                    $metadataCache = new class () extends ArrayAdapter {
                        public int $metadataWrites = 0;
                        public int $metadataHits = 0;

                        public function save(CacheItemInterface $item): bool
                        {
                            if (is_string($item->get()) && str_contains($item->get(), 'Doctrine\\ORM\\Mapping\\ClassMetadata')) {
                                ++$this->metadataWrites;
                            }
                            return parent::save($item);
                        }

                        public function getItems(array $keys = []): iterable
                        {
                            foreach (parent::getItems($keys) as $key => $item) {
                                if ($item->isHit() && is_string($item->get()) && str_contains($item->get(), 'Doctrine\\ORM\\Mapping\\ClassMetadata')) {
                                    ++$this->metadataHits;
                                }
                                yield $key => $item;
                            }
                        }
                    };
                    $constructorCache = clone $metadataCache;
                    $read = null;
                    try {
                        $GLOBALS['GLPI_CACHE'] = new Psr16Cache($constructorCache);
                        $read = new TimelineCountReadOperation($probe);
                        // The selected table callback runs after construction. Its
                        // cache choice must be observed by the first actual read.
                        $GLOBALS['GLPI_CACHE'] = new Psr16Cache($metadataCache);
                        $solutionSelection = new TimelineSelection(
                            ['solutions' => ['itemtype' => $type, 'items_id' => $item->getID()]],
                            false,
                            null,
                            false,
                            null
                        );
                        $this->integer($read->solutions(ITILSolution::getTable(), $solutionSelection))->isIdenticalTo(1);
                        $this->integer($constructorCache->metadataWrites)->isIdenticalTo(0);
                        $this->integer($metadataCache->metadataWrites)->isGreaterThan(0);
                        $writes = $metadataCache->metadataWrites;
                        $read->close();
                        $read = new TimelineCountReadOperation($probe);
                        $this->integer($read->solutions(ITILSolution::getTable(), $solutionSelection))->isIdenticalTo(1);
                        $this->integer($metadataCache->metadataWrites)->isIdenticalTo($writes);
                        $this->integer($metadataCache->metadataHits)->isGreaterThan(0);
                        $read->close();

                        // Exercise the complete public sequence, including
                        // Document privacy targets and validation metadata.
                        $this->integer($item->getTimelineItemCount())->isIdenticalTo(9);
                        $this->integer($metadataCache->metadataWrites)->isGreaterThan($writes);
                        $writes = $metadataCache->metadataWrites;
                        $fullHits = $metadataCache->metadataHits;
                        $this->integer($item->getTimelineItemCount())->isIdenticalTo(9);
                        $this->integer($metadataCache->metadataWrites)->isIdenticalTo($writes);
                        $this->integer($metadataCache->metadataHits)->isGreaterThan($fullHits);

                        // A local fallback before the first canonical read must
                        // permanently retire deferred private-cache admission.
                        $hits = $metadataCache->metadataHits;
                        $read = new TimelineCountReadOperation($probe);
                        $configurationSelection = new TimelineSelection(['solutions' => ['context' => 'core']], false, null, false, null);
                        $this->integer($read->solutions('glpi_configs', $configurationSelection))->isGreaterThan(0);
                        $this->integer($read->solutions(ITILSolution::getTable(), $solutionSelection))->isIdenticalTo(1);
                        $this->integer($metadataCache->metadataHits)->isIdenticalTo($hits);
                        $this->integer($metadataCache->metadataWrites)->isIdenticalTo($writes);
                        $read->close();

                        // Register a real metadata mutation after construction:
                        // a connection-owned EventManager must never inherit the
                        // warmed canonical mapping or skip its listener.
                        $localConnection = new TimelineLocalCountProbe($originalAdapter->getDoctrineConnection());
                        $read = new TimelineCountReadOperation($localConnection);
                        $listener = new class () {
                            public int $loads = 0;

                            public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                            {
                                if ($event->getClassMetadata()->name === SolutionRecord::class) {
                                    ++$this->loads;
                                    $event->getClassMetadata()->fieldMappings['itemtype']->columnName = 'content';
                                }
                            }
                        };
                        $localConnection->getEventManager()->addEventListener(['loadClassMetadata'], $listener);
                        $this->integer($read->solutions(ITILSolution::getTable(), $solutionSelection))->isIdenticalTo(0);
                        $this->integer($listener->loads)->isIdenticalTo(1);
                        $this->integer($metadataCache->metadataHits)->isIdenticalTo($hits);
                        $this->integer($metadataCache->metadataWrites)->isIdenticalTo($writes);
                    } finally {
                        $read?->close();
                        $GLOBALS['GLPI_CACHE'] = $previousCache;
                    }
                }

                // A selected non-task table or non-parent column retains the generic count contract.
                $countManager = Orm::forConnection($originalAdapter->getDoctrineConnection());
                $ordinaryCounts = new RecordRepository($countManager);
                $countReader = new TimelineCountReadOperation($probe);
                try {
                    foreach ([
                        [$task_class::getTable(), ['users_id' => (int)$_SESSION['glpiID']]],
                        ['glpi_configs', ['context' => 'core']],
                    ] as [$selectedTable, $criteria]) {
                        $expected = $ordinaryCounts->countMatching($selectedTable, $criteria);
                        $this->integer($expected)->isGreaterThan(0);
                        $selection = new TimelineSelection(['tasks' => $criteria], false, null, false, null);
                        $builders = $probe->builders;
                        $this->integer($countReader->tasks($selectedTable, $selection))->isIdenticalTo($expected);
                        $this->integer($probe->builders)->isIdenticalTo($builders);
                    }
                } finally {
                    $countReader->close();
                    $countManager->clear();
                }

                $_SESSION['glpiactiveprofile']['followup'] &= ~ITILFollowup::SEEPRIVATE;
                $_SESSION['glpiactiveprofile']['task'] &= ~CommonITILTask::SEEPRIVATE;
                foreach (['central', 'helpdesk'] as $interface) {
                    $_SESSION['glpiactiveprofile']['interface'] = $interface;
                    $timeline = $item->getTimelineItems();
                    $probe->builders = 0;
                    $probe->queries = [];
                    try {
                        $DB = $adapter;
                        $count = $item->getTimelineItemCount();
                        $this->integer($probe->builders)->isIdenticalTo(3);
                    } finally {
                        $DB = $originalAdapter;
                    }
                    $this->integer($count)->isEqualTo(count($timeline));
                    $this->integer($count)->isEqualTo($private_tasks ? 5 : 7);
                    $task_ids = array_column(array_column(array_filter($timeline, static fn ($event) => $event['type'] === $task_class), 'item'), 'id');
                    $visible_private = $interface === 'central' ? 'author' : 'anonymous';
                    $hidden_private = $interface === 'central' ? 'anonymous' : 'author';
                    $this->array($task_ids)->hasSize(2)
                        ->contains($task_ids_by_role['public'])->contains($task_ids_by_role[$visible_private])
                        ->notContains($task_ids_by_role['other'])->notContains($task_ids_by_role[$hidden_private]);
                    $followup_ids = array_column(array_column(array_filter($timeline, static fn ($event) => $event['type'] === 'ITILFollowup'), 'item'), 'id');
                    $this->array($followup_ids)->hasSize(2)
                        ->contains($followup_ids_by_role['public'])->contains($followup_ids_by_role['author'])
                        ->notContains($followup_ids_by_role['other'])->notContains($followup_ids_by_role['anonymous']);
                }

                if ($type === 'Ticket') {
                    $expectedRouteCount = $item->getTimelineItemCount();
                    $otherProbe = new ScalarReadProbe($originalAdapter->getDoctrineConnection());
                    $otherAdapter = new TimelineCountAdapter();
                    $this->calling($otherAdapter)->getDoctrineConnection = $otherProbe;
                    $callbackItem = new class () extends LegacyTicket {
                        public static bool $captured = false;
                        public static mixed $replacement;

                        public static function getForeignKeyField()
                        {
                            return LegacyTicket::getForeignKeyField();
                        }

                        public static function getType()
                        {
                            if (self::$captured) {
                                $GLOBALS['DB'] = self::$replacement;
                            }
                            return 'Ticket';
                        }
                    };
                    $callbackItem->fields = $item->fields;
                    $callbackItem::$replacement = $otherAdapter;
                    $selectedCalls = 0;
                    $this->calling($adapter)->getDoctrineConnection = static function () use ($probe, $callbackItem, &$selectedCalls): Connection {
                        ++$selectedCalls;
                        $callbackItem::$captured = true;
                        return $probe;
                    };
                    try {
                        $DB = $adapter;
                        $probe->builders = 0;
                        $this->integer($callbackItem->getTimelineItemCount())->isIdenticalTo($expectedRouteCount);
                        $this->integer($selectedCalls)->isIdenticalTo(1);
                        $this->integer($probe->builders)->isIdenticalTo(3);
                        $this->array($otherProbe->queries)->isEmpty();
                        $this->object($DB)->isIdenticalTo($otherAdapter);
                    } finally {
                        $callbackItem::$captured = false;
                        $this->calling($adapter)->getDoctrineConnection = $probe;
                        $DB = $originalAdapter;
                    }

                    $local = new TimelineLocalCountProbe($originalAdapter->getDoctrineConnection());
                    $listener = new class () {
                        public int $loads = 0;

                        public function loadClassMetadata(): void
                        {
                            ++$this->loads;
                        }
                    };
                    $local->getEventManager()->addEventListener(['loadClassMetadata'], $listener);
                    $originalTypes = [];
                    $converters = [
                        'string' => new class () extends StringType {
                            public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                            {
                                return "CASE WHEN " . $sqlExpr . " = '' THEN 'no-timeline-kind' ELSE 'no-timeline-kind' END";
                            }
                        },
                        'bigint' => new class () extends BigIntType {
                            public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                            {
                                return 'CASE WHEN ' . $sqlExpr . ' = -1 THEN -1 ELSE -1 END';
                            }
                        },
                        'integer' => new class () extends IntegerType {
                            public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                            {
                                return 'CASE WHEN ' . $sqlExpr . ' = -1 THEN -1 ELSE -1 END';
                            }
                        },
                        'boolean' => new class () extends BooleanType {
                            public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                            {
                                return '(CASE WHEN ' . $sqlExpr . ' THEN FALSE ELSE TRUE END)';
                            }
                        },
                    ];
                    try {
                        $DB = $adapter;
                        foreach ($converters as $name => $converter) {
                            $originalTypes[$name] = Type::getType($name);
                            Type::overrideType($name, $converter);
                            $this->calling($adapter)->getDoctrineConnection = $local;
                            $expected = $item->getTimelineItemCount();
                            $this->integer($local->builders)->isIdenticalTo(0);
                            $this->integer($listener->loads)->isGreaterThan(0);
                            $this->calling($adapter)->getDoctrineConnection = $probe;
                            $probe->builders = 0;
                            $this->integer($item->getTimelineItemCount())->isIdenticalTo($expected);
                            $this->integer($probe->builders)->isIdenticalTo(3);
                            Type::overrideType($name, $originalTypes[$name]);
                        }
                    } finally {
                        foreach ($originalTypes as $name => $original) {
                            Type::overrideType($name, $original);
                        }
                        $this->calling($adapter)->getDoctrineConnection = $probe;
                        $DB = $originalAdapter;
                    }
                }

                foreach (['followup', 'task', 'ticket', 'change', 'problem', 'document', 'ticketvalidation', 'changevalidation'] as $right) {
                    $_SESSION['glpiactiveprofile'][$right] = 0;
                }
                // Existing rendering always includes solutions, even without event READ rights.
                $this->integer($item->getTimelineItemCount())->isEqualTo(1)->isEqualTo(count($item->getTimelineItems()));
                if ($type !== 'Ticket') {
                    $this->string($item->getTabNameForItem($item))->isEmpty();
                }
            }
        } finally {
            $_SESSION['glpiactiveprofile'] = $profile;
        }
    }

    public function testTimelineCountEventKeys()
    {
        global $DB;
        $this->login();
        foreach (['Ticket', 'Change', 'Problem'] as $type) {
            $item = new $type();
            $this->integer((int)$item->add(['name' => 'Timeline event keys', 'content' => 'Count only']))->isGreaterThan(0);
            $document = new Document();
            $this->integer((int)$document->add(['name' => 'Timeline count attachment']))->isGreaterThan(0);
            $bindings = [];
            foreach ([CommonITILObject::TIMELINE_LEFT, CommonITILObject::TIMELINE_RIGHT] as $position) {
                $binding = new Document_Item();
                $this->integer((int)$binding->add([
                    'itemtype' => $type, 'items_id' => $item->getID(),
                    'documents_id' => $document->getID(), 'timeline_position' => $position,
                ]))->isGreaterThan(0);
                $bindings[] = $binding->getID();
            }
            // NULL date falls back to date_creation; duplicate event keys collapse.
            $this->boolean($DB->update('glpi_documents_items', [
                'date' => null, 'date_creation' => '2020-01-01 12:00:00', 'users_id' => null,
            ], ['id' => $bindings]))->isTrue();
            $this->checkTimelineDocumentCount($item, 1);
            $this->integer($item->getTimelineItemCount())->isEqualTo(1)->isEqualTo(count($item->getTimelineItems()));
            $this->boolean($DB->update('glpi_documents_items', ['date' => '2020-01-02 12:00:00'], ['id' => $bindings[1]]))->isTrue();
            $this->checkTimelineDocumentCount($item, 2);
            $this->integer($item->getTimelineItemCount())->isEqualTo(2)->isEqualTo(count($item->getTimelineItems()));
            if ($type === 'Ticket') {
                $originalInteger = Type::getType('integer');
                $converter = new class () extends IntegerType {
                    public int $calls = 0;
                    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                    {
                        // The fifth original occurrence is the final timeline-position predicate.
                        return ++$this->calls === 5 ? '(' . $sqlExpr . ' + 100)' : $sqlExpr;
                    }
                };
                $access = new ITILDocumentAccess(17, true, true, true, true, true);
                $manager = Orm::create($DB);
                $probe = new TimelineDocumentReadProbe($DB->getDoctrineConnection());
                $reader = new TimelineCountReadOperation($probe);
                try {
                    Type::overrideType('integer', $converter);
                    $expected = (new DocumentRepository($manager))->countTimelineDocuments('Ticket', (int)$item->getID(), $access);
                    $this->integer($expected)->isIdenticalTo(0);
                    $this->integer($converter->calls)->isIdenticalTo(5);
                    $converter->calls = 0;
                    $this->integer($reader->documents('Ticket', (int)$item->getID(), $access))->isIdenticalTo($expected);
                    $this->integer($converter->calls)->isIdenticalTo(5);
                    $this->array($probe->queries)->hasSize(1);
                    $this->string($probe->queries[0]['sql'])->startWith('SELECT DISTINCT ');
                } finally {
                    Type::overrideType('integer', $originalInteger);
                    $reader->close();
                    $manager->clear();
                }
                $dispatch = new class ($DB->getDoctrineConnection()) extends TimelineDocumentReadProbe {
                    public function fetchOne(string $query, array $params = [], array $types = []): mixed
                    {
                        throw new LogicException('Document reads must retain the ORM executeQuery dispatch');
                    }
                };
                $reader = new TimelineCountReadOperation($dispatch);
                try {
                    $this->integer($reader->documents('Ticket', (int)$item->getID(), $access))->isIdenticalTo(2);
                    $this->array($dispatch->queries)->hasSize(1);
                    $this->string($dispatch->queries[0]['sql'])->startWith('SELECT COUNT(*) FROM (SELECT DISTINCT ');
                } finally {
                    $reader->close();
                }
                // Supplied compiler extensions keep their ordinary ORM dispatch.
                foreach ([
                    new class ($DB->getDoctrineConnection()) extends TimelineDocumentReadProbe {
                        public function createQueryBuilder(): QueryBuilder
                        {
                            throw new LogicException('ORM reads do not invoke the DBAL builder extension');
                        }
                    },
                    new class ($DB->getDoctrineConnection()) extends TimelineDocumentReadProbe {
                        public function getDatabasePlatform(): AbstractPlatform
                        {
                            return parent::getDatabasePlatform();
                        }
                    },
                    new class ($DB->getDoctrineConnection()) extends TimelineDocumentReadProbe {
                        public function quote(string $value): string
                        {
                            return parent::quote($value);
                        }
                    },
                ] as $extended) {
                    $reader = new TimelineCountReadOperation($extended);
                    try {
                        $this->integer($reader->documents('Ticket', (int)$item->getID(), $access))->isIdenticalTo(2);
                        $this->array($extended->queries)->hasSize(1);
                        $this->string($extended->queries[0]['sql'])->startWith('SELECT DISTINCT ');
                    } finally {
                        $reader->close();
                    }
                }
                $local = new TimelineLocalCountProbe($DB->getDoctrineConnection());
                $reader = new TimelineCountReadOperation($local);
                $listener = new class () {
                    public int $loads = 0;
                    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                    {
                        if ($event->getClassMetadata()->name === DocumentItem::class) {
                            ++$this->loads;
                            $event->getClassMetadata()->fieldMappings['date']->columnName = 'date_creation';
                        }
                    }
                };
                // A listener attached after construction still owns this operation's metadata.
                $local->getEventManager()->addEventListener(['loadClassMetadata'], $listener);
                try {
                    $this->integer($reader->documents('Ticket', (int)$item->getID(), $item::getAssociatedDocumentAccess()))->isIdenticalTo(1);
                    $this->integer($listener->loads)->isIdenticalTo(1);
                    $this->integer($local->builders)->isIdenticalTo(0);
                } finally {
                    $reader->close();
                }
            }
            $this->boolean($DB->update('glpi_documents_items', ['date' => null, 'date_creation' => null], ['id' => $bindings]))->isTrue();
            $this->checkTimelineDocumentCount($item, 1);
            $this->integer($item->getTimelineItemCount())->isEqualTo(1)->isEqualTo(count($item->getTimelineItems()));
            $this->boolean($DB->update('glpi_documents_items', ['timeline_position' => CommonITILObject::NO_TIMELINE], ['id' => $bindings[1]]))->isTrue();
            $this->checkTimelineDocumentCount($item, 1);
            $this->integer($item->getTimelineItemCount())->isEqualTo(1)->isEqualTo(count($item->getTimelineItems()));

            if ($type !== 'Problem') {
                $validation_class = $type . 'Validation';
                $validation = new $validation_class();
                $this->integer((int)$validation->add([
                    $item->getForeignKeyField() => $item->getID(),
                    'users_id_validate' => Session::getLoginUserID(),
                    'comment_submission' => 'Timeline count validation',
                ]))->isGreaterThan(0);
                $connection = $DB->getDoctrineConnection();
                $reader = new TimelineCountReadOperation($connection);
                $managerProperty = new ReflectionProperty(TimelineCountReadOperation::class, 'manager');
                $originalManager = $managerProperty->getValue($reader);
                $countManager = new class ($connection, Orm::configuration($connection->getDatabasePlatform())) extends EntityManager {
                    public int $dql = 0;
                    public function createQuery(string $dql = ''): Query
                    {
                        ++$this->dql;
                        return parent::createQuery($dql);
                    }
                };
                $managerProperty->setValue($reader, $countManager);
                $criteria = [$item->getForeignKeyField() => (int)$item->getID()];
                $referenceManager = Orm::forConnection($connection);
                $reference = new TimelineRepository($referenceManager);
                try {
                    $this->integer($reader->validations($validation->getTable(), $criteria))->isIdenticalTo(1);
                    // Existing public reader is callable on original source: its DQL budget is one.
                    $this->integer($countManager->dql)->isIdenticalTo(0);
                    foreach ([null, 'null', 'NULL', '', false, 0, -1, (string)$item->getID()] as $selection) {
                        $where = [$item->getForeignKeyField() => $selection];
                        $this->integer($reader->validations($validation->getTable(), $where))
                            ->isIdenticalTo($reference->countValidations($validation->getTable(), $where));
                    }
                    // Nonplain criteria retain the original compiler, not a partial SQL translator.
                    $where = [$item->getForeignKeyField() => ['=', (int)$item->getID()]];
                    $this->integer($reader->validations($validation->getTable(), $where))->isIdenticalTo(1);
                    $integer = Type::getType('integer');
                    $bigint = Type::getType('bigint');
                    try {
                        Type::overrideType('bigint', new class () extends BigIntType {
                            public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                            {
                                throw new LogicException('COUNT operands are raw mapped paths, not selected scalar converters.');
                            }
                        });
                        $this->integer($reader->validations($validation->getTable(), $criteria))
                            ->isIdenticalTo($reference->countValidations($validation->getTable(), $criteria));
                        $converter = new class () extends IntegerType {
                            public int $calls = 0;
                            public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
                            {
                                ++$this->calls;
                                return '(' . $sqlExpr . ' + 1000000)';
                            }
                            public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?int
                            {
                                throw new LogicException('The scalar aggregate keeps its native value before the final int cast.');
                            }
                        };
                        Type::overrideType('integer', $converter);
                        $this->integer($reader->validations($validation->getTable(), $criteria))->isIdenticalTo(0);
                        $this->integer($reference->countValidations($validation->getTable(), $criteria))->isIdenticalTo(0);
                        $this->integer($converter->calls)->isIdenticalTo(2);
                    } finally {
                        Type::overrideType('integer', $integer);
                        Type::overrideType('bigint', $bigint);
                    }
                } finally {
                    $reader->close();
                    $originalManager->clear();
                    $referenceManager->clear();
                }
                $this->boolean($DB->update($validation->getTable(), [
                    'submission_date' => '2020-01-01 12:00:00', 'validation_date' => '2020-01-02 12:00:00',
                ], ['id' => $validation->getID()]))->isTrue();
                $local = new TimelineLocalCountProbe($connection);
                $reader = new TimelineCountReadOperation($local);
                $listener = new class ($validation->getTable()) {
                    public int $loads = 0;
                    public function __construct(private string $table)
                    {
                    }
                    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
                    {
                        if ($event->getClassMetadata()->getTableName() === $this->table) {
                            ++$this->loads;
                            $event->getClassMetadata()->fieldMappings['validation_date']->columnName = 'submission_date';
                        }
                    }
                };
                $local->getEventManager()->addEventListener(['loadClassMetadata'], $listener);
                try {
                    $this->integer($reader->validations($validation->getTable(), $criteria))->isIdenticalTo(1);
                    $this->integer($listener->loads)->isIdenticalTo(1);
                    $this->integer($local->builders)->isIdenticalTo(0);
                } finally {
                    $reader->close();
                    $local->getEventManager()->removeEventListener(['loadClassMetadata'], $listener);
                }
                foreach ([
                    ['2020-01-01 12:00:00', null, 2],
                    ['2020-01-01 12:00:00', '2020-01-02 12:00:00', 3],
                    ['2020-01-01 12:00:00', '2020-01-01 12:00:00', 2],
                    [null, '2020-01-02 12:00:00', 3],
                    [null, null, 2],
                ] as [$submitted, $answered, $expected]) {
                    $this->boolean($DB->update($validation->getTable(), [
                        'submission_date' => $submitted, 'validation_date' => $answered,
                    ], ['id' => $validation->getID()]))->isTrue();
                    $timeline = $item->getTimelineItems();
                    $this->integer($item->getTimelineItemCount())->isEqualTo($expected)->isEqualTo(count($timeline));
                    if ($answered === null) {
                        $request = array_values(array_filter($timeline, static fn ($event) => $event['type'] === $validation_class))[0];
                        $this->boolean($request['item']['can_answer'])->isTrue();
                    }
                }
                $this->boolean($DB->update($validation->getTable(), ['users_id' => null, 'users_id_validate' => null], ['id' => $validation->getID()]))->isTrue();
                $this->integer($item->getTimelineItemCount())->isEqualTo(2)->isEqualTo(count($item->getTimelineItems()));
                $profile = $_SESSION['glpiactiveprofile'];
                try {
                    $_SESSION['glpiactiveprofile'][$validation_class::$rightname] = 0;
                    $this->integer($item->getTimelineItemCount())->isEqualTo(1)->isEqualTo(count($item->getTimelineItems()));
                } finally {
                    $_SESSION['glpiactiveprofile'] = $profile;
                }
            }
        }
    }

    public function testSatisfactionTabReadsOnlyClosedTickets(): void
    {
        global $DB;
        $this->login();
        $ticket = $this->createItem(LegacyTicket::class, ['name' => $this->getUniqueString(),
            'content' => 'Satisfaction tab guard', '_disablenotif' => true]);
        $satisfaction = new TicketSatisfaction();
        $this->integer((int)$satisfaction->add([
            'tickets_id' => $ticket->getID(), 'type' => 1, 'date_begin' => $_SESSION['glpi_currenttime'],
        ]))->isGreaterThan(0);
        $this->boolean($satisfaction->getFromDB($ticket->getID()))->isTrue();
        $database = $DB;
        $connection = $database->getDoctrineConnection();
        $this->mockGenerator->orphanize('__construct');
        $adapter = new TimelineCountAdapter();
        $this->calling($adapter)->getProvider = $database->getProvider();
        $this->calling($adapter)->request = static fn ($table, $criteria = '', $debug = false) =>
            $database->request($table, $criteria, $debug);
        $probe = new TimelineDocumentReadProbe($connection);
        $this->calling($adapter)->getDoctrineConnection = $probe;
        $readTabs = function (int $expectedReads) use ($ticket, $database, $adapter, $probe): array {
            global $DB;
            // Keep the canonical scoped path as the domain oracle, then observe
            // the same public call on the same physical transaction.
            $expected = $ticket->getTabNameForItem($ticket);
            $probe->queries = [];
            try {
                $DB = $adapter;
                $observed = $ticket->getTabNameForItem($ticket);
            } finally {
                $DB = $database;
            }
            $this->array($observed)->isIdenticalTo($expected);
            $reads = array_values(array_filter($probe->queries, static fn (array $query): bool =>
                str_contains($query['sql'], 'glpi_ticketsatisfactions')));
            $this->array($reads)->hasSize($expectedReads);
            if ($expectedReads !== 0) {
                $this->array(array_values($reads[0]['params']))->isIdenticalTo([(int)$ticket->getID()]);
            }
            return $expected;
        };
        $ticket->fields['status'] = $_SESSION['INCOMING'];
        $open = $readTabs(0);
        $this->array($open)->notHasKey(3);
        $this->array($open)->hasKey(1);
        $ticket->fields['status'] = $_SESSION['CLOSED'];
        $closed = $readTabs(1);
        $this->array($closed)->hasKey(3);
        $this->string($closed[3])->isIdenticalTo(__('Satisfaction'));
        unset($closed[3]);
        $this->array($closed)->isIdenticalTo($open);
        // A missing satisfaction on a closed ticket still performs the current presence read.
        $this->boolean($satisfaction->delete(['tickets_id' => $ticket->getID()], true))->isTrue();
        $this->array($readTabs(1))->isIdenticalTo($open);
        $ticket->fields['status'] = $_SESSION['INCOMING'];
        $this->array($readTabs(0))->isIdenticalTo($open);
    }

    public function testTimelineCountAssociatedDocumentVisibility()
    {
        global $DB;
        $this->login();
        $profile = $_SESSION['glpiactiveprofile'];
        $show_count = $_SESSION['glpishow_count_on_tabs'];
        try {
            $ticket = new LegacyTicket();
            $this->integer((int)$ticket->add(['name' => 'Timeline private attachments', 'content' => 'Count only']))->isGreaterThan(0);
            foreach ([Session::getLoginUserID(), getItemByTypeName('User', 'normal', true)] as $author) {
                $followup = new ITILFollowup();
                $this->integer((int)$followup->add([
                    'itemtype' => 'Ticket', 'items_id' => $ticket->getID(), 'content' => 'Private attachment', 'is_private' => 1,
                ]))->isGreaterThan(0);
                $this->boolean($DB->update($followup->getTable(), ['users_id' => $author], ['id' => $followup->getID()]))->isTrue();
                $document = new Document();
                $this->integer((int)$document->add(['name' => 'Private followup attachment']))->isGreaterThan(0);
                $binding = new Document_Item();
                $this->integer((int)$binding->add([
                    'itemtype' => 'ITILFollowup', 'items_id' => $followup->getID(),
                    'documents_id' => $document->getID(), 'timeline_position' => CommonITILObject::TIMELINE_LEFT,
                ]))->isGreaterThan(0);
            }
            $document = new Document();
            $this->integer((int)$document->add(['name' => 'Direct attachment']))->isGreaterThan(0);
            $binding = new Document_Item();
            $this->integer((int)$binding->add([
                'itemtype' => 'Ticket', 'items_id' => $ticket->getID(), 'documents_id' => $document->getID(),
            ]))->isGreaterThan(0);
            $this->checkTimelineDocumentCount($ticket, 3);
            $this->integer($ticket->getTimelineItemCount())->isEqualTo(5)->isEqualTo(count($ticket->getTimelineItems()));
            $custom = new class () extends LegacyTicket {
                public static function getType()
                {
                    return 'Ticket';
                }
                public static function getTable($classname = null)
                {
                    return LegacyTicket::getTable();
                }
                public function getAssociatedDocumentsCriteria($bypass_rights = false): array
                {
                    return [Document_Item::getTableField('id') => 0];
                }
            };
            $custom->fields = $ticket->fields;
            $this->integer($custom->getTimelineItemCount())->isEqualTo(2)->isEqualTo(count($custom->getTimelineItems()));

            $_SESSION['glpiactiveprofile']['followup'] &= ~ITILFollowup::SEEPRIVATE;
            $this->checkTimelineDocumentCount($ticket, 2);
            $this->checkTimelineDocumentCount($ticket, 3, true);
            $this->integer($ticket->getTimelineItemCount())->isEqualTo(3)->isEqualTo(count($ticket->getTimelineItems()));
            foreach ([0, 1] as $enabled) {
                $_SESSION['glpishow_count_on_tabs'] = $enabled;
                $this->string($ticket->getTabNameForItem($ticket)[1])->contains("<sup class='tab_nb'>3</sup>");
            }
            foreach (['followup', 'task', 'ticket', 'change', 'problem', 'document'] as $right) {
                $_SESSION['glpiactiveprofile'][$right] = 0;
            }
            $_SESSION['glpiactiveprofile']['ticket'] = LegacyTicket::READDOCUMENT;
            $this->checkTimelineDocumentCount($ticket, 1);
            $this->integer($ticket->getTimelineItemCount())->isEqualTo(1)->isEqualTo(count($ticket->getTimelineItems()));
        } finally {
            $_SESSION['glpiactiveprofile'] = $profile;
            $_SESSION['glpishow_count_on_tabs'] = $show_count;
        }
    }

    public function testTimelineCountUsesLocalCalendarKeys()
    {
        global $DB;
        $this->login();
        $connection = $DB->getDoctrineConnection();
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $timezone = $connection->fetchOne($postgres ? 'SHOW TIME ZONE' : 'SELECT @@session.time_zone');
        try {
            $connection->executeStatement($postgres ? "SET TIME ZONE 'UTC'" : "SET time_zone = '+00:00'");
            $ticket = new LegacyTicket();
            $this->integer((int)$ticket->add(['name' => 'Timeline DST fold', 'content' => 'Count calendar keys']))->isGreaterThan(0);
            $document = new Document();
            $this->integer((int)$document->add(['name' => 'Fold attachment']))->isGreaterThan(0);
            foreach ([
                [CommonITILObject::TIMELINE_LEFT, '2026-10-25 00:30:00'],
                [CommonITILObject::TIMELINE_RIGHT, '2026-10-25 01:30:00'],
            ] as [$position, $date]) {
                $binding = new Document_Item();
                $this->integer((int)$binding->add([
                    'itemtype' => 'Ticket', 'items_id' => $ticket->getID(),
                    'documents_id' => $document->getID(), 'timeline_position' => $position,
                ]))->isGreaterThan(0);
                $this->boolean($DB->update($binding->getTable(), ['date' => $date], ['id' => $binding->getID()]))->isTrue();
            }
            $validation = new TicketValidation();
            $this->integer((int)$validation->add([
                'tickets_id' => $ticket->getID(), 'users_id_validate' => Session::getLoginUserID(),
                'comment_submission' => 'Fold validation',
            ]))->isGreaterThan(0);
            $this->boolean($DB->update($validation->getTable(), [
                'submission_date' => '2026-10-25 00:30:00', 'validation_date' => '2026-10-25 01:30:00',
            ], ['id' => $validation->getID()]))->isTrue();
            $this->checkTimelineDocumentCount($ticket, 2);
            $this->integer($ticket->getTimelineItemCount())->isEqualTo(4)->isEqualTo(count($ticket->getTimelineItems()));
            // Both instants are 02:30 in Paris's repeated hour. MySQL's fixed-offset
            // control needs no populated timezone tables and keeps the keys distinct.
            $connection->executeStatement($postgres ? "SET TIME ZONE 'Europe/Paris'" : "SET time_zone = '+02:00'");
            $this->checkTimelineDocumentCount($ticket, $postgres ? 1 : 2);
            $this->integer($ticket->getTimelineItemCount())->isEqualTo($postgres ? 2 : 4)->isEqualTo(count($ticket->getTimelineItems()));
        } finally {
            $connection->executeStatement($postgres ? 'SELECT set_config(?, ?, false)' : 'SET time_zone = ?', $postgres ? ['TimeZone', $timezone] : [$timezone]);
        }
    }

    public function testTimelineCountKeepsCustomTimeline()
    {
        $ticket = new class () extends LegacyTicket {
            public function getTimelineItems()
            {
                return ['plugin-event' => ['type' => 'custom']];
            }
        };
        $this->integer($ticket->getTimelineItemCount())->isEqualTo(1);
    }

    public function inputProvider()
    {
        return [
           [
              'input'     => [
                 'name'     => 'This is a title',
                 'content'   => 'This is a content'
              ],
              'expected'  => [
                 'name' => 'This is a title',
                 'content' => 'This is a content'
              ]
           ], [
              'input'     => [
                 'name'      => '',
                 'content'   => 'This is a content'
              ],
              'expected'  => [
                 'name' => 'This is a content',
                 'content' => 'This is a content'
              ]
           ], [
              'input'     => [
                 'name'      => '',
                 'content'   => "This is a content\nwith a carriage return"
              ],
              'expected'  => [
                 'name' => 'This is a content with a carriage return',
                 'content' => 'This is a content\nwith a carriage return'
              ]
           ], [
              'input'     => [
                 'name'      => '',
                 'content'   => "This is a content\r\nwith a carriage return"
              ],
              'expected'  => [
                 'name' => 'This is a content with a carriage return',
                 'content' => 'This is a content\nwith a carriage return'
              ]
           ], [
              'input'     => [
                 'name'      => '',
                 'content'   => "<p>This is a content\r\nwith a carriage return</p>"
              ],
              'expected'  => [
                 'name' => 'This is a content with a carriage return',
                 'content' => '<p>This is a content\nwith a carriage return</p>',
              ]
           ], [
              'input'     => [
                 'name'      => '',
                 'content'   => "&lt;p&gt;This is a content\r\nwith a carriage return&lt;/p&gt;"
              ],
              'expected'  => [
                 'name' => 'This is a content with a carriage return',
                 'content' => '&lt;p&gt;This is a content\nwith a carriage return&lt;/p&gt;'
              ]
           ], [
              'input'     => [
                 'name'      => '',
                 'content'   => 'Test for buggy &#039; character'
              ],
              'expected'  => [
                 'name'      => 'Test for buggy \\\' character',
                 'content'   => 'Test for buggy \\\' character',
              ]
           ], [
              'input'     => [
                 'name'      => '',
                 'content'   => 'Test for buggy &#39; character'
              ],
              'expected'  => [
                 'name'      => 'Test for buggy \\\' character',
                 'content'   => 'Test for buggy \\\' character',
              ]
           ]
        ];
    }

    /**
     * @dataProvider inputProvider
     */
    public function testPrepareInputForAdd($input, $expected)
    {
        $this->login();
        $prepared_input = [];
        $this
           ->if($this->newTestedInstance)
           ->then
              ->array($prepared_input = $this->testedInstance->prepareInputForAdd(\Toolbox::addslashes_deep($input)))
                 ->string['content']->isIdenticalTo($expected['content']);

        if (isset($expected['name']) && isset($prepared_input['name'])) {
            $this->string($prepared_input['name'])->isIdenticalTo($expected['name']);
        }
    }

    public function itilActorTranslationProvider()
    {
        return [
           'requester user' => [
              '_itil_requester',
              'user',
              '_users_id_requester',
              'user:post-only',
           ],
           'requester group' => [
              '_itil_requester',
              'group',
              '_groups_id_of_requester',
              'group',
           ],
           'observer user' => [
              '_itil_observer',
              'user',
              '_users_id_observer',
              'user:normal',
           ],
           'observer group' => [
              '_itil_observer',
              'group',
              '_groups_id_observer',
              'group',
           ],
           'assign user' => [
              '_itil_assign',
              'user',
              '_users_id_assign',
              'user:tech',
           ],
           'assign group' => [
              '_itil_assign',
              'group',
              '_groups_id_assign',
              'group',
           ],
           'assign supplier' => [
              '_itil_assign',
              'supplier',
              '_suppliers_id_assign',
              'supplier',
           ],
        ];
    }

    private function resolveItilActorTranslationValue($source)
    {
        if (strpos($source, 'user:') === 0) {
            return getItemByTypeName('User', substr($source, 5), true);
        }

        if ($source === 'group') {
            $group = new \Group();
            $groupId = (int)$group->add([
               'name'         => 'actor-format-group-' . uniqid(),
               'entities_id'  => 0,
               'is_requester' => 1,
               'is_assign'    => 1,
               'is_watcher'   => 1,
            ]);
            $this->integer($groupId)->isGreaterThan(0);
            return $groupId;
        }

        if ($source === 'supplier') {
            $supplier = new \Supplier();
            $supplierId = (int)$supplier->add([
               'name'        => 'actor-format-supplier-' . uniqid(),
               'entities_id' => 0,
            ]);
            $this->integer($supplierId)->isGreaterThan(0);
            return $supplierId;
        }

        throw new \RuntimeException('Unknown actor source: ' . $source);
    }

    /**
     * @dataProvider itilActorTranslationProvider
     */
    public function testPrepareInputForAddTranslatesItilActorFormats(
        $itilField,
        $actorType,
        $expectedField,
        $valueSource
    ) {
        $this->login();
        $this->newTestedInstance();

        $value = $this->resolveItilActorTranslationValue($valueSource);
        $idField = sprintf('%ss_id', $actorType);

        $input = [
           'name'    => 'actor translation test',
           'content' => 'actor translation test',
           $itilField => [
              '_type'    => $actorType,
              $idField    => $value,
           ],
        ];

        $preparedInput = $this->testedInstance->prepareInputForAdd(\Toolbox::addslashes_deep($input));

        $this->array($preparedInput)->hasKey($expectedField);
        $this->variable($preparedInput[$expectedField])->isEqualTo($value);
    }

    public function testAssignChangeStatus()
    {
        // login postonly
        $this->login('post-only', 'postonly');

        //create a new ticket
        $ticket = new \Ticket();
        $this->integer(
            (int)$ticket->add([
              'name'    => '',
              'content' => 'A ticket to check change of status when using "associate myself" feature',
         ])
        )->isGreaterThan(0);
        $tickets_id = $ticket->getID();
        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();

        // login TU_USER
        $this->login();

        // simulate "associate myself" feature
        $ticket_user = new \Ticket_User();
        $input_ticket_user = [
           'tickets_id'       => $tickets_id,
           'users_id'         => \Session::getLoginUserID(),
           'use_notification' => 1,
           'type'             => \CommonITILActor::ASSIGN
        ];
        $this->integer((int) $ticket_user->add($input_ticket_user))->isGreaterThan(0);
        $this->boolean($ticket_user->getFromDB($ticket_user->getId()))->isTrue();

        // check status (should be ASSIGNED)
        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $this->integer((int) $ticket->fields['status'])
             ->isEqualto(\CommonITILObject::ASSIGNED);

        // remove associated user
        $ticket_user->delete([
           'id' => $ticket_user->getId()
        ]);

        // check status (should be INCOMING)
        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $this->integer((int) $ticket->fields['status'])
             ->isEqualto(\CommonITILObject::INCOMING);

        // drop UPDATE right to TU_USER and redo "associate myself"
        $saverights = $_SESSION['glpiactiveprofile'];
        $_SESSION['glpiactiveprofile']['ticket'] -= \UPDATE;
        $this->integer((int) $ticket_user->add($input_ticket_user))->isGreaterThan(0);
        // restore rights
        $_SESSION['glpiactiveprofile'] = $saverights;
        //check ticket creation
        $this->boolean($ticket_user->getFromDB($ticket_user->getId()))->isTrue();

        // check status (should be ASSIGNED)
        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $this->integer((int) $ticket->fields['status'])
             ->isEqualto(\CommonITILObject::ASSIGNED);

        // remove associated user
        $ticket_user->delete([
           'id' => $ticket_user->getId()
        ]);

        // check status (should be INCOMING)
        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $this->integer((int) $ticket->fields['status'])
             ->isEqualto(\CommonITILObject::INCOMING);

        // remove associated user
        $ticket_user->delete([
           'id' => $ticket_user->getId()
        ]);

        // check with very limited rights and redo "associate myself"
        $_SESSION['glpiactiveprofile']['ticket'] = \CREATE
                                                 + \Ticket::READMY
                                                 + \Ticket::READALL
                                                 + \Ticket::READGROUP
                                                 + \Ticket::OWN; // OWN right must allow self-assign
        $this->integer((int) $ticket_user->add($input_ticket_user))->isGreaterThan(0);
        // restore rights
        $_SESSION['glpiactiveprofile'] = $saverights;
        //check ticket creation
        $this->boolean($ticket_user->getFromDB($ticket_user->getId()))->isTrue();

        // check status (should still be ASSIGNED)
        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $this->integer((int) $ticket->fields['status'])
             ->isEqualto(\CommonITILObject::ASSIGNED);
    }

    public function testClosedTicketTransfer()
    {

        // 1- create a category
        $itilcat      = new \ITILCategory();
        $first_cat_id = $itilcat->add([
                                         'name' => 'my first cat',
                                      ]);
        $this->boolean($itilcat->isNewItem())->isFalse();

        // 2- create a category
        $second_cat    = new \ITILCategory();
        $second_cat_id = $second_cat->add([
                                             'name' => 'my second cat',
                                          ]);
        $this->boolean($second_cat->isNewItem())->isFalse();

        // 3- create ticket
        $ticket    = new \Ticket();
        $ticket_id = $ticket->add([
                                     'name'              => 'A ticket to check the category change when using the "transfer" function.',
                                     'content'           => 'A ticket to check the category change when using the "transfer" function.',
                                     'itilcategories_id' => $first_cat_id,
                                     'status'            => \CommonITILObject::CLOSED
                                  ]);

        $this->boolean($ticket->isNewItem())->isFalse();

        // 4 - delete category with replacement
        $itilcat->delete(['id'          => $first_cat_id,
                          '_replace_by' => $second_cat_id], 1);

        // 5 - check that the category has been replaced in the ticket
        $ticket->getFromDB($ticket_id);
        $this->integer((int)$ticket->fields['itilcategories_id'])
             ->isEqualto($second_cat_id);
    }

    protected function computePriorityProvider()
    {
        $cases = [
           [
              'input'    => [
                 'urgency'   => 2,
                 'impact'    => 2
              ],
              'urgency'  => 2,
              'impact'   => 2,
              'priority' => 2
           ], [
              'input'    => [
                 'urgency'   => 5
              ],
              'urgency'  => 5,
              'impact'   => 3,
              'priority' => 4
           ], [
              'input'    => [
                 'impact'   => 5
              ],
              'urgency'  => 3,
              'impact'   => 5,
              'priority' => 4
           ], [
              'input'    => [
                 'urgency'   => 5,
                 'impact'    => 5
              ],
              'urgency'  => 5,
              'impact'   => 5,
              'priority' => 5
           ], [
              'input'    => [
                 'urgency'   => 5,
                 'impact'    => 1
              ],
              'urgency'  => 5,
              'impact'   => 1,
              'priority' => 2
           ]
        ];
        // HTML selects submit numeric strings. They must calculate the same
        // domain values without relying on string coercion of native inputs.
        foreach ($cases as $case) {
            $case['input'] = array_map(static fn (int $value): string => (string)$value, $case['input']);
            $cases[] = $case;
        }
        return $cases;
    }

    /**
     * @dataProvider computePriorityProvider
     */
    public function testComputePriority(array $input, int $urgency, int $impact, int $priority)
    {
        global $DB;

        // Atoum runs every provider dataset inside one DbTestCase transaction.
        // Isolate actual writes so missing-field cases retain their original data.
        $connection = $DB->getDoctrineConnection();
        $level = $connection->getTransactionNestingLevel();
        $connection->beginTransaction();
        try {
            $this->login();
            $ticket = getItemByTypeName('Ticket', '_ticket01');
            $input['id'] = $ticket->fields['id'];
            $this->boolean($ticket->can($input['id'], UPDATE))->isTrue();
            $expected = ['urgency' => $urgency, 'impact' => $impact, 'priority' => $priority];
            $before = array_intersect_key($ticket->fields, $expected);
            $result = $ticket->prepareInputForUpdate($input);
            $this->array($result);
            foreach ($expected as $field => $value) {
                // Preparation retains supplied text. Missing values come from
                // integer fields; the frozen default matrix returns integers.
                if (isset($input[$field]) && is_string($input[$field])) {
                    $this->string($result[$field])->isIdenticalTo((string)$value);
                } else {
                    $this->integer($result[$field])->isIdenticalTo($value);
                }
            }
            $this->boolean($ticket->update($input))->isTrue();
            $reloaded = new LegacyTicket();
            $this->boolean($reloaded->getFromDB($input['id']))->isTrue();
            $native = Orm::create($DB)->find(TicketEntity::class, $input['id']);
            $this->object($native)->isInstanceOf(TicketEntity::class);
            foreach ($expected as $field => $value) {
                $this->integer($reloaded->fields[$field])->isIdenticalTo($value);
                $this->integer($native->$field)->isIdenticalTo($value);
            }
        } finally {
            $connection->rollBack();
        }
        $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($level);
        $restored = new LegacyTicket();
        $this->boolean($restored->getFromDB($input['id']))->isTrue();
        $this->array(array_intersect_key($restored->fields, $expected))->isIdenticalTo($before);
    }

    public function testGetDefaultValues()
    {
        $input = \Ticket::getDefaultValues();

        $this->integer($input['_users_id_requester'])->isEqualTo(0);
        $this->array($input['_users_id_requester_notif']['use_notification'])->contains('1');
        $this->array($input['_users_id_requester_notif']['alternative_email'])->contains('');

        $this->integer($input['_groups_id_requester'])->isEqualTo(0);

        $this->integer($input['_users_id_assign'])->isEqualTo(0);
        $this->array($input['_users_id_assign_notif']['use_notification'])->contains('1');
        $this->array($input['_users_id_assign_notif']['alternative_email'])->contains('');

        $this->integer($input['_groups_id_assign'])->isEqualTo(0);

        $this->integer($input['_users_id_observer'])->isEqualTo(0);
        $this->array($input['_users_id_observer_notif']['use_notification'])->contains('1');
        $this->array($input['_users_id_observer_notif']['alternative_email'])->contains('');

        $this->integer($input['_suppliers_id_assign'])->isEqualTo(0);
        $this->array($input['_suppliers_id_assign_notif']['use_notification'])->contains('1');
        $this->array($input['_suppliers_id_assign_notif']['alternative_email'])->contains('');

        $this->string($input['name'])->isEqualTo('');
        $this->string($input['content'])->isEqualTo('');
        $this->integer((int) $input['itilcategories_id'])->isEqualTo(0);
        $this->integer((int) $input['urgency'])->isEqualTo(3);
        $this->integer((int) $input['impact'])->isEqualTo(3);
        $this->integer((int) $input['priority'])->isEqualTo(3);
        $this->integer((int) $input['requesttypes_id'])->isEqualTo(1);
        $this->integer((int) $input['actiontime'])->isEqualTo(0);
        $this->integer((int) $input['entities_id'])->isEqualTo(0);
        $this->integer((int) $input['status'])->isEqualTo(\Ticket::INCOMING);
        $this->array($input['followup'])->size->isEqualTo(0);
        $this->string($input['itemtype'])->isEqualTo('');
        $this->integer((int) $input['items_id'])->isEqualTo(0);
        $this->array($input['plan'])->size->isEqualTo(0);
        $this->integer((int) $input['global_validation'])->isEqualTo(\CommonITILValidation::NONE);

        $this->string($input['time_to_resolve'])->isEqualTo('NULL');
        $this->string($input['time_to_own'])->isEqualTo('NULL');
        $this->integer((int) $input['slas_id_tto'])->isEqualTo(0);
        $this->integer((int) $input['slas_id_ttr'])->isEqualTo(0);

        $this->string($input['internal_time_to_resolve'])->isEqualTo('NULL');
        $this->string($input['internal_time_to_own'])->isEqualTo('NULL');
        $this->integer((int) $input['olas_id_tto'])->isEqualTo(0);
        $this->integer((int) $input['olas_id_ttr'])->isEqualTo(0);

        $this->integer((int) $input['_add_validation'])->isEqualTo(0);

        $this->array($input['users_id_validate'])->size->isEqualTo(0);
        $this->integer((int) $input['type'])->isEqualTo(\Ticket::INCIDENT_TYPE);
        $this->array($input['_documents_id'])->size->isEqualTo(0);
        $this->array($input['_tasktemplates_id'])->size->isEqualTo(0);
        $this->array($input['_filename'])->size->isEqualTo(0);
        $this->array($input['_tag_filename'])->size->isEqualTo(0);
    }

    /**
     * @see self::testCanTakeIntoAccount()
     */
    protected function canTakeIntoAccountProvider()
    {
        return [
           [
              'input'    => [
                 '_users_id_requester' => ['3'], // "post-only"
              ],
              'user'     => [
                 'login'    => 'post-only',
                 'password' => 'postonly',
              ],
              'expected' => false, // is requester, so cannot take into account
           ],
           [
              'input'    => [
                 '_users_id_requester' => ['3', '4'], // "post-only" and "tech"
              ],
              'user'     => [
                 'login'    => 'tech',
                 'password' => 'tech',
              ],
              'expected' => false, // is requester, so cannot take into account
           ],
           [
              'input'    => [
                 '_users_id_requester' => ['3'], // "post-only"
              ],
              'user'     => [
                 'login'    => 'tech',
                 'password' => 'tech',
              ],
              'expected' => true, // has enough rights so can take into account
           ],
           [
              'input'    => [
                 '_users_id_requester' => ['3'], // "post-only"
              ],
              'user'     => [
                 'login'    => 'tech',
                 'password' => 'tech',
                 'rights'   => [
                    'task' => \READ,
                    'followup' => \READ,
                 ],
              ],
              'expected' => false, // has not enough rights so cannot take into account
           ],
           [
              'input'    => [
                 '_users_id_requester' => ['3'], // "post-only"
              ],
              'user'     => [
                 'login'    => 'tech',
                 'password' => 'tech',
                 'rights'   => [
                    'task' => \READ + \CommonITILTask::ADDALLITEM,
                    'followup' => \READ,
                 ],
              ],
              'expected' => true, // has enough rights so can take into account
           ],
           [
              'input'    => [
                 '_users_id_requester' => ['3'], // "post-only"
              ],
              'user'     => [
                 'login'    => 'tech',
                 'password' => 'tech',
                 'rights'   => [
                    'task' => \READ,
                    'followup' => \READ + \ITILFollowup::ADDALLTICKET,
                 ],
              ],
              'expected' => true, // has enough rights so can take into account
           ],
           [
              'input'    => [
                 '_users_id_requester' => ['3'], // "post-only"
              ],
              'user'     => [
                 'login'    => 'tech',
                 'password' => 'tech',
                 'rights'   => [
                    'task' => \READ,
                    'followup' => \READ + \ITILFollowup::ADDMYTICKET,
                 ],
              ],
              'expected' => true, // has enough rights so can take into account
           ],
           [
              'input'    => [
                 '_users_id_requester' => ['3'], // "post-only"
              ],
              'user'     => [
                 'login'    => 'tech',
                 'password' => 'tech',
                 'rights'   => [
                    'task' => \READ,
                    'followup' => \READ + \ITILFollowup::ADDGROUPTICKET,
                 ],
              ],
              'expected' => true, // has enough rights so can take into account
           ],
           [
              'input'    => [
                 '_do_not_compute_takeintoaccount' => 1,
                 '_users_id_requester'             => ['4'], // "tech"
                 '_users_id_assign'                => ['4'], // "tech"
              ],
              'user'     => [
                 'login'    => 'tech',
                 'password' => 'tech',
              ],
              // is requester but also assigned, so can take into account
              // this is only possible if "_do_not_compute_takeintoaccount" flag is set by business rules
              'expected' => true,
           ],
        ];
    }

    /**
     * Tests ability to take a ticket into account.
     *
     * @param array   $input    Input used to create the ticket
     * @param array   $user     Array containing 'login' and 'password' fields of tested user,
     *                          and a 'rights' array if rights have to be forced
     * @param boolean $expected Expected result of "Ticket::canTakeIntoAccount()" method
     *
     * @dataProvider canTakeIntoAccountProvider
     */
    public function testCanTakeIntoAccount(array $input, array $user, $expected)
    {
        // Create a ticket
        $this->login();
        $_SESSION['glpiset_default_tech'] = false;
        $ticket = new \Ticket();
        $ticketId = $ticket->add(
            $input + [
              'name'    => '',
              'content' => 'A ticket to check canTakeIntoAccount() results',
              'status'  => CommonITILObject::ASSIGNED
         ]
        );
        $this->integer((int)$ticketId)->isGreaterThan(0);
        // Reload ticket to get all default fields values
        $this->boolean($ticket->getFromDB($ticketId))->isTrue();
        // Validate that "takeintoaccount_delay_stat" is not automatically defined
        $this->integer((int)$ticket->fields['takeintoaccount_delay_stat'])->isEqualTo(0);
        // Login with tested user
        $this->login($user['login'], $user['password']);
        // Apply specific rights if defined
        if (array_key_exists('rights', $user)) {
            foreach ($user['rights'] as $rightname => $rightvalue) {
                $_SESSION['glpiactiveprofile'][$rightname] = $rightvalue;
            }
        }
        // Verify result
        $this->boolean($ticket->canTakeIntoAccount())->isEqualTo($expected);

        // Check that computation of "takeintoaccount_delay_stat" can be prevented
        sleep(1); // be sure to wait at least one second before updating
        $this->boolean(
            $ticket->update(
                [
                 'id'                              => $ticketId,
                 'content'                         => 'Updated ticket 1',
                 '_do_not_compute_takeintoaccount' => 1
            ]
            )
        )->isTrue();
        $this->integer((int)$ticket->fields['takeintoaccount_delay_stat'])->isEqualTo(0);

        // Check that computation of "takeintoaccount_delay_stat" is done if user can take into account
        $this->boolean(
            $ticket->update(
                [
                 'id'      => $ticketId,
                 'content' => 'Updated ticket 2',
            ]
            )
        )->isTrue();
        if (!$expected) {
            $this->integer((int)$ticket->fields['takeintoaccount_delay_stat'])->isEqualTo(0);
        } else {
            $this->integer((int)$ticket->fields['takeintoaccount_delay_stat'])->isGreaterThan(0);
        }
    }

    /**
     * Tests taken into account state.
     */
    public function testIsAlreadyTakenIntoAccount()
    {

        // Create a ticket
        $this->login();
        $_SESSION['glpiset_default_tech'] = false;
        $ticket = new \Ticket();
        $ticket_id = $ticket->add(
            [
              'name'    => '',
              'content' => 'A ticket to check isAlreadyTakenIntoAccount() results',
         ]
        );
        $this->integer((int)$ticket_id)->isGreaterThan(0);

        // Reload ticket to get all default fields values
        $this->boolean($ticket->getFromDB($ticket_id))->isTrue();

        // Empty ticket is not taken into account
        $this->boolean($ticket->isAlreadyTakenIntoAccount())->isFalse();

        // Take into account
        $this->login('tech', 'tech');
        $ticket_user = new \Ticket_User();
        $ticket_user_id = $ticket_user->add(
            [
              'tickets_id'       => $ticket_id,
              'users_id'         => \Session::getLoginUserID(),
              'use_notification' => 1,
              'type'             => \CommonITILActor::ASSIGN
         ]
        );
        $this->integer((int)$ticket_user_id)->isGreaterThan(0);

        // Assign to tech made ticket taken into account
        $this->boolean($ticket->getFromDB($ticket_id))->isTrue();
        $this->boolean($ticket->isAlreadyTakenIntoAccount())->isTrue();
    }

    public function testCronCloseTicket()
    {
        global $DB;
        $this->login();
        // set default calendar and autoclose delay in root entity
        $entity = new \Entity();
        $this->boolean($entity->update([
           'id'              => 0,
           'calendars_id'    => 1,
           'autoclose_delay' => 5,
        ]))->isTrue();

        // create some solved tickets at various solvedate
        $ticket = new \Ticket();
        $tickets_id_1 = $ticket->add([
           'name'        => "test autoclose 1",
           'content'     => "test autoclose 1",
           'entities_id' => 0,
           'status'      => \CommonITILObject::SOLVED,
        ]);
        $this->integer((int)$tickets_id_1)->isGreaterThan(0);
        $DB->update('glpi_tickets', [
           'solvedate' => date('Y-m-d 10:00:00', time() - 10 * DAY_TIMESTAMP),
        ], [
           'id' => $tickets_id_1,
        ]);
        $tickets_id_2 = $ticket->add([
           'name'        => "test autoclose 1",
           'content'     => "test autoclose 1",
           'entities_id' => 0,
           'status'      => \CommonITILObject::SOLVED,
        ]);
        $DB->update('glpi_tickets', [
           'solvedate' => date('Y-m-d 10:00:00', time()),
        ], [
           'id' => $tickets_id_2,
        ]);
        $this->integer((int)$tickets_id_2)->isGreaterThan(0);

        // launch Cron for closing tickets
        $mode = - \CronTask::MODE_EXTERNAL; // force
        \CronTask::launch($mode, 5, 'closeticket');

        // check ticket status
        $this->boolean($ticket->getFromDB($tickets_id_1))->isTrue();
        $this->integer((int)$ticket->fields['status'])->isEqualTo(\CommonITILObject::CLOSED);
        $this->boolean($ticket->getFromDB($tickets_id_2))->isTrue();
        $this->integer((int)$ticket->fields['status'])->isEqualTo(\CommonITILObject::SOLVED);
    }

    /**
     * @see self::testTakeIntoAccountDelayComputationOnCreate()
     * @see self::testTakeIntoAccountDelayComputationOnUpdate()
     */
    protected function takeIntoAccountDelayComputationProvider()
    {
        $this->login();
        $group = new \Group();
        $group_id = $group->add(['name' => 'Test group']);
        $supplier_id = getItemByTypeName('Supplier', '_suplier01_name', true);
        $this->integer((int)$group_id)->isGreaterThan(0);

        $group_user = new \Group_User();
        $this->integer(
            (int)$group_user->add([
              'groups_id' => $group_id,
              'users_id'  => '4', // "tech"
         ])
        )->isGreaterThan(0);

        $test_cases = [
           [
              'input'    => [
                 'content' => 'test',
              ],
              'computed' => false, // not computed as tech is requester
           ],
           [
              'input'    => [
                 '_users_id_assign' => '4', // "tech"
              ],
              'computed' => true, // computed on asignment
           ],
           [
              'input'    => [
                 '_users_id_observer' => '4', // "tech"
              ],
              'computed' => false, // not computed as new actor is not assigned
           ],
           /* Triggers PHP error "Uncaught Error: [] operator not supported for strings in /var/www/glpi/inc/ticket.class.php:1162"
           [
              'input'    => [
                 '_users_id_requester' => '3', // "post-only"
              ],
              'computed' => false, // not computed as new actor is not assigned
           ],
           */
           [
              'input'    => [
                 '_additional_assigns' => [
                    ['users_id' => '4'], // "tech"
                 ],
              ],
              'computed' => true, // computed on asignment
           ],
           [
              'input'    => [
                 '_additional_observers' => [
                    ['users_id' => '4'], // "tech"
                 ],
              ],
              'computed' => false, // not computed as new actor is not assigned
           ],
           [
              'input'    => [
                 '_additional_requesters' => [
                    ['users_id' => '2'], // "post-only"
                 ],
              ],
              'computed' => false, // not computed as new actor is not assigned
           ],
           [
              'input'    => [
                 '_groups_id_assign' => $group_id,
              ],
              'computed' => true, // computed on asignment
           ],
           [
              'input'    => [
                 '_groups_id_observer' => $group_id,
              ],
              'computed' => false, // not computed as new actor is not assigned
           ],
           [
              'input'    => [
                 '_groups_id_requester' => $group_id,
              ],
              'computed' => false, // not computed as new actor is not assigned
           ],
           [
              'input'    => [
                 '_additional_groups_assigns' => [$group_id],
              ],
              'computed' => true, // computed on asignment
           ],
           [
              'input'    => [
                 '_additional_groups_observers' => [$group_id],
              ],
              'computed' => false, // not computed as new actor is not assigned
           ],
           [
              'input'    => [
                 '_additional_groups_requesters' => [$group_id],
              ],
              'computed' => false, // not computed as new actor is not assigned
           ],
           /* Not computing delay, do not know why
           [
              'input'    => [
                 '_suppliers_id_assign' => $supplier_id,
              ],
              'computed' => true, // computed on asignment
           ],
           */
           [
              'input'    => [
                 '_additional_suppliers_assigns' => [
                    ['suppliers_id' => $supplier_id],
                 ],
              ],
              'computed' => true, // computed on asignment
           ],
        ];

        // for all test cases that expect a computation
        // add a test case with '_do_not_compute_takeintoaccount' flag to check that computation is prevented
        foreach ($test_cases as $test_case) {
            $test_case['input']['_do_not_compute_takeintoaccount'] = 1;
            $test_case['computed'] = false;
            $test_cases[] = $test_case;
        }

        return $test_cases;
    }

    /**
     * Tests that "takeintoaccount_delay_stat" is computed (or not) as expected on ticket creation.
     *
     * @param array   $input    Input used to create the ticket
     * @param boolean $computed Expected computation state
     *
     * @dataProvider takeIntoAccountDelayComputationProvider
     */
    public function testTakeIntoAccountDelayComputationOnCreate(array $input, $computed)
    {

        // Create a ticket
        $this->login('tech', 'tech'); // Login with tech to be sure to be the requester
        $_SESSION['glpiset_default_tech'] = false;
        $ticket = new \Ticket();
        $ticketId = $ticket->add(
            $input + [
              'name'    => '',
              'content' => 'A ticket to check takeintoaccount_delay_stat computation state',
         ]
        );
        $this->integer((int)$ticketId)->isGreaterThan(0);

        // Reload ticket to get all default fields values
        $this->boolean($ticket->getFromDB($ticketId))->isTrue();

        if (!$computed) {
            $this->integer((int)$ticket->fields['takeintoaccount_delay_stat'])->isEqualTo(0);
        } else {
            $this->integer((int)$ticket->fields['takeintoaccount_delay_stat'])->isGreaterThan(0);
        }
    }

    /**
     * Tests that "takeintoaccount_delay_stat" is computed (or not) as expected on ticket update.
     *
     * @param array   $input     Input used to update the ticket
     * @param boolean $computed  Expected computation state
     *
     * @dataProvider takeIntoAccountDelayComputationProvider
     */
    public function testTakeIntoAccountDelayComputationOnUpdate(array $input, $computed)
    {

        // Create a ticket
        $this->login('tech', 'tech'); // Login with tech to be sure to be the requester
        $_SESSION['glpiset_default_tech'] = false;
        $ticket = new \Ticket();
        $ticketId = $ticket->add(
            [
              'name'    => '',
              'content' => 'A ticket to check takeintoaccount_delay_stat computation state',
         ]
        );
        $this->integer((int)$ticketId)->isGreaterThan(0);

        // Reload ticket to get all default fields values
        $this->boolean($ticket->getFromDB($ticketId))->isTrue();

        // Validate that "takeintoaccount_delay_stat" is not automatically defined
        $this->integer((int)$ticket->fields['takeintoaccount_delay_stat'])->isEqualTo(0);

        // Login with tech to be sure to be have rights to take into account
        $this->login('tech', 'tech');

        sleep(1); // be sure to wait at least one second before updating
        $this->boolean(
            $ticket->update(
                $input + [
                 'id' => $ticketId,
            ]
            )
        )->isTrue();

        // Reload ticket to get fresh values that can be defined by a tier object
        $this->boolean($ticket->getFromDB($ticketId))->isTrue();

        if (!$computed) {
            $this->integer((int)$ticket->fields['takeintoaccount_delay_stat'])->isEqualTo(0);
        } else {
            $this->integer((int)$ticket->fields['takeintoaccount_delay_stat'])->isGreaterThan(0);
        }
    }

    /**
     * @see self::testStatusComputationOnCreate()
     */
    protected function statusComputationOnCreateProvider()
    {

        $group = new \Group();
        $group_id = $group->add(['name' => 'Test group']);
        $supplier_id = getItemByTypeName('Supplier', '_suplier01_name', true);
        $this->integer((int)$group_id)->isGreaterThan(0);

        return [
           [
              'input'    => [
                 '_users_id_assign' => ['4'], // "tech"
                 'status' => \CommonITILObject::INCOMING,
              ],
              'expected' => \CommonITILObject::ASSIGNED, // incoming changed to assign as actors are set
           ],
           [
              'input'    => [
                 '_groups_id_assign' => $group_id,
                 'status' => \CommonITILObject::INCOMING,
              ],
              'expected' => \CommonITILObject::ASSIGNED, // incoming changed to assign as actors are set
           ],
           [
              'input'    => [
                 '_suppliers_id_assign' => $supplier_id,
                 'status' => \CommonITILObject::INCOMING,
              ],
              'expected' => \CommonITILObject::ASSIGNED, // incoming changed to assign as actors are set
           ],
           [
              'input'    => [
                 '_users_id_assign' => ['4'], // "tech"
                 'status' => \CommonITILObject::INCOMING,
                 '_do_not_compute_status' => '1',
              ],
              'expected' => \CommonITILObject::INCOMING, // flag prevent status change
           ],
           [
              'input'    => [
                 '_groups_id_assign' => $group_id,
                 'status' => \CommonITILObject::INCOMING,
                 '_do_not_compute_status' => '1',
              ],
              'expected' => \CommonITILObject::INCOMING, // flag prevent status change
           ],
           [
              'input'    => [
                 '_suppliers_id_assign' => $supplier_id,
                 'status' => \CommonITILObject::INCOMING,
                 '_do_not_compute_status' => '1',
              ],
              'expected' => \CommonITILObject::INCOMING, // flag prevent status change
           ],
           [
              'input'    => [
                 '_users_id_assign' => ['4'], // "tech"
                 'status' => \CommonITILObject::WAITING,
              ],
              'expected' => \CommonITILObject::WAITING, // status not changed as not "new"
           ],
           [
              'input'    => [
                 '_groups_id_assign' => $group_id,
                 'status' => \CommonITILObject::WAITING,
              ],
              'expected' => \CommonITILObject::WAITING, // status not changed as not "new"
           ],
           [
              'input'    => [
                 '_suppliers_id_assign' => $supplier_id,
                 'status' => \CommonITILObject::WAITING,
              ],
              'expected' => \CommonITILObject::WAITING, // status not changed as not "new"
           ],
        ];
    }

    /**
     * Check computed status on ticket creation..
     *
     * @param array   $input     Input used to create the ticket
     * @param boolean $expected  Expected status
     *
     * @dataProvider statusComputationOnCreateProvider
     */
    public function testStatusComputationOnCreate(array $input, $expected)
    {

        // Create a ticket
        $this->login();
        $_SESSION['glpiset_default_tech'] = false;
        $ticket = new \Ticket();
        $ticketId = $this->integer(
            (int)$ticket->add([
              'name'    => '',
              'content' => 'A ticket to check status computation',
         ] + $input)
        )->isGreaterThan(0);

        // Reload ticket to get computed fields values
        $this->boolean($ticket->getFromDB($ticketId))->isTrue();

        // Check status
        $this->integer((int)$ticket->fields['status'])->isEqualTo($expected);
    }

    public function testLocationAssignment()
    {
        $this->login();

        $rule = new \Rule();
        $rule->getFromDBByCrit([
           'sub_type' => 'RuleTicket',
           'name' => 'Ticket location from user',
        ]);
        $location = new \Location();
        $location->getFromDBByCrit([
           'name' => '_location01'
        ]);
        $user = new \User();
        $user->add([
           'name' => $this->getUniqueString(),
           'locations_id' => $location->getID(),
        ]);

        // test ad ticket with single requester
        $ticket = new \Ticket();
        $rule->update([
           'id' => $rule->getID(),
           'is_active' => '1'
        ]);
        $ticket->add([
            '_users_id_requester' => $user->getID(),
            'name' => 'test location assignment',
            'content' => 'test location assignment',
            'entities_id' => 0,
        ]);
        $rule->update([
           'id' => $rule->getID(),
           'is_active' => '0'
        ]);
        $ticket->getFromDB($ticket->getID());
        $this->integer((int) $ticket->fields['locations_id'])->isEqualTo($location->getID());

        // test add ticket with multiple requesters
        $ticket = new \Ticket();
        $rule->update([
           'id' => $rule->getID(),
           'is_active' => '1'
        ]);
        $ticket->add([
            '_users_id_requester' => [$user->getID(), 2],
            'name' => 'test location assignment',
            'content' => 'test location assignment',
            'entities_id' => 0,
        ]);
        $rule->update([
           'id' => $rule->getID(),
           'is_active' => '0'
        ]);
        $ticket->getFromDB($ticket->getID());
        $this->integer((int) $ticket->fields['locations_id'])->isEqualTo($location->getID());

        // test add ticket with multiple requesters
        $ticket = new \Ticket();
        $rule->update([
           'id' => $rule->getID(),
           'is_active' => '1'
        ]);
        $ticket->add([
            '_users_id_requester' => [2, $user->getID()],
            'name' => 'test location assignment',
            'content' => 'test location assignment',
            'entities_id' => 0,
        ]);
        $rule->update([
           'id' => $rule->getID(),
           'is_active' => '0'
        ]);
        $ticket->getFromDB($ticket->getID());
        $this->integer((int) $ticket->fields['locations_id'])->isEqualTo(0);
    }

    public function testCronPurgeTicket()
    {

        $this->login(); // must be logged as Document_Item uses Session::getLoginUserID()

        global $DB;
        // set default calendar and autoclose delay in root entity
        $entity = new \Entity();
        $this->boolean($entity->update([
           'id'              => 0,
           'calendars_id'    => 1,
           'autopurge_delay' => 5,
        ]))->isTrue();

        $doc = new \Document();
        $did = (int)$doc->add([
           'name'   => 'test doc'
        ]);
        $this->integer($did)->isGreaterThan(0);

        // create some closed tickets at various solvedate
        $ticket = new \Ticket();
        $tickets_id_1 = $ticket->add([
           'name'            => "test autopurge 1",
           'content'         => "test autopurge 1",
           'entities_id'     => 0,
           'status'          => \CommonITILObject::CLOSED,
           '_documents_id'   => [$did]
        ]);
        $this->integer((int)$tickets_id_1)->isGreaterThan(0);
        $this->boolean(
            $DB->update('glpi_tickets', [
              'closedate' => date('Y-m-d 10:00:00', time() - 10 * DAY_TIMESTAMP),
         ], [
              'id' => $tickets_id_1,
         ])
        )->isTrue();
        $this->boolean($ticket->getFromDB($tickets_id_1))->isTrue();

        $docitem = new \Document_Item();
        if (!$docitem->getFromDBByCrit(['itemtype' => 'Ticket', 'items_id' => $tickets_id_1])) {
            $this->integer((int)$docitem->add([
               'itemtype'     => 'Ticket',
               'items_id'     => $tickets_id_1,
               'documents_id' => $did
            ]))->isGreaterThan(0);
        }
        $this->boolean($docitem->getFromDBByCrit(['itemtype' => 'Ticket', 'items_id' => $tickets_id_1]))->isTrue();

        $tickets_id_2 = $ticket->add([
           'name'        => "test autopurge 2",
           'content'     => "test autopurge 2",
           'entities_id' => 0,
           'status'      => \CommonITILObject::CLOSED,
        ]);
        $this->integer((int)$tickets_id_2)->isGreaterThan(0);
        $this->boolean(
            $DB->update('glpi_tickets', [
              'closedate' => date('Y-m-d 10:00:00', time()),
         ], [
              'id' => $tickets_id_2,
         ])
        );

        // launch Cron for closing tickets
        $mode = - \CronTask::MODE_EXTERNAL; // force
        \CronTask::launch($mode, 5, 'purgeticket');

        // check ticket presence
        // first ticket should have been removed
        $this->boolean($ticket->getFromDB($tickets_id_1))->isFalse();
        //also ensure linked document has been dropped
        $this->boolean($docitem->getFromDBByCrit(['itemtype' => 'Ticket', 'items_id' => $tickets_id_1]))->isFalse();
        $this->boolean($doc->getFromDB($did))->isTrue(); //document itself remains
        //second ticket is still present
        $this->boolean($ticket->getFromDB($tickets_id_2))->isTrue();
        $this->integer((int)$ticket->fields['status'])->isEqualTo(\CommonITILObject::CLOSED);
    }

    public function testMerge()
    {
        $this->login();
        $_SESSION['glpiactiveprofile']['interface'] = '';
        $this->setEntity('Root entity', true);

        $ticket = new \Ticket();
        $ticket1 = $ticket->add([
           'name'        => "test merge 1",
           'content'     => "test merge 1",
           'entities_id' => 0,
           'status'      => \CommonITILObject::INCOMING,
        ]);
        $ticket2 = $ticket->add([
           'name'        => "test merge 2",
           'content'     => "test merge 2",
           'entities_id' => 0,
           'status'      => \CommonITILObject::INCOMING,
        ]);
        $ticket3 = $ticket->add([
           'name'        => "test merge 3",
           'content'     => "test merge 3",
           'entities_id' => 0,
           'status'      => \CommonITILObject::INCOMING,
        ]);

        $task = new \TicketTask();
        $fup = new \ITILFollowup();
        $task->add([
           'tickets_id'   => $ticket2,
           'content'      => 'ticket 2 task 1'
        ]);
        $task->add([
           'tickets_id'   => $ticket3,
           'content'      => 'ticket 3 task 1'
        ]);
        $fup->add([
           'itemtype'  => 'Ticket',
           'items_id'  => $ticket2,
           'content'   => 'ticket 2 fup 1'
        ]);
        $fup->add([
           'itemtype'  => 'Ticket',
           'items_id'  => $ticket3,
           'content'   => 'ticket 3 fup 1'
        ]);

        $document = new \Document();
        $documents_id = $document->add([
           'name'     => 'basic document in both',
           'filename' => 'doc.xls',
           'users_id' => '2', // user "glpi"
        ]);
        $documents_id2 = $document->add([
           'name'     => 'basic document in target',
           'filename' => 'doc.xls',
           'users_id' => '2', // user "glpi"
        ]);
        $documents_id3 = $document->add([
           'name'     => 'basic document in sources',
           'filename' => 'doc.xls',
           'users_id' => '2', // user "glpi"
        ]);

        $document_item = new \Document_Item();
        // Add document to two tickets to test merging duplicates
        $document_item->add([
           'itemtype'     => 'Ticket',
           'items_id'     => $ticket2,
           'documents_id' => $documents_id,
           'entities_id'  => '0',
           'is_recursive' => 0
        ]);
        $document_item->add([
           'itemtype'     => 'Ticket',
           'items_id'     => $ticket1,
           'documents_id' => $documents_id,
           'entities_id'  => '0',
           'is_recursive' => 0
        ]);
        $document_item->add([
           'itemtype'     => 'Ticket',
           'items_id'     => $ticket1,
           'documents_id' => $documents_id2,
           'entities_id'  => '0',
           'is_recursive' => 0
        ]);
        $document_item->add([
           'itemtype'     => 'Ticket',
           'items_id'     => $ticket2,
           'documents_id' => $documents_id3,
           'entities_id'  => '0',
           'is_recursive' => 0
        ]);
        $document_item->add([
           'itemtype'     => 'Ticket',
           'items_id'     => $ticket3,
           'documents_id' => $documents_id3,
           'entities_id'  => '0',
           'is_recursive' => 0
        ]);

        $ticket_user = new \Ticket_User();
        $ticket_user->add([
           'tickets_id'         => $ticket1,
           'type'               => \Ticket_User::REQUESTER,
           'users_id'           => 2
        ]);
        $ticket_user->add([ // Duplicate with #1
           'tickets_id'         => $ticket3,
           'type'               => \Ticket_User::REQUESTER,
           'users_id'           => 2
        ]);
        $ticket_user->add([
           'tickets_id'         => $ticket1,
           'users_id'           => 0,
           'type'               => \Ticket_User::REQUESTER,
           'alternative_email'  => 'test@glpi.com'
        ]);
        $ticket_user->add([ // Duplicate with #3
           'tickets_id'         => $ticket2,
           'users_id'           => 0,
           'type'               => \Ticket_User::REQUESTER,
           'alternative_email'  => 'test@glpi.com'
        ]);
        $ticket_user->add([ // Duplicate with #1
           'tickets_id'         => $ticket2,
           'users_id'           => 2,
           'type'               => \Ticket_User::REQUESTER,
           'alternative_email'  => 'test@glpi.com'
        ]);
        $ticket_user->add([
           'tickets_id'         => $ticket3,
           'users_id'           => 2,
           'type'               => \Ticket_User::ASSIGN,
           'alternative_email'  => 'test@glpi.com'
        ]);

        $group = new Group();
        $groupId = $group->add(['name' => 'Ticket merge group']);
        $this->integer((int)$groupId)->isGreaterThan(0);
        $ticket_group = new \Group_Ticket();
        $ticket_group->add([
           'tickets_id'         => $ticket1,
           'groups_id'          => $groupId,
           'type'               => \Group_Ticket::REQUESTER
        ]);
        $ticket_group->add([ // Duplicate with #1
           'tickets_id'         => $ticket3,
           'groups_id'          => $groupId,
           'type'               => \Group_Ticket::REQUESTER
        ]);
        $ticket_group->add([
           'tickets_id'         => $ticket3,
           'groups_id'          => $groupId,
           'type'               => \Group_Ticket::ASSIGN
        ]);

        $supplierId = (new Supplier())->add(['name' => 'Ticket merge supplier', 'entities_id' => 0]);
        $this->integer((int)$supplierId)->isGreaterThan(0);
        $ticket_supplier = new \Supplier_Ticket();
        $ticket_supplier->add([
           'tickets_id'         => $ticket1,
           'type'               => \Supplier_Ticket::REQUESTER,
           'suppliers_id'       => $supplierId
        ]);
        $ticket_supplier->add([ // Duplicate with #1
           'tickets_id'         => $ticket3,
           'type'               => \Supplier_Ticket::REQUESTER,
           'suppliers_id'       => $supplierId
        ]);
        $ticket_supplier->add([
           'tickets_id'         => $ticket1,
           'suppliers_id'       => 0,
           'type'               => \Supplier_Ticket::REQUESTER,
           'alternative_email'  => 'test@glpi.com'
        ]);
        $ticket_supplier->add([ // Duplicate with #3
           'tickets_id'         => $ticket2,
           'suppliers_id'       => 0,
           'type'               => \Supplier_Ticket::REQUESTER,
           'alternative_email'  => 'test@glpi.com'
        ]);
        $ticket_supplier->add([ // Duplicate with #1
           'tickets_id'         => $ticket2,
           'suppliers_id'       => $supplierId,
           'type'               => \Supplier_Ticket::REQUESTER,
           'alternative_email'  => 'test@glpi.com'
        ]);
        $ticket_supplier->add([
           'tickets_id'         => $ticket3,
           'suppliers_id'       => $supplierId,
           'type'               => \Supplier_Ticket::ASSIGN,
           'alternative_email'  => 'test@glpi.com'
        ]);

        $status = [];
        $mergeparams = [
           'linktypes' => [
              'ITILFollowup',
              'TicketTask',
              'Document'
           ],
           'link_type'  => \Ticket_Ticket::SON_OF
        ];

        // Email-only requesters and groups without members have no user identity.
        // Retain their legacy empty-key bucket separately from real user roles.
        $this->boolean($ticket->getFromDB($ticket1))->isTrue();
        $actors = $ticket->getITILActors();
        $this->array($actors[2])->contains(Ticket_User::REQUESTER);
        $this->array($actors[''])->contains(Ticket_User::REQUESTER);
        $this->boolean(array_key_exists(0, $actors))->isFalse();

        \Ticket::merge($ticket1, [$ticket2, $ticket3], $status, $mergeparams);

        $status_counts = array_count_values($status);
        $failure_count = 0;
        if (array_key_exists(1, $status_counts)) {
            $failure_count += $status_counts[1];
        }
        if (array_key_exists(2, $status_counts)) {
            $failure_count += $status_counts[2];
        }

        $this->integer((int)$failure_count)->isEqualTo(0);

        $task_count = count($task->find(['tickets_id' => $ticket1]));
        $fup_count = count($fup->find([
           'itemtype' => 'Ticket',
           'items_id' => $ticket1]));
        $doc_count = count($document_item->find([
           'itemtype' => 'Ticket',
           'items_id' => $ticket1]));
        $user_count = count($ticket_user->find([
           'tickets_id' => $ticket1]));
        $group_count = count($ticket_group->find([
           'tickets_id' => $ticket1]));
        $supplier_count = count($ticket_supplier->find([
           'tickets_id' => $ticket1]));

        // Target ticket should have all tasks
        $this->integer((int)$task_count)->isEqualTo(2);
        // Target ticket should have all followups + 1 for each source ticket description
        $this->integer((int)$fup_count)->isEqualTo(4);
        // Target ticket should have the original document, one instance of the duplicate, and the new document from one of the source tickets
        $this->integer((int)$doc_count)->isEqualTo(3);
        // Target ticket should have all users not marked as duplicates above + original requester (ID: 6)
        $this->integer((int)$user_count)->isEqualTo(4);
        // Target ticket should have all groups not marked as duplicates above
        $this->integer((int)$group_count)->isEqualTo(2);
        // Target ticket should have all suppliers not marked as duplicates above
        $this->integer((int)$supplier_count)->isEqualTo(3);

        $this->boolean($ticket->getFromDB($ticket1))->isTrue();
        $actors = $ticket->getITILActors();
        $this->array($actors[2])->contains(Ticket_User::REQUESTER)->contains(Ticket_User::ASSIGN);
        $this->array($actors[''])->contains(Group_Ticket::REQUESTER)->contains(Group_Ticket::ASSIGN);
    }

    /**
     * @see self::testGetAssociatedDocumentsCriteria()
     */
    protected function getAssociatedDocumentsCriteriaProvider()
    {
        $ticket = new \Ticket();
        $ticket_id = $ticket->add([
           'name'            => "test",
           'content'         => "test",
        ]);
        $this->integer((int)$ticket_id)->isGreaterThan(0);

        return [
           [
              'rights'   => [
                 \Change::$rightname       => 0,
                 \Problem::$rightname      => 0,
                 \Ticket::$rightname       => 0,
                 \ITILFollowup::$rightname => 0,
                 \TicketTask::$rightname   => 0,
              ],
              'ticket_id'      => $ticket_id,
              'bypass_rights'  => false,
              'expected_where' => sprintf(
                  "(`glpi_documents_items`.`itemtype` = 'Ticket' AND `glpi_documents_items`.`items_id` = '%1\$s')",
                  $ticket_id
              ),
           ],
           [
              'rights'   => [
                 \Change::$rightname       => 0,
                 \Problem::$rightname      => 0,
                 \Ticket::$rightname       => \READ,
                 \ITILFollowup::$rightname => 0,
                 \TicketTask::$rightname   => 0,
              ],
              'ticket_id'      => $ticket_id,
              'bypass_rights'  => false,
              'expected_where' => sprintf(
                  "(`glpi_documents_items`.`itemtype` = 'Ticket' AND `glpi_documents_items`.`items_id` = '%1\$s')"
               . " OR (`glpi_documents_items`.`itemtype` = 'ITILFollowup' AND `glpi_documents_items`.`items_id` IN (SELECT `id` FROM `glpi_itilfollowups` WHERE `glpi_itilfollowups`.`itemtype` = 'Ticket' AND `glpi_itilfollowups`.`items_id` = '%1\$s' AND ((`is_private` = '0' OR `users_id` = '%2\$s'))))"
               . " OR (`glpi_documents_items`.`itemtype` = 'ITILSolution' AND `glpi_documents_items`.`items_id` IN (SELECT `id` FROM `glpi_itilsolutions` WHERE `glpi_itilsolutions`.`itemtype` = 'Ticket' AND `glpi_itilsolutions`.`items_id` = '%1\$s'))",
                  $ticket_id,
                  getItemByTypeName('User', TU_USER, true)
              ),
         ],
           [
              'rights'   => [
                 \Change::$rightname       => 0,
                 \Problem::$rightname      => 0,
                 \Ticket::$rightname       => \READ,
                 \ITILFollowup::$rightname => \ITILFollowup::SEEPUBLIC,
                 \TicketTask::$rightname   => \TicketTask::SEEPUBLIC,
              ],
              'ticket_id'      => $ticket_id,
              'bypass_rights'  => false,
              'expected_where' => sprintf(
                  "(`glpi_documents_items`.`itemtype` = 'Ticket' AND `glpi_documents_items`.`items_id` = '%1\$s')"
               . " OR (`glpi_documents_items`.`itemtype` = 'ITILFollowup' AND `glpi_documents_items`.`items_id` IN (SELECT `id` FROM `glpi_itilfollowups` WHERE `glpi_itilfollowups`.`itemtype` = 'Ticket' AND `glpi_itilfollowups`.`items_id` = '%1\$s' AND ((`is_private` = '0' OR `users_id` = '%2\$s'))))"
               . " OR (`glpi_documents_items`.`itemtype` = 'ITILSolution' AND `glpi_documents_items`.`items_id` IN (SELECT `id` FROM `glpi_itilsolutions` WHERE `glpi_itilsolutions`.`itemtype` = 'Ticket' AND `glpi_itilsolutions`.`items_id` = '%1\$s'))"
               . " OR (`glpi_documents_items`.`itemtype` = 'TicketTask' AND `glpi_documents_items`.`items_id` IN (SELECT `id` FROM `glpi_tickettasks` WHERE `tickets_id` = '%1\$s' AND ((`is_private` = '0' OR `users_id` = '%2\$s'))))",
                  $ticket_id,
                  getItemByTypeName('User', TU_USER, true)
              ),
         ],
           [
              'rights'   => [
                 \Change::$rightname       => 0,
                 \Problem::$rightname      => 0,
                 \Ticket::$rightname       => \READ,
                 \ITILFollowup::$rightname => \ITILFollowup::SEEPRIVATE,
                 \TicketTask::$rightname   => 0,
              ],
              'ticket_id'      => $ticket_id,
              'bypass_rights'  => false,
              'expected_where' => sprintf(
                  "(`glpi_documents_items`.`itemtype` = 'Ticket' AND `glpi_documents_items`.`items_id` = '%1\$s')"
               . " OR (`glpi_documents_items`.`itemtype` = 'ITILFollowup' AND `glpi_documents_items`.`items_id` IN (SELECT `id` FROM `glpi_itilfollowups` WHERE `glpi_itilfollowups`.`itemtype` = 'Ticket' AND `glpi_itilfollowups`.`items_id` = '%1\$s'))"
               . " OR (`glpi_documents_items`.`itemtype` = 'ITILSolution' AND `glpi_documents_items`.`items_id` IN (SELECT `id` FROM `glpi_itilsolutions` WHERE `glpi_itilsolutions`.`itemtype` = 'Ticket' AND `glpi_itilsolutions`.`items_id` = '%1\$s'))",
                  $ticket_id,
                  getItemByTypeName('User', TU_USER, true)
              ),
         ],
           [
              'rights'   => [
                 \Change::$rightname       => 0,
                 \Problem::$rightname      => 0,
                 \Ticket::$rightname       => \READ,
                 \ITILFollowup::$rightname => \ITILFollowup::SEEPUBLIC,
                 \TicketTask::$rightname   => \TicketTask::SEEPRIVATE,
              ],
              'ticket_id'      => $ticket_id,
              'bypass_rights'  => false,
              'expected_where' => sprintf(
                  "(`glpi_documents_items`.`itemtype` = 'Ticket' AND `glpi_documents_items`.`items_id` = '%1\$s')"
               . " OR (`glpi_documents_items`.`itemtype` = 'ITILFollowup' AND `glpi_documents_items`.`items_id` IN (SELECT `id` FROM `glpi_itilfollowups` WHERE `glpi_itilfollowups`.`itemtype` = 'Ticket' AND `glpi_itilfollowups`.`items_id` = '%1\$s' AND ((`is_private` = '0' OR `users_id` = '%2\$s'))))"
               . " OR (`glpi_documents_items`.`itemtype` = 'ITILSolution' AND `glpi_documents_items`.`items_id` IN (SELECT `id` FROM `glpi_itilsolutions` WHERE `glpi_itilsolutions`.`itemtype` = 'Ticket' AND `glpi_itilsolutions`.`items_id` = '%1\$s'))"
               . " OR (`glpi_documents_items`.`itemtype` = 'TicketTask' AND `glpi_documents_items`.`items_id` IN (SELECT `id` FROM `glpi_tickettasks` WHERE `tickets_id` = '%1\$s'))",
                  $ticket_id,
                  getItemByTypeName('User', TU_USER, true)
              ),
         ],
        ];
    }

    /**
     * @dataProvider getAssociatedDocumentsCriteriaProvider
     */
    public function testGetAssociatedDocumentsCriteria($rights, $ticket_id, $bypass_rights, $expected_where)
    {
        global $DB;
        $this->login();

        $ticket = new \Ticket();
        $this->boolean($ticket->getFromDB($ticket_id))->isTrue();

        $session_backup = $_SESSION['glpiactiveprofile'];
        foreach ($rights as $rightname => $rightvalue) {
            $_SESSION['glpiactiveprofile'][$rightname] = $rightvalue;
        }
        $crit = $ticket->getAssociatedDocumentsCriteria($bypass_rights);
        $_SESSION['glpiactiveprofile'] = $session_backup;

        $it = new \DBmysqlIterator(null);
        $it->execute('glpi_tickets', $crit);
        $expected = 'SELECT * FROM `glpi_tickets` WHERE (' . $expected_where . ')';
        $expected = preg_replace_callback('/`([^`]+)`/', static fn ($match) => $DB->getDoctrineConnection()->getDatabasePlatform()->quoteIdentifier($match[1]), $expected);
        $this->string($it->getSql())->isIdenticalTo($expected);
    }

    public function testKeepScreenshotsOnFormReload()
    {
        //login to get session
        $this->login();

        $base64Image = base64_encode(file_get_contents(__DIR__ . '/../fixtures/uploads/foo.png'));

        // Test display of saved inputs from a previous submit
        $_SESSION['saveInput'][\Ticket::class] = [
           'content' => '&lt;p&gt; &lt;/p&gt;&lt;p&gt;&lt;img id="3e29dffe-0237ea21-5e5e7034b1d1a1.77230247"'
           . ' src="data:image/png;base64,' . $base64Image . '" width="12" height="12" /&gt;&lt;/p&gt;',
        ];

        $this->output(
            function () {
                set_error_handler(static function ($severity, $message) {
                    return $severity === E_WARNING && str_contains($message, 'Array to string conversion');
                });
                try {
                    $instance = new \Ticket();
                    $instance->showForm('-1');
                } finally {
                    restore_error_handler();
                }
            }
        )->contains('src="data:image/png;base64,' . $base64Image . '"')
            ->matches('/<input\b[^>]*\btype="datetime-local"/')
            ->notMatches('/<input\b[^>]*\btype="datetime-local"[^>]*\s="1"/');
    }

    public function testScreenshotConvertedIntoDocument()
    {

        $this->login(); // must be logged as Document_Item uses Session::getLoginUserID()

        // Test uploads for item creation
        $base64Image = base64_encode(file_get_contents(__DIR__ . '/../fixtures/uploads/foo.png'));
        $filename = '5e5e92ffd9bd91.11111111image_paste22222222.png';
        $instance = new \Ticket();
        $input = [
           'name'    => 'a ticket',
           'content' => '&lt;p&gt; &lt;/p&gt;&lt;p&gt;&lt;img id="3e29dffe-0237ea21-5e5e7034b1d1a1.00000000"'
           . ' src="data:image/png;base64,' . $base64Image . '" width="12" height="12" /&gt;&lt;/p&gt;',
           '_content' => [
              $filename,
           ],
           '_tag_content' => [
              '3e29dffe-0237ea21-5e5e7034b1d1a1.00000000',
           ],
           '_prefix_content' => [
              '5e5e92ffd9bd91.11111111',
           ]
        ];
        copy(__DIR__ . '/../fixtures/uploads/foo.png', GLPI_TMP_DIR . '/' . $filename);
        $instance->add($input);
        $expected = 'a href=&quot;/front/document.send.php?docid=';
        $this->string($instance->fields['content'])->contains($expected);

        // Test uploads for item update
        $base64Image = base64_encode(file_get_contents(__DIR__ . '/../fixtures/uploads/bar.png'));
        $filename = '5e5e92ffd9bd91.44444444image_paste55555555.png';
        copy(__DIR__ . '/../fixtures/uploads/bar.png', GLPI_TMP_DIR . '/' . $filename);
        $instance->update([
           'id' => $instance->getID(),
           'content' => '&lt;p&gt; &lt;/p&gt;&lt;p&gt;&lt;img id="3e29dffe-0237ea21-5e5e7034b1d1a1.33333333"'
           . ' src="data:image/png;base64,' . $base64Image . '" width="12" height="12" /&gt;&lt;/p&gt;',
           '_content' => [
              $filename,
           ],
           '_tag_content' => [
              '3e29dffe-0237ea21-5e5e7034b1d1a1.33333333',
           ],
           '_prefix_content' => [
              '5e5e92ffd9bd91.44444444',
           ]
        ]);
        $expected = 'a href=&quot;/front/document.send.php?docid=';
        $this->string($instance->fields['content'])->contains($expected);
    }

    public function testUploadDocuments()
    {

        $this->login(); // must be logged as Document_Item uses Session::getLoginUserID()

        // Test uploads for item creation
        $filename = '5e5e92ffd9bd91.11111111' . 'foo.txt';
        $instance = new \Ticket();
        $input = [
           'name'    => 'a ticket',
           'content' => 'testUploadDocuments',
           '_filename' => [
              $filename,
           ],
           '_tag_filename' => [
              '3e29dffe-0237ea21-5e5e7034b1ffff.00000000',
           ],
           '_prefix_filename' => [
              '5e5e92ffd9bd91.11111111',
           ]
        ];
        copy(__DIR__ . '/../fixtures/uploads/foo.txt', GLPI_TMP_DIR . '/' . $filename);
        $instance->add($input);
        $this->string($instance->fields['content'])->contains('testUploadDocuments');
        $count = (new \DBUtils())->countElementsInTable(\Document_Item::getTable(), [
           'itemtype' => 'Ticket',
           'items_id' => $instance->getID(),
        ]);
        $this->integer($count)->isEqualTo(1);

        // Test uploads for item update (adds a 2nd document)
        $filename = '5e5e92ffd9bd91.44444444bar.txt';
        copy(__DIR__ . '/../fixtures/uploads/bar.txt', GLPI_TMP_DIR . '/' . $filename);
        $instance->update([
           'id' => $instance->getID(),
           'content' => 'update testUploadDocuments',
           '_filename' => [
              $filename,
           ],
           '_tag_filename' => [
              '3e29dffe-0237ea21-5e5e7034b1d1a1.33333333',
           ],
           '_prefix_filename' => [
              '5e5e92ffd9bd91.44444444',
           ]
        ]);
        $this->string($instance->fields['content'])->contains('update testUploadDocuments');
        $count = (new \DBUtils())->countElementsInTable(\Document_Item::getTable(), [
           'itemtype' => 'Ticket',
           'items_id' => $instance->getID(),
        ]);
        $this->integer($count)->isEqualTo(2);
    }

    public function testKeepScreenshotFromTemplate()
    {
        //login to get session
        $this->login();

        // create a template with a predeined description
        $ticketTemplate = new \TicketTemplate();
        $ticketTemplate->add([
           'name' => $this->getUniqueString(),
        ]);
        $base64Image = base64_encode(file_get_contents(__DIR__ . '/../fixtures/uploads/foo.png'));
        $content = '&lt;p&gt;&lt;img id="3e29dffe-0237ea21-5e57d2c8895d55.57735524"'
        . ' src="data:image/png;base64,' . $base64Image . '" width="12" height="12" /&gt;&lt;/p&gt;';
        $predefinedField = new \TicketTemplatePredefinedField();
        $predefinedField->add([
           'tickettemplates_id' => $ticketTemplate->getID(),
           'num' => '21',
           'value' => $content
        ]);
        $session_tpl_id_back = $_SESSION['glpiactiveprofile']['tickettemplates_id'];
        $_SESSION['glpiactiveprofile']['tickettemplates_id'] = $ticketTemplate->getID();

        $this->output(
            function () use ($session_tpl_id_back) {
                set_error_handler(static function ($severity, $message) {
                    return $severity === E_WARNING && str_contains($message, 'Array to string conversion');
                });
                try {
                    $instance = new \Ticket();
                    $instance->showForm('0');
                    $_SESSION['glpiactiveprofile']['tickettemplates_id'] = $session_tpl_id_back;
                } finally {
                    restore_error_handler();
                }
            }
        )->contains('src="data:image/png;base64,' . $base64Image . '"')
            ->matches('/<input\b[^>]*\btype="datetime-local"/')
            ->notMatches('/<input\b[^>]*\btype="datetime-local"[^>]*\s="1"/');
    }


    public function testCanDelegateeCreateTicket()
    {
        global $DB;
        $normal_id   = getItemByTypeName('User', 'normal', true);
        $tech_id     = getItemByTypeName('User', 'tech', true);
        $postonly_id = getItemByTypeName('User', 'post-only', true);
        $tuser_id    = getItemByTypeName('User', TU_USER, true);

        // check base behavior (only standard interface can create for other users)
        $this->login();
        $this->boolean(\Ticket::canDelegateeCreateTicket($normal_id))->isTrue();
        $this->login('tech', 'tech');
        $this->boolean(\Ticket::canDelegateeCreateTicket($normal_id))->isTrue();
        $this->login('post-only', 'postonly');
        $this->boolean(\Ticket::canDelegateeCreateTicket($normal_id))->isFalse();

        // create a test group
        $group = new \Group();
        $groups_id = $group->add(['name' => 'test delegatee']);
        $this->integer($groups_id)->isGreaterThan(0);

        // make postonly delegate of the group
        $gu = new \Group_User();
        $this->integer($gu->add([
           'users_id'         => $postonly_id,
           'groups_id'        => $groups_id,
           'is_userdelegate' => 1,
        ]))->isGreaterThan(0);
        $this->integer($gu->add([
           'users_id'  => $normal_id,
           'groups_id' => $groups_id,
        ]))->isGreaterThan(0);

        // check postonly can now create (yes for normal and himself) or not (no for others) for other users
        $this->login('post-only', 'postonly');
        $this->boolean(\Ticket::canDelegateeCreateTicket($postonly_id))->isTrue();
        $this->boolean(\Ticket::canDelegateeCreateTicket($normal_id))->isTrue();
        $this->boolean(\Ticket::canDelegateeCreateTicket($tech_id))->isFalse();
        $this->boolean(\Ticket::canDelegateeCreateTicket($tuser_id))->isFalse();

        // Warm the two selector operations without enclosing permission-helper callbacks.
        $groupRows = iterator_to_array(User::getSqlSearchResult(false, 'groups'));
        $this->array(array_map('intval', array_column($groupRows, 'id')))->contains((int)$normal_id);
        $managers = new ReflectionProperty(Orm::class, 'unitsOfWork');
        $beforeManagers = $managers->getValue();
        $this->array(iterator_to_array(User::getSqlSearchResult(false, 'groups')))->isIdenticalTo($groupRows);
        $this->integer($managers->getValue() - $beforeManagers)->isIdenticalTo(
            0,
            'Group membership and user selection reuse the canonical manager'
        );
        $originalAdapter = $DB;
        $connection = $DB->getDoctrineConnection();
        $scope = $connection->captureManagedTransactionScope();
        $expected = iterator_to_array(User::getSqlSearchResult(false, 'id'));
        $probe = new TimelineDocumentReadProbe($connection);
        $this->mockGenerator->orphanize('__construct');
        $adapter = new TimelineCountAdapter();
        $selected = $probe;
        $getters = 0;
        $this->calling($adapter)->getDoctrineConnection = static function () use (&$selected, &$getters): Connection {
            ++$getters;
            return $selected;
        };
        $this->calling($adapter)->getProvider = $originalAdapter->getProvider();
        try {
            $DB = $adapter;
            $beforeManagers = $managers->getValue();
            $this->array(iterator_to_array(User::getSqlSearchResult(false, 'id')))->isIdenticalTo($expected);
            $this->integer($getters)->isIdenticalTo(1);
            $this->integer($managers->getValue() - $beforeManagers)->isIdenticalTo(1);
            $this->array($probe->queries)->hasSize(1);
            // The same adapter changing its physical selection must fail before executing SQL.
            $selected = $connection;
            OwnershipUpdateUnit::withWriterGuard($adapter, $connection, function () use (&$selected, $probe, $connection): void {
                $selected = $probe;
                try {
                    $this->exception(static fn () => User::getSqlSearchResult(false, 'id'))
                        ->isInstanceOf(TransactionOwnershipMismatch::class);
                    $this->array($probe->queries)->hasSize(1);
                } finally {
                    $selected = $connection;
                }
            });
        } finally {
            $DB = $originalAdapter;
            $scope->assertActive();
        }
        // A DBAL change after the warm read must affect permission selection immediately.
        $connection->delete('glpi_groups_users', ['groups_id' => $groups_id, 'users_id' => $normal_id]);
        $this->boolean(LegacyTicket::canDelegateeCreateTicket($normal_id))->isFalse();
    }

    public function testCanAddFollowupsDefaults()
    {
        $tech_id = getItemByTypeName('User', 'tech', true);
        $normal_id = getItemByTypeName('User', 'normal', true);
        $post_only_id = getItemByTypeName('User', 'post-only', true);

        $this->login();

        $ticket = new \Ticket();
        $this->integer(
            (int)$ticket->add([
              'name'    => '',
              'content' => 'A ticket to check ACLS',
         ])
        )->isGreaterThan(0);

        $this->boolean((bool)$ticket->canUserAddFollowups($tech_id))->isTrue();
        $this->boolean((bool)$ticket->canUserAddFollowups($normal_id))->isFalse();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isFalse();

        $this->login('tech', 'tech');
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
        $this->login('normal', 'normal');
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();
    }

    public function testCanAddFollowupsAsRecipient()
    {
        global $DB;

        $post_only_id = getItemByTypeName('User', 'post-only', true);

        $this->login();

        $ticket = new \Ticket();
        $this->integer(
            (int)$ticket->add([
              'name'               => '',
              'content'            => 'A ticket to check ACLS',
              'users_id_recipient' => $post_only_id,
              '_auto_import'       => false,
         ])
        )->isGreaterThan(0);

        // Drop all followup rights.
        $this->setSelfServiceFollowupRight(0);

        // Cannot add followup as user do not have ADDMYTICKET right
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isFalse();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();

        // Add user right
        $DB->update(
            'glpi_profilerights',
            [
              'rights' => \ITILFollowup::ADDMYTICKET
         ],
            [
              'profiles_id' => getItemByTypeName('Profile', 'Self-Service', true),
              'name'        => \ITILFollowup::$rightname,
         ]
        );

        // User is recipient and have ADDMYTICKET, he should be able to add followup
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isTrue();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
    }

    public function testCanAddFollowupsAsRequester()
    {
        global $DB;

        $post_only_id = getItemByTypeName('User', 'post-only', true);

        $this->login();

        $ticket = new \Ticket();
        $this->integer(
            (int)$ticket->add([
              'name'    => '',
              'content' => 'A ticket to check ACLS',
         ])
        )->isGreaterThan(0);

        // Drop all followup rights.
        $this->setSelfServiceFollowupRight(0);

        // Cannot add followups by default
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isFalse();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();

        // Add user as requester
        $this->login();
        $ticket_user = new \Ticket_User();
        $input_ticket_user = [
           'tickets_id' => $ticket->getID(),
           'users_id'   => $post_only_id,
           'type'       => \CommonITILActor::REQUESTER
        ];
        $this->integer((int) $ticket_user->add($input_ticket_user))->isGreaterThan(0);
        $this->boolean($ticket->getFromDB($ticket->getID()))->isTrue(); // Reload ticket actors

        // Cannot add followup as user do not have ADDMYTICKET right
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isFalse();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();

        // Add user right
        $DB->update(
            'glpi_profilerights',
            [
              'rights' => \ITILFollowup::ADDMYTICKET
         ],
            [
              'profiles_id' => getItemByTypeName('Profile', 'Self-Service', true),
              'name'        => \ITILFollowup::$rightname,
         ]
        );

        // User is requester and have ADDMYTICKET, he should be able to add followup
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isTrue();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
    }

    public function testCanAddFollowupsAsRequesterGroup()
    {
        global $DB;

        $post_only_id = getItemByTypeName('User', 'post-only', true);

        $this->login();

        $ticket = new \Ticket();
        $this->integer(
            (int)$ticket->add([
              'name'    => '',
              'content' => 'A ticket to check ACLS',
         ])
        )->isGreaterThan(0);

        // Drop all followup rights.
        $this->setSelfServiceFollowupRight(0);

        // Cannot add followups by default
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isFalse();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();

        // Add user's group as requester
        $this->login();
        $group = new \Group();
        $group_id = $group->add(['name' => 'Test group']);
        $this->integer((int)$group_id)->isGreaterThan(0);
        $group_user = new \Group_User();
        $this->integer(
            (int)$group_user->add([
              'groups_id' => $group_id,
              'users_id'  => $post_only_id,
         ])
        )->isGreaterThan(0);

        $group_ticket = new \Group_Ticket();
        $input_group_ticket = [
           'tickets_id' => $ticket->getID(),
           'groups_id'  => $group_id,
           'type'       => \CommonITILActor::REQUESTER
        ];
        $this->integer((int) $group_ticket->add($input_group_ticket))->isGreaterThan(0);
        $this->boolean($ticket->getFromDB($ticket->getID()))->isTrue(); // Reload ticket actors

        // Cannot add followup as user do not have ADDGROUPTICKET right
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isFalse();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();

        // Add user right
        $DB->update(
            'glpi_profilerights',
            [
              'rights' => \ITILFollowup::ADDGROUPTICKET
         ],
            [
              'profiles_id' => getItemByTypeName('Profile', 'Self-Service', true),
              'name'        => \ITILFollowup::$rightname,
         ]
        );

        // User is requester and have ADDGROUPTICKET, he should be able to add followup
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isTrue();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
    }

    protected function setSelfServiceFollowupRight(int $right): void
    {
        global $DB;

        $DB->update(
            'glpi_profilerights',
            [
              'rights' => $right
         ],
            [
              'profiles_id' => getItemByTypeName('Profile', 'Self-Service', true),
              'name'        => \ITILFollowup::$rightname,
         ]
        );
    }

    public function testCanAddFollowupsAsAssigned()
    {
        $post_only_id = getItemByTypeName('User', 'post-only', true);

        $this->login();

        $ticket = new \Ticket();
        $this->integer(
            (int)$ticket->add([
              'name'    => '',
              'content' => 'A ticket to check ACLS',
         ])
        )->isGreaterThan(0);

        // Drop all followup rights.
        $this->setSelfServiceFollowupRight(0);

        // Cannot add followups by default
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isFalse();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();

        // Add user as requester
        $this->login();
        $ticket_user = new \Ticket_User();
        $input_ticket_user = [
           'tickets_id' => $ticket->getID(),
           'users_id'   => $post_only_id,
           'type'       => \CommonITILActor::ASSIGN
        ];
        $this->integer((int) $ticket_user->add($input_ticket_user))->isGreaterThan(0);
        $this->boolean($ticket->getFromDB($ticket->getID()))->isTrue(); // Reload ticket actors

        // Stricter behavior: assignment alone is not enough.
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isFalse();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();

        // Grant assigned-only followup right.
        $this->setSelfServiceFollowupRight(\ITILFollowup::ADDASSIGNEDTICKET);

        // Can add followup as user is assigned and has dedicated right.
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isTrue();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
    }

    public function testCanAddFollowupsAsAssignedGroup()
    {
        $post_only_id = getItemByTypeName('User', 'post-only', true);

        $this->login();

        $ticket = new \Ticket();
        $this->integer(
            (int)$ticket->add([
              'name'    => '',
              'content' => 'A ticket to check ACLS',
         ])
        )->isGreaterThan(0);

        // Drop all followup rights.
        $this->setSelfServiceFollowupRight(0);

        // Cannot add followups by default
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isFalse();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();

        // Add user's group as requester
        $this->login();
        $group = new \Group();
        $group_id = $group->add(['name' => 'Test group']);
        $this->integer((int)$group_id)->isGreaterThan(0);
        $group_user = new \Group_User();
        $this->integer(
            (int)$group_user->add([
              'groups_id' => $group_id,
              'users_id'  => $post_only_id,
         ])
        )->isGreaterThan(0);

        $ticket_user = new \Group_Ticket();
        $group_ticket = new \Group_Ticket();
        $input_group_ticket = [
           'tickets_id' => $ticket->getID(),
           'groups_id'  => $group_id,
           'type'       => \CommonITILActor::ASSIGN
        ];
        $this->integer((int) $group_ticket->add($input_group_ticket))->isGreaterThan(0);
        $this->boolean($ticket->getFromDB($ticket->getID()))->isTrue(); // Reload ticket actors

        // Stricter behavior: assignment alone is not enough.
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isFalse();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isFalse();

        // Grant assigned-only followup right.
        $this->setSelfServiceFollowupRight(\ITILFollowup::ADDASSIGNEDTICKET);

        // Can add followup as user is assigned and has dedicated right.
        $this->login();
        $this->boolean((bool)$ticket->canUserAddFollowups($post_only_id))->isTrue();
        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket->canAddFollowups())->isTrue();
    }

    public function testCanAddFollowupsWithClassicRightOnAssignedAndUnassigned()
    {
        $post_only_id = getItemByTypeName('User', 'post-only', true);

        $this->login();

        $ticket_unassigned = new \Ticket();
        $this->integer(
            (int)$ticket_unassigned->add([
              'name'    => '',
              'content' => 'A ticket not assigned to post-only',
         ])
        )->isGreaterThan(0);

        $ticket_assigned = new \Ticket();
        $this->integer(
            (int)$ticket_assigned->add([
              'name'    => '',
              'content' => 'A ticket assigned to post-only',
         ])
        )->isGreaterThan(0);

        $ticket_user = new \Ticket_User();
        $this->integer(
            (int)$ticket_user->add([
              'tickets_id' => $ticket_assigned->getID(),
              'users_id'   => $post_only_id,
              'type'       => \CommonITILActor::ASSIGN
         ])
        )->isGreaterThan(0);
        $this->boolean($ticket_assigned->getFromDB($ticket_assigned->getID()))->isTrue();

        // Without followup rights, unassigned ticket is denied.
        $this->setSelfServiceFollowupRight(0);
        $this->login();
        $this->boolean((bool)$ticket_unassigned->canUserAddFollowups($post_only_id))->isFalse();

        // Classic followup right allows both assigned and unassigned tickets.
        $this->setSelfServiceFollowupRight(\ITILFollowup::ADDALLTICKET);
        $this->login();
        $this->boolean((bool)$ticket_unassigned->canUserAddFollowups($post_only_id))->isTrue();
        $this->boolean((bool)$ticket_assigned->canUserAddFollowups($post_only_id))->isTrue();

        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket_unassigned->canAddFollowups())->isTrue();
        $this->boolean((bool)$ticket_assigned->canAddFollowups())->isTrue();
    }

    public function testCanAddFollowupsWithAssignedOnlyRightOnAssignedAndUnassigned()
    {
        $post_only_id = getItemByTypeName('User', 'post-only', true);

        $this->login();

        $ticket_unassigned = new \Ticket();
        $this->integer(
            (int)$ticket_unassigned->add([
              'name'    => '',
              'content' => 'A ticket not assigned to post-only',
         ])
        )->isGreaterThan(0);

        $ticket_assigned = new \Ticket();
        $this->integer(
            (int)$ticket_assigned->add([
              'name'    => '',
              'content' => 'A ticket assigned to post-only',
         ])
        )->isGreaterThan(0);

        $ticket_user = new \Ticket_User();
        $this->integer(
            (int)$ticket_user->add([
              'tickets_id' => $ticket_assigned->getID(),
              'users_id'   => $post_only_id,
              'type'       => \CommonITILActor::ASSIGN
         ])
        )->isGreaterThan(0);
        $this->boolean($ticket_assigned->getFromDB($ticket_assigned->getID()))->isTrue();

        // Assigned-only right allows only assigned tickets.
        $this->setSelfServiceFollowupRight(\ITILFollowup::ADDASSIGNEDTICKET);
        $this->login();
        $this->boolean((bool)$ticket_unassigned->canUserAddFollowups($post_only_id))->isFalse();
        $this->boolean((bool)$ticket_assigned->canUserAddFollowups($post_only_id))->isTrue();

        $this->login('post-only', 'postonly');
        $this->boolean((bool)$ticket_unassigned->canAddFollowups())->isFalse();
        $this->boolean((bool)$ticket_assigned->canAddFollowups())->isTrue();
    }

    protected function convertContentForTicketProvider(): iterable
    {
        yield [
           'content'  => '',
           'files'    => [],
           'tags'     => [],
           'expected' => '',
        ];

        // Content with embedded image.
        yield [
           'content'  => <<<HTML
Here is the screenshot:
<img src="screenshot.png" />
blabla
HTML
           ,
           'files'    => [
              'screenshot.png' => 'screenshot.png',
           ],
           'tags'     => [
              'screenshot.png' => '9faff0a6-f37490bd-60e2af9721f420.96500246',
           ],
           'expected' => <<<HTML
Here is the screenshot:
<p>#9faff0a6-f37490bd-60e2af9721f420.96500246#</p>
blabla
HTML
           ,
        ];

        // Content with leading external image that will not be replaced by a tag.
        yield [
           'content'  => <<<HTML
<img src="http://test.glpi-project.org/logo.png" />
Here is the screenshot:
<img src="img.jpg" />
blabla
HTML
           ,
           'files'    => [
              'img.jpg' => 'img.jpg',
           ],
           'tags'     => [
              'img.jpg' => '3eaff0a6-f37490bd-60e2a59721f420.96500246',
           ],
           'expected' => <<<HTML
<img src="http://test.glpi-project.org/logo.png" />
Here is the screenshot:
<p>#3eaff0a6-f37490bd-60e2a59721f420.96500246#</p>
blabla
HTML
           ,
        ];
    }

    /**
     * @dataProvider convertContentForTicketProvider
     */
    public function testConvertContentForTicket(string $content, array $files, array $tags, string $expected)
    {
        $this->newTestedInstance();

        $this->string($this->testedInstance->convertContentForTicket($content, $files, $tags))->isEqualTo($expected);
    }

    protected function testIsValidatorProvider(): array
    {
        $this->login();

        // Existing ursers from databaser
        $users_id_1 = getItemByTypeName(User::class, "itsm", true);
        $users_id_2 = getItemByTypeName(User::class, "tech", true);

        // Tickets to create before tests
        $this->createItems(\Ticket::class, [
           [
              'name'    => 'testIsValidatorProvider 1',
              'content' => 'testIsValidatorProvider 1',
           ],
           [
              'name'    => 'testIsValidatorProvider 2',
              'content' => 'testIsValidatorProvider 2',
           ],
        ]);

        // Get id of created tickets to reuse later
        $tickets_id_1 = getItemByTypeName(\Ticket::class, "testIsValidatorProvider 1", true);
        $tickets_id_2 = getItemByTypeName(\Ticket::class, "testIsValidatorProvider 2", true);

        // TicketValidation items to create before tests
        $this->createItems(TicketValidation::class, [
           [
              'tickets_id'        => $tickets_id_1,
              'users_id_validate' => $users_id_1,
           ],
           [
              'tickets_id'        => $tickets_id_2,
              'users_id_validate' => $users_id_2,
           ],
        ]);

        return [
           [
              'tickets_id' => $tickets_id_1,
              'users_id'   => $users_id_1,
              'expected'   => true,
           ],
           [
              'tickets_id' => $tickets_id_1,
              'users_id'   => $users_id_2,
              'expected'   => false,
           ],
           [
              'tickets_id' => $tickets_id_2,
              'users_id'   => $users_id_1,
              'expected'   => false,
           ],
           [
              'tickets_id' => $tickets_id_2,
              'users_id'   => $users_id_2,
              'expected'   => true,
           ],
        ];
    }

    /**
     * @dataProvider testIsValidatorProvider
     */
    public function testIsValidator(
        int $tickets_id,
        int $users_id,
        bool $expected
    ) {
        $ticket = new \Ticket();
        $this->boolean($ticket->getFromDB($tickets_id))->isTrue();
        $this->boolean($ticket->isValidator($users_id))->isEqualTo($expected);
    }
}




/** Observe document SQL while retaining the base connection's compiler methods. */
class TimelineDocumentReadProbe extends Connection
{
    public array $queries = [];

    public function __construct(private readonly Connection $selected)
    {
        $driver = new class ($selected->getDriver(), $selected->getDatabasePlatform()) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly AbstractPlatform $platform)
            {
                parent::__construct($driver);
            }

            public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
            {
                return $this->platform;
            }

            public function connect(array $params): DriverConnection
            {
                throw new LogicException('The document observer cannot open an independent connection');
            }
        };
        parent::__construct($selected->getParams(), $driver, $selected->getConfiguration());
    }

    protected function connect(): DriverConnection
    {
        // Fixed discriminator quoting uses the same physical route and transaction.
        return $this->selected->connect();
    }

    public function executeQuery(string $sql, array $params = [], array $types = [], ?QueryCacheProfile $qcp = null): Result
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params, 'types' => $types];
        return $this->selected->executeQuery($sql, $params, $types, $qcp);
    }
}


/** A supplied mutable EventManager keeps every timeline count on the ordinary path. */
class TimelineLocalCountProbe extends ScalarReadProbe
{
    private EventManager $events;

    public function __construct(Connection $selected)
    {
        parent::__construct($selected);
        $this->events = new EventManager();
    }

    public function getEventManager(): EventManager
    {
        return $this->events;
    }
}
