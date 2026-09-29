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

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/// Class SLALevel
class SlaLevel_Ticket extends CommonDBTM
{
    private static function serviceRepository(): \itsmng\Database\Repository\ServiceLevelRepository
    {
        global $DB;
        return new \itsmng\Database\Repository\ServiceLevelRepository(\itsmng\Database\Orm::create($DB), 'sla');
    }

    public static function getTypeName($nb = 0)
    {
        return __('SLA level for Ticket');
    }


    /**
     * Retrieve an item from the database
     *
     * @param $ID        ID of the item to get
     * @param $slatype
     *
     * @since 9.1 2 mandatory parameters
     *
     * @return true if succeed else false
    **/
    public function getFromDBForTicket($ID, $slaType)
    {
        $rows = self::serviceRepository()->scheduled((int)$ID, (int)$slaType, limit: 1);
        return $rows ? $this->getFromDB($rows[0]['id']) : false;
    }


    /**
     * Delete entries for a ticket
     *
     * @param $tickets_id    Ticket ID
     * @param $type          Type of SLA
     *
     * @since 9.1 2 parameters mandatory
     *
     * @return void
    **/
    public function deleteForTicket($tickets_id, $slaType)
    {
        foreach (self::serviceRepository()->scheduled((int)$tickets_id, (int)$slaType) as $row) {
            $this->delete(['id' => $row['id']]);
        }
    }


    /**
     * Give cron information
     *
     * @param $name : task's name
     *
     * @return array of information
    **/
    public static function cronInfo($name)
    {

        switch ($name) {
            case 'slaticket':
                return ['description' => __('Automatic actions of SLA')];
        }
        return [];
    }


    /**
     * Cron for ticket's automatic close
     *
     * @param $task : CronTask object
     *
     * @return integer (0 : nothing done - 1 : done)
    **/
    public static function cronSlaTicket(CronTask $task)
    {
        $rows = self::serviceRepository()->scheduled(before: new \DateTimeImmutable());
        foreach ($rows as $row) {
            self::doLevelForTicket($row, $row['type']);
        }
        $task->setVolume(count($rows));
        return $rows ? 1 : 0;
    }


    /**
     * Do a specific SLAlevel for a ticket
     *
     * @param $data          array data of an entry of slalevels_tickets
     * @param $slaType             Type of sla
     *
     * @since 9.1   2 parameters mandatory
     *
     * @return void
    **/
    public static function doLevelForTicket(array $data, $slaType)
    {

        $ticket         = new Ticket();
        $slalevelticket = new self();

        // existing ticket and not deleted
        if (
            $ticket->getFromDB($data['tickets_id'])
            && !$ticket->isDeleted()
        ) {
            // search all actors of a ticket
            foreach ($ticket->getUsers(CommonITILActor::REQUESTER) as $user) {
                $ticket->fields['_users_id_requester'][] = $user['users_id'];
            }
            foreach ($ticket->getUsers(CommonITILActor::ASSIGN) as $user) {
                $ticket->fields['_users_id_assign'][] = $user['users_id'];
            }
            foreach ($ticket->getUsers(CommonITILActor::OBSERVER) as $user) {
                $ticket->fields['_users_id_observer'][] = $user['users_id'];
            }

            foreach ($ticket->getGroups(CommonITILActor::REQUESTER) as $group) {
                $ticket->fields['_groups_id_requester'][] = $group['groups_id'];
            }
            foreach ($ticket->getGroups(CommonITILActor::ASSIGN) as $group) {
                $ticket->fields['_groups_id_assign'][] = $group['groups_id'];
            }
            foreach ($ticket->getGroups(CommonITILActor::OBSERVER) as $group) {
                $ticket->fields['_groups_id_observer'][] = $group['groups_id'];
            }

            foreach ($ticket->getSuppliers(CommonITILActor::ASSIGN) as $supplier) {
                $ticket->fields['_suppliers_id_assign'][] = $supplier['suppliers_id'];
            }

            $slalevel = new SlaLevel();
            $sla      = new SLA();
            // Check if sla datas are OK
            list($dateField, $slaField) = SLA::getFieldNames($slaType);
            if (($ticket->fields[$slaField] > 0)) {
                if ($ticket->fields['status'] == CommonITILObject::CLOSED) {
                    // Drop line when status is closed
                    $slalevelticket->delete(['id' => $data['id']]);
                } elseif ($ticket->fields['status'] != CommonITILObject::SOLVED) {
                    // No execution if ticket has been taken into account
                    if (
                        !(($slaType == SLM::TTO)
                          && ($ticket->fields['takeintoaccount_delay_stat'] > 0))
                    ) {
                        // If status = solved : keep the line in case of solution not validated
                        $input['id']           = $ticket->getID();
                        $input['_auto_update'] = true;

                        if (
                            $slalevel->getRuleWithCriteriasAndActions($data['slalevels_id'], 1, 1)
                            && $sla->getFromDB($ticket->fields[$slaField])
                        ) {
                            $doit = true;
                            if (count($slalevel->criterias)) {
                                $doit = $slalevel->checkCriterias($ticket->fields);
                            }
                            // Process rules
                            if ($doit) {
                                $input = $slalevel->executeActions($input, [], $ticket->fields);
                            }
                        }

                        // Put next level in todo list
                        if (
                            $next = $slalevel->getNextSlaLevel(
                                $ticket->fields[$slaField],
                                $data['slalevels_id']
                            )
                        ) {
                            $sla->addLevelToDo($ticket, $next);
                        }
                        // Action done : drop the line
                        $slalevelticket->delete(['id' => $data['id']]);

                        $ticket->update($input);
                    } else {
                        // Drop line
                        $slalevelticket->delete(['id' => $data['id']]);
                    }
                }
            } else {
                // Drop line
                $slalevelticket->delete(['id' => $data['id']]);
            }
        } else {
            // Drop line
            $slalevelticket->delete(['id' => $data['id']]);
        }
    }


    /**
     * Replay all task needed for a specific ticket
     *
     * @param $tickets_id Ticket ID
     * @param $slaType Type of sla
     *
     * @since 9.1    2 parameters mandatory
     *
     */
    public static function replayForTicket($tickets_id, $slaType)
    {
        $repository = self::serviceRepository();
        do {
            $rows = $repository->scheduled((int)$tickets_id, (int)$slaType, new \DateTimeImmutable(), 2);
            if (count($rows) === 1) {
                self::doLevelForTicket($rows[0], $slaType);
            }
        } while (count($rows) === 1);
    }
}
