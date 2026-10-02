<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RuleRepository;
use itsmng\Database\UnsupportedCriteria;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/rule-collections.php /path/to/test-config\n");
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
class PortabilityRule extends RuleTicket
{
}
class PortabilityRuleCollection extends RuleTicketCollection
{
}
class PortabilityOrderRule extends Rule
{
}
class PortabilityOrderRuleCollection extends RuleCollection
{
}
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$savedSession = $_SESSION;
$savedFiles = $_FILES;
$importFile = null;
$savedCache = $GLPI_CACHE;
$GLPI_CACHE = new \Glpi\Cache\SimpleCache(new \Laminas\Cache\Storage\Adapter\Memory(), GLPI_CACHE_DIR, false);
$connection = $DB->getDoctrineConnection();
$connection->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $repo = static fn () => new RuleRepository(Orm::create($DB));
    $records = static fn () => new RecordRepository(Orm::create($DB));
    $stamp = 'Rule collection ' . bin2hex(random_bytes(5));
    $literal = "O'Reilly\\path_% 東京";
    $parent = $fixtures->create('glpi_entities', ['name' => $stamp, 'level' => 1]);
    $child = $fixtures->create('glpi_entities', ['name' => $stamp . ' child', 'entities_id' => $parent, 'level' => 2]);
    $foreign = $fixtures->create('glpi_entities', ['name' => $stamp . ' foreign', 'level' => 1]);
    $add = static fn (int $entity, int $rank, int $condition, bool $recursive, bool $active = true) => $fixtures->create('glpi_rules', [
        'sub_type' => PortabilityRule::class, 'entities_id' => $entity, 'ranking' => $rank, 'condition' => $condition,
        'is_recursive' => $recursive, 'is_active' => $active, 'name' => $literal, 'match' => Rule::AND_MATCHING,
    ]);
    $rootRule = $add(0, 1, 3, true);
    $parentRule = $add($parent, 2, 1, true);
    $nonrecursive = $add($parent, 3, 2, false);
    $childRule = $add($child, 4, 3, true);
    $foreignRule = $add($foreign, 5, 1, false);
    $inactive = $add($child, 6, 1, false, false);
    $criterion = $fixtures->create('glpi_rulecriterias', ['rules_id' => $rootRule, 'criteria' => 'NAME', 'pattern' => $literal]);
    $fixtures->create('glpi_rulecriterias', ['rules_id' => $rootRule, 'criteria' => 'NAME', 'pattern' => $literal]);
    $fixtures->create('glpi_rulecriterias', ['rules_id' => $parentRule, 'criteria' => 'COMMENT', 'pattern' => 'parent']);
    $fixtures->create('glpi_rulecriterias', ['rules_id' => $nonrecursive, 'criteria' => 'UPDATE_ONLY']);
    $fixtures->create('glpi_rulecriterias', ['rules_id' => $inactive, 'criteria' => 'INACTIVE']);
    $action = $fixtures->create('glpi_ruleactions', ['rules_id' => $rootRule, 'action_type' => 'assign', 'field' => 'impact', 'value' => $literal]);
    $fixtures->create('glpi_ruleactions', ['rules_id' => $rootRule, 'action_type' => 'assign', 'field' => 'impact', 'value' => $literal]);
    $fixtures->create('glpi_ruleactions', ['rules_id' => $rootRule, 'field' => 'users_id_validate', 'value' => '0']);
    $collection = new PortabilityRuleCollection();
    $collection->setEntity($child);
    $SQL_TOTAL_REQUEST = 0;
    verify($collection->getCollectionSize() === 4 && $collection->getCollectionSize(false) === 2, 'Count includes inactive direct rules and only recursive ancestors');
    $collection->getCollectionPart(['limit' => 1, 'start' => 1]);
    verify(array_column(array_map(static fn ($rule) => $rule->fields, $collection->RuleList->list), 'id') === [$parentRule], 'Entity level precedes rank; page limits apply before hydration');
    $collection->getCollectionDatas(1, 1, 1);
    verify(array_column(array_map(static fn ($rule) => $rule->fields, $collection->RuleList->list), 'id') === [$rootRule, $parentRule, $childRule], 'Active inherited rules retain any-bit condition masks');
    $collection->setEntity($parent);
    verify($collection->getCollectionSize(true, 0, 1) === 4, 'Child collection includes both parent and child rules');
    verify($collection->prepareInputDataForTestProcess(1) === ['COMMENT', 'NAME']
        && $collection->prepareInputDataForTestProcess(2) === ['NAME', 'UPDATE_ONLY'], 'Criteria discovery joins active parent rules, deduplicates fields and filters masks');
    verify($collection->getFieldsToLookFor() === ['comment', 'name', 'update_only'], 'Preview fields remain lowercase');
    $criteria = (new RuleCriteria())->getRuleCriterias($rootRule);
    $actions = (new RuleAction())->getRuleActions($rootRule);
    verify(count($criteria) === 2 && $criteria[0]->fields['id'] === $criterion && $criteria[0]->fields['pattern'] === $literal
        && count($actions) === 3 && $actions[0]->fields['id'] === $action && $actions[0]->fields['value'] === $literal, 'Mapped children retain ordered IDs and literal payloads');
    verify((new RuleAction())->getAlreadyUsedForRuleID($rootRule, PortabilityRule::class) === ['impact' => 'impact'], 'Validation fields remain reusable');
    $rules = (new PortabilityRule())->getRulesForCriteria(['field' => 'impact', 'value' => addslashes($literal)]);
    verify(count($rules) === 1 && $rules[0]->fields['id'] === $rootRule && count($rules[0]->actions) === 3, 'Action lookup binds literal values and returns each mapped parent once');
    verify($SQL_TOTAL_REQUEST === 0, 'Rule reads use no legacy query adapter');
    try {
        $repo()->matching([new QueryExpression('1=1')]);
        throw new LogicException('Raw rule SQL accepted');
    } catch (UnsupportedCriteria) {
    }
    // Independent order collection exercises model callbacks and intervening ranks.
    $ordered = [];
    foreach ([1, 2, 3, 4] as $rank) {
        $ordered[] = $fixtures->create('glpi_rules', ['sub_type' => PortabilityOrderRule::class, 'ranking' => $rank, 'name' => $literal, 'condition' => $rank === 2 ? 2 : 1]);
    }
    $order = new PortabilityOrderRuleCollection();
    $ranks = static fn () => array_column($repo()->matching(['sub_type' => PortabilityOrderRule::class], 'ranking'), 'id');
    verify((new PortabilityOrderRule())->getNextRanking() === 5, 'Next rank uses only its collection');
    foreach (['up', 'down'] as $direction) {
        ob_start();
        verify($order->changeRuleOrder($ordered[$direction === 'up' ? 2 : 0], $direction, 1), 'Skip a neighbor excluded by the condition mask');
        ob_end_clean();
        verify($ranks() === ($direction === 'up' ? [$ordered[1], $ordered[2], $ordered[0], $ordered[3]] : [$ordered[2], $ordered[0], $ordered[1], $ordered[3]]), 'Intervening ranks shift in both directions');
        foreach ($ordered as $index => $id) {
            (new \itsmng\Database\MappedStorage($DB))->update('glpi_rules', $id, ['ranking' => $index + 1]);
        }
    }
    ob_start();
    verify($order->changeRuleOrder($ordered[3], 'up', 1), 'Condition-filtered reorder');
    ob_end_clean();
    verify($ranks() === [$ordered[0], $ordered[1], $ordered[3], $ordered[2]], 'Neighbor condition filter chooses the preceding matching rule');
    verify($order->moveRule($ordered[0], 0, 'after') && $ranks() === [$ordered[1], $ordered[3], $ordered[2], $ordered[0]], 'Move to collection end shifts intervening ranks');
    verify($order->moveRule($ordered[0], 0, 'before') && $ranks() === [$ordered[0], $ordered[1], $ordered[3], $ordered[2]], 'Move to collection beginning shifts intervening ranks');
    verify($order->moveRule($ordered[2], $ordered[1], 'before') && $ranks() === [$ordered[0], $ordered[2], $ordered[1], $ordered[3]], 'Move before a selected rule');
    verify(!$order->changeRuleOrder($ordered[0], 'invalid'), 'Invalid order action is closed');
    verify($records()->find('glpi_rules', 'id', $ordered[0])['name'] === $literal, 'Rank changes do not rewrite unrelated escaped names');
    $order->deleteRuleOrder(2);
    verify(array_column($repo()->matching(['sub_type' => PortabilityOrderRule::class], 'id'), 'ranking') === [1, 2, 2, 3], 'Gap closing decrements only higher ranks in its subtype');
    verify($records()->find('glpi_rules', 'id', $foreignRule)['ranking'] === 5, 'Other collections retain their ranking');
    $names = [];
    foreach ([null, $literal, $literal] as $name) {
        $names[] = $fixtures->create('glpi_rules', ['sub_type' => 'RuleNameOrderingFixture', 'name' => $name]);
    }
    foreach (['ASC', 'DESC'] as $direction) {
        $where = ['sub_type' => 'RuleNameOrderingFixture'];
        $expected = $direction === 'ASC' ? $names : [$names[1], $names[2], $names[0]];
        $page = $repo()->matching($where, 'name ' . $direction, 2);
        $last = $repo()->matching($where, 'name ' . $direction, 2, 2);
        verify(array_column(array_merge($page, $last), 'id') === $expected, 'NULL name ordering and tied name page boundaries agree across providers');
    }
    // Replacement and disabling support both criteria patterns and action values.
    $replacement = $fixtures->create('glpi_entities', ['name' => $stamp . ' replacement']);
    $entityAction = $fixtures->create('glpi_ruleactions', ['rules_id' => $parentRule, 'field' => 'entities_id', 'value' => (string)$parent]);
    $entityCriteria = $fixtures->create('glpi_rulecriterias', ['rules_id' => $childRule, 'criteria' => 'entities_id', 'pattern' => (string)$parent]);
    $levelActions = [];
    foreach ([SlaLevel::class, OlaLevel::class] as $class) {
        $level = new $class();
        $levelId = $fixtures->create($level->getTable(), ['name' => $stamp . $class]);
        $actionTable = getTableForItemType($level->getRuleActionClass());
        $actionId = $fixtures->create($actionTable, [$level->getRuleIdField() => $levelId, 'field' => 'entities_id', 'value' => (string)$parent]);
        $found = $level->getRulesForCriteria(['field' => 'entities_id', 'value' => (string)$parent]);
        verify(count($found) === 1 && $found[0] instanceof $class && $found[0]->fields['id'] === $levelId
            && $found[0]->actions[0]->fields['id'] === $actionId, 'Specialized action lookup follows its actual SLA/OLA parent instead of glpi_rules');
        $levelActions[] = [$actionTable, $actionId];
    }
    verify($repo()->entityActionCount([PortabilityRule::class], $parent) === 1 && $repo()->entityActionCount([], $parent) === 0, 'Entity action count uses owning rule association');
    $entity = new Entity();
    verify($entity->getFromDB($parent), 'Load selected dropdown');
    $entity->input['_replace_by'] = $replacement;
    Rule::cleanForItemAction($entity);
    Rule::cleanForItemCriteria($entity);
    verify($records()->find('glpi_ruleactions', 'id', $entityAction)['value'] === (string)$replacement
        && $records()->find('glpi_rulecriterias', 'id', $entityCriteria)['pattern'] === (string)$replacement, 'Mapped payload replacement retains scalar string storage');
    foreach ($levelActions as [$table, $id]) {
        verify($records()->find($table, 'id', $id)['value'] === (string)$replacement, 'Dropdown replacement includes mapped SLA/OLA action variants');
    }
    verify($entity->getFromDB($replacement), 'Load replacement dropdown');
    $entity->input = [];
    Rule::cleanForItemAction($entity);
    Rule::cleanForItemCriteria($entity);
    verify(!$records()->find('glpi_rules', 'id', $parentRule)['is_active'] && !$records()->find('glpi_rules', 'id', $childRule)['is_active'], 'Purged dropdown disables referenced rules through model updates');
    // A group with multiple users appears once; empty groups are absent.
    $group = $fixtures->create('glpi_groups', ['name' => $stamp]);
    $emptyGroup = $fixtures->create('glpi_groups', ['name' => $stamp . ' empty']);
    foreach ([1, 2] as $index) {
        $user = $fixtures->create('glpi_users', ['name' => $stamp . $index]);
        $fixtures->create('glpi_groups_users', ['groups_id' => $group, 'users_id' => $user]);
    }
    $groups = (new \itsmng\Database\Repository\GroupMembershipRepository(Orm::create($DB)))->groupsWithMembers();
    verify(count(array_keys($groups, $group, true)) === 1 && !in_array($emptyGroup, $groups, true), 'Validation dropdown selects groups with members without fanout');
    // XML dropdown names arrive without legacy SQL escaping. A missing entity
    // keeps the preview in this process so the resolved names can be inspected.
    $category = $fixtures->create('glpi_itilcategories', ['name' => $literal, 'completename' => $literal]);
    $encoded = htmlspecialchars($literal, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $xml = '<rules><rule><sub_type>PortabilityRule</sub_type><entities_id>Missing import entity</entities_id><name>Import fixture</name><uuid>rule-import-fixture</uuid>'
        . '<rulecriteria><criteria>itilcategories_id</criteria><condition>0</condition><pattern>' . $encoded . '</pattern></rulecriteria>'
        . '<ruleaction><field>itilcategories_id</field><action_type>assign</action_type><value>' . $encoded . '</value></ruleaction></rule></rules>';
    $importFile = tempnam(dirname(GLPI_CONFIG_DIR), 'rule-import-');
    file_put_contents($importFile, $xml);
    $_FILES['xml_file'] = ['tmp_name' => $importFile, 'size' => strlen($xml), 'error' => UPLOAD_ERR_OK];
    $_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
    ob_start();
    RuleCollection::previewImportRules();
    ob_end_clean();
    verify($_SESSION['glpi_import_rules']['rule'][0]['rulecriteria'][0]['pattern'] === $category
        && $_SESSION['glpi_import_rules']['rule'][0]['ruleaction'][0]['value'] === $category
        && $_SESSION['glpi_import_rules_refused'][0] === ['entity' => true], 'XML criterion/action dropdown names bind quotes and backslashes literally');
} finally {
    $connection->rollBack();
    $_SESSION = $savedSession;
    $GLPI_CACHE = $savedCache;
    $_FILES = $savedFiles;
    if ($importFile !== null) {
        unlink($importFile);
    }
}
echo $DB->getProvider() . ": mapped rule collections, recursive visibility, masks, children, rank changes and dropdown cleanup passed.\n";
