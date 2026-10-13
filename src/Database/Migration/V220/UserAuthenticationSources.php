<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\MySQLGeneratedColumnInspection;
use RuntimeException;

/** Frozen upgrade: authentication server branches are FKs; non-server kinds retain opaque codes. */
final class UserAuthenticationSources
{
    public const CHECK = 'glpi_users_authentication_kind';

    public static function configureTable(Table $table): void
    {
        self::configureColumns($table);
        $table->getColumn('auths_id')->setNotnull(false)->setDefault(null)->setColumnDefinition(self::keyDeclaration());
    }

    private static function configureColumns(Table $table): void
    {
        foreach (['authldaps_id', 'authmails_id'] as $column) {
            if (!$table->hasColumn($column)) {
                $table->addColumn($column, 'bigint', ['notnull' => false]);
            }
            if (!$table->hasIndex($column)) {
                $table->addIndex([$column], $column);
            }
        }
        if (!$table->hasColumn('auth_source_code')) {
            $table->addColumn('auth_source_code', 'integer', ['notnull' => false]);
        }
        $table->getColumn('auth_source_code')->setDefault(null);
    }

    private static function keyDeclaration(): string
    {
        return 'BIGINT GENERATED ALWAYS AS (CASE WHEN authtype IN (0, 3, 4, 5, 6) THEN COALESCE(authldaps_id, 0) WHEN authtype = 2 THEN COALESCE(authmails_id, 0) ELSE auth_source_code END) STORED';
    }

    public static function checkSql(): string
    {
        return 'ALTER TABLE glpi_users ADD CONSTRAINT ' . self::CHECK
            . ' CHECK (' . self::validSourceSql() . ')';
    }

    private static function validSourceSql(): string
    {
        return '(authtype = 2 AND (authmails_id IS NULL OR authmails_id > 0) AND authldaps_id IS NULL AND auth_source_code IS NULL)'
            . ' OR (authtype IN (0, 3, 4, 5, 6) AND (authldaps_id IS NULL OR authldaps_id > 0) AND authmails_id IS NULL AND auth_source_code IS NULL)'
            . ' OR (authtype NOT IN (0, 2, 3, 4, 5, 6) AND authldaps_id IS NULL AND authmails_id IS NULL AND auth_source_code IS NOT NULL)';
    }

    public function plan(Connection $connection): array
    {
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable('glpi_users');
        $hasKey = $before->hasColumn('auths_id');
        if (!$hasKey && (!$before->hasColumn('authldaps_id') || !$before->hasColumn('authmails_id') || !$before->hasColumn('auth_source_code'))) {
            throw new RuntimeException('Missing authentication identity without recoverable canonical columns');
        }
        $platform = $connection->getDatabasePlatform();
        $postgres = $platform instanceof PostgreSQLPlatform;
        $schema = $connection->fetchOne($postgres ? 'SELECT current_schema()' : 'SELECT DATABASE()');
        $generated = $hasKey && ($postgres
            ? (bool)$connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'glpi_users' AND column_name = 'auths_id' AND is_generated = 'ALWAYS'", [$schema])
            : MySQLGeneratedColumnInspection::isGenerated($connection, $schema, 'glpi_users', 'auths_id'));
        $identity = $hasKey ? 'r.auths_id' : '(CASE WHEN r.authtype IN (0, 3, 4, 5, 6) THEN COALESCE(r.authldaps_id, 0) WHEN r.authtype = 2 THEN COALESCE(r.authmails_id, 0) ELSE r.auth_source_code END)';
        $invalid = (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_users r'
            . ' LEFT JOIN glpi_authldaps g ON g.id = ' . $identity . ' LEFT JOIN glpi_authmails p ON p.id = ' . $identity
            . ' WHERE (r.authtype IN (0, 3, 4, 5, 6) AND (' . $identity . ' > 0 AND g.id IS NULL)) OR (r.authtype = 2 AND (' . $identity . ' > 0 AND p.id IS NULL))');
        if ($invalid) {
            throw new RuntimeException('Invalid legacy authentication LDAP/mail sources: ' . $invalid);
        }
        foreach (['authldaps_id' => '0, 3, 4, 5, 6', 'authmails_id' => '2'] as $column => $kinds) {
            if ($before->hasColumn($column)) {
                $invalid = (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_users r WHERE r.' . $column
                    . ' IS NOT NULL AND (r.' . $column . ' <= 0 OR r.authtype NOT IN (' . $kinds . ') OR r.' . $column . ' <> ' . $identity . ')');
                if ($invalid) {
                    throw new RuntimeException('Canonical and legacy authentication sources disagree: ' . $column);
                }
            }
        }
        if ($generated || !$hasKey) {
            $invalid = (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_users WHERE NOT (' . self::validSourceSql() . ')');
            if ($invalid) {
                throw new RuntimeException('Invalid canonical authentication sources: ' . $invalid);
            }
        } elseif ($before->hasColumn('auth_source_code')) {
            $invalid = (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_users WHERE auth_source_code IS NOT NULL AND auth_source_code <> 0 AND (authtype IN (0, 2, 3, 4, 5, 6) OR auth_source_code <> auths_id)');
            if ($invalid) {
                throw new RuntimeException('Canonical and legacy authentication source codes disagree');
            }
        }
        // Normalizing old negative no-server sentinels to zero must not merge distinct logins.
        $selection = $generated || !$hasKey ? $identity
            : '(CASE WHEN r.authtype IN (0, 2, 3, 4, 5, 6) AND r.auths_id <= 0 THEN 0 ELSE r.auths_id END)';
        $duplicates = $connection->fetchOne('SELECT COUNT(*) FROM (SELECT r.name, r.authtype, ' . $selection
            . ' FROM glpi_users r WHERE r.name IS NOT NULL GROUP BY r.name, r.authtype, ' . $selection . ' HAVING COUNT(*) > 1) collisions');
        if ($duplicates) {
            throw new RuntimeException('Authentication source normalization would merge distinct login keys: ' . $duplicates);
        }
        $after = clone $before;
        self::configureColumns($after);
        $sql = $platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after));
        $keySql = [];
        if (!$generated) {
            $without = clone $after;
            $indexes = [];
            foreach ($without->getIndexes() as $index) {
                if (in_array('auths_id', $index->getColumns(), true)) {
                    $indexes[] = $index;
                    $without->dropIndex($index->getName());
                }
            }
            if ($hasKey) {
                $without->dropColumn('auths_id');
            }
            $withKey = clone $without;
            $withKey->addColumn('auths_id', 'bigint', ['notnull' => false, 'columnDefinition' => self::keyDeclaration()]);
            foreach ($indexes as $index) {
                if ($index->isUnique()) {
                    $withKey->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
                } else {
                    $withKey->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
                }
            }
            if (!array_filter($withKey->getIndexes(), static fn ($index): bool => $index->isUnique() && $index->getColumns() === ['name', 'authtype', 'auths_id'])) {
                $withKey->addUniqueIndex(['name', 'authtype', 'auths_id'], 'users_unicityloginauth');
            }
            $keySql = $postgres
                ? array_merge(
                    $platform->getAlterTableSQL($manager->createComparator()->compareTables($after, $without)),
                    $platform->getAlterTableSQL($manager->createComparator()->compareTables($without, $withKey))
                )
                : $platform->getAlterTableSQL($manager->createComparator()->compareTables($after, $withKey));
        }
        $checked = $connection->fetchOne('SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ?', [$schema, 'glpi_users', self::CHECK, 'CHECK']);
        $constraints = $checked ? [] : [self::checkSql()];
        foreach ([new ForeignKeyConstraint(['authldaps_id'], 'glpi_authldaps', ['id'], 'fk_users_authldaps_id', ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT']),
            new ForeignKeyConstraint(['authmails_id'], 'glpi_authmails', ['id'], 'fk_users_authmails_id', ['onDelete' => 'RESTRICT', 'onUpdate' => 'RESTRICT'])] as $foreign) {
            if (!$before->hasForeignKey($foreign->getName())) {
                $constraints[] = $platform->getCreateForeignKeySQL($foreign, 'glpi_users');
            } else {
                $existing = $before->getForeignKey($foreign->getName());
                if ($existing->getLocalColumns() !== $foreign->getLocalColumns() || $existing->getForeignTableName() !== $foreign->getForeignTableName()
                    || $existing->getForeignColumns() !== ['id'] || !in_array($existing->onDelete(), [null, 'RESTRICT', 'NO ACTION'], true)
                    || !in_array($existing->onUpdate(), [null, 'RESTRICT', 'NO ACTION'], true)) {
                    throw new RuntimeException('Existing authentication source FK has a different definition');
                }
            }
        }
        return ['sql' => $sql, 'key_sql' => $keySql, 'constraint_sql' => $constraints, 'copy_legacy' => $hasKey && !$generated,
            'ldap_rows' => (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_users WHERE authtype IN (0, 3, 4, 5, 6)'),
            'mail_rows' => (int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_users WHERE authtype = 2')];
    }

    public function apply(Connection $connection): array
    {
        $plan = $this->plan($connection);
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (($plan['sql'] || $plan['key_sql'] || $plan['constraint_sql']) && !$postgres && $connection->isTransactionActive()) {
            throw new RuntimeException('MySQL authentication-source DDL must run outside an application transaction');
        }
        $apply = static function () use ($connection, $plan): array {
            foreach ($plan['sql'] as $statement) {
                $connection->executeStatement($statement);
            }
            if ($plan['copy_legacy']) {
                $connection->executeStatement('UPDATE glpi_users SET authldaps_id = CASE WHEN authtype IN (0, 3, 4, 5, 6) THEN CASE WHEN auths_id > 0 THEN auths_id ELSE NULL END ELSE NULL END,'
                    . ' authmails_id = CASE WHEN authtype = 2 THEN CASE WHEN auths_id > 0 THEN auths_id ELSE NULL END ELSE NULL END, auth_source_code = CASE WHEN authtype NOT IN (0, 2, 3, 4, 5, 6) THEN auths_id ELSE NULL END');
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
