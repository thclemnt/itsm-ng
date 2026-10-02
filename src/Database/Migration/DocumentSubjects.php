<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

/** Frozen 20261001 document subjects; entity zero is a selected real root. */
final class DocumentSubjects extends TypedItemMigration
{
    public const VERSION = '20261001_document_subjects';

    protected function tables(): array
    {
        return ['glpi_documents_items'];
    }

    protected static function column(string $target): string
    {
        return match ($target) {
            'documents' => 'linked_documents_id',
            'entities' => 'subject_entities_id',
            'users' => 'subject_users_id',
            default => parent::column($target),
        };
    }

    protected static function minimumId(string $kind): int
    {
        return $kind === 'Entity' ? 0 : 1;
    }

    protected static function targets(): array
    {
        return [
            'Budget' => 'budgets',
            'CartridgeItem' => 'cartridgeitems',
            'Change' => 'changes',
            'Computer' => 'computers',
            'ConsumableItem' => 'consumableitems',
            'Contact' => 'contacts',
            'Contract' => 'contracts',
            'Document' => 'documents',
            'Entity' => 'entities',
            'KnowbaseItem' => 'knowbaseitems',
            'Monitor' => 'monitors',
            'NetworkEquipment' => 'networkequipments',
            'Peripheral' => 'peripherals',
            'Phone' => 'phones',
            'Printer' => 'printers',
            'Problem' => 'problems',
            'Project' => 'projects',
            'ProjectTask' => 'projecttasks',
            'Reminder' => 'reminders',
            'Software' => 'softwares',
            'Line' => 'lines',
            'SoftwareLicense' => 'softwarelicenses',
            'Supplier' => 'suppliers',
            'Ticket' => 'tickets',
            'User' => 'users',
            'Certificate' => 'certificates',
            'Cluster' => 'clusters',
            'ITILFollowup' => 'itilfollowups',
            'ITILSolution' => 'itilsolutions',
            'ChangeTask' => 'changetasks',
            'ProblemTask' => 'problemtasks',
            'TicketTask' => 'tickettasks',
            'Appliance' => 'appliances',
        ];
    }
}
