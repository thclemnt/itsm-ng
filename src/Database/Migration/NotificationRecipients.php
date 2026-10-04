<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Table;

/** Frozen upgrade: recipient constants remain payloads; profile/group kinds select real rows. */
final class NotificationRecipients
{
    public const VERSION = '20260930_notification_recipients';
    public const CHECK = 'glpi_notificationtargets_recipient_kind';

    public static function configureTable(Table $table): void
    {
        self::configureColumns($table);
        $table->getColumn('items_id')->setNotnull(false)->setDefault(null)->setColumnDefinition(self::keyDeclaration());
    }

    private static function configureColumns(Table $table): void
    {
        foreach (['groups_id', 'profiles_id'] as $column) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, 'bigint', ['notnull' => false]);
            }
            if (!$table->hasIndex($column)) {
                $table->addIndex([$column], $column);
            }
        }
        if (!$table->hasColumn('recipient_code')) {
            $table->addColumn('recipient_code', 'integer', ['notnull' => false, 'default' => 0]);
        }
    }

    private static function keyDeclaration(): string
    {
        return 'BIGINT GENERATED ALWAYS AS (CASE WHEN type IN (3, 5, 6) THEN groups_id WHEN type = 2 THEN profiles_id ELSE recipient_code END) STORED';
    }

    public static function checkSql(): string
    {
        return 'ALTER TABLE glpi_notificationtargets ADD CONSTRAINT ' . self::CHECK
            . ' CHECK (' . self::validRecipientSql() . ')';
    }

    private static function validRecipientSql(): string
    {
        return '(type = 2 AND profiles_id IS NOT NULL AND profiles_id > 0 AND groups_id IS NULL AND recipient_code IS NULL)'
            . ' OR (type IN (3, 5, 6) AND groups_id IS NOT NULL AND groups_id > 0 AND profiles_id IS NULL AND recipient_code IS NULL)'
            . ' OR (type NOT IN (2, 3, 5, 6) AND groups_id IS NULL AND profiles_id IS NULL AND recipient_code IS NOT NULL)';
    }

    public function plan(Connection $connection): array
    {
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable('glpi_notificationtargets');
        $hasKey = $before->hasColumn('items_id');
        if (!$hasKey && (!$before->hasColumn('groups_id') || !$before->hasColumn('profiles_id') || !$before->hasColumn('recipient_code'))) {
            throw new \RuntimeException('Missing notification identity without recoverable canonical columns');
        }
        $platform = $connection->getDatabasePlatform();
        $postgres = $platform instanceof PostgreSQLPlatform;
        $schema = $connection->fetchOne($postgres ? 'SELECT current_schema()' : 'SELECT DATABASE()');
        $generated = $hasKey && ($postgres
            ? (bool)$connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'glpi_notificationtargets' AND column_name = 'items_id' AND is_generated = 'ALWAYS'", [$schema])
            : \itsmng\Database\MySQLGeneratedColumnInspection::isGenerated($connection, $schema, 'glpi_notificationtargets', 'items_id'));
        $identity = $hasKey ? 'r.items_id' : '(CASE WHEN r.type IN (3, 5, 6) THEN r.groups_id WHEN r.type = 2 THEN r.profiles_id ELSE r.recipient_code END)';
        $invalid = (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notificationtargets r'
            . ' LEFT JOIN glpi_groups g ON g.id = ' . $identity . ' LEFT JOIN glpi_profiles p ON p.id = ' . $identity
            . ' WHERE (r.type IN (3, 5, 6) AND (' . $identity . ' IS NULL OR ' . $identity . ' <= 0 OR g.id IS NULL)) OR (r.type = 2 AND (' . $identity . ' IS NULL OR ' . $identity . ' <= 0 OR p.id IS NULL))');
        if ($invalid) {
            throw new \RuntimeException('Invalid legacy notification group/profile recipients: ' . $invalid);
        }
        foreach (['groups_id' => '3, 5, 6', 'profiles_id' => '2'] as $column => $kinds) {
            if ($before->hasColumn($column)) {
                $invalid = (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notificationtargets r WHERE r.' . $column
                    . ' IS NOT NULL AND (r.type NOT IN (' . $kinds . ') OR r.' . $column . ' <> ' . $identity . ')');
                if ($invalid) {
                    throw new \RuntimeException('Canonical and legacy notification recipients disagree: ' . $column);
                }
            }
        }
        if ($generated || !$hasKey) {
            $invalid = (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notificationtargets WHERE NOT (' . self::validRecipientSql() . ')');
            if ($invalid) {
                throw new \RuntimeException('Invalid canonical notification recipients: ' . $invalid);
            }
        } elseif ($before->hasColumn('recipient_code')) {
            $invalid = (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notificationtargets WHERE recipient_code IS NOT NULL AND recipient_code <> 0 AND (type IN (2, 3, 5, 6) OR recipient_code <> items_id)');
            if ($invalid) {
                throw new \RuntimeException('Canonical and legacy notification recipient codes disagree');
            }
        }
        $after = clone $before;
        self::configureColumns($after);
        $sql = $platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after));
        $keySql = [];
        if (!$generated) {
            $without = clone $after;
            $indexes = [];
            foreach ($without->getIndexes() as $index) {
                if (in_array('items_id', $index->getColumns(), true)) {
                    $indexes[] = $index;
                    $without->dropIndex($index->getName());
                }
            }
            if ($hasKey) {
                $without->dropColumn('items_id');
            }
            $withKey = clone $without;
            $withKey->addColumn('items_id', 'bigint', ['notnull' => false, 'columnDefinition' => self::keyDeclaration()]);
            foreach ($indexes as $index) {
                if ($index->isUnique()) {
                    $withKey->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
                } else {
                    $withKey->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
                }
            }
            if (!$withKey->hasIndex('items')) {
                $withKey->addIndex(['type', 'items_id'], 'items');
            }
            $keySql = $postgres
                ? array_merge(
                    $platform->getAlterTableSQL($manager->createComparator()->compareTables($after, $without)),
                    $platform->getAlterTableSQL($manager->createComparator()->compareTables($without, $withKey))
                )
                : $platform->getAlterTableSQL($manager->createComparator()->compareTables($after, $withKey));
        }
        $checked = $connection->fetchOne('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ?', [$schema, 'glpi_notificationtargets', self::CHECK, 'CHECK']);
        $constraints = $checked ? [] : [self::checkSql()];
        foreach ([new ForeignKeyConstraint(['groups_id'], 'glpi_groups', ['id'], 'fk_notificationtargets_groups_id', ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT']),
            new ForeignKeyConstraint(['profiles_id'], 'glpi_profiles', ['id'], 'fk_notificationtargets_profiles_id', ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'])] as $foreign) {
            if (!$before->hasForeignKey($foreign->getName())) {
                $constraints[] = $platform->getCreateForeignKeySQL($foreign, 'glpi_notificationtargets');
            } else {
                $existing = $before->getForeignKey($foreign->getName());
                if ($existing->getLocalColumns() !== $foreign->getLocalColumns() || $existing->getForeignTableName() !== $foreign->getForeignTableName()
                    || $existing->getForeignColumns() !== ['id'] || !in_array($existing->onDelete(), [null, 'RESTRICT', 'NO ACTION'], true)
                    || !in_array($existing->onUpdate(), [null, 'RESTRICT', 'NO ACTION'], true)) {
                    throw new \RuntimeException('Existing notification recipient FK has a different definition');
                }
            }
        }
        return ['sql' => $sql, 'key_sql' => $keySql, 'constraint_sql' => $constraints, 'copy_legacy' => $hasKey && !$generated,
            'group_rows' => (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notificationtargets WHERE type IN (3, 5, 6)'),
            'profile_rows' => (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_notificationtargets WHERE type = 2')];
    }

    public function apply(Connection $connection): array
    {
        $plan = $this->plan($connection);
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (($plan['sql'] || $plan['key_sql'] || $plan['constraint_sql']) && !$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL notification-recipient DDL must run outside an application transaction');
        }
        $apply = static function () use ($connection, $plan): array {
            foreach ($plan['sql'] as $statement) {
                $connection->executeStatement($statement);
            }
            if ($plan['copy_legacy']) {
                $connection->executeStatement('UPDATE glpi_notificationtargets SET groups_id = CASE WHEN type IN (3, 5, 6) THEN items_id ELSE NULL END,'
                    . ' profiles_id = CASE WHEN type = 2 THEN items_id ELSE NULL END, recipient_code = CASE WHEN type NOT IN (2, 3, 5, 6) THEN items_id ELSE NULL END');
            }
            foreach ($plan['key_sql'] as $statement) {
                $connection->executeStatement($statement);
            }
            foreach ($plan['constraint_sql'] as $statement) {
                $connection->executeStatement($statement);
            }
            return $plan;
        };
        return $postgres ? $connection->transactional($apply) : $apply();
    }
}
