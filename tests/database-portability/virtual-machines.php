<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\ForeignKeys;
use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/virtual-machines.php /path/to/test-config\n");
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
$_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$fixtures = new FixtureRecords($DB);
$read = static fn (string $table, int $id): ?array => (new RecordRepository(Orm::create($DB)))->find($table, 'id', $id);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
}, E_WARNING);
$savedSession = $_SESSION;
$DB->beginTransaction();
try {
    $entityId = (int)$connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 100 FROM glpi_entities');
    $entity = $fixtures->create('glpi_entities', ['id' => $entityId, 'name' => 'VM scope', 'entities_id' => 0]);
    $outside = $fixtures->create('glpi_entities', ['id' => $entityId + 1, 'name' => 'Outside VM scope', 'entities_id' => 0]);
    $_SESSION['glpiactiveentities'] = [0, $entity];
    $_SESSION['glpiactiveentities_string'] = '0,' . $entity;
    $_SESSION['glpiactive_entity'] = $entity;
    $_SESSION['glpishowallentities'] = false;
    $uuid = '12345678-abcd-ef01-2345-6789abcdef01';
    $guest = $fixtures->create('glpi_computers', ['name' => 'Visible VM guest', 'uuid' => $uuid, 'entities_id' => $entity]);
    $hosts = [];
    $children = [];
    foreach (['visible' => [], 'outside' => ['entities_id' => $outside], 'deleted' => ['is_deleted' => true], 'template' => ['is_template' => true], 'retired-vm' => []] as $name => $values) {
        $hosts[$name] = $fixtures->create('glpi_computers', $values + ['name' => $name . ' VM host', 'entities_id' => $entity]);
        $children[$name] = $fixtures->create('glpi_computervirtualmachines', ['computers_id' => $hosts[$name], 'name' => $name . ' VM entry', 'uuid' => strtoupper($uuid), 'is_deleted' => $name === 'retired-vm', 'entities_id' => $values['entities_id'] ?? $entity]);
    }
    $duplicate = $fixtures->create('glpi_computervirtualmachines', ['computers_id' => $hosts['visible'], 'name' => 'visible VM entry', 'uuid' => $uuid, 'entities_id' => $entity]);
    $retired = $fixtures->create('glpi_computervirtualmachines', ['computers_id' => $hosts['visible'], 'name' => 'Deleted VM entry', 'is_deleted' => true, 'entities_id' => $entity]);
    $repo = static fn () => new \itsmng\Database\Repository\InventoryRepository(Orm::create($DB));
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $SQL_TOTAL_REQUEST = 0;
    verify(array_column($repo()->virtualMachinesForComputer($hosts['visible']), 'id') === [$children['visible'], $duplicate], 'VM list excludes deleted children and sorts ties by ID');
    verify($repo()->countVirtualMachines($hosts['visible']) === 2, 'VM count matches list');
    $scope = getEntitiesRestrictCriteria('glpi_computers');
    verify(array_column($repo()->virtualMachineHosts(ComputerVirtualMachine::getUUIDRestrictCriteria($uuid), $scope), 'computers_id') === [$hosts['visible']], 'UUID hosts are case-insensitive, unique, visible, active and not templates');
    verify($repo()->virtualMachineHosts([], $scope) === [], 'Empty UUID list returns no hosts');
    verify($SQL_TOTAL_REQUEST === 0, 'VM repository queries bypass adapter SQL');
    $item = new Computer();
    $item->getFromDB($guest);
    ob_start();
    ComputerVirtualMachine::showForVirtualMachine($item);
    $html = ob_get_clean();
    verify(str_contains($html, 'visible VM host'), 'Visible VM host renders');
    foreach (['outside', 'deleted', 'template', 'retired-vm'] as $hidden) {
        verify(!str_contains($html, $hidden . ' VM host'), 'Host rendering excludes ' . $hidden);
    }
    $item->getFromDB($hosts['visible']);
    ob_start();
    ComputerVirtualMachine::showForComputer($item);
    $html = ob_get_clean();
    verify(str_contains($html, 'visible VM entry') && !str_contains($html, 'Deleted VM entry'), 'VM child view renders mapped rows');
    $hiddenGuest = $fixtures->create('glpi_computers', ['name' => 'Private VM guest', 'uuid' => 'private-vm-uuid', 'entities_id' => $outside]);
    $privateVm = $fixtures->create('glpi_computervirtualmachines', ['computers_id' => $hosts['visible'], 'name' => 'Private guest inventory entry', 'uuid' => 'private-vm-uuid', 'entities_id' => $entity]);
    ob_start();
    ComputerVirtualMachine::showForComputer($item);
    $html = ob_get_clean();
    verify(!str_contains($html, 'Private VM guest'), 'UUID match cannot reveal an inaccessible guest computer name');
    $vm = new ComputerVirtualMachine();
    $created = $vm->add(['computers_id' => $hosts['visible'], 'name' => 'Lifecycle VM', 'uuid' => 'lifecycle-vm', 'vcpu' => 2, 'ram' => '2048', 'is_dynamic' => true]);
    verify($created > 0 && $vm->getFromDB($created) && $vm->fields['computers_id'] === $hosts['visible'], 'VM model creates and hydrates its required host');
    verify($vm->delete(['id' => $created]) && $repo()->countVirtualMachines($hosts['visible']) === 3, 'VM soft delete removes only the active list entry');
    verify($vm->restore(['id' => $created]) && $repo()->countVirtualMachines($hosts['visible']) === 4, 'VM restore returns to active list');
    $replacement = $fixtures->create('glpi_computers', ['name' => 'Replacement VM host', 'entities_id' => $entity]);
    verify($vm->update(['id' => $created, 'computers_id' => $replacement]), 'VM can move to another valid host');
    verify($repo()->countVirtualMachines($replacement) === 1, 'Moved VM belongs to new host');
    verify((new Computer())->delete(['id' => $hosts['visible']], true), 'Computer purge runs VM child lifecycle');
    foreach ([$children['visible'], $duplicate, $retired, $privateVm] as $id) {
        verify($read('glpi_computervirtualmachines', $id) === null, 'Host purge removes active and deleted VM children');
    }
    verify($read('glpi_computervirtualmachines', $created)['computers_id'] === $replacement, 'Host purge preserves reassigned VM');
    verify($read('glpi_computervirtualmachines', $children['outside']) !== null, 'Host purge preserves unrelated VMs');
    verify((new ForeignKeys())->audit($connection) === [], 'VM host graph remains valid');
} finally {
    $DB->rollBack();
    $_SESSION = $savedSession;
    restore_error_handler();
}
echo $DB->getProvider() . ": Virtual machine host mapping, UUID visibility, rendering, lifecycle and purge passed.\n";
