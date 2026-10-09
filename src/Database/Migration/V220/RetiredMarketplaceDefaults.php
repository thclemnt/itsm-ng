<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use itsmng\Database\Migration\Ledger;
use itsmng\Database\MutationRollbackFailure;
use itsmng\Database\OwnedMutationFrame;

/** Archive only the three unchanged children left by the released marketplace removal. */
final class RetiredMarketplaceDefaults
{
    public const RECEIPT = '2.2.0_retired_marketplace_defaults';
    private const FORMAT = 'released-2.1.3-marketplace-children-v1';

    // Complete physical rows from 5ecdf8e2a29:install/empty_data.php.
    // ef30493fad removed both parents; none of these values use translation or
    // omitted database defaults. Integer strings permit the two DBAL fetch types.
    private const ROWS = [
        'glpi_notifications_notificationtemplates' => ['id' => '71', 'notifications_id' => '71', 'mode' => 'mailing', 'notificationtemplates_id' => '28'],
        'glpi_notificationtargets' => ['id' => '139', 'items_id' => '1', 'type' => '1', 'notifications_id' => '71'],
        'glpi_notificationtemplatetranslations' => [
            'id' => '28', 'notificationtemplates_id' => '28', 'language' => '',
            'subject' => '##lang.plugins_updates_available##',
            'content_text' => "##lang.plugins_updates_available##\n\n##FOREACHplugins##\n##plugin.name## :##plugin.old_version## -&gt; ##plugin.version##\n##ENDFOREACHplugins##",
            'content_html' => "&lt;p&gt;##lang.plugins_updates_available##&lt;/p&gt;\n&lt;ul&gt;##FOREACHplugins##\n&lt;li&gt;##plugin.name## :##plugin.old_version## -&gt; ##plugin.version##&lt;/li&gt;\n##ENDFOREACHplugins##&lt;/ul&gt;",
        ],
    ];
    private const OWNERS = [
        'glpi_notifications_notificationtemplates' => ['notifications_id' => ['glpi_notifications', 71], 'notificationtemplates_id' => ['glpi_notificationtemplates', 28]],
        'glpi_notificationtargets' => ['notifications_id' => ['glpi_notifications', 71]],
        'glpi_notificationtemplatetranslations' => ['notificationtemplates_id' => ['glpi_notificationtemplates', 28]],
    ];

    public function plan(Connection $connection): array
    {
        $receipt = Ledger::state($connection, self::RECEIPT);
        $parents = $this->parents($connection);
        $rows = $this->rows($connection);
        if ($receipt !== null) {
            if (($receipt['complete'] ?? false) !== true || ($receipt['format'] ?? null) !== self::FORMAT
                || !$this->matches($receipt['rows'] ?? []) || $rows || in_array(true, $parents, true)) {
                throw new \RuntimeException('Retired marketplace archive differs from its frozen original rows or live ownership; reconcile the retained receipt before retry.');
            }
            return [];
        }
        if (!$rows || !in_array(false, $parents, true)) {
            return [];
        }
        if (in_array(true, $parents, true) || !$this->matches($rows)) {
            $samples = [];
            foreach ($rows as $table => $records) {
                foreach (self::OWNERS[$table] as $field => [$parent, $id]) {
                    if (!$parents[$parent]) {
                        foreach (array_slice($records, 0, 5) as $row) {
                            if ((string)$row[$field] === (string)$id) {
                                $samples[] = ['source' => $table . '.' . $field, 'source_id' => $row['id'], 'missing_parent' => $parent . '.id', 'missing_id' => $id];
                            }
                        }
                    }
                }
            }
            throw new \RuntimeException('Orphaned retired marketplace ownership does not match all three complete released defaults with both parents absent. Reconcile customized, partial or mixed ownership using original installation records; no rows were archived or removed. Samples: ' . json_encode($samples, JSON_THROW_ON_ERROR));
        }
        $this->assertIsolatedRetirement($connection);
        $this->assertTransactional($connection);
        return ['kind' => 'archival_prerequisite', 'receipt' => self::RECEIPT,
            'description' => 'Archive and retire exactly three unchanged released marketplace children; both retired parents are absent.',
            'actions' => array_map(static fn (string $table, array $row): array => ['action' => 'archive_and_retire', 'table' => $table, 'id' => $row['id']], array_keys(self::ROWS), array_values(self::ROWS)),
            'rows' => $rows];
    }

    /** History holds its migration lock; application writers must remain stopped. */
    public function apply(Connection $connection, ?callable $progress = null): void
    {
        $plan = $this->plan($connection);
        if (!$plan) {
            return;
        }
        // MySQL ledger DDL is outside the data transaction. An interruption here
        // leaves only empty storage, never a completion or an unarchived deletion.
        Ledger::ensure($connection);
        $frame = OwnedMutationFrame::begin($connection);
        try {
            $this->parents($connection, true);
            $this->rows($connection, true);
            $plan = $this->plan($connection);
            if (!$plan) {
                throw new \RuntimeException('Retired marketplace source changed after preview; stop writers and retry.');
            }
            Ledger::save($connection, self::RECEIPT, ['complete' => true, 'format' => self::FORMAT, 'rows' => $plan['rows']]);
            $progress && $progress('Archived three original retired marketplace default rows; retirement is in the same transaction.');
            $frame->assertActive();
            foreach (self::ROWS as $table => $row) {
                if ($connection->delete($table, ['id' => $row['id']]) !== 1) {
                    throw new \RuntimeException('Retired marketplace source row changed while archiving: ' . $table . '.' . $row['id']);
                }
                $progress && $progress('Retired archived marketplace default: ' . $table . '.' . $row['id']);
                $frame->assertActive();
            }
            $this->plan($connection); // Verify the archive and absence before commit.
            $frame->commit();
        } catch (\Throwable $error) {
            try {
                $frame->rollBack();
            } catch (\Throwable $cleanup) {
                throw new MutationRollbackFailure($error, $cleanup);
            }
            throw $error;
        }
    }

    private function parents(Connection $connection, bool $lock = false): array
    {
        $result = [];
        foreach (['glpi_notifications' => 71, 'glpi_notificationtemplates' => 28] as $table => $id) {
            $result[$table] = $connection->fetchOne('SELECT id FROM ' . $table . ' WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]) !== false;
        }
        return $result;
    }

    private function rows(Connection $connection, bool $lock = false): array
    {
        $result = [];
        foreach (self::OWNERS as $table => $owners) {
            $conditions = [];
            $parameters = [];
            foreach ($owners as $field => [, $id]) {
                $conditions[] = $field . ' = ?';
                $parameters[] = $id;
            }
            $rows = $connection->fetchAllAssociative('SELECT * FROM ' . $table . ' WHERE ' . implode(' OR ', $conditions) . ' ORDER BY id' . ($lock ? ' FOR UPDATE' : ''), $parameters);
            if ($rows) {
                $result[$table] = $rows;
            }
        }
        return $result;
    }

    private function matches(array $rows): bool
    {
        if (count($rows) !== count(self::ROWS)) {
            return false;
        }
        foreach (self::ROWS as $table => $expected) {
            if (count($rows[$table] ?? []) !== 1 || !isset($rows[$table][0]) || !is_array($rows[$table][0])) {
                return false;
            }
            $actual = $rows[$table][0];
            if (count($actual) !== count($expected)) {
                return false;
            }
            foreach ($expected as $field => $value) {
                if (!isset($actual[$field]) || (!is_string($actual[$field]) && !is_int($actual[$field])) || (string)$actual[$field] !== $value) {
                    return false;
                }
            }
        }
        return true;
    }

    /** The released tables have no incoming owners or custom deletion behavior. */
    private function assertIsolatedRetirement(Connection $connection): void
    {
        $mysql = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
        foreach (array_keys(self::ROWS) as $table) {
            $owner = $mysql
                ? $connection->fetchAssociative('SELECT TABLE_SCHEMA AS owner_schema, TABLE_NAME AS owner_table, CONSTRAINT_NAME AS owner_key FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? LIMIT 1', [$table])
                : $connection->fetchAssociative("SELECT conrelid::regclass::text AS owner_table, conname AS owner_key FROM pg_constraint WHERE contype = 'f' AND confrelid = to_regclass(?) LIMIT 1", [$table]);
            if ($owner !== false) {
                throw new \RuntimeException('Retired marketplace archival refuses an incoming foreign key to ' . $table
                    . ': ' . json_encode($owner, JSON_THROW_ON_ERROR) . '. Custom owners may cascade or change on deletion; no rows were archived or removed.');
            }
            $trigger = $mysql
                ? $connection->fetchOne('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? LIMIT 1', [$table])
                : $connection->fetchOne('SELECT tgname FROM pg_trigger WHERE tgrelid = to_regclass(?) AND NOT tgisinternal LIMIT 1', [$table]);
            if ($trigger !== false) {
                throw new \RuntimeException('Retired marketplace archival refuses a custom trigger on ' . $table . ': ' . $trigger
                    . '. Its effects are outside the frozen archive; no rows were archived or removed.');
            }
            // PostgreSQL rules can rewrite a DELETE without using a trigger.
            if (!$mysql && ($rule = $connection->fetchOne('SELECT rulename FROM pg_rewrite WHERE ev_class = to_regclass(?) LIMIT 1', [$table])) !== false) {
                throw new \RuntimeException('Retired marketplace archival refuses a custom rewrite rule on ' . $table . ': ' . $rule
                    . '. Its effects are outside the frozen archive; no rows were archived or removed.');
            }
        }
    }

    private function assertTransactional(Connection $connection): void
    {
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return;
        }
        foreach (array_keys(self::ROWS) as $table) {
            $engine = $connection->fetchOne('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            if (strcasecmp((string)$engine, 'InnoDB') !== 0) {
                throw new \RuntimeException('Retired marketplace archival requires transactional InnoDB source table: ' . $table);
            }
        }
    }
}
