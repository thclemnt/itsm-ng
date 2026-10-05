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
use DbTestCase;
use ITILFollowup as CoreITILFollowup;
use Ticket;
use Ticket_User;
use User;

/* Test for inc/itilfollowup.class.php */

class ITILFollowup extends DbTestCase
{
    /**
     * Create a new ITILObject and return its id
     *
     * @param string $itemtype ITILObject parent to test followups on
     * @return integer
     */
    private function getNewITILObject($itemtype)
    {
        //create reference ITILObject
        $itilobject = new $itemtype();
        $this->integer((int)$itilobject->add([
              'name'         => "$itemtype title",
              'description'  => 'a description',
              'content'      => 'a description',
              'entities_id'  => getItemByTypeName('Entity', '_test_root_entity', true),
        ]))->isGreaterThan(0);

        $this->boolean($itilobject->isNewItem())->isFalse();
        $this->boolean($itilobject->can($itilobject->getID(), \READ))->isTrue();
        return (int)$itilobject->getID();
    }

    public function testReparentingRequiresApplicableRemovalRights()
    {
        $this->login();
        $source = $this->getNewITILObject(Ticket::class);
        $target = $this->getNewITILObject(Ticket::class);
        $followup = new CoreITILFollowup();
        $id = $followup->add([
            'itemtype' => Ticket::class,
            'items_id' => $source,
            'content' => 'Followup parent permission regression',
        ]);
        $this->integer($id)->isGreaterThan(0);
        $this->boolean($followup->maybeDeleted())->isFalse();
        $this->boolean($followup->can($id, \DELETE))->isFalse();
        $this->boolean($followup->can($id, \PURGE))->isTrue();
        $profile = $_SESSION['glpiactiveprofile'];
        $input = ['id' => $id, 'itemtype' => Ticket::class, 'items_id' => $target];
        try {
            $_SESSION['glpiactiveprofile'][CoreITILFollowup::$rightname] &= ~\PURGE;
            $this->boolean($followup->can($id, \PURGE))->isFalse();
            $this->boolean((new CoreITILFollowup())->can(-1, \CREATE, $input))->isTrue();
            $this->boolean($followup->update($input))->isFalse();
            $this->array($_SESSION['MESSAGE_AFTER_REDIRECT'][\ERROR])->isIdenticalTo([
                __('Cannot update item: not enough right on the parent(s) item(s)'),
            ]);
            unset($_SESSION['MESSAGE_AFTER_REDIRECT'][\ERROR]);
            $this->boolean($followup->getFromDB($id))->isTrue();
            $this->integer($followup->fields['items_id'])->isIdenticalTo($source);

            $_SESSION['glpiactiveprofile'] = $profile;
            $_SESSION['glpiactiveprofile'][CoreITILFollowup::$rightname] = \PURGE;
            $_SESSION['glpiactiveprofile'][Ticket::$rightname] &= ~Ticket::OWN;
            $this->boolean($followup->can($id, \PURGE))->isTrue();
            $this->boolean((new CoreITILFollowup())->can(-1, \CREATE, $input))->isFalse();
            $this->boolean($followup->update($input))->isFalse();
            $this->array($_SESSION['MESSAGE_AFTER_REDIRECT'][\ERROR])->isIdenticalTo([
                __('Cannot update item: not enough right on the parent(s) item(s)'),
            ]);
            unset($_SESSION['MESSAGE_AFTER_REDIRECT'][\ERROR]);
            $this->boolean($followup->getFromDB($id))->isTrue();
            $this->integer($followup->fields['items_id'])->isIdenticalTo($source);

            $_SESSION['glpiactiveprofile'] = $profile;
            $this->boolean($followup->update($input))->isTrue();
            $this->boolean($followup->getFromDB($id))->isTrue();
            $this->integer($followup->fields['items_id'])->isIdenticalTo($target);
            $this->integer($followup->fields['tickets_id'])->isIdenticalTo($target);
            $this->variable($followup->fields['problems_id'])->isNull();
            $this->variable($followup->fields['changes_id'])->isNull();
        } finally {
            $_SESSION['glpiactiveprofile'] = $profile;
        }
    }

    public function testACL()
    {
        $this->login();

        $ticketId = $this->getNewITILObject('Ticket');
        $fup      = new \ITILFollowup();
        $tmp      = ['itemtype' => 'Ticket', 'items_id' => $ticketId];
        $this->boolean((bool) $fup->can(-1, \CREATE, $tmp))->isTrue();

        $fup_id = $fup->add([
           'content'      => "my followup",
           'itemtype'   => 'Ticket',
           'items_id'   => $ticketId
        ]);
        $this->integer($fup_id)->isGreaterThan(0);
        $this->boolean((bool) $fup->canViewItem())->isTrue();
        $this->boolean((bool) $fup->canUpdateItem())->isTrue();
        $this->boolean((bool) $fup->canPurgeItem())->isTrue();

        $changeId = $this->getNewITILObject('Change');
        $fup      = new \ITILFollowup();
        $tmp      = ['itemtype' => 'Change', 'items_id' => $changeId];
        $this->boolean((bool) $fup->can(-1, \CREATE, $tmp))->isTrue();

        $fup_id = $fup->add([
           'content'      => "my followup",
           'itemtype'   => 'Change',
           'items_id'   => $changeId
        ]);
        $this->integer($fup_id)->isGreaterThan(0);
        $this->boolean((bool) $fup->canViewItem())->isTrue();
        $this->boolean((bool) $fup->canUpdateItem())->isTrue();
        $this->boolean((bool) $fup->canPurgeItem())->isTrue();

        $problemId = $this->getNewITILObject('Problem');
        $fup      = new \ITILFollowup();
        $tmp      = ['itemtype' => 'Problem', 'items_id' => $problemId];
        $this->boolean((bool) $fup->can(-1, \CREATE, $tmp))->isTrue();

        $fup_id = $fup->add([
           'content'      => "my followup",
           'itemtype'   => 'Problem',
           'items_id'   => $problemId
        ]);
        $this->integer($fup_id)->isGreaterThan(0);
        $this->boolean((bool) $fup->canViewItem())->isTrue();
        $this->boolean((bool) $fup->canUpdateItem())->isTrue();
        $this->boolean((bool) $fup->canPurgeItem())->isTrue();
    }

    protected function updateAndDeleteProvider(): array
    {
        return [
            ['Ticket', false], ['Ticket', true],
            ['Problem', false], ['Problem', true],
            ['Change', false], ['Change', true],
        ];
    }

    /** @dataProvider updateAndDeleteProvider */
    public function testUpdateAndDelete(string $itemtype, bool $force)
    {
        global $DB, $PLUGIN_HOOKS;

        $this->login();
        $parentId = $this->getNewITILObject($itemtype);
        $parent = new $itemtype();
        $this->boolean($parent->can($parentId, \UPDATE))->isTrue();
        $connection = $DB->getDoctrineConnection();
        $depth = $connection->getTransactionNestingLevel();
        $this->integer($depth)->isGreaterThan(0);
        $savedHooks = $PLUGIN_HOOKS;
        $activated = new \ReflectionProperty(\Plugin::class, 'activated_plugins');
        $savedPlugins = $activated->getValue();
        $savedClock = $_SESSION['glpi_currenttime'];
        $events = [];
        $clock = new \DateTimeImmutable('2031-02-03 04:05:06');
        $assertUpdater = function () use ($connection, $parent, $parentId, &$clock): void {
            $row = $connection->fetchAssociative('SELECT users_id_lastupdater, date_mod FROM '
                . $connection->quoteIdentifier($parent->getTable()) . ' WHERE id = ?', [$parentId]);
            $this->integer((int)$row['users_id_lastupdater'])->isEqualTo((int)\Session::getLoginUserID());
            $this->integer((new \DateTimeImmutable($row['date_mod']))->getTimestamp())->isEqualTo($clock->getTimestamp());
        };
        try {
            $activated->setValue(null, [...$savedPlugins, 'itil_purge_fixture']);
            // A non-trashable followup must emit purge hooks even for delete()'s default force.
            // Register delete hooks too, so the exact vector proves they never fire.
            foreach (['item_add', 'item_update', 'pre_item_delete', 'item_delete', 'pre_item_purge', 'item_purge'] as $event) {
                $PLUGIN_HOOKS[$event]['itil_purge_fixture'][CoreITILFollowup::class] = static function (CoreITILFollowup $item) use (&$events, $event): void {
                    $events[] = $event;
                };
            }
            $_SESSION['glpi_currenttime'] = $clock->format('Y-m-d H:i:s');
            $fup = new CoreITILFollowup();
            $fupId = $fup->add(['content' => 'my followup', 'itemtype' => $itemtype, 'items_id' => $parentId]);
            $this->integer((int)$fupId)->isGreaterThan(0);
            $this->boolean((bool)$fup->maybeDeleted())->isFalse();
            $this->boolean((bool)$fup->can($fupId, \PURGE))->isTrue();
            $this->array($events)->isEqualTo(['item_add']);
            $assertUpdater();

            $clock = $clock->modify('+1 second');
            $_SESSION['glpi_currenttime'] = $clock->format('Y-m-d H:i:s');
            $this->boolean($fup->update(['id' => $fupId, 'content' => 'my followup updated',
                'itemtype' => $itemtype, 'items_id' => $parentId]))->isTrue();
            $this->boolean($fup->getFromDB($fupId))->isTrue();
            $this->string((string)$fup->fields['content'])->isEqualTo('my followup updated');
            $this->array($events)->isEqualTo(['item_add', 'item_update']);
            $assertUpdater();

            $clock = $clock->modify('+1 second');
            $_SESSION['glpi_currenttime'] = $clock->format('Y-m-d H:i:s');
            $deleted = $force ? $fup->delete(['id' => $fupId], true) : $fup->delete(['id' => $fupId]);
            $this->boolean($deleted)->isTrue();
            $this->array($events)->isEqualTo(['item_add', 'item_update', 'pre_item_purge', 'item_purge']);
            $this->boolean((bool)$fup->getFromDB($fupId))->isFalse();
            $assertUpdater();
            $actions = array_map('intval', $connection->fetchFirstColumn('SELECT linked_action FROM glpi_logs '
                . 'WHERE itemtype = ? AND items_id = ? ORDER BY id', [$itemtype, $parentId]));
            foreach ([\Log::HISTORY_ADD_SUBITEM, \Log::HISTORY_UPDATE_SUBITEM, \Log::HISTORY_DELETE_SUBITEM] as $action) {
                $this->boolean(in_array($action, $actions, true))->isTrue();
            }
            $this->variable($DB->getDoctrineConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isEqualTo($depth);
            $DB->assertManagedTransaction();
        } finally {
            $_SESSION['glpi_currenttime'] = $savedClock;
            $PLUGIN_HOOKS = $savedHooks;
            $activated->setValue(null, $savedPlugins);
        }
    }

    /**
     * Test _do_not_compute_takeintoaccount flag
     */
    public function testDoNotComputeTakeintoaccount()
    {
        $this->login();

        $ticket = new \Ticket();
        $oldConf = [
          'glpiset_default_tech'      => $_SESSION['glpiset_default_tech'],
          'glpiset_default_requester' => $_SESSION['glpiset_default_requester'],
        ];

        $_SESSION['glpiset_default_tech'] = 0;
        $_SESSION['glpiset_default_requester'] = 0;

        // Normal behaviior, no flag specified
        $ticketID = $this->getNewITILObject('Ticket');
        $this->integer($ticketID);

        $ITILFollowUp = new \ITILFollowup();
        $this->integer($ITILFollowUp->add([
           'date'                            => $_SESSION['glpi_currenttime'],
           'users_id'                        => \Session::getLoginUserID(),
           'content'                         => "Functionnal test",
           'items_id'                        => $ticketID,
           'itemtype'                        => \Ticket::class,
        ]));

        $this->boolean($ticket->getFromDB($ticketID))
           ->isTrue();
        $this->integer((int) $ticket->fields['takeintoaccount_delay_stat'])
           ->isGreaterThan(0);

        // Now using the _do_not_compute_takeintoaccount flag
        $ticketID = $this->getNewITILObject('Ticket');
        $this->integer($ticketID);

        $ITILFollowUp = new \ITILFollowup();
        $this->integer($ITILFollowUp->add([
           'date'                            => $_SESSION['glpi_currenttime'],
           'users_id'                        => \Session::getLoginUserID(),
           'content'                         => "Functionnal test",
           '_do_not_compute_takeintoaccount' => true,
           'items_id'                        => $ticketID,
           'itemtype'                        => \Ticket::class,
        ]));

        $this->boolean($ticket->getFromDB($ticketID))
           ->isTrue();

        $this->integer((int) $ticket->fields['takeintoaccount_delay_stat'])
           ->isEqualTo(0);

        // Reset conf
        $_SESSION['glpiset_default_tech']      = $oldConf['glpiset_default_tech'];
        $_SESSION['glpiset_default_requester'] = $oldConf['glpiset_default_requester'];
    }

    protected function testIsFromSupportAgentProvider()
    {
        return [
           [
              // Case 1: user is not an actor of the ticket
              "roles"    => [],
              "profile" => "Technician",
              "expected" => true,
           ],
           [
              // Case 2: user is a requester
              "roles"    => [CommonITILActor::REQUESTER],
              "profile" => "Technician",
              "expected" => false,
           ],
           [
              // Case 3: user is an observer with a central profile
              "roles"    => [CommonITILActor::OBSERVER],
              "profile" => "Technician",
              "expected" => true,
           ],
           [
              // Case 3b: user is an observer without central profiles
              "roles"    => [CommonITILActor::OBSERVER],
              "profile" => "Self-Service",
              "expected" => false,
           ],
           [
              // Case 4: user is assigned
              "roles"    => [CommonITILActor::ASSIGN],
              "profile" => "Technician",
              "expected" => true,
           ],
           [
              // Case 5: user is observer and assigned
              "roles"    => [
                 CommonITILActor::OBSERVER,
                 CommonITILActor::ASSIGN,
              ],
              "profile" => "Technician",
              "expected" => true,
           ],
        ];
    }

    /**
     * @dataprovider testIsFromSupportAgentProvider
     */
    public function testIsFromSupportAgent(
        array $roles,
        string $profile,
        bool $expected
    ) {
        global $CFG_GLPI, $DB;

        // Disable notifications
        $old_conf = $CFG_GLPI['use_notifications'];
        $CFG_GLPI['use_notifications'] = false;

        $this->login();

        // Insert a ticket;
        $ticket = new Ticket();
        $ticket_id = $ticket->add([
           "name"    => "testIsFromSupportAgent",
           "content" => "testIsFromSupportAgent",
        ]);
        $this->integer($ticket_id);

        // Create test user
        $rand = mt_rand();
        $user = new User();
        $users_id = $user->add([
           'name' => "testIsFromSupportAgent$rand",
           'password' => 'testIsFromSupportAgent',
           'password2' => 'testIsFromSupportAgent',
           '_profiles_id' => getItemByTypeName('Profile', $profile, true),
           '_entities_id' => getItemByTypeName('Entity', '_test_root_entity', true),
        ]);
        $this->integer($users_id)->isGreaterThan(0);

        // Insert a followup
        $fup = new CoreITILFollowup();
        $fup_id = $fup->add([
           'content'  => "testIsFromSupportAgent",
           'users_id' => $users_id,
           'items_id' => $ticket_id,
           'itemtype' => "Ticket",
        ]);
        $this->integer($fup_id);
        $this->boolean($fup->getFromDB($fup_id))->isTrue();

        // Remove any roles that may have been set after insert
        $DB->delete(Ticket_User::getTable(), ['tickets_id' => $ticket_id]);
        $this->array($ticket->getITILActors())->hasSize(0);

        // Insert roles
        $tuser = new Ticket_User();
        foreach ($roles as $role) {
            $this->integer($tuser->add([
               'tickets_id' => $ticket_id,
               'users_id'   => $users_id,
               'type'       => $role,
            ]));
        }

        // Execute test
        $result = $fup->isFromSupportAgent();
        $this->boolean($result)->isEqualTo($expected);

        // Reset conf
        $CFG_GLPI['use_notifications'] = $old_conf;
    }

    public function testScreenshotConvertedIntoDocument()
    {

        $this->login(); // must be logged as Document_Item uses Session::getLoginUserID()

        // Test uploads for item creation
        $ticket = new \Ticket();
        $ticket->add([
           'name' => $this->getUniqueString(),
           'content' => 'test',
        ]);
        $this->boolean($ticket->isNewItem())->isFalse();

        $base64Image = base64_encode(file_get_contents(__DIR__ . '/../fixtures/uploads/foo.png'));
        $user = getItemByTypeName('User', TU_USER, true);
        $filename = '5e5e92ffd9bd91.11111111image_paste22222222.png';
        $instance = new \ITILFollowup();
        $input = [
           'users_id' => $user,
           'items_id' => $ticket->getID(),
           'itemtype' => 'Ticket',
           'name'    => 'a followup',
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
        $this->boolean($instance->isNewItem())->isFalse();
        $expected = 'a href=&quot;/front/document.send.php?docid=';
        $this->string($instance->fields['content'])->contains($expected);

        // Test uploads for item update
        $base64Image = base64_encode(file_get_contents(__DIR__ . '/../fixtures/uploads/bar.png'));
        $filename = '5e5e92ffd9bd91.44444444image_paste55555555.png';
        copy(__DIR__ . '/../fixtures/uploads/bar.png', GLPI_TMP_DIR . '/' . $filename);
        $success = $instance->update([
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
        $this->boolean($success)->isTrue();
        $expected = 'a href=&quot;/front/document.send.php?docid=';
        $this->string($instance->fields['content'])->contains($expected);
    }

    public function testCkeditorUploadPlaceholderConvertedIntoDocument()
    {

        $this->login(); // must be logged as Document_Item uses Session::getLoginUserID()

        $ticket = new \Ticket();
        $ticket->add([
           'name' => $this->getUniqueString(),
           'content' => 'test',
        ]);
        $this->boolean($ticket->isNewItem())->isFalse();

        $user = getItemByTypeName('User', TU_USER, true);
        $tag = '3e29dffe-0237ea21-5e5e7034b1d1a1.ckeditortag';
        $filename = '5e5e92ffd9bd91.77777777image_ck_editor88888888.png';

        copy(__DIR__ . '/../fixtures/uploads/foo.png', GLPI_TMP_DIR . '/' . $filename);

        $instance = new \ITILFollowup();
        $instance->add([
           'users_id' => $user,
           'items_id' => $ticket->getID(),
           'itemtype' => 'Ticket',
           'name'    => 'a followup',
           'content' => '&lt;p&gt;before&lt;/p&gt;&lt;figure class=&quot;image&quot; data-glpi-doc-tag=&quot;' . $tag . '&quot;&gt;&lt;img src=&quot;blob:http://itsm-main.local/followup-add&quot; width=&quot;12&quot; height=&quot;12&quot; /&gt;&lt;/figure&gt;',
           '_content' => [
              $filename,
           ],
           '_tag_content' => [
              $tag,
           ],
           '_prefix_content' => [
              '5e5e92ffd9bd91.77777777',
           ]
        ]);
        $this->boolean($instance->isNewItem())->isFalse();
        $expected = 'a href=&quot;/front/document.send.php?docid=';
        $this->string($instance->fields['content'])->contains($expected);
        $this->string($instance->fields['content'])->notContains('data-glpi-doc-tag');
        $this->string($instance->fields['content'])->notContains('blob:http://itsm-main.local/followup-add');

        $updated_tag = '3e29dffe-0237ea21-5e5e7034b1d1a1.ckeditortag2';
        $updated_filename = '5e5e92ffd9bd91.99999999image_ck_editor11111111.png';

        copy(__DIR__ . '/../fixtures/uploads/bar.png', GLPI_TMP_DIR . '/' . $updated_filename);

        $success = $instance->update([
           'id' => $instance->getID(),
           'content' => '&lt;p&gt;after&lt;/p&gt;&lt;figure class=&quot;image&quot; data-glpi-doc-tag=&quot;' . $updated_tag . '&quot;&gt;&lt;img src=&quot;blob:http://itsm-main.local/followup-update&quot; width=&quot;12&quot; height=&quot;12&quot; /&gt;&lt;/figure&gt;',
           '_content' => [
              $updated_filename,
           ],
           '_tag_content' => [
              $updated_tag,
           ],
           '_prefix_content' => [
              '5e5e92ffd9bd91.99999999',
           ]
        ]);
        $this->boolean($success)->isTrue();
        $this->string($instance->fields['content'])->contains($expected);
        $this->string($instance->fields['content'])->notContains('data-glpi-doc-tag');
        $this->string($instance->fields['content'])->notContains('blob:http://itsm-main.local/followup-update');
    }

    public function testUploadDocuments()
    {

        $this->login(); // must be logged as Document_Item uses Session::getLoginUserID()

        // Test uploads for item creation
        $ticket = new \Ticket();
        $ticket->add([
           'name' => $this->getUniqueString(),
           'content' => 'test',
        ]);
        $this->boolean($ticket->isNewItem())->isFalse();

        $user = getItemByTypeName('User', TU_USER, true);
        // Test uploads for item creation
        $filename = '5e5e92ffd9bd91.11111111' . 'foo.txt';
        $instance = new \ITILFollowup();
        $input = [
           'users_id' => $user,
           'items_id' => $ticket->getID(),
           'itemtype' => 'Ticket',
           'name'    => 'a followup',
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
        $this->boolean($instance->isNewItem())->isFalse();
        $this->string($instance->fields['content'])->contains('testUploadDocuments');
        $count = (new \DBUtils())->countElementsInTable(\Document_Item::getTable(), [
           'itemtype' => 'ITILFollowup',
           'items_id' => $instance->getID(),
        ]);
        $this->integer($count)->isEqualTo(1);

        // Test uploads for item update (adds a 2nd document)
        $filename = '5e5e92ffd9bd91.44444444bar.txt';
        copy(__DIR__ . '/../fixtures/uploads/bar.png', GLPI_TMP_DIR . '/' . $filename);
        $success = $instance->update([
           'id' => $instance->getID(),
           'content' => 'update testUploadDocuments',
           '_filename' => [
              $filename,
           ],
           '_tag_filename' => [
              '3e29dffe-0237ea21-5e5e7034b1ffff.33333333',
           ],
           '_prefix_filename' => [
              '5e5e92ffd9bd91.44444444',
           ]
        ]);
        $this->boolean($success)->isTrue();
        $this->string($instance->fields['content'])->contains('update testUploadDocuments');
        $count = (new \DBUtils())->countElementsInTable(\Document_Item::getTable(), [
           'itemtype' => 'ITILFollowup',
           'items_id' => $instance->getID(),
        ]);
        $this->integer($count)->isEqualTo(2);
    }
}
