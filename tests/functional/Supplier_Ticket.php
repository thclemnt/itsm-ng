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
use Supplier;
use Supplier_Ticket as LegacySupplierTicket;
use Ticket;

class Supplier_Ticket extends DbTestCase
{
    public function testIsSupplierEmailChecksTicketAssignments()
    {
        $this->login();

        $supplier = new Supplier();
        $supplier_id = $supplier->add([
           'name'        => 'supplier-ticket-' . $this->getUniqueString(),
           'entities_id' => 0,
           'email'       => 'supplier-ticket-' . mt_rand(1000, 9999) . '@example.com',
        ]);
        $this->integer((int)$supplier_id)->isGreaterThan(0);

        $ticket = new Ticket();
        $ticket_id = $ticket->add([
           'name'    => 'ticket-' . $this->getUniqueString(),
           'content' => 'content-' . $this->getUniqueString(),
        ]);
        $this->integer((int)$ticket_id)->isGreaterThan(0);

        $relation = new LegacySupplierTicket();
        $relation_id = $relation->add([
           'tickets_id'   => $ticket_id,
           'suppliers_id' => $supplier_id,
           'type'         => CommonITILActor::ASSIGN,
        ]);
        $this->integer((int)$relation_id)->isGreaterThan(0);

        $this->boolean($relation->isSupplierEmail($ticket_id, $supplier->fields['email']))->isTrue();
        $this->boolean($relation->isSupplierEmail($ticket_id, 'no-match@example.com'))->isFalse();

        global $DB;
        $connection = $DB->getDoctrineConnection();
        $session = $_SESSION;
        try {
            $_SESSION['glpiactiveentities'] = [];
            // Mail collection checks the known ticket relation, not interactive entity grants.
            $this->boolean($relation->isSupplierEmail($ticket_id, $supplier->fields['email']))->isTrue();
            $connection->update('glpi_suppliers', ['email' => 'current@example.com', 'is_deleted' => 1], ['id' => $supplier_id]);
            $this->boolean($relation->isSupplierEmail($ticket_id, $supplier->fields['email']))->isFalse();
            $this->boolean($relation->isSupplierEmail($ticket_id, 'current@example.com'))->isTrue();
            $this->boolean($relation->isSupplierEmail(PHP_INT_MAX, 'current@example.com'))->isFalse();
            $connection->update('glpi_suppliers_tickets', ['alternative_email' => 'alternative@example.com', 'type' => CommonITILActor::REQUESTER], ['id' => $relation_id]);
            $this->boolean($relation->isSupplierEmail($ticket_id, 'alternative@example.com'))->isFalse();
            $this->boolean($relation->isSupplierEmail($ticket_id, 'current@example.com'))->isTrue();
            $connection->update('glpi_suppliers', ['email' => null], ['id' => $supplier_id]);
            $this->boolean($relation->isSupplierEmail($ticket_id, null))->isTrue();
            $this->boolean($relation->isSupplierEmail((string)$ticket_id, 'null'))->isTrue();
            $this->boolean($relation->isSupplierEmail($ticket_id, 'NuLl'))->isTrue();
            // Preserve the established LEFT JOIN/IS NULL behavior for an unbound actor.
            $connection->update('glpi_suppliers_tickets', ['suppliers_id' => null], ['id' => $relation_id]);
            $this->boolean($relation->isSupplierEmail($ticket_id, null))->isTrue();
            $this->boolean($relation->isSupplierEmail($ticket_id, 'alternative@example.com'))->isFalse();
            $connection->delete('glpi_suppliers_tickets', ['id' => $relation_id]);
            $this->boolean($relation->isSupplierEmail($ticket_id, null))->isFalse();
        } finally {
            $_SESSION = $session;
        }
    }
}
