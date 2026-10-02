<?php

// SPDX-License-Identifier: GPL-2.0-or-later

// Private subprocess router. It is never installed under the application's public directory.
if (PHP_SAPI !== 'cli-server'
    || !hash_equals((string)getenv('ITSM_DELETION_TOKEN'), $_SERVER['HTTP_X_ITSM_DELETION_TOKEN'] ?? '')
    || !is_file((string)getenv('ITSM_DELETION_CONFIG') . '/config_db.php')) {
    http_response_code(404);
    exit;
}
ob_start();
define('GLPI_ROOT', dirname(__DIR__, 3));
define('GLPI_CONFIG_DIR', realpath(getenv('ITSM_DELETION_CONFIG')));
require GLPI_ROOT . '/inc/includes.php';
class DeletionLegacyUser extends User
{
    public static function getTable($classname = null)
    {
        return User::getTable();
    }

    public static function getType()
    {
        return User::getType();
    }

    public function pre_deleteItem()
    {
        parent::pre_deleteItem();
        return false;
    }
}

class DeletionCancelledUser extends DeletionLegacyUser
{
    public function pre_deleteItem()
    {
        global $DB;
        (new \itsmng\Database\Repository\UserRepository(\itsmng\Database\Orm::create($DB)))
            ->detachEntityGrants((int)$this->getID(), $_SESSION['glpiactiveentities']);
        return false;
    }
}

class DeletionThrowingUser extends DeletionLegacyUser
{
    public function pre_deleteItem()
    {
        parent::pre_deleteItem();
        throw new RuntimeException('Expected legacy User override exception');
    }
}

class DeletionStructuredUser extends DeletionLegacyUser
{
    public function deletionDecision(): \itsmng\Database\DeletionDecision
    {
        return $this->decideAccountDeletion();
    }
}

try {
    if (!str_starts_with($DB->dbdefault, 'itsm_port_') || !(new Auth())->login('itsm', 'itsm', true)) {
        throw new RuntimeException('Disposable authenticated fixture required');
    }
    $payload = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
    $_SESSION['glpiactiveentities'] = array_map('intval', $payload['accessible']);
    $_SESSION['glpishowallentities'] = false;
    $CFG_GLPI['use_notifications'] = false;
    $id = (int)$payload['id'];
    $source = match ($payload['mode'] ?? '') {
        'legacy-parent-cancel' => new DeletionLegacyUser(),
        'legacy-cancel' => new DeletionCancelledUser(),
        'legacy-throw' => new DeletionThrowingUser(),
        'structured' => new DeletionStructuredUser(),
        default => new User(),
    };
    if (isset($payload['warm_id'])) {
        $source->getFromDB((int)$payload['warm_id']);
        $source->canPurgeItem(); // Warm the prior account's private visibility cache.
    }
    $records = fn (): \itsmng\Database\Repository\RecordRepository => new \itsmng\Database\Repository\RecordRepository(\itsmng\Database\Orm::create($DB));
    $grants = fn (): array => $records()->matching('glpi_profiles_users', ['users_id' => $id], ['id ASC']);
    $before = $grants();
    if ($payload['cancel'] ?? false) {
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $plugins->setValue(null, [...$plugins->getValue(), 'orm_delete_fixture']);
        $PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture'][User::class] = static function ($item) use ($DB, $payload): void {
            (new \itsmng\Database\Repository\UserRepository(\itsmng\Database\Orm::create($DB)))
                ->detachEntityGrants((int)$item->getID(), $payload['accessible']);
            Session::addMessageAfterRedirect('Uncommitted detachment');
            $item->input = false;
        };
    }
    $allEntities = Session::canViewAllEntities();
    $nestedResult = null;
    if (isset($payload['group'])) {
        $plugins = new ReflectionProperty(Plugin::class, 'activated_plugins');
        $plugins->setValue(null, [...$plugins->getValue(), 'orm_delete_fixture']);
        $PLUGIN_HOOKS['pre_item_purge']['orm_delete_fixture'][Group::class] = static function () use ($source, $id, &$nestedResult): void {
            $nestedResult = $source->delete(['id' => $id], true);
        };
        $result = (new Group())->delete(['id' => (int)$payload['group']], true);
    } else {
        $result = $source->delete(['id' => $id], true);
    }
    $after = $grants();
    $response = [
        'result' => $result, 'nested_result' => $nestedResult, 'view_all' => $allEntities,
        'source_exists' => (new User())->getFromDB($id),
        'before' => array_map('intval', array_column($before, 'entities_id')),
        'after' => array_map('intval', array_column($after, 'entities_id')),
        'transaction_active' => $DB->getDoctrineConnection()->isTransactionActive(),
        'message_retained' => in_array('Uncommitted detachment', $_SESSION['MESSAGE_AFTER_REDIRECT'][INFO] ?? [], true),
    ];
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $error::class, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
