<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\DropdownChoiceContext;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/dropdown-choice-callers.php /path/to/test-config\n");
}
define('GLPI_ROOT', dirname(__DIR__, 2));
define('GLPI_CONFIG_DIR', realpath($directory));
require GLPI_ROOT . '/inc/includes.php';
require __DIR__ . '/FixtureRecords.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string)$error . "\n");
    exit(1);
});
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function validChoiceRequest(array $request): bool
{
    return Session::validateIDOR($request + ['_dropdown_choice_context' => DropdownChoiceContext::encode($request)]);
}
function capture(callable $render): string
{
    ob_start();
    try {
        $render();
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}
function renderedChoice(array $select, array $fields = []): string
{
    expandSelect($select, $fields);
    return Twig::load(GLPI_ROOT . '/templates', false)->render('macros/inputs/select.twig', [
        'attributes' => $select + ['name' => 'current_choice', 'id' => 'current_choice'],
    ]);
}
function renderedTokens(string $html): array
{
    verify((bool)preg_match('/(?:const|var) choiceTokens = (\{[^;]+\});/', $html, $match), 'Rendered team script provides a per-kind policy token map');
    return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Disposable database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$session = $_SESSION;
$configuration = $CFG_GLPI;
$CFG_GLPI['use_notifications'] = false;
$_SESSION['glpishowallentities'] = false;
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $scope = $fixtures->create('glpi_entities', ['name' => 'Choice caller scope', 'entities_id' => 0, 'level' => 2]);
    $_SESSION['glpiactiveentities'] = [0, $scope];
    $inside = $fixtures->create('glpi_computers', ['name' => 'Scoped choice', 'entities_id' => $scope]);
    $outside = $fixtures->create('glpi_computers', ['name' => 'Other entity choice', 'entities_id' => 0]);
    $select = ['itemtype' => 'Computer', 'condition' => ['entities_id' => $scope, 'id' => [$inside, $outside]], 'right' => 'all'];
    expandSelect($select);
    $request = $select['ajax']['data'];
    verify(validChoiceRequest($request), 'Twig selects sign their actual request');
    verify(is_string($request['condition']) && $_SESSION['glpicondition'][$request['condition']] === ['id' => [$inside, $outside]], 'Twig condition is an immutable session key, not an unsanitized SQL array');
    $initialIds = [];
    array_walk_recursive($select['values'], static function ($label, $id) use (&$initialIds): void {
        $initialIds[] = (int)$id;
    });
    verify(in_array($inside, $initialIds, true) && !in_array($outside, $initialIds, true), 'Initial choices preserve the same entity restriction as subsequent AJAX requests');
    foreach (['entity_restrict' => 0, 'condition' => '', 'right' => 'own', 'displaywith' => ['serial'], 'permit_select_parent' => true, 'inactive_deleted' => 1, 'with_no_right' => 1, 'itemtype' => 'Printer'] as $field => $value) {
        verify(!validChoiceRequest(array_replace($request, [$field => $value])), 'Caller token rejects changed policy: ' . $field);
    }
    parse_str(http_build_query($request), $formEncodedRequest);
    verify(validChoiceRequest($formEncodedRequest), 'HTTP form encoding preserves the signed context');
    verify(validChoiceRequest(array_replace($request, ['searchText' => 'new search', 'page' => 2, 'value' => $inside, 'used' => [$inside], 'toadd' => [-1 => 'Extra']])), 'Paging, searching and selection remain mutable');
    $empty = ['itemtype' => 'Computer'];
    expandSelect($empty, ['entities_id' => []]);
    verify(count($empty['values']) === 1 && validChoiceRequest($empty['ajax']['data']), 'Explicit empty scope remains empty in initial and signed AJAX choices');

    // A preselected identifier never authorizes its label independently of choices.
    $html = renderedChoice(['itemtype' => 'Computer', 'value' => $outside], ['entities_id' => $scope]);
    verify(!str_contains($html, 'Other entity choice'), 'Current value outside requested scope does not expose its label');
    $html = renderedChoice(['itemtype' => 'Computer', 'value' => $inside], ['entities_id' => []]);
    verify(!str_contains($html, 'Scoped choice'), 'Current value cannot bypass an explicit empty scope');
    $_SESSION['glpiactiveentities'] = [0];
    $html = renderedChoice(['itemtype' => 'Computer', 'value' => $inside], ['entities_id' => $scope]);
    verify(!str_contains($html, 'Scoped choice'), 'Current value outside session grants does not expose its label');
    $_SESSION['glpiactiveentities'] = [0, $scope];
    $html = renderedChoice(['itemtype' => 'Computer', 'value' => $inside, 'condition' => ['id' => $outside]], ['entities_id' => $scope]);
    verify(!str_contains($html, 'Scoped choice'), 'Current value excluded by the stored condition remains excluded');
    $html = renderedChoice(['itemtype' => 'Computer', 'value' => $inside], ['entities_id' => $scope]);
    verify((bool)preg_match('/<option value="' . $inside . '"[^>]*selected="selected"[^>]*>[^<]*Scoped choice/', $html), 'Authorized current value retains its selected label');
    $parent = $fixtures->create('glpi_locations', ['name' => 'Choice tree parent', 'completename' => 'Choice tree parent', 'entities_id' => $scope, 'level' => 1]);
    $location = $fixtures->create('glpi_locations', ['name' => 'Choice tree child', 'completename' => 'Choice tree parent > Choice tree child', 'locations_id' => $parent, 'entities_id' => $scope, 'level' => 2]);
    $html = renderedChoice(['itemtype' => 'Location', 'value' => $location], ['entities_id' => $scope]);
    verify((bool)preg_match('/<option value="' . $location . '"[^>]*selected="selected"[^>]*>[^<]*Choice tree child/', $html), 'Authorized tree current value retains the owning API tree label');

    $profile = $fixtures->create('glpi_profiles', ['name' => 'Choice grant', 'interface' => 'central']);
    $rightId = $fixtures->create('glpi_profilerights', ['profiles_id' => $profile, 'name' => 'ticket', 'rights' => Ticket::OWN]);
    $user = $fixtures->create('glpi_users', ['name' => 'Choice granted recipient']);
    $fixtures->create('glpi_profiles_users', ['users_id' => $user, 'profiles_id' => $profile, 'entities_id' => $scope]);
    $userChoice = ['itemtype' => 'User', 'value' => $user, 'right' => 'own_ticket', 'condition' => ['id' => $user]];
    $html = renderedChoice($userChoice, ['entities_id' => $scope]);
    verify(str_contains($html, 'Choice granted recipient') && (bool)preg_match('/<option value="' . $user . '"[^>]*selected="selected"/', $html), 'Current User choice honors its scoped role permission');
    $html = renderedChoice(array_replace($userChoice, ['condition' => ['id' => 0]]), ['entities_id' => $scope]);
    verify(!str_contains($html, 'Choice granted recipient'), 'User current value also obeys the owning choice API stored condition');
    $html = renderedChoice(array_replace($userChoice, ['right' => []]), ['entities_id' => $scope]);
    verify(!str_contains($html, 'Choice granted recipient'), 'Empty requested User rights remove a preselected label');
    (new \itsmng\Database\Repository\RecordWriter(\itsmng\Database\Orm::create($DB)))->update('glpi_profilerights', $rightId, ['rights' => 0]);
    $html = renderedChoice($userChoice, ['entities_id' => $scope]);
    verify(!str_contains($html, 'Choice granted recipient'), 'Revoked User right removes the current label without a direct-row fallback');
    $html = renderedChoice($userChoice, ['entities_id' => []]);
    verify(!str_contains($html, 'Choice granted recipient'), 'Empty User scope removes a preselected account label');

    $projectId = $fixtures->create('glpi_projects', ['name' => 'Choice project', 'entities_id' => $scope]);
    $project = new Project();
    verify($project->getFromDB($projectId), 'Load project');
    $html = capture(static fn () => $project->showTeam($project));
    $tokens = renderedTokens($html);
    verify(array_keys($tokens) === ProjectTeam::$available_types && str_contains($html, '_idor_token: choiceTokens[this.value]'), 'Project request selects the token belonging to its actual kind');
    foreach ($tokens as $kind => $token) {
        $request = ['itemtype' => $kind, '_idor_token' => $token, 'display_emptychoice' => 1];
        verify(validChoiceRequest($request) && !validChoiceRequest($request + ['entity_restrict' => $scope]), 'Project preserves session scope, bound independently per kind');
        verify(!validChoiceRequest(array_replace($request, ['itemtype' => 'Computer'])), 'Project token cannot substitute another kind');
    }
    foreach ([false, true] as $recursive) {
        $taskId = $fixtures->create('glpi_projecttasks', ['name' => 'Choice task', 'projects_id' => $projectId, 'entities_id' => $scope, 'is_recursive' => $recursive]);
        $task = new ProjectTask();
        verify($task->getFromDB($taskId), 'Load task');
        $html = capture(static fn () => $task->showTeam($task));
        $tokens = renderedTokens($html);
        verify((bool)preg_match('/entity_restrict: ([^\n]+),/', $html, $match), 'Task sends its explicit owner scope');
        $requestScope = json_decode(trim($match[1]), true, 512, JSON_THROW_ON_ERROR);
        $expected = $recursive ? array_values(getSonsOf('glpi_entities', $scope)) : $scope;
        verify($requestScope === $expected && str_contains($html, '_idor_token: choiceTokens[itemtype]'), 'Task preserves recursive and direct owner scopes');
        foreach ($tokens as $kind => $token) {
            $request = ['itemtype' => $kind, '_idor_token' => $token, 'entity_restrict' => $requestScope];
            verify(validChoiceRequest($request) && !validChoiceRequest(array_replace($request, ['entity_restrict' => 0])), 'Task token binds the exact rendered owner scope');
        }
    }

    $ticketId = $fixtures->create('glpi_tickets', ['name' => 'Choice ticket']);
    $html = capture(static fn () => (new Ticket())->showForm($ticketId));
    preg_match_all('/const params = (\{[^\n]+\});/', $html, $matches);
    $ticketRequest = null;
    foreach ($matches[1] as $json) {
        $params = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (($params['data']['itemtype'] ?? '') === 'Ticket') {
            $ticketRequest = $params['data'];
        }
    }
    verify(is_array($ticketRequest) && validChoiceRequest($ticketRequest), 'Actual linked-ticket form renders a signed request from its PHP producer');
    verify(str_contains($html, 'type: params.type,'), 'Selected-value initialization uses the declared AJAX method, retaining POST context tokens');
    verify($ticketRequest['entity_restrict'] === Session::getActiveEntity() && $ticketRequest['recursive'] === Session::getIsActiveEntityRecursive(), 'Linked-ticket selector retains its current-entity policy');
    verify(!validChoiceRequest(array_replace($ticketRequest, ['entity_restrict' => $scope])), 'Linked-ticket context cannot broaden after rendering');
    echo "Dropdown caller contexts passed\n";
} finally {
    $DB->rollBack();
    $_SESSION = $session;
    $CFG_GLPI = $configuration;
}
