<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Orm;
use itsmng\Database\Repository\RecordRepository;

// CLI owns data setup/readback/cleanup only. The browser establishes its own
// ordinary login, entity scope, CSRF tokens and transfer list through real routes.
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
$connection = $DB->getDoctrineConnection();
$records = new RecordRepository(Orm::create($DB));
$input = isset($argv[3]) ? json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR) : [];
if ($action === 'guard') {
    $token = bin2hex(random_bytes(20));
    $state = ['database' => $DB->dbdefault, 'prefix' => 'TransferBrowser-' . $token . '-', 'owned' => [], 'identities' => []];
} else {
    $token = $input['token'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{40}$/D', $token)) {
        throw new RuntimeException('Private fixture token required');
    }
}
$path = GLPI_VAR_DIR . '/_tmp/transfer-browser-' . $token . '.json';
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
$snapshot = static function () use ($connection, &$state): array {
    $id = $state['identities']['domain'] ?? throw new RuntimeException('Owned Domain required');
    // Native DBAL readback avoids identity-map state after separate HTTP writes.
    return [
        'domain' => $connection->fetchAssociative('SELECT * FROM glpi_domains WHERE id = ?', [$id]),
        'financial' => $connection->fetchAllAssociative('SELECT * FROM glpi_infocoms WHERE itemtype = ? AND items_id = ? ORDER BY id', [Domain::class, $id]),
        'documents' => $connection->fetchAllAssociative('SELECT * FROM glpi_documents_items WHERE itemtype = ? AND items_id = ? ORDER BY id', [Domain::class, $id]),
        'contracts' => $connection->fetchAllAssociative('SELECT * FROM glpi_contracts_items WHERE itemtype = ? AND items_id = ? ORDER BY id', [Domain::class, $id]),
        'history' => $connection->fetchAllAssociative('SELECT * FROM glpi_logs WHERE itemtype = ? AND items_id = ? ORDER BY id', [Domain::class, $id]),
        'targets' => [
            'document' => $connection->fetchAssociative('SELECT * FROM glpi_documents WHERE id = ?', [$state['identities']['document']]),
            'contract' => $connection->fetchAssociative('SELECT * FROM glpi_contracts WHERE id = ?', [$state['identities']['contract']]),
            'commercial' => $connection->fetchAssociative('SELECT * FROM glpi_suppliers WHERE id = ?', [$state['identities']['commercial']]),
            'financialSupplier' => $connection->fetchAssociative('SELECT * FROM glpi_suppliers WHERE id = ?', [$state['identities']['financialSupplier']]),
        ],
    ];
};
if ($action === 'guard') {
    $save();
    $result = ['token' => $token, 'prefix' => $state['prefix']];
} elseif ($action === 'seed') {
    if ($state['owned']) {
        throw new RuntimeException('Fixture already seeded');
    }
    $connection->beginTransaction();
    try {
        $create = static function (string $type, string $key, array $values = []) use (&$state, $save): int {
            $model = new $type();
            $values = ['name' => $state['prefix'] . $key] + $values;
            if (!$model->can(-1, CREATE, $values) || !($id = $model->add($values))) {
                throw new RuntimeException('Public fixture creation failed for ' . $type);
            }
            $state['owned'][$type][] = (int)$id;
            $state['identities'][$key] = (int)$id;
            $save();
            return (int)$id;
        };
        $source = $create(Entity::class, 'source', ['entities_id' => 0]);
        $destination = $create(Entity::class, 'destination', ['entities_id' => 0]);
        // Refresh through the normal authorized API after public Entity hooks.
        // This affects this CLI login only; no browser session is ever edited.
        if (!Session::changeActiveEntities(0, true)
            || !Session::haveAccessToEntity($source) || !Session::haveAccessToEntity($destination)) {
            throw new RuntimeException('Fixture administrator lacks the created entity scope');
        }
        $commercial = $create(Supplier::class, 'commercial', ['entities_id' => $source, 'is_recursive' => false]);
        $financialSupplier = $create(Supplier::class, 'financialSupplier', ['entities_id' => 0, 'is_recursive' => true]);
        $domain = $create(Domain::class, 'domain', ['entities_id' => $source, 'suppliers_id' => $commercial, 'comment' => 'Transfer fixture before refusal']);
        $document = $create(Document::class, 'document', ['entities_id' => $source]);
        $contract = $create(Contract::class, 'contract', ['entities_id' => $source]);
        $mode = $create(Transfer::class, 'mode', ['keep_infocom' => 1, 'keep_supplier' => 1, 'keep_document' => 1, 'keep_contract' => 1, 'keep_history' => 1]);
        $financial = new Infocom();
        if ($financial->getFromDBforDevice(Domain::class, $domain)) {
            if (!$financial->update(['id' => $financial->getID(), 'suppliers_id' => $financialSupplier])) {
                throw new RuntimeException('Public financial setup update refused');
            }
        } elseif (!$financial->add(['itemtype' => Domain::class, 'items_id' => $domain, 'suppliers_id' => $financialSupplier])) {
            throw new RuntimeException('Public financial setup creation refused');
        }
        foreach ([Document_Item::class => ['documents_id' => $document], Contract_Item::class => ['contracts_id' => $contract]] as $type => $parent) {
            $relation = new $type();
            $values = ['itemtype' => Domain::class, 'items_id' => $domain] + $parent;
            if (!$relation->can(-1, CREATE, $values) || !$relation->add($values)) {
                throw new RuntimeException('Public fixture relation refused for ' . $type);
            }
        }
        if (!(new Domain())->update(['id' => $domain, 'comment' => 'Audited Domain before transfer'])) {
            throw new RuntimeException('Public audited Domain update refused');
        }
        $connection->commit();
        $result = $state['identities'] + ['domainName' => $state['prefix'] . 'domain'];
    } catch (Throwable $error) {
        $connection->rollBack();
        throw $error;
    }
} elseif ($action === 'read') {
    $result = $snapshot();
} elseif ($action === 'clean') {
    $failures = [];
    // Public Transfer may copy named targets. The unguessable manifest prefix
    // admits those copies but never arbitrary caller-supplied IDs/table names.
    foreach ([Domain::class, Document::class, Contract::class, Supplier::class, Transfer::class, Entity::class] as $type) {
        $ids = $state['owned'][$type] ?? [];
        foreach ($records->matching($type::getTable(), ['name' => ['LIKE', $state['prefix'] . '%']]) as $row) {
            $ids[] = (int)$row['id'];
        }
        foreach (array_unique($ids) as $id) {
            try {
                $model = new $type();
                if ($model->getFromDB($id) && (!str_starts_with($model->fields['name'], $state['prefix']) || !$model->delete(['id' => $id], true))) {
                    throw new RuntimeException('Owned ' . $type . ' purge refused');
                }
                foreach ($records->matching('glpi_logs', ['itemtype' => $type, 'items_id' => $id]) as $log) {
                    $connection->delete('glpi_logs', ['id' => $log['id']]);
                }
            } catch (Throwable $error) {
                $failures[] = $error;
            }
        }
    }
    if ($failures) {
        throw new RuntimeException('Owned cleanup failed: ' . implode('; ', array_map(static fn ($error) => $error->getMessage(), $failures)), previous: $failures[0]);
    }
    unlink($path);
    $result = ['cleaned' => true];
} else {
    throw new RuntimeException('Unknown fixture action');
}
echo json_encode($result, JSON_THROW_ON_ERROR);
