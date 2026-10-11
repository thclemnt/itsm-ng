<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use itsmng\Database\ForeignKeys;
use itsmng\Database\Migration\Ledger;
use RuntimeException;

/** Frozen direct commercial supplier, helpdesk visibility and type authorization. */
final class DomainIntegration
{
    public const PHASE = '20261006_domain_supplier_helpdesk_rights';
    public const ADOPTION = '20261006_domains_plugin_adoption_v1';
    public const FORMAT = 'infotel-domains-2.1.0-completed-v1';

    public function plan(Connection $connection): array
    {
        if ((Ledger::state($connection, self::PHASE)['complete'] ?? false) === true) {
            return [];
        }
        $platform = $connection->getDatabasePlatform();
        $postgres = $platform instanceof PostgreSQLPlatform;
        if (!$postgres) {
            foreach ($connection->fetchAllAssociative("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('glpi_domains', 'glpi_profilerights')") as $storage) {
                if (strcasecmp((string)$storage['ENGINE'], 'InnoDB') !== 0) {
                    throw new RuntimeException('Domain supplier/helpdesk adoption requires transactional InnoDB ' . $storage['TABLE_NAME'] . '; reconcile its storage before applying history.');
                }
            }
        }
        $manager = $connection->createSchemaManager();
        $before = $manager->introspectTable('glpi_domains');
        $after = clone $before;
        if ($before->hasColumn('suppliers_id')) {
            $column = $before->getColumn('suppliers_id');
            if (!in_array(Type::lookupName($column->getType()), [Types::SMALLINT, Types::INTEGER, Types::BIGINT], true) || $column->getAutoincrement() || $column->getColumnDefinition() !== null) {
                throw new RuntimeException('Unsupported partially adopted Domain supplier column.');
            }
            $invalid = $connection->fetchAllAssociative('SELECT d.id, d.suppliers_id FROM glpi_domains d LEFT JOIN glpi_suppliers s ON s.id = d.suppliers_id WHERE d.suppliers_id IS NOT NULL AND d.suppliers_id <> 0 AND (d.suppliers_id < 0 OR s.id IS NULL) ORDER BY d.id LIMIT 5');
            if ($invalid) {
                throw new RuntimeException('Invalid direct Domain suppliers: ' . json_encode($invalid, JSON_THROW_ON_ERROR));
            }
            $after->getColumn('suppliers_id')->setType(Type::getType(Types::BIGINT))->setUnsigned(false)->setNotnull(false)->setDefault(null);
        } else {
            $after->addColumn('suppliers_id', Types::BIGINT, ['notnull' => false]);
        }
        if ($before->hasColumn('is_helpdesk_visible')) {
            $column = $before->getColumn('is_helpdesk_visible');
            $type = Type::lookupName($column->getType());
            if (!in_array($type, [Types::BOOLEAN, Types::SMALLINT, Types::INTEGER, Types::BIGINT], true) || $column->getAutoincrement() || $column->getColumnDefinition() !== null) {
                throw new RuntimeException('Unsupported partially adopted Domain helpdesk flag.');
            }
            $invalid = 'is_helpdesk_visible IS NULL' . ($postgres && $type === Types::BOOLEAN ? '' : ' OR is_helpdesk_visible NOT IN (0, 1)');
            $rows = $connection->fetchAllAssociative('SELECT id, is_helpdesk_visible FROM glpi_domains WHERE ' . $invalid . ' ORDER BY id LIMIT 5');
            if ($rows) {
                throw new RuntimeException('Invalid Domain helpdesk flags: ' . json_encode($rows, JSON_THROW_ON_ERROR));
            }
            $after->getColumn('is_helpdesk_visible')->setType(Type::getType(Types::BOOLEAN))->setUnsigned(false)->setNotnull(true)->setDefault(true);
        } else {
            $after->addColumn('is_helpdesk_visible', Types::BOOLEAN, ['default' => true]);
        }
        if ($after->hasIndex('domains_suppliers_id')) {
            $index = $after->getIndex('domains_suppliers_id');
            if ($index->getColumns() !== ['suppliers_id'] || $index->isUnique()) {
                throw new RuntimeException('Conflicting direct Domain supplier index.');
            }
        } else {
            $after->addIndex(['suppliers_id'], 'domains_suppliers_id');
        }
        $sql = [];
        // PostgreSQL needs an explicit integer-to-boolean conversion; ordinary
        // DBAL comparisons retain existing comments and other column options.
        if ($postgres && $before->hasColumn('is_helpdesk_visible') && Type::lookupName($before->getColumn('is_helpdesk_visible')->getType()) !== Types::BOOLEAN) {
            $sql[] = 'ALTER TABLE glpi_domains ALTER COLUMN is_helpdesk_visible DROP DEFAULT, ALTER COLUMN is_helpdesk_visible TYPE BOOLEAN USING (is_helpdesk_visible = 1), ALTER COLUMN is_helpdesk_visible SET DEFAULT TRUE, ALTER COLUMN is_helpdesk_visible SET NOT NULL';
            $before->getColumn('is_helpdesk_visible')->setType(Type::getType(Types::BOOLEAN))->setNotnull(true)->setDefault(true);
        }
        $sql = [...$sql, ...$platform->getAlterTableSQL($manager->createComparator()->compareTables($before, $after))];
        $foreignKeys = new ForeignKeys(['glpi_domains' => ['suppliers_id' => 'glpi_suppliers']]);
        $foreignSql = $foreignKeys->plan($connection);
        if (!$postgres) {
            // A same-name CHECK is not evidence of its semantics/enforcement.
            // Reinstall our single owned declaration after the complete audit.
            $name = 'glpi_domains_is_helpdesk_visible_boolean';
            if ($connection->fetchOne("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'glpi_domains' AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'", [$name])) {
                $sql[] = 'ALTER TABLE glpi_domains DROP ' . ($platform instanceof MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ') . $platform->quoteIdentifier($name);
            }
            $sql[] = 'ALTER TABLE glpi_domains ADD CONSTRAINT ' . $platform->quoteIdentifier($name) . ' CHECK (is_helpdesk_visible IS NOT NULL AND is_helpdesk_visible IN (0, 1))' . ($platform instanceof MySQLPlatform ? ' ENFORCED' : '');
        }
        $rows = $connection->fetchAllAssociative("SELECT r.id, r.profiles_id, r.name, r.rights FROM glpi_profilerights r LEFT JOIN glpi_profiles p ON p.id = r.profiles_id WHERE r.name IN ('dropdown', 'domaintype') AND (p.id IS NULL OR r.rights IS NULL OR r.rights < 0) ORDER BY r.id LIMIT 5");
        if ($rows) {
            throw new RuntimeException('Invalid dedicated DomainType authorization source: ' . json_encode($rows, JSON_THROW_ON_ERROR));
        }
        $deferred = $this->deferred($connection);
        foreach ($deferred as $row) {
            if (!$connection->fetchOne('SELECT 1 FROM glpi_domains WHERE id = ?', [$row['id']]) || ($row['suppliers_id'] !== null && !$connection->fetchOne('SELECT 1 FROM glpi_suppliers WHERE id = ?', [$row['suppliers_id']]))) {
                throw new RuntimeException('Missing deferred Domains import target: ' . $row['id']);
            }
        }
        return ['sql' => $sql, 'normalize_suppliers' => $before->hasColumn('suppliers_id') ? ['UPDATE glpi_domains SET suppliers_id = NULL WHERE suppliers_id = 0'] : [], 'foreign_keys' => $foreignSql,
            'deferred_domains' => $deferred, 'authorization' => ["INSERT INTO glpi_profilerights (profiles_id, name, rights) SELECT p.id, 'domaintype', COALESCE(d.rights, 0) FROM glpi_profiles p LEFT JOIN glpi_profilerights d ON d.profiles_id = p.id AND d.name = 'dropdown' WHERE NOT EXISTS (SELECT 1 FROM glpi_profilerights existing WHERE existing.profiles_id = p.id AND existing.name = 'domaintype')"]];
    }

    private function deferred(Connection $connection): array
    {
        $receipt = Ledger::state($connection, self::ADOPTION);
        if ($receipt === null) {
            return [];
        }
        if (($receipt['complete'] ?? false) !== true || ($receipt['format'] ?? null) !== self::FORMAT || !is_array($receipt['deferred_domains'] ?? null)) {
            throw new RuntimeException('Unrecognized frozen Domains adoption receipt.');
        }
        $seen = [];
        foreach ($receipt['deferred_domains'] as $row) {
            if (!is_array($row) || array_keys($row) !== ['id', 'suppliers_id', 'is_helpdesk_visible'] || !is_int($row['id']) || $row['id'] < 1 || isset($seen[$row['id']])
                || (!is_null($row['suppliers_id']) && (!is_int($row['suppliers_id']) || $row['suppliers_id'] < 1)) || !is_bool($row['is_helpdesk_visible'])) {
                throw new RuntimeException('Invalid frozen deferred Domains receipt values.');
            }
            $seen[$row['id']] = true;
        }
        return $receipt['deferred_domains'];
    }

    public function apply(Connection $connection, ?callable $progress = null): void
    {
        if ((Ledger::state($connection, self::PHASE)['complete'] ?? false) === true) {
            return;
        }
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (!$postgres && $connection->isTransactionActive()) {
            throw new RuntimeException('MySQL Domain integration must run outside an application transaction.');
        }
        $plan = $this->plan($connection);
        $apply = function () use ($connection, $progress, $plan): void {
            Ledger::save($connection, self::PHASE, ['complete' => false]);
            // Empty-selection normalization precedes nullable storage and FK DDL.
            // Old NOT NULL storage needs widening/nullability before this update.
            foreach ($plan['sql'] as $sql) {
                $connection->executeStatement($sql);
                $progress && $progress($sql);
            }
            foreach ($plan['normalize_suppliers'] as $sql) {
                $connection->executeStatement($sql);
            }
            foreach ($plan['foreign_keys'] as $sql) {
                $connection->executeStatement($sql);
                $progress && $progress($sql);
            }
            $connection->transactional(static function () use ($connection, $plan): void {
                foreach ($plan['deferred_domains'] as $row) {
                    $connection->update('glpi_domains', ['suppliers_id' => $row['suppliers_id'], 'is_helpdesk_visible' => $row['is_helpdesk_visible']], ['id' => $row['id']], ['suppliers_id' => Types::BIGINT, 'is_helpdesk_visible' => Types::BOOLEAN]);
                }
                foreach ($plan['authorization'] as $sql) {
                    $connection->executeStatement($sql);
                }
                Ledger::save($connection, self::PHASE, ['complete' => true, 'deferred_domains' => count($plan['deferred_domains'])]);
            });
        };
        $postgres ? $connection->transactional($apply) : $apply();
    }
}
