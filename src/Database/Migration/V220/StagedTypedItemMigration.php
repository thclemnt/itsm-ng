<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Migration\V220;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Table;
use itsmng\Database\Migration\Ledger;

/** Internal 2.2.0 DDL checkpoints preserve projection data and MySQL retry state. */
abstract class StagedTypedItemMigration extends TypedItemMigration
{
    abstract protected function phase(): string;

    abstract protected function table(): string;

    protected function unsupportedKindGuidance(): string
    {
        return 'Resolve unsupported subject links in the source installation before adoption. A canonical importer requires completed migration history and cannot bypass this legacy-data preflight.';
    }

    final protected function tables(): array
    {
        return [$this->table()];
    }

    public function plan(Connection $connection, ?IncomingProjectionReferences $incomingReferences = null): array
    {
        return $this->planInspectedTable($connection, null, $incomingReferences);
    }

    /** Capture once per plan; every apply phase still invokes a new public plan. */
    protected function planInspectedTable(Connection $connection, ?Table $inspection, ?IncomingProjectionReferences $incomingReferences = null): array
    {
        if ($inspection !== null && $inspection->getName() !== $this->table()) {
            throw new \InvalidArgumentException('Staged typed item inspection belongs to a different table.');
        }
        if ((Ledger::state($connection, $this->phase())['complete'] ?? false) === true) {
            if (!isset(ExactDiscriminators::definitions()['tables'][$this->table()])) {
                $this->verify($connection);
            }
            return [];
        }
        $manager = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();
        $inspection ??= $manager->introspectTable($this->table());
        $identity = $inspection->hasColumn('items_id') ? 'items_id' : 'NULL AS items_id';
        $unsupported = $connection->fetchAllAssociative('SELECT id, itemtype, ' . $identity
            . ' FROM ' . $this->table() . ' WHERE itemtype IS NOT NULL AND ' . static::discriminatorSql($platform) . ' NOT IN (?)'
            . (static::allowsEmptyReference() ? ' AND NOT (' . static::emptyReferenceSql('', $platform) . ')' : '')
            . ' LIMIT 5', [array_keys(static::targets())], [\Doctrine\DBAL\ArrayParameterType::STRING]);
        if ($unsupported) {
            throw new \RuntimeException('Unsupported typed relationship kinds in ' . $this->table() . '; samples: ' . json_encode($unsupported, JSON_THROW_ON_ERROR)
                . '. ' . $this->unsupportedKindGuidance());
        }
        $entry = parent::planInspectedTable($connection, $inspection, $incomingReferences)[$this->table()];
        // A matching name does not prove the constraint's expression or MySQL
        // enforcement. Reinstall only our owned CHECK from its frozen declaration
        // after the complete data audit, without comparing lossy SQL normalizations.
        $platform = $connection->getDatabasePlatform();
        $check = static::checkSql($this->table(), $platform);
        $constraints = [];
        if (!in_array($check, $entry['constraint_sql'], true)) {
            $constraints[] = 'ALTER TABLE ' . $platform->quoteIdentifier($this->table()) . ' DROP '
                . ($platform instanceof MySQLPlatform ? 'CHECK ' : 'CONSTRAINT ')
                . $platform->quoteIdentifier(static::constraintName($this->table()));
        }
        $constraints[] = $check . ($platform instanceof MySQLPlatform ? ' ENFORCED' : '');
        foreach ($entry['constraint_sql'] as $statement) {
            if ($statement !== $check) {
                $constraints[] = $statement;
            }
        }
        return [$this->table() => [
            'columns' => $entry['sql'],
            'copy' => array_merge(
                static::allowsEmptyReference() ? ['UPDATE ' . $this->table() . ' SET itemtype = NULL WHERE ' . static::discriminatorSql($platform) . " = ''"] : [],
                $entry['copy_legacy'] ? [$this->copySql()] : []
            ),
            'projection' => $entry['key_sql'],
            'constraints' => $constraints,
        ]];
    }

    /** Replan each completed DDL phase on retry using frozen inputs and the same ledger. */
    public function apply(Connection $connection, ?callable $progress = null): array
    {
        $plan = $this->plan($connection);
        if (!$plan) {
            return [];
        }
        $postgres = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if (!$postgres && $connection->isTransactionActive()) {
            throw new \RuntimeException('MySQL typed relationship adoption must run outside an application transaction.');
        }
        $apply = function () use ($connection, $progress, $plan): array {
            $state = Ledger::state($connection, $this->phase());
            if ($state === null) {
                $columns = $connection->createSchemaManager()->listTableColumns($this->table());
                $state = ['complete' => false, 'phase' => 'audited', 'items_comment' => ($columns['items_id'] ?? null)?->getComment() ?? ''];
                Ledger::save($connection, $this->phase(), $state);
            }
            foreach (['columns', 'copy', 'projection', 'constraints'] as $phase) {
                $sql = $this->plan($connection)[$this->table()][$phase];
                foreach ($sql as $statement) {
                    $connection->executeStatement($statement);
                    $policy = in_array($phase, ['projection', 'constraints'], true) ? $this->nativePolicy($connection) : null;
                    $progress && $progress($phase, $statement);
                    if ($policy !== null && $policy !== $this->nativePolicy($connection)) {
                        throw new \RuntimeException('Typed subject native policy changed before checkpoint: ' . $this->table());
                    }
                }
                $state = $this->journalPhase($state, $phase);
                Ledger::save($connection, $this->phase(), $state);
            }
            // Recover the original comment even if a retry found the projection
            // absent; no runtime metadata can rewrite this historical declaration.
            $platform = $connection->getDatabasePlatform();
            $table = $connection->createSchemaManager()->introspectTable($this->table());
            if ($table->getColumn('items_id')->getComment() !== $state['items_comment']) {
                if ($platform->supportsInlineColumnComments()) {
                    $this->configureCommentProjection($table, $platform);
                    $connection->executeStatement('ALTER TABLE ' . $this->table() . ' MODIFY COLUMN items_id '
                        . $table->getColumn('items_id')->getColumnDefinition() . ' ' . $platform->getInlineColumnCommentSQL($state['items_comment']));
                } else {
                    $connection->executeStatement($platform->getCommentOnColumnSQL($this->table(), 'items_id', $state['items_comment']));
                }
            }
            $this->complete($connection);
            return $plan;
        };
        return $postgres ? $connection->transactional($apply) : $apply();
    }

    /** Preserve each frozen stage's projection when restoring a journaled comment. */
    protected function configureCommentProjection(Table $table, AbstractPlatform $platform): void
    {
        static::configureTable($table, $platform);
    }

    public function verify(Connection $connection): void
    {
        parent::verify($connection);
        // The exact-discriminator owner supersedes these earlier declarations.
        if (isset(ExactDiscriminators::definitions()['tables'][$this->table()])) {
            return;
        }
        $policy = Ledger::state($connection, $this->phase())['policy'] ?? null;
        if (!is_array($policy)) {
            throw new \RuntimeException('Experimental typed-subject receipt lacks retained post-DDL native policy: ' . $this->table()
                . '. Restore the genuine 2.1.3 source and apply the supported transition; no receipt or data was rewritten.');
        }
        if ($policy !== $this->nativePolicy($connection)) {
            throw new \RuntimeException('Frozen subject native policy changed after authoritative DDL: ' . $this->table());
        }
    }

    private function nativePolicy(Connection $connection): array
    {
        return ExactDiscriminators::nativePolicy($connection, $this->table(), [
            'column' => 'items_id', 'constraint' => static::constraintName($this->table()),
        ]);
    }

    /** Data-bearing appended stages can commit restored rows with their receipt. */
    protected function complete(Connection $connection): void
    {
        Ledger::save($connection, $this->phase(), ['complete' => true, 'policy' => $this->nativePolicy($connection)]);
    }

    protected function journalPhase(array $state, string $phase): array
    {
        $state['phase'] = $phase;
        return $state;
    }

    private function copySql(): string
    {
        $assignments = [];
        foreach (static::targets() as $kind => $target) {
            $assignments[] = static::column($target) . " = CASE WHEN itemtype = '" . $kind . "' THEN items_id ELSE NULL END";
        }
        return 'UPDATE ' . $this->table() . ' SET ' . implode(', ', $assignments);
    }

}
