<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/** Search semantics on real, disposable MySQL and PostgreSQL installations. */
$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/search.php /path/to/test-config\n");
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

function searchRows(string $type, array $criteria, array $params = [], array $columns = []): array
{
    return Search::getDatas($type, $params + [
        'criteria' => $criteria, 'sort' => 1, 'order' => 'ASC', 'reset' => 'reset',
        'list_limit' => 50,
    ], $columns);
}
function ids(array $data): array
{
    return array_map('intval', array_column($data['data']['rows'] ?? [], 'id'));
}
function criterion(int $id, string $value, string $searchtype = 'equals', string $link = 'AND'): array
{
    return ['field' => $id, 'value' => $value, 'searchtype' => $searchtype, 'link' => $link];
}
function insertFixture(string $table, array $values): int
{
    global $DB;
    $DB->insertOrDie($table, $values);
    return $DB->insertId();
}
$prefix = 'Search contract ' . bin2hex(random_bytes(5));
$DB->beginTransaction();
try {
    $tickets = [];
    foreach (range(0, 3) as $i) {
        $tickets[] = insertFixture('glpi_tickets', ['name' => $prefix, 'content' => 'fixture ' . $i, 'entities_id' => 0, 'status' => 1]);
    }
    $base = [criterion(1, $prefix, 'contains')];
    $first = searchRows('Ticket', $base, ['list_limit' => 2]);
    $second = searchRows('Ticket', $base, ['list_limit' => 2, 'start' => 2]);
    verify(ids($first) === array_slice($tickets, 0, 2) && ids($second) === array_slice($tickets, 2), 'Stable ID tie-break across pages.');
    verify($first['data']['totalcount'] === 4, 'Count is independent of LIMIT.');
    $clamped = searchRows('Ticket', $base, ['list_limit' => 2, 'start' => 99]);
    verify($clamped['search']['start'] === 2 && ids($clamped) === array_slice($tickets, 2), 'Clamp before hydration.');
    verify(ids(searchRows('Ticket', $base, ['list_limit' => 0])) === $tickets, 'Zero limit means all results.');
    verify(ids(searchRows('Ticket', $base, ['list_limit' => 1, 'export_all' => 1])) === $tickets, 'Export is not truncated.');
    $data = Search::prepareDatasForSearch('Ticket', ['criteria' => $base]);
    Search::constructSQL($data);
    Search::constructData($data, true);
    verify($data['data']['totalcount'] === 4 && empty($data['data']['rows']), 'Count-only skips hydration.');

    $groups = [];
    foreach (['Alpha', 'Beta'] as $label) {
        $groups[] = insertFixture('glpi_groups', ['name' => $prefix . $label, 'completename' => $prefix . $label, 'entities_id' => 0]);
    }
    foreach ([[$tickets[0], $groups[0]], [$tickets[0], $groups[1]], [$tickets[1], $groups[1]]] as [$ticket, $group]) {
        insertFixture('glpi_groups_tickets', ['tickets_id' => $ticket, 'groups_id' => $group, 'type' => CommonITILActor::ASSIGN]);
    }
    $followupOption = null;
    $countOption = null;
    foreach (Search::getOptions('Ticket') as $id => $option) {
        if (!is_array($option)) {
            continue;
        }
        if (($option['table'] ?? '') === 'glpi_itilfollowups') {
            if ($option['field'] === 'content') {
                $followupOption = $id;
            }
            if (($option['datatype'] ?? '') === 'count') {
                $countOption = $id;
            }
        }
    }
    // Search option 8 is the assigned group, shared with existing saved searches.
    $groupsOption = 8;
    $match = searchRows('Ticket', [...$base, criterion($groupsOption, (string)$groups[0])]);
    verify(ids($match) === [$tickets[0]], 'Multivalue relation filter.');
    $raw = $DB->fetchAssoc($DB->query($match['sql']['search']));
    verify(str_contains($raw['ITEM_Ticket_8'], $prefix . 'Beta'), 'Filtering Alpha must not remove Beta from displayed groups.');
    verify(ids(searchRows('Ticket', [...$base, criterion(8, (string)$groups[0], 'notequals')])) === array_slice($tickets, 1), 'Negation excludes any matching relation and includes empty relations.');

    foreach ([[$tickets[0], 'old', '2024-01-01'], [$tickets[0], 'new', '2024-02-01'], [$tickets[1], 'one', '2024-01-01']] as [$ticket, $body, $date]) {
        insertFixture('glpi_itilfollowups', ['itemtype' => 'Ticket', 'items_id' => $ticket, 'content' => $body, 'date' => $date . ' 12:00:00', 'is_private' => 0]);
    }
    verify($countOption !== null && $followupOption !== null, 'Followup search metadata.');
    $mixed = [...$base, ['link' => 'AND', 'criteria' => [criterion($countOption, '>1'), criterion(2, (string)$tickets[2], 'equals', 'OR')]]];
    verify(ids(searchRows('Ticket', $mixed)) === [$tickets[0], $tickets[2]], 'Nested OR combines aggregate and scalar predicates.');
    $negative = [...$base, ['link' => 'AND NOT', 'criteria' => [criterion($countOption, '>1'), criterion(2, (string)$tickets[2], 'equals', 'OR')]]];
    verify(ids(searchRows('Ticket', $negative)) === [$tickets[1], $tickets[3]], 'Negated aggregate/scalar group.');
    $ordered = searchRows('Ticket', $base, [], [$followupOption, 8]);
    $raw = $DB->fetchAssoc($DB->query($ordered['sql']['search']));
    verify(strpos($raw['ITEM_Ticket_' . $followupOption], 'new') < strpos($raw['ITEM_Ticket_' . $followupOption], 'old'), 'Followups are ordered newest first despite join multiplication.');
    verify(substr_count($raw['ITEM_Ticket_' . $followupOption], 'new') === 1, 'Ordered aggregation removes join duplicates.');

    $computer = insertFixture('glpi_computers', ['name' => $prefix, 'entities_id' => 0]);
    insertFixture('glpi_items_tickets', ['tickets_id' => $tickets[0], 'itemtype' => 'Computer', 'items_id' => $computer]);
    $meta = ['meta' => true, 'itemtype' => 'Computer'] + criterion(1, $prefix, 'contains');
    verify(ids(searchRows('Ticket', [...$base, $meta])) === [$tickets[0]], 'Meta criteria participate in eligibility.');
    verify(ids(searchRows('Ticket', $base, ['metacriteria' => [$meta]])) === [$tickets[0]], 'Legacy meta parameters are normalized.');

    $metaDeadline = ['meta' => true, 'itemtype' => 'Ticket'] + criterion(188, '^$', 'contains');
    verify(ids(searchRows('Computer', [...$base, $metaDeadline])) === [$computer], 'Meta computed date remains a scalar inside grouped projections.');

    $dialect = new \itsmng\Search\Provider\Dialect($DB);
    $pluginFields = (new \itsmng\Search\Provider\SelectList())->add($dialect->literal('portable hook'), 'hook_value');
    $pluginProjection = (new \itsmng\Search\Provider\SelectList())->addHookResult($pluginFields, $dialect);
    $pluginRow = $DB->fetchAssoc($DB->query('SELECT ' . $pluginProjection->sql($dialect)));
    verify($pluginRow['hook_value'] === 'portable hook', 'Structured plugin projections work on both engines.');

    $zeta = insertFixture('glpi_users', ['name' => $prefix . ' A', 'realname' => 'Zeta', 'is_active' => 1]);
    $alpha = insertFixture('glpi_users', ['name' => $prefix . ' Z', 'realname' => 'Alpha', 'is_active' => 1]);
    $DB->update('glpi_tickets', ['users_id_recipient' => $zeta], ['id' => $tickets[0]]);
    $DB->update('glpi_tickets', ['users_id_recipient' => $alpha], ['id' => $tickets[1]]);
    verify(ids(searchRows('Ticket', $base, ['sort' => 22])) === [$tickets[2], $tickets[3], $tickets[1], $tickets[0]], 'Recipient sorting follows display names and NULL policy, not login or ID.');
    verify(ids(searchRows('Ticket', $base, ['sort' => 22, 'order' => 'DESC'])) === [$tickets[0], $tickets[1], $tickets[2], $tickets[3]], 'Descending name ordering retains deterministic NULL ties.');

    $precedence = [...$base, ['link' => 'AND', 'criteria' => [
        criterion(2, (string)$tickets[0]), criterion(2, (string)$tickets[1], 'equals', 'OR'),
        criterion(2, (string)$tickets[2], 'equals', 'AND'),
    ]]];
    verify(ids(searchRows('Ticket', $precedence)) === [$tickets[0]], 'Saved-search AND precedence is preserved.');
    verify(ids(searchRows('Ticket', [...$base, criterion($countOption, '0')])) === [$tickets[2], $tickets[3]], 'Zero aggregate matches empty relations.');
    verify(ids(searchRows('Ticket', [...$base, criterion(82, '0')])) === $tickets, 'Computed boolean deadline filter.');
    $next = searchRows('Ticket', $base, ['sort' => 188], [188]);
    verify(ids($next) === $tickets, 'Nullable next escalation uses typed dates and stable ordering.');

    // Entity scope applies outside the complete OR tree, including aggregate leaves.
    $foreignEntity = (int)(new Entity())->add(['name' => $prefix, 'entities_id' => 0]);
    verify($foreignEntity > 0, 'Foreign entity fixture.');
    $foreignTicket = insertFixture('glpi_tickets', ['name' => $prefix, 'entities_id' => $foreignEntity, 'status' => 1]);
    $session = $_SESSION;
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [0];
    $_SESSION['glpiactiveentities_string'] = '0';
    try {
        verify(ids(searchRows('Ticket', [...$base, criterion(2, (string)$foreignTicket, 'equals', 'OR')])) === $tickets, 'OR cannot escape entity scope.');
    } finally {
        $_SESSION = $session;
        $DB->delete('glpi_tickets', ['id' => $foreignTicket]);
    }

    $DB->update('glpi_tickets', ['is_deleted' => 1], ['id' => $tickets[3]]);
    verify(ids(searchRows('Ticket', $base)) === array_slice($tickets, 0, 3), 'Deleted records excluded.');
    verify(ids(searchRows('Ticket', $base, ['is_deleted' => 1])) === [$tickets[3]], 'Deleted-only search.');
    $DB->update('glpi_computers', ['is_template' => 1], ['id' => $computer]);
    verify(ids(searchRows('Computer', $base)) === [], 'Templates excluded.');

    $user = insertFixture('glpi_users', ['name' => $prefix, 'is_active' => 0]);
    insertFixture('glpi_profiles_users', ['users_id' => $user, 'profiles_id' => 4, 'entities_id' => 0]);
    $inactive = searchRows('User', [...$base, criterion(8, '0')], [], [8]);
    verify(ids($inactive) === [$user], 'Native false boolean criteria.');
    $row = $DB->fetchAssoc($DB->query($inactive['sql']['search']));
    verify((int)$row['ITEM_User_8'] === 0, 'False is displayed as false.');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": search semantics, pagination, aggregation and boolean contract passed.\n";
