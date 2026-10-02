<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\ConsumableRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/consumables.php /path/to/test-config\n");
    exit(2);
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated test database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$DB->beginTransaction();
try {
    $fixtures = new FixtureRecords($DB);
    $child = (new Entity())->add(['name' => 'Consumable scope child', 'entities_id' => 0]);
    $model = $fixtures->create('glpi_consumableitems', ['name' => 'Scoped consumable model', 'entities_id' => 0]);
    $emptyModel = $fixtures->create('glpi_consumableitems', ['name' => 'Empty consumable model', 'entities_id' => 0]);
    $hiddenModel = $fixtures->create('glpi_consumableitems', ['name' => 'Hidden consumable model', 'entities_id' => $child]);
    $em = Orm::create($DB);
    $sharedId = 100 + max(
        (int)$em->createQuery('SELECT MAX(u.id) FROM ' . \itsmng\Database\Entity\User::class . ' u')->getSingleScalarResult(),
        (int)$em->createQuery('SELECT MAX(g.id) FROM ' . \itsmng\Database\Entity\Group::class . ' g')->getSingleScalarResult()
    );
    $em->clear();
    $user = $fixtures->create('glpi_users', ['id' => $sharedId, 'name' => 'Consumable recipient']);
    $group = $fixtures->create('glpi_groups', ['id' => $user, 'name' => 'Consumable group']);
    $unused = $fixtures->create('glpi_consumables', ['consumableitems_id' => $model, 'date_in' => null, 'entities_id' => $child]);
    $unused2 = $fixtures->create('glpi_consumables', ['consumableitems_id' => $model, 'date_in' => '2026-01-02']);
    $used = [];
    foreach ([['User', $user, '2026-01-01'], ['Group', $group, '2026-01-03'], ['Group', $group, '2026-01-02']] as [$type, $target, $date]) {
        $used[] = $fixtures->create('glpi_consumables', ['consumableitems_id' => $model, 'itemtype' => $type, 'items_id' => $target, 'date_out' => '2026-02-01', 'date_in' => $date]);
    }
    $fixtures->create('glpi_consumables', ['consumableitems_id' => $hiddenModel, 'entities_id' => 0]);
    $em = Orm::create($DB);
    $repository = new ConsumableRepository($em);
    $scope = ['entities_id' => 0, 'id' => [$model, $hiddenModel, $emptyModel]];
    $new = $repository->summary($scope, false);
    verify(count($new) === 1 && (int)$new[0]['consumableitems_id'] === $model && (int)$new[0]['count'] === 2, 'Stock summary follows model entity, ignoring cached child entity');
    $summary = $repository->summary($scope, true);
    $counts = array_column($summary, 'count', 'itemtype');
    verify(count($counts) === 2 && (int)$counts['User'] === 1 && (int)$counts['Group'] === 2, 'Recipients with overlapping numeric IDs remain distinct');
    verify(array_column($repository->forModel($model, false, 1, 0), 'id') === [$unused], 'Stock pagination places NULL entry dates first');
    verify(array_column($repository->forModel($model, false, 1, 1), 'id') === [$unused2], 'Stock pagination applies offset');
    verify(array_column($repository->forModel($model, true, 10, 0), 'id') === [$used[0], $used[2], $used[1]], 'Used stock orders by use date, entry date and ID');
    $em->clear();
    verify(Consumable::getTotalNumber($model) === 5 && Consumable::getOldNumber($model) === 3 && Consumable::getUnusedNumber($model) === 2, 'Mapped stock counts');
    verify(Consumable::isNew($unused) && !Consumable::isOld($unused) && Consumable::isOld($used[0]), 'Mapped stock state checks');
    verify(!Consumable::isNew(-1) && !Consumable::isOld(-1), 'Missing stock has no state');
    $consumable = new Consumable();
    verify(!$consumable->out($unused, '', $user) && !$consumable->out($unused, 'User', 0), 'Incomplete recipient is rejected');
    verify($consumable->out($unused, 'User', $user) && $consumable->getFromDB($unused), 'Give consumable');
    verify($consumable->fields['date_out'] !== null && $consumable->fields['itemtype'] === 'User' && (int)$consumable->fields['items_id'] === $user, 'Given stock stores recipient and date');
    verify($consumable->backToStock(['id' => $unused]) && $consumable->backToStock(['id' => $unused]), 'Repeated return retains existing successful-operation contract');
    verify($consumable->getFromDB($unused) && $consumable->fields['date_out'] === null && $consumable->fields['itemtype'] === 'User' && (int)$consumable->fields['items_id'] === $user, 'Returned stock retains last recipient history');

    $before = new DateTimeImmutable('2026-09-28 12:00:00');
    $cases = [];
    foreach (['old', 'boundary', 'recent', 'missing', 'other_type', 'deleted', 'disabled', 'foreign'] as $case) {
        $cases[$case] = $fixtures->create('glpi_consumableitems', ['name' => 'Alert ' . $case, 'entities_id' => $case === 'foreign' ? $child : 0, 'is_deleted' => $case === 'deleted', 'alarm_threshold' => $case === 'disabled' ? -1 : 2]);
        if ($case === 'other_type') {
            $fixtures->create('glpi_cartridgeitems', ['id' => $cases[$case]]);
        }
        if (in_array($case, ['old', 'boundary', 'recent', 'other_type'], true)) {
            $date = match ($case) {
                'old' => '2026-09-28 11:59:59', 'boundary' => '2026-09-28 12:00:00', default => '2026-09-28 12:00:01',
            };
            $fixtures->create('glpi_alerts', ['items_id' => $cases[$case], 'itemtype' => $case === 'other_type' ? 'CartridgeItem' : 'ConsumableItem', 'date' => $date]);
        }
    }
    $em = Orm::create($DB);
    $candidates = (new ConsumableRepository($em))->alertCandidates(0, $before);
    $candidateIds = array_map('intval', array_column($candidates, 'consID'));
    foreach ($cases as $case => $id) {
        verify(in_array($id, $candidateIds, true) === in_array($case, ['old', 'missing', 'other_type'], true), 'Alert cutoff/entity/type filter: ' . $case);
    }
    $dates = array_column($candidates, 'date', 'consID');
    verify($dates[$cases['old']] === '2026-09-28 11:59:59', 'Alert date retains the notification scalar-string contract');
    $em->clear();
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    Consumable::getTotalNumber($model);
    Consumable::isNew($unused);
    $repository = new ConsumableRepository(Orm::create($DB));
    $repository->summary($scope, true);
    $repository->alertCandidates(0, $before);
    verify($SQL_TOTAL_REQUEST === 0, 'Stock queries bypass legacy adapter execution');

    Session::changeActiveEntities(0, false);
    try {
        $fixtures->create('glpi_consumables', ['consumableitems_id' => $model, 'itemtype' => 'PluginRemovedRecipient', 'items_id' => 1, 'date_out' => '2026-01-01']);
        throw new RuntimeException('Unsupported consumable recipient was accepted');
    } catch (InvalidArgumentException) {
    }
    ob_start();
    Consumable::showSummary();
    $html = ob_get_clean();
    verify(str_contains($html, 'Scoped consumable model') && str_contains($html, 'Empty consumable model') && !str_contains($html, 'Hidden consumable model'), 'Rendered summary includes empty models and excludes hidden models');
    $modelObject = new ConsumableItem();
    verify($modelObject->getFromDB($model), 'Load inventory model');
    ob_start();
    Consumable::showForConsumableItem($modelObject, true);
    $html = ob_get_clean();
    verify(str_contains($html, 'Consumable group'), 'Used stock list renders mapped recipients');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": consumable lifecycle, paginated stock, recipient summary, entity scope and alert boundaries passed.\n";
