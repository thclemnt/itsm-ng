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

use CommonDBConnexity;
use DbTestCase;
use Doctrine\DBAL\Types\Types;
use ReflectionProperty;
use Entity as LegacyEntity;
use Entity_RSSFeed;
use Notification as LegacyNotification;
use Notification_NotificationTemplate as LegacyNotification_NotificationTemplate;
use itsmng\Database\Entity\Entity;
use itsmng\Database\Entity\Notification;
use itsmng\Database\Entity\NotificationNotificationTemplate;
use itsmng\Database\Entity\NotificationTemplate;
use itsmng\Domain\NotificationDeliveryService;
use itsmng\Database\Orm;

/* Test for inc/notification_notificationtemplate.class.php */

class Notification_NotificationTemplate extends DbTestCase
{
    public function testBindingReadsReturnCurrentRowsAndRetainIndependentOwners(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', false);
        $manager = Orm::create($DB);
        $connection = $manager->getConnection();
        $depth = $connection->getTransactionNestingLevel();
        try {
            $parents = $templates = [];
            foreach ([0, 1] as $index) {
                $parent = new Notification();
                $parent->entities = $manager->find(Entity::class, $_SESSION['glpiactive_entity']);
                $parent->name = 'Binding owner ' . $index . ' ' . $this->getUniqueString();
                $parent->itemtype = 'Ticket';
                $parent->event = 'new';
                $template = new NotificationTemplate();
                $template->name = 'Binding template ' . $index . ' ' . $this->getUniqueString();
                $template->itemtype = 'Ticket';
                $manager->persist($parent);
                $manager->persist($template);
                $parents[] = $parent;
                $templates[] = $template;
            }
            $bindings = [];
            foreach ([[0, 0, 'mailing'], [0, 1, 'ajax'], [1, 0, 'mailing']] as [$parent, $template, $mode]) {
                $binding = new NotificationNotificationTemplate();
                $binding->notifications = $parents[$parent];
                $binding->notificationtemplates = $templates[$template];
                $binding->mode = $mode;
                $manager->persist($binding);
                $bindings[] = $binding;
            }
            $manager->flush();
            $service = new NotificationDeliveryService($DB);
            $snapshot = $service->bindingsForNotification($parents[0]->id);
            $byNotification = array_column($snapshot, null, 'id');
            $byTemplate = array_column($service->bindingsForTemplate($templates[0]->id), null, 'id');
            $this->integer(count($byNotification))->isIdenticalTo(2);
            $this->integer(count($byTemplate))->isIdenticalTo(2);
            foreach ([$bindings[0], $bindings[1]] as $binding) {
                $row = $byNotification[$binding->id];
                $this->integer(count($row))->isIdenticalTo(4);
                $this->integer($row['id'])->isIdenticalTo($binding->id);
                $this->integer($row['notifications_id'])->isIdenticalTo($parents[0]->id);
                $this->integer($row['notificationtemplates_id'])->isIdenticalTo($binding->notificationtemplates->id);
                $this->string($row['mode'])->isIdenticalTo($binding->mode);
            }
            foreach ([$bindings[0], $bindings[2]] as $binding) {
                $row = $byTemplate[$binding->id];
                $this->integer($row['id'])->isIdenticalTo($binding->id);
                $this->integer($row['notifications_id'])->isIdenticalTo($binding->notifications->id);
                $this->integer($row['notificationtemplates_id'])->isIdenticalTo($templates[0]->id);
                $this->string($row['mode'])->isIdenticalTo($binding->mode);
            }
            $this->array($service->bindingsForNotification(-1))->isEmpty();
            $this->array($service->bindingsForTemplate(-1))->isEmpty();

            // Native writers do not update an independently managed caller entity.
            $this->integer($connection->update('glpi_notifications_notificationtemplates', ['mode' => 'mail-current'],
                ['id' => $bindings[0]->id], ['mode' => Types::STRING, 'id' => Types::BIGINT]))->isIdenticalTo(1);
            $creations = new ReflectionProperty(Orm::class, 'unitsOfWork');
            $before = $creations->getValue();
            for ($repeat = 0; $repeat < 2; ++$repeat) {
                $currentNotification = array_column($service->bindingsForNotification($parents[0]->id), null, 'id');
                $currentTemplate = array_column($service->bindingsForTemplate($templates[0]->id), null, 'id');
                $this->string($currentNotification[$bindings[0]->id]['mode'])->isIdenticalTo('mail-current');
                $this->string($currentTemplate[$bindings[0]->id]['mode'])->isIdenticalTo('mail-current');
                $this->string($currentNotification[$bindings[1]->id]['mode'])->isIdenticalTo('ajax');
                $this->array($service->bindingsForNotification(-1))->isEmpty();
                $this->array($service->bindingsForTemplate(-1))->isEmpty();
            }
            $this->integer($creations->getValue())->isIdenticalTo($before);
            $this->string($byNotification[$bindings[0]->id]['mode'])->isIdenticalTo('mailing');
            $this->array($snapshot)->isIdenticalTo(array_values($byNotification));
            $this->boolean($manager->contains($bindings[0]))->isTrue();
            $this->string($bindings[0]->mode)->isIdenticalTo('mailing');
            $this->boolean($manager->contains($parents[0]))->isTrue();

            Orm::read($DB, function ($parentManager) use ($service, $bindings, $parents, $templates): void {
                $reference = $parentManager->getReference(Notification::class, $parents[0]->id);
                $this->boolean($parentManager->contains($reference))->isTrue();
                $notificationRows = array_column($service->bindingsForNotification($parents[0]->id), null, 'id');
                $templateRows = array_column($service->bindingsForTemplate($templates[0]->id), null, 'id');
                $this->string($notificationRows[$bindings[0]->id]['mode'])->isIdenticalTo('mail-current');
                $this->string($templateRows[$bindings[0]->id]['mode'])->isIdenticalTo('mail-current');
                $this->boolean($parentManager->contains($reference))->isTrue();
            });
            $this->integer($connection->getTransactionNestingLevel())->isIdenticalTo($depth);
            $this->boolean($manager->contains($bindings[0]))->isTrue();
        } finally {
            $manager->clear();
        }
    }

    public function testOptionalAttachmentSelection(): void
    {
        global $DB;
        $this->login();
        $this->setEntity('_test_root_entity', false);
        $session = $_SESSION;
        $manager = Orm::create($DB);
        $depth = $manager->getConnection()->getTransactionNestingLevel();
        try {
            $parent = new Notification();
            $parent->entities = $manager->find(Entity::class, $_SESSION['glpiactive_entity']);
            $parent->name = 'Optional template ' . bin2hex(random_bytes(8));
            $parent->itemtype = 'Ticket';
            $parent->event = 'new';
            $this->object($parent->entities)->isInstanceOf(Entity::class);
            $manager->persist($parent);
            $manager->flush();
            $this->boolean((new LegacyNotification())->can($parent->id, UPDATE))->isTrue();
            $missing = (int)$manager->createQuery('SELECT MAX(t.id) FROM itsmng\\Database\\Entity\\NotificationTemplate t')->getSingleScalarResult() + 100;
            foreach ([null, '', 0, '0'] as $selection) {
                $input = ['notifications_id' => $parent->id, 'notificationtemplates_id' => $selection,
                    'mode' => LegacyNotification_NotificationTemplate::MODE_MAIL];
                $this->boolean((new LegacyNotification_NotificationTemplate())->can(-1, CREATE, $input))->isTrue();
                $input['notifications_id'] = 0;
                $this->boolean((new LegacyNotification_NotificationTemplate())->can(-1, CREATE, $input))->isFalse();
            }
            $input = ['notifications_id' => $parent->id, 'notificationtemplates_id' => $missing,
                'mode' => LegacyNotification_NotificationTemplate::MODE_MAIL];
            $this->boolean((new LegacyNotification_NotificationTemplate())->can(-1, CREATE, $input))->isFalse();

            // Zero is a valid identity for the actual root entity, not an empty selection.
            $relation = new Entity_RSSFeed();
            $relation->fields['entities_id'] = 0;
            $root = null;
            $this->boolean($relation->canConnexityItem(
                'canUpdateItem',
                'canUpdate',
                CommonDBConnexity::DONT_CHECK_ITEM_RIGHTS,
                'Entity',
                'entities_id',
                $root
            ))->isTrue();
            $this->object($root)->isInstanceOf(LegacyEntity::class);
            $this->integer((int)$root->getID())->isEqualTo(0);
            $this->integer($manager->getConnection()->getTransactionNestingLevel())->isEqualTo($depth);
        } finally {
            $_SESSION = $session;
            $manager->clear();
        }
    }

    public function testGetTypeName()
    {
        $this->string(\Notification_NotificationTemplate::getTypeName(0))->isIdenticalTo('Templates');
        $this->string(\Notification_NotificationTemplate::getTypeName(1))->isIdenticalTo('Template');
        $this->string(\Notification_NotificationTemplate::getTypeName(2))->isIdenticalTo('Templates');
        $this->string(\Notification_NotificationTemplate::getTypeName(10))->isIdenticalTo('Templates');
    }

    public function testGetTabNameForItem()
    {
        $n_nt = new \Notification_NotificationTemplate();
        $this->boolean($n_nt->getFromDB(1))->isTrue();

        $notif = new \Notification();
        $this->boolean($notif->getFromDB($n_nt->getField('notifications_id')))->isTrue();

        $_SESSION['glpishow_count_on_tabs'] = 1;

        //not logged => no ACLs
        $name = $n_nt->getTabNameForItem($notif);
        $this->string($name)->isIdenticalTo('');

        $this->login();
        $name = $n_nt->getTabNameForItem($notif);
        $this->string($name)->isIdenticalTo('Templates <sup class=\'tab_nb\'>1</sup>');

        $_SESSION['glpishow_count_on_tabs'] = 0;
        $name = $n_nt->getTabNameForItem($notif);
        $this->string($name)->isIdenticalTo('Templates');

        $toadd = $n_nt->fields;
        unset($toadd['id']);
        $toadd['mode'] = \Notification_NotificationTemplate::MODE_XMPP;
        $this->integer((int)$n_nt->add($toadd))->isGreaterThan(0);

        $_SESSION['glpishow_count_on_tabs'] = 1;
        $name = $n_nt->getTabNameForItem($notif);
        $this->string($name)->isIdenticalTo('Templates <sup class=\'tab_nb\'>2</sup>');
    }

    public function testShowForNotification()
    {
        $notif = new \Notification();
        $this->boolean($notif->getFromDB(1))->isTrue();

        //not logged, no ACLs
        $this->output(
            function () use ($notif) {
                \Notification_NotificationTemplate::showForNotification($notif);
            }
        )->isEmpty();

        $this->login();

        $this->output(
            function () use ($notif) {
                \Notification_NotificationTemplate::showForNotification($notif);
            }
        )->contains('Alert Tickets not closed')
           ->contains('Template')
           ->contains('Email');
    }

    public function testGetName()
    {
        $n_nt = new \Notification_NotificationTemplate();
        $this->boolean($n_nt->getFromDB(1))->isTrue();
        $this->integer($n_nt->getName())->isIdenticalTo(1);
    }

    public function testShowForFormNotLogged()
    {
        //not logged, no ACLs
        $this->output(
            function () {
                $n_nt = new \Notification_NotificationTemplate();
                $n_nt->showForm(1);
            }
        )->isEmpty();
    }

    public function testShowForForm()
    {
        \ProfileRight::updateProfileRights(
            4,
            [
                'notification'         => READ | UPDATE | CREATE,
                'notificationtemplate' => READ | UPDATE,
            ]
        );

        $this->login();

        $n_nt = new \Notification_NotificationTemplate();
        $notification = new \Notification();
        $this->integer(\Session::haveRight('notification', UPDATE))->isGreaterThan(0);
        $this->integer(\Session::haveRight('notificationtemplate', UPDATE))->isGreaterThan(0);

        $notifications_id = (int)$notification->add([
            'name'       => __FUNCTION__,
            'itemtype'   => 'Ticket',
            'event'      => 'new',
            'entities_id' => $_SESSION['glpiactive_entity'],
        ]);
        $this->integer($notifications_id)->isGreaterThan(0);
        $this->boolean($notification->can($notifications_id, UPDATE))->isTrue();

        $input = ['notifications_id' => $notifications_id];
        $this->boolean($n_nt->can(-1, CREATE, $input))->isTrue();

        $this->output(
            function () use ($n_nt, $notifications_id) {
                $n_nt->showForm(0, ['notifications_id' => $notifications_id]);
            }
        )->contains('<form')
           ->contains('name="mode"')
           ->contains('show_templates');
    }

    public function testGetMode()
    {
        $mode = \Notification_NotificationTemplate::getMode(\Notification_NotificationTemplate::MODE_MAIL);
        $expected = [
           'label'  => 'Email',
           'from'   => 'core'
        ];
        $this->array($mode)->isIdenticalTo($expected);

        $mode = \Notification_NotificationTemplate::getMode('not_a_mode');
        $this->string($mode)->isIdenticalTo(NOT_AVAILABLE);
    }

    public function testGetModes()
    {
        $modes = \Notification_NotificationTemplate::getModes();
        $this->array($modes)
           ->hasKey(\Notification_NotificationTemplate::MODE_MAIL)
           ->hasKey(\Notification_NotificationTemplate::MODE_AJAX);

        //register new mode
        \Notification_NotificationTemplate::registerMode(
            'test_mode',
            'A test label',
            'anyplugin'
        );
        $modes = \Notification_NotificationTemplate::getModes();
        $this->array($modes)->hasKey('test_mode');
    }

    public function testGetSpecificValueToDisplay()
    {
        $n_nt = new \Notification_NotificationTemplate();
        $display = $n_nt->getSpecificValueToDisplay('id', 1);
        $this->string($display)->isEmpty();

        $display = $n_nt->getSpecificValueToDisplay('mode', \Notification_NotificationTemplate::MODE_AJAX);
        $this->string($display)->isIdenticalTo('Browser');

        $display = $n_nt->getSpecificValueToDisplay('mode', 'not_a_mode');
        $this->string($display)->isIdenticalTo('not_a_mode (N/A)');
    }

    public function testGetSpecificValueToSelect()
    {
        $n_nt = new \Notification_NotificationTemplate();
        $select = $n_nt->getSpecificValueToSelect('id', 1);
        $this->string($select)->isEmpty();

        $select = $n_nt->getSpecificValueToSelect('mode', 'a_name', \Notification_NotificationTemplate::MODE_AJAX);
        //FIXME: why @selected?
        /** $this->string($select)->matches(
           "<select name='a_name' id='dropdown_a_name459469776' size='1'><option value='mailing'>Email</option><option value='ajax' selected>Browser</option><option value='chat'>Chat</option></select><script type=\"text/javascript\">
//<![CDATA[

$(function() {
           $('#dropdown_a_name459469776').select2({

              width: '',
              dropdownAutoWidth: true,
              quietMillis: 100,
              minimumResultsForSearch: 10,
              matcher: function(params, data) {
                 // store last search in the global var
                 query = params;

                 // If there are no search terms, return all of the data
                 if ($.trim(params.term) === '') {
                    return data;
                 }

                 var searched_term = getTextWithoutDiacriticalMarks(params.term);
                 var data_text = typeof(data.text) === 'string'
                    ? getTextWithoutDiacriticalMarks(data.text)
                    : '';
                 var select2_fuzzy_opts = {
                    pre: '<span class=\"select2-rendered__match\">',
                    post: '</span>',
                 };

                 if (data_text.indexOf('>') !== -1 || data_text.indexOf('<') !== -1) {
                    // escape text, if it contains chevrons (can already be escaped prior to this point :/)
                    data_text = jQuery.fn.select2.defaults.defaults.escapeMarkup(data_text);
                 }

                 // Skip if there is no 'children' property
                 if (typeof data.children === 'undefined') {
                    var match  = fuzzy.match(searched_term, data_text, select2_fuzzy_opts);
                    if (match == null) {
                       return false;
                    }
                    data.rendered_text = match.rendered_text;
                    data.score = match.score;
                    return data;
                 }

                 // `data.children` contains the actual options that we are matching against
                 // also check in `data.text` (optgroup title)
                 var filteredChildren = [];

                 $.each(data.children, function (idx, child) {
                    var child_text = typeof(child.text) === 'string'
                       ? getTextWithoutDiacriticalMarks(child.text)
                       : '';

                    if (child_text.indexOf('>') !== -1 || child_text.indexOf('<') !== -1) {
                       // escape text, if it contains chevrons (can already be escaped prior to this point :/)
                       child_text = jQuery.fn.select2.defaults.defaults.escapeMarkup(child_text);
                    }

                    var match_child = fuzzy.match(searched_term, child_text, select2_fuzzy_opts);
                    var match_text  = fuzzy.match(searched_term, data_text, select2_fuzzy_opts);
                    if (match_child !== null || match_text !== null) {
                       if (match_text !== null) {
                          data.score         = match_text.score;
                          data.rendered_text = match_text.rendered;
                       }

                       if (match_child !== null) {
                          child.score         = match_child.score;
                          child.rendered_text = match_child.rendered;
                       }
                       filteredChildren.push(child);
                    }
                 });

                 // If we matched any of the group's children, then set the matched children on the group
                 // and return the group object
                 if (filteredChildren.length) {
                    var modifiedData = $.extend({}, data, true);
                    modifiedData.children = filteredChildren;

                    // You can return modified objects from here
                    // This includes matching the `children` how you want in nested data sets
                    return modifiedData;
                 }

                 // Return `null` if the term should not be displayed
                 return null;
              },
              templateResult: templateResult,
              templateSelection: templateSelection,
           })
           .bind('setValue', function(e, value) {
              $('#dropdown_a_name459469776').val(value).trigger('change');
           })
           $('label[for=dropdown_a_name459469776]').on('click', function(){ $('#dropdown_a_name459469776').select2('open'); });
        });

//]]>
</script>"
        );**/
    }

    public function testGetModeClass()
    {
        $class = \Notification_NotificationTemplate::getModeClass(\Notification_NotificationTemplate::MODE_MAIL);
        $this->string($class)->isIdenticalTo('NotificationMailing');

        $class = \Notification_NotificationTemplate::getModeClass(\Notification_NotificationTemplate::MODE_MAIL, 'event');
        $this->string($class)->isIdenticalTo('NotificationEventMailing');

        $class = \Notification_NotificationTemplate::getModeClass(\Notification_NotificationTemplate::MODE_MAIL, 'setting');
        $this->string($class)->isIdenticalTo('NotificationMailingSetting');

        //register new mode
        \Notification_NotificationTemplate::registerMode(
            'testmode',
            'A test label',
            'anyplugin'
        );

        $class = \Notification_NotificationTemplate::getModeClass('testmode');
        $this->string($class)->isIdenticalTo('PluginAnypluginNotificationTestmode');

        $class = \Notification_NotificationTemplate::getModeClass('testmode', 'event');
        $this->string($class)->isIdenticalTo('PluginAnypluginNotificationEventTestmode');

        $class = \Notification_NotificationTemplate::getModeClass('testmode', 'setting');
        $this->string($class)->isIdenticalTo('PluginAnypluginNotificationTestmodeSetting');
    }

    public function testHasActiveMode()
    {
        global $CFG_GLPI;
        $this->boolean(\Notification_NotificationTemplate::hasActiveMode())->isFalse();
        $CFG_GLPI['notifications_ajax'] = 1;
        $this->boolean(\Notification_NotificationTemplate::hasActiveMode())->isTrue();
        $CFG_GLPI['notifications_ajax'] = 0;
    }
}
