<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\Glpi\Api\Deprecated;

use GLPITestCase;
use Glpi\Api\Deprecated\TicketFollowup as DeprecatedTicketFollowup;

class TicketFollowup extends GLPITestCase
{
    public function testDeleteFieldDistinguishesNullFromAbsent(): void
    {
        $mapper = new DeprecatedTicketFollowup();
        foreach ([null, 0, false, '', 'value'] as $value) {
            $fields = ['removed' => $value, 'retained' => null];
            $mapper->deleteField($fields, 'removed');
            $mapper->deleteField($fields, 'absent');
            $this->array($fields)->isIdenticalTo(['retained' => null]);

            $fields = (object)['removed' => $value, 'retained' => null];
            $mapper->deleteField($fields, 'removed');
            $mapper->deleteField($fields, 'absent');
            $this->array((array)$fields)->isIdenticalTo(['retained' => null]);
        }
    }

    public function testTicketProjectionPreservesTheDeprecatedFields(): void
    {
        $expected = [
            'id' => 11, 'tickets_id' => 12, 'date' => null,
            'users_id' => 13, 'users_id_editor' => null, 'content' => 'Followup',
            'is_private' => false, 'requesttypes_id' => null, 'date_mod' => null,
            'date_creation' => null, 'timeline_position' => 0, 'links' => [],
        ];
        $current = $expected;
        unset($current['tickets_id']);
        $current += [
            'itemtype' => 'Ticket', 'items_id' => 12, 'tickets_id' => 12,
            'problems_id' => null, 'changes_id' => null,
            'sourceitems_id' => null, 'sourceof_items_id' => null,
        ];
        $mapper = new DeprecatedTicketFollowup();
        $mapped = $mapper->mapCurrentToDeprecatedFields($current);
        $this->array($mapped)->isEqualTo($expected)->hasSize(12);
        $this->array($current)->hasKeys(['problems_id', 'changes_id', 'sourceitems_id', 'sourceof_items_id']);

        unset($current['problems_id'], $current['changes_id'], $current['sourceitems_id'], $current['sourceof_items_id']);
        $this->array($mapper->mapCurrentToDeprecatedFields($current))->isEqualTo($expected);
    }
}
