<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use itsmng\Database\Migration\ReferenceHistory;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\DriverException;
use itsmng\Database\EntityConfigurationReferences as References;
use itsmng\Database\ForeignKeys;
use itsmng\Database\MappedReads;
use itsmng\Database\MappedStorage;
use itsmng\Database\Migration\EntityConfigurationReferences as Migration;
use itsmng\Database\Orm;
use itsmng\Database\ReferenceMode;
use itsmng\Database\Repository\RecordRepository;
use itsmng\Database\Repository\RecordWriter;

$directory = $argv[1] ?? '';
if (!is_file($directory . '/config_db.php')) {
    exit("Usage: php tests/database-portability/entity-configuration.php /path/to/test-config\n");
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
verify(str_starts_with($DB->dbdefault, 'itsm_port_'), 'Dedicated database required');
$_SESSION['glpiextauth'] = 0;
verify((new Auth())->login('itsm', 'itsm', true), 'Login');
$CFG_GLPI['use_notifications'] = false;
$connection = $DB->getDoctrineConnection();
$create = (new FixtureRecords($DB))->create(...);
$read = static fn (int $id): ?array => (new RecordRepository(Orm::create($DB)))->find('glpi_entities', 'id', $id);
$reject = static function (callable $operation, string $exception, ?string $constraint = null) use ($connection): void {
    try {
        $connection->transactional($operation);
        throw new LogicException('Operation unexpectedly succeeded');
    } catch (Throwable $error) {
        if (!$error instanceof $exception) {
            throw $error;
        }
        if ($constraint !== null) {
            verify(!$error instanceof ForeignKeyConstraintViolationException && str_contains($error->getMessage(), $constraint), 'Selection rejected by its CHECK constraint');
        }
    }
};
$DB->beginTransaction();
try {
    $selected = [];
    foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
        $selected[$column] = $definition['target'] === 'glpi_entities' ? 0 : $create($definition['target']);
    }
    $grand = $create('glpi_entities', ['name' => 'Configuration grandparent', 'entities_id' => 0, 'level' => 2, 'tag' => 'config-unique-tag', 'delay_send_emails' => 7, 'admin_email' => 'parent@example.invalid'] + $selected);
    $parent = $create('glpi_entities', ['name' => 'Configuration parent', 'entities_id' => $grand, 'level' => 3, 'delay_send_emails' => -2]);
    $child = $create('glpi_entities', ['name' => 'Configuration child', 'entities_id' => $parent, 'level' => 4, 'delay_send_emails' => -2]);
    $model = new Entity();
    verify($model->getEmpty(), 'Create blank entity form');
    foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
        verify($model->fields[$column] === ($definition['default'] === 'inherit' ? -2 : 0), 'Blank form selection: ' . $column);
        // LDAP defaults to none, but can explicitly inherit like the other settings.
        verify((new Entity())->update(['id' => $parent, $column => -2]), 'Enable parent inheritance: ' . $column);
        verify((new Entity())->update(['id' => $child, $column => -2]), 'Enable child inheritance: ' . $column);
        verify(Entity::getUsedConfig($column, $child) === $selected[$column], 'Resolve grandparent setting: ' . $column);
        verify($read($child)[$column] === null && $read($child)[$definition['mode']] === 'inherit', 'Inheritance uses a NULL association: ' . $column);
        verify($model->getFromDB($child) && $model->fields[$column] === -2, 'Model retains legacy inherited selection: ' . $column);
        $ids = array_column(MappedReads::matching($DB, 'glpi_entities', ['id' => [$grand, $parent, $child], $column => -2], 'id'), 'id');
        verify($ids === array_values(array_filter([$grand, $parent, $child], static fn (int $id): bool => $id !== $grand)), 'Legacy criteria select inherited policies: ' . $column);
        $canonical = (new RecordRepository(Orm::create($DB)))->matching('glpi_entities', ['id' => $child, $column => null, $definition['mode'] => ReferenceMode::Inherit], legacyValues: false);
        verify(count($canonical) === 1, 'Canonical criteria bind enum policy and NULL association: ' . $column);
        $reject(fn () => $connection->update('glpi_entities', [$column => 2147483647, $definition['mode'] => 'explicit'], ['id' => $child]), ForeignKeyConstraintViolationException::class);
        $reject(fn () => $connection->update('glpi_entities', [$column => $selected[$column], $definition['mode'] => 'inherit'], ['id' => $child]), DriverException::class, Migration::checkName($column));
        verify((new Entity())->update(['id' => $child, $column => 0]), 'Explicit none or real root: ' . $column);
        verify(Entity::getUsedConfig($column, $child) === 0, 'Explicit selection stops inheritance: ' . $column);
        verify($read($child)[$column] === ($definition['empty_zero'] ? null : 0) && $read($child)[$definition['mode']] === 'explicit', 'Zero storage distinguishes none from real software root: ' . $column);
        verify((new Entity())->update(['id' => $child, $definition['mode'] => ReferenceMode::Explicit]), 'Mode-only update retains an existing explicit target: ' . $column);
        $changed = (new MappedStorage($DB))->update('glpi_entities', $child, [$definition['mode'] => ReferenceMode::Inherit]);
        verify(in_array($column, $changed, true), 'Mode-only update reports the logical field as changed: ' . $column);
        $mapped = Orm::create($DB)->find(\itsmng\Database\Entity\Entity::class, $child);
        verify($mapped->{$definition['mode']} === ReferenceMode::Inherit && $mapped->{$definition['association']} === null, 'ORM hydrates a typed enum and nullable association: ' . $column);
        $reject(fn () => References::normalizeLegacy('glpi_entities', [$column => -7]), InvalidArgumentException::class);
    }
    verify((new Entity())->update(['id' => $child, 'software_entity_mode' => ReferenceMode::Unchanged]), 'Canonical unchanged software mode');
    $reject(fn () => (new Entity())->update(['id' => $child, 'software_entity_mode' => ReferenceMode::Explicit]), InvalidArgumentException::class);
    verify(Entity::getUsedConfig('entities_id_software', $child) === -10 && $read($child)['entities_id_software'] === null, 'Leave software ownership unchanged is separate from inheritance/root');
    verify(count(MappedReads::matching($DB, 'glpi_entities', ['id' => $child, 'entities_id_software' => -10])) === 1, 'Unchanged software selection participates in mapped criteria');
    verify((new Entity())->update(['id' => $child, 'calendars_id' => null, 'calendar_mode' => ReferenceMode::Explicit]), 'Canonical explicit no-calendar update');
    verify(Entity::getUsedConfig('calendars_id', $child) === 0, 'Canonical NULL explicit calendar means 24/7');
    verify((new Entity())->update(['id' => $child, 'calendar_mode' => ReferenceMode::Inherit]), 'Canonical mode-only inheritance update');
    verify((new Entity())->update(['id' => $child, 'calendar_mode' => ReferenceMode::Explicit]), 'Canonical mode-only none update');
    verify(Entity::getUsedConfig('calendars_id', $child) === 0, 'Mode-only explicit transition clears inherited selection');
    verify(Entity::getUsedConfig('admin_email', $child, '', '') === 'parent@example.invalid', 'String settings inherit empty values');
    verify(Entity::getUsedConfig('delay_send_emails', $child, 'admin_email', '') === null, 'String default retains the legacy truthiness rule for the reference field');
    verify(Entity::getUsedConfig('delay_send_emails', $child) === 7, 'Ordinary scalar setting inherits through mapped records');
    verify(Entity::getUsedConfig('delay_send_emails', $grand, 'mail_domain', '') === null, 'A configured reference preserves a NULL alternate value');
    verify(Entity::getUsedConfig('cartriges_alert_repeat', $child, 'default_cartridges_alarm_threshold', 10) === 10, 'Legacy missing reference field returns its fallback');
    verify(Entity::getUsedConfig('calendars_id', 2147483647, '', 24) === 24, 'Missing entity returns supplied default');
    verify(Entity::getEntityIDByTag('config-unique-tag') === $grand && Entity::getEntityIDByTag('config-no-match') === -1, 'Mapped unique entity lookup');
    (new RecordWriter(Orm::create($DB)))->update('glpi_entities', $parent, ['tag' => 'config-unique-tag']);
    verify(Entity::getEntityIDByTag('config-unique-tag') === -1, 'Duplicate entity lookup is ambiguous');
    (new RecordWriter(Orm::create($DB)))->update('glpi_entities', 0, ['delay_send_emails' => 11]);
    $oldLevel = $create('glpi_entities', ['entities_id' => 0, 'level' => 0, 'delay_send_emails' => -2]);
    $notifications = Entity::getEntitiesToNotify('delay_send_emails');
    verify($notifications[$grand] === 7 && $notifications[$parent] === 7 && $notifications[$child] === 7, 'Notification setting projection resolves parent-first inheritance');
    verify($notifications[0] === 11 && $notifications[$oldLevel] === 11, 'Root is resolved first even with legacy zero-level descendants');
    $savedRights = $_SESSION['glpiactiveprofile'];
    try {
        $_SESSION['glpiactiveprofile']['entity'] = READ;
        foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
            $before = $read($child);
            verify((new Entity())->update(['id' => $child, $definition['mode'] => 'inherit']), 'Filtered update returns successfully');
            verify($read($child) === $before, 'Policy fields require the same rights as the reference: ' . $column);
        }
    } finally {
        $_SESSION['glpiactiveprofile'] = $savedRights;
    }
    // Real self-associations must use the new record, not a conflicting ID proxy.
    $selfId = 1 + (int)$connection->fetchOne('SELECT MAX(id) FROM glpi_entities');
    $self = $create('glpi_entities', ['id' => $selfId, 'entities_id' => 0, 'entities_id_software' => $selfId]);
    verify($read($self)['entities_id_software'] === $self, 'Insert assigned-ID self-reference through ORM');
    verify((new Entity())->delete(['id' => $self], true) && $read($self) === null, 'Purge a software self-reference through application lifecycle');
    foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
        if ($definition['target'] === 'glpi_entities') {
            continue;
        }
        $source = $create($definition['target']);
        $replacement = $create($definition['target']);
        verify((new Entity())->update(['id' => $child, $column => $source]), 'Select purge source: ' . $column);
        $target = getItemForItemtype(getItemTypeForTable($definition['target']));
        verify($target->delete(['id' => $source, '_replace_by' => $replacement], true), 'Purge setting with replacement: ' . $column);
        verify($read($child)[$column] === $replacement && $read($child)[$definition['mode']] === 'explicit', 'Purge preserves explicit replacement: ' . $column);
        verify($target->delete(['id' => $replacement], true), 'Purge setting without replacement: ' . $column);
        verify($read($child)[$column] === null && $read($child)[$definition['mode']] === 'explicit', 'Purge becomes explicit none, not inheritance: ' . $column);
    }
    $softwareSource = (int)(new Entity())->add(['name' => 'Configuration software purge', 'entities_id' => 0]);
    $softwareReplacement = (int)(new Entity())->add(['name' => 'Configuration software replacement', 'entities_id' => 0]);
    verify((new Entity())->update(['id' => $child, 'entities_id_software' => $softwareSource]), 'Select software entity');
    verify((new Entity())->delete(['id' => $softwareSource, '_replace_by' => $softwareReplacement], true), 'Purge software entity with replacement');
    verify($read($child)['entities_id_software'] === $softwareReplacement, 'Software ownership setting follows replacement');
    verify((new Entity())->delete(['id' => $softwareReplacement], true), 'Purge software entity without replacement');
    verify($read($child)['entities_id_software'] === 0 && $read($child)['software_entity_mode'] === 'explicit', 'Software purge falls back to real root');
    // Cycles must fail deterministically instead of overflowing the PHP stack.
    (new RecordWriter(Orm::create($DB)))->update('glpi_entities', $parent, ['entities_id' => $child]);
    $reject(fn () => Entity::getUsedConfig('delay_send_emails', $child), RuntimeException::class);
    (new RecordWriter(Orm::create($DB)))->update('glpi_entities', $parent, ['entities_id' => $grand]);
    $DB->listFields('glpi_entities');
    $_SESSION['glpi_use_mode'] = Session::DEBUG_MODE;
    $CFG_GLPI['debug_sql'] = true;
    $SQL_TOTAL_REQUEST = 0;
    $DEBUG_SQL = [];
    verify(Entity::getUsedConfig('delay_send_emails', $child) === 7, 'Application setting lookup');
    Entity::getEntitiesToNotify('delay_send_emails');
    Entity::getEntityIDByTag('config-no-match');
    verify($SQL_TOTAL_REQUEST === 0, 'Configuration reads execute no adapter SQL: ' . json_encode($DEBUG_SQL['queries'] ?? []));
    verify((new ForeignKeys())->audit($connection) === [], 'Lifecycle leaves no dangling references');
} finally {
    $DB->rollBack();
}

// Reconstruct the former six sentinel columns in this disposable database only.
$migration = new Migration();
$platform = $connection->getDatabasePlatform();
$quote = $platform->quoteIdentifier(...);
$entities = [];
$targets = [];
try {
    $chosen = [];
    foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
        $chosen[$column] = $definition['target'] === 'glpi_entities' ? 0 : $create($definition['target']);
        if ($definition['target'] !== 'glpi_entities') {
            $targets[$definition['target']][] = $chosen[$column];
        }
    }
    $inherited = $create('glpi_entities', ['entities_id' => 0]);
    $entities[] = $inherited;
    $none = $create('glpi_entities', ['entities_id' => 0, 'entities_id_software' => 0]);
    $entities[] = $none;
    $selected = $create('glpi_entities', ['entities_id' => 0] + $chosen);
    $entities[] = $selected;
    $unchanged = $create('glpi_entities', ['entities_id' => 0, 'software_entity_mode' => ReferenceMode::Unchanged]);
    $entities[] = $unchanged;
    foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
        (new RecordWriter(Orm::create($DB)))->update('glpi_entities', $inherited, [$column => null, $definition['mode'] => ReferenceMode::Inherit]);
        (new RecordWriter(Orm::create($DB)))->update('glpi_entities', $none, [$column => $definition['empty_zero'] ? null : 0, $definition['mode'] => ReferenceMode::Explicit]);
    }
    $before = $connection->createSchemaManager()->introspectTable('glpi_entities');
    foreach ($before->getForeignKeys() as $key) {
        if (array_intersect(array_keys(ReferenceHistory::get('inherited', 'FIELDS')), $key->getLocalColumns())) {
            $connection->executeStatement($platform->getDropForeignKeySQL($key->getName(), 'glpi_entities'));
        }
    }
    foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
        $drop = $platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform && !$platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform ? 'CHECK ' : 'CONSTRAINT ';
        $connection->executeStatement('ALTER TABLE glpi_entities DROP ' . $drop . $quote(Migration::checkName($column)));
        $field = $quote($column);
        $mode = $quote($definition['mode']);
        $connection->executeStatement("UPDATE glpi_entities SET $field = CASE WHEN $mode = 'inherit' THEN -2 WHEN $mode = 'unchanged' THEN -10 ELSE COALESCE($field, 0) END");
    }
    $manager = $connection->createSchemaManager();
    $before = $manager->introspectTable('glpi_entities');
    $after = clone $before;
    foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
        $after->dropColumn($definition['mode']);
        $after->getColumn($column)->setNotnull(true)->setDefault($definition['default'] === 'inherit' ? -2 : 0);
    }
    foreach ($platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
        $connection->executeStatement($sql);
    }
    $connection->update('glpi_entities', ['authldaps_id' => 2147483647], ['id' => $selected]);
    $reject(fn () => $migration->apply($connection), RuntimeException::class);
    $connection->update('glpi_entities', ['authldaps_id' => $chosen['authldaps_id'], 'entities_id_software' => -7], ['id' => $selected]);
    $reject(fn () => $migration->apply($connection), RuntimeException::class);
    foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
        $schema = $connection->createSchemaManager()->introspectTable('glpi_entities');
        verify(!$schema->hasColumn($definition['mode']) && $schema->getColumn($column)->getNotnull(), 'All-reference preflight precedes every DDL: ' . $column);
    }
    $connection->update('glpi_entities', ['entities_id_software' => 0], ['id' => $selected]);
    $plan = $migration->plan($connection);
    verify(count($plan['check_sql']) === 6 && array_sum($plan['counts']['entities_id_software']) > 0, 'Upgrade plans policy columns, sentinel normalization and six checks');
    // MySQL can commit DDL before normalization; retry must derive explicit IDs
    // even when newly added mode columns still contain their inherit defaults.
    foreach ($plan['sql'] as $sql) {
        $connection->executeStatement($sql);
    }
    $migration->apply($connection);
    foreach (ReferenceHistory::get('inherited', 'FIELDS') as $column => $definition) {
        verify($read($inherited)[$column] === null && $read($inherited)[$definition['mode']] === 'inherit', 'Upgrade inherited association: ' . $column);
        verify($read($none)[$column] === ($definition['empty_zero'] ? null : 0) && $read($none)[$definition['mode']] === 'explicit', 'Upgrade explicit none/root: ' . $column);
        verify($read($selected)[$column] === $chosen[$column] && $read($selected)[$definition['mode']] === 'explicit', 'Partial-DDL retry preserves explicit IDs: ' . $column);
    }
    verify($read($unchanged)['entities_id_software'] === null && $read($unchanged)['software_entity_mode'] === 'unchanged', 'Upgrade preserves leave-unchanged policy');
    $plan = $migration->plan($connection);
    verify($plan['sql'] === [] && $plan['check_sql'] === [] && array_sum(array_map(array_sum(...), $plan['counts'])) === 0, 'Migration retry is idempotent');
    (new ForeignKeys())->apply($connection);
    $reject(fn () => $connection->update('glpi_entities', ['calendars_id' => 2147483647, 'calendar_mode' => 'explicit'], ['id' => $selected]), ForeignKeyConstraintViolationException::class);
} finally {
    foreach ($entities as $id) {
        $connection->delete('glpi_entities', ['id' => $id]);
    }
    $migration->apply($connection);
    (new ForeignKeys())->apply($connection);
    foreach ($targets as $table => $ids) {
        foreach ($ids as $id) {
            $connection->delete($table, ['id' => $id]);
        }
    }
}
echo $DB->getProvider() . ": typed entity settings, inheritance, scalar APIs, permissions, foreign keys, lifecycle and migration retry passed.\n";
