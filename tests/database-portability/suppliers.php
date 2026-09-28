<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\InfocomRepository;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    fwrite(STDERR, "Usage: php tests/database-portability/suppliers.php /path/to/test-config\n");
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
    $entity = (new Entity())->add(['name' => 'Supplier scope', 'entities_id' => 0]);
    $supplier = $fixtures->create('glpi_suppliers', ['name' => 'Mapped vendor', 'entities_id' => $entity, 'email' => "vendor.o'connor@example.invalid"]);
    $sameEmail = $fixtures->create('glpi_suppliers', ['name' => 'Other vendor', 'email' => "vendor.o'connor@example.invalid"]);
    $em = Orm::create($DB);
    $repository = new InfocomRepository($em);
    $parent = $fixtures->create('glpi_computers', ['entities_id' => $entity]);
    $visible = [];
    foreach (['Computer', 'Cartridge', 'Consumable', 'Item_DeviceControl', 'Item_DeviceProcessor'] as $type) {
        [$linktype, $linkfield] = InfocomRepository::linkFor($type);
        $namefield = $linktype::getNameField();
        foreach (['visible', 'hidden'] as $visibility) {
            $linkEntity = $visibility === 'visible' ? $entity : 0;
            $name = $visibility . ' supplier ' . $type;
            if ($linkfield === 'id') {
                $id = $fixtures->create(getTableForItemType($type), [$namefield => $name, 'entities_id' => $linkEntity, 'is_deleted' => 1, 'is_template' => 1]);
            } else {
                $link = $fixtures->create(getTableForItemType($linktype), [$namefield => $name, 'entities_id' => $linkEntity]);
                $values = [$linkfield => $link, 'entities_id' => $visibility === 'visible' ? 0 : $entity];
                if (is_a($type, Item_Devices::class, true)) {
                    $values += ['itemtype' => 'Computer', 'items_id' => $parent, 'serial' => 'Component serial'];
                }
                $id = $fixtures->create(getTableForItemType($type), $values);
            }
            $fixtures->create('glpi_infocoms', ['suppliers_id' => $supplier, 'items_id' => $id, 'itemtype' => $type, 'entities_id' => 0]);
            if ($visibility === 'visible') {
                $visible[$type] = $id;
            }
        }
        $rows = $repository->forSupplier($type, $supplier, [$entity], 10);
        verify($rows['count'] === 1 && count($rows['rows']) === 1 && $rows['rows'][0]['id'] === $visible[$type], 'Supplier projection uses item/model scope: ' . $type);
        verify($rows['rows'][0][$namefield] === 'visible supplier ' . $type, 'Model display name is hydrated: ' . $type);
        if ($linkfield !== 'id') {
            verify($rows['rows'][0][$linkfield] > 0, 'Linked record identity retained');
        }
        verify($repository->forSupplier($type, $supplier, [], 10) === ['count' => 0, 'rows' => []], 'Empty scope exposes no supplier items');
        $em->clear();
        verify($repository->forSupplier($type, $supplier, null, 1) === ['count' => 2, 'rows' => []], 'Oversized supplier groups are counted without loading rows');
        verify($em->getUnitOfWork()->size() === 0, 'Count-only supplier groups hydrate no entities');
    }
    foreach (Infocom::getExcludedTypes() as $type) {
        $fixtures->create('glpi_infocoms', ['suppliers_id' => $supplier, 'items_id' => $fixtures->create(getTableForItemType($type)), 'itemtype' => $type]);
    }
    $discovered = array_column(Infocom::getTypes(['suppliers_id' => $supplier]), 'itemtype');
    $expected = array_keys($visible);
    sort($expected);
    verify($discovered === $expected, 'Distinct financial type discovery excludes model-level records');
    $matched = array_column(Supplier::getSuppliersByEmail("vendor.o'connor@example.invalid"), 'id');
    sort($matched);
    verify($matched === [$supplier, $sameEmail] && Supplier::getSuppliersByEmail('missing@example.invalid') === [], 'Mapped email identity lookup preserves apostrophes and duplicate suppliers');
    $_SESSION['glpishowallentities'] = false;
    $_SESSION['glpiactiveentities'] = [$entity];
    $_SESSION['glpiactive_entity'] = $entity;
    $_SESSION['glpilist_limit'] = 10;
    $_SESSION['_glpi_csrf_token'] = Session::getNewCSRFToken();
    $item = new Supplier();
    verify($item->getFromDB($supplier), 'Load supplier view');
    ob_start();
    $item->showInfocoms();
    $html = ob_get_clean();
    foreach (array_keys($visible) as $type) {
        verify(str_contains($html, 'visible supplier ' . $type) && !str_contains($html, 'hidden supplier ' . $type), 'Supplier view renders only scoped rows: ' . $type);
    }
    $extra = $fixtures->create('glpi_computers', ['entities_id' => $entity, 'name' => 'Second supplier computer']);
    $fixtures->create('glpi_infocoms', ['suppliers_id' => $supplier, 'itemtype' => 'Computer', 'items_id' => $extra]);
    $_SESSION['glpilist_limit'] = 1;
    ob_start();
    $item->showInfocoms();
    $html = ob_get_clean();
    verify(str_contains($html, 'Device list') && !str_contains($html, 'visible supplier Computer') && !str_contains($html, 'Second supplier computer'), 'Oversized type renders its search link instead of loading all rows');
    verify(str_contains($html, 'visible supplier Consumable'), 'Other types still render at their exact limit');
    foreach ([$visible['Computer'] => 'dollar.png', $parent => 'dollaradd.png'] as $id => $icon) {
        ob_start();
        Infocom::showDisplayLink('Computer', $id);
        $html = ob_get_clean();
        verify(str_contains($html, $icon), 'Mapped financial modal link distinguishes existing records from new ones');
    }
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $DEBUG_SQL = [];
    $SQL_TOTAL_REQUEST = 0;
    Infocom::getTypes(['suppliers_id' => $supplier]);
    Supplier::getSuppliersByEmail("vendor.o'connor@example.invalid");
    $repository->forSupplier('Consumable', $supplier, [$entity], 10);
    verify($SQL_TOTAL_REQUEST === 0, 'Supplier queries bypass legacy execution');
} finally {
    $DB->rollBack();
}
echo $DB->getProvider() . ": mapped supplier identities, scoped financial lists, model links and bounded rendering passed.\n";
