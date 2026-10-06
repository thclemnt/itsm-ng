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

/* Test for inc/savedsearch_user.class.php */

class SavedSearch_User extends DbTestCase
{
    public function testGetDefault()
    {
        global $DB;
        // needs a user
        // let's use TU_USER
        $this->login();
        $uid =  getItemByTypeName('User', TU_USER, true);

        // with no default bookmark
        $this->boolean(
            (bool)\SavedSearch_User::getDefault($uid, 'Ticket')
        )->isFalse();
        $this->boolean(\SavedSearch_User::getDefault(0, 'Ticket'))->isFalse();
        $this->boolean(\SavedSearch_User::getDefault(-1, 'Ticket'))->isFalse();

        // now add a bookmark on Ticket view
        $bk = new \SavedSearch();
        $this->boolean(
            (bool)$bk->add(['name'         => 'All my tickets',
                              'type'         => 1,
                              'itemtype'     => 'Ticket',
                              'users_id'     => $uid,
                              'is_private'   => 1,
                              'entities_id'  => 0,
                              'is_recursive' => 1,
                              'url'         => 'front/ticket.php?itemtype=Ticket&sort=2&order=DESC&start=0&criteria[0][field]=5&criteria[0][searchtype]=equals&criteria[0][value]='.$uid
                             ])
        )->isTrue();

        $bk_id = $bk->fields['id'];

        $bk_user = new \SavedSearch_User();
        $this->boolean(
            (bool)$bk_user->add(['users_id' => $uid,
                                   'itemtype' => 'Ticket',
                                   'savedsearches_id' => $bk_id
                                  ])
        )->isTrue();

        // should get a default bookmark
        $bk = \SavedSearch_User::getDefault($uid, 'Ticket');
        $this->array(
            $bk
        )->isEqualTo(['itemtype'         => 'Ticket',
                      'sort'             => '2',
                      'order'            => 'DESC',
                      'savedsearches_id' => $bk_id,
                      'criteria'         => [0 => ['field' => '5',
                                                   'searchtype' => 'equals',
                                                   'value' => $uid
                                                  ]
                                            ],
                      'reset'            => 'reset',
                     ]);

        $reader = \itsmng\Database\Orm::create($DB);
        $defaults = new \itsmng\Database\Repository\SavedSearchRepository($reader);
        $projection = $defaults->defaultParameters((int)$uid, 'Ticket');
        $this->array($projection)->hasSize(4)->hasKeys(['id', 'query', 'type', 'itemtype']);
        $this->integer($projection['id'])->isIdenticalTo((int)$bk_id);
        $this->array($reader->getUnitOfWork()->getIdentityMap())->isEmpty();
        $this->variable($defaults->defaultParameters(0, 'Ticket'))->isNull();
        $this->variable($defaults->defaultParameters(PHP_INT_MAX, 'Ticket'))->isNull();
        $this->variable($defaults->defaultParameters((int)$uid, 'Computer'))->isNull();
        $this->boolean(\SavedSearch_User::getDefault($uid, 'Computer'))->isFalse();

        // Public parameter loading still populates the complete model, including non-projected fields.
        $bookmark = new \SavedSearch();
        $this->array($bookmark->getParameters($bk_id))->isIdenticalTo($bk);
        $this->string($bookmark->fields['name'])->isIdenticalTo('All my tickets');
        $this->integer((int)$bookmark->fields['users_id'])->isIdenticalTo((int)$uid);

        // Every operation observes writes; the projection must not become a default-row cache.
        $this->boolean($bookmark->update(['id' => $bk_id, 'query' => 'itemtype=Ticket&sort=1&order=ASC']))->isTrue();
        $fresh = \SavedSearch_User::getDefault($uid, 'Ticket');
        $this->string($fresh['sort'])->isIdenticalTo('1');
        $this->string($fresh['order'])->isIdenticalTo('ASC');
        $this->string($fresh['reset'])->isIdenticalTo('reset');

        // Unknown stored item types still reject; URI/AllAssets keeps its original parsing contract.
        $this->boolean($bookmark->update(['id' => $bk_id, 'itemtype' => 'MissingSavedSearchItemType']))->isTrue();
        $this->boolean(\SavedSearch_User::getDefault($uid, 'Ticket'))->isFalse();
        $this->boolean($bookmark->update(['id' => $bk_id, 'itemtype' => 'AllAssets', 'type' => \SavedSearch::URI,
            'query' => 'itemtype=AllAssets&custom%5B0%5D=one&custom%5B1%5D=two']))->isTrue();
        $this->array(\SavedSearch_User::getDefault($uid, 'Ticket'))->isIdenticalTo([
            'itemtype' => 'AllAssets', 'custom' => ['one', 'two'], 'savedsearches_id' => (int)$bk_id,
        ]);
        $this->boolean($bookmark->update(['id' => $bk_id, 'query' => null]))->isTrue();
        $this->array(\SavedSearch_User::getDefault($uid, 'Ticket'))->isIdenticalTo(['savedsearches_id' => (int)$bk_id]);
        $this->boolean($bk_user->delete(['id' => $bk_user->fields['id']], true))->isTrue();
        $this->boolean(\SavedSearch_User::getDefault($uid, 'Ticket'))->isFalse();

    }

}
