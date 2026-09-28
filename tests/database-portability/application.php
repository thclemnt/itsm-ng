<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Real application workflow against a dedicated installation. No browser mocks. */
$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/application.php /path/to/test-config\n");
    exit(2);
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
class GlpitestSQLError extends RuntimeException
{
}
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Use a dedicated itsm_port_* database.');
$_SESSION['glpiextauth'] = 0;
$auth = new Auth();
verify($auth->login('itsm', 'itsm', true), 'Seeded administrator login.');
$name = 'Portability workflow ' . bin2hex(random_bytes(5));
$DB->beginTransaction();
try {
    $computer = new Computer();
    $computerId = $computer->add(['name' => $name, 'entities_id' => 0]);
    verify(is_int($computerId) && $computerId > 0, 'Asset creation through CommonDBTM.');
    verify($computer->update(['id' => $computerId, 'comment' => "Apostrophe: l\'ordinateur"]), 'Asset update.');
    verify($computer->getFromDB($computerId) && $computer->fields['comment'] === "Apostrophe: l'ordinateur", 'Asset reload.');

    $ticket = new Ticket();
    $ticketId = $ticket->add(['name' => $name, 'content' => 'Body', 'entities_id' => 0]);
    verify(is_int($ticketId) && $ticketId > 0, 'Ticket creation through rules and calendars.');
    foreach ([Ticket_User::class => 'users_id', Supplier_Ticket::class => 'suppliers_id'] as $actorClass => $actorField) {
        $actor = new $actorClass();
        verify((bool)$actor->add([
            'tickets_id' => $ticketId, $actorField => 0,
            'alternative_email' => 'anonymous@example.invalid',
            'type' => CommonITILActor::REQUESTER,
        ]), 'Anonymous email-only actors remain supported.');
    }
    $data = Search::getDatas('Ticket', [
        'criteria' => [['field' => 1, 'searchtype' => 'contains', 'value' => strtolower($name)]],
        'sort' => 1, 'order' => 'ASC',
    ]);
    verify(count($data['data']['rows'] ?? []) === 1, 'Filtered ticket search with default actor aggregates.');
    verify((int)$data['data']['rows'][0]['id'] === $ticketId, 'Search returns the created ticket.');
    foreach ([
        ['field' => 2, 'searchtype' => 'contains', 'value' => (string)$ticketId],
        ['field' => 15, 'searchtype' => 'equals', 'value' => substr($ticket->fields['date'], 0, 10)],
    ] as $criterion) {
        $data = Search::getDatas('Ticket', [
            'criteria' => [
                ['field' => 1, 'searchtype' => 'contains', 'value' => $name],
                ['link' => 'AND'] + $criterion,
            ], 'sort' => 1, 'order' => 'ASC', 'reset' => 'reset',
        ]);
        verify(count($data['data']['rows'] ?? []) === 1, 'Text search of numeric and date fields.');
    }

    $group = new Group();
    $groupId = $group->add(['name' => $name, 'entities_id' => 0]);
    verify(is_int($groupId) && $groupId > 0, 'Group creation.');
    $groups = Search::getDatas('Group', ['criteria' => [], 'sort' => 1, 'order' => 'ASC', 'reset' => 'reset']);
    verify(!empty($groups['data']['rows']), 'Unfiltered group list with access to all entities.');
    $membership = new Group_User();
    verify((bool)$membership->add(['groups_id' => $groupId, 'users_id' => 2]), 'Valid foreign-key association.');
    verify($group->delete(['id' => $groupId], true), 'Group purge hooks run before FK-restricted parent deletion.');
    verify(count($DB->request(['FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $groupId]])) === 0, 'Purge removed memberships.');
    verify($ticket->delete(['id' => $ticketId], true), 'Ticket purge.');
    verify($computer->delete(['id' => $computerId], true), 'Asset purge.');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": login, asset/ticket CRUD, search, relationship and purge workflow passed.\n";
