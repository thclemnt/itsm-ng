<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Private CLI companion: browser/API requests always use application routes.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$directory = $argv[1] ?? '';
$action = $argv[2] ?? '';
if (!is_file($directory . '/config_db.php')) {
    throw new RuntimeException('Disposable application configuration required');
}
define('GLPI_ROOT', dirname(__DIR__, 3));
define('GLPI_CONFIG_DIR', realpath($directory));
define('GLPI_VAR_DIR', getenv('GLPI_VAR_DIR') ?: GLPI_ROOT . '/files');
require GLPI_ROOT . '/inc/includes.php';
if (!str_starts_with($DB->dbdefault, 'itsm_port_')) {
    throw new RuntimeException('Disposable test database required');
}
$_SESSION['glpiextauth'] = 0;
if (!(new Auth())->login('itsm', 'itsm', true)) {
    throw new RuntimeException('Fixture administrator login failed');
}
$CFG_GLPI['use_notifications'] = false;
$records = new \itsmng\Database\Repository\RecordRepository(\itsmng\Database\Orm::create($DB));
$connection = $DB->getDoctrineConnection();
$input = isset($argv[3]) ? json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR) : [];
if ($action === 'guard') {
    $token = bin2hex(random_bytes(20));
    $state = ['database' => $DB->dbdefault, 'prefix' => 'DomainBrowser-' . $token . '-', 'owned' => []];
} else {
    $token = $input['token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{40}$/D', $token)) {
        throw new RuntimeException('Private fixture token required');
    }
}
$path = GLPI_VAR_DIR . '/_tmp/domain-browser-' . $token . '.json';
if ($action !== 'guard') {
    $state = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if ($state['database'] !== $DB->dbdefault) {
        throw new RuntimeException('Fixture configuration changed');
    }
}
$save = static function () use ($path, &$state): void {
    if (file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Cannot save private fixture manifest');
    }
    chmod($path, 0600);
};
$ownedId = static function (string $type) use (&$state): int {
    $ids = $state['owned'][$type] ?? [];
    if (count($ids) !== 1) {
        throw new RuntimeException('Exactly one owned ' . $type . ' required');
    }
    return $ids[0];
};
if ($action === 'guard') {
    $save();
    $result = ['token' => $token, 'prefix' => $state['prefix']];
} elseif ($action === 'own') {
    $type = $input['itemtype'] ?? '';
    $id = $input['id'] ?? 0;
    if (!in_array($type, [Supplier::class, Domain::class, Document::class], true) || !is_int($id) || $id <= 0) {
        throw new RuntimeException('Unsupported API fixture identity');
    }
    $model = new $type();
    if (!$model->getFromDB($id) || !str_starts_with($model->fields['name'], $state['prefix'])) {
        throw new RuntimeException('API and CLI must share the disposable database and owned name prefix');
    }
    if (!in_array($id, $state['owned'][$type] ?? [], true)) {
        $state['owned'][$type][] = $id;
    }
    $save();
    $result = ['registered' => true];
} elseif ($action === 'financial') {
    $supplier = $input['supplier'] ?? 0;
    if (!in_array($supplier, $state['owned'][Supplier::class] ?? [], true)) {
        throw new RuntimeException('Owned financial Supplier required');
    }
    $domain = $ownedId(Domain::class);
    $financial = new Infocom();
    if ($financial->getFromDBforDevice(Domain::class, $domain)) {
        if (!$financial->update(['id' => $financial->getID(), 'suppliers_id' => $supplier])) {
            throw new RuntimeException('Public financial update failed');
        }
    } elseif (!$financial->add(['itemtype' => Domain::class, 'items_id' => $domain, 'suppliers_id' => $supplier])) {
        throw new RuntimeException('Public financial creation failed');
    }
    $result = ['id' => $financial->getID()];
} elseif ($action === 'read') {
    $domain = $ownedId(Domain::class);
    $financial = $records->matching('glpi_infocoms', ['itemtype' => Domain::class, 'items_id' => $domain]);
    $result = [
        'domain' => $records->find('glpi_domains', 'id', $domain),
        'financial' => $financial,
        'documents' => $records->matching('glpi_documents_items', ['itemtype' => Domain::class, 'items_id' => $domain], ['id ASC']),
    ];
} elseif ($action === 'clean') {
    // Purge owned subjects first, through their real cleanup hooks. The manifest
    // never permits arbitrary table names or records from another fixture.
    foreach ([Domain::class, Document::class, Supplier::class] as $type) {
        foreach ($state['owned'][$type] ?? [] as $id) {
            $model = new $type();
            if ($model->getFromDB($id)) {
                if (!str_starts_with($model->fields['name'], $state['prefix']) || !$model->delete(['id' => $id], true)) {
                    throw new RuntimeException('Owned ' . $type . ' purge failed');
                }
            }
            // History is intentionally retained by some public purge policies.
            foreach ($records->matching('glpi_logs', ['itemtype' => $type, 'items_id' => $id]) as $log) {
                $connection->delete('glpi_logs', ['id' => $log['id']]);
            }
        }
    }
    unlink($path);
    $result = ['cleaned' => true];
} else {
    throw new RuntimeException('Unknown fixture action');
}
echo json_encode($result, JSON_THROW_ON_ERROR);
